<?php

namespace App\Controller;

use App\Repository\OrganizationLogoRepository;
use App\Repository\OrganizationRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class LogoController
{
    /** L'URL porte un numéro de version : l'image peut donc se mettre en cache sans limite. */
    #[Route('/{slug}/logo', name: 'organization_logo', requirements: ['slug' => '[a-z0-9][a-z0-9-]*'], methods: ['GET'])]
    public function show(string $slug, OrganizationRepository $organizations, OrganizationLogoRepository $logos): Response
    {
        $organization = $organizations->findBySlug($slug);
        $logo = $organization ? $logos->findFor($organization) : null;

        if (null === $logo) {
            throw new NotFoundHttpException();
        }

        return new Response($logo->getContent(), Response::HTTP_OK, [
            'Content-Type' => $logo->getMimeType(),
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
