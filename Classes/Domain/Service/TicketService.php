<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Service;

use ISP\Lanyard\Domain\Model\Order;
use ISP\Lanyard\Domain\Model\Ticket;
use ISP\Lanyard\Domain\Repository\TicketRepository;
use Neos\Flow\Annotations as Flow;

/**
 * Stellt die Tickets einer Bestellung aus und macht sie ungültig.
 *
 * Wird nur vom OrderService aufgerufen, innerhalb der Transaktion des
 * Statuswechsels – flush() und Commit erledigt der Aufrufer.
 */
#[Flow\Scope('singleton')]
class TicketService
{
    protected const MAX_ATTEMPTS = 10;

    #[Flow\Inject]
    protected TicketRepository $ticketRepository;

    #[Flow\InjectConfiguration(path: 'tickets.code', package: 'ISP.Lanyard')]
    protected array $codeConfiguration = [];

    /**
     * Ein Ticket je Person: eine Position mit Menge 5 ergibt fünf.
     * Hat die Bestellung schon Tickets, bleibt es dabei – ein
     * wiederholter Aufruf stellt nichts doppelt aus.
     *
     * @return array<int, Ticket>
     */
    public function issue(Order $order): array
    {
        $existing = $this->ticketRepository->findByOrderInOrder($order);

        if ($existing !== []) {
            return $existing;
        }

        $tickets = [];
        $number = 1;

        foreach ($order->getItems() as $item) {
            for ($index = 0; $index < $item->getQuantity(); $index++) {
                $ticket = new Ticket($order, $item, $number++, $this->generateCode());
                $this->ticketRepository->add($ticket);
                $tickets[] = $ticket;
            }
        }

        return $tickets;
    }

    /**
     * Alle noch gültigen Tickets der Bestellung ungültig machen, z. B.
     * beim Storno. Die Tickets bleiben gespeichert, damit ein Scan
     * "storniert" statt "unbekannt" melden kann.
     */
    public function cancel(Order $order): void
    {
        foreach ($this->ticketRepository->findByOrderInOrder($order) as $ticket) {
            if ($ticket->isValid()) {
                $ticket->cancel();
                $this->ticketRepository->update($ticket);
            }
        }
    }

    /**
     * Wie die Buchungsnummer: zufällig, ohne verwechselbare Zeichen.
     * Bewusst länger, weil der Code allein den Einlass gewährt.
     * Der Unique-Index fängt die seltene Kollision zweier
     * gleichzeitiger Bestellungen ab.
     */
    protected function generateCode(): string
    {
        $alphabet = (string)($this->codeConfiguration['alphabet'] ?? '0123456789ABCDEFGHJKMNPQRSTVWXYZ');
        $length = (int)($this->codeConfiguration['length'] ?? 20);
        $maxIndex = strlen($alphabet) - 1;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = '';

            for ($position = 0; $position < $length; $position++) {
                $code .= $alphabet[random_int(0, $maxIndex)];
            }

            if ($this->ticketRepository->findOneByCode($code) === null) {
                return $code;
            }
        }

        throw new \RuntimeException('Es konnte kein freier Ticket-Code erzeugt werden.', 1764100101);
    }
}
