<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Service;

use ISP\Lanyard\Domain\Model\Order;
use ISP\Lanyard\Domain\Model\OrderLogEntry;
use ISP\Lanyard\Domain\Repository\OrderLogEntryRepository;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context as SecurityContext;

/**
 * Schreibt den Änderungsverlauf einer Bestellung.
 *
 * Wer gehandelt hat, ermittelt der Dienst selbst: ein angemeldeter
 * Benutzer, ein Flow-Kommando oder – ohne Anmeldung im Web – der
 * Besteller.
 *
 * Wird direkt vom OrderService aufgerufen und nicht über Signale
 * verdrahtet: Der Verlauf ist Teil der Nachvollziehbarkeit und soll
 * sich nicht abhängen lassen.
 */
#[Flow\Scope('singleton')]
class OrderHistoryService
{
    #[Flow\Inject]
    protected OrderLogEntryRepository $orderLogEntryRepository;

    #[Flow\Inject]
    protected SecurityContext $securityContext;

    /**
     * @param string|null $actorType Nur angeben, wenn er sich nicht aus
     *     dem Request ergibt – z. B. ACTOR_GATEWAY beim Webhook.
     */
    public function record(Order $order, string $action, ?string $message = null, ?string $actorType = null): void
    {
        [$actorType, $actorName] = $actorType === null ? $this->determineActor() : [$actorType, null];

        $this->orderLogEntryRepository->add(new OrderLogEntry($order, $action, $actorType, $actorName, $message));
    }

    /**
     * @return array<int, OrderLogEntry>
     */
    public function findForOrder(Order $order): array
    {
        return $this->orderLogEntryRepository->findByOrderChronologically($order);
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    protected function determineActor(): array
    {
        if (FLOW_SAPITYPE === 'CLI') {
            return [OrderLogEntry::ACTOR_SYSTEM, null];
        }

        if ($this->securityContext->canBeInitialized()) {
            $account = $this->securityContext->getAccount();

            if ($account !== null) {
                return [OrderLogEntry::ACTOR_USER, $account->getAccountIdentifier()];
            }
        }

        return [OrderLogEntry::ACTOR_CUSTOMER, null];
    }
}
