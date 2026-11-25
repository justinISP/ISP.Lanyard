<?php
declare(strict_types=1);

namespace ISP\Lanyard\Payment;

/**
 * Stand eines Zahlungsvorgangs beim Gateway.
 */
final class PaymentResult
{
    /**
     * @param string $status Einer der Werte aus \ISP\Lanyard\Domain\Model\PaymentStatus
     * @param string|null $transactionId Kennung der Zahlung selbst, sobald es
     *     eine gibt (PAYONE: payment.id) – für Erstattungen
     * @param int|null $amount Bezahlter Betrag in Cent, wenn bekannt
     */
    public function __construct(
        public string $status,
        public ?string $transactionId = null,
        public ?int $amount = null
    ) {
    }
}
