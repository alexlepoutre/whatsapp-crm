<?php

namespace App\Enum;

enum MessageSender: string
{
    case CLIENT = 'client';
    case OWNER = 'owner';
    case BOT = 'bot';
}
