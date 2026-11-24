<?php
declare(strict_types=1);

namespace ISP\Lanyard\Service;

use ISP\Lanyard\Domain\Exception\LanyardException;
use ISP\Lanyard\Domain\Model\EventDay;
use ISP\Lanyard\Domain\Model\Order;
use ISP\Lanyard\Domain\Model\OrderStatus;
use ISP\Lanyard\Domain\Model\PaymentStatus;
use ISP\Lanyard\Payment\PaymentService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\BaseUriProvider;
use Neos\FluidAdaptor\View\StandaloneView;
use Neos\SymfonyMailer\Service\MailerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Verschickt die Mails rund um eine Bestellung.
 *
 * Wird nicht aus Diensten aufgerufen, sondern über die Signale des
 * OrderService angestossen – die Verdrahtung steht in der Package.php.
 *
 * Jedes Template liefert zwei Sections: "subject" für den Betreff und
 * "body" für den Inhalt. Ein leerer Template-Pfad in den Settings
 * schaltet die jeweilige Mail ab.
 */
#[Flow\Scope('singleton')]
class MailService
{
    public const TEMPLATE_OPT_IN = 'optIn';
    public const TEMPLATE_CONFIRMED = 'confirmed';
    public const TEMPLATE_REJECTED = 'rejected';
    public const TEMPLATE_CANCELLED = 'cancelled';
    public const TEMPLATE_NOTIFICATION = 'notification';
    public const TEMPLATE_PAID = 'paid';

    #[Flow\Inject]
    protected MailerService $mailerService;

    #[Flow\Inject]
    protected BaseUriProvider $baseUriProvider;

    /**
     * Nicht verzögert: Die verzögerte Injektion legt eine Referenz auf
     * die Property an, das ist bei einer typisierten, noch leeren
     * Property in PHP 8 ein Fehler.
     */
    #[Flow\Inject(name: 'Neos.Flow:SystemLogger', lazy: false)]
    protected LoggerInterface $logger;

    #[Flow\Inject]
    protected PriceFormatter $priceFormatter;

    #[Flow\Inject]
    protected PaymentService $paymentService;

    #[Flow\InjectConfiguration(path: 'mail', package: 'ISP.Lanyard')]
    protected array $mailSettings = [];

    #[Flow\InjectConfiguration(path: 'optIn.confirmationUri', package: 'ISP.Lanyard')]
    protected string $confirmationUri = '';

    /**
     * Bestätigungslink an den Besteller.
     *
     * Manuell im Backend erfasste Bestellungen senden dasselbe Signal,
     * haben aber kein Opt-in – sie werden hier übergangen.
     */
    public function sendOptIn(Order $order): void
    {
        if ($order->getStatus() !== OrderStatus::RECEIVED || $order->getOptInToken() === null) {
            return;
        }

        $this->sendToOrderer(self::TEMPLATE_OPT_IN, $order, [
            'optInUri' => $this->buildConfirmationUri($order->getOptInToken()),
        ]);
    }

    public function sendConfirmed(Order $order): void
    {
        $this->sendToOrderer(self::TEMPLATE_CONFIRMED, $order);
    }

    public function sendRejected(Order $order): void
    {
        $this->sendToOrderer(self::TEMPLATE_REJECTED, $order);
    }

    public function sendCancelled(Order $order): void
    {
        $this->sendToOrderer(self::TEMPLATE_CANCELLED, $order);
    }

    /**
     * Zahlung eingegangen – online über das Gateway oder vom Orga-Team
     * als bezahlt markiert. Bei Online-Zahlung ist das die einzige
     * Bestätigung, die der Besteller bekommt.
     */
    public function sendPaid(Order $order): void
    {
        $this->sendToOrderer(self::TEMPLATE_PAID, $order);
    }

    /**
     * Benachrichtigung des Orga-Teams bei Online-Zahlung. Offline-
     * Bestellungen haben ihre Benachrichtigung schon mit dem Opt-in
     * bekommen.
     */
    public function sendPaidNotification(Order $order): void
    {
        if ($this->paymentService->isOnline($order->getPaymentMethod())) {
            $this->sendNotification($order);
        }
    }

    /**
     * Hinweis an das Orga-Team, dass eine Anfrage zur Freigabe wartet.
     * Ohne Empfänger in den Settings geht nichts raus.
     */
    public function sendNotification(Order $order): void
    {
        $recipients = array_filter((array)($this->mailSettings['notificationRecipients'] ?? []));

        if ($recipients === []) {
            return;
        }

        $language = (string)($this->mailSettings['notificationLanguage'] ?? 'de');

        $this->send(self::TEMPLATE_NOTIFICATION, $order, $recipients, $language);
    }

    /**
     * @param array<string, mixed> $variables
     */
    protected function sendToOrderer(string $templateName, Order $order, array $variables = []): void
    {
        $this->send($templateName, $order, [$order->getEmail()], $order->getLanguage(), $variables);
    }

