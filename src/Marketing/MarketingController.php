<?php

namespace App\Marketing;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Supports de communication, en ligne mais non référencés. Les images sont dans public/marketing. */
class MarketingController extends AbstractController
{
    #[Route('/linkedin', name: 'marketing_linkedin')]
    public function linkedin(): Response
    {
        return $this->render('marketing/linkedin.html.twig', ['slides' => SlideController::SLIDES]);
    }

    #[Route('/article', name: 'marketing_article')]
    public function article(): Response
    {
        return $this->render('marketing/article.html.twig');
    }
}
