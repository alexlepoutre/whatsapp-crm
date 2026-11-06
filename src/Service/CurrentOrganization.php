<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Repository\OrganizationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * L'organisation dont le back-office affiche les données. Un compte normal est lié à la
 * sienne pour toujours ; seul un super-admin peut passer de l'une à l'autre.
 */
class CurrentOrganization
{
    private const SESSION_KEY = 'current_organization';

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requests,
        private readonly OrganizationRepository $organizations,
        private readonly ConversationRepository $conversations,
    ) {
    }

    public function get(): Organization
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        if (!$user->isSuperAdmin()) {
            return $user->getOrganization() ?? throw new NotFoundHttpException('Compte sans organisation.');
        }

        $id = $this->requests->getSession()->get(self::SESSION_KEY);

        return ($id ? $this->organizations->find($id) : null)
            ?? $this->busiest()
            ?? $this->organizations->findAllByName()[0]
            ?? throw new NotFoundHttpException('Aucune organisation.');
    }

    /**
     * Sans choix en session, ce qui arrive après chaque déploiement, on ouvre là où des messages
     * attendent : une organisation vide affichée par défaut fait croire à des messages perdus.
     */
    private function busiest(): ?Organization
    {
        $counts = $this->conversations->countOpenByOrganization();
        arsort($counts);
        $id = array_key_first($counts);

        return null === $id ? null : $this->organizations->find($id);
    }

    public function switchTo(Organization $organization): void
    {
        $user = $this->security->getUser();

        if ($user instanceof User && $user->isSuperAdmin()) {
            $this->requests->getSession()->set(self::SESSION_KEY, $organization->getId());
        }
    }

    /**
     * Un identifiant dans l'URL ne prouve rien : la fiche doit appartenir à l'organisation
     * du compte. 404 et non 403, pour ne pas confirmer qu'elle existe ailleurs.
     */
    public function assertOwns(Client $client): void
    {
        $user = $this->security->getUser();

        // Un lien d'alerte peut viser une autre organisation que celle affichée.
        if ($user instanceof User && $user->isSuperAdmin()) {
            $this->switchTo($client->getOrganization());

            return;
        }

        if ($client->getOrganization() !== $this->get()) {
            throw new NotFoundHttpException();
        }
    }
}
