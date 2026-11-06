<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffre les secrets stockés en base. Un mot de passe SMTP doit rester réutilisable, il ne
 * peut donc pas être haché. La clé dérive d'APP_SECRET, qui n'est pas en base : une copie de
 * celle-ci ne suffit pas. Changer APP_SECRET impose de ressaisir ces mots de passe.
 */
class SecretBox
{
    private readonly string $key;

    public function __construct(#[Autowire('%kernel.secret%')] string $secret)
    {
        $this->key = sodium_crypto_generichash($secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    /** @return string|null null si le contenu est illisible, par exemple après un changement de clé */
    public function decrypt(string $encrypted): ?string
    {
        $raw = base64_decode($encrypted, true);

        if (false === $raw || \strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );

        return false === $plain ? null : $plain;
    }
}
