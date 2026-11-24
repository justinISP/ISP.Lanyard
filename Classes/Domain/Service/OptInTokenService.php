<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Service;

use Neos\Flow\Annotations as Flow;

/**
 * Erzeugt Tokens für die E-Mail-Bestätigung.
 *
 * Getrennt von der Buchungsnummer: Das Token landet in einer URL und
 * darf dort nicht die Buchungsnummer preisgeben, die später Tickets
 * identifiziert.
 */
#[Flow\Scope('singleton')]
class OptInTokenService
{
    protected const TOKEN_BYTES = 32;

    public function generate(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * Vergleich in konstanter Zeit, damit sich ein Token nicht über
     * Laufzeitunterschiede Zeichen für Zeichen erraten lässt.
     */
    public function matches(string $expected, string $given): bool
    {
        return hash_equals($expected, $given);
    }
}