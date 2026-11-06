<?php

namespace App\Command;

use App\Repository\OrganizationRepository;
use App\WhatsApp\WebhookProcessor;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fait entrer un message par le même chemin que le relais du hook, sans passer par Meta.
 */
#[AsCommand('app:whatsapp:simulate', 'Simule la réception d\'un message WhatsApp')]
class SimulateWhatsAppCommand
{
    public function __construct(
        private readonly WebhookProcessor $processor,
        private readonly OrganizationRepository $organizations,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Organisation destinataire, par son adresse')] string $organization,
        #[Argument('Numéro de l\'expéditeur, indicatif compris')] string $phone,
        #[Argument('Texte du message')] string $text,
        #[Option('Nom du profil WhatsApp')] string $name = 'Client de test',
        #[Option('Simule un vocal dont le texte est la transcription')] bool $voice = false,
    ): int {
        $target = $this->organizations->findBySlug($organization);

        if (null === $target) {
            $io->error(\sprintf('Organisation inconnue : %s', $organization));

            return Command::FAILURE;
        }

        $this->processor->process($target, [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'contacts' => [['wa_id' => $phone, 'profile' => ['name' => $name]]],
                        'messages' => [[
                            'from' => $phone,
                            'id' => 'wamid.simulation.'.bin2hex(random_bytes(8)),
                        ] + ($voice
                            ? ['type' => 'audio', 'audio' => ['id' => 'simulation'], 'transcription' => $text]
                            : ['type' => 'text', 'text' => ['body' => $text]])],
                    ],
                ]],
            ]],
        ]);

        $io->success('Message reçu.');

        return Command::SUCCESS;
    }
}
