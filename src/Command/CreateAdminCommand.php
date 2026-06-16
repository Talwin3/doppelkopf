<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Legt einen neuen Admin-Benutzer an.',
)]
class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = $io->ask('Benutzername (max. 30 Zeichen)');
        $email    = $io->ask('E-Mail-Adresse');
        $password = $io->askHidden('Passwort');

        if (!$username || !$email || !$password) {
            $io->error('Alle Felder sind Pflicht.');
            return Command::FAILURE;
        }

        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $user->setRoles(['ROLE_ADMIN']);
        $user->setIsVerified(true);

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Admin "%s" wurde erfolgreich angelegt.', $username));

        return Command::SUCCESS;
    }
}
