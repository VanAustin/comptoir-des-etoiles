<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Vide toutes les tables de la base de test (comptoir_test) avant chaque test.
 * Le schéma est créé une seule fois, au lancement des tests (tests/bootstrap.php).
 */
trait ResetDatabaseTrait
{
    protected static function resetDatabase(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();

        // Clés étrangères suspendues le temps de tout vider, quel que soit l'ordre des tables
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($connection->createSchemaManager()->introspectTableNames() as $table) {
            $connection->executeStatement('TRUNCATE TABLE '.$table->toSQL($connection->getDatabasePlatform()));
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        return $entityManager;
    }
}
