<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022093545 extends AbstractMigration
{
    /** Émojis du menu d'exemple → icônes du catalogue ; les autres émojis sont effacés. */
    private const EMOJI_TO_ICON = [
        '🥪' => 'sandwich', '🌭' => 'sandwich', '🍕' => 'pizza', '🥞' => 'dessert', '🍫' => 'dessert',
        '🍰' => 'cake-slice', '🥤' => 'cup-soda', '🧃' => 'citrus', '💧' => 'glass-water',
        '☕' => 'coffee', '♨️' => 'coffee', '⭐' => 'star',
    ];

    public function getDescription(): string
    {
        return 'Quantité des articles dans une formule ; icônes Lucide à la place des émojis';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_component ADD COLUMN quantity INTEGER DEFAULT 1 NOT NULL');

        foreach (self::EMOJI_TO_ICON as $emoji => $icon) {
            $this->addSql('UPDATE product SET icon = ? WHERE icon = ?', [$icon, $emoji]);
        }
        $this->addSql('UPDATE product SET icon = NULL WHERE icon NOT IN (?)', [array_unique(array_values(self::EMOJI_TO_ICON))], [ArrayParameterType::STRING]);
    }

    public function down(Schema $schema): void
    {
        // Suppression de colonne plutôt que recréation de la table (voir CLAUDE.md).
        // Les icônes ne redeviennent pas des émojis : l'article affichera l'icône par défaut.
        $this->addSql('ALTER TABLE product_component DROP COLUMN quantity');
    }
}
