<?php
declare(strict_types=1);

namespace ISP\Lanyard\Service;

use ISP\Lanyard\Domain\Model\Event;
use ISP\Lanyard\Domain\Model\EventDay;
use ISP\Lanyard\Domain\Model\OrderStatus;
use ISP\Lanyard\Domain\Model\TicketType;
use ISP\Lanyard\Domain\Repository\EventDayRepository;
use ISP\Lanyard\Domain\Repository\EventRepository;
use ISP\Lanyard\Domain\Repository\OrderRepository;
use ISP\Lanyard\Domain\Repository\TicketTypeRepository;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;

/**
 * Pflege der Stammdaten: Events, Tage, Ticketarten.
 *
 * Jede Eingabe kommt als Array einfacher Werte und wird hier geprüft,
 * bevor sie eine Entity berührt. validate*() liefert Fehler je Feld,
 * save*() speichert nur geprüfte Daten.
 *
 * Die Schutzregeln verhindern Änderungen, die die Platzzähler
 * verfälschen würden: Das Freigeben von Plätzen rechnet über die
 * aktuellen Tage einer Ticketart.
 */
#[Flow\Scope('singleton')]
class SetupService
{
    protected const IDENTIFIER_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    protected const INTEGER_PATTERN = '/^-?\d+$/';
    protected const PRICE_PATTERN = '/^(\d{1,7})(?:[,.](\d{1,2}))?$/';

    #[Flow\Inject]
    protected EventRepository $eventRepository;

    #[Flow\Inject]
    protected EventDayRepository $eventDayRepository;

    #[Flow\Inject]
    protected TicketTypeRepository $ticketTypeRepository;

    #[Flow\Inject]
    protected OrderRepository $orderRepository;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    #[Flow\InjectConfiguration(path: 'capacity.defaultPerDay', package: 'ISP.Lanyard')]
    protected int $defaultCapacity = 1000;

    public function getDefaultCapacity(): int
    {
        return $this->defaultCapacity;
    }

    #
    # Events
    #

