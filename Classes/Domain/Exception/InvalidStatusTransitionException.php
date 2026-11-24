<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Exception;

/**
 * Ein Statuswechsel, den OrderStatus::transitions() nicht vorsieht.
 *
 * Schützt davor, dass ein Controller eine stornierte Bestellung
 * wieder freigibt oder eine unbestätigte direkt auf "bezahlt" setzt.
 */
class InvalidStatusTransitionException extends LanyardException
{
    public static function between(string $from, string $to): self
    {
        return new self(
            sprintf('Statuswechsel von "%s" nach "%s" ist nicht erlaubt.', $from, $to),
            1761224400
        );
    }
}