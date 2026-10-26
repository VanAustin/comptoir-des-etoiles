<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sauvegarde en PHP, sans mysqldump (souvent absent des hébergements mutualisés) :
 * un fichier SQL (structure et données), importable avec phpMyAdmin.
 */
#[AsCommand(
    name: 'app:backup',
    description: 'Sauvegarde la base de données, horodatée, dans var/backups/',
)]
class BackupCommand extends Command
{
    private const ROWS_PER_INSERT = 100;

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

        $path = \sprintf('%s/comptoir-%s.sql', $this->backupDirectory, (new \DateTimeImmutable())->format('Y-m-d_His'));
        if (file_exists($path)) {
            $io->error('Une sauvegarde vient d\'être faite à la même seconde : relancez la commande dans un instant.');

            return Command::FAILURE;
        }

        $file = fopen($path, 'x');
        chmod($path, 0o600);

        // Instantané cohérent de toutes les tables, même pendant que les tablettes écrivent
        $this->connection->executeStatement('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            fwrite($file, "-- Sauvegarde du Comptoir des étoiles\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n");
            foreach ($this->connection->createSchemaManager()->introspectTableNames() as $tableName) {
                $this->dumpTable($file, $tableName->toSQL($this->connection->getDatabasePlatform()));
            }
            fwrite($file, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
        } finally {
            $this->connection->executeStatement('COMMIT');
            fclose($file);
        }

        $io->success(\sprintf('Base sauvegardée dans %s (%d Ko).', $path, (int) ceil(filesize($path) / 1024)));

        return Command::SUCCESS;
    }

    /** @param resource $file */
    private function dumpTable($file, string $table): void
    {
        $create = $this->connection->fetchNumeric('SHOW CREATE TABLE '.$table)[1];
        fwrite($file, \sprintf("\nDROP TABLE IF EXISTS %s;\n%s;\n", $table, $create));

        $values = [];
        foreach ($this->connection->iterateNumeric('SELECT * FROM '.$table) as $row) {
            $values[] = '('.implode(', ', array_map($this->quote(...), $row)).')';
            if (\count($values) === self::ROWS_PER_INSERT) {
                fwrite($file, \sprintf("INSERT INTO %s VALUES\n%s;\n", $table, implode(",\n", $values)));
                $values = [];
            }
        }
        if ([] !== $values) {
            fwrite($file, \sprintf("INSERT INTO %s VALUES\n%s;\n", $table, implode(",\n", $values)));
        }
    }

    private function quote(mixed $value): string
    {
        return match (true) {
            null === $value => 'NULL',
            \is_int($value) => (string) $value,
            default => $this->connection->quote((string) $value),
        };
    }
}
