<?php

namespace App\Tests\Command;

use App\Command\BackupCommand;
use App\Tests\FixturesTrait;
use App\Tests\ResetDatabaseTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
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

    public function testBackupIsAReadableCopyOfTheDatabase(): void
    {
        self::createEvent('Loto de Noël');

        $tester = new CommandTester(new BackupCommand(self::getContainer()->get(Connection::class), $this->directory));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $files = glob($this->directory.'/comptoir-*.db');
        self::assertCount(1, $files);
        self::assertSame(0o600, fileperms($files[0]) & 0o777, 'lisible par le seul propriétaire');

        $copy = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $files[0]]);
        self::assertSame('Loto de Noël', $copy->fetchOne('SELECT name FROM event'));
        $copy->close();
    }
}
