<?php

declare(strict_types=1);

namespace Survos\BrevoBundle\Controller;

use Brevo\Exceptions\BrevoApiException;
use Brevo\Exceptions\BrevoException;
use Survos\BrevoBundle\Command\BrevoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only views of the Brevo account, straight from the API (nothing cached or stored).
 * Lists and campaigns are edited in Brevo; each page links there.
 */
final class BrevoAdminController extends AbstractController
{
    public function __construct(
        private readonly BrevoService $brevo,
        #[Autowire('%survos_brevo.admin_role%')] private readonly string $role,
        #[Autowire('%survos_brevo.base_template%')] private readonly string $baseTemplate,
    ) {
    }

    #[Route('', name: 'survos_brevo_account', methods: ['GET'])]
    public function account(): Response
    {
        return $this->page('account', fn () => ['account' => $this->brevo->sdk()->account->getAccount()]);
    }

    #[Route('/lists', name: 'survos_brevo_lists', methods: ['GET'])]
    public function lists(): Response
    {
        return $this->page('lists', fn () => ['lists' => $this->brevo->lists(100)]);
    }

    #[Route('/campaigns', name: 'survos_brevo_campaigns', methods: ['GET'])]
    public function campaigns(Request $request): Response
    {
        $status = $request->query->getString('status') ?: null;

        return $this->page('campaigns', fn () => ['campaigns' => $this->brevo->campaigns($status, 100), 'status' => $status]);
    }

    #[Route('/logs', name: 'survos_brevo_logs', methods: ['GET'])]
    public function logs(Request $request): Response
    {
        $email = $request->query->getString('email') ?: null;
        $days = max(1, min(90, $request->query->getInt('days', 7)));

        return $this->page('logs', fn () => ['events' => $this->brevo->events($email, null, $days, 200), 'email' => $email, 'days' => $days]);
    }

    #[Route('/blocked', name: 'survos_brevo_blocked', methods: ['GET'])]
    public function blocked(): Response
    {
        return $this->page('blocked', fn () => ['blocked' => $this->brevo->blockedContacts(200)]);
    }

    /** @param \Closure(): array<string, mixed> $load */
    private function page(string $name, \Closure $load): Response
    {
        $this->denyAccessUnlessGranted($this->role);

        $data = [];
        $error = null;
        if (!$this->brevo->isConfigured()) {
            $error = 'BREVO_API_KEY is not set.';
        } else {
            try {
                $data = $load();
            } catch (BrevoApiException $e) {
                $error = sprintf('Brevo: %s', BrevoService::errorMessage($e));
            } catch (BrevoException $e) {
                $error = sprintf('Brevo: %s', $e->getMessage());
            }
        }

        return $this->render(sprintf('@SurvosBrevo/%s.html.twig', $name), $data + [
            'base_template' => $this->baseTemplate,
            'error' => $error,
            'page' => $name,
        ]);
    }
}
