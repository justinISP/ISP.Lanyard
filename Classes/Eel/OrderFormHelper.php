<?php
declare(strict_types=1);

namespace ISP\Lanyard\Eel;

use ISP\Lanyard\Domain\Model\EventDay;
use ISP\Lanyard\Domain\Model\TicketType;
use ISP\Lanyard\Payment\PaymentService;
use ISP\Lanyard\Service\OrderFormService;
use ISP\Lanyard\Service\PriceFormatter;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;

/**
 * Daten für das Bestellformular, als ISP.Lanyard.OrderForm in Fusion.
 *
 * Liefert ausschliesslich flache Arrays. Die Presentation bekommt so
 * nie eine Entity zu sehen, und das Sitepackage kann das Markup
 * ersetzen, ohne das Domänenmodell zu kennen.
 */
class OrderFormHelper implements ProtectedContextAwareInterface
{
    #[Flow\Inject]
    protected OrderFormService $orderFormService;

    #[Flow\Inject]
    protected PriceFormatter $priceFormatter;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    #[Flow\Inject]
    protected PaymentService $paymentService;

    #[Flow\InjectConfiguration(path: 'order.maxTicketsPerOrder', package: 'ISP.Lanyard')]
    protected array $maxTicketsPerOrder = [];

    /**
     * Das Event zum Kurzschlüssel, oder null, wenn es nicht existiert
     * oder inaktiv ist.
     *
     * @return array{identifier: string, title: string, description: ?string}|null
     */
    public function event(?string $identifier, string $language = 'de'): ?array
    {
        $event = $this->orderFormService->findEvent((string)$identifier);

        if ($event === null) {
            return null;
        }

        return [
            'identifier' => (string)$event->getIdentifier(),
            'title' => $event->getLocalisedTitle($language),
            'description' => $event->getLocalisedDescription($language),
        ];
    }

    /**
     * Die Ticketarten des Events in Anzeigereihenfolge.
     *
     * 'maxQuantity' ist die Obergrenze für das Eingabefeld: Restplätze
     * des knappsten Tages, gedeckelt durch die Grenze der Ticketart.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ticketTypes(?string $identifier, string $language = 'de'): array
    {
        $event = $this->orderFormService->findEvent((string)$identifier);

        if ($event === null) {
            return [];
        }

        $ticketTypes = [];

        foreach ($this->orderFormService->findTicketTypes($event) as $ticketIdentifier => $ticketType) {
            $ticketTypes[] = $this->ticketRow($ticketIdentifier, $ticketType, $language);
        }

        return $ticketTypes;
    }

    /**
     * Die Ticketarten gegliedert für das Formular:
     *
     * - 'packages': Ticketarten über mehrere Tage (Kombi, Dauerkarte)
     * - 'days': je Tag die Ticketarten, die nur diesen einen Tag
     *   abdecken, nach Datum sortiert – mit 'label' (Wochentag und
     *   Datum, bei eigenem Titel dieser davor), 'bookable' und
     *   'fromPrice' (günstigster buchbarer Preis)
     *
     * Ticketarten ohne Tag sind nie buchbar und fehlen.
     *
     * @return array{packages: array<int, array<string, mixed>>, days: array<int, array<string, mixed>>}
     */
    public function ticketGroups(?string $identifier, string $language = 'de'): array
    {
        $event = $this->orderFormService->findEvent((string)$identifier);
        $packages = [];
        $days = [];

        if ($event === null) {
            return ['packages' => [], 'days' => []];
        }

        foreach ($this->orderFormService->findTicketTypes($event) as $ticketIdentifier => $ticketType) {
            $coveredDays = $ticketType->getCoveredDays();

            if ($coveredDays->isEmpty()) {
                continue;
            }

            $row = $this->ticketRow($ticketIdentifier, $ticketType, $language);

            if ($coveredDays->count() > 1) {
                $packages[] = $row;
                continue;
            }

            $day = $coveredDays->first();
            $dayIdentifier = (string)$this->persistenceManager->getIdentifierByObject($day);

            $days[$dayIdentifier] ??= [
                'identifier' => $dayIdentifier,
                'label' => $this->dayLabel($day, $language),
                'date' => $day->getDate(),
                'bookable' => false,
                'fromPriceCents' => null,
                'tickets' => [],
            ];
            $days[$dayIdentifier]['tickets'][] = $row;

            if ($row['bookable']) {
                $days[$dayIdentifier]['bookable'] = true;
                $current = $days[$dayIdentifier]['fromPriceCents'];
                $days[$dayIdentifier]['fromPriceCents'] = $current === null ? $ticketType->getPrice() : min($current, $ticketType->getPrice());
            }
        }

        usort($days, static fn(array $a, array $b): int => $a['date'] <=> $b['date']);

        foreach ($days as &$day) {
            $day['fromPrice'] = $day['fromPriceCents'] !== null ? $this->priceFormatter->format($day['fromPriceCents'], $language) : null;
            unset($day['date'], $day['fromPriceCents']);
        }

        return ['packages' => $packages, 'days' => $days];
    }

