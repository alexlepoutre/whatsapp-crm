<?php

namespace App\Command;

use App\Entity\Organization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand('app:organization:create', 'Crée une organisation et sa page publique')]
class CreateOrganizationCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Adresse de la page, par exemple itroom')] string $slug,
        #[Argument('Nom affiché, par exemple IT-Room')] string $name,
        #[Option('Adresse prévenue des nouveaux messages')] ?string $notify = null,
    ): int {
        $organization = (new Organization($slug, $name))->setNotificationEmail($notify);

        $errors = $this->validator->validate($organization);

        if (\count($errors) > 0) {
            $io->error((string) $errors);

            return Command::FAILURE;
        }

        $this->entityManager->persist($organization);
        $this->entityManager->flush();

        $io->success(\sprintf('Organisation %s créée : /%s', $name, $slug));

        return Command::SUCCESS;
    }
}
