<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Schéma de la base de test recréé une fois par lancement : chaque test ne fait ensuite que vider les tables
passthru(sprintf('php %s/bin/console doctrine:schema:drop --env=test --force --full-database --quiet', dirname(__DIR__)), $dropStatus);
passthru(sprintf('php %s/bin/console doctrine:schema:create --env=test --quiet', dirname(__DIR__)), $createStatus);
if (0 !== $dropStatus || 0 !== $createStatus) {
    exit('Impossible de préparer la base de test comptoir_test : le service database est-il démarré ?'.\PHP_EOL);
}
