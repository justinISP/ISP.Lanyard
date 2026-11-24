<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;

/**
 * Ein Eintrag im Änderungsverlauf einer Bestellung.
 *
 * Wird nur angelegt, nie geändert. Verschwindet mit der Bestellung
 * (ON DELETE CASCADE).
 *
 * @Flow\Entity
 * @ORM\Table(
 *     indexes={
 *         @ORM\Index(name="order_created", columns={"lanyard_order", "createdat"})
 *     }
 * )
 */
class OrderLogEntry
{
    public const ACTION_PLACED = 'placed';
    public const ACTION_CREATED_MANUALLY = 'createdManually';
    public const ACTION_OPT_IN_CONFIRMED = 'optInConfirmed';
    public const ACTION_APPROVED = 'approved';
    public const ACTION_REJECTED = 'rejected';
    public const ACTION_CANCELLED = 'cancelled';
    public const ACTION_PAID = 'paid';
    public const ACTION_EXPIRED = 'expired';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_PAYMENT_STARTED = 'paymentStarted';
    public const ACTION_PAYMENT_FAILED = 'paymentFailed';
    public const ACTION_REFUNDED = 'refunded';

    /** Ein angemeldeter Backend-Benutzer, Name in actorName. */
    public const ACTOR_USER = 'user';

    /** Der Besteller selbst, z. B. über Formular oder Opt-in-Link. */
    public const ACTOR_CUSTOMER = 'customer';

    /** Ein Flow-Kommando, z. B. der Verfall unbestätigter Bestellungen. */
    public const ACTOR_SYSTEM = 'system';

    /** Der Zahlungsanbieter, z. B. über den Webhook. */
    public const ACTOR_GATEWAY = 'gateway';

    /**
     * Spalte ausdrücklich benannt: "order" ist ein reserviertes SQL-Wort.
     *
     * @var \ISP\Lanyard\Domain\Model\Order
     *
     * @ORM\ManyToOne
     * @ORM\JoinColumn(name="lanyard_order", nullable=false, onDelete="CASCADE")
     */
    protected Order $order;

    /**
     * @var \DateTimeImmutable
     *
     * @ORM\Column(type="datetime_immutable")
     */
    protected \DateTimeImmutable $createdAt;

    /**
     * Eine der ACTION_*-Konstanten.
     *
     * @var string
     *
     * @ORM\Column(length=30)
     */
    protected string $action;

    /**
     * Eine der ACTOR_*-Konstanten.
     *
     * @var string
     *
     * @ORM\Column(length=20)
     */
    protected string $actorType;

    /**
     * Kontoname bei ACTOR_USER.
     *
     * @var string|null
     *
     * @ORM\Column(nullable=true)
     */
    protected ?string $actorName = null;

    /**
     * Details, z. B. Begründung oder geänderte Felder.
     *
     * @var string|null
     *
     * @ORM\Column(type="text", nullable=true)
     */
    protected ?string $message = null;

    public function __construct(Order $order, string $action, string $actorType, ?string $actorName = null, ?string $message = null)
    {
        $this->order = $order;
        $this->action = $action;
        $this->actorType = $actorType;
        $this->actorName = $actorName;
        $this->message = $message;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getActorType(): string
    {
        return $this->actorType;
    }

    public function getActorName(): ?string
    {
        return $this->actorName;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }
}
