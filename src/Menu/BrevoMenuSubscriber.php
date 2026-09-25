<?php

declare(strict_types=1);

namespace Survos\BrevoBundle\Menu;

use Survos\BrevoBundle\Command\BrevoService;
use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Traits\KnpMenuHelperInterface;
use Survos\TablerBundle\Traits\KnpMenuHelperTrait;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * "Brevo" dropdown in the Tabler admin navbar. No network call here: the menu only needs to
 * know a key is set and the viewer may see the pages; each page reports its own API errors.
 */
final class BrevoMenuSubscriber implements KnpMenuHelperInterface
{
    use KnpMenuHelperTrait;

    public function __construct(
        private readonly BrevoService $brevo,
        private readonly AuthorizationCheckerInterface $auth,
        #[Autowire('%survos_brevo.admin_role%')] private readonly string $role,
    ) {
    }

    #[AsEventListener(event: MenuEvent::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        if (!$this->brevo->isConfigured() || !$this->auth->isGranted($this->role)) {
            return;
        }
        $menu = $this->addSubmenu($event->getMenu(), 'Brevo', 'tabler:mail');
        $this->add($menu, route: 'survos_brevo_account', label: 'Account', icon: 'tabler:building');
        $this->add($menu, route: 'survos_brevo_lists', label: 'Lists', icon: 'tabler:list');
        $this->add($menu, route: 'survos_brevo_campaigns', label: 'Campaigns', icon: 'tabler:speakerphone');
        $this->add($menu, route: 'survos_brevo_logs', label: 'Logs', icon: 'tabler:history');
        $this->add($menu, route: 'survos_brevo_blocked', label: 'Blocked', icon: 'tabler:ban');
    }
}
