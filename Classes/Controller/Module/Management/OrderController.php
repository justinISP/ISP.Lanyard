<?php
declare(strict_types=1);

namespace ISP\Lanyard\Controller\Module\Management;

use ISP\Lanyard\Domain\Exception\CapacityExceededException;
use ISP\Lanyard\Domain\Exception\InvalidStatusTransitionException;
use ISP\Lanyard\Domain\Model\Event;
use ISP\Lanyard\Domain\Model\EventDay;
use ISP\Lanyard\Domain\Model\Order;
use ISP\Lanyard\Domain\Model\OrderStatus;
use ISP\Lanyard\Domain\Model\PaymentStatus;
use ISP\Lanyard\Domain\Model\Ticket;
use ISP\Lanyard\Domain\Repository\EventDayRepository;
use ISP\Lanyard\Domain\Repository\EventRepository;
use ISP\Lanyard\Domain\Repository\OrderRepository;
use ISP\Lanyard\Domain\Repository\TicketRepository;
use ISP\Lanyard\Domain\Service\OrderHistoryService;
use ISP\Lanyard\Domain\Service\OrderService;
use ISP\Lanyard\Payment\PaymentException;
use ISP\Lanyard\Payment\PaymentService;
use ISP\Lanyard\Service\CsvWriter;
use ISP\Lanyard\Service\MailService;
use ISP\Lanyard\Service\OrderAdminService;
use ISP\Lanyard\Service\PriceFormatter;
use ISP\Lanyard\Service\TicketPdfService;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\QueryResultInterface;

/**
 * Backend-Modul: Arbeitsliste, Detailansicht mit Statuswechseln und
 * Verlauf, Bearbeiten, manuelle Buchung, CSV-Export, Auslastung und
 * Abholliste je Tag.
 *
 * Gerendert über Fusion (ISP.Lanyard.backend.fusionPathPatterns). Der
 * Controller reicht nur flache Arrays weiter – die Fusion sieht keine
 * Entities.
 *
 * Statuswechsel laufen ausschliesslich über den OrderService, damit
 * Kapazität, Verlauf und Mails mitgeführt werden. Schreibende Actions
 * prüfen ISP.Lanyard:ManageOrders selbst (siehe Basisklasse).
 */
class OrderController extends AbstractTicketModuleController
{
    /**
     * Filterwert für "alle Status".
     */
    protected const STATUS_ALL = 'all';

    protected const FUSION_PATH = 'ISP/Lanyard/Module/Management/OrderController/';

    /**
     * Status, die auf der Abholliste erscheinen: freigegeben oder
     * bezahlt. Noch nicht freigegebene Anfragen holen nichts ab.
     */
    protected const PICKUP_STATUSES = [OrderStatus::CONFIRMED, OrderStatus::PAID];

    #[Flow\Inject]
    protected OrderRepository $orderRepository;

    #[Flow\Inject]
    protected EventRepository $eventRepository;

    #[Flow\Inject]
    protected EventDayRepository $eventDayRepository;

    #[Flow\Inject]
    protected OrderService $orderService;

    #[Flow\Inject]
    protected OrderHistoryService $orderHistoryService;

    #[Flow\Inject]
    protected OrderAdminService $orderAdminService;

    #[Flow\Inject]
    protected PaymentService $paymentService;

    #[Flow\Inject]
    protected CsvWriter $csvWriter;

    // $persistenceManager stammt aus dem AbstractController.

    #[Flow\Inject]
    protected PriceFormatter $priceFormatter;

    #[Flow\Inject]
    protected TicketRepository $ticketRepository;

    #[Flow\Inject]
    protected TicketPdfService $ticketPdfService;

    #[Flow\Inject]
    protected MailService $mailService;

    #[Flow\InjectConfiguration(path: 'backend.itemsPerPage', package: 'ISP.Lanyard')]
    protected int $itemsPerPage = 25;

    #[Flow\InjectConfiguration(path: 'capacity.warningThreshold', package: 'ISP.Lanyard')]
    protected int $warningThreshold = 50;

