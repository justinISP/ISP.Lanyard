<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;

/**
 * Eine Bestellung mit einer oder mehreren Positionen.
 *
 * Statuswechsel laufen nicht über setStatus(), sondern über den
 * OrderService – nur dort ist sichergestellt, dass die Kapazität
 * mitgeführt wird.
 *
 * @Flow\Entity
 * @ORM\Table(
 *     indexes={
 *         @ORM\Index(name="status_idx", columns={"status"}),
 *         @ORM\Index(name="created_idx", columns={"createdat"}),
 *         @ORM\Index(name="paymentreference_idx", columns={"paymentreference"})
 *     }
 * )
 */
class Order
{
    public const TYPE_PRIVATE = 'private';
    public const TYPE_GROUP = 'group';

    /**
     * Nicht erratbar, für spätere QR-Tickets. Wird beim Anlegen
     * durch den BookingNumberService gesetzt.
     *
     * @var string|null
     *
     * @ORM\Column(unique=true, nullable=true)
     */
    protected ?string $bookingNumber = null;

    /**
     * @var \ISP\Lanyard\Domain\Model\Event
     *
     * @ORM\ManyToOne
     * @ORM\JoinColumn(nullable=false)
     */
    protected Event $event;

    /**
     * @var string
     *
     * @ORM\Column(length=20)
     */
    protected string $status = OrderStatus::RECEIVED;

    /**
     * @var string
     *
     * @ORM\Column(length=20)
     */
    protected string $ordererType = self::TYPE_PRIVATE;

    /**
     * Nur bei Organisationen und Gruppen.
     *
     * @var string|null
     *
     * @ORM\Column(nullable=true)
     */
    protected ?string $organisation = null;

    /**
     * @var string
     *
     * @Flow\Validate(type="NotEmpty")
     * @Flow\Validate(type="StringLength", options={"maximum"=100})
     */
    protected string $firstName = '';

    /**
     * @var string
     *
     * @Flow\Validate(type="NotEmpty")
     * @Flow\Validate(type="StringLength", options={"maximum"=100})
     */
    protected string $lastName = '';

    /**
     * @var string
     *
     * @Flow\Validate(type="NotEmpty")
     * @Flow\Validate(type="EmailAddress")
     */
    protected string $email = '';

    /**
     * Bei Gruppen Pflicht, bei Privatpersonen optional.
     *
     * @var string|null
     *
     * @ORM\Column(nullable=true)
     */
    protected ?string $phone = null;

    /**
     * @var string|null
     *
     * @ORM\Column(type="text", nullable=true)
     */
    protected ?string $message = null;

    /**
     * @var bool
     */
    protected bool $privacyConsent = false;

    /**
     * @var \DateTimeImmutable|null
     *
     * @ORM\Column(type="datetime_immutable", nullable=true)
     */
    protected ?\DateTimeImmutable $privacyConsentAt = null;

    /**
     * Sprache für Folgemails, z.B. 'de' oder 'en'.
     *
     * @var string
     *
     * @ORM\Column(length=10)
     */
    protected string $language = 'de';

    /**
     * @var string|null
     *
     * @ORM\Column(nullable=true)
     */
    protected ?string $optInToken = null;

    /**
     * @var \DateTimeImmutable|null
     *
     * @ORM\Column(type="datetime_immutable", nullable=true)
     */
    protected ?\DateTimeImmutable $optInSentAt = null;

    /**
     * @var \DateTimeImmutable|null
     *
     * @ORM\Column(type="datetime_immutable", nullable=true)
     */
    protected ?\DateTimeImmutable $optInConfirmedAt = null;

    /**
     * @var int
     */
    protected int $optInResendCount = 0;

    /**
     * Freitext, erscheint in der Ablehnungsmail wenn gefüllt.
     *
     * @var string|null
     *
     * @ORM\Column(type="text", nullable=true)
     */
    protected ?string $rejectionReason = null;

    /**
     * Nur im Backend sichtbar.
     *
     * @var string|null
     *
     * @ORM\Column(type="text", nullable=true)
     */
    protected ?string $internalNote = null;

    /**
     * @var string|null
     *
     * @ORM\Column(nullable=true)
     */
    protected ?string $paymentMethod = null;

