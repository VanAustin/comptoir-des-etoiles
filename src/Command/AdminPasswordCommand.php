<?php

namespace App\Command;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Repository\AdminUserRepository;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Remplace le mot de passe d'un administrateur, depuis le serveur : seul recours
 * quand il est oublié, puisque l'administration n'a pas de « mot de passe oublié ».
 */
#[AsCommand(
    name: 'app:admin:password',
    description: 'Remplace le mot de passe d\'un administrateur (mot de passe oublié)',
)]
class AdminPasswordCommand extends Command
{
    public function __construct(
        private readonly AdminUserRepository $adminUsers,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('identifiant', InputArgument::OPTIONAL, 'Identifiant de l\'administrateur (facultatif s\'il n\'y en a qu\'un)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $admins = $this->adminUsers->findBy([], ['username' => 'ASC']);
        } catch (TableNotFoundException) {
            $io->error('La base de données n\'est pas encore créée. Lancez d\'abord : php bin/console doctrine:migrations:migrate');

            return Command::FAILURE;
        }

        if ([] === $admins) {
            $io->error('Aucun administrateur n\'existe encore. Créez-le avec : php bin/console app:install');

            return Command::FAILURE;
        }

        $username = $input->getArgument('identifiant');
        $admin = $this->findAdmin($admins, $username);
        if (null === $admin) {
            $io->error([
                (null === $username ? 'Il y a plusieurs administrateurs : précisez lequel.' : \sprintf('Aucun administrateur ne s\'appelle « %s ».', $username))
                    .' Identifiants existants : '.implode(', ', array_map(static fn (AdminUser $a): string => $a->getUsername(), $admins)).'.',
                'Relancez la commande avec l\'un d\'eux, par exemple : php bin/console app:admin:password '.$admins[0]->getUsername(),
            ]);

            return Command::FAILURE;
        }

        if (!$input->isInteractive()) {
            $io->error('Cette commande demande un mot de passe : lancez-la dans un terminal, sans l\'option --no-interaction.');

            return Command::FAILURE;
        }

        $io->text(\sprintf('Nouveau mot de passe pour l\'administrateur « %s ».', $admin->getUsername()));
        $password = NewPasswordPrompt::ask($io);
        if (null === $password) {
            $io->error('Les deux saisies du mot de passe ne correspondent pas. Le mot de passe n\'a pas changé : relancez la commande.');

            return Command::FAILURE;
        }

        $admin->setPassword($this->passwordHasher->hashPassword($admin, $password));
        $this->entityManager->persist(new AuditLog('console', 'admin.password', 'admin:'.$admin->getUsername()));
        $this->entityManager->flush();

        $io->success([
            \sprintf('Le mot de passe de « %s » est remplacé.', $admin->getUsername()),
            'Les sessions ouvertes avec l\'ancien mot de passe sont fermées : reconnectez-vous à l\'administration.',
        ]);

        return Command::SUCCESS;
    }

    /** @param list<AdminUser> $admins */
    private function findAdmin(array $admins, ?string $username): ?AdminUser
    {
        if (null === $username) {
            // Un seul administrateur : pas besoin de se souvenir de son identifiant
            return 1 === \count($admins) ? $admins[0] : null;
        }

        foreach ($admins as $admin) {
            if ($admin->getUsername() === $username) {
                return $admin;
            }
        }

        return null;
    }
}
