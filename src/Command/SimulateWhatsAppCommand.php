<?php

namespace App\Command;

use App\WhatsApp\WebhookProcessor;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Tant que l'application n'est pas en ligne, Meta ne peut pas joindre le webhook :
 * cette commande fait entrer le même contenu par le même chemin.
 */
#[AsCommand('app:whatsapp:simulate', 'Simule la réception d\'un message WhatsApp')]
class SimulateWhatsAppCommand
{
    public function __construct(private readonly WebhookProcessor $processor)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Numéro de l\'expéditeur, indicatif compris')] string $phone,
        #[Argument('Texte du message')] string $text,
        #[Option('Nom du profil WhatsApp')] string $name = 'Client de test',
    ): int {
        $this->processor->process([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'contacts' => [['wa_id' => $phone, 'profile' => ['name' => $name]]],
                        'messages' => [[
                            'from' => $phone,
                            'id' => 'wamid.simulation.'.bin2hex(random_bytes(8)),
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ]);

        $io->success('Message reçu.');

        return Command::SUCCESS;
    }
}
