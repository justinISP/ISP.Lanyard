<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Repository;

use ISP\Lanyard\Domain\Model\Order;
use ISP\Lanyard\Domain\Model\Ticket;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Doctrine\Repository;
use Neos\Flow\Persistence\QueryInterface;

/**
 * @method Ticket|null findOneByCode(string $code)
 */
#[Flow\Scope('singleton')]
class TicketRepository extends Repository
{
    protected const ENTITY_CLASSNAME = Ticket::class;

    /**
     * Tickets einer Bestellung in ihrer laufenden Nummer.
     *
     * @return array<int, Ticket>
     */
    public function findByOrderInOrder(Order $order): array
    {
        $query = $this->createQuery();

        return $query
            ->matching($query->equals('order', $order))
            ->setOrderings(['number' => QueryInterface::ORDER_ASCENDING])
            ->execute()
            ->toArray();
    }

    public function countValidByOrder(Order $order): int
    {
        $query = $this->createQuery();

        return $query
            ->matching($query->logicalAnd(
                $query->equals('order', $order),
                $query->equals('status', Ticket::STATUS_VALID)
            ))
            ->count();
    }
}
