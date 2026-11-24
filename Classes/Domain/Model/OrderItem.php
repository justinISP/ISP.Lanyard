<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;

/**
 * Eine Position innerhalb einer Bestellung.
 *
 * Titel und Preis werden zum Bestellzeitpunkt kopiert. Ändert sich
 * später die Ticketart, bleibt nachvollziehbar, was bestellt wurde.
 *
 * In Phase 2 ist diese Entität der Ausgangspunkt für einzelne
 * Tickets mit QR-Code: eine Position mit Menge 5 wird zu fünf
 * Tickets.
 *
 * @Flow\Entity
 */
class OrderItem
{
    /**
     * Spalte ausdrücklich benannt: "order" ist ein reserviertes
     * SQL-Wort, das Doctrine beim INSERT nicht maskiert.
     *
     * @var \ISP\Lanyard\Domain\Model\Order
     *
     * @ORM\ManyToOne(inversedBy="items")
     * @ORM\JoinColumn(name="lanyard_order", nullable=false)
     */
    protected Order $order;

    /**
     * Nullable, damit eine gelöschte Ticketart die Bestellhistorie
     * nicht mitreisst.
     *
     * @var \ISP\Lanyard\Domain\Model\TicketType|null
     *
     * @ORM\ManyToOne
     * @ORM\JoinColumn(nullable=true, onDelete="SET NULL")
     */
    protected ?TicketType $ticketType = null;

    /**
     * Kopie zum Bestellzeitpunkt.
     *
     * @var string
     */
    protected string $ticketTypeTitle = '';

    /**
     * Kopie zum Bestellzeitpunkt, in Cent.
     *
     * @var int
     */
    protected int $unitPrice = 0;

    /**
     * @var int
     */
    protected int $quantity = 1;

    public function __construct(Order $order, TicketType $ticketType, int $quantity)
    {
        $this->order = $order;
        $this->ticketType = $ticketType;
        $this->quantity = $quantity;
        $this->ticketTypeTitle = $ticketType->getTitle();
        $this->unitPrice = $ticketType->getPrice();
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function setOrder(Order $order): void
    {
        $this->order = $order;
    }

    public function getTicketType(): ?TicketType
    {
        return $this->ticketType;
    }

    public function setTicketType(?TicketType $ticketType): void
    {
        $this->ticketType = $ticketType;
    }

    public function getTicketTypeTitle(): string
    {
        return $this->ticketTypeTitle;
    }

    public function setTicketTypeTitle(string $ticketTypeTitle): void
    {
        $this->ticketTypeTitle = $ticketTypeTitle;
    }

    public function getUnitPrice(): int
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(int $unitPrice): void
    {
        $this->unitPrice = $unitPrice;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = $quantity;
    }

    /** In Cent. */
    public function getSubtotal(): int
    {
        return $this->unitPrice * $this->quantity;
    }

    /**
     * Die Tage, die diese Position belegt. Leer, wenn die Ticketart
     * zwischenzeitlich gelöscht wurde – dann ist die Kapazität bereits
     * über den Statuswechsel freigegeben worden.
     *
     * @return array<int, EventDay>
     */
    public function getAffectedDays(): array
    {
        if ($this->ticketType === null) {
            return [];
        }

        return array_values($this->ticketType->getCoveredDays()->toArray());
    }
}