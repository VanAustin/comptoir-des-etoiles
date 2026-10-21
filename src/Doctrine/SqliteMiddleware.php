<?php

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Réglages appliqués à chaque connexion SQLite :
 * - foreign_keys : SQLite ne vérifie pas les clés étrangères sans ce réglage ;
 * - journal_mode WAL : les lectures (écrans qui interrogent le serveur toutes
 *   les 2 à 3 secondes) ne bloquent pas l'enregistrement des commandes.
 */
#[AsMiddleware]
final class SqliteMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $connection = parent::connect($params);

                if (str_contains($params['driver'] ?? '', 'sqlite')) {
                    $connection->exec('PRAGMA foreign_keys = ON');
                    $connection->exec('PRAGMA journal_mode = WAL');
                }

                return $connection;
            }
        };
    }
}