    /**
     * Arbeitsliste. Ohne Filter zeigt sie alle Status.
     */
    public function indexAction(
        string $status = self::STATUS_ALL,
        string $event = '',
        string $search = '',
        int $page = 1
    ): void {
        $result = $this->filteredOrders($status, $event, $search);
        $total = $result->count();
        $pages = max(1, (int)ceil($total / max(1, $this->itemsPerPage)));
        $page = min(max(1, $page), $pages);

        $query = $result->getQuery();
        $query->setOffset(($page - 1) * $this->itemsPerPage);
        $query->setLimit($this->itemsPerPage);

        $orders = [];

        foreach ($query->execute() as $order) {
            $orders[] = $this->summarise($order);
        }

        // Summen über alle gefilterten Bestellungen, nicht nur die Seite
        $ticketSum = 0;
        $amountSum = 0;

        foreach ($result as $order) {
            $ticketSum += $order->getTotalQuantity();
            $amountSum += $order->getTotalAmount();
        }

        $this->view->assignMultiple([
            'orders' => $orders,
            'total' => $total,
            'sums' => ['tickets' => $ticketSum, 'amount' => $this->priceFormatter->format($amountSum)],
            'filter' => ['status' => $status, 'event' => $event, 'search' => $search],
            'pagination' => ['page' => $page, 'pages' => $pages],
            'statusOptions' => array_merge([self::STATUS_ALL], OrderStatus::all()),
            'eventOptions' => $this->eventOptions(),
        ]);
    }

    /**
     * Die Bestellungen der Liste als CSV, mit denselben Filtern, aber
     * ohne Seitenweise.
     */
    public function exportAction(string $status = self::STATUS_ALL, string $event = '', string $search = ''): string
    {
        $rows = [];

        foreach ($this->filteredOrders($status, $event, $search) as $order) {
            $rows[] = [
                $order->getBookingNumber(),
                $this->formatDate($order->getCreatedAt()),
                $order->getStatus(),
                $order->getEvent()->getTitle(),
                $order->getOrdererType(),
                $order->getOrganisation(),
                $order->getFirstName(),
                $order->getLastName(),
                $order->getEmail(),
                $order->getPhone(),
                $order->getLanguage(),
                $this->itemsSummary($order),
                $order->getTotalQuantity(),
                $this->priceFormatter->format($order->getTotalAmount()),
                $this->formatDate($order->getOptInConfirmedAt()),
                $this->formatDate($order->getPaymentDate()),
                $this->paymentService->getMethodLabel($order->getPaymentMethod()),
                $order->getPaymentStatus(),
                $order->getPaymentReference(),
                $order->getInternalNote(),
            ];
        }

        return $this->csvResponse('bestellungen-' . date('Y-m-d') . '.csv', [
            'Buchungsnummer', 'Eingang', 'Status', 'Veranstaltung', 'Art', 'Organisation',
            'Vorname', 'Nachname', 'E-Mail', 'Telefon', 'Sprache', 'Tickets', 'Anzahl',
            'Betrag', 'E-Mail bestätigt', 'Bezahlt am', 'Zahlungsart', 'Zahlungsstatus', 'Zahlungsreferenz', 'Interne Notiz',
        ], $rows);
    }

