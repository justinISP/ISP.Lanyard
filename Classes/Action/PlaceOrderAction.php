<?php
declare(strict_types=1);

namespace ISP\Lanyard\Action;

use ISP\Lanyard\Domain\Exception\CapacityExceededException;
use ISP\Lanyard\Domain\Service\OrderService;
use ISP\Lanyard\Payment\PaymentException;
use ISP\Lanyard\Payment\PaymentService;
use ISP\Lanyard\Service\OrderFormService;
use ISP\Lanyard\Service\RateLimitService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Fusion\Form\Runtime\Action\AbstractAction;
use Neos\Fusion\Form\Runtime\Domain\Exception\ActionException;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Legt die Bestellung aus einem abgeschickten Formular an.
 *
 * Ansprechbar als 'ISP.Lanyard:PlaceOrder'. Die Daten sind zu diesem
 * Zeitpunkt bereits vom OrderProcess geprüft.
 *
 * - Online-Zahlung: Plätze sofort buchen, weiter zum Gateway.
 * - Sonst: Status RECEIVED ohne Plätze, Opt-in-Mail, Meldung
 *   "message".
 *
 * Optionen:
 * - eventIdentifier: Kurzschlüssel des Events
 * - language: Sprache für die Folgemails
 * - data: die Formulardaten
 * - formUri: Adresse der Formularseite, für "erneut bestellen"
 * - successUri, failureUri: Seiten nach der Online-Zahlung (optional)
 * - message: Inhalt nach dem Absenden ohne Online-Zahlung
 * - soldOutMessage: Plätze zwischen Prüfung und Buchung weg
 * - paymentErrorMessage: Gateway nicht erreichbar
 */
class PlaceOrderAction extends AbstractAction
{
    #[Flow\Inject]
    protected OrderFormService $orderFormService;

    #[Flow\Inject]
    protected OrderService $orderService;

    #[Flow\Inject]
    protected PaymentService $paymentService;

    #[Flow\Inject]
    protected RateLimitService $rateLimitService;

    #[Flow\Inject]
    protected UriFactoryInterface $uriFactory;

    /**
     * @throws ActionException
     */
    public function perform(): ?ActionResponse
    {
        $event = $this->orderFormService->findEvent((string)($this->options['eventIdentifier'] ?? ''));

        if ($event === null) {
            throw new ActionException('ISP.Lanyard:PlaceOrder needs an active event.', 1761480301);
        }

        $data = (array)($this->options['data'] ?? []);
        $order = $this->orderFormService->buildOrder($event, $data, (string)($this->options['language'] ?? 'de'));
        $response = new ActionResponse();

        try {
            if (!$this->paymentService->isOnline($order->getPaymentMethod())) {
                $this->orderService->place($order);
                $response->setContent((string)($this->options['message'] ?? ''));

                return $response;
            }

            foreach (['formUri' => 'setFormUri', 'successUri' => 'setSuccessUri', 'failureUri' => 'setFailureUri'] as $option => $setter) {
                $uri = (string)($this->options[$option] ?? '');
                $order->$setter($uri !== '' ? $uri : null);
            }

            $this->orderService->placeForPayment($order, (string)$order->getPaymentMethod(), $this->paymentService->generateToken());
            $redirectUri = $this->paymentService->start($order);
            $response->setRedirectUri($this->uriFactory->createUri($redirectUri), 303);
        } catch (CapacityExceededException) {
            // Zwischen Prüfung und Buchung vergeben – die Bestellung ist
            // nicht gespeichert.
            $response->setContent((string)($this->options['soldOutMessage'] ?? ''));
        } catch (PaymentException) {
            // Die Bestellung ist bereits verfallen, die Plätze sind frei.
            $response->setContent((string)($this->options['paymentErrorMessage'] ?? ''));
        } finally {
            // Auch zählen, wenn danach z. B. der Mailversand oder das
            // Gateway scheitert – die Bestellung existiert dann trotzdem.
            if ($order->getBookingNumber() !== null) {
                $this->rateLimitService->registerAttempt((string)($data['clientIpHash'] ?? ''));
            }
        }

        return $response;
    }
}
