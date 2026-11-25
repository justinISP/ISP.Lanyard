<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Service;

use Doctrine\ORM\EntityManagerInterface;
use ISP\Lanyard\Domain\Exception\CapacityExceededException;
use ISP\Lanyard\Domain\Exception\InvalidStatusTransitionException;
use ISP\Lanyard\Domain\Model\Order;
use ISP\Lanyard\Domain\Model\OrderLogEntry;
use ISP\Lanyard\Domain\Model\OrderStatus;
use ISP\Lanyard\Domain\Model\PaymentStatus;
use ISP\Lanyard\Domain\Repository\OrderRepository;
use Neos\Flow\Annotations as Flow;

/**
 * Führt Bestellungen durch ihren Lebenszyklus.
 *
 * Einziger Ort, an dem der Status einer Bestellung wechselt. Jeder
 * Wechsel prüft die Zulässigkeit und führt die Kapazität mit – beides
 * zusammen in einer Transaktion, damit kein Zustand entsteht, in dem
 * der Status gesetzt, die Platzbuchung aber ausgeblieben ist.
 *
 * Mails verschickt dieser Dienst nicht. Er sendet Signale; wer darauf
 * reagiert, entscheidet die Konfiguration.
 *
 * Jeder Wechsel landet im Änderungsverlauf (OrderHistoryService), in
 * derselben Transaktion wie der Wechsel selbst.
 */
#[Flow\Scope('singleton')]
class OrderService
{
    #[Flow\Inject]
    protected EntityManagerInterface $entityManager;

    #[Flow\Inject]
    protected OrderRepository $orderRepository;

    #[Flow\Inject]
    protected CapacityService $capacityService;

    #[Flow\Inject]
    protected BookingNumberService $bookingNumberService;

    #[Flow\Inject]
    protected OptInTokenService $optInTokenService;

    #[Flow\Inject]
    protected OrderHistoryService $orderHistoryService;

    #[Flow\InjectConfiguration(path: 'optIn.lifetime', package: 'ISP.Lanyard')]
    protected string $optInLifetime = 'PT24H';

    /**
     * Legt eine neue Bestellung an.
     *
     * Status bleibt RECEIVED, es wird noch keine Kapazität belegt –
     * erst die Bestätigung der Mailadresse bucht Plätze. Andernfalls
     * könnte jemand mit erfundenen Adressen die Veranstaltung
     * blockieren.
     */
    public function place(Order $order): Order
    {
        $order->setStatus(OrderStatus::RECEIVED);
        $order->setBookingNumber($this->bookingNumberService->generate());
        $order->setOptInToken($this->optInTokenService->generate());
        $order->setOptInSentAt(new \DateTimeImmutable());
        $order->touch();

        $this->orderRepository->add($order);
        $this->orderHistoryService->record($order, OrderLogEntry::ACTION_PLACED);
        $this->entityManager->flush();

        $this->emitOrderPlaced($order);

        return $order;
    }

    /**
     * Legt eine Bestellung mit Online-Zahlung an.
     *
     * Ohne Opt-in und ohne Freigabe: Die Plätze werden sofort gebucht,
     * die Zahlung ersetzt beides. Damit niemand Plätze blockiert, ohne
     * zu zahlen, gilt die Reservierung nur für payment.lifetime – danach
     * gibt lanyard:expirepayments sie frei.
     *
     * add() erst nach erfolgreicher Platzbuchung, wie bei
     * createManually().
     *
     * @param string $paymentToken Geheimnis für die Rückkehr-URL
     * @throws CapacityExceededException
     */
    public function placeForPayment(Order $order, string $paymentMethod, string $paymentToken): Order
    {
        $order->setStatus(OrderStatus::PENDING_PAYMENT);
        $order->setBookingNumber($this->bookingNumberService->generate());
        $order->setPaymentMethod($paymentMethod);
        $order->setPaymentStatus(PaymentStatus::OPEN);
        $order->setPaymentAmount($order->getTotalAmount());
        $order->setPaymentToken($paymentToken);
        $order->touch();

        $this->transactional(function () use ($order, $paymentMethod): void {
            $this->capacityService->reserve($order);

            $this->orderRepository->add($order);
            $this->orderHistoryService->record($order, OrderLogEntry::ACTION_PLACED, $paymentMethod);
        });

        $this->emitOrderPlaced($order);

        return $order;
    }

