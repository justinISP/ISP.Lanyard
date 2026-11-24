<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Service;

use Doctrine\ORM\EntityManagerInterface;
use ISP\Lanyard\Domain\Exception\CapacityExceededException;
use ISP\Lanyard\Domain\Model\EventDay;
use ISP\Lanyard\Domain\Model\Order;
use ISP\Lanyard\Domain\Model\OrderStatus;
use ISP\Lanyard\Domain\Repository\EventDayRepository;
use ISP\Lanyard\Domain\Repository\OrderRepository;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;

/**
 * Verwaltet die Platzbelegung der Veranstaltungstage.
 *
 * Grundregel: reservedSeats am EventDay wird ausschliesslich hier
 * verändert, und ausschliesslich innerhalb einer Transaktion mit
 * Schreib-Lock auf die betroffenen Tageszeilen. Ohne Lock könnten
 * zwei gleichzeitige Bestellungen denselben letzten Platz belegen.
 *
 * Belegt wird ab Status REQUESTED, nicht erst ab CONFIRMED – sonst
 * könnte das Orga-Team Bestellungen freigeben, für die längst keine
 * Plätze mehr da sind.
 */
#[Flow\Scope('singleton')]
class CapacityService
{
    #[Flow\Inject]
    protected EntityManagerInterface $entityManager;

    #[Flow\Inject]
    protected EventDayRepository $eventDayRepository;

    #[Flow\Inject]
    protected OrderRepository $orderRepository;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    /**
     * Ermittelt, wie viele Plätze die Bestellung je Tag belegt.
     *
     * Mehrere Positionen können denselben Tag betreffen – ein
     * Tagesticket für den 22.05. und ein Kombiticket, das den 22.05.
     * mit abdeckt, summieren sich. Deshalb wird je Tag aufaddiert
     * und nicht je Position betrachtet.
     *
     * @return array<string, int> Tag-Identifier => benötigte Plätze
     */
    public function calculateSeatsPerDay(Order $order): array
    {
        $seatsPerDay = [];

        foreach ($order->getItems() as $item) {
            $quantity = $item->getQuantity();

            if ($quantity < 1) {
                continue;
            }

            foreach ($item->getAffectedDays() as $day) {
                $identifier = $this->persistenceManager->getIdentifierByObject($day);

                if ($identifier === null) {
                    continue;
                }

                $seatsPerDay[$identifier] = ($seatsPerDay[$identifier] ?? 0) + $quantity;
            }
        }

        return $seatsPerDay;
    }

    /**
     * Prüfung ohne Lock, für Formularvalidierung und Anzeige.
     *
     * Das Ergebnis ist eine Momentaufnahme – zwischen Prüfung und
     * Buchung können Plätze weggehen. Verbindlich ist allein reserve().
     */
    public function isAvailable(Order $order): bool
    {
        try {
            $this->assertAvailable($order);
            return true;
        } catch (CapacityExceededException) {
            return false;
        }
    }

    /**
     * Wie isAvailable(), wirft aber mit Angabe des betroffenen Tages.
     *
     * @throws CapacityExceededException
     */
    public function assertAvailable(Order $order): void
    {
        $seatsPerDay = $this->calculateSeatsPerDay($order);

        if ($seatsPerDay === []) {
            return;
        }

        $days = $this->eventDayRepository->findByIdentifiers(array_keys($seatsPerDay));

        foreach ($seatsPerDay as $identifier => $requestedSeats) {
            $day = $days[$identifier] ?? null;

            if ($day === null) {
                continue;
            }

            $this->assertDayCanTake($day, $requestedSeats);
        }
    }