    public function showAction(Order $order): void
    {
        $detail = $this->summarise($order);
        $online = $this->paymentService->isOnline($order->getPaymentMethod());
        $tickets = $this->ticketRepository->findByOrderInOrder($order);
        $validTickets = array_filter($tickets, static fn(Ticket $ticket): bool => $ticket->isValid());

        $detail += [
            'phone' => $order->getPhone(),
            'message' => $order->getMessage(),
            'internalNote' => $order->getInternalNote(),
            'language' => $order->getLanguage(),
            'privacyConsentAt' => $this->formatDate($order->getPrivacyConsentAt()),
            'optInSentAt' => $this->formatDate($order->getOptInSentAt()),
            'optInConfirmedAt' => $this->formatDate($order->getOptInConfirmedAt()),
            'updatedAt' => $this->formatDate($order->getUpdatedAt()),
            'rejectionReason' => $order->getRejectionReason(),
            'paymentMethod' => $this->paymentService->getMethodLabel($order->getPaymentMethod()),
            'paymentAmount' => $order->getPaymentAmount() !== null ? $this->priceFormatter->format($order->getPaymentAmount()) : null,
            'paymentDate' => $this->formatDate($order->getPaymentDate()),
            'paymentStatus' => $order->getPaymentStatus(),
            'paymentReference' => $order->getPaymentReference(),
            'onlinePayment' => $online,
            // Storno erstattet über das Gateway
            'refundOnCancel' => $online && $order->getPaymentStatus() === PaymentStatus::PAID,
            'items' => [],
            'tickets' => array_map(static fn(Ticket $ticket): array => [
                'number' => $ticket->getNumber(),
                'code' => $ticket->getCode(),
                'title' => $ticket->getOrderItem()->getTicketTypeTitle(),
                'valid' => $ticket->isValid(),
            ], $tickets),
            // Je Datei ein Download, große Bestellungen haben mehrere
            'ticketFiles' => range(1, max(1, $this->ticketPdfService->getFileCount($order))),
            'hasValidTickets' => $validTickets !== [],
            'history' => [],
            'can' => [
                'approve' => OrderStatus::canTransition($order->getStatus(), OrderStatus::CONFIRMED),
                'reject' => OrderStatus::canTransition($order->getStatus(), OrderStatus::REJECTED),
                'cancel' => OrderStatus::canTransition($order->getStatus(), OrderStatus::CANCELLED),
                // Online-Zahlungen bucht das Gateway, nicht das Orga-Team
                'markPaid' => !$online && OrderStatus::canTransition($order->getStatus(), OrderStatus::PAID),
                'syncPayment' => $online && $order->getPaymentReference() !== null && $order->getStatus() === OrderStatus::PENDING_PAYMENT,
                'resendTickets' => $order->getStatus() === OrderStatus::PAID && $validTickets !== [],
            ],
        ];

        foreach ($order->getItems() as $item) {
            $detail['items'][] = [
                'title' => $item->getTicketTypeTitle(),
                'quantity' => $item->getQuantity(),
                'unitPrice' => $this->priceFormatter->format($item->getUnitPrice()),
                'subtotal' => $this->priceFormatter->format($item->getSubtotal()),
                'days' => $this->daysOf($item->getAffectedDays()),
            ];
        }

        foreach ($this->orderHistoryService->findForOrder($order) as $entry) {
            $detail['history'][] = [
                'createdAt' => $this->formatDate($entry->getCreatedAt()),
                'action' => $entry->getAction(),
                'actorType' => $entry->getActorType(),
                'actorName' => $entry->getActorName(),
                'message' => $entry->getMessage(),
            ];
        }

        $this->view->assignMultiple([
            'order' => $detail,
            'paymentMethodOptions' => $this->paymentService->getOfflineMethodOptions(),
        ]);
    }

    #
    # Bearbeiten
    #

