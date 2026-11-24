<?php
declare(strict_types=1);

namespace ISP\Lanyard\Controller\Module\Management;

use ISP\Lanyard\Domain\Model\Event;
use ISP\Lanyard\Domain\Model\EventDay;
use ISP\Lanyard\Domain\Model\TicketType;
use ISP\Lanyard\Domain\Repository\EventRepository;
use ISP\Lanyard\Service\PriceFormatter;
use ISP\Lanyard\Service\SetupService;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;

/**
 * Stammdaten: Events mit ihren Tagen und Ticketarten.
 *
 * Untermodul management/lanyard/setup. Ein ModulePrivilege kann in
 * Neos 8.3 nur zwei Ebenen – abgesichert ist der Controller deshalb
 * über das MethodPrivilege ISP.Lanyard:ManageSetup.
 *
 * Eingaben kommen als einfaches Array "input" und werden im
 * SetupService geprüft, nicht über das Property-Mapping auf Entities.
 * Bei Fehlern wird das Formular mit Eingaben und Meldungen erneut
 * gerendert, nach dem Speichern folgt eine Weiterleitung.
 */
class SetupController extends AbstractTicketModuleController
{
    protected const FUSION_PATH = 'ISP/Lanyard/Module/Management/SetupController/';

    #[Flow\Inject]
    protected EventRepository $eventRepository;

    #[Flow\Inject]
    protected SetupService $setupService;

    #[Flow\Inject]
    protected PriceFormatter $priceFormatter;

    public function indexAction(): void
    {
        $events = [];

        foreach ($this->eventRepository->findAll() as $event) {
            $events[] = [
                'identifier' => $this->setupService->identifierOf($event),
                'title' => $event->getTitle(),
                'key' => $event->getIdentifier(),
                'active' => $event->isActive(),
                'dayCount' => $event->getDays()->count(),
                'ticketTypeCount' => $event->getTicketTypes()->count(),
            ];
        }

        $this->view->assign('events', $events);
    }

    #
    # Events
    #

