<?php
declare(strict_types=1);

namespace ISP\Lanyard\Service;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;
use Dompdf\Options;
use ISP\Lanyard\Domain\Exception\LanyardException;
use ISP\Lanyard\Domain\Model\EventDay;
use ISP\Lanyard\Domain\Model\Order;
use ISP\Lanyard\Domain\Model\Ticket;
use ISP\Lanyard\Domain\Repository\TicketRepository;
use ISP\Lanyard\Payment\PaymentService;
use Neos\Flow\Annotations as Flow;
use Neos\FluidAdaptor\View\StandaloneView;
use Neos\Utility\Files;

/**
 * Erzeugt das PDF mit den Tickets einer Bestellung – eine Seite je
 * Ticket.
 *
 * Das Aussehen bestimmt ein Fluid-Template (tickets.pdf.template), das
 * das Sitepackage durch ein eigenes ersetzt. Gerendert wird es mit
 * dompdf: HTML und CSS, aber kein Flexbox und kein Grid – Layout über
 * Tabellen oder absolute Positionen.
 *
 * dompdf lädt nichts aus dem Netz. Bilder und Schriften gehören als
 * data-URI ins Template (ViewHelper lanyard:dataUri) oder liegen unter
 * dem Wurzelverzeichnis der Installation.
 */
#[Flow\Scope('singleton')]
class TicketPdfService
{
    #[Flow\Inject]
    protected TicketRepository $ticketRepository;

    #[Flow\Inject]
    protected PriceFormatter $priceFormatter;

    #[Flow\Inject]
    protected PaymentService $paymentService;

    #[Flow\InjectConfiguration(path: 'tickets', package: 'ISP.Lanyard')]
    protected array $settings = [];

    /**
     * Gültige Tickets der Bestellung als PDF-Dateien.
     *
     * Große Bestellungen werden auf mehrere Dateien verteilt
     * (tickets.pdf.ticketsPerFile): dompdf braucht für ein Dokument
     * überproportional Zeit und Speicher – 100 Tickets in einer Datei
     * dauern 13 s und belegen 130 MB, in vier Dateien 10 s und 60 MB.
     *
     * @return array<string, string> Dateiname => PDF, leer ohne gültige
     *     Tickets
     * @throws LanyardException ohne Template
     */
    public function renderFiles(Order $order): array
    {
        $files = [];

        for ($part = 1, $count = $this->getFileCount($order); $part <= $count; $part++) {
            [$filename, $pdf] = $this->renderFile($order, $part);
            $files[$filename] = $pdf;
        }

        return $files;
    }

    /**
     * Anzahl der PDF-Dateien für die gültigen Tickets, 0 ohne Tickets.
     */
    public function getFileCount(Order $order): int
    {
        return (int)ceil(count($this->validTickets($order)) / $this->ticketsPerFile());
    }

    /**
     * Eine einzelne Datei, z. B. für den Download im Backend.
     *
     * @return array{0: string, 1: string} Dateiname, PDF
     * @throws LanyardException ohne gültige Tickets, ohne Template oder
     *     wenn es den Teil nicht gibt
     */
    public function renderFile(Order $order, int $part = 1): array
    {
        $tickets = $this->validTickets($order);

        if ($tickets === []) {
            throw new LanyardException(sprintf('Order %s has no valid tickets.', $order->getBookingNumber()), 1764100201);
        }

        $chunks = array_chunk($tickets, $this->ticketsPerFile());

        if (!isset($chunks[$part - 1])) {
            throw new LanyardException(sprintf('Order %s has no ticket file %d.', $order->getBookingNumber(), $part), 1764100203);
        }

        $templatePath = (string)($this->settings['pdf']['template'] ?? '');

        if ($templatePath === '') {
            throw new LanyardException('ISP.Lanyard.tickets.pdf.template is not configured.', 1764100202);
        }

        $language = $order->getLanguage();

        $view = new StandaloneView();
        $view->setTemplatePathAndFilename($templatePath);
        $view->assignMultiple([
            'order' => $order,
            'language' => $language,
            'bookingNumber' => $order->getBookingNumber(),
            'eventTitle' => $order->getEvent()->getLocalisedTitle($language),
            'holder' => $order->isGroupOrder() && $order->getOrganisation() ? $order->getOrganisation() : $order->getFullName(),
            'fullName' => $order->getFullName(),
            'paymentMethod' => $this->paymentService->getMethodLabel($order->getPaymentMethod(), $language),
            'ticketCount' => count($tickets),
            'tickets' => array_map(fn(Ticket $ticket): array => $this->buildTicket($ticket, count($tickets), $language), $chunks[$part - 1]),
        ]);

        return [
            $this->getFilename($order, count($chunks) > 1 ? $part : null),
            $this->toPdf((string)$view->render()),
        ];
    }

