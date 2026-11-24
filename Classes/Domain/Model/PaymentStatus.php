<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Model;

/**
 * Stand einer Zahlung, wie ihn das Gateway meldet. Steht in
 * Order::$paymentStatus, getrennt vom Status der Bestellung.
 *
 * Konstanten statt Enum aus demselben Grund wie bei OrderStatus.
 */
final class PaymentStatus
{
    /** Zahlungsvorgang angelegt, noch nicht abgeschlossen. */
    public const OPEN = 'open';

    /** Bezahlt. */
    public const PAID = 'paid';

    /** Vom Anbieter abgelehnt, z. B. Karte gesperrt. */
    public const FAILED = 'failed';

    /** Vom Besteller abgebrochen. */
    public const CANCELLED = 'cancelled';

    /** Beim Anbieter oder bei uns verfallen, ohne Zahlung. */
    public const EXPIRED = 'expired';

    /** Bezahlt und wieder erstattet. */
    public const REFUNDED = 'refunded';

    /**
     * Ausgänge, nach denen keine Zahlung mehr kommt.
     *
     * @return array<int, string>
     */
    public static function unsuccessful(): array
    {
        return [self::FAILED, self::CANCELLED, self::EXPIRED];
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::OPEN, self::PAID, self::FAILED, self::CANCELLED, self::EXPIRED, self::REFUNDED];
    }
}
