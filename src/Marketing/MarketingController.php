<?php

namespace App\Marketing;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Supports de communication, consultables en local uniquement. #[When] est indispensable : tout
 * contrôleur enregistré obtient ses routes automatiquement, où qu'il soit rangé. Le dossier
 * marketing/ n'entre pas non plus dans l'image de production.
 */
#[When(env: 'dev')]
class MarketingController extends AbstractController
{
    public const SLIDES = 7;

    #[Route('/linkedin', name: 'marketing_linkedin')]
    public function linkedin(): Response
    {
        return $this->render('marketing/linkedin.html.twig', ['slides' => self::SLIDES]);
    }

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

    #[Route('/article', name: 'marketing_article')]
    public function article(): Response
    {
        return $this->render('marketing/article.html.twig');
    }

    #[Route('/marketing/{file}', name: 'marketing_file', requirements: ['file' => '[a-z0-9-]+\.(png|jpg|pdf)'])]
    public function serve(string $file, #[Autowire('%kernel.project_dir%/marketing')] string $directory): Response
    {
        $path = $directory.'/'.$file;

        if (!is_file($path)) {
            throw $this->createNotFoundException();
        }

        return new BinaryFileResponse($path);
    }
}
