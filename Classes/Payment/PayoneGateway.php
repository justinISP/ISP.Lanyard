<?php
declare(strict_types=1);

namespace ISP\Lanyard\Payment;

use ISP\Lanyard\Domain\Model\PaymentStatus;
use Neos\Flow\Annotations as Flow;
use OnlinePayments\Sdk\Authentication\V1HmacAuthenticator;
use OnlinePayments\Sdk\Client;
use OnlinePayments\Sdk\Communicator;
use OnlinePayments\Sdk\CommunicatorConfiguration;
use OnlinePayments\Sdk\Domain\AmountOfMoney;
use OnlinePayments\Sdk\Domain\APIError;
use OnlinePayments\Sdk\Domain\CardPaymentMethodSpecificInputBase;
use OnlinePayments\Sdk\Domain\CreateHostedCheckoutRequest;
use OnlinePayments\Sdk\Domain\Feedbacks;
use OnlinePayments\Sdk\Domain\HostedCheckoutSpecificInput;
use OnlinePayments\Sdk\Domain\Order as SdkOrder;
use OnlinePayments\Sdk\Domain\OrderReferences;
use OnlinePayments\Sdk\Domain\PaymentStatusOutput;
use OnlinePayments\Sdk\Domain\RedirectPaymentMethodSpecificInput;
use OnlinePayments\Sdk\Domain\RefundRequest;
use OnlinePayments\Sdk\Merchant\MerchantClientInterface;
use OnlinePayments\Sdk\ReferenceException;
use OnlinePayments\Sdk\ResponseException;
use OnlinePayments\Sdk\Webhooks\InMemorySecretKeyStore;
use OnlinePayments\Sdk\Webhooks\SignatureValidationException;
use OnlinePayments\Sdk\Webhooks\WebhooksHelper;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PAYONE (Worldline-Plattform) über den Hosted Checkout, mit dem
 * offiziellen SDK online-payments/sdk-php.
 *
 * - Abbuchung sofort (SALE): "bezahlt" heisst, das Geld ist da.
 * - Die Bezahlseite gilt so lange wie die Reservierung (payment.lifetime).
 * - Die Buchungsnummer geht als merchantReference mit, darüber ordnet
 *   der Webhook die Zahlung zu.
 * - RETURNMAC wird gespeichert und bei der Rückkehr geprüft.
 * - Webhooks prüft das SDK über X-GCS-Signature/X-GCS-KeyId.
 *
 * Zugangsdaten in ISP.Lanyard.payment.gateways.payone. Ohne vollständige
 * Zugangsdaten ist das Gateway nicht verfügbar, die Zahlungsart erscheint
 * dann nicht im Formular.
 */
#[Flow\Scope('singleton')]
class PayoneGateway implements PaymentGatewayInterface
{
    protected const REQUIRED_SETTINGS = ['apiEndpoint', 'merchantId', 'apiKeyId', 'apiSecret', 'webhookKeyId', 'webhookSecret'];

    protected const MAX_RETURN_URL_LENGTH = 200;

    #[Flow\InjectConfiguration(path: 'payment.gateways.payone', package: 'ISP.Lanyard')]
    protected array $settings = [];

    #[Flow\InjectConfiguration(path: 'payment.lifetime', package: 'ISP.Lanyard')]
    protected string $lifetime = 'PT30M';

    protected ?MerchantClientInterface $merchantClient = null;

    protected ?WebhooksHelper $webhooksHelper = null;