    /**
     * Bucht die Plätze der Bestellung.
     *
     * Läuft vollständig innerhalb einer Transaktion: erst werden alle
     * betroffenen Tage gesperrt, dann geprüft, dann erhöht. Schlägt
     * ein einziger Tag fehl, wird nichts gebucht.
     *
     * @throws CapacityExceededException
     */
    public function reserve(Order $order): void
    {
        $seatsPerDay = $this->calculateSeatsPerDay($order);

        if ($seatsPerDay === []) {
            return;
        }

        $this->transactional(function () use ($seatsPerDay): void {
            $days = $this->eventDayRepository->findByIdentifiersForUpdate(
                array_keys($seatsPerDay)
            );

            // Erst alles prüfen, dann alles buchen. Andernfalls wären
            // bei einem Fehler im dritten Tag die ersten beiden schon
            // erhöht – der Rollback fängt das zwar ab, aber die
            // Trennung macht die Absicht lesbar.
            foreach ($seatsPerDay as $identifier => $requestedSeats) {
                $day = $days[$identifier] ?? null;

                if ($day === null) {
                    continue;
                }

                $this->assertDayCanTake($day, $requestedSeats);
            }

            foreach ($seatsPerDay as $identifier => $requestedSeats) {
                $day = $days[$identifier] ?? null;

                if ($day === null) {
                    continue;
                }

                $day->setReservedSeats($day->getReservedSeats() + $requestedSeats);
                $this->eventDayRepository->update($day);
            }
        });
    }

    /**
     * Gibt die Plätze der Bestellung wieder frei.
     *
     * Wird beim Wechsel in einen nicht belegenden Status aufgerufen,
     * also bei Ablehnung, Stornierung und Verfall.
     */
    public function release(Order $order): void
    {
        $seatsPerDay = $this->calculateSeatsPerDay($order);

        if ($seatsPerDay === []) {
            return;
        }

        $this->transactional(function () use ($seatsPerDay): void {
            $days = $this->eventDayRepository->findByIdentifiersForUpdate(
                array_keys($seatsPerDay)
            );

            foreach ($seatsPerDay as $identifier => $seats) {
                $day = $days[$identifier] ?? null;

                if ($day === null) {
                    continue;
                }

                // Untergrenze null: ein fehlerhaft doppelter Aufruf
                // soll den Zähler nicht ins Negative ziehen.
                $day->setReservedSeats(max(0, $day->getReservedSeats() - $seats));
                $this->eventDayRepository->update($day);
            }
        });
    }

    /**
     * Berechnet den Zähler eines Tages aus den tatsächlichen
     * Bestellungen neu.
     *
     * Reparaturwerkzeug für das Backend – etwa nachdem Daten direkt
     * in der Datenbank geändert wurden. Im Normalbetrieb nicht nötig.
     *
     * @return int Der neue Zählerstand
     */
    public function recalculate(EventDay $day): int
    {
        $identifier = $this->persistenceManager->getIdentifierByObject($day);

        if ($identifier === null) {
            return 0;
        }

        $orders = $this->orderRepository->findByEventDay(
            $identifier,
            OrderStatus::blocking()
        );

        $total = 0;

        foreach ($orders as $order) {
            $seatsPerDay = $this->calculateSeatsPerDay($order);
            $total += $seatsPerDay[$identifier] ?? 0;
        }

        $this->transactional(function () use ($identifier, $total): void {
            $days = $this->eventDayRepository->findByIdentifiersForUpdate([$identifier]);
            $lockedDay = $days[$identifier] ?? null;

            if ($lockedDay !== null) {
                $lockedDay->setReservedSeats($total);
                $this->eventDayRepository->update($lockedDay);
            }
        });

        return $total;
    }

    /**
     * @throws CapacityExceededException
     */
    protected function assertDayCanTake(EventDay $day, int $requestedSeats): void
    {
        if ($day->isClosed()) {
            throw CapacityExceededException::dayClosed($day);
        }

        $available = $day->getAvailableSeats();

        if ($available < $requestedSeats) {
            throw CapacityExceededException::forDay($day, $requestedSeats, $available);
        }
    }

    /**
     * Führt die Operation in einer Transaktion aus.
     *
     * Läuft bereits eine Transaktion – etwa weil der OrderService
     * Statuswechsel und Buchung zusammenfasst –, hängt sich die
     * Operation dort ein, statt eine zweite zu öffnen. Nur der
     * äussere Aufruf committet.
     *
     * Geänderte Tage müssen mit update() vorgemerkt werden. Flow mappt
     * Entities mit Change-Tracking DEFERRED_EXPLICIT – ohne update()
     * verwirft flush() die Änderung stillschweigend.
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
}