<?php
declare(strict_types=1);

namespace ISP\Lanyard\Payment;

/**
 * Eine geprüfte Webhook-Meldung. Sie sagt nur, welche Bestellung sich
 * geändert hat – den Stand holt PaymentService::synchronise() selbst.
 */
final class PaymentNotification
{
    /**
     * @param string|null $reference Kennung des Vorgangs (wie PaymentSession::$reference)
     * @param string|null $bookingNumber Buchungsnummer, die als Referenz übergeben wurde
     * @param string|null $transactionId Kennung der Zahlung
     */
    public function __construct(
        public ?string $reference = null,
        public ?string $bookingNumber = null,
        public ?string $transactionId = null
    ) {
    }
}
