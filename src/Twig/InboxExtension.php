<?php

namespace App\Twig;

use App\Entity\Organization;
use App\Repository\ConversationRepository;
use App\Repository\OrganizationRepository;
use App\Service\CurrentOrganization;
use Twig\Attribute\AsTwigFunction;

class InboxExtension
{
    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly OrganizationRepository $organizations,
        private readonly CurrentOrganization $current,
    ) {
    }

    #[AsTwigFunction('open_conversations')]
    public function openConversations(): int
    {
        return $this->conversations->countOpen($this->current->get());
    }

    /** @return array<int, int> */
    #[AsTwigFunction('open_by_organization')]
    public function openByOrganization(): array
    {
        return $this->conversations->countOpenByOrganization();
    }

    #[AsTwigFunction('current_organization')]
    public function currentOrganization(): Organization
    {
        return $this->current->get();
    }

    /** @return Organization[] */
    #[AsTwigFunction('all_organizations')]
    public function allOrganizations(): array
    {
        return $this->organizations->findAllByName();
    }
}
