<?php
declare(strict_types=1);

namespace ISP\Lanyard\Payment;

use ISP\Lanyard\Domain\Model\PaymentStatus;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Simuliert einen Zahlungsanbieter, solange noch keiner feststeht.
 *
 * Verhält sich wie ein echter: Die Bezahlseite liegt "beim Anbieter"
 * (TestGatewayController), der Ausgang wird dort gewählt, der Stand ist
 * nur über fetchStatus() verbindlich. Der Zustand liegt im persistenten
 * Cache ISP_Lanyard_TestGateway – er überlebt einen Cache-Flush, wie
 * ein Vorgang beim Anbieter.
 *
 * Im Production-Kontext verweigert das Gateway jeden Aufruf, sonst
 * könnte jemand live kostenlos "bezahlen". Ausnahme nur mit
 * payment.gateways.test.allowInProduction.
 */
#[Flow\Scope('singleton')]
class TestGateway implements PaymentGatewayInterface
{
    public const OUTCOME_PAID = 'paid';
    public const OUTCOME_PAID_DELAYED = 'paidDelayed';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_CANCELLED = 'cancelled';

    /**
     * Wird über Objects.yaml gesetzt.
     *
     * @var VariableFrontend
     */
    protected $cache;

    #[Flow\Inject]
    protected Bootstrap $bootstrap;

    #[Flow\InjectConfiguration(path: 'payment.gateways.test', package: 'ISP.Lanyard')]
    protected array $settings = [];

    public function isAllowed(): bool
    {
        return !$this->bootstrap->getContext()->isProduction()
            || ($this->settings['allowInProduction'] ?? false) === true;
    }

    public function isAvailable(): bool
    {
        return $this->isAllowed();
    }

    public function startPayment(PaymentRequest $request): PaymentSession
    {
        $this->assertAllowed();

        $reference = 'test_' . bin2hex(random_bytes(12));

        $this->store($reference, [
            'bookingNumber' => $request->bookingNumber,
            'amount' => $request->amount,
            'currency' => $request->currency,
            'description' => $request->description,
            'email' => $request->email,
            'language' => $request->language,
            'returnUri' => $request->returnUri,
            'status' => PaymentStatus::OPEN,
            'paidAfter' => null,
            'createdAt' => time(),
        ]);

        $checkoutUri = (string)($this->settings['checkoutUri'] ?? '/lanyard/payment/test-gateway?reference={reference}');

        return new PaymentSession($reference, str_replace('{reference}', rawurlencode($reference), $checkoutUri));
    }

    public function fetchStatus(string $reference, ?string $transactionId = null): PaymentResult
    {
        $this->assertAllowed();

        $session = $this->getSession($reference);

        if ($session === null) {
            throw new PaymentException(sprintf('Unknown test payment "%s".', $reference), 1763640101);
        }

        // Verzögerte Bestätigung: erst nach Ablauf der Wartezeit bezahlt
        if ($session['status'] === PaymentStatus::OPEN && $session['paidAfter'] !== null && $session['paidAfter'] <= time()) {
            $session['status'] = PaymentStatus::PAID;
            $this->store($reference, $session);
        }

        return new PaymentResult((string)$session['status'], $reference, (int)$session['amount']);
    }

    /**
     * Die Rückkehr-URL trägt hier kein eigenes Geheimnis – das Token der
     * Bestellung genügt.
     */
    public function verifyReturn(ServerRequestInterface $request, ?string $returnSecret): bool
    {
        return true;
    }

    /**
     * Ohne Signatur: Das Test-Gateway meldet sich nicht per HTTP, sondern
     * über TestGatewayController direkt.
     */
    public function parseNotification(ServerRequestInterface $request): array
    {
        $this->assertAllowed();

        $body = (array)$request->getParsedBody();
        $reference = $body['reference'] ?? $request->getQueryParams()['reference'] ?? null;

        return is_string($reference) && $reference !== '' ? [new PaymentNotification($reference)] : [];
    }

    public function refund(string $reference, ?string $transactionId, int $amount, string $currency): void
    {
        $this->assertAllowed();

        $session = $this->getSession($reference);

        if ($session === null || $this->fetchStatus($reference)->status !== PaymentStatus::PAID) {
            throw new PaymentException(sprintf('Test payment "%s" is not paid, nothing to refund.', $reference), 1763640102);
        }

        $session['status'] = PaymentStatus::REFUNDED;
        $this->store($reference, $session);
    }

    /**
     * Daten eines Vorgangs für die Bezahlseite, null wenn unbekannt.
     *
     * @return array<string, mixed>|null
     */
    public function getSession(string $reference): ?array
    {
        if (preg_match('/^test_[0-9a-f]{24}$/', $reference) !== 1) {
            return null;
        }

        $session = $this->cache->get($reference);

        return is_array($session) ? $session : null;
    }

    /**
     * Der Besteller hat auf der Bezahlseite einen Ausgang gewählt. Nur
     * solange der Vorgang offen ist – wie bei einem echten Anbieter.
     *
     * @throws PaymentException
     */
    public function decide(string $reference, string $outcome): void
    {
        $this->assertAllowed();

        $session = $this->getSession($reference);

        if ($session === null || $session['status'] !== PaymentStatus::OPEN || $session['paidAfter'] !== null) {
            throw new PaymentException(sprintf('Test payment "%s" is not open.', $reference), 1763640103);
        }

        switch ($outcome) {
            case self::OUTCOME_PAID:
                $session['status'] = PaymentStatus::PAID;
                break;
            case self::OUTCOME_PAID_DELAYED:
                $session['paidAfter'] = time() + (int)($this->settings['confirmationDelay'] ?? 30);
                break;
            case self::OUTCOME_FAILED:
                $session['status'] = PaymentStatus::FAILED;
                break;
            case self::OUTCOME_CANCELLED:
                $session['status'] = PaymentStatus::CANCELLED;
                break;
            default:
                throw new PaymentException(sprintf('Unknown outcome "%s".', $outcome), 1763640104);
        }

        $this->store($reference, $session);
    }

    /**
     * @throws PaymentException
     */
    protected function assertAllowed(): void
    {
        if (!$this->isAllowed()) {
            throw new PaymentException('The test payment gateway is disabled in Production context.', 1763640100);
        }
    }

    /**
     * @param array<string, mixed> $session
     */
    protected function store(string $reference, array $session): void
    {
        // Ein echter Anbieter hält Vorgänge ebenfalls nicht ewig vor.
        $this->cache->set($reference, $session, [], 30 * 86400);
    }
}
