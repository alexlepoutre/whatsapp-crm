<?php

namespace App\Enum;

enum ConversationStatus: string
{
    case OPEN = 'open';
    case ANSWERED = 'answered';
    case CLOSED = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'À traiter',
            self::ANSWERED => 'Répondu',
            self::CLOSED => 'Clôturé',
        };
    }
}
