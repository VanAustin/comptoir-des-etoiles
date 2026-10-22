<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:backup',
    description: 'Copie la base de données, horodatée, dans var/backups/',
)]
class BackupCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%/var/backups')]
        private readonly string $backupDirectory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!is_dir($this->backupDirectory) && !mkdir($this->backupDirectory, 0o700, true) && !is_dir($this->backupDirectory)) {
            $io->error(\sprintf('Impossible de créer le dossier %s : vérifiez les droits d\'écriture.', $this->backupDirectory));

            return Command::FAILURE;
        }

        $path = \sprintf('%s/comptoir-%s.db', $this->backupDirectory, (new \DateTimeImmutable())->format('Y-m-d_His'));
        if (file_exists($path)) {
            $io->error('Une sauvegarde vient d\'être faite à la même seconde : relancez la commande dans un instant.');

            return Command::FAILURE;
        }

        // VACUUM INTO : copie cohérente même pendant que les tablettes écrivent (mode WAL),
        // contrairement à une simple copie du fichier
        $this->connection->executeStatement('VACUUM INTO '.$this->connection->quote($path));
        chmod($path, 0o600);

        $io->success(\sprintf('Base sauvegardée dans %s (%d Ko).', $path, (int) ceil(filesize($path) / 1024)));

        return Command::SUCCESS;
    }
}
