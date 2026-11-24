<?php
declare(strict_types=1);

namespace ISP\Lanyard\Service;

use ISP\Lanyard\Domain\Exception\CapacityExceededException;
use ISP\Lanyard\Domain\Model\Event;
use ISP\Lanyard\Domain\Model\Order;
use ISP\Lanyard\Domain\Model\OrderItem;
use ISP\Lanyard\Domain\Model\TicketType;
use ISP\Lanyard\Domain\Repository\EventRepository;
use ISP\Lanyard\Domain\Repository\TicketTypeRepository;
use ISP\Lanyard\Domain\Service\CapacityService;
use ISP\Lanyard\Payment\PaymentService;
use Neos\Error\Messages\Error;
use Neos\Error\Messages\Result;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Cryptography\HashService;
use Neos\Flow\Security\Exception as SecurityException;
use Neos\Flow\Validation\Validator\EmailAddressValidator;
use Neos\Flow\Validation\ValidatorResolver;

/**
 * Prüft und übersetzt die Daten des Bestellformulars.
 *
 * Das Schema im Fusion-Formular wandelt nur Typen um. Alle Regeln
 * stehen hier, weil sie feldübergreifend sind: Pflichtfelder hängen
 * vom Besteller-Typ ab, Obergrenzen von der Summe aller Positionen,
 * die Kapazität vom Zusammenspiel der Tage.
 *
 * Alle Fehler tragen einen eigenen Code, damit das Sitepackage sie
 * über ISP.Lanyard/ValidationErrors.xlf übersetzen kann. Die Texte
 * hier sind nur der Rückfall.
 */
#[Flow\Scope('singleton')]
class OrderFormService
{
    /**
     * Felder mit Kontaktdaten und ihre Höchstlänge. Muss zu den
     * Validierungen an der Order passen, sonst scheitert erst das
     * Speichern.
     */
    protected const CONTACT_FIELDS = [
        'organisation' => 255,
        'firstName' => 100,
        'lastName' => 100,
        'email' => 255,
        'phone' => 255,
        'message' => 5000,
    ];

    #[Flow\Inject]
    protected EventRepository $eventRepository;

    #[Flow\Inject]
    protected TicketTypeRepository $ticketTypeRepository;

    #[Flow\Inject]
    protected CapacityService $capacityService;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    #[Flow\Inject]
    protected HashService $hashService;

    #[Flow\Inject]
    protected ValidatorResolver $validatorResolver;

    #[Flow\Inject]
    protected PaymentService $paymentService;

    #[Flow\InjectConfiguration(path: 'order', package: 'ISP.Lanyard')]
    protected array $orderSettings = [];

    #[Flow\InjectConfiguration(path: 'spamProtection', package: 'ISP.Lanyard')]
    protected array $spamProtectionSettings = [];

    /**
     * Ein Event, das im Formular bestellt werden kann – nur aktive.
     */
    public function findEvent(string $identifier): ?Event
    {
        if ($identifier === '') {
            return null;
        }

        $event = $this->eventRepository->findOneByIdentifier($identifier);

        return $event instanceof Event && $event->isActive() ? $event : null;
    }

    /**
     * Ticketarten des Events, nach Identifier.
     *
     * @return array<string, TicketType>
     */
    public function findTicketTypes(Event $event): array
    {
        $ticketTypes = [];

        foreach ($this->ticketTypeRepository->findActiveByEvent($event) as $ticketType) {
            $ticketTypes[(string)$this->persistenceManager->getIdentifierByObject($ticketType)] = $ticketType;
        }

        return $ticketTypes;
    }

    /**
     * Signierter Zeitstempel für die Zeitfalle. Signiert, damit ein
     * Bot keinen beliebig alten Wert mitschicken kann.
     */
    public function createFormToken(): string
    {
        return $this->hashService->appendHmac((string)time());
    }

    /**
     * Gehasht mit dem Schlüssel der Installation – die IP lässt sich
     * damit wiedererkennen, aber nicht zurückrechnen.
     */
    public function hashClientIp(string $clientIp): string
    {
        return $this->hashService->generateHmac($clientIp);
    }

