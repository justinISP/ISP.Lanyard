<?php
declare(strict_types=1);

namespace ISP\Lanyard\Payment;

/**
 * Was ein Gateway braucht, um einen Zahlungsvorgang anzulegen. Bewusst
 * ohne Order: Ein Gateway soll nichts über Bestellungen wissen müssen.
 */
final class PaymentRequest
{
    /**
     * @param int $amount In Cent
     * @param string $returnUri Hierhin schickt das Gateway den Besteller zurück
     * @param string $notifyUri Webhook für die verbindliche Statusmeldung
     */
    public function __construct(
        public string $bookingNumber,
        public int $amount,
        public string $currency,
        public string $description,
        public string $email,
        public string $language,
        public string $returnUri,
        public string $notifyUri
    ) {
    }
}
