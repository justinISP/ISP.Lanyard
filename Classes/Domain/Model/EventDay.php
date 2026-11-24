<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;

/**
 * Ein einzelner Veranstaltungstag. Trägt die Kapazität – nicht das
 * Event, denn die Plätze gelten pro Tag.
 *
 * reservedSeats ist ein Zähler und keine Summe über die Bestellungen.
 * Ein Zähler lässt sich mit einem Lock absichern, eine Aggregation
 * über wachsende Datenmengen nicht.
 *
 * @Flow\Entity
 * @ORM\Table(
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="event_date", columns={"event", "date"})
 *     }
 * )
 */
class EventDay
{
    /**
     * @var \ISP\Lanyard\Domain\Model\Event
     *
     * @ORM\ManyToOne(inversedBy="days")
     * @ORM\JoinColumn(nullable=false)
     */
    protected Event $event;

    /**
     * @var \DateTimeImmutable
     *
     * @ORM\Column(type="date_immutable")
     */
    protected \DateTimeImmutable $date;

    /**
     * Abweichende Bezeichnung, z.B. 'Eröffnungsfeier'. Ohne Angabe
     * wird das Datum angezeigt.
     *
     * @var string|null
     *
     * @ORM\Column(nullable=true)
     */
    protected ?string $title = null;

    /**
     * Englische Fassung. Leer heisst: Rückfall auf $title.
     *
     * @var string|null
     *
     * @ORM\Column(nullable=true)
     */
    protected ?string $titleEn = null;

    /**
     * Gesamtzahl der Plätze an diesem Tag. Im Backend änderbar,
     * auch nachträglich.
     *
     * @var int
     */
    protected int $capacity = 0;

    /**
     * Belegte Plätze, einschliesslich der nur vorgemerkten.
     * Wird ausschliesslich über den Kapazitätsdienst verändert.
     *
     * @var int
     */
    protected int $reservedSeats = 0;

    /**
     * Sperrt den Tag für neue Bestellungen, unabhängig von der
     * Restkapazität.
     *
     * @var bool
     */
    protected bool $closed = false;

    public function __construct(Event $event, \DateTimeImmutable $date, int $capacity)
    {
        $this->event = $event;
        $this->date = $date;
        $this->capacity = $capacity;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function setEvent(Event $event): void
    {
        $this->event = $event;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): void
    {
        $this->date = $date;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function getTitleEn(): ?string
    {
        return $this->titleEn;
    }

    public function setTitleEn(?string $titleEn): void
    {
        $this->titleEn = $titleEn;
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function setCapacity(int $capacity): void
    {
        $this->capacity = $capacity;
    }

    public function getReservedSeats(): int
    {
        return $this->reservedSeats;
    }

    /**
     * Nur für den Kapazitätsdienst. Nicht aus Controllern aufrufen –
     * die Zählung muss innerhalb einer Transaktion mit Lock laufen.
     */
    public function setReservedSeats(int $reservedSeats): void
    {
        $this->reservedSeats = $reservedSeats;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function setClosed(bool $closed): void
    {
        $this->closed = $closed;
    }

    /**
     * Kann negativ werden, wenn die Kapazität nachträglich unter die
     * Zahl bestehender Buchungen gesenkt wird. Das ist gewollt – das
     * Backend soll die Überbuchung sehen, statt sie zu verbergen.
     */
    public function getAvailableSeats(): int
    {
        return $this->capacity - $this->reservedSeats;
    }

    public function isBookable(int $requestedSeats = 1): bool
    {
        return !$this->closed && $this->getAvailableSeats() >= $requestedSeats;
    }

    public function getUtilisationPercentage(): float
    {
        if ($this->capacity <= 0) {
            return 100.0;
        }

        return round(($this->reservedSeats / $this->capacity) * 100, 1);
    }

    /**
     * Beschriftung für Listen: eigener Titel, sonst das Datum.
     */
    public function getLabel(string $language = 'de'): string
    {
        if ($language !== 'de' && $this->titleEn !== null && $this->titleEn !== '') {
            return $this->titleEn;
        }

        return $this->title ?? $this->date->format('d.m.Y');
    }
}