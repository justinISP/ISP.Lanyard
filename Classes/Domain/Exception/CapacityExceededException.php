<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Exception;

use ISP\Lanyard\Domain\Model\EventDay;

/**
 * Wird geworfen, wenn eine Bestellung an mindestens einem Tag mehr
 * Plätze belegen würde, als verfügbar sind.
 *
 * Trägt den betroffenen Tag mit, damit das Formular eine konkrete
 * Meldung ausgeben kann statt eines allgemeinen "ausgebucht".
 */
class CapacityExceededException extends LanyardException
{
    protected ?EventDay $affectedDay = null;

    protected int $requestedSeats = 0;

    protected int $availableSeats = 0;

    public static function forDay(
        EventDay $day,
        int $requestedSeats,
        int $availableSeats
    ): self {
        $exception = new self(
            sprintf(
                'Nicht genügend Plätze am %s: %d angefragt, %d verfügbar.',
                $day->getDate()->format('d.m.Y'),
                $requestedSeats,
                $availableSeats
            ),
            1761220800
        );

        $exception->affectedDay = $day;
        $exception->requestedSeats = $requestedSeats;
        $exception->availableSeats = $availableSeats;

        return $exception;
    }

    public static function dayClosed(EventDay $day): self
    {
        $exception = new self(
            sprintf(
                'Der %s ist für Bestellungen gesperrt.',
                $day->getDate()->format('d.m.Y')
            ),
            1761220801
        );

        $exception->affectedDay = $day;

        return $exception;
    }

    public function getAffectedDay(): ?EventDay
    {
        return $this->affectedDay;
    }

    public function getRequestedSeats(): int
    {
        return $this->requestedSeats;
    }

    public function getAvailableSeats(): int
    {
        return $this->availableSeats;
    }
}