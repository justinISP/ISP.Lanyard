<?php
declare(strict_types=1);

namespace ISP\Lanyard\Payment;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use ISP\Lanyard\Domain\Exception\InvalidStatusTransitionException;
use ISP\Lanyard\Domain\Model\Order;
use ISP\Lanyard\Domain\Model\OrderLogEntry;
use ISP\Lanyard\Domain\Model\OrderStatus;
use ISP\Lanyard\Domain\Model\PaymentStatus;
use ISP\Lanyard\Domain\Repository\OrderRepository;
use ISP\Lanyard\Domain\Service\OptInTokenService;
use ISP\Lanyard\Domain\Service\OrderService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\BaseUriProvider;
use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Translator;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Security\Cryptography\HashService;
use Neos\Flow\Security\Exception as SecurityException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Verbindet Bestellungen mit den Zahlungsarten und Gateways.
 *
 * Zahlungsarten stehen in ISP.Lanyard.payment.methods: "online" läuft
 * über ein Gateway, ohne Opt-in und Freigabe; "offline" (Vorkasse, bar)
 * über den bisherigen Ablauf.
 *
 * Den Status der Bestellung ändert auch hier nur der OrderService.
 * Dieser Dienst entscheidet anhand des Gateways, welcher Wechsel fällig
 * ist (synchronise()).
 */
#[Flow\Scope('singleton')]
class PaymentService
{
    public const TYPE_ONLINE = 'online';
    public const TYPE_OFFLINE = 'offline';

    #[Flow\Inject]
    protected OrderService $orderService;

    #[Flow\Inject]
    protected OrderRepository $orderRepository;

    #[Flow\Inject]
    protected OptInTokenService $tokenService;

    #[Flow\Inject]
    protected EntityManagerInterface $entityManager;

    #[Flow\Inject]
    protected ObjectManagerInterface $objectManager;

    #[Flow\Inject]
    protected HashService $hashService;

    #[Flow\Inject]
    protected BaseUriProvider $baseUriProvider;

    #[Flow\Inject]
    protected Translator $translator;

    #[Flow\Inject(name: 'Neos.Flow:SystemLogger', lazy: false)]
    protected LoggerInterface $logger;

    #[Flow\InjectConfiguration(path: 'payment', package: 'ISP.Lanyard')]
    protected array $settings = [];

    #[Flow\InjectConfiguration(path: 'order.currency', package: 'ISP.Lanyard')]
    protected string $currency = 'EUR';

    #
    # Zahlungsarten
    #

    /**
     * Eingeschaltete Zahlungsarten in der Reihenfolge von "position".
     * Online-Zahlungsarten, deren Gateway nicht zur Verfügung steht
     * (Test-Gateway im Production-Kontext), fallen weg.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getMethods(): array
    {
        $methods = array_filter(
            (array)($this->settings['methods'] ?? []),
            fn($method): bool => is_array($method)
                && ($method['enabled'] ?? false) === true
                && (($method['type'] ?? '') !== self::TYPE_ONLINE || $this->isGatewayAvailable((string)($method['gateway'] ?? '')))
        );

        uasort($methods, static fn(array $a, array $b): int => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        return $methods;
    }

    public function isMethodAvailable(string $method): bool
    {
        return isset($this->getMethods()[$method]);
    }

    /**
     * Unabhängig davon, ob die Zahlungsart gerade eingeschaltet ist –
     * eine bestehende Bestellung bleibt online bezahlt.
     */
    public function isOnline(?string $method): bool
    {
        return $method !== null && ($this->settings['methods'][$method]['type'] ?? '') === self::TYPE_ONLINE;
    }

    /**
     * Bezeichnung aus ISP.Lanyard/Form.xlf (paymentMethod.<schlüssel>),
     * sonst der Schlüssel selbst – ältere Bestellungen tragen hier
     * Freitext aus dem Backend ("bar", "Überweisung").
     */
    public function getMethodLabel(?string $method, string $language = 'de'): string
    {
        if ($method === null || $method === '') {
            return '';
        }

        if (!isset($this->settings['methods'][$method])) {
            return $method;
        }

        $label = $this->translator->translateById('paymentMethod.' . $method, [], null, new Locale($language), 'Form', 'ISP.Lanyard');

        return is_string($label) && $label !== '' ? $label : (string)($this->settings['methods'][$method]['label'] ?? $method);
    }

