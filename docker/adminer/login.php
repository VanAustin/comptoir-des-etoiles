<?php

// Outil de développement uniquement (service adminer de compose.yaml).

// http://localhost:8082 ouvre directement la base de développement : SQLite et chemin préremplis.
if (($_SERVER['REQUEST_URI'] ?? '') === '/' && 'GET' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
    header('Location: /?sqlite=&username=&db=' . rawurlencode('/var/db/data_dev.db'));
    exit;
}

// Adminer refuse d'ouvrir une base SQLite sans mot de passe : celui-ci est fixé
// par ADMINER_PASSWORD dans compose.yaml.
require_once 'plugins/login-password-less.php';

return new AdminerLoginPasswordLess(password_hash(getenv('ADMINER_PASSWORD') ?: '', PASSWORD_DEFAULT));
