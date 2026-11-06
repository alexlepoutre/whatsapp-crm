<?php

namespace App\Controller;

use App\Repository\OrganizationRepository;
use App\Service\PhoneNormalizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Propositions de page d'accueil, à comparer avant d'en retenir une.
 */
class LandingController extends AbstractController
{
    #[Route('/{version}', name: 'landing', requirements: ['version' => 'v[123]'])]
    public function show(
        string $version,
        OrganizationRepository $organizations,
        #[Autowire('%env(WHATSAPP_PUBLIC_NUMBER)%')] string $publicNumber,
    ): Response {
        return $this->render(\sprintf('public/landing/%s.html.twig', $version), [
            'version' => $version,
            'editor' => $organizations->findBySlug('itroom'),
            'whatsappNumber' => PhoneNormalizer::normalize($publicNumber),
        ]);
    }
}