    public function editEventAction(?Event $event = null): void
    {
        $this->renderEventForm($event, $event !== null ? $this->eventValues($event) : ['active' => '1'], []);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function saveEventAction(array $input = [], ?Event $event = null): void
    {
        $errors = $this->setupService->validateEvent($input, $event);

        if ($errors !== []) {
            $this->renderEventForm($event, $input, $errors);
            return;
        }

        $event = $this->setupService->saveEvent($input, $event);
        $this->addFlashMessage('Die Veranstaltung ist gespeichert.');
        $this->redirect('editEvent', null, null, ['event' => $event]);
    }

    public function deleteEventAction(Event $event): void
    {
        $reason = $this->setupService->preventEventDeletion($event);

        if ($reason !== null) {
            $this->addFlashMessage($reason, '', Message::SEVERITY_ERROR);
            $this->redirect('editEvent', null, null, ['event' => $event]);
        }

        $this->setupService->deleteEvent($event);
        $this->addFlashMessage('Die Veranstaltung ist gelöscht.');
        $this->redirect('index');
    }

    #
    # Tage
    #

    public function editDayAction(Event $event, ?EventDay $day = null): void
    {
        $values = $day !== null
            ? $this->dayValues($day)
            : ['capacity' => (string)$this->setupService->getDefaultCapacity()];

        $this->renderDayForm($event, $day, $values, []);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function saveDayAction(Event $event, array $input = [], ?EventDay $day = null): void
    {
        $errors = $this->setupService->validateDay($input, $event, $day);

        if ($errors !== []) {
            $this->renderDayForm($event, $day, $input, $errors);
            return;
        }

        foreach ($this->setupService->saveDay($input, $event, $day) as $note) {
            $this->addFlashMessage($note, '', Message::SEVERITY_WARNING);
        }

        $this->addFlashMessage('Der Tag ist gespeichert.');
        $this->redirect('editEvent', null, null, ['event' => $event]);
    }

    public function deleteDayAction(EventDay $day): void
    {
        $event = $day->getEvent();
        $reason = $this->setupService->preventDayDeletion($day);

        if ($reason !== null) {
            $this->addFlashMessage($reason, '', Message::SEVERITY_ERROR);
        } else {
            $this->setupService->deleteDay($day);
            $this->addFlashMessage('Der Tag ist gelöscht.');
        }

        $this->redirect('editEvent', null, null, ['event' => $event]);
    }

    #
    # Ticketarten
    #

    public function editTicketTypeAction(Event $event, ?TicketType $ticketType = null): void
    {
        $values = $ticketType !== null
            ? $this->ticketTypeValues($ticketType)
            : ['active' => '1', 'sortingIndex' => '0', 'maxPerOrder' => '0', 'coveredDays' => []];

        $this->renderTicketTypeForm($event, $ticketType, $values, []);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function saveTicketTypeAction(Event $event, array $input = [], ?TicketType $ticketType = null): void
    {
        $errors = $this->setupService->validateTicketType($input, $event, $ticketType);

        if ($errors !== []) {
            $this->renderTicketTypeForm($event, $ticketType, $input, $errors);
            return;
        }

        $this->setupService->saveTicketType($input, $event, $ticketType);
        $this->addFlashMessage('Die Ticketart ist gespeichert.');
        $this->redirect('editEvent', null, null, ['event' => $event]);
    }

    /**
     * Tagestickets für mehrere Tage auf einmal: je Tag eine Ticketart.
     */
    public function editDayTicketsAction(Event $event): void
    {
        $coveredDays = [];

        foreach ($event->getDays() as $day) {
            $coveredDays[] = $this->setupService->identifierOf($day);
        }

        $this->renderDayTicketsForm(
            $event,
            ['active' => '1', 'sortingIndex' => '0', 'maxPerOrder' => '0', 'coveredDays' => $coveredDays],
            []
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    public function saveDayTicketsAction(Event $event, array $input = []): void
    {
        $errors = $this->setupService->validateDayTickets($input, $event);

        if ($errors !== []) {
            $this->renderDayTicketsForm($event, $input, $errors);
            return;
        }

        $count = $this->setupService->saveDayTickets($input, $event);
        $this->addFlashMessage(sprintf('%d Tagestickets sind angelegt.', $count));
        $this->redirect('editEvent', null, null, ['event' => $event]);
    }

    public function deleteTicketTypeAction(TicketType $ticketType): void
    {
        $event = $ticketType->getEvent();
        $reason = $this->setupService->preventTicketTypeDeletion($ticketType);

        if ($reason !== null) {
            $this->addFlashMessage($reason, '', Message::SEVERITY_ERROR);
        } else {
            $this->setupService->deleteTicketType($ticketType);
            $this->addFlashMessage('Die Ticketart ist gelöscht.');
        }

        $this->redirect('editEvent', null, null, ['event' => $event]);
    }

    #
    # Formulare
    #

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    protected function renderEventForm(?Event $event, array $values, array $errors): void
    {
        $this->view->setFusionPath(self::FUSION_PATH . 'editEvent');
        $this->view->assignMultiple([
            'event' => $event !== null ? $this->eventSummary($event) : null,
            'values' => $values,
            'errors' => $errors,
            'days' => $event !== null ? $this->dayRows($event) : [],
            'ticketTypes' => $event !== null ? $this->ticketTypeRows($event) : [],
        ]);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    protected function renderDayForm(Event $event, ?EventDay $day, array $values, array $errors): void
    {
        $this->view->setFusionPath(self::FUSION_PATH . 'editDay');
        $this->view->assignMultiple([
            'event' => $this->eventSummary($event),
            'day' => $day !== null
                ? ['identifier' => $this->setupService->identifierOf($day), 'reserved' => $day->getReservedSeats()]
                : null,
            'values' => $values,
            'errors' => $errors,
        ]);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    protected function renderTicketTypeForm(Event $event, ?TicketType $ticketType, array $values, array $errors): void
    {
        $this->view->setFusionPath(self::FUSION_PATH . 'editTicketType');
        $this->view->assignMultiple([
            'event' => $this->eventSummary($event),
            'ticketType' => $ticketType !== null
                ? [
                    'identifier' => $this->setupService->identifierOf($ticketType),
                    'coverageLocked' => $this->setupService->isCoverageLocked($ticketType),
                ]
                : null,
            'values' => $values + ['coveredDays' => []],
            'errors' => $errors,
            'days' => $this->dayRows($event),
        ]);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    protected function renderDayTicketsForm(Event $event, array $values, array $errors): void
    {
        $this->view->setFusionPath(self::FUSION_PATH . 'editDayTickets');
        $this->view->assignMultiple([
            'event' => $this->eventSummary($event),
            'values' => $values + ['coveredDays' => []],
            'errors' => $errors,
            'days' => $this->dayRows($event),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function eventSummary(Event $event): array
    {
        return [
            'identifier' => $this->setupService->identifierOf($event),
            'title' => $event->getTitle(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function eventValues(Event $event): array
    {
        return [
            'title' => $event->getTitle(),
            'titleEn' => (string)$event->getTitleEn(),
            'identifier' => (string)$event->getIdentifier(),
            'description' => (string)$event->getDescription(),
            'descriptionEn' => (string)$event->getDescriptionEn(),
            'active' => $event->isActive() ? '1' : '',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function dayValues(EventDay $day): array
    {
        return [
            'date' => $day->getDate()->format('Y-m-d'),
            'capacity' => (string)$day->getCapacity(),
            'title' => (string)$day->getTitle(),
            'titleEn' => (string)$day->getTitleEn(),
            'closed' => $day->isClosed() ? '1' : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function ticketTypeValues(TicketType $ticketType): array
    {
        $coveredDays = [];

        foreach ($ticketType->getCoveredDays() as $day) {
            $coveredDays[] = $this->setupService->identifierOf($day);
        }

        return [
            'title' => $ticketType->getTitle(),
            'titleEn' => (string)$ticketType->getTitleEn(),
            'description' => (string)$ticketType->getDescription(),
            'descriptionEn' => (string)$ticketType->getDescriptionEn(),
            'price' => $this->setupService->formatPriceForInput($ticketType->getPrice()),
            'maxPerOrder' => (string)$ticketType->getMaxPerOrder(),
            'sortingIndex' => (string)$ticketType->getSortingIndex(),
            'active' => $ticketType->isActive() ? '1' : '',
            'coveredDays' => $coveredDays,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function dayRows(Event $event): array
    {
        $rows = [];

        foreach ($event->getDays() as $day) {
            $rows[] = [
                'identifier' => $this->setupService->identifierOf($day),
                'date' => $day->getDate()->format('d.m.Y'),
                'title' => $day->getTitle(),
                'label' => $day->getLabel(),
                'capacity' => $day->getCapacity(),
                'reserved' => $day->getReservedSeats(),
                'closed' => $day->isClosed(),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function ticketTypeRows(Event $event): array
    {
        $rows = [];

        foreach ($event->getTicketTypes() as $ticketType) {
            $rows[] = [
                'identifier' => $this->setupService->identifierOf($ticketType),
                'title' => $ticketType->getTitle(),
                'price' => $this->priceFormatter->format($ticketType->getPrice()),
                'days' => implode(', ', array_map(
                    static fn(EventDay $day): string => $day->getLabel(),
                    $ticketType->getCoveredDays()->toArray()
                )),
                'maxPerOrder' => $ticketType->getMaxPerOrder(),
                'active' => $ticketType->isActive(),
            ];
        }

        return $rows;
    }
}
