<?php
declare(strict_types=1);

namespace ISP\Lanyard\Payment;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Anbindung eines Zahlungsanbieters.
 *
 * Nachgebildet nach dem Muster, das gängige Anbieter teilen: Vorgang
 * anlegen, Besteller auf die Bezahlseite des Anbieters schicken,
 * Ergebnis per Webhook. Verbindlich ist immer nur fetchStatus() – die
 * Rückkehr des Bestellers und der Inhalt eines Webhooks lassen sich
 * fälschen, die Abfrage beim Anbieter nicht.
 *
 * Registriert in ISP.Lanyard.payment.gateways.<schlüssel>.className.
 */
interface PaymentGatewayInterface
{
    /**
     * @throws PaymentException
     */
    public function startPayment(PaymentRequest $request): PaymentSession;

    /**
     * Aktueller Stand beim Anbieter, einer der Werte aus
     * \ISP\Lanyard\Domain\Model\PaymentStatus.
     *
     * @throws PaymentException
     */
    public function fetchStatus(string $reference): string;

    /**
     * Kennung des Vorgangs aus einer Webhook-Anfrage, null wenn die
     * Anfrage keine enthält.
     */
    public function referenceFromNotification(ServerRequestInterface $request): ?string;

    /**
     * Erstattet den Betrag vollständig.
     *
     * @param int $amount In Cent
     * @throws PaymentException
     */
    public function refund(string $reference, int $amount): void;
}
