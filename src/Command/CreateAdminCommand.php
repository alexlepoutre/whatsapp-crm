<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand('app:admin:create', 'Crée un compte d\'accès au back-office ou change son mot de passe')]
class CreateAdminCommand
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Adresse e-mail')] string $email,
        #[Argument('Mot de passe')] string $password,
    ): int {
        $user = $this->users->findOneBy(['email' => mb_strtolower($email)]) ?? new User($email);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('Compte %s prêt.', $user->getEmail()));

        return Command::SUCCESS;
    }
}
