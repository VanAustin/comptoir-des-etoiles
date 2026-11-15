-- Développement uniquement : exécuté par l'image mysql à la première création du volume.
-- Base des tests (bin/phpunit), à côté de la base de développement créée par MYSQL_DATABASE.
CREATE DATABASE IF NOT EXISTS comptoir_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON comptoir_test.* TO 'comptoir'@'%';

-- Base des essais Bruno (bin/bruno), servie sur http://localhost:8081 : la base de développement
-- n'est jamais touchée par les essais. bin/bruno refait ces deux lignes sur un volume déjà créé.
CREATE DATABASE IF NOT EXISTS comptoir_bruno CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON comptoir_bruno.* TO 'comptoir'@'%';
