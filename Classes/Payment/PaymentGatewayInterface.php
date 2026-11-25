<?php
declare(strict_types=1);

namespace ISP\Lanyard\Payment;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Anbindung eines Zahlungsanbieters.
 *
 * Nachgebildet nach dem Muster gängiger Anbieter: Vorgang anlegen,
 * Besteller auf die Bezahlseite des Anbieters schicken, Ergebnis per
 * Webhook. Verbindlich ist immer nur fetchStatus() – die Rückkehr des
 * Bestellers und der Inhalt eines Webhooks lassen sich fälschen, die
 * Abfrage beim Anbieter nicht.
 *
 * Registriert in ISP.Lanyard.payment.gateways.<schlüssel>.className.
 */
interface PaymentGatewayInterface
{
    /**
     * Ob das Gateway vollständig konfiguriert und hier erlaubt ist.
     * Online-Zahlungsarten mit nicht verfügbarem Gateway bietet das
     * Formular nicht an.
     */
    public function isAvailable(): bool;

    /**
     * @throws PaymentException
     */
    public function startPayment(PaymentRequest $request): PaymentSession;

    /**
     * Aktueller Stand beim Anbieter.
     *
     * @param string|null $transactionId Bekannte Kennung der Zahlung, als
     *     Rückfall, falls der Vorgang selbst nicht mehr abrufbar ist
     * @throws PaymentException
     */
    public function fetchStatus(string $reference, ?string $transactionId = null): PaymentResult;

    /**
     * Prüft die Rückkehr des Bestellers gegen das gespeicherte
     * Geheimnis aus PaymentSession::$returnSecret.
     */
    public function verifyReturn(ServerRequestInterface $request, ?string $returnSecret): bool;

    /**
     * Prüft und liest eine Webhook-Anfrage.
     *
     * @return array<int, PaymentNotification> Leer, wenn die Meldung keine
     *     Zahlung betrifft (z. B. Testmeldung)
     * @throws InvalidNotificationException Signatur fehlt oder ist falsch
     */
    public function parseNotification(ServerRequestInterface $request): array;

    /**
     * Erstattet den Betrag vollständig.
     *
     * @param int $amount In Cent
     * @throws PaymentException
     */
    public function refund(string $reference, ?string $transactionId, int $amount, string $currency): void;
}