    /**
     * In Cent.
     *
     * @var int|null
     *
     * @ORM\Column(nullable=true)
     */
    protected ?int $paymentAmount = null;

    /**
     * @var string|null
     *
     * @ORM\Column(nullable=true)
     */
    protected ?string $paymentStatus = null;

    /**
     * @var \DateTimeImmutable|null
     *
     * @ORM\Column(type="datetime_immutable", nullable=true)
     */
    protected ?\DateTimeImmutable $paymentDate = null;

    /**
     * Kennung des Zahlungsvorgangs beim Gateway. Über sie ordnet der
     * Webhook die Zahlung der Bestellung zu.
     *
     * @var string|null
     *
     * @ORM\Column(nullable=true)
     */
    protected ?string $paymentReference = null;

    /**
     * Geheimnis in der Rückkehr-URL vom Gateway, wie das Opt-in-Token
     * getrennt von der Buchungsnummer.
     *
     * @var string|null
     *
     * @ORM\Column(unique=true, nullable=true)
     */
    protected ?string $paymentToken = null;

    /**
     * Kennung der Zahlung beim Gateway (PAYONE: payment.id), sobald es
     * eine gibt. Grundlage für Erstattungen.
     *
     * @var string|null
     *
     * @ORM\Column(nullable=true)
     */
    protected ?string $paymentTransactionId = null;

    /**
     * Geheimnis, das das Gateway bei der Rückkehr mitschickt (PAYONE:
     * RETURNMAC).
     *
     * @var string|null
     *
     * @ORM\Column(nullable=true)
     */
    protected ?string $paymentReturnSecret = null;

    /**
     * Gehasht, nie im Klartext. Dient dem Rate-Limit und der
     * Missbrauchsanalyse.
     *
     * @var string|null
     *
     * @ORM\Column(length=64, nullable=true)
     */
    protected ?string $clientIpHash = null;

    /**
     * @var \DateTimeImmutable
     *
     * @ORM\Column(type="datetime_immutable")
     */
    protected \DateTimeImmutable $createdAt;

    /**
     * @var \DateTimeImmutable
     *
     * @ORM\Column(type="datetime_immutable")
     */
    protected \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<\ISP\Lanyard\Domain\Model\OrderItem>
     *
     * @ORM\OneToMany(mappedBy="order", cascade={"persist", "remove"}, orphanRemoval=true)
     */
    protected Collection $items;

