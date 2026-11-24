<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Repository;

use ISP\Lanyard\Domain\Model\Event;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Doctrine\Repository;
use Neos\Flow\Persistence\QueryInterface;
use Neos\Flow\Persistence\QueryResultInterface;

/**
 * @method Event|null findOneByIdentifier(string $identifier)
 */
#[Flow\Scope('singleton')]
class EventRepository extends Repository
{
    protected const ENTITY_CLASSNAME = Event::class;

    /**
     * @var array<string, string>
     */
    protected $defaultOrderings = [
        'sortingIndex' => QueryInterface::ORDER_ASCENDING,
    ];

    /**
     * Alle Events, die im Bestellformular erscheinen sollen.
     *
     * @return QueryResultInterface<Event>
     */
    public function findActive(): QueryResultInterface
    {
        $query = $this->createQuery();

        return $query
            ->matching($query->equals('active', true))
            ->execute();
    }
}