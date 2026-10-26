-- Développement uniquement : exécuté par l'image mysql à la première création du volume.
-- Base des tests (bin/phpunit), à côté de la base de développement créée par MYSQL_DATABASE.
CREATE DATABASE IF NOT EXISTS comptoir_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON comptoir_test.* TO 'comptoir'@'%';