    /**
     * @param array<string, mixed> $input
     * @return array<string, string> Feld => Meldung
     */
    public function validateEvent(array $input, ?Event $event): array
    {
        $errors = [];

        $this->requireText($input, 'title', 255, $errors);
        $this->limitText($input, 'titleEn', 255, $errors);

        $identifier = $this->text($input, 'identifier');

        if ($identifier !== '') {
            if (strlen($identifier) > 255 || preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
                $errors['identifier'] = 'Nur Kleinbuchstaben, Ziffern und Bindestriche, z. B. "jugend-wm".';
            } else {
                $existing = $this->eventRepository->findOneByIdentifier($identifier);

                if ($existing !== null && $existing !== $event) {
                    $errors['identifier'] = 'Dieser Kurzschlüssel ist bereits vergeben.';
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $input Bereits mit validateEvent() geprüft
     */
    public function saveEvent(array $input, ?Event $event): Event
    {
        $isNew = $event === null;
        $event ??= new Event();

        $event->setTitle($this->text($input, 'title'));
        $event->setTitleEn($this->nullableText($input, 'titleEn'));
        $event->setIdentifier($this->nullableText($input, 'identifier'));
        $event->setDescription($this->nullableText($input, 'description'));
        $event->setDescriptionEn($this->nullableText($input, 'descriptionEn'));
        $event->setActive($this->flag($input, 'active'));

        $isNew ? $this->eventRepository->add($event) : $this->eventRepository->update($event);

        return $event;
    }

    /**
     * @return string|null Grund, warum nicht gelöscht werden darf
     */
    public function preventEventDeletion(Event $event): ?string
    {
        if ($this->orderRepository->countByEvent($event) > 0) {
            return 'Zu dieser Veranstaltung gibt es Bestellungen. Sie lässt sich nur deaktivieren, nicht löschen.';
        }

        return null;
    }

    public function deleteEvent(Event $event): void
    {
        $this->eventRepository->remove($event);
    }

    #
    # Tage
    #

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function validateDay(array $input, Event $event, ?EventDay $day): array
    {
        $errors = [];

        $date = $this->parseDate($this->text($input, 'date'));

        if ($date === null) {
            $errors['date'] = 'Bitte ein gültiges Datum angeben.';
        } else {
            foreach ($event->getDays() as $existing) {
                if ($existing !== $day && $existing->getDate()->format('Y-m-d') === $date->format('Y-m-d')) {
                    $errors['date'] = 'Für dieses Datum gibt es bereits einen Tag.';
                }
            }
        }

        $capacity = $this->text($input, 'capacity');

        if (preg_match(self::INTEGER_PATTERN, $capacity) !== 1 || (int)$capacity < 0) {
            $errors['capacity'] = 'Bitte eine ganze Zahl ab 0 angeben.';
        }

        $this->limitText($input, 'title', 255, $errors);
        $this->limitText($input, 'titleEn', 255, $errors);

        return $errors;
    }

    /**
     * @param array<string, mixed> $input Bereits mit validateDay() geprüft
     * @return array<int, string> Hinweise, z. B. zur Überbuchung
     */
    public function saveDay(array $input, Event $event, ?EventDay $day): array
    {
        $date = $this->parseDate($this->text($input, 'date'));
        $capacity = (int)$this->text($input, 'capacity');

        if ($day === null) {
            $day = new EventDay($event, $date, $capacity);
            $event->addDay($day);
            $this->eventDayRepository->add($day);
        } else {
            $day->setDate($date);
            $day->setCapacity($capacity);
            $this->eventDayRepository->update($day);
        }

        $day->setTitle($this->nullableText($input, 'title'));
        $day->setTitleEn($this->nullableText($input, 'titleEn'));
        $day->setClosed($this->flag($input, 'closed'));

        $notes = [];

        if ($day->getAvailableSeats() < 0) {
            $notes[] = sprintf(
                'Der Tag ist überbucht: %d Plätze belegt, Kapazität %d.',
                $day->getReservedSeats(),
                $day->getCapacity()
            );
        }

        return $notes;
    }

    public function preventDayDeletion(EventDay $day): ?string
    {
        if ($day->getReservedSeats() > 0) {
            return 'An diesem Tag sind Plätze belegt. Sperren Sie ihn stattdessen.';
        }

        foreach ($day->getEvent()->getTicketTypes() as $ticketType) {
            if ($ticketType->getCoveredDays()->contains($day)) {
                return sprintf('Die Ticketart „%s“ deckt diesen Tag ab. Entfernen Sie ihn dort zuerst.', $ticketType->getTitle());
            }
        }

        return null;
    }

    public function deleteDay(EventDay $day): void
    {
        $day->getEvent()->removeDay($day);
        $this->eventDayRepository->remove($day);
    }

    #
    # Ticketarten
    #

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function validateTicketType(array $input, Event $event, ?TicketType $ticketType): array
    {
        $errors = [];

        $this->requireText($input, 'title', 255, $errors);
        $this->limitText($input, 'titleEn', 255, $errors);
        $this->optionalInteger($input, 'sortingIndex', $errors);

        if ($this->parsePrice($this->text($input, 'price')) === null) {
            $errors['price'] = 'Bitte einen Preis in Euro angeben, z. B. 18,50.';
        }

        $maxPerOrder = $this->text($input, 'maxPerOrder');

        if ($maxPerOrder !== '' && (preg_match(self::INTEGER_PATTERN, $maxPerOrder) !== 1 || (int)$maxPerOrder < 0)) {
            $errors['maxPerOrder'] = 'Bitte eine ganze Zahl ab 0 angeben (0 = keine eigene Grenze).';
        }

        $requested = $this->dayIdentifiers($input);
        $known = $this->dayIdentifiersOf($event);

        if (array_diff($requested, $known) !== []) {
            $errors['coveredDays'] = 'Die Auswahl enthält Tage, die nicht zu dieser Veranstaltung gehören.';
        } elseif ($ticketType !== null && $this->isCoverageLocked($ticketType) && $this->coverageChanged($ticketType, $requested)) {
            $errors['coveredDays'] = 'Die Tage lassen sich nicht mehr ändern, weil für diese Ticketart bereits Plätze gebucht sind.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $input Bereits mit validateTicketType() geprüft
     */
    public function saveTicketType(array $input, Event $event, ?TicketType $ticketType): TicketType
    {
        $title = $this->text($input, 'title');
        $price = (int)$this->parsePrice($this->text($input, 'price'));
        $isNew = $ticketType === null;

        if ($isNew) {
            $ticketType = new TicketType($event, $title, $price);
            $event->addTicketType($ticketType);
        } else {
            $ticketType->setTitle($title);
            $ticketType->setPrice($price);
        }

        $ticketType->setTitleEn($this->nullableText($input, 'titleEn'));
        $ticketType->setDescription($this->nullableText($input, 'description'));
        $ticketType->setDescriptionEn($this->nullableText($input, 'descriptionEn'));
        $ticketType->setMaxPerOrder((int)$this->text($input, 'maxPerOrder'));
        $ticketType->setSortingIndex((int)$this->text($input, 'sortingIndex'));
        $ticketType->setActive($this->flag($input, 'active'));

        $requested = $this->dayIdentifiers($input);

        foreach ($event->getDays() as $day) {
            $selected = in_array($this->identifierOf($day), $requested, true);

            if ($selected) {
                $ticketType->addCoveredDay($day);
            } else {
                $ticketType->removeCoveredDay($day);
            }
        }

        $isNew ? $this->ticketTypeRepository->add($ticketType) : $this->ticketTypeRepository->update($ticketType);

        return $ticketType;
    }

    /**
     * Wie validateTicketType(), zusätzlich muss mindestens ein Tag
     * gewählt sein.
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function validateDayTickets(array $input, Event $event): array
    {
        $errors = $this->validateTicketType($input, $event, null);

        if (!isset($errors['coveredDays']) && $this->dayIdentifiers($input) === []) {
            $errors['coveredDays'] = 'Bitte mindestens einen Tag auswählen.';
        }

        return $errors;
    }

    /**
     * Legt je gewähltem Tag eine eigene Ticketart an, die nur diesen
     * Tag abdeckt – mit gleichem Titel, Preis und gleichen Grenzen.
     * Das Formular gliedert solche Ticketarten unter dem jeweiligen Tag.
     *
     * @param array<string, mixed> $input Bereits mit validateDayTickets() geprüft
     * @return int Anzahl angelegter Ticketarten
     */
    public function saveDayTickets(array $input, Event $event): int
    {
        $count = 0;

        foreach ($this->dayIdentifiers($input) as $dayIdentifier) {
            $this->saveTicketType(array_merge($input, ['coveredDays' => [$dayIdentifier]]), $event, null);
            $count++;
        }

        return $count;
    }

    /**
     * Sobald Plätze für die Ticketart belegt sind, dürfen sich die Tage
     * nicht mehr ändern – sonst gäbe ein Storno Plätze an Tagen frei,
     * die nie gebucht wurden.
     */
    public function isCoverageLocked(TicketType $ticketType): bool
    {
        return $this->orderRepository->countByTicketType($ticketType, OrderStatus::blocking()) > 0;
    }

    public function preventTicketTypeDeletion(TicketType $ticketType): ?string
    {
        // Auch unbestätigte Bestellungen zählen: Beim Opt-in würde sonst
        // über eine gelöschte Ticketart gebucht – also gar nicht.
        $open = array_values(array_diff(OrderStatus::all(), OrderStatus::closed()));

        if ($this->orderRepository->countByTicketType($ticketType, $open) > 0) {
            return 'Für diese Ticketart gibt es offene Bestellungen. Deaktivieren Sie sie stattdessen.';
        }

        return null;
    }

    public function deleteTicketType(TicketType $ticketType): void
    {
        $ticketType->getEvent()->removeTicketType($ticketType);
        $this->ticketTypeRepository->remove($ticketType);
    }

    #
    # Umwandlung für die Formulare
    #

    /**
     * Euro-Eingabe in Cent, ohne Umweg über Fliesskommazahlen.
     * "18", "18,5", "18,50" und "18.50" sind gültig.
     */
    public function parsePrice(string $value): ?int
    {
        $value = str_replace([' ', '€'], '', $value);

        if (preg_match(self::PRICE_PATTERN, $value, $matches) !== 1) {
            return null;
        }

        return (int)$matches[1] * 100 + (int)str_pad($matches[2] ?? '0', 2, '0');
    }

    public function formatPriceForInput(int $cents): string
    {
        return sprintf('%d,%02d', intdiv($cents, 100), $cents % 100);
    }

    public function identifierOf(object $object): string
    {
        return (string)$this->persistenceManager->getIdentifierByObject($object);
    }

    #
    # Hilfen
    #

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    protected function requireText(array $input, string $field, int $maximum, array &$errors): void
    {
        if ($this->text($input, $field) === '') {
            $errors[$field] = 'Dieses Feld ist ein Pflichtfeld.';
            return;
        }

        $this->limitText($input, $field, $maximum, $errors);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    protected function limitText(array $input, string $field, int $maximum, array &$errors): void
    {
        if (mb_strlen($this->text($input, $field)) > $maximum) {
            $errors[$field] = sprintf('Bitte höchstens %d Zeichen eingeben.', $maximum);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    protected function optionalInteger(array $input, string $field, array &$errors): void
    {
        $value = $this->text($input, $field);

        if ($value !== '' && preg_match(self::INTEGER_PATTERN, $value) !== 1) {
            $errors[$field] = 'Bitte eine ganze Zahl angeben.';
        }
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
    protected function nullableText(array $input, string $field): ?string
    {
        $value = $this->text($input, $field);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function flag(array $input, string $field): bool
    {
        return in_array($input[$field] ?? null, ['1', 1, true, 'on'], true);
    }

    protected function parseDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, string>
     */
    protected function dayIdentifiers(array $input): array
    {
        $days = $input['coveredDays'] ?? [];

        return is_array($days) ? array_values(array_unique(array_map('strval', array_filter($days)))) : [];
    }

    /**
     * @return array<int, string>
     */
    protected function dayIdentifiersOf(Event $event): array
    {
        $identifiers = [];

        foreach ($event->getDays() as $day) {
            $identifiers[] = $this->identifierOf($day);
        }

        return $identifiers;
    }

    /**
     * @param array<int, string> $requested
     */
    protected function coverageChanged(TicketType $ticketType, array $requested): bool
    {
        $current = [];

        foreach ($ticketType->getCoveredDays() as $day) {
            $current[] = $this->identifierOf($day);
        }

        sort($current);
        sort($requested);

        return $current !== $requested;
    }
}