    /**
     * @param array<int, string> $recipients
     * @param array<string, mixed> $variables
     * @throws LanyardException
     */
    protected function send(
        string $templateName,
        Order $order,
        array $recipients,
        string $language,
        array $variables = []
    ): void {
        $templatePath = (string)($this->mailSettings['templates'][$templateName] ?? '');

        if ($templatePath === '') {
            return;
        }

        $senderAddress = (string)($this->mailSettings['senderAddress'] ?? '');

        if ($senderAddress === '') {
            throw new LanyardException('ISP.Lanyard.mail.senderAddress is not configured.', 1761220001);
        }

        $view = new StandaloneView();
        $view->setTemplatePathAndFilename($templatePath);
        $view->assignMultiple(array_merge($this->buildVariables($order, $language), $variables));

        $subject = trim(preg_replace('/\s+/', ' ', (string)$view->renderSection('subject')));
        $html = (string)$view->renderSection('body');

        $email = (new Email())
            ->from(new Address($senderAddress, (string)($this->mailSettings['senderName'] ?? '')))
            ->to(...$recipients)
            ->subject($subject)
            ->html($html)
            ->text($this->htmlToText($html));

        $replyTo = (string)($this->mailSettings['replyToAddress'] ?? '');

        if ($replyTo !== '') {
            $email->replyTo($replyTo);
        }

        // Der Statuswechsel ist zu diesem Zeitpunkt bereits gespeichert.
        // Ein Versandfehler soll ihn nicht als gescheitert erscheinen
        // lassen, sondern landet im Log.
        try {
            $this->mailerService->getMailer()->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error(sprintf(
                'ISP.Lanyard: Mail "%s" für Bestellung %s konnte nicht versendet werden: %s',
                $templateName,
                $order->getBookingNumber() ?? '-',
                $exception->getMessage()
            ));
        }
    }

    /**
     * Flache Daten für das Template. Beträge kommen fertig formatiert,
     * damit im Template nicht mit Cent gerechnet werden muss.
     *
     * @return array<string, mixed>
     */
    protected function buildVariables(Order $order, string $language): array
    {
        $items = [];

        foreach ($order->getItems() as $item) {
            $ticketType = $item->getTicketType();

            $items[] = [
                'title' => $ticketType !== null && $language !== 'de'
                    ? $ticketType->getLocalisedTitle($language)
                    : $item->getTicketTypeTitle(),
                // Tagestickets tragen oft denselben Titel für jeden Tag –
                // erst die Tage machen die Position eindeutig.
                'days' => implode(', ', array_map(
                    static fn(EventDay $day): string => $day->getLabel($language),
                    $item->getAffectedDays()
                )),
                'quantity' => $item->getQuantity(),
                'unitPrice' => $this->formatAmount($item->getUnitPrice(), $language),
                'subtotal' => $this->formatAmount($item->getSubtotal(), $language),
            ];
        }

        return [
            'order' => $order,
            'language' => $language,
            'bookingNumber' => $order->getBookingNumber(),
            'eventTitle' => $order->getEvent()->getLocalisedTitle($language),
            'fullName' => $order->getFullName(),
            'organisation' => $order->getOrganisation(),
            'isGroupOrder' => $order->isGroupOrder(),
            'items' => $items,
            'totalQuantity' => $order->getTotalQuantity(),
            'totalAmount' => $this->formatAmount($order->getTotalAmount(), $language),
            'rejectionReason' => $order->getRejectionReason(),
            'message' => $order->getMessage(),
            'paymentMethod' => $this->paymentService->getMethodLabel($order->getPaymentMethod(), $language),
            'isOnlinePayment' => $this->paymentService->isOnline($order->getPaymentMethod()),
            'isPaid' => $order->getStatus() === OrderStatus::PAID,
            'isRefunded' => $order->getPaymentStatus() === PaymentStatus::REFUNDED,
        ];
    }

    /**
     * Ein relativer Pfad wird um die Basis-URI der Installation
     * ergänzt, damit der Link auch aus Kommandos heraus stimmt,
     * sofern Neos.Flow.http.baseUri gesetzt ist.
     *
     * @throws LanyardException
     */
    protected function buildConfirmationUri(string $token): string
    {
        if ($this->confirmationUri === '') {
            throw new LanyardException('ISP.Lanyard.optIn.confirmationUri is not configured.', 1761220002);
        }

        $uri = str_replace('{token}', rawurlencode($token), $this->confirmationUri);

        if (preg_match('#^https?://#i', $uri) === 1) {
            return $uri;
        }

        $baseUri = (string)$this->baseUriProvider->getConfiguredBaseUriOrFallbackToCurrentRequest();

        return rtrim($baseUri, '/') . '/' . ltrim($uri, '/');
    }

    protected function formatAmount(int $cents, string $language): string
    {
        return $this->priceFormatter->format($cents, $language);
    }

    protected function htmlToText(string $html): string
    {
        $text = preg_replace('#<br\s*/?>|</(p|div|tr|h[1-6]|li)>#i', "\n", $html);
        $text = html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n\s*\n\s*\n+/", "\n\n", (string)$text);

        return trim((string)$text);
    }
}