    /**
     * Merkt sich die Kennung des Zahlungsvorgangs beim Gateway und das
     * Geheimnis für die Rückkehr. Der Status ändert sich nicht.
     */
    public function recordPaymentStarted(Order $order, string $reference, ?string $returnSecret = null): void
    {
        $this->transactional(function () use ($order, $reference, $returnSecret): void {
            $order->setPaymentReference($reference);
            $order->setPaymentReturnSecret($returnSecret);
            $order->touch();
            $this->orderRepository->update($order);
            $this->orderHistoryService->record($order, OrderLogEntry::ACTION_PAYMENT_STARTED, $reference);
        });
    }

    /**
     * Merkt sich die Kennung der Zahlung beim Gateway, sobald es eine
     * gibt. Der Status ändert sich nicht.
     */
    public function recordPaymentTransaction(Order $order, string $transactionId): void
    {
        $this->transactional(function () use ($order, $transactionId): void {
            $order->setPaymentTransactionId($transactionId);
            $order->touch();
            $this->orderRepository->update($order);
        });
    }

    /**
     * Bestätigt die Mailadresse und bucht die Plätze.
     *
     * Hier kann es zum ersten Mal scheitern: Zwischen Bestellung und
     * Klick auf den Link können Plätze weggegangen sein. Die Bestellung
     * bleibt dann auf RECEIVED stehen und verfällt regulär.
     *
     * @throws CapacityExceededException
     * @throws InvalidStatusTransitionException
     */
    public function confirmOptIn(Order $order): void
    {
        $this->assertTransition($order, OrderStatus::REQUESTED);

        $this->transactional(function () use ($order): void {
            $this->capacityService->reserve($order);

            $order->setStatus(OrderStatus::REQUESTED);
            $order->setOptInConfirmedAt(new \DateTimeImmutable());
            $order->setOptInToken(null);
            $order->touch();
            $this->orderRepository->update($order);
            $this->orderHistoryService->record($order, OrderLogEntry::ACTION_OPT_IN_CONFIRMED);
        });

        $this->emitOptInConfirmed($order);
    }

    /**
     * Gibt die Bestellung frei. Die Plätze sind bereits seit dem
     * Opt-in gebucht, hier ändert sich nur der Status.
     *
     * @throws InvalidStatusTransitionException
     */
    public function approve(Order $order): void
    {
        $this->assertTransition($order, OrderStatus::CONFIRMED);

        $this->transactional(function () use ($order): void {
            $order->setStatus(OrderStatus::CONFIRMED);
            $order->touch();
            $this->orderRepository->update($order);
            $this->orderHistoryService->record($order, OrderLogEntry::ACTION_APPROVED);
        });

        $this->emitOrderApproved($order);
    }

    /**
     * Lehnt die Bestellung ab und gibt die Plätze frei.
     *
     * @throws InvalidStatusTransitionException
     */
    public function reject(Order $order, ?string $reason = null): void
    {
        $this->assertTransition($order, OrderStatus::REJECTED);

        $this->transactional(function () use ($order, $reason): void {
            $this->capacityService->release($order);

            $order->setStatus(OrderStatus::REJECTED);
            $order->setRejectionReason($reason);
            $order->touch();
            $this->orderRepository->update($order);
            $this->orderHistoryService->record($order, OrderLogEntry::ACTION_REJECTED, $reason);
        });

        $this->emitOrderRejected($order);
    }

    /**
     * Storniert die Bestellung. Nur durch das Orga-Team, nicht durch
     * den Besteller.
     *
     * @throws InvalidStatusTransitionException
     */
    public function cancel(Order $order, ?string $reason = null): void
    {
        $this->assertTransition($order, OrderStatus::CANCELLED);

        $wasBlocking = $order->isBlockingCapacity();

        $this->transactional(function () use ($order, $reason, $wasBlocking): void {
            // Eine noch unbestätigte Bestellung hat nie Plätze belegt –
            // ein release() würde fremde Buchungen mindern.
            if ($wasBlocking) {
                $this->capacityService->release($order);
            }

            $order->setStatus(OrderStatus::CANCELLED);
            $order->setRejectionReason($reason);
            $order->touch();
            $this->orderRepository->update($order);
            $this->orderHistoryService->record($order, OrderLogEntry::ACTION_CANCELLED, $reason);
        });

        $this->emitOrderCancelled($order);
    }

