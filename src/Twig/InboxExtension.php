<?php

namespace App\Twig;

use App\Entity\Organization;
use App\Repository\ConversationRepository;
use App\Repository\OrganizationRepository;
use App\Service\CurrentOrganization;
use Twig\Attribute\AsTwigFilter;
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

    #[AsTwigFilter('duration')]
    public function duration(?float $seconds): string
    {
        if (null === $seconds) {
            return '–';
        }

        $minutes = (int) round($seconds / 60);

        return match (true) {
            $minutes < 1 => "<\u{a0}1\u{a0}min",
            $minutes < 60 => $minutes."\u{a0}min",
            $minutes < 1440 => \sprintf("%d\u{a0}h\u{a0}%02d", intdiv($minutes, 60), $minutes % 60),
            default => \sprintf("%d\u{a0}j\u{a0}%d\u{a0}h", intdiv($minutes, 1440), intdiv($minutes % 1440, 60)),
        };
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
