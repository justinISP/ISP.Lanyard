<?php
declare(strict_types=1);

namespace ISP\Lanyard\Controller;

use ISP\Lanyard\Domain\Exception\CapacityExceededException;
use ISP\Lanyard\Domain\Model\Order;
use ISP\Lanyard\Domain\Model\OrderStatus;
use ISP\Lanyard\Domain\Repository\OrderRepository;
use ISP\Lanyard\Domain\Service\OptInTokenService;
use ISP\Lanyard\Domain\Service\OrderService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Fusion\View\FusionView;

/**
 * Bestätigungslink aus der Opt-in-Mail.
 *
 * Zweistufig: Der Link (GET) zeigt nur eine Seite mit Button, erst das
 * Absenden (POST) bucht die Plätze. Mail-Scanner rufen Links oft
 * automatisch auf und würden sonst Bestellungen ohne Zutun des
 * Bestellers bestätigen.
 *
 * Gerendert wird über Fusion, Pfad 'lanyardOptIn'. Welche Dateien
 * geladen werden, steht in ISP.Lanyard.optIn.fusionPathPatterns –
 * das Sitepackage hängt dort seine eigene Fassung an.
 */
class OptInController extends ActionController
{
    public const STATE_PENDING = 'pending';
    public const STATE_CONFIRMED = 'confirmed';
    public const STATE_ALREADY_CONFIRMED = 'alreadyConfirmed';
    public const STATE_EXPIRED = 'expired';
    public const STATE_SOLD_OUT = 'soldOut';
    public const STATE_INVALID = 'invalid';

    /**
     * @var string
     */
    protected $defaultViewObjectName = FusionView::class;

    #[Flow\Inject]
    protected OrderRepository $orderRepository;

    #[Flow\Inject]
    protected OrderService $orderService;

    #[Flow\Inject]
    protected OptInTokenService $optInTokenService;

    #[Flow\InjectConfiguration(path: 'optIn.fusionPathPatterns', package: 'ISP.Lanyard')]
    protected array $fusionPathPatterns = [];

    protected function initializeView(ViewInterface $view): void
    {
        if ($view instanceof FusionView) {
            $view->setFusionPathPatterns(array_values(array_filter($this->fusionPathPatterns)));
            $view->setFusionPath('lanyardOptIn');
        }
    }

    /**
     * Seite hinter dem Link: zeigt den Button, ändert nichts.
     */
    public function showAction(string $token = ''): void
    {
        $order = $this->findOrder($token);

        $this->assignState($order, $this->determineState($order), $token);
    }

    /**
     * Der Token selbst ist das Geheimnis, ein CSRF-Token bringt hier
     * nichts – und angemeldete Redakteure würden sonst abgewiesen.
     */
    #[Flow\SkipCsrfProtection]
    public function confirmAction(string $token = ''): void
    {
        $order = $this->findOrder($token);
        $state = $this->determineState($order);

        if ($order !== null && $state === self::STATE_EXPIRED && $order->getStatus() === OrderStatus::RECEIVED) {
            // Sofort verfallen lassen, statt auf das Aufräum-Kommando
            // zu warten.
            $this->orderService->expire($order);
        }

        if ($order !== null && $state === self::STATE_PENDING) {
            try {
                $this->orderService->confirmOptIn($order);
                $state = self::STATE_CONFIRMED;
            } catch (CapacityExceededException) {
                // Die Bestellung bleibt RECEIVED und verfällt regulär.
                $state = self::STATE_SOLD_OUT;
            }
        }

        $this->assignState($order, $state, $token);
    }

    protected function findOrder(string $token): ?Order
    {
        if ($token === '') {
            return null;
        }

        $order = $this->orderRepository->findOneByOptInToken($token);

        if (!$order instanceof Order || !$this->optInTokenService->matches((string)$order->getOptInToken(), $token)) {
            return null;
        }

        return $order;
    }

    /**
     * Nach dem Opt-in wird der Token gelöscht. Ein zweiter Klick auf
     * denselben Link landet deshalb bei STATE_INVALID, nicht bei
     * STATE_ALREADY_CONFIRMED – der Fall tritt nur auf, wenn der Token
     * noch steht, die Bestellung aber schon weiter ist.
     */
    protected function determineState(?Order $order): string
    {
        if ($order === null) {
            return self::STATE_INVALID;
        }

        if ($order->getStatus() !== OrderStatus::RECEIVED) {
            return $order->isBlockingCapacity() ? self::STATE_ALREADY_CONFIRMED : self::STATE_INVALID;
        }

        if ($order->isOptInExpired($this->orderService->getOptInLifetime())) {
            return self::STATE_EXPIRED;
        }

        return self::STATE_PENDING;
    }

    protected function assignState(?Order $order, string $state, string $token): void
    {
        $this->view->assignMultiple([
            'lanyardLanguage' => $order !== null ? $order->getLanguage() : 'de',
            'state' => $state,
            'token' => $state === self::STATE_PENDING ? $token : '',
            'bookingNumber' => $order !== null ? (string)$order->getBookingNumber() : '',
            'confirmUri' => $this->uriBuilder->reset()->uriFor('confirm'),
        ]);
    }
}