    /**
     * Erläuterung für das Formular aus ISP.Lanyard/Form.xlf
     * (paymentMethod.<schlüssel>.description), leer wenn keine.
     */
    public function getMethodDescription(string $method, string $language = 'de'): string
    {
        $description = $this->translator->translateById('paymentMethod.' . $method . '.description', [], null, new Locale($language), 'Form', 'ISP.Lanyard');

        return is_string($description) && $description !== 'paymentMethod.' . $method . '.description' ? $description : '';
    }

    /**
     * Für das Backend: Zahlungsarten, die das Orga-Team von Hand als
     * bezahlt markiert.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getOfflineMethodOptions(): array
    {
        $options = [];

        foreach ($this->getMethods() as $key => $method) {
            if (($method['type'] ?? '') === self::TYPE_OFFLINE) {
                $options[] = ['value' => (string)$key, 'label' => $this->getMethodLabel((string)$key)];
            }
        }

        return $options;
    }

    public function getLifetime(): \DateInterval
    {
        return new \DateInterval((string)($this->settings['lifetime'] ?? 'PT30M'));
    }

    #
    # Ablauf
    #

    public function generateToken(): string
    {
        return $this->tokenService->generate();
    }

    /**
     * Legt den Zahlungsvorgang beim Gateway an und liefert die Adresse,
     * auf die der Besteller weitergeleitet wird.
     *
     * Scheitert das Gateway, verfällt die Bestellung sofort und gibt
     * die Plätze frei – der Besteller sieht eine Fehlermeldung.
     *
     * @param string $backUri Seite des Formulars, für "erneut bestellen"
     * @throws PaymentException
     */
    public function start(Order $order, string $backUri): string
    {
        $returnUri = $this->buildReturnUri($order, $backUri);

        // Nichts zu bezahlen, z. B. nur Freikarten: kein Umweg übers Gateway
        if ($order->getTotalAmount() === 0) {
            $this->orderService->markAsPaid($order, null, 0, null, OrderLogEntry::ACTOR_SYSTEM);
            return $returnUri;
        }

        $gateway = $this->gatewayFor($order);

        try {
            if ($gateway === null) {
                throw new PaymentException(sprintf('No gateway for payment method "%s".', $order->getPaymentMethod()), 1763640201);
            }

            $session = $gateway->startPayment(new PaymentRequest(
                (string)$order->getBookingNumber(),
                $order->getTotalAmount(),
                $this->currency,
                $order->getEvent()->getLocalisedTitle($order->getLanguage()) . ' – ' . $order->getBookingNumber(),
                $order->getEmail(),
                $order->getLanguage(),
                $returnUri,
                $this->absoluteUri(str_replace('{gateway}', rawurlencode($this->gatewayKeyFor($order)), (string)($this->settings['notifyUri'] ?? '')))
            ));
        } catch (PaymentException $exception) {
            $this->logger->error(sprintf('ISP.Lanyard: Zahlung für Bestellung %s konnte nicht gestartet werden: %s', $order->getBookingNumber(), $exception->getMessage()));
            $this->orderService->failPayment($order, PaymentStatus::FAILED, OrderLogEntry::ACTOR_SYSTEM);
            throw $exception;
        }

        $this->orderService->recordPaymentStarted($order, $session->reference);

        return $session->redirectUri;
    }

