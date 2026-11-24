<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Service;

use ISP\Lanyard\Domain\Repository\OrderRepository;
use Neos\Flow\Annotations as Flow;

/**
 * Erzeugt nicht erratbare Buchungsnummern.
 *
 * Bewusst kein fortlaufender Zähler: Aus einer Nummer darf sich
 * keine andere ableiten lassen, da in Phase 2 QR-Tickets daran
 * hängen.
 */
#[Flow\Scope('singleton')]
class BookingNumberService
{
    /**
     * Mehr Versuche sind nicht sinnvoll – bei einer Kollisionsrate,
     * die zehn Versuche scheitern lässt, ist der Zeichenvorrat zu
     * klein gewählt.
     */
    protected const MAX_ATTEMPTS = 10;

    #[Flow\InjectConfiguration(path: 'bookingNumber', package: 'ISP.Lanyard')]
    protected array $configuration = [];

    #[Flow\Inject]
    protected OrderRepository $orderRepository;

    /**
     * Liefert eine Nummer, die noch nicht vergeben ist.
     *
     * Die Prüfung gegen die Datenbank ersetzt keinen Unique-Index –
     * zwei gleichzeitige Bestellungen könnten dieselbe Nummer
     * erzeugen. Der Index an der Spalte fängt das ab, und der
     * Wiederholungsversuch greift beim nächsten Durchlauf.
     */
    public function generate(): string
    {
        $prefix = (string)($this->configuration['prefix'] ?? '');

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = $prefix . $this->randomSequence();

            if ($this->orderRepository->findOneByBookingNumber($candidate) === null) {
                return $candidate;
            }
        }

        throw new \RuntimeException(
            'Es konnte keine freie Buchungsnummer erzeugt werden. '
            . 'Vermutlich ist der Zeichenvorrat oder die Länge zu knapp konfiguriert.',
            1761224401
        );
    }

    /**
     * Zufallsfolge aus dem konfigurierten Alphabet.
     *
     * random_int() statt rand(): kryptografisch sicher, und genau
     * darum geht es bei einer nicht erratbaren Nummer.
     */
    protected function randomSequence(): string
    {
        $alphabet = (string)($this->configuration['alphabet'] ?? '0123456789ABCDEFGHJKMNPQRSTVWXYZ');
        $length = (int)($this->configuration['length'] ?? 10);

        $maxIndex = strlen($alphabet) - 1;
        $sequence = '';

        for ($position = 0; $position < $length; $position++) {
            $sequence .= $alphabet[random_int(0, $maxIndex)];
        }

        return $sequence;
    }
}