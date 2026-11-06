<?php

namespace App\Service;

use App\Entity\Organization;

/**
 * Tous les textes automatiques, avec leur version d'origine. Une organisation peut en remplacer
 * chacun ; seul ce qui diffère de l'origine est enregistré, pour profiter des futures corrections.
 */
final class MessageCatalog
{
    public const VOICE_ECHO = 'whatsapp_voice_echo';
    public const CHOICE_PROMPT = 'whatsapp_choice_prompt';
    public const CLOSED = 'whatsapp_closed';
    public const CONTINUE = 'whatsapp_continue';
    public const TRACKING_LINK = 'email_tracking_link';
    public const REPLY = 'email_reply';
    public const FINAL_REPLY = 'email_final_reply';
    public const TEAM = 'email_team';

    private const WHATSAPP_LIMIT = 1000;
    private const EMAIL_LIMIT = 3000;

    private const DEFINITIONS = [
        self::VOICE_ECHO => [
            'group' => 'Sur WhatsApp, au client',
            'title' => 'Transcription d\'un vocal',
            'when' => 'Après chaque message vocal transcrit.',
            'required' => ['{transcription}'],
            'variables' => ['{transcription}', '{organisation}'],
            'limit' => self::WHATSAPP_LIMIT,
            'default' => "🎤 Voici ce que nous avons compris de votre message vocal :\n\n« {transcription} »",
        ],
        self::CHOICE_PROMPT => [
            'group' => 'Sur WhatsApp, au client',
            'title' => 'Question de la dernière réponse',
            'when' => 'Avec les boutons Clôturer et Continuer, si votre réponse dépasse 1024 caractères. Sinon, les boutons sont sous votre réponse.',
            'required' => [],
            'variables' => ['{organisation}'],
            'limit' => self::WHATSAPP_LIMIT,
            'default' => 'Votre demande est-elle traitée ?',
        ],
        self::CLOSED => [
            'group' => 'Sur WhatsApp, au client',
            'title' => 'Confirmation de clôture',
            'when' => 'Quand le client touche Clôturer.',
            'required' => [],
            'variables' => ['{organisation}'],
            'limit' => self::WHATSAPP_LIMIT,
            'default' => 'Votre demande est clôturée. Écrivez-nous à tout moment si vous avez besoin de la rouvrir.',
        ],
        self::CONTINUE => [
            'group' => 'Sur WhatsApp, au client',
            'title' => 'Suite de la conversation',
            'when' => 'Quand le client touche Continuer.',
            'required' => [],
            'variables' => ['{organisation}'],
            'limit' => self::WHATSAPP_LIMIT,
            'default' => 'Très bien, écrivez votre message ici.',
        ],
        self::TRACKING_LINK => [
            'group' => 'Par courriel, au client',
            'title' => 'Lien de suivi',
            'when' => 'Après l\'envoi du formulaire.',
            'required' => ['{lien}'],
            'variables' => ['{lien}', '{client}', '{objet}', '{organisation}'],
            'limit' => self::EMAIL_LIMIT,
            'default' => "Bonjour {client},\n\nVotre demande « {objet} » est bien reçue par {organisation}.\n\nSuivez notre échange et répondez à tout moment depuis ce lien privé :\n{lien}\n\nNe transférez pas ce courriel : toute personne qui détient ce lien peut lire la conversation.",
        ],
        self::REPLY => [
            'group' => 'Par courriel, au client',
            'title' => 'Réponse',
            'when' => 'À chaque réponse sur une demande du formulaire.',
            'required' => ['{message}', '{lien}'],
            'variables' => ['{message}', '{lien}', '{client}', '{objet}', '{organisation}'],
            'limit' => self::EMAIL_LIMIT,
            'default' => "Bonjour {client},\n\n{organisation} a répondu à votre demande « {objet} » :\n\n{message}\n\nPour répondre, utilisez votre lien privé (ne répondez pas à ce courriel) :\n{lien}",
        ],
        self::FINAL_REPLY => [
            'group' => 'Par courriel, au client',
            'title' => 'Dernière réponse',
            'when' => 'Quand vous cliquez sur Dernière réponse.',
            'required' => ['{message}', '{lien}'],
            'variables' => ['{message}', '{lien}', '{client}', '{objet}', '{organisation}'],
            'limit' => self::EMAIL_LIMIT,
            'default' => "Bonjour {client},\n\n{organisation} a répondu à votre demande « {objet} » :\n\n{message}\n\nVotre demande est-elle traitée ? Depuis votre lien privé, vous pouvez la clôturer ou continuer la conversation (ne répondez pas à ce courriel) :\n{lien}",
        ],
        self::TEAM => [
            'group' => 'Par courriel, à l\'équipe',
            'title' => 'Nouveau message',
            'when' => 'À chaque message d\'un client, sauf la phrase d\'ouverture WhatsApp.',
            'required' => ['{message}', '{lien}'],
            'variables' => ['{message}', '{lien}', '{client}', '{canal}', '{organisation}'],
            'limit' => self::EMAIL_LIMIT,
            'default' => "{organisation} : nouveau message de {client} ({canal}) :\n\n{message}\n\nRépondre : {lien}",
        ],
    ];

    /** Pour afficher un texte sans vraie conversation. */
    private const SAMPLE = [
        '{transcription}' => 'Où en est ma commande ?',
        '{client}' => 'Camille Martin',
        '{objet}' => 'Objet de la demande',
        '{message}' => '[le message]',
        '{lien}' => '[lien]',
        '{canal}' => 'WhatsApp',
    ];

    public function exists(string $key): bool
    {
        return isset(self::DEFINITIONS[$key]);
    }

    public function defaultText(string $key): string
    {
        return self::DEFINITIONS[$key]['default'];
    }

    public function text(Organization $organization, string $key): string
    {
        return $organization->getMessageTemplate($key) ?? $this->defaultText($key);
    }

    /**
     * strtr remplace en une seule passe : un client qui écrirait « {lien} » n'injecte rien.
     *
     * @param array<string, string> $variables
     */
    public function render(Organization $organization, string $key, array $variables = []): string
    {
        $replacements = ['{organisation}' => $organization->getName()];

        foreach ($variables as $name => $value) {
            $replacements['{'.$name.'}'] = $value;
        }

        return strtr($this->text($organization, $key), $replacements);
    }

    /** @return string|null le problème à corriger, null si le texte convient */
    public function validate(string $key, string $text): ?string
    {
        $definition = self::DEFINITIONS[$key];

        if ('' === trim($text)) {
            return 'Le message ne peut pas être vide. Utilisez « Rétablir » pour revenir au texte d\'origine.';
        }

        if (mb_strlen($text) > $definition['limit']) {
            return \sprintf('Le message dépasse %d caractères.', $definition['limit']);
        }

        foreach ($definition['required'] as $variable) {
            if (!str_contains($text, $variable)) {
                return \sprintf('Gardez %s dans ce message : sans cela, il perd son utilité.', $variable);
            }
        }

        return null;
    }

    /** @return array<string, array<int, array<string, mixed>>> les messages de l'organisation, par groupe */
    public function all(Organization $organization): array
    {
        $groups = [];

        foreach (self::DEFINITIONS as $key => $definition) {
            $text = $this->text($organization, $key);

            $groups[$definition['group']][] = [
                'key' => $key,
                'title' => $definition['title'],
                'when' => $definition['when'],
                'variables' => $definition['variables'],
                'text' => $text,
                'preview' => strtr($text, self::SAMPLE + ['{organisation}' => $organization->getName()]),
                'custom' => null !== $organization->getMessageTemplate($key),
            ];
        }

        return $groups;
    }
}