    /**
     * Dateiname für Anhang und Download, bei mehreren Dateien mit
     * laufender Nummer.
     */
    public function getFilename(Order $order, ?int $part = null): string
    {
        $pattern = (string)($this->settings['pdf']['filename'] ?? 'Tickets-{bookingNumber}.pdf');
        $filename = str_replace('{bookingNumber}', (string)$order->getBookingNumber(), $pattern);

        return $part === null ? $filename : preg_replace('/(\.pdf)?$/i', '-' . $part . '$1', $filename, 1);
    }

    /**
     * @return array<int, Ticket>
     */
    protected function validTickets(Order $order): array
    {
        return array_values(array_filter(
            $this->ticketRepository->findByOrderInOrder($order),
            static fn(Ticket $ticket): bool => $ticket->isValid()
        ));
    }

    protected function ticketsPerFile(): int
    {
        return max(1, (int)($this->settings['pdf']['ticketsPerFile'] ?? 25));
    }

    /**
     * Flache Daten je Ticket, damit das Template nicht in Entities
     * greifen muss.
     *
     * @return array<string, mixed>
     */
    protected function buildTicket(Ticket $ticket, int $count, string $language): array
    {
        $item = $ticket->getOrderItem();
        $ticketType = $item->getTicketType();

        return [
            'number' => $ticket->getNumber(),
            'count' => $count,
            'code' => $ticket->getCode(),
            'qrCode' => $this->qrCode($ticket->getCode()),
            'title' => $ticketType !== null && $language !== 'de'
                ? $ticketType->getLocalisedTitle($language)
                : $item->getTicketTypeTitle(),
            'days' => array_map(
                static fn(EventDay $day): string => $day->getLabel($language),
                $item->getAffectedDays()
            ),
            'price' => $this->priceFormatter->format($item->getUnitPrice(), $language),
        ];
    }

    /**
     * QR-Code als SVG-data-URI – Vektor, bleibt im Druck scharf.
     * Inhalt ist der Ticket-Code mit optionalem Präfix
     * (tickets.qr.prefix), z. B. für eine App, die fremde Codes
     * erkennen soll.
     */
    protected function qrCode(string $code): string
    {
        $options = new QROptions([
            // outputInterface greift in php-qrcode 5 nur mit CUSTOM
            'outputType' => QROutputInterface::CUSTOM,
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'quietzoneSize' => 2,
            'addQuietzone' => true,
        ]);

        return (new QRCode($options))->render((string)($this->settings['qr']['prefix'] ?? '') . $code);
    }

    protected function toPdf(string $html): string
    {
        $fontDirectory = FLOW_PATH_DATA . 'Temporary/Lanyard/Fonts/';
        Files::createDirectoryRecursively($fontDirectory);

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setChroot([FLOW_PATH_ROOT]);
        $options->setFontDir($fontDirectory);
        $options->setFontCache($fontDirectory);
        $options->setTempDir($fontDirectory);
        $options->setDefaultFont((string)($this->settings['pdf']['defaultFont'] ?? 'DejaVu Sans'));
        $options->setDpi(96);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper(
            (string)($this->settings['pdf']['paper'] ?? 'A4'),
            (string)($this->settings['pdf']['orientation'] ?? 'portrait')
        );
        $dompdf->render();

        return (string)$dompdf->output();
    }
}
