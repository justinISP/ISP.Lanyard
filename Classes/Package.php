<?php
declare(strict_types=1);

namespace ISP\Lanyard;

use ISP\Lanyard\Domain\Service\OrderService;
use ISP\Lanyard\Service\MailService;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;

/**
 * Verdrahtet die Signale des OrderService mit dem Mailversand.
 *
 * Der OrderService selbst verschickt nichts. Das Sitepackage kann
 * weitere Slots an dieselben Signale hängen oder einzelne Mails
 * abschalten, indem es den zugehörigen Template-Pfad in den
 * Settings leert.
 */
class Package extends BasePackage
{
    public function boot(Bootstrap $bootstrap): void
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();

        $dispatcher->connect(OrderService::class, 'orderPlaced', MailService::class, 'sendOptIn');
        $dispatcher->connect(OrderService::class, 'optInConfirmed', MailService::class, 'sendNotification');
        $dispatcher->connect(OrderService::class, 'orderApproved', MailService::class, 'sendConfirmed');
        $dispatcher->connect(OrderService::class, 'orderRejected', MailService::class, 'sendRejected');
        $dispatcher->connect(OrderService::class, 'orderCancelled', MailService::class, 'sendCancelled');
        $dispatcher->connect(OrderService::class, 'orderPaid', MailService::class, 'sendPaid');
        $dispatcher->connect(OrderService::class, 'orderPaid', MailService::class, 'sendPaidNotification');
    }
}