    public function __construct(Event $event)
    {
        $this->event = $event;
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getBookingNumber(): ?string
    {
        return $this->bookingNumber;
    }

    public function setBookingNumber(?string $bookingNumber): void
    {
        $this->bookingNumber = $bookingNumber;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function setEvent(Event $event): void
    {
        $this->event = $event;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Nur für den OrderService. Direkte Aufrufe umgehen die
     * Kapazitätsbuchung.
     */
    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getOrdererType(): string
    {
        return $this->ordererType;
    }

    public function setOrdererType(string $ordererType): void
    {
        $this->ordererType = $ordererType;
    }

    public function isGroupOrder(): bool
    {
        return $this->ordererType === self::TYPE_GROUP;
    }

    public function getOrganisation(): ?string
    {
        return $this->organisation;
    }

    public function setOrganisation(?string $organisation): void
    {
        $this->organisation = $organisation;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): void
    {
        $this->firstName = $firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): void
    {
        $this->lastName = $lastName;
    }

    public function getFullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): void
    {
        $this->message = $message;
    }

    public function isPrivacyConsent(): bool
    {
        return $this->privacyConsent;
    }

    public function setPrivacyConsent(bool $privacyConsent): void
    {
        $this->privacyConsent = $privacyConsent;

        if ($privacyConsent && $this->privacyConsentAt === null) {
            $this->privacyConsentAt = new \DateTimeImmutable();
        }
    }

    public function getPrivacyConsentAt(): ?\DateTimeImmutable
    {
        return $this->privacyConsentAt;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function setLanguage(string $language): void
    {
        $this->language = $language;
    }

    public function getOptInToken(): ?string
    {
        return $this->optInToken;
    }

    public function setOptInToken(?string $optInToken): void
    {
        $this->optInToken = $optInToken;
    }

    public function getOptInSentAt(): ?\DateTimeImmutable
    {
        return $this->optInSentAt;
    }

    public function setOptInSentAt(?\DateTimeImmutable $optInSentAt): void
    {
        $this->optInSentAt = $optInSentAt;
    }

    public function getOptInConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->optInConfirmedAt;
    }

    public function setOptInConfirmedAt(?\DateTimeImmutable $optInConfirmedAt): void
    {
        $this->optInConfirmedAt = $optInConfirmedAt;
    }

    public function getOptInResendCount(): int
    {
        return $this->optInResendCount;
    }

    public function increaseOptInResendCount(): void
    {
        $this->optInResendCount++;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): void
    {
        $this->rejectionReason = $rejectionReason;
    }

    public function getInternalNote(): ?string
    {
        return $this->internalNote;
    }

    public function setInternalNote(?string $internalNote): void
    {
        $this->internalNote = $internalNote;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(?string $paymentMethod): void
    {
        $this->paymentMethod = $paymentMethod;
    }

    public function getPaymentAmount(): ?int
    {
        return $this->paymentAmount;
    }

    public function setPaymentAmount(?int $paymentAmount): void
    {
        $this->paymentAmount = $paymentAmount;
    }

    public function getPaymentStatus(): ?string
    {
        return $this->paymentStatus;
    }

    public function setPaymentStatus(?string $paymentStatus): void
    {
        $this->paymentStatus = $paymentStatus;
    }

    public function getPaymentDate(): ?\DateTimeImmutable
    {
        return $this->paymentDate;
    }

    public function setPaymentDate(?\DateTimeImmutable $paymentDate): void
    {
        $this->paymentDate = $paymentDate;
    }

    public function getPaymentReference(): ?string
    {
        return $this->paymentReference;
    }

    public function setPaymentReference(?string $paymentReference): void
    {
        $this->paymentReference = $paymentReference;
    }

    public function getPaymentToken(): ?string
    {
        return $this->paymentToken;
    }

    public function setPaymentToken(?string $paymentToken): void
    {
        $this->paymentToken = $paymentToken;
    }

    public function getPaymentTransactionId(): ?string
    {
        return $this->paymentTransactionId;
    }

    public function setPaymentTransactionId(?string $paymentTransactionId): void
    {
        $this->paymentTransactionId = $paymentTransactionId;
    }

    public function getPaymentReturnSecret(): ?string
    {
        return $this->paymentReturnSecret;
    }

    public function setPaymentReturnSecret(?string $paymentReturnSecret): void
    {
        $this->paymentReturnSecret = $paymentReturnSecret;
    }

    /**
     * Prüft, ob die Frist für eine offene Online-Zahlung abgelaufen ist.
     */
    public function isPaymentExpired(\DateInterval $lifetime): bool
    {
        if ($this->status !== OrderStatus::PENDING_PAYMENT) {
            return false;
        }

        return $this->createdAt->add($lifetime) < new \DateTimeImmutable();
    }

    public function getClientIpHash(): ?string
    {
        return $this->clientIpHash;
    }

    public function setClientIpHash(?string $clientIpHash): void
    {
        $this->clientIpHash = $clientIpHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @return Collection<\ISP\Lanyard\Domain\Model\OrderItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(OrderItem $item): void
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setOrder($this);
        }
    }

    public function removeItem(OrderItem $item): void
    {
        $this->items->removeElement($item);
    }

    public function getTotalQuantity(): int
    {
        $total = 0;

        foreach ($this->items as $item) {
            $total += $item->getQuantity();
        }

        return $total;
    }

    /** Summe in Cent. */
    public function getTotalAmount(): int
    {
        $total = 0;

        foreach ($this->items as $item) {
            $total += $item->getSubtotal();
        }

        return $total;
    }

    public function isBlockingCapacity(): bool
    {
        return in_array($this->status, OrderStatus::blocking(), true);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, OrderStatus::closed(), true);
    }

    /**
     * Prüft, ob die Opt-in-Frist abgelaufen ist.
     */
    public function isOptInExpired(\DateInterval $lifetime): bool
    {
        if ($this->status !== OrderStatus::RECEIVED) {
            return false;
        }

        $reference = $this->optInSentAt ?? $this->createdAt;

        return $reference->add($lifetime) < new \DateTimeImmutable();
    }
}