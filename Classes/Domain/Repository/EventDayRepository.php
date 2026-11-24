<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Repository;

use Doctrine\DBAL\LockMode;
use ISP\Lanyard\Domain\Model\Event;
use ISP\Lanyard\Domain\Model\EventDay;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Doctrine\Repository;
use Neos\Flow\Persistence\QueryInterface;
use Neos\Flow\Persistence\QueryResultInterface;

#[Flow\Scope('singleton')]
class EventDayRepository extends Repository
{
    protected const ENTITY_CLASSNAME = EventDay::class;

    /**
     * @var array<string, string>
     */
    protected $defaultOrderings = [
        'date' => QueryInterface::ORDER_ASCENDING,
    ];

    /**
     * @return QueryResultInterface<EventDay>
     */
    public function findByEvent(Event $event): QueryResultInterface
    {
        $query = $this->createQuery();

        return $query
            ->matching($query->equals('event', $event))
            ->execute();
    }

    /**
     * Lädt Tage ohne Lock – für Prüfungen und Anzeige.
     *
     * @param array<int, string> $dayIdentifiers
     * @return array<string, EventDay>
     */
    public function findByIdentifiers(array $dayIdentifiers): array
    {
        $days = [];

        foreach (array_unique($dayIdentifiers) as $identifier) {
            $day = $this->entityManager->find(EventDay::class, $identifier);

            if ($day instanceof EventDay) {
                $days[$identifier] = $day;
            }
        }

        return $days;
    }

    /**
     * Lädt die angegebenen Tage mit einem Schreib-Lock.
     *
     * Muss innerhalb einer laufenden Transaktion aufgerufen werden,
     * sonst wirft Doctrine eine TransactionRequiredException.
     *
     * Die Sortierung nach technischer ID ist kein Schönheitsdetail:
     * Zwei gleichzeitige Bestellungen, die dieselben Tage in
     * unterschiedlicher Reihenfolge sperren, würden sich gegenseitig
     * blockieren. Eine feste Reihenfolge schliesst das aus.
     *
     * @param array<int, string> $dayIdentifiers
     * @return array<string, EventDay>
     */
    public function findByIdentifiersForUpdate(array $dayIdentifiers): array
    {
        $uniqueIdentifiers = array_values(array_unique($dayIdentifiers));
        sort($uniqueIdentifiers);

        $days = [];

        foreach ($uniqueIdentifiers as $identifier) {
            $day = $this->entityManager->find(
                EventDay::class,
                $identifier,
                LockMode::PESSIMISTIC_WRITE
            );

            if ($day instanceof EventDay) {
                $days[$identifier] = $day;
            }
        }

        return $days;
    }

    /**
     * Tage mit Auslastung, für die Übersicht im Backend.
     *
     * @return array<int, EventDay>
     */
    public function findForUtilisationOverview(?Event $event = null): array
    {
        $query = $this->createQuery();

        if ($event !== null) {
            $query->matching($query->equals('event', $event));
        }

        return $query->execute()->toArray();
    }
}