    public function isAvailable(): bool
    {
        foreach (self::REQUIRED_SETTINGS as $key) {
            if (trim((string)($this->settings[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    public function startPayment(PaymentRequest $request): PaymentSession
    {
        // PAYONE lehnt längere Rückkehr-URLs ab, meldet aber nur
        // "50001111 hostedCheckoutSpecificInput.returnUrl"
        if (strlen($request->returnUri) > self::MAX_RETURN_URL_LENGTH) {
            throw new PaymentException(sprintf('Return URL longer than %d characters: %s', self::MAX_RETURN_URL_LENGTH, $request->returnUri), 1764000108);
        }

        $hostedCheckoutInput = (new HostedCheckoutSpecificInput())
            ->withReturnUrl($request->returnUri)
            ->withLocale($this->locale($request->language))
            ->withSessionTimeout($this->sessionTimeoutMinutes());

        $checkoutRequest = (new CreateHostedCheckoutRequest())
            ->withOrder((new SdkOrder())
                ->withAmountOfMoney((new AmountOfMoney())->withAmount($request->amount)->withCurrencyCode($request->currency))
                ->withReferences((new OrderReferences())->withMerchantReference($request->bookingNumber)))
            ->withHostedCheckoutSpecificInput($hostedCheckoutInput)
            // Sofort abbuchen, nicht nur reservieren
            ->withCardPaymentMethodSpecificInput((new CardPaymentMethodSpecificInputBase())->withAuthorizationMode('SALE'))
            ->withRedirectPaymentMethodSpecificInput((new RedirectPaymentMethodSpecificInput())->withRequiresApproval(false));

        // Webhook-Adresse je Vorgang – nur HTTPS nimmt der Anbieter an.
        // Sonst gilt die im Merchant Portal hinterlegte.
        if (str_starts_with($request->notifyUri, 'https://')) {
            $checkoutRequest->withFeedbacks((new Feedbacks())->withWebhooksUrls([$request->notifyUri]));
        }

        try {
            $response = $this->client()->hostedCheckout()->createHostedCheckout($checkoutRequest);
        } catch (\Throwable $exception) {
            throw $this->wrap('CreateHostedCheckout', $exception);
        }

        if ((string)$response->getHostedCheckoutId() === '' || (string)$response->getRedirectUrl() === '') {
            throw new PaymentException('CreateHostedCheckout returned no hostedCheckoutId or redirectUrl.', 1764000101);
        }

        // redirectUrl unverändert verwenden, nicht aus partialRedirectUrl bauen
        return new PaymentSession(
            (string)$response->getHostedCheckoutId(),
            (string)$response->getRedirectUrl(),
            (string)$response->getRETURNMAC()
        );
    }

    public function fetchStatus(string $reference, ?string $transactionId = null): PaymentResult
    {
        try {
            $hostedCheckout = $this->client()->hostedCheckout()->getHostedCheckout($reference);
        } catch (ReferenceException $exception) {
            // Vorgang nicht (mehr) abrufbar: über die Zahlung selbst, falls
            // bekannt – sonst bleibt der Stand offen.
            if ($exception->getHttpStatusCode() !== 404) {
                throw $this->wrap('GetHostedCheckout', $exception);
            }

            return $transactionId !== null ? $this->fetchPayment($transactionId) : new PaymentResult(PaymentStatus::OPEN);
        } catch (\Throwable $exception) {
            throw $this->wrap('GetHostedCheckout', $exception);
        }

        switch ($hostedCheckout->getStatus()) {
            case 'PAYMENT_CREATED':
                $payment = $hostedCheckout->getCreatedPaymentOutput()?->getPayment();

                if ($payment === null) {
                    return new PaymentResult(PaymentStatus::OPEN);
                }

                return new PaymentResult(
                    $this->mapStatus($payment->getStatus(), $payment->getStatusOutput()),
                    (string)$payment->getId(),
                    $payment->getPaymentOutput()?->getAmountOfMoney()?->getAmount()
                );
            case 'CANCELLED_BY_CONSUMER':
                // Abgebrochen oder Sitzung abgelaufen
                return new PaymentResult(PaymentStatus::CANCELLED);
            default:
                // IN_PROGRESS: Der Besteller ist noch nicht fertig.
                return new PaymentResult(PaymentStatus::OPEN);
        }
    }

    /**
     * PAYONE hängt RETURNMAC an die Rückkehr-URL. Nur eine Rückkehr mit
     * dem gespeicherten Wert gehört zu diesem Vorgang.
     */
    public function verifyReturn(ServerRequestInterface $request, ?string $returnSecret): bool
    {
        $given = $request->getQueryParams()['RETURNMAC'] ?? null;

        return $returnSecret !== null && $returnSecret !== '' && is_string($given) && hash_equals($returnSecret, $given);
    }

    public function parseNotification(ServerRequestInterface $request): array
    {
        $body = (string)$request->getBody();

        // Mehrfach gesendete Kopfzeilen zusammenführen statt nur den ersten
        // Wert zu nehmen – eine doppelte Signatur muss scheitern.
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(',', $values);
        }

        try {
            $event = $this->webhooksHelper()->unmarshal($body, $headers);
        } catch (SignatureValidationException $exception) {
            throw new InvalidNotificationException($exception->getMessage(), 1764000102, $exception);
        } catch (\Throwable $exception) {
            // Signatur gültig, aber nicht lesbar: nichts zuzuordnen. Das
            // Verfallskommando gleicht offene Zahlungen ohnehin ab.
            throw new PaymentException('Webhook could not be read: ' . $exception->getMessage(), 1764000103, $exception);
        }

        $payment = $event->getPayment();

        if ($payment === null) {
            // Testmeldung, Erstattung, Zahlungslink …
            return [];
        }

        $bookingNumber = $payment->getPaymentOutput()?->getReferences()?->getMerchantReference();

        return [new PaymentNotification(null, $bookingNumber !== null ? (string)$bookingNumber : null, (string)$payment->getId())];
    }

    public function refund(string $reference, ?string $transactionId, int $amount, string $currency): void
    {
        $transactionId ??= $this->fetchStatus($reference)->transactionId;

        if ($transactionId === null) {
            throw new PaymentException(sprintf('No payment for hosted checkout "%s", nothing to refund.', $reference), 1764000104);
        }

        try {
            $details = $this->client()->payments()->getPaymentDetails($transactionId);

            if ($details->getStatusOutput()?->getIsRefundable() !== true) {
                throw new PaymentException(sprintf('Payment is not refundable in status %s.', $details->getStatus()), 1764000105);
            }

            // Ohne Idempotenzschlüssel: Ein zweiter Versuch nach einer
            // Zeitüberschreitung scheitert an "nicht erstattbar" und
            // erstattet nicht doppelt.
            $this->client()->payments()->refundPayment(
                (string)$details->getId(),
                (new RefundRequest())->withAmountOfMoney((new AmountOfMoney())->withAmount($amount)->withCurrencyCode($currency))
            );
        } catch (PaymentException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw $this->wrap('RefundPayment', $exception);
        }
    }

    protected function fetchPayment(string $transactionId): PaymentResult
    {
        try {
            $payment = $this->client()->payments()->getPaymentDetails($transactionId);
        } catch (\Throwable $exception) {
            throw $this->wrap('GetPaymentDetails', $exception);
        }

        return new PaymentResult(
            $this->mapStatus($payment->getStatus(), $payment->getStatusOutput()),
            (string)$payment->getId(),
            $payment->getPaymentOutput()?->getAmountOfMoney()?->getAmount()
        );
    }

    /**
     * Entscheidend ist statusOutput.statusCategory. Ohne Kategorie ist
     * nichts endgültig – offen, nie bezahlt.
     */
    protected function mapStatus(?string $status, ?PaymentStatusOutput $statusOutput): string
    {
        return match ($statusOutput?->getStatusCategory()) {
            'COMPLETED' => PaymentStatus::PAID,
            'REFUNDED' => PaymentStatus::REFUNDED,
            'UNSUCCESSFUL' => $status === 'CANCELLED' ? PaymentStatus::CANCELLED : PaymentStatus::FAILED,
            // CREATED, PENDING_PAYMENT, PENDING_MERCHANT,
            // PENDING_CONNECT_OR_3RD_PARTY, REVERSED: nicht endgültig
            default => PaymentStatus::OPEN,
        };
    }

    protected function locale(string $language): string
    {
        return $language === 'de' ? 'de-DE' : 'en-GB';
    }

    /**
     * Die Bezahlseite gilt so lange wie die Reservierung der Plätze,
     * mindestens eine Minute.
     */
    protected function sessionTimeoutMinutes(): int
    {
        $interval = new \DateInterval($this->lifetime);
        $minutes = $interval->d * 1440 + $interval->h * 60 + $interval->i;

        return max(1, min(1440, $minutes));
    }

    /**
     * @throws PaymentException
     */
    protected function client(): MerchantClientInterface
    {
        if ($this->merchantClient !== null) {
            return $this->merchantClient;
        }

        if (!$this->isAvailable()) {
            throw new PaymentException('PAYONE is not configured (ISP.Lanyard.payment.gateways.payone).', 1764000100);
        }

        $configuration = new CommunicatorConfiguration(
            trim((string)$this->settings['apiKeyId']),
            trim((string)$this->settings['apiSecret']),
            trim((string)$this->settings['apiEndpoint']),
            (string)($this->settings['integrator'] ?? 'ISP.Lanyard'),
            null,
            (int)($this->settings['connectTimeout'] ?? 5),
            (int)($this->settings['readTimeout'] ?? 30)
        );

        $client = new Client(new Communicator($configuration, new V1HmacAuthenticator($configuration)));

        return $this->merchantClient = $client->merchant(trim((string)$this->settings['merchantId']));
    }

    protected function webhooksHelper(): WebhooksHelper
    {
        return $this->webhooksHelper ??= new WebhooksHelper(new InMemorySecretKeyStore([
            trim((string)($this->settings['webhookKeyId'] ?? '')) => trim((string)($this->settings['webhookSecret'] ?? '')),
        ]));
    }

    /**
     * Fehler des SDK als PaymentException. Nur Fehler-ID, Code, Feldname
     * und Fehlertext in die Meldung, nie Anfrage oder Antwort – die
     * können Kundendaten enthalten.
     */
    protected function wrap(string $operation, \Throwable $exception): PaymentException
    {
        if ($exception instanceof ResponseException) {
            $codes = array_map(static fn(APIError $error): string => trim(sprintf(
                '%s %s %s',
                (string)$error->getErrorCode(),
                (string)$error->getPropertyName(),
                (string)$error->getMessage()
            )), $exception->getErrors());

            return new PaymentException(sprintf(
                '%s failed: HTTP %d, errorId %s, errors: %s',
                $operation,
                $exception->getHttpStatusCode(),
                (string)$exception->getErrorId(),
                implode('; ', $codes)
            ), 1764000106, $exception);
        }

        return new PaymentException(sprintf('%s failed: %s', $operation, $exception->getMessage()), 1764000107, $exception);
    }
}