    /**
     * Gleicht die Bestellung mit dem Stand beim Gateway ab und löst den
     * fälligen Statuswechsel aus.
     *
     * Rückkehrseite, Webhook und Verfallskommando rufen dies auf, oft
     * fast gleichzeitig. Die Zeilensperre auf die Bestellung sorgt
     * dafür, dass nur einer den Wechsel vornimmt – der zweite sieht nach
     * dem Warten den neuen Status und tut nichts mehr.
     *
     * @throws PaymentException
     */
    public function synchronise(Order $order, string $actorType = OrderLogEntry::ACTOR_GATEWAY): void
    {
        $gateway = $this->gatewayFor($order);

        if ($order->getPaymentReference() === null || $gateway === null) {
            return;
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($order);

            $this->apply($order, $gateway, $gateway->fetchStatus((string)$order->getPaymentReference()), $actorType);

            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    /**
     * Webhook: Das Gateway meldet eine Änderung. Der Inhalt der Meldung
     * zählt nicht, nur die Kennung – den Stand holt synchronise().
     *
     * @return bool false, wenn die Meldung keiner Bestellung zuzuordnen ist
     * @throws PaymentException
     */
    public function handleNotification(string $gatewayKey, ServerRequestInterface $request): bool
    {
        $gateway = $this->getGateway($gatewayKey);
        $reference = $gateway->referenceFromNotification($request);

        return $reference !== null && $this->synchroniseByReference($reference, $gatewayKey);
    }

    /**
     * @param string|null $gatewayKey Nur Bestellungen dieses Gateways
     * @throws PaymentException
     */
    public function synchroniseByReference(string $reference, ?string $gatewayKey = null): bool
    {
        $order = $this->orderRepository->findOneByPaymentReference($reference);

        if (!$order instanceof Order || ($gatewayKey !== null && $this->gatewayKeyFor($order) !== $gatewayKey)) {
            return false;
        }

        $this->synchronise($order);

        return true;
    }

    /**
     * Erstattet eine online bezahlte Bestellung vor dem Storno.
     *
     * @return bool true, wenn erstattet wurde; false, wenn es nichts
     *     über ein Gateway zu erstatten gibt (offline, unbezahlt)
     * @throws PaymentException Die Erstattung ist gescheitert, die
     *     Bestellung darf dann nicht storniert werden.
     */
    public function refund(Order $order): bool
    {
        $gateway = $this->gatewayFor($order);

        if ($gateway === null || $order->getPaymentReference() === null || $order->getPaymentStatus() !== PaymentStatus::PAID) {
            return false;
        }

        $gateway->refund((string)$order->getPaymentReference(), $order->getPaymentAmount() ?? $order->getTotalAmount());
        $this->orderService->recordRefund($order);

        return true;
    }

    /**
     * Lässt Online-Bestellungen verfallen, deren Zahlung zu lange offen
     * ist. Vorher wird beim Gateway nachgefragt – eine Zahlung, deren
     * Webhook verloren ging, wird so noch gebucht.
     *
     * @return array<int, array{order: Order, result: string}> result: paid, failed, expired
     */
    public function expireOverdue(bool $dryRun = false): array
    {
        $lifetime = $this->getLifetime();
        $results = [];

        foreach ($this->orderRepository->findOverduePayments((new \DateTimeImmutable())->sub($lifetime)) as $order) {
            if ($dryRun) {
                $results[] = ['order' => $order, 'result' => OrderStatus::EXPIRED];
                continue;
            }

            try {
                $this->synchronise($order);
            } catch (PaymentException $exception) {
                $this->logger->warning(sprintf('ISP.Lanyard: Zahlungsstand für %s nicht abrufbar: %s', $order->getBookingNumber(), $exception->getMessage()));
            }

            if ($order->getStatus() === OrderStatus::PENDING_PAYMENT) {
                try {
                    $this->orderService->failPayment($order, PaymentStatus::EXPIRED);
                } catch (InvalidStatusTransitionException) {
                    continue;
                }
            }

            $results[] = [
                'order' => $order,
                'result' => $order->getStatus() === OrderStatus::PAID ? OrderStatus::PAID : (string)$order->getPaymentStatus(),
            ];
        }

        return $results;
    }

    #
    # Rückkehrseite
    #

    public function findOrderByToken(string $token): ?Order
    {
        if ($token === '') {
            return null;
        }

        $order = $this->orderRepository->findOneByPaymentToken($token);

        return $order instanceof Order && $this->tokenService->matches((string)$order->getPaymentToken(), $token) ? $order : null;
    }

    /**
     * Prüft die signierte Formularadresse aus der Rückkehr-URL. Nur
     * selbst ausgestellte Adressen werden verlinkt.
     */
    public function verifyBackUri(string $signedUri): ?string
    {
        try {
            $uri = $this->hashService->validateAndStripHmac($signedUri);
        } catch (SecurityException) {
            return null;
        }

        return preg_match('#^(https?://|/)#i', $uri) === 1 ? $uri : null;
    }

    #
    # Gateways
    #

    /**
     * @throws PaymentException
     */
    public function getGateway(string $key): PaymentGatewayInterface
    {
        $className = (string)($this->settings['gateways'][$key]['className'] ?? '');

        if ($className === '' || !$this->objectManager->isRegistered($className)) {
            throw new PaymentException(sprintf('Payment gateway "%s" is not configured.', $key), 1763640202);
        }

        $gateway = $this->objectManager->get($className);

        if (!$gateway instanceof PaymentGatewayInterface) {
            throw new PaymentException(sprintf('Payment gateway "%s" does not implement PaymentGatewayInterface.', $key), 1763640203);
        }

        return $gateway;
    }

    protected function isGatewayAvailable(string $key): bool
    {
        try {
            $gateway = $this->getGateway($key);
        } catch (PaymentException) {
            return false;
        }

        return !$gateway instanceof TestGateway || $gateway->isAllowed();
    }

    protected function gatewayKeyFor(Order $order): string
    {
        return (string)($this->settings['methods'][$order->getPaymentMethod()]['gateway'] ?? '');
    }

    protected function gatewayFor(Order $order): ?PaymentGatewayInterface
    {
        if (!$this->isOnline($order->getPaymentMethod())) {
            return null;
        }

        try {
            return $this->getGateway($this->gatewayKeyFor($order));
        } catch (PaymentException) {
            return null;
        }
    }

    /**
     * @throws PaymentException
     */
    protected function apply(Order $order, PaymentGatewayInterface $gateway, string $paymentStatus, string $actorType): void
    {
        if ($order->getStatus() === OrderStatus::PENDING_PAYMENT) {
            if ($paymentStatus === PaymentStatus::PAID) {
                $this->orderService->markAsPaid($order, null, null, null, $actorType);
            } elseif (in_array($paymentStatus, PaymentStatus::unsuccessful(), true)) {
                $this->orderService->failPayment($order, $paymentStatus, $actorType);
            }

            return;
        }

        // Zahlung nach Verfall oder Storno: Die Plätze sind womöglich
        // vergeben, das Geld geht zurück.
        if ($paymentStatus === PaymentStatus::PAID && $order->isClosed()) {
            $gateway->refund((string)$order->getPaymentReference(), $order->getPaymentAmount() ?? $order->getTotalAmount());
            $this->orderService->recordRefund($order, 'Zahlung nach Verfall oder Storno eingegangen', $actorType);
            return;
        }

        if ($order->getPaymentStatus() === PaymentStatus::OPEN && in_array($paymentStatus, PaymentStatus::unsuccessful(), true)) {
            $this->orderService->recordPaymentStatus($order, $paymentStatus);
        }
    }

    protected function buildReturnUri(Order $order, string $backUri): string
    {
        $uri = str_replace('{token}', rawurlencode((string)$order->getPaymentToken()), (string)($this->settings['returnUri'] ?? ''));

        if ($backUri !== '') {
            $uri .= (str_contains($uri, '?') ? '&' : '?') . 'back=' . rawurlencode($this->hashService->appendHmac($backUri));
        }

        return $this->absoluteUri($uri);
    }

    protected function absoluteUri(string $uri): string
    {
        if (preg_match('#^https?://#i', $uri) === 1) {
            return $uri;
        }

        $baseUri = (string)$this->baseUriProvider->getConfiguredBaseUriOrFallbackToCurrentRequest();

        return rtrim($baseUri, '/') . '/' . ltrim($uri, '/');
    }
}