    /**
     * 'maxQuantity' ist die Obergrenze für das Eingabefeld: Restplätze
     * des knappsten Tages, gedeckelt durch die Grenze der Ticketart.
     *
     * @return array<string, mixed>
     */
    protected function ticketRow(string $identifier, TicketType $ticketType, string $language): array
    {
        $maxQuantity = $ticketType->getAvailableQuantity();

        if ($ticketType->getMaxPerOrder() > 0) {
            $maxQuantity = min($maxQuantity, $ticketType->getMaxPerOrder());
        }

        return [
            'identifier' => $identifier,
            'title' => $ticketType->getLocalisedTitle($language),
            'description' => $ticketType->getLocalisedDescription($language),
            'price' => $this->priceFormatter->format($ticketType->getPrice(), $language),
            'bookable' => $ticketType->isBookable(),
            'maxQuantity' => max(0, $maxQuantity),
        ];
    }

    /**
     * "Freitag, 22.05.2027" bzw. "Friday, 22 May 2027", bei eigenem
     * Titel "Eröffnungsfeier – Freitag, 22.05.2027".
     */
    protected function dayLabel(EventDay $day, string $language): string
    {
        $pattern = $language === 'de' ? 'EEEE, dd.MM.yyyy' : 'EEEE, d MMMM yyyy';
        $formatter = new \IntlDateFormatter($language, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, $pattern);
        $date = $formatter->format($day->getDate()) ?: $day->getDate()->format('d.m.Y');

        $title = $language !== 'de' && $day->getTitleEn() ? $day->getTitleEn() : $day->getTitle();

        return $title ? $title . ' – ' . $date : $date;
    }

    public function formToken(): string
    {
        return $this->orderFormService->createFormToken();
    }

    /**
     * @return array<string, string> Feld => 'always', 'group' oder 'private'
     */
    public function fieldRequirements(): array
    {
        return $this->orderFormService->getFieldRequirements();
    }

    /**
     * @return array<string, int>
     */
    public function maxTicketsPerOrder(): array
    {
        return array_map('intval', $this->maxTicketsPerOrder);
    }

    /**
     * Eingeschaltete Zahlungsarten. Leer heisst: Das Formular fragt
     * keine ab, Bestellungen laufen über Opt-in und Freigabe.
     *
     * @return array<int, array{value: string, label: string, description: string, online: bool}>
     */
    public function paymentMethods(string $language = 'de'): array
    {
        $options = [];

        foreach ($this->paymentService->getMethods() as $key => $method) {
            $options[] = [
                'value' => (string)$key,
                'label' => $this->paymentService->getMethodLabel((string)$key, $language),
                'description' => $this->paymentService->getMethodDescription((string)$key, $language),
                'online' => ($method['type'] ?? '') === PaymentService::TYPE_ONLINE,
            ];
        }

        return $options;
    }

    /**
     * @param string $methodName
     */
    public function allowsCallOfMethod($methodName): bool
    {
        return true;
    }
}
