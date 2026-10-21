<?php

namespace App\Command;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Repository\AdminUserRepository;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:install',
    description: 'Crée le premier administrateur et génère APP_SECRET dans .env.local',
)]
class InstallCommand extends Command
{
    public const MIN_PASSWORD_LENGTH = 12;
    private const MAX_PASSWORD_LENGTH = 4096;
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly AdminUserRepository $adminUsers,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire('%kernel.project_dir%/.env.local')]
        private readonly string $envLocalPath,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Installation du Comptoir des étoiles');

        try {
            $adminCount = $this->adminUsers->count();
        } catch (TableNotFoundException) {
            $io->error([
                'La base de données n\'est pas encore créée.',
                'Lancez d\'abord : php bin/console doctrine:migrations:migrate',
            ]);

            return Command::FAILURE;
        }

        if ($adminCount > 0) {
            $io->error([
                'Un administrateur existe déjà : l\'installation a déjà été faite.',
                'Rien n\'a été modifié.',
            ]);

            return Command::FAILURE;
        }

        if (!$input->isInteractive()) {
            $io->error('Cette commande demande un mot de passe : lancez-la dans un terminal, sans l\'option --no-interaction.');

            return Command::FAILURE;
        }

        $this->ensureAppSecret($io);

        $io->section('Création du compte administrateur');
        $io->text([
            'Aucun compte n\'existe encore : vous allez en créer un.',
            'Choisissez l\'identifiant et le mot de passe qui vous serviront à vous connecter à l\'administration.',
        ]);
        $username = $io->askQuestion($this->usernameQuestion());
        $password = $this->askNewPassword($io);
        if (null === $password) {
            $io->error('Les deux saisies du mot de passe ne correspondent pas. Relancez la commande.');

            return Command::FAILURE;
        }

        $admin = new AdminUser($username);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, $password));
        $this->entityManager->persist($admin);
        $this->entityManager->persist(new AuditLog('installation', 'admin.create', 'admin:'.$username));
        $this->entityManager->flush();

        $io->success(\sprintf('L\'administrateur « %s » est créé. Vous pouvez vous connecter à l\'administration.', $username));

        return Command::SUCCESS;
    }

    /**
     * Ajoute APP_SECRET à .env.local s'il n'y est pas déjà défini.
     * Un secret existant n'est jamais remplacé : cela déconnecterait tout le monde.
     */
    private function ensureAppSecret(SymfonyStyle $io): void
    {
        $content = is_file($this->envLocalPath) ? (string) file_get_contents($this->envLocalPath) : null;

        if (null !== $content && preg_match('/^[ \t]*APP_SECRET[ \t]*=[ \t]*["\']?[^\s"\']+/m', $content)) {
            $io->text('APP_SECRET est déjà défini dans .env.local : il est conservé.');

            return;
        }

        $line = 'APP_SECRET='.bin2hex(random_bytes(32)).\PHP_EOL;
        if (null === $content) {
            file_put_contents($this->envLocalPath, $line);
            chmod($this->envLocalPath, 0600);
        } else {
            $prefix = '' === $content || str_ends_with($content, "\n") ? '' : \PHP_EOL;
            file_put_contents($this->envLocalPath, $prefix.$line, \FILE_APPEND);
        }

        $io->text('APP_SECRET a été généré dans .env.local.');
    }

    private function usernameQuestion(): Question
    {
        $question = new Question('Choisissez un identifiant (lettres sans accent, chiffres, point, tiret ; par exemple : admin-ape)');
        $question->setMaxAttempts(self::MAX_ATTEMPTS);
        $question->setValidator(static function (?string $value): string {
            $value = trim((string) $value);
            if (!preg_match('/^[A-Za-z0-9._-]{3,180}$/', $value)) {
                throw new \RuntimeException('L\'identifiant doit faire au moins 3 caractères : lettres sans accent, chiffres, point ou tiret.');
            }

            return $value;
        });

        return $question;
    }

    private function askNewPassword(SymfonyStyle $io): ?string
    {
        $question = new Question(\sprintf('Choisissez un mot de passe (%d caractères minimum ; rien ne s\'affiche pendant la saisie, c\'est normal)', self::MIN_PASSWORD_LENGTH));
        $question->setHidden(true);
        $question->setMaxAttempts(self::MAX_ATTEMPTS);
        $question->setValidator(static function (?string $value): string {
            $length = mb_strlen((string) $value);
            if ($length < self::MIN_PASSWORD_LENGTH) {
                throw new \RuntimeException(\sprintf('Le mot de passe doit faire au moins %d caractères.', self::MIN_PASSWORD_LENGTH));
            }
            if ($length > self::MAX_PASSWORD_LENGTH) {
                throw new \RuntimeException('Le mot de passe est trop long.');
            }

            return (string) $value;
        });
        $password = $io->askQuestion($question);

        $confirmation = new Question('Saisissez à nouveau le même mot de passe');
        $confirmation->setHidden(true);

        return hash_equals($password, (string) $io->askQuestion($confirmation)) ? $password : null;
    }
}
