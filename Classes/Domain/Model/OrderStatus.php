<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Model;

/**
 * Statuswerte einer Bestellung.
 *
 * Bewusst Konstanten und kein Backed Enum: Neos 8.3 erlaubt PHP 8.0,
 * Enums brauchen 8.1. Sobald die Mindestversion steigt, ist der
 * Umbau eine Zeile im Mapping.
 */
final class OrderStatus
{
    /**
     * Online bestellt, Zahlung beim Gateway offen. Ohne Opt-in und
     * Freigabe – belegt Kapazität, aber nur für payment.lifetime.
     */
    public const PENDING_PAYMENT = 'pendingPayment';

    /** Abgeschickt, E-Mail noch nicht bestätigt. Belegt keine Kapazität. */
    public const RECEIVED = 'received';

    /** Opt-in erfolgt, wartet auf Freigabe. Belegt Kapazität vorläufig. */
    public const REQUESTED = 'requested';

    /** Vom Orga-Team freigegeben. Belegt Kapazität. */
    public const CONFIRMED = 'confirmed';

    /** Bezahlt. Belegt Kapazität. */
    public const PAID = 'paid';

    /** Abgelehnt. Gibt Kapazität frei. */
    public const REJECTED = 'rejected';

    /** Storniert. Gibt Kapazität frei. */
    public const CANCELLED = 'cancelled';

    /** Opt-in nicht erfolgt, Frist abgelaufen. */
    public const EXPIRED = 'expired';

    /**
     * Status, die Plätze belegen. Einzige Quelle dieser Wahrheit –
     * der Kapazitätsdienst richtet sich danach.
     *
     * @return array<int, string>
     */
    public static function blocking(): array
    {
        return [self::PENDING_PAYMENT, self::REQUESTED, self::CONFIRMED, self::PAID];
    }

    /**
     * Endzustände. Aus ihnen heraus sind keine Übergänge mehr erlaubt.
     *
     * @return array<int, string>
     */
    public static function closed(): array
    {
        return [self::REJECTED, self::CANCELLED, self::EXPIRED];
    }

    /**
     * Erlaubte Übergänge. Der Statusdienst prüft dagegen, damit kein
     * Controller eine Bestellung in einen unsinnigen Zustand setzt.
     *
     * @return array<string, array<int, string>>
     */
    public static function transitions(): array
    {
        return [
            self::PENDING_PAYMENT => [self::PAID, self::EXPIRED, self::CANCELLED],
            self::RECEIVED => [self::REQUESTED, self::EXPIRED, self::CANCELLED],
            self::REQUESTED => [self::CONFIRMED, self::REJECTED, self::CANCELLED],
            self::CONFIRMED => [self::PAID, self::CANCELLED],
            self::PAID => [self::CANCELLED],
            self::REJECTED => [],
            self::CANCELLED => [],
            self::EXPIRED => [],
        ];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::transitions()[$from] ?? [], true);
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::PENDING_PAYMENT,
            self::RECEIVED,
            self::REQUESTED,
            self::CONFIRMED,
            self::PAID,
            self::REJECTED,
            self::CANCELLED,
            self::EXPIRED,
        ];
    }
}