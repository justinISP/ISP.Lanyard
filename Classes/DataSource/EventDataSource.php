<?php
declare(strict_types=1);

namespace ISP\Lanyard\DataSource;

use ISP\Lanyard\Domain\Model\Event;
use ISP\Lanyard\Domain\Repository\EventRepository;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Service\DataSource\AbstractDataSource;

/**
 * Auswahlliste der Events für den Inspector.
 *
 * Nur Events mit Kurzschlüssel erscheinen – das Formular spricht
 * Events über den Kurzschlüssel an, nicht über die technische ID.
 */
class EventDataSource extends AbstractDataSource
{
    /**
     * @var string
     */
    protected static $identifier = 'isp-lanyard-events';

    #[Flow\Inject]
    protected EventRepository $eventRepository;

    /**
     * @param array<string, mixed> $arguments
     * @return array<int, array{value: string, label: string}>
     */
    public function getData(?NodeInterface $node = null, array $arguments = []): array
    {
        $options = [];

        /** @var Event $event */
        foreach ($this->eventRepository->findAll() as $event) {
            if ($event->getIdentifier() === null || $event->getIdentifier() === '') {
                continue;
            }

            $label = $event->getTitle();

            if (!$event->isActive()) {
                $label .= ' (inaktiv)';
            }

            $options[] = ['value' => $event->getIdentifier(), 'label' => $label];
        }

        return $options;
    }
}