    /**
     * Markiert die Bestellung als bezahlt – durch das Orga-Team
     * (offline) oder nach Meldung des Gateways (online, mit
     * $actorType = ACTOR_GATEWAY).
     *
     * @throws InvalidStatusTransitionException
     */
    public function markAsPaid(
        Order $order,
        ?string $method = null,
        ?int $amount = null,
        ?\DateTimeImmutable $date = null,
        ?string $actorType = null
    ): void {
        $this->assertTransition($order, OrderStatus::PAID);

        $this->transactional(function () use ($order, $method, $amount, $date, $actorType): void {
            $order->setStatus(OrderStatus::PAID);
            $order->setPaymentMethod($method ?? $order->getPaymentMethod());
            $order->setPaymentAmount($amount ?? $order->getTotalAmount());
            $order->setPaymentStatus(PaymentStatus::PAID);
            $order->setPaymentDate($date ?? new \DateTimeImmutable());
            $order->touch();
            $this->orderRepository->update($order);
            $this->orderHistoryService->record($order, OrderLogEntry::ACTION_PAID, $method ?? $order->getPaymentMethod(), $actorType);
        });

        $this->emitOrderPaid($order);
    }

    /**
     * Lässt eine unbestätigte Bestellung verfallen.
     *
     * Wird vom Aufräum-Kommando aufgerufen. Eine unbestätigte Bestellung
     * (RECEIVED) belegt keine Plätze, eine mit offener Zahlung schon –
     * die werden freigegeben.
     *
     * @throws InvalidStatusTransitionException
     */
    public function expire(Order $order): void
    {
        $this->assertTransition($order, OrderStatus::EXPIRED);

        $wasBlocking = $order->isBlockingCapacity();

        $this->transactional(function () use ($order, $wasBlocking): void {
            if ($wasBlocking) {
                $this->capacityService->release($order);
            }

            $order->setStatus(OrderStatus::EXPIRED);
            $order->setOptInToken(null);
            $order->touch();
            $this->orderRepository->update($order);
            $this->orderHistoryService->record($order, OrderLogEntry::ACTION_EXPIRED);
        });

        $this->emitOrderExpired($order);
    }

    /**
     * Die Online-Zahlung ist gescheitert, abgebrochen oder verfallen:
     * Die Bestellung verfällt, die Plätze werden frei. Einen zweiten
     * Zahlungsversuch an derselben Bestellung gibt es nicht.
     *
     * @param string $paymentStatus Einer der Werte aus PaymentStatus::unsuccessful()
     * @throws InvalidStatusTransitionException
     */
    public function failPayment(Order $order, string $paymentStatus, ?string $actorType = null): void
    {
        $this->assertTransition($order, OrderStatus::EXPIRED);

        $this->transactional(function () use ($order, $paymentStatus, $actorType): void {
            $this->capacityService->release($order);

            $order->setStatus(OrderStatus::EXPIRED);
            $order->setPaymentStatus($paymentStatus);
            $order->touch();
            $this->orderRepository->update($order);
            $this->orderHistoryService->record($order, OrderLogEntry::ACTION_PAYMENT_FAILED, $paymentStatus, $actorType);
        });

        $this->emitPaymentFailed($order);
    }

    /**
     * Hält den Zahlungsstand fest, ohne den Status der Bestellung zu
     * ändern – z. B. wenn eine Zahlung verfällt, deren Bestellung schon
     * storniert ist.
     */
    public function recordPaymentStatus(Order $order, string $paymentStatus): void
    {
        if ($order->getPaymentStatus() === $paymentStatus) {
            return;
        }

        $this->transactional(function () use ($order, $paymentStatus): void {
            $order->setPaymentStatus($paymentStatus);
            $order->touch();
            $this->orderRepository->update($order);
        });
    }

