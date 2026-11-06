<?php

namespace App\Enum;

enum DeliveryStatus: string
{
    case PENDING = 'pending';
    /** WhatsApp n'est pas configuré : rien n'est réellement parti. */
    case SIMULATED = 'simulated';
    case SENT = 'sent';
    case DELIVERED = 'delivered';
    case READ = 'read';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En cours d\'envoi',
            self::SIMULATED => 'Simulé, non envoyé',
            self::SENT => 'Envoyé',
            self::DELIVERED => 'Distribué',
            self::READ => 'Lu',
            self::FAILED => 'Échec',
        };
    }

    /** Meta ne garantit pas l'ordre des statuts : « distribué » peut arriver après « lu ». */
    public function rank(): int
    {
        return match ($this) {
            self::PENDING, self::SIMULATED => 0,
            self::SENT => 1,
            self::DELIVERED => 2,
            self::READ => 3,
            self::FAILED => 4,
        };
    }
}
