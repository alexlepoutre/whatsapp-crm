<?php

namespace App\Marketing;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sources des diapositives, pour les régénérer en local. #[When] est indispensable : tout
 * contrôleur enregistré obtient ses routes automatiquement, où qu'il soit rangé.
 */
#[When(env: 'dev')]
class SlideController extends AbstractController
{
    public const SLIDES = 7;

    #[Route('/linkedin/slide/{number}', name: 'marketing_slide', requirements: ['number' => '[1-7]'])]
    public function slide(int $number): Response
    {
        return $this->render('marketing/slide.html.twig', ['numbers' => [$number]]);
    }

    #[Route('/linkedin/carrousel', name: 'marketing_carousel')]
    public function carousel(): Response
    {
        return $this->render('marketing/slide.html.twig', ['numbers' => range(1, self::SLIDES)]);
    }
}