    /**
     * Hält eine Erstattung über das Gateway fest. Der Status der
     * Bestellung ändert sich hier nicht – beim Storno erledigt das
     * cancel() danach.
     */
    public function recordRefund(Order $order, ?string $message = null, ?string $actorType = null): void
    {
        $this->transactional(function () use ($order, $message, $actorType): void {
            $order->setPaymentStatus(PaymentStatus::REFUNDED);
            $order->touch();
            $this->orderRepository->update($order);
            $this->orderHistoryService->record($order, OrderLogEntry::ACTION_REFUNDED, $message, $actorType);
        });
    }

    /**
     * Legt eine Bestellung an, die das Orga-Team im Backend erfasst –
     * ohne Opt-in, mit sofortiger Platzbuchung.
     *
     * add() erst nach erfolgreicher Platzbuchung: Scheitert sie, rollt
     * die Transaktion zurück – eine schon vorgemerkte Bestellung würde
     * am Ende des Requests trotzdem gespeichert, ohne gebuchte Plätze.
     *
     * @throws CapacityExceededException
     */
    public function createManually(Order $order, bool $approved = true): Order
    {
        $order->setBookingNumber($this->bookingNumberService->generate());
        $order->setOptInConfirmedAt(new \DateTimeImmutable());
        $order->setStatus($approved ? OrderStatus::CONFIRMED : OrderStatus::REQUESTED);
        $order->touch();

        $this->transactional(function () use ($order, $approved): void {
            $this->capacityService->reserve($order);

            $this->orderRepository->add($order);
            $this->orderHistoryService->record(
                $order,
                OrderLogEntry::ACTION_CREATED_MANUALLY,
                $approved ? 'freigegeben' : null
            );
        });

        $this->emitOrderPlaced($order);

        return $order;
    }

    /**
     * Speichert geänderte Kontaktdaten oder die interne Notiz. Status
     * und Positionen ändern sich hier nicht.
     *
     * @param array<int, string> $changedFields Für den Verlauf
     */
    public function saveDetails(Order $order, array $changedFields): void
    {
        if ($changedFields === []) {
            return;
        }

        $this->transactional(function () use ($order, $changedFields): void {
            $order->touch();
            $this->orderRepository->update($order);
            $this->orderHistoryService->record($order, OrderLogEntry::ACTION_UPDATED, implode(', ', $changedFields));
        });
    }

    public function getOptInLifetime(): \DateInterval
    {
        return new \DateInterval($this->optInLifetime);
    }

    /**
     * @throws InvalidStatusTransitionException
     */
    protected function assertTransition(Order $order, string $target): void
    {
        if (!OrderStatus::canTransition($order->getStatus(), $target)) {
            throw InvalidStatusTransitionException::between($order->getStatus(), $target);
        }
    }

    /**
     * Wie im CapacityService: hängt sich in eine laufende Transaktion
     * ein, statt eine zweite zu öffnen.
     *
     * Jede Operation muss die geänderte Bestellung selbst mit
     * update() vormerken. Flow mappt Entities mit Change-Tracking
     * DEFERRED_EXPLICIT – ohne update() verwirft flush() die Änderung
     * stillschweigend.
     */
    protected function transactional(callable $operation): void
    {
        $connection = $this->entityManager->getConnection();
        $ownsTransaction = !$connection->isTransactionActive();

        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            $operation();
            $this->entityManager->flush();

            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    #[Flow\Signal]
    public function emitOrderPlaced(Order $order): void
    {
    }

    #[Flow\Signal]
    public function emitOptInConfirmed(Order $order): void
    {
    }

    #[Flow\Signal]
    public function emitOrderApproved(Order $order): void
    {
    }

    #[Flow\Signal]
    public function emitOrderRejected(Order $order): void
    {
    }

    #[Flow\Signal]
    public function emitOrderCancelled(Order $order): void
    {
    }

    #[Flow\Signal]
    public function emitOrderPaid(Order $order): void
    {
    }

    #[Flow\Signal]
    public function emitOrderExpired(Order $order): void
    {
    }

    #[Flow\Signal]
    public function emitPaymentFailed(Order $order): void
    {
    }
}