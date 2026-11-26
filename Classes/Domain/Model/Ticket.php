<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;

/**
 * Ein einzelnes Ticket mit QR-Code – eines je Person.
 *
 * Entsteht, sobald eine Bestellung bezahlt ist: Eine Position mit
 * Menge 5 wird zu fünf Tickets. Ein Kombiticket ist ein einziges
 * Ticket, das an allen Tagen seiner Ticketart gilt.
 *
 * Der Code steht im QR-Code und ist nicht erratbar. Er verrät weder
 * Buchungsnummer noch Reihenfolge.
 *
 * @Flow\Entity
 * @ORM\Table(
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="code_unique", columns={"code"})
 *     }
 * )
 */
class Ticket
{
    public const STATUS_VALID = 'valid';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Spalte ausdrücklich benannt: "order" ist ein reserviertes
     * SQL-Wort.
     *
     * @var \ISP\Lanyard\Domain\Model\Order
     *
     * @ORM\ManyToOne
     * @ORM\JoinColumn(name="lanyard_order", nullable=false, onDelete="CASCADE")
     */
    protected Order $order;

    /**
     * cascade ausdrücklich leer: OrderItem hat kein Repository, Flow
     * würde sonst cascade "all" setzen – das Entfernen eines Tickets
     * löschte dann die Position mit.
     *
     * @var \ISP\Lanyard\Domain\Model\OrderItem
     *
     * @ORM\ManyToOne(cascade={})
     * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
     */
    protected OrderItem $orderItem;

    /**
     * Laufende Nummer innerhalb der Bestellung, ab 1. Nur für die
     * Anzeige ("Ticket 3 von 12").
     *
     * @var int
     */
    protected int $number = 1;

    /**
     * @var string
     *
     * @ORM\Column(length=40)
     */
    protected string $code = '';

    /**
     * @var string
     *
     * @ORM\Column(length=20)
     */
    protected string $status = self::STATUS_VALID;

    /**
     * @var \DateTimeImmutable
     */
    protected \DateTimeImmutable $issuedAt;

    /**
     * @var \DateTimeImmutable|null
     *
     * @ORM\Column(nullable=true)
     */
    protected ?\DateTimeImmutable $cancelledAt = null;

    public function __construct(Order $order, OrderItem $orderItem, int $number, string $code)
    {
        $this->order = $order;
        $this->orderItem = $orderItem;
        $this->number = $number;
        $this->code = $code;
        $this->issuedAt = new \DateTimeImmutable();
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getOrderItem(): OrderItem
    {
        return $this->orderItem;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isValid(): bool
    {
        return $this->status === self::STATUS_VALID;
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function cancel(): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->cancelledAt = new \DateTimeImmutable();
    }
}
