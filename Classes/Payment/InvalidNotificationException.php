<?php
declare(strict_types=1);

namespace ISP\Lanyard\Payment;

/**
 * Eine Webhook-Anfrage, die nicht vom Gateway stammt – Signatur fehlt
 * oder stimmt nicht. Wird nicht verarbeitet.
 */
class InvalidNotificationException extends PaymentException
{
}