    /**
     * Welche Kontaktfelder für welchen Besteller-Typ Pflicht sind,
     * für die Kennzeichnung im Formular.
     *
     * @return array<string, string> Feld => 'always', 'group' oder 'private'
     */
    public function getFieldRequirements(): array
    {
        $private = (array)($this->orderSettings['requiredFields'][Order::TYPE_PRIVATE] ?? []);
        $group = (array)($this->orderSettings['requiredFields'][Order::TYPE_GROUP] ?? []);
        $requirements = [];

        foreach (array_unique(array_merge($private, $group)) as $field) {
            $inPrivate = in_array($field, $private, true);
            $inGroup = in_array($field, $group, true);
            $requirements[$field] = $inPrivate && $inGroup ? 'always' : ($inGroup ? Order::TYPE_GROUP : Order::TYPE_PRIVATE);
        }

        return $requirements;
    }

    /**
     * @param array<string, mixed> $data Vom Schema umgewandelte Werte
     * @param array<string, mixed> $rawArguments Unveränderte Formulardaten, für Honeypots
     */
    public function validate(Event $event, array $data, array $rawArguments): Result
    {
        $result = new Result();

        $this->validateSpamProtection($data, $rawArguments, $result);

        $ordererType = (string)($data['ordererType'] ?? '');

        if (!in_array($ordererType, [Order::TYPE_PRIVATE, Order::TYPE_GROUP], true)) {
            $result->forProperty('ordererType')->addError(
                new Error('Bitte wählen Sie aus, als wer Sie bestellen.', 1761480101)
            );
            $ordererType = Order::TYPE_PRIVATE;
        }

        $this->validateContact($ordererType, $data, $result);
        $this->validateTickets($event, $ordererType, $data, $result);
        $this->validatePaymentMethod($data, $result);

        return $result;
    }

