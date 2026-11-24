<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Repository;

use ISP\Lanyard\Domain\Model\Order;
use ISP\Lanyard\Domain\Model\OrderLogEntry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Doctrine\Repository;
use Neos\Flow\Persistence\QueryInterface;

#[Flow\Scope('singleton')]
class OrderLogEntryRepository extends Repository
{
    protected const ENTITY_CLASSNAME = OrderLogEntry::class;

    /**
     * Verlauf einer Bestellung, ältester Eintrag zuerst.
     *
     * @return array<int, OrderLogEntry>
     */
    public function findByOrderChronologically(Order $order): array
    {
        $query = $this->createQuery();

        return $query
            ->matching($query->equals('order', $order))
            ->setOrderings(['createdAt' => QueryInterface::ORDER_ASCENDING])
            ->execute()
            ->toArray();
    }
}
