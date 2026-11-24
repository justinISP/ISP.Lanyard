<?php
declare(strict_types=1);

namespace ISP\Lanyard\Service;

use ISP\Lanyard\Domain\Model\Event;
use ISP\Lanyard\Domain\Model\Order;
use ISP\Lanyard\Domain\Model\OrderItem;
use ISP\Lanyard\Domain\Model\TicketType;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Validation\Validator\EmailAddressValidator;

/**
 * Bestelldaten im Backend: Bearbeiten und manuelle Buchung.
 *
 * Eingaben kommen als Array einfacher Werte, Fehler gehen als
 * Feld => Meldung zurück – wie im SetupService.
 *
 * Positionen lassen sich nachträglich nicht ändern: Sie hängen an der
 * gebuchten Kapazität. Eine andere Menge heisst stornieren und neu
 * buchen.
 */
#[Flow\Scope('singleton')]
class OrderAdminService
{
    /**
     * Bearbeitbare Felder, Höchstlänge und Bezeichnung für den
     * Änderungsverlauf. Längen wie im OrderFormService.
     */
    protected const FIELDS = [
        'organisation' => [255, 'Organisation'],
        'firstName' => [100, 'Vorname'],
        'lastName' => [100, 'Nachname'],
        'email' => [255, 'E-Mail'],
        'phone' => [255, 'Telefon'],
        'message' => [5000, 'Nachricht'],
        'internalNote' => [5000, 'Interne Notiz'],
    ];

    protected const LANGUAGES = ['de', 'en'];

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    /**
     * @return array<string, string>
     */
    public function values(Order $order): array
    {
        return [
            'ordererType' => $order->getOrdererType(),
            'organisation' => (string)$order->getOrganisation(),
            'firstName' => $order->getFirstName(),
            'lastName' => $order->getLastName(),
            'email' => $order->getEmail(),
            'phone' => (string)$order->getPhone(),
            'message' => (string)$order->getMessage(),
            'internalNote' => (string)$order->getInternalNote(),
            'language' => $order->getLanguage(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function validateDetails(array $input): array
    {
        $errors = [];
        $ordererType = $this->text($input, 'ordererType');

        if (!in_array($ordererType, [Order::TYPE_PRIVATE, Order::TYPE_GROUP], true)) {
            $errors['ordererType'] = 'Bitte auswählen.';
        }

        foreach (['firstName', 'lastName', 'email'] as $field) {
            if ($this->text($input, $field) === '') {
                $errors[$field] = 'Dieses Feld ist ein Pflichtfeld.';
            }
        }

        if ($ordererType === Order::TYPE_GROUP && $this->text($input, 'organisation') === '') {
            $errors['organisation'] = 'Bei Organisationen und Gruppen ein Pflichtfeld.';
        }

        foreach (self::FIELDS as $field => [$maximum]) {
            if (!isset($errors[$field]) && mb_strlen($this->text($input, $field)) > $maximum) {
                $errors[$field] = sprintf('Bitte höchstens %d Zeichen eingeben.', $maximum);
            }
        }

        $email = $this->text($input, 'email');

        if (!isset($errors['email']) && (new EmailAddressValidator())->validate($email)->hasErrors()) {
            $errors['email'] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
        }

        if (!in_array($this->text($input, 'language'), self::LANGUAGES, true)) {
            $errors['language'] = 'Bitte auswählen.';
        }

        return $errors;
    }

    /**
     * Übernimmt geprüfte Daten in die Bestellung.
     *
     * @param array<string, mixed> $input Bereits mit validateDetails() geprüft
     * @return array<int, string> Bezeichnungen der geänderten Felder
     */
    public function applyDetails(Order $order, array $input): array
    {
        $before = $this->values($order);
        $ordererType = $this->text($input, 'ordererType');

        $order->setOrdererType($ordererType);
        $order->setOrganisation($ordererType === Order::TYPE_GROUP ? $this->nullable($input, 'organisation') : null);
        $order->setFirstName($this->text($input, 'firstName'));
        $order->setLastName($this->text($input, 'lastName'));
        $order->setEmail($this->text($input, 'email'));
        $order->setPhone($this->nullable($input, 'phone'));
        $order->setMessage($this->nullable($input, 'message'));
        $order->setInternalNote($this->nullable($input, 'internalNote'));
        $order->setLanguage($this->text($input, 'language'));

        $after = $this->values($order);
        $labels = ['ordererType' => 'Art', 'language' => 'Sprache']
            + array_map(static fn(array $field): string => $field[1], self::FIELDS);
        $changed = [];

        foreach ($after as $field => $value) {
            if ($before[$field] !== $value) {
                $changed[] = $labels[$field] ?? $field;
            }
        }

        return $changed;
    }

    #
    # Manuelle Buchung
    #

    /**
     * Alle Ticketarten des Events, auch inaktive – das Orga-Team kann
     * z. B. Freikarten buchen, die im Formular nicht erscheinen.
     *
     * @return array<string, TicketType>
     */
    public function ticketTypesOf(Event $event): array
    {
        $ticketTypes = [];

        foreach ($event->getTicketTypes() as $ticketType) {
            $ticketTypes[(string)$this->persistenceManager->getIdentifierByObject($ticketType)] = $ticketType;
        }

        return $ticketTypes;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function validateManualOrder(Event $event, array $input): array
    {
        $errors = $this->validateDetails($input);
        $ticketTypes = $this->ticketTypesOf($event);
        $total = 0;

        foreach ($this->quantities($input) as $identifier => $quantity) {
            if ($quantity === null || $quantity < 0 || ($quantity > 0 && !isset($ticketTypes[$identifier]))) {
                $errors['tickets'] = 'Bitte nur ganze Zahlen ab 0 eingeben.';
                return $errors;
            }

            $total += $quantity;
        }

        if ($total === 0) {
            $errors['tickets'] = 'Bitte mindestens ein Ticket eintragen.';
        }

        return $errors;
    }

    /**
     * Baut eine nicht gespeicherte Bestellung. Gebucht wird sie über
     * OrderService::createManually().
     *
     * @param array<string, mixed> $input Bereits mit validateManualOrder() geprüft
     */
    public function buildManualOrder(Event $event, array $input): Order
    {
        $order = new Order($event);
        $this->applyDetails($order, $input);
        $ticketTypes = $this->ticketTypesOf($event);

        foreach ($this->quantities($input) as $identifier => $quantity) {
            if ($quantity > 0) {
                $order->addItem(new OrderItem($order, $ticketTypes[$identifier], $quantity));
            }
        }

        return $order;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, int|null> null bei ungültiger Eingabe
     */
    protected function quantities(array $input): array
    {
        $quantities = [];

        foreach ((array)($input['tickets'] ?? []) as $identifier => $value) {
            $value = is_scalar($value) ? trim((string)$value) : 'x';
            $quantities[(string)$identifier] = $value === '' ? 0 : (preg_match('/^\d+$/', $value) === 1 ? (int)$value : null);
        }

        return $quantities;
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function text(array $input, string $field): string
    {
        $value = $input[$field] ?? '';

        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function nullable(array $input, string $field): ?string
    {
        $value = $this->text($input, $field);

        return $value === '' ? null : $value;
    }
}
