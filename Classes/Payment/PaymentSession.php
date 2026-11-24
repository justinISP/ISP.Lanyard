<?php
declare(strict_types=1);

namespace ISP\Lanyard\Payment;

/**
 * Ein beim Gateway angelegter Zahlungsvorgang.
 */
final class PaymentSession
{
    /**
     * @param string $reference Kennung beim Gateway, kommt im Webhook zurück
     * @param string $redirectUri Bezahlseite des Gateways
     */
    public function __construct(
        public string $reference,
        public string $redirectUri
    ) {
    }
}
