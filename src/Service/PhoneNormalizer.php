<?php

namespace App\Service;

/**
 * Ramène un numéro au format de Meta (chiffres seuls, indicatif compris) : c'est
 * ce qui permet de reconnaître un même client entre WhatsApp et le formulaire.
 */
final class PhoneNormalizer
{
    public static function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ('' === $digits) {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }

        // Numéro français saisi sans indicatif : 06 12 34 56 78.
        if (10 === \strlen($digits) && str_starts_with($digits, '0')) {
            return '33'.substr($digits, 1);
        }

        return $digits;
    }
}