    public function editAction(Order $order): void
    {
        $this->assertGranted(self::PRIVILEGE_MANAGE_ORDERS);
        $this->renderEditForm($order, $this->orderAdminService->values($order), []);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateAction(Order $order, array $input = []): void
    {
        $this->assertGranted(self::PRIVILEGE_MANAGE_ORDERS);

        $errors = $this->orderAdminService->validateDetails($input);

        if ($errors !== []) {
            $this->renderEditForm($order, $input, $errors);
            return;
        }

        $changed = $this->orderAdminService->applyDetails($order, $input);
        $this->orderService->saveDetails($order, $changed);

        $this->addFlashMessage($changed === [] ? 'Keine Änderungen.' : 'Die Bestelldaten sind gespeichert.');
        $this->redirect('show', null, null, ['order' => $order]);
    }

    #
    # Manuelle Buchung
    #

    /**
     * Erst die Veranstaltung wählen, dann Tickets und Kontaktdaten.
     */
    public function newAction(string $event = ''): void
    {
        $this->assertGranted(self::PRIVILEGE_MANAGE_ORDERS);

        $eventObject = $this->findEvent($event);

        if ($eventObject === null && $this->eventRepository->countAll() === 1) {
            $eventObject = $this->eventRepository->findAll()->getFirst();
        }

        $this->renderNewForm($eventObject, ['ordererType' => Order::TYPE_PRIVATE, 'language' => 'de', 'approved' => '1'], []);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createAction(string $event, array $input = []): void
    {
        $this->assertGranted(self::PRIVILEGE_MANAGE_ORDERS);

        $eventObject = $this->findEvent($event);

        if ($eventObject === null) {
            $this->addFlashMessage('Bitte eine Veranstaltung wählen.', '', Message::SEVERITY_ERROR);
            $this->redirect('new');
        }

        $errors = $this->orderAdminService->validateManualOrder($eventObject, $input);

        if ($errors === []) {
            $order = $this->orderAdminService->buildManualOrder($eventObject, $input);
            $approved = in_array($input['approved'] ?? null, ['1', 1, true], true);

            try {
                $this->orderService->createManually($order, $approved);
                $this->addFlashMessage(sprintf('Die Bestellung %s ist angelegt.', $order->getBookingNumber()));
                $this->redirect('show', null, null, ['order' => $order]);
            } catch (CapacityExceededException $exception) {
                $errors['tickets'] = $exception->getMessage();
            }
        }

        $this->renderNewForm($eventObject, $input, $errors);
    }

    #
    # Statuswechsel
    #

    public function approveAction(Order $order): void
    {
        $this->changeStatus($order, fn() => $this->orderService->approve($order), 'Die Bestellung ist freigegeben.');
    }

    public function rejectAction(Order $order, string $reason = ''): void
    {
        $this->changeStatus(
            $order,
            fn() => $this->orderService->reject($order, $this->nullIfEmpty($reason)),
            'Die Bestellung ist abgelehnt, die Plätze sind freigegeben.'
        );
    }

    /**
     * Online bezahlte Bestellungen werden vorher über das Gateway
     * erstattet. Scheitert das, bleibt die Bestellung unverändert.
     */
    public function cancelAction(Order $order, string $reason = ''): void
    {
        $this->assertGranted(self::PRIVILEGE_MANAGE_ORDERS);

        // Erstattet wird nur bei Status "bezahlt" – der lässt sich immer
        // stornieren, eine Erstattung ohne Storno kann so nicht entstehen.
        try {
            $refunded = $this->paymentService->refund($order);
        } catch (PaymentException $exception) {
            $this->addFlashMessage(
                'Die Erstattung ist gescheitert, die Bestellung wurde nicht storniert: ' . $exception->getMessage(),
                '',
                Message::SEVERITY_ERROR
            );
            $this->redirect('show', null, null, ['order' => $order]);
        }

        $this->changeStatus(
            $order,
            fn() => $this->orderService->cancel($order, $this->nullIfEmpty($reason)),
            $refunded ? 'Die Bestellung ist storniert, der Betrag wurde erstattet.' : 'Die Bestellung ist storniert.'
        );
    }

    /**
     * Fragt den Stand einer offenen Online-Zahlung beim Gateway ab –
     * für den Fall, dass ein Webhook ausgeblieben ist.
     */
    public function syncPaymentAction(Order $order): void
    {
        $this->assertGranted(self::PRIVILEGE_MANAGE_ORDERS);

        try {
            $this->paymentService->synchronise($order);
            $this->addFlashMessage(match ($order->getStatus()) {
                OrderStatus::PAID => 'Die Zahlung ist eingegangen.',
                OrderStatus::PENDING_PAYMENT => 'Die Zahlung ist noch offen.',
                default => 'Die Zahlung ist nicht zustande gekommen, die Bestellung ist verfallen.',
            });
        } catch (PaymentException $exception) {
            $this->addFlashMessage('Das Gateway ist nicht erreichbar: ' . $exception->getMessage(), '', Message::SEVERITY_ERROR);
        }

        $this->redirect('show', null, null, ['order' => $order]);
    }

    /**
     * Schickt die Mail "Zahlung eingegangen" mit den Tickets noch
     * einmal – z. B. wenn sie im Spam gelandet ist.
     */
    public function resendTicketsAction(Order $order): void
    {
        $this->assertGranted(self::PRIVILEGE_MANAGE_ORDERS);

        if ($order->getStatus() !== OrderStatus::PAID || $this->ticketRepository->countValidByOrder($order) === 0) {
            $this->addFlashMessage('Für diese Bestellung gibt es keine gültigen Tickets.', '', Message::SEVERITY_ERROR);
        } else {
            $this->mailService->sendPaid($order);
            $this->addFlashMessage(sprintf('Die Tickets sind erneut an %s gesendet.', $order->getEmail()));
        }

        $this->redirect('show', null, null, ['order' => $order]);
    }

    /**
     * Ticket-PDF zum Herunterladen, bei großen Bestellungen je Teil.
     */
    public function downloadTicketsAction(Order $order, int $part = 1): string
    {
        [$filename, $pdf] = $this->ticketPdfService->renderFile($order, $part);

        $this->response->setContentType('application/pdf');
        $this->response->setHttpHeader('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $pdf;
    }

    public function markPaidAction(Order $order, string $method = ''): void
    {
        $this->changeStatus(
            $order,
            fn() => $this->orderService->markAsPaid($order, $this->nullIfEmpty($method)),
            'Die Bestellung ist als bezahlt markiert.'
        );
    }

    #
    # Auslastung und Abholliste
    #

    /**
     * Auslastung je Tag, gruppiert nach Event.
     */
    public function capacityAction(string $event = ''): void
    {
        $groups = [];

        foreach ($this->sortedDays($this->findEvent($event)) as $day) {
            $eventIdentifier = (string)$this->persistenceManager->getIdentifierByObject($day->getEvent());

            $groups[$eventIdentifier] ??= ['title' => $day->getEvent()->getTitle(), 'days' => []];
            $groups[$eventIdentifier]['days'][] = [
                // Nur der eigene Titel – das Datum steht in der eigenen Spalte.
                'label' => $day->getTitle() ?? '–',
                'date' => $day->getDate()->format('d.m.Y'),
                'capacity' => $day->getCapacity(),
                'reserved' => $day->getReservedSeats(),
                'available' => $day->getAvailableSeats(),
                'utilisation' => $day->getUtilisationPercentage(),
                'closed' => $day->isClosed(),
                'critical' => $day->getAvailableSeats() <= $this->warningThreshold,
                'overbooked' => $day->getAvailableSeats() < 0,
            ];
        }

        $this->view->assignMultiple([
            'events' => array_values($groups),
            'filter' => ['event' => $event],
            'eventOptions' => $this->eventOptions(),
        ]);
    }

    /**
     * Wer an einem Tag wie viele Tickets abholt – freigegebene und
     * bezahlte Bestellungen, alphabetisch nach Nachname.
     */
    public function pickupAction(string $day = ''): void
    {
        $dayObject = $this->findDay($day);
        $rows = $dayObject !== null ? $this->pickupRows($dayObject) : [];

        $this->view->assignMultiple([
            'day' => $dayObject !== null ? ['identifier' => $day, 'label' => $this->dayOptionLabel($dayObject)] : null,
            'rows' => $rows,
            'totals' => [
                'orders' => count($rows),
                'tickets' => array_sum(array_column($rows, 'quantity')),
                // Am Schalter noch zu kassieren
                'open' => $this->priceFormatter->format(array_sum(array_map(
                    static fn(array $row): int => $row['paid'] ? 0 : $row['totalCents'],
                    $rows
                ))),
            ],
            'dayOptions' => $this->dayOptions(),
        ]);
    }

    public function pickupExportAction(string $day): string
    {
        $dayObject = $this->findDay($day);
        $rows = [];

        foreach ($dayObject !== null ? $this->pickupRows($dayObject) : [] as $row) {
            $rows[] = [
                $row['lastName'],
                $row['firstName'],
                $row['organisation'],
                $row['bookingNumber'],
                $row['quantity'],
                $row['tickets'],
                $row['paid'] ? 'ja' : 'nein',
                $row['total'],
                $row['phone'],
                $row['internalNote'],
            ];
        }

        $date = $dayObject !== null ? $dayObject->getDate()->format('Y-m-d') : 'unbekannt';

        return $this->csvResponse('abholliste-' . $date . '.csv', [
            'Nachname', 'Vorname', 'Organisation', 'Buchungsnummer', 'Tickets an diesem Tag',
            'Ticketarten', 'Bezahlt', 'Gesamtbetrag', 'Telefon', 'Interne Notiz',
        ], $rows);
    }

    #
    # Hilfen
    #

    /**
     * Führt einen Statuswechsel aus und kehrt zur Detailansicht zurück.
     * Unzulässige Übergänge und fehlende Plätze landen als Meldung im
     * Modul statt als Fehlerseite.
     */
    protected function changeStatus(Order $order, callable $operation, string $successMessage): void
    {
        $this->assertGranted(self::PRIVILEGE_MANAGE_ORDERS);

        try {
            $operation();
            $this->addFlashMessage($successMessage);
        } catch (InvalidStatusTransitionException) {
            $this->addFlashMessage(
                'Dieser Statuswechsel ist für die Bestellung nicht möglich.',
                '',
                Message::SEVERITY_ERROR
            );
        } catch (CapacityExceededException $exception) {
            $this->addFlashMessage($exception->getMessage(), '', Message::SEVERITY_ERROR);
        }

        $this->redirect('show', null, null, ['order' => $order]);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    protected function renderEditForm(Order $order, array $values, array $errors): void
    {
        $this->view->setFusionPath(self::FUSION_PATH . 'edit');
        $this->view->assignMultiple([
            'order' => $this->summarise($order),
            'values' => $values,
            'errors' => $errors,
        ]);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    protected function renderNewForm(?Event $event, array $values, array $errors): void
    {
        $ticketTypes = [];

        if ($event !== null) {
            foreach ($this->orderAdminService->ticketTypesOf($event) as $identifier => $ticketType) {
                $ticketTypes[] = [
                    'identifier' => $identifier,
                    'title' => $ticketType->getTitle(),
                    'days' => $this->daysOf($ticketType->getCoveredDays()->toArray()),
                    'price' => $this->priceFormatter->format($ticketType->getPrice()),
                    'active' => $ticketType->isActive(),
                ];
            }
        }

        $this->view->setFusionPath(self::FUSION_PATH . 'new');
        $this->view->assignMultiple([
            'event' => $event !== null
                ? ['identifier' => (string)$this->persistenceManager->getIdentifierByObject($event), 'title' => $event->getTitle()]
                : null,
            'eventOptions' => $this->eventOptions(),
            'ticketTypes' => $ticketTypes,
            'values' => $values + ['tickets' => []],
            'errors' => $errors,
        ]);
    }

    /**
     * @return QueryResultInterface<Order>
     */
    protected function filteredOrders(string $status, string $event, string $search): QueryResultInterface
    {
        $statuses = $status === self::STATUS_ALL ? [] : [$status];

        return $this->orderRepository->findFiltered($statuses, $this->findEvent($event), $search);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function pickupRows(EventDay $day): array
    {
        $identifier = (string)$this->persistenceManager->getIdentifierByObject($day);
        $rows = [];

        foreach ($this->orderRepository->findByEventDay($identifier, self::PICKUP_STATUSES) as $order) {
            $quantity = 0;
            $titles = [];

            foreach ($order->getItems() as $item) {
                if (in_array($day, $item->getAffectedDays(), true)) {
                    $quantity += $item->getQuantity();
                    $titles[] = $item->getQuantity() . ' × ' . $item->getTicketTypeTitle();
                }
            }

            $rows[] = [
                'identifier' => (string)$this->persistenceManager->getIdentifierByObject($order),
                'bookingNumber' => (string)$order->getBookingNumber(),
                'lastName' => $order->getLastName(),
                'firstName' => $order->getFirstName(),
                'organisation' => $order->getOrganisation(),
                'phone' => $order->getPhone(),
                'quantity' => $quantity,
                'tickets' => implode(', ', $titles),
                'paid' => $order->getStatus() === OrderStatus::PAID,
                'total' => $this->priceFormatter->format($order->getTotalAmount()),
                'totalCents' => $order->getTotalAmount(),
                'internalNote' => $order->getInternalNote(),
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, string> $header
     * @param array<int, array<int, scalar|null>> $rows
     */
    protected function csvResponse(string $filename, array $header, array $rows): string
    {
        $this->response->setContentType('text/csv; charset=UTF-8');
        $this->response->setHttpHeader('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $this->csvWriter->write($header, $rows);
    }

    /**
     * Gemeinsame Felder für Liste und Detailansicht.
     *
     * @return array<string, mixed>
     */
    protected function summarise(Order $order): array
    {
        return [
            'identifier' => (string)$this->persistenceManager->getIdentifierByObject($order),
            'bookingNumber' => (string)$order->getBookingNumber(),
            'createdAt' => $this->formatDate($order->getCreatedAt()),
            'createdDate' => $order->getCreatedAt()->format('d.m.Y'),
            'createdTime' => $order->getCreatedAt()->format('H:i'),
            'eventTitle' => $order->getEvent()->getTitle(),
            'status' => $order->getStatus(),
            // Abgelehnt, storniert, verfallen – in Listen gedimmt
            'closed' => $order->isClosed(),
            'ordererType' => $order->getOrdererType(),
            'organisation' => $order->getOrganisation(),
            'fullName' => $order->getFullName(),
            'email' => $order->getEmail(),
            'quantity' => $order->getTotalQuantity(),
            'total' => $this->priceFormatter->format($order->getTotalAmount()),
        ];
    }

    protected function itemsSummary(Order $order): string
    {
        $parts = [];

        foreach ($order->getItems() as $item) {
            $days = $this->daysOf($item->getAffectedDays());
            $parts[] = $item->getQuantity() . ' × ' . $item->getTicketTypeTitle() . ($days !== '' ? ' (' . $days . ')' : '');
        }

        return implode('; ', $parts);
    }

    /**
     * @param iterable<EventDay> $days
     */
    protected function daysOf(iterable $days): string
    {
        $labels = [];

        foreach ($days as $day) {
            $labels[] = $day->getLabel();
        }

        return implode(', ', $labels);
    }

    protected function findEvent(string $identifier): ?Event
    {
        if ($identifier === '') {
            return null;
        }

        $event = $this->persistenceManager->getObjectByIdentifier($identifier, Event::class);

        return $event instanceof Event ? $event : null;
    }

    protected function findDay(string $identifier): ?EventDay
    {
        if ($identifier === '') {
            return null;
        }

        $day = $this->persistenceManager->getObjectByIdentifier($identifier, EventDay::class);

        return $day instanceof EventDay ? $day : null;
    }

    /**
     * @return array<int, EventDay>
     */
    protected function sortedDays(?Event $event = null): array
    {
        $days = $this->eventDayRepository->findForUtilisationOverview($event);

        usort($days, static fn(EventDay $a, EventDay $b): int =>
            [$a->getEvent()->getSortingIndex(), $a->getEvent()->getTitle(), $a->getDate()]
            <=> [$b->getEvent()->getSortingIndex(), $b->getEvent()->getTitle(), $b->getDate()]);

        return $days;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function eventOptions(): array
    {
        $options = [];

        foreach ($this->eventRepository->findAll() as $event) {
            $options[] = [
                'value' => (string)$this->persistenceManager->getIdentifierByObject($event),
                'label' => $event->getTitle(),
            ];
        }

        return $options;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function dayOptions(): array
    {
        $options = [];

        foreach ($this->sortedDays() as $day) {
            $options[] = [
                'value' => (string)$this->persistenceManager->getIdentifierByObject($day),
                'label' => $this->dayOptionLabel($day),
            ];
        }

        return $options;
    }

    protected function dayOptionLabel(EventDay $day): string
    {
        $label = $day->getEvent()->getTitle() . ' – ' . $day->getDate()->format('d.m.Y');

        return $day->getTitle() !== null ? $label . ' (' . $day->getTitle() . ')' : $label;
    }

    protected function formatDate(?\DateTimeInterface $date): ?string
    {
        return $date?->format('d.m.Y H:i');
    }

    protected function nullIfEmpty(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
