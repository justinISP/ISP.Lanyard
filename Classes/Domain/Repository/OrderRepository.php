<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Repository;

use ISP\Lanyard\Domain\Model\Event;
use ISP\Lanyard\Domain\Model\Order;
use ISP\Lanyard\Domain\Model\OrderStatus;
use ISP\Lanyard\Domain\Model\TicketType;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Doctrine\Repository;
use Neos\Flow\Persistence\QueryInterface;
use Neos\Flow\Persistence\QueryResultInterface;

/**
 * @method Order|null findOneByBookingNumber(string $bookingNumber)
 * @method Order|null findOneByOptInToken(string $optInToken)
 * @method Order|null findOneByPaymentReference(string $paymentReference)
 * @method Order|null findOneByPaymentToken(string $paymentToken)
 */
#[Flow\Scope('singleton')]
class OrderRepository extends Repository
{
    protected const ENTITY_CLASSNAME = Order::class;

    /**
     * @var array<string, string>
     */
    protected $defaultOrderings = [
        'createdAt' => QueryInterface::ORDER_DESCENDING,
    ];

    /**
     * Arbeitsliste im Backend: wartet auf Freigabe.
     *
     * Aufsteigend sortiert – wer zuerst bestellt hat, wird zuerst
     * bearbeitet.
     *
     * @return QueryResultInterface<Order>
     */
    public function findRequested(?Event $event = null): QueryResultInterface
    {
        $query = $this->createQuery();

        $constraints = [$query->equals('status', OrderStatus::REQUESTED)];

        if ($event !== null) {
            $constraints[] = $query->equals('event', $event);
        }

        return $query
            ->matching($query->logicalAnd($constraints))
            ->setOrderings(['createdAt' => QueryInterface::ORDER_ASCENDING])
            ->execute();
    }

    /**
     * Gefilterte Liste für das Backend.
     *
     * @param array<int, string> $statuses Leer heisst: alle Status.
     * @return QueryResultInterface<Order>
     */
    public function findFiltered(
        array $statuses = [],
        ?Event $event = null,
        ?string $searchTerm = null
    ): QueryResultInterface {
        $query = $this->createQuery();
        $constraints = [];

        if ($statuses !== []) {
            $constraints[] = $query->in('status', $statuses);
        }

        if ($event !== null) {
            $constraints[] = $query->equals('event', $event);
        }

        if ($searchTerm !== null && trim($searchTerm) !== '') {
            $term = '%' . trim($searchTerm) . '%';

            $constraints[] = $query->logicalOr([
                $query->like('bookingNumber', $term, false),
                $query->like('lastName', $term, false),
                $query->like('firstName', $term, false),
                $query->like('email', $term, false),
                $query->like('organisation', $term, false),
            ]);
        }

        if ($constraints !== []) {
            $query->matching($query->logicalAnd($constraints));
        }

        return $query->execute();
    }

    /**
     * Unbestätigte Bestellungen, deren Opt-in-Frist abgelaufen ist.
     *
     * Der Vergleich läuft hier in der Abfrage und nicht über
     * Order::isOptInExpired(), damit nicht alle offenen Bestellungen
     * geladen werden müssen.
     *
     * @return array<int, Order>
     */
    public function findExpiredOptIns(\DateTimeImmutable $threshold): array
    {
        $query = $this->createQuery();

        return $query
            ->matching(
                $query->logicalAnd([
                    $query->equals('status', OrderStatus::RECEIVED),
                    $query->lessThan('createdAt', $threshold),
                ])
            )
            ->execute()
            ->toArray();
    }

    /**
     * Online-Bestellungen, deren Zahlung länger als bis zum Stichtag
     * offen ist.
     *
     * @return array<int, Order>
     */
    public function findOverduePayments(\DateTimeImmutable $threshold): array
    {
        $query = $this->createQuery();

        return $query
            ->matching(
                $query->logicalAnd([
                    $query->equals('status', OrderStatus::PENDING_PAYMENT),
                    $query->lessThan('createdAt', $threshold),
                ])
            )
            ->execute()
            ->toArray();
    }

    /**
     * Zählt Bestellungen einer IP innerhalb des Zeitfensters.
     * Grundlage des Rate-Limits.
     */
    public function countByClientIpHashSince(string $ipHash, \DateTimeImmutable $since): int
    {
        $query = $this->createQuery();

        return $query
            ->matching(
                $query->logicalAnd([
                    $query->equals('clientIpHash', $ipHash),
                    $query->greaterThanOrEqual('createdAt', $since),
                ])
            )
            ->count();
    }

    /**
     * Bestellungen mit mindestens einer Position, die den Tag abdeckt –
     * Grundlage der Abholliste.
     *
     * Bewusst als DQL: über die Objektabfrage wäre der Weg
     * Order → Item → TicketType → coveredDays nicht ausdrückbar.
     *
     * @param array<int, string> $statuses
     * @return array<int, Order>
     */
    public function findByEventDay(string $eventDayIdentifier, array $statuses): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        $queryBuilder
            ->select('DISTINCT o')
            ->from(Order::class, 'o')
            ->join('o.items', 'i')
            ->join('i.ticketType', 't')
            ->join('t.coveredDays', 'd')
            ->where('d = :day')
            ->andWhere($queryBuilder->expr()->in('o.status', ':statuses'))
            ->setParameter('day', $eventDayIdentifier)
            ->setParameter('statuses', $statuses)
            ->orderBy('o.lastName', 'ASC');

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Zählt Bestellungen mit mindestens einer Position dieser
     * Ticketart – Grundlage der Schutzregeln in der Stammdatenpflege.
     *
     * @param array<int, string> $statuses
     */
    public function countByTicketType(TicketType $ticketType, array $statuses): int
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        $queryBuilder
            ->select('COUNT(DISTINCT o)')
            ->from(Order::class, 'o')
            ->join('o.items', 'i')
            ->where('i.ticketType = :ticketType')
            ->andWhere($queryBuilder->expr()->in('o.status', ':statuses'))
            ->setParameter('ticketType', $ticketType)
            ->setParameter('statuses', $statuses);

        return (int)$queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * Zählt alle Bestellungen eines Events, unabhängig vom Status.
     */
    public function countByEvent(Event $event): int
    {
        $query = $this->createQuery();

        return $query->matching($query->equals('event', $event))->count();
    }
}