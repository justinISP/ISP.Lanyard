<?php
declare(strict_types=1);

namespace ISP\Lanyard\Controller\Module\Management;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Flow\Security\Authorization\PrivilegeManagerInterface;
use Neos\Flow\Security\Exception\AccessDeniedException;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;

/**
 * Gemeinsame Basis der Controller im Modul "Tickets".
 *
 * Setzt die Fusion-Pfade (statt über eine Views.yaml – deren
 * Request-Filter griff im Modul-Subrequest nicht), baut die Reiter und
 * reicht Flash-Meldungen und Rechte an die Fusion weiter.
 *
 * Rechte: Der ModulePrivilege auf management/lanyard gibt alle Actions
 * des OrderControllers frei – auch für TicketViewer. Schreibende
 * Actions prüfen deshalb selbst mit assertGranted().
 */
abstract class AbstractTicketModuleController extends AbstractModuleController
{
    public const PRIVILEGE_MANAGE_ORDERS = 'ISP.Lanyard:ManageOrders';
    public const PRIVILEGE_MANAGE_SETUP = 'ISP.Lanyard:ManageSetup';

    public const MODULE_ORDERS = 'management/lanyard';
    public const MODULE_SETUP = 'management/lanyard/setup';

    /**
     * @var string
     */
    protected $defaultViewObjectName = FusionView::class;

    #[Flow\Inject]
    protected PrivilegeManagerInterface $privilegeManager;

    #[Flow\InjectConfiguration(path: 'backend.fusionPathPatterns', package: 'ISP.Lanyard')]
    protected array $fusionPathPatterns = [];

    protected function initializeView(ViewInterface $view)
    {
        parent::initializeView($view);

        if ($view instanceof FusionView) {
            $view->setFusionPathPatterns(array_values(array_filter($this->fusionPathPatterns)));
        }

        $can = [
            'manageOrders' => $this->isGranted(self::PRIVILEGE_MANAGE_ORDERS),
            'manageSetup' => $this->isGranted(self::PRIVILEGE_MANAGE_SETUP),
        ];

        $view->assignMultiple([
            'lanyardLanguage' => 'de',
            'lanyardCan' => $can,
            'lanyardTabs' => [
                'list' => $this->moduleUri(self::MODULE_ORDERS, 'index'),
                'capacity' => $this->moduleUri(self::MODULE_ORDERS, 'capacity'),
                'pickup' => $this->moduleUri(self::MODULE_ORDERS, 'pickup'),
                'setup' => $can['manageSetup'] ? $this->moduleUri(self::MODULE_SETUP, 'index') : null,
            ],
            'flashMessages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
        ]);
    }

    /**
     * URI auf ein Modul dieses Packages, über den Haupt-Request. Der
     * UriBuilder des Modul-Subrequests kann nur Actions des eigenen
     * Controllers erreichen.
     */
    protected function moduleUri(string $module, string $action): string
    {
        $uriBuilder = new UriBuilder();
        $uriBuilder->setRequest($this->request->getMainRequest());

        return $uriBuilder->uriFor(
            'index',
            ['module' => $module, 'moduleArguments' => ['@action' => $action]],
            'Backend\Module',
            'Neos.Neos'
        );
    }

    protected function isGranted(string $privilegeTarget): bool
    {
        return $this->privilegeManager->isPrivilegeTargetGranted($privilegeTarget);
    }

    /**
     * @throws AccessDeniedException
     */
    protected function assertGranted(string $privilegeTarget): void
    {
        if (!$this->isGranted($privilegeTarget)) {
            throw new AccessDeniedException(sprintf('Privilege "%s" is required.', $privilegeTarget), 1761490001);
        }
    }
}
