<?php
declare(strict_types=1);

namespace ISP\Lanyard\Domain\Exception;

use Neos\Flow\Exception;

/**
 * Basisklasse aller fachlichen Fehler des Packages. Erlaubt es
 * aufrufendem Code, Lanyard-Fehler von technischen zu unterscheiden.
 */
class LanyardException extends Exception
{
}