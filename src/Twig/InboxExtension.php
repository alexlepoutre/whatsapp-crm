<?php

namespace App\Twig;

use App\Repository\ConversationRepository;
use Twig\Attribute\AsTwigFunction;

class InboxExtension
{
    public function __construct(private readonly ConversationRepository $conversations)
    {
    }

    #[AsTwigFunction('open_conversations')]
    public function openConversations(): int
    {
        return $this->conversations->countOpen();
    }
}
