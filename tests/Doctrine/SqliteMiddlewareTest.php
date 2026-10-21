<?php

namespace App\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SqliteMiddlewareTest extends KernelTestCase
{
    public function testForeignKeysAndWalAreEnabled(): void
    {
        $connection = self::getContainer()->get(Connection::class);

        self::assertSame(1, (int) $connection->fetchOne('PRAGMA foreign_keys'));
        self::assertSame('wal', strtolower((string) $connection->fetchOne('PRAGMA journal_mode')));
    }
}
