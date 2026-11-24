<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;

/**
 * Eine Veranstaltung mit einem oder mehreren Tagen.
 *
 * Das Package kennt keine konkreten Events – sie werden im
 * Backend gepflegt.
 *
 * @Flow\Entity
 */
class Event
{
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
     * Optionaler Kurzschlüssel, z.B. 'jugendwm'. Erlaubt es dem
     * Sitepackage, ein Event anzusprechen, ohne die technische ID
     * zu kennen.
     *
     * @var string|null
     *
     * @ORM\Column(unique=true, nullable=true)
     */
    protected ?string $identifier = null;

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
     * Reihenfolge in Auswahllisten und im Backend.
     *
     * @var int
     */
    protected int $sortingIndex = 0;

    /**
     * Nur aktive Events erscheinen im Bestellformular.
     *
     * @var bool
     */
    protected bool $active = true;

    /**
     * @var Collection<\ISP\Lanyard\Domain\Model\EventDay>
     *
     * @ORM\OneToMany(mappedBy="event", cascade={"persist", "remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"date"="ASC"})
     */
    protected Collection $days;

    /**
     * @var Collection<\ISP\Lanyard\Domain\Model\TicketType>
     *
     * @ORM\OneToMany(mappedBy="event", cascade={"persist", "remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"sortingIndex"="ASC"})
     */
    protected Collection $ticketTypes;

    public function __construct()
    {
        $this->days = new ArrayCollection();
        $this->ticketTypes = new ArrayCollection();
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

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function setIdentifier(?string $identifier): void
    {
        $this->identifier = $identifier;
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
     * @return Collection<\ISP\Lanyard\Domain\Model\EventDay>
     */
    public function getDays(): Collection
    {
        return $this->days;
    }

    public function addDay(EventDay $day): void
    {
        if (!$this->days->contains($day)) {
            $this->days->add($day);
            $day->setEvent($this);
        }
    }

    public function removeDay(EventDay $day): void
    {
        $this->days->removeElement($day);
    }

    /**
     * @return Collection<\ISP\Lanyard\Domain\Model\TicketType>
     */
    public function getTicketTypes(): Collection
    {
        return $this->ticketTypes;
    }

    public function addTicketType(TicketType $ticketType): void
    {
        if (!$this->ticketTypes->contains($ticketType)) {
            $this->ticketTypes->add($ticketType);
            $ticketType->setEvent($this);
        }
    }

    public function removeTicketType(TicketType $ticketType): void
    {
        $this->ticketTypes->removeElement($ticketType);
    }

    /**
     * Nur buchbare Ticketarten, für die Anzeige im Formular.
     *
     * @return array<int, TicketType>
     */
    public function getBookableTicketTypes(): array
    {
        return array_values(
            $this->ticketTypes->filter(
                fn(TicketType $type): bool => $type->isBookable()
            )->toArray()
        );
    }
}