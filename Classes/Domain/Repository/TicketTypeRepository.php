<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Repository;

use ISP\Lanyard\Domain\Model\Event;
use ISP\Lanyard\Domain\Model\TicketType;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Doctrine\Repository;
use Neos\Flow\Persistence\QueryInterface;

#[Flow\Scope('singleton')]
class TicketTypeRepository extends Repository
{
    protected const ENTITY_CLASSNAME = TicketType::class;

    /**
     * @var array<string, string>
     */
    protected $defaultOrderings = [
        'sortingIndex' => QueryInterface::ORDER_ASCENDING,
    ];

    /**
     * Aktive Ticketarten eines Events, für die Anzeige im Formular.
     *
     * Ob tatsächlich buchbar, entscheidet erst isBookable() – dort
     * fliesst die Restkapazität der abgedeckten Tage ein.
     *
     * @return array<int, TicketType>
     */
    public function findActiveByEvent(Event $event): array
    {
        $query = $this->createQuery();

        return $query
            ->matching(
                $query->logicalAnd([
                    $query->equals('event', $event),
                    $query->equals('active', true),
                ])
            )
            ->execute()
            ->toArray();
    }
}