    /**
     * Baut eine nicht gespeicherte Bestellung aus geprüften Daten.
     *
     * @param array<string, mixed> $data
     */
    public function buildOrder(Event $event, array $data, string $language): Order
    {
        $order = new Order($event);
        $ordererType = (string)($data['ordererType'] ?? Order::TYPE_PRIVATE);

        $order->setOrdererType($ordererType);
        $order->setOrganisation($ordererType === Order::TYPE_GROUP ? $this->stringValue($data, 'organisation') : null);
        $order->setFirstName((string)$this->stringValue($data, 'firstName'));
        $order->setLastName((string)$this->stringValue($data, 'lastName'));
        $order->setEmail((string)$this->stringValue($data, 'email'));
        $order->setPhone($this->stringValue($data, 'phone'));
        $order->setMessage($this->stringValue($data, 'message'));
        $order->setPrivacyConsent(($data['privacyConsent'] ?? false) === true);
        $order->setLanguage($language);
        $order->setPaymentMethod($this->paymentMethod($data));

        if (isset($data['clientIpHash']) && is_string($data['clientIpHash'])) {
            $order->setClientIpHash($data['clientIpHash']);
        }

        $ticketTypes = $this->findTicketTypes($event);

        foreach ($this->quantities($data) as $identifier => $quantity) {
            if ($quantity > 0 && isset($ticketTypes[$identifier])) {
                $order->addItem(new OrderItem($order, $ticketTypes[$identifier], $quantity));
            }
        }

        return $order;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rawArguments
     */
    protected function validateSpamProtection(array $data, array $rawArguments, Result $result): void
    {
        $emptyValidator = $this->validatorResolver->createValidator('ISP.Lanyard:Empty');

        foreach ((array)($this->spamProtectionSettings['honeypotFields'] ?? []) as $field) {
            $result->forProperty((string)$field)->merge($emptyValidator->validate($rawArguments[$field] ?? null));
        }

        $minimumDuration = (int)($this->spamProtectionSettings['minimumFormDuration'] ?? 0);

        try {
            $startedAt = (int)$this->hashService->validateAndStripHmac((string)($data['formToken'] ?? ''));
        } catch (SecurityException) {
            $startedAt = null;
        }

        if ($startedAt === null || time() - $startedAt < $minimumDuration) {
            $result->forProperty('formToken')->addError(
                new Error('Das Formular konnte nicht verarbeitet werden. Bitte senden Sie es erneut ab.', 1761480102)
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function validateContact(string $ordererType, array $data, Result $result): void
    {
        $required = (array)($this->orderSettings['requiredFields'][$ordererType] ?? []);

        foreach (self::CONTACT_FIELDS as $field => $maximumLength) {
            $value = $this->stringValue($data, $field);

            if ($value === null) {
                if (in_array($field, $required, true)) {
                    $result->forProperty($field)->addError(new Error('Dieses Feld ist ein Pflichtfeld.', 1761480103));
                }
                continue;
            }

            if (mb_strlen($value) > $maximumLength) {
                $result->forProperty($field)->addError(
                    new Error('Bitte höchstens %d Zeichen eingeben.', 1761480104, [$maximumLength])
                );
            }
        }

        $email = $this->stringValue($data, 'email');

        if ($email !== null && (new EmailAddressValidator())->validate($email)->hasErrors()) {
            $result->forProperty('email')->addError(
                new Error('Bitte geben Sie eine gültige E-Mail-Adresse ein.', 1761480105)
            );
        }

        if (in_array('privacyConsent', $required, true) && ($data['privacyConsent'] ?? false) !== true) {
            $result->forProperty('privacyConsent')->addError(
                new Error('Bitte stimmen Sie der Datenschutzerklärung zu.', 1761480106)
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function validateTickets(Event $event, string $ordererType, array $data, Result $result): void
    {
        $ticketResult = $result->forProperty('tickets');
        $ticketTypes = $this->findTicketTypes($event);
        $quantities = $this->quantities($data);
        $total = 0;

        foreach ($quantities as $identifier => $quantity) {
            if ($quantity === 0) {
                continue;
            }

            $ticketType = $ticketTypes[$identifier] ?? null;

            if ($ticketType === null || $quantity < 0) {
                $ticketResult->addError(new Error('Die Auswahl enthält ungültige Tickets.', 1761480107));
                return;
            }

            if ($ticketType->getMaxPerOrder() > 0 && $quantity > $ticketType->getMaxPerOrder()) {
                $ticketResult->addError(new Error(
                    'Von „%s“ sind höchstens %d Tickets pro Bestellung möglich.',
                    1761480108,
                    [$ticketType->getTitle(), $ticketType->getMaxPerOrder()]
                ));
            }

            $total += $quantity;
        }

        if ($total === 0) {
            $ticketResult->addError(new Error('Bitte wählen Sie mindestens ein Ticket aus.', 1761480109));
            return;
        }

        $maximum = (int)($this->orderSettings['maxTicketsPerOrder'][$ordererType] ?? 0);

        if ($maximum > 0 && $total > $maximum) {
            $ticketResult->addError(new Error(
                'Pro Bestellung sind höchstens %d Tickets möglich.',
                1761480110,
                [$maximum]
            ));
        }

        if ($ticketResult->hasErrors()) {
            return;
        }

        // Momentaufnahme ohne Lock – verbindlich gebucht wird erst beim
        // Opt-in oder, bei Online-Zahlung, in der PlaceOrderAction. Hier geht es nur darum, aussichtslose Anfragen gar
        // nicht erst anzunehmen.
        try {
            $this->capacityService->assertAvailable($this->buildOrder($event, $data, 'de'));
        } catch (CapacityExceededException $exception) {
            $day = $exception->getAffectedDay();
            $ticketResult->addError(new Error(
                'Für %s sind nicht mehr genügend Plätze frei (noch %d verfügbar).',
                1761480111,
                [$day !== null ? $day->getLabel() : '', max(0, $exception->getAvailableSeats())]
            ));
        }
    }

    /**
     * Nur wenn Zahlungsarten eingeschaltet sind – sonst fragt das
     * Formular keine ab.
     *
     * @param array<string, mixed> $data
     */
    protected function validatePaymentMethod(array $data, Result $result): void
    {
        if ($this->paymentService->getMethods() === []) {
            return;
        }

        if ($this->paymentMethod($data) === null) {
            $result->forProperty('paymentMethod')->addError(
                new Error('Bitte wählen Sie eine Zahlungsart.', 1761480112)
            );
        }
    }

    /**
     * Die gewählte Zahlungsart, null wenn keine oder keine gültige.
     *
     * @param array<string, mixed> $data
     */
    public function paymentMethod(array $data): ?string
    {
        $method = $this->stringValue($data, 'paymentMethod');

        return $method !== null && $this->paymentService->isMethodAvailable($method) ? $method : null;
    }

    /**
     * Mengen je Ticketart. Leere oder nicht numerische Eingaben
     * zählen als 0.
     *
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    protected function quantities(array $data): array
    {
        $quantities = [];

        foreach ((array)($data['tickets'] ?? []) as $identifier => $quantity) {
            $quantities[(string)$identifier] = is_numeric($quantity) ? (int)$quantity : 0;
        }

        return $quantities;
    }

    /**
     * Getrimmter String, null wenn leer.
     *
     * @param array<string, mixed> $data
     */
    protected function stringValue(array $data, string $field): ?string
    {
        $value = $data[$field] ?? null;

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }
}
