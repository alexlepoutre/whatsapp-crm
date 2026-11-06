<?php

namespace App\Controller;

use App\Repository\OrganizationRepository;
use App\Service\CurrentOrganization;
use App\Service\Statistics;
use App\Service\StatisticsPeriod;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/statistiques')]
#[IsGranted('ROLE_ADMIN')]
class StatisticsController extends AbstractController
{
    public function __construct(private readonly Statistics $statistics)
    {
    }

    #[Route('', name: 'admin_statistics')]
    public function organization(Request $request, CurrentOrganization $current): Response
    {
        $period = $this->period($request);

        return $this->render('admin/statistics.html.twig', [
            'period' => $period,
            'presets' => StatisticsPeriod::presets(),
            'report' => $this->statistics->report($current->get(), $period),
            'rows' => null,
        ]);
    }

    #[Route('/organisations', name: 'admin_statistics_all')]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function all(Request $request, OrganizationRepository $organizations): Response
    {
        $period = $this->period($request);
        $rows = [];

        foreach ($organizations->findAllByName() as $organization) {
            $rows[] = ['organization' => $organization, 'report' => $this->statistics->report($organization, $period)];
        }

        return $this->render('admin/statistics.html.twig', [
            'period' => $period,
            'presets' => StatisticsPeriod::presets(),
            'report' => $this->statistics->report(null, $period),
            'rows' => $rows,
        ]);
    }

    private function period(Request $request): StatisticsPeriod
    {
        return StatisticsPeriod::fromStrings($request->query->get('du'), $request->query->get('au'));
    }
}
