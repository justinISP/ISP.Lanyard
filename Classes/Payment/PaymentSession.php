<?php
declare(strict_types=1);

namespace ISP\Lanyard\Payment;

/**
 * Ein beim Gateway angelegter Zahlungsvorgang.
 */
final class PaymentSession
{
    /**
     * @param string $reference Kennung des Vorgangs beim Gateway
     * @param string $redirectUri Bezahlseite des Gateways, unverändert zu verwenden
     * @param string|null $returnSecret Geheimnis, das das Gateway bei der
     *     Rückkehr mitschickt (PAYONE: RETURNMAC). Wird an der Bestellung
     *     gespeichert und von verifyReturn() geprüft.
     */
    public function __construct(
        public string $reference,
        public string $redirectUri,
        public ?string $returnSecret = null
    ) {
    }
}
