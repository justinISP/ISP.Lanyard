<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;

/**
 * Eine buchbare Ticketart.
 *
 * Tagesticket und Kombiticket sind dasselbe Konstrukt mit
 * unterschiedlich vielen abgedeckten Tagen. Das Package trifft
 * dazu keine Annahme.
 *
 * @Flow\Entity
 */
class TicketType
{
    /**
     * @var \ISP\Lanyard\Domain\Model\Event
     *
     * @ORM\ManyToOne(inversedBy="ticketTypes")
     * @ORM\JoinColumn(nullable=false)
     */
    protected Event $event;

    /**
     * @var string
     *
     * @Flow\Validate(type="NotEmpty")
     * @Flow\Validate(type="StringLength", options={"maximum"=255})
     */
    protected string $title = '';

    /**
     * Englische Fassung. Leer heisst: Rückfall auf $title.
     *
     * @var string|null
     *
     * @ORM\Column(nullable=true)
     */
    protected ?string $titleEn = null;

    /**
     * @var string|null
     *
     * @ORM\Column(type="text", nullable=true)
     */
    protected ?string $description = null;

    /**
     * @var string|null
     *
     * @ORM\Column(type="text", nullable=true)
     */
    protected ?string $descriptionEn = null;

    /**
     * Preis in Cent. Keine Fliesskommazahl, um Rundungsfehler
     * auszuschliessen.
     *
     * @var int
     */
    protected int $price = 0;

    /**
     * Tage, die dieses Ticket abdeckt. Jeder davon wird bei einer
     * Bestellung belegt.
     *
     * ON DELETE CASCADE auf beiden Seiten der Join-Tabelle: Beim
     * Löschen eines Events entfernt Doctrine die Tage vor den
     * Ticketarten, ohne Cascade blockiert der Fremdschlüssel.
     *
     * @var Collection<\ISP\Lanyard\Domain\Model\EventDay>
     *
     * @ORM\ManyToMany
     * @ORM\JoinTable(
     *     joinColumns={@ORM\JoinColumn(onDelete="CASCADE")},
     *     inverseJoinColumns={@ORM\JoinColumn(onDelete="CASCADE")}
     * )
     * @ORM\OrderBy({"date"="ASC"})
     */
    protected Collection $coveredDays;

    /**
     * Obergrenze je Bestellung für diese Ticketart. 0 heisst:
     * nur die allgemeine Grenze aus den Settings gilt.
     *
     * @var int
     */
    protected int $maxPerOrder = 0;

    /**
     * @var int
     */
    protected int $sortingIndex = 0;

    /**
     * @var bool
     */
    protected bool $active = true;

    public function __construct(Event $event, string $title, int $price)
    {
        $this->event = $event;
        $this->title = $title;
        $this->price = $price;
        $this->coveredDays = new ArrayCollection();
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function setEvent(Event $event): void
    {
        $this->event = $event;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
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

    /**
     * Titel in der gewünschten Sprache, mit Rückfall auf Deutsch.
     */
    public function getLocalisedTitle(string $language = 'de'): string
    {
        if ($language !== 'de' && $this->titleEn !== null && $this->titleEn !== '') {
            return $this->titleEn;
        }

        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getDescriptionEn(): ?string
    {
        return $this->descriptionEn;
    }

    public function setDescriptionEn(?string $descriptionEn): void
    {
        $this->descriptionEn = $descriptionEn;
    }

    public function getLocalisedDescription(string $language = 'de'): ?string
    {
        if ($language !== 'de' && $this->descriptionEn !== null && $this->descriptionEn !== '') {
            return $this->descriptionEn;
        }

        return $this->description;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function setPrice(int $price): void
    {
        $this->price = $price;
    }

    /**
     * @return Collection<\ISP\Lanyard\Domain\Model\EventDay>
     */
    public function getCoveredDays(): Collection
    {
        return $this->coveredDays;
    }

    public function addCoveredDay(EventDay $day): void
    {
        if (!$this->coveredDays->contains($day)) {
            $this->coveredDays->add($day);
        }
    }

    public function removeCoveredDay(EventDay $day): void
    {
        $this->coveredDays->removeElement($day);
    }

    public function getMaxPerOrder(): int
    {
        return $this->maxPerOrder;
    }

    public function setMaxPerOrder(int $maxPerOrder): void
    {
        $this->maxPerOrder = $maxPerOrder;
    }

    public function getSortingIndex(): int
    {
        return $this->sortingIndex;
    }

    public function setSortingIndex(int $sortingIndex): void
    {
        $this->sortingIndex = $sortingIndex;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    /**
     * Buchbar nur, wenn aktiv, mindestens ein Tag zugeordnet ist und
     * jeder dieser Tage noch freie Plätze hat. Ein Kombiticket ist
     * ausverkauft, sobald ein einziger seiner Tage voll ist.
     */
    public function isBookable(int $quantity = 1): bool
    {
        if (!$this->active || $this->coveredDays->isEmpty()) {
            return false;
        }

        foreach ($this->coveredDays as $day) {
            if (!$day->isBookable($quantity)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Wie viele Tickets dieser Art noch verfügbar sind – begrenzt
     * durch den knappsten abgedeckten Tag.
     */
    public function getAvailableQuantity(): int
    {
        if ($this->coveredDays->isEmpty()) {
            return 0;
        }

        $available = PHP_INT_MAX;

        foreach ($this->coveredDays as $day) {
            if ($day->isClosed()) {
                return 0;
            }
            $available = min($available, $day->getAvailableSeats());
        }

        return max(0, $available);
    }
}