<?php
declare(strict_types=1);

namespace ISP\Lanyard\Payment;

use ISP\Lanyard\Domain\Exception\LanyardException;

/**
 * Das Gateway hat eine Anfrage abgelehnt oder war nicht erreichbar.
 */
class PaymentException extends LanyardException
{
}
