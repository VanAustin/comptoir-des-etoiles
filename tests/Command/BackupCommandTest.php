<?php

namespace App\Tests\Command;

use App\Command\BackupCommand;
use App\Tests\FixturesTrait;
use App\Tests\ResetDatabaseTrait;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class BackupCommandTest extends KernelTestCase
{
    use FixturesTrait;
    use ResetDatabaseTrait;

    private string $directory;

    protected function setUp(): void
    {
        self::bootKernel();
        self::resetDatabase();
        $this->directory = sys_get_temp_dir().'/backups-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function testBackupRestoresTheDatabase(): void
    {
        self::createEvent('Loto d\'hiver « Noël »');
        $connection = self::getContainer()->get(Connection::class);

        $tester = new CommandTester(new BackupCommand($connection, $this->directory));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $files = glob($this->directory.'/comptoir-*.sql');
        self::assertCount(1, $files);
        self::assertSame(0o600, fileperms($files[0]) & 0o777, 'lisible par le seul propriétaire');

        // Restauration comme dans phpMyAdmin : les tables sont recréées avec leurs données
        $connection->executeStatement('UPDATE event SET name = \'Modifié après la sauvegarde\'');
        $connection->getNativeConnection()->exec((string) file_get_contents($files[0]));

        self::assertSame('Loto d\'hiver « Noël »', $connection->fetchOne('SELECT name FROM event'));
    }
}
