<?php

namespace App\Enum;

enum Channel: string
{
    case WHATSAPP = 'whatsapp';
    case FORM = 'form';

    public function label(): string
    {
        return match ($this) {
            self::WHATSAPP => 'WhatsApp',
            self::FORM => 'Formulaire',
        };
    }
}
