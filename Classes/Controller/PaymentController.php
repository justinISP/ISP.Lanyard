<?php
declare(strict_types=1);

namespace ISP\Lanyard\Controller;

use ISP\Lanyard\Domain\Model\Order;
use ISP\Lanyard\Domain\Model\OrderStatus;
use ISP\Lanyard\Payment\InvalidNotificationException;
use ISP\Lanyard\Payment\PaymentException;
use ISP\Lanyard\Payment\PaymentService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Fusion\View\FusionView;

/**
 * Rückkehr vom Gateway und Webhook der Online-Zahlung.
 *
 * Die Rückkehrseite fragt den Stand selbst beim Gateway ab, statt der
 * Rückkehr zu glauben: Ein Besteller könnte die URL sonst ohne Zahlung
 * aufrufen. Danach geht es auf die Seiten aus payment.resultUris oder,
 * wenn keine eingetragen sind, auf die eigene Rückkehrseite: gerendert
 * über Fusion, Pfad 'lanyardPayment', Dateien aus
 * ISP.Lanyard.payment.fusionPathPatterns.
 */
class PaymentController extends ActionController
{
    public const STATE_PAID = 'paid';
    public const STATE_PENDING = 'pending';
    public const STATE_FAILED = 'failed';
    public const STATE_INVALID = 'invalid';

    /**
     * @var string
     */
    protected $defaultViewObjectName = FusionView::class;

    #[Flow\Inject]
    protected PaymentService $paymentService;

    #[Flow\InjectConfiguration(path: 'payment.resultUris', package: 'ISP.Lanyard')]
    protected array $resultUris = [];

    // $logger stammt aus dem ActionController.

    #[Flow\InjectConfiguration(path: 'payment.fusionPathPatterns', package: 'ISP.Lanyard')]
    protected array $fusionPathPatterns = [];

    protected function initializeView(ViewInterface $view): void
    {
        if ($view instanceof FusionView) {
            $view->setFusionPathPatterns(array_values(array_filter($this->fusionPathPatterns)));
            $view->setFusionPath('lanyardPayment');
        }
    }

    public function returnAction(string $token = ''): void
    {
        $order = $this->paymentService->findOrderByToken($token);

        // Gehört die Rückkehr zu diesem Zahlungsvorgang? PAYONE hängt dafür
        // RETURNMAC an die Rückkehr-URL.
        if ($order !== null && !$this->paymentService->verifyReturn($order, $this->request->getHttpRequest())) {
            $this->logger->warning(sprintf('ISP.Lanyard: Rückkehr zu Bestellung %s ohne passendes Rückkehr-Geheimnis.', $order->getBookingNumber()));
            $order = null;
        }

        if ($order !== null && $order->getStatus() === OrderStatus::PENDING_PAYMENT) {
            try {
                $this->paymentService->synchronise($order);
            } catch (PaymentException $exception) {
                // Die Seite zeigt dann "wird geprüft", Webhook oder
                // Verfallskommando holen den Stand nach.
                $this->logger->warning(sprintf('ISP.Lanyard: Zahlungsstand für %s nicht abrufbar: %s', $order->getBookingNumber(), $exception->getMessage()));
            }
        }

        $state = $this->determineState($order);

        // Seiten der Website statt der eigenen Rückkehrseite
        $resultUri = $this->resultUri($state, $order !== null ? $order->getLanguage() : 'de');

        if ($resultUri !== '') {
            $this->redirectToUri($resultUri, 0, 303);
        }

        $this->view->assignMultiple([
            'page' => 'return',
            'lanyardLanguage' => $order !== null ? $order->getLanguage() : 'de',
            'state' => $state,
            'bookingNumber' => $order !== null ? (string)$order->getBookingNumber() : '',
            'email' => $order !== null ? $order->getEmail() : '',
            'backUri' => $order !== null && preg_match('#^https?://#i', (string)$order->getFormUri()) === 1 ? $order->getFormUri() : null,
            'reloadUri' => $state === self::STATE_PENDING ? (string)$this->request->getHttpRequest()->getUri() : null,
        ]);
    }

    /**
     * Webhook. Antwortet mit 401 auf eine falsche Signatur und mit 500,
     * wenn das Gateway nicht erreichbar war – Anbieter wiederholen die
     * Meldung dann.
     */
    #[Flow\SkipCsrfProtection]
    public function notifyAction(string $gateway): string
    {
        $this->response->setContentType('text/plain');

        try {
            $known = $this->paymentService->handleNotification($gateway, $this->request->getHttpRequest()) > 0;
        } catch (InvalidNotificationException $exception) {
            $this->logger->warning(sprintf('ISP.Lanyard: Webhook für "%s" abgewiesen: %s', $gateway, $exception->getMessage()));
            $this->response->setStatusCode(401);
            return 'invalid';
        } catch (PaymentException $exception) {
            $this->logger->error(sprintf('ISP.Lanyard: Webhook von "%s" nicht verarbeitet: %s', $gateway, $exception->getMessage()));
            $this->response->setStatusCode(500);
            return 'error';
        }

        return $known ? 'ok' : 'ignored';
    }

    protected function resultUri(string $state, string $language): string
    {
        $uris = (array)($this->resultUris[in_array($state, [self::STATE_PAID, self::STATE_PENDING], true) ? 'success' : 'failure'] ?? []);

        return trim((string)($uris[$language] ?? $uris['de'] ?? ''));
    }

    protected function determineState(?Order $order): string
    {
        return match (true) {
            $order === null => self::STATE_INVALID,
            $order->getStatus() === OrderStatus::PAID => self::STATE_PAID,
            $order->getStatus() === OrderStatus::PENDING_PAYMENT => self::STATE_PENDING,
            default => self::STATE_FAILED,
        };
    }
}
