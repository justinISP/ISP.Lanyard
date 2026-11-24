<?php
declare(strict_types=1);

namespace ISP\Lanyard\Controller;

use ISP\Lanyard\Domain\Model\PaymentStatus;
use ISP\Lanyard\Payment\PaymentException;
use ISP\Lanyard\Payment\PaymentService;
use ISP\Lanyard\Payment\TestGateway;
use ISP\Lanyard\Service\PriceFormatter;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Fusion\View\FusionView;

/**
 * Bezahlseite des Test-Gateways – steht für die Seite des Anbieters.
 *
 * Nach der Wahl des Ausgangs ruft sie die Webhook-Verarbeitung direkt
 * auf (statt per HTTP) und schickt den Besteller zur Rückkehrseite. Beim
 * Ausgang "verzögert" bleibt der Webhook aus: Die Rückkehrseite zeigt
 * "wird geprüft", bis die Wartezeit um ist.
 *
 * Im Production-Kontext nicht erreichbar (404).
 */
class TestGatewayController extends ActionController
{
    /**
     * @var string
     */
    protected $defaultViewObjectName = FusionView::class;

    #[Flow\Inject]
    protected TestGateway $testGateway;

    #[Flow\Inject]
    protected PaymentService $paymentService;

    #[Flow\Inject]
    protected PriceFormatter $priceFormatter;

    #[Flow\InjectConfiguration(path: 'payment.fusionPathPatterns', package: 'ISP.Lanyard')]
    protected array $fusionPathPatterns = [];

    protected function initializeView(ViewInterface $view): void
    {
        if ($view instanceof FusionView) {
            $view->setFusionPathPatterns(array_values(array_filter($this->fusionPathPatterns)));
            $view->setFusionPath('lanyardPayment');
        }
    }

    public function showAction(string $reference = ''): void
    {
        $this->assertAvailable();

        $session = $this->testGateway->getSession($reference);
        $open = $session !== null && $session['status'] === PaymentStatus::OPEN && $session['paidAfter'] === null;

        $this->view->assignMultiple([
            'page' => 'testGateway',
            'lanyardLanguage' => (string)($session['language'] ?? 'de'),
            'state' => $session === null ? 'unknown' : ($open ? 'open' : 'closed'),
            'reference' => $reference,
            'description' => (string)($session['description'] ?? ''),
            'amount' => $session !== null ? $this->priceFormatter->format((int)$session['amount'], (string)$session['language']) : '',
            'email' => (string)($session['email'] ?? ''),
            'returnUri' => (string)($session['returnUri'] ?? ''),
            'decideUri' => $this->uriBuilder->reset()->uriFor('decide'),
            'outcomes' => [
                TestGateway::OUTCOME_PAID,
                TestGateway::OUTCOME_PAID_DELAYED,
                TestGateway::OUTCOME_FAILED,
                TestGateway::OUTCOME_CANCELLED,
            ],
        ]);
    }

    /**
     * Seite "des Anbieters", ohne Anmeldung – ein CSRF-Token gibt es
     * hier nicht.
     */
    #[Flow\SkipCsrfProtection]
    public function decideAction(string $reference = '', string $outcome = ''): void
    {
        $this->assertAvailable();

        $session = $this->testGateway->getSession($reference);

        if ($session === null) {
            $this->redirect('show', null, null, ['reference' => $reference]);
        }

        try {
            $this->testGateway->decide($reference, $outcome);

            if ($outcome !== TestGateway::OUTCOME_PAID_DELAYED) {
                $this->paymentService->synchroniseByReference($reference);
            }
        } catch (PaymentException) {
            // Schon entschieden oder ungültiger Ausgang: einfach zurück,
            // die Rückkehrseite zeigt den tatsächlichen Stand.
        }

        $this->redirectToUri((string)$session['returnUri'], 0, 303);
    }

    protected function assertAvailable(): void
    {
        if (!$this->testGateway->isAllowed()) {
            $this->throwStatus(404);
        }
    }
}
