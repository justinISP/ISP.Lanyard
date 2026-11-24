<?php
declare(strict_types=1);

namespace ISP\Lanyard\Command;

use ISP\Lanyard\Domain\Exception\InvalidStatusTransitionException;
use ISP\Lanyard\Domain\Model\EventDay;
use ISP\Lanyard\Domain\Repository\EventDayRepository;
use ISP\Lanyard\Domain\Repository\EventRepository;
use ISP\Lanyard\Domain\Repository\OrderRepository;
use ISP\Lanyard\Domain\Service\CapacityService;
use ISP\Lanyard\Domain\Service\OrderService;
use ISP\Lanyard\Payment\PaymentService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;

/**
 * Wartung der Ticketbestellungen.
 */
class LanyardCommandController extends CommandController
{
    #[Flow\Inject]
    protected OrderRepository $orderRepository;

    #[Flow\Inject]
    protected EventRepository $eventRepository;

    #[Flow\Inject]
    protected EventDayRepository $eventDayRepository;

    #[Flow\Inject]
    protected OrderService $orderService;

    #[Flow\Inject]
    protected CapacityService $capacityService;

    #[Flow\Inject]
    protected PaymentService $paymentService;

    /**
     * Lässt unbestätigte Bestellungen verfallen
     *
     * Bestellungen, deren E-Mail-Adresse nicht innerhalb von
     * ISP.Lanyard.optIn.lifetime bestätigt wurde, wechseln auf
     * "expired". Plätze werden dabei nicht frei – unbestätigte
     * Bestellungen belegen keine.
     *
     * Gedacht für den System-Cron, stündlich.
     *
     * @param bool $dryRun Nur anzeigen, nichts ändern
     */
    public function expireOptInsCommand(bool $dryRun = false): void
    {
        $lifetime = $this->orderService->getOptInLifetime();
        $threshold = (new \DateTimeImmutable())->sub($lifetime);
        $expired = 0;

        foreach ($this->orderRepository->findExpiredOptIns($threshold) as $order) {
            // Die Abfrage vergleicht mit dem Eingang, isOptInExpired()
            // mit dem letzten Versand der Opt-in-Mail – massgeblich ist
            // der spätere Zeitpunkt.
            if (!$order->isOptInExpired($lifetime)) {
                continue;
            }

            $this->outputLine('%s  %s  %s', [
                $order->getBookingNumber(),
                $order->getCreatedAt()->format('d.m.Y H:i'),
                $order->getEmail(),
            ]);

            if (!$dryRun) {
                try {
                    $this->orderService->expire($order);
                } catch (InvalidStatusTransitionException $exception) {
                    $this->outputLine('  übersprungen: %s', [$exception->getMessage()]);
                    continue;
                }
            }

            $expired++;
        }

        $this->outputLine(
            $dryRun ? '%d Bestellungen würden verfallen (Probelauf).' : '%d Bestellungen verfallen.',
            [$expired]
        );
    }

    /**
     * Gibt die Plätze offener Online-Zahlungen frei
     *
     * Online-Bestellungen, deren Zahlung länger als
     * ISP.Lanyard.payment.lifetime offen ist. Vorher wird beim Gateway
     * nachgefragt: Ist die Zahlung dort doch eingegangen (z. B. Webhook
     * verloren), wird die Bestellung als bezahlt gebucht statt verfallen.
     *
     * Gedacht für den System-Cron, alle fünf Minuten.
     *
     * @param bool $dryRun Nur anzeigen, nichts ändern
     */
    public function expirePaymentsCommand(bool $dryRun = false): void
    {
        $results = $this->paymentService->expireOverdue($dryRun);

        foreach ($results as $result) {
            $order = $result['order'];

            $this->outputLine('%s  %s  %s  → %s', [
                $order->getBookingNumber(),
                $order->getCreatedAt()->format('d.m.Y H:i'),
                $order->getEmail(),
                $result['result'],
            ]);
        }

        $this->outputLine(
            $dryRun ? '%d offene Zahlungen würden geprüft (Probelauf).' : '%d offene Zahlungen geprüft.',
            [count($results)]
        );
    }

    /**
     * Zählt die belegten Plätze je Tag neu
     *
     * Reparaturwerkzeug, kein Normalbetrieb: Setzt den Zähler jedes
     * Tages auf die Summe der Tickets aus Bestellungen, die Plätze
     * belegen (angefragt, freigegeben, bezahlt). Abweichungen werden
     * angezeigt.
     *
     * @param string $event Nur dieses Event (Kurzschlüssel), sonst alle
     */
    public function recalculateCommand(string $event = ''): void
    {
        $eventObject = null;

        if ($event !== '') {
            $eventObject = $this->eventRepository->findOneByIdentifier($event);

            if ($eventObject === null) {
                $this->outputLine('Kein Event mit dem Kurzschlüssel "%s".', [$event]);
                $this->quit(1);
            }
        }

        $changed = 0;
        $days = $this->eventDayRepository->findForUtilisationOverview($eventObject);

        usort($days, static fn(EventDay $a, EventDay $b): int =>
            [$a->getEvent()->getTitle(), $a->getDate()] <=> [$b->getEvent()->getTitle(), $b->getDate()]);

        foreach ($days as $day) {
            $before = $day->getReservedSeats();
            $after = $this->capacityService->recalculate($day);

            $this->outputLine('%s  %s  %d → %d%s', [
                $day->getEvent()->getTitle(),
                $day->getDate()->format('d.m.Y'),
                $before,
                $after,
                $before !== $after ? '  (korrigiert)' : '',
            ]);

            if ($before !== $after) {
                $changed++;
            }
        }

        $this->outputLine('%d Tage geprüft, %d korrigiert.', [count($days), $changed]);
    }
}
