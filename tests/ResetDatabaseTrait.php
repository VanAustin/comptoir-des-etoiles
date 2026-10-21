<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Recrée un schéma vide dans la base de test (var/data_test.db) avant chaque test.
 */
trait ResetDatabaseTrait
{
    protected static function resetDatabase(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        // Clés étrangères suspendues le temps de tout supprimer, quel que soit l'ordre des tables
        $connection = $entityManager->getConnection();
        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($metadata);
        $connection->executeStatement('PRAGMA foreign_keys = ON');

        return $entityManager;
    }
}
