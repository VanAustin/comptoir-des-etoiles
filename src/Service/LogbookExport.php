<?php

namespace App\Service;

use App\Controller\Admin\LogbookController;
use App\Entity\Event;
use App\Enum\LineKind;
use App\Enum\PaymentMethod;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Exports CSV du Carnet de bord, lisibles directement par Excel en français :
 * UTF-8 avec BOM (accents), point-virgule, virgule décimale, fins de ligne Windows.
 *
 * Une cellule de texte qui commence par =, +, -, @ ou une tabulation est précédée
 * d'une apostrophe : un nom d'article ou une remarque ne peut pas devenir une formule.
 */
final class LogbookExport
{
    private const BOM = "\u{FEFF}";
    private const LINE_KINDS = ['article' => 'Article', 'consigne' => 'Consigne', 'retour_consigne' => 'Retour de consigne'];

    public function __construct(
        private readonly Logbook $logbook,
        #[Autowire('%app.timezone%')]
        private readonly string $timezone,
    ) {
    }

    /** Une ligne par ligne de commande, commandes annulées comprises (colonne État). */
    public function sales(Event $event): string
    {
        $timezone = new \DateTimeZone($this->timezone);
        $rows = [['Numéro', 'Caisse', 'Date', 'Heure', 'État', 'Paiement', 'Type de ligne', 'Article', 'Quantité', 'Prix unitaire (€)', 'Total ligne (€)', 'Total commande (€)', 'Reçu (€)', 'Rendu (€)', 'Écart de caisse (€)', 'Remarque']];

        foreach (array_reverse($this->logbook->summarize($event)['orders']) as $order) {
            $change = $order->getChangeCents();
            $createdAt = $order->getCreatedAt()->setTimezone($timezone);
            foreach ($order->getLines() as $line) {
                $rows[] = [
                    self::text($order->getDisplayNumber()),
                    self::text($order->getTillLetter()),
                    $createdAt->format('d/m/Y'),
                    $createdAt->format('H:i'),
                    LogbookController::STATUS_LABELS[$order->getStatus()->value],
                    PaymentMethod::Card === $order->getPaymentMethod() ? 'Carte' : 'Espèces',
                    self::LINE_KINDS[$line->getKind()->value],
                    self::text($line->getLabel()),
                    (string) $line->getQuantity(),
                    self::euros($line->getUnitPriceCents()),
                    self::euros($line->getTotalCents()),
                    self::euros($order->getTotalCents()),
                    self::euros($order->getAmountReceivedCents()),
                    null !== $change && $change > 0 ? self::euros($change) : '',
                    null !== $change && $change < 0 ? self::euros(-$change) : '',
                    self::text((string) $order->getNote()),
                ];
            }
        }

        return self::csv($rows);
    }

    /** Synthèse par article : de quoi ajuster les courses du prochain événement. */
    public function articles(Event $event): string
    {
        $rows = [['Article', 'Formule', 'Retiré du menu', 'Vendus', 'Chiffre d\'affaires (€)', 'Stock de départ', 'Stock restant']];
        foreach ($this->logbook->summarize($event)['articles'] as $article) {
            $rows[] = [
                self::text($article['name']),
                $article['formula'] ? 'oui' : 'non',
                $article['archived'] ? 'oui' : 'non',
                (string) $article['sold'],
                self::euros($article['revenue']),
                null === $article['initial'] ? '' : (string) $article['initial'],
                null === $article['remaining'] ? '' : (string) $article['remaining'],
            ];
        }

        return self::csv($rows);
    }

    /** « Loto de Noël » du 18/12/2026 → carnet-loto-de-noel-2026-12-18-ventes.csv */
    public static function filename(Event $event, string $kind): string
    {
        $name = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $event->getName())));

        return \sprintf('carnet-%s-%s-%s.csv', trim($name, '-') ?: 'evenement', $event->getDate()->format('Y-m-d'), $kind);
    }

    /** Montant en euros, virgule décimale, sans symbole : Excel le lit comme un nombre. */
    private static function euros(?int $cents): string
    {
        return null === $cents ? '' : Money::formatEuros($cents);
    }

    /** Texte saisi (noms, remarques) : neutralisé s'il pourrait être pris pour une formule. */
    private static function text(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    /** @param list<list<string>> $rows */
    private static function csv(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = implode(';', array_map(static fn (string $cell): string => preg_match('/[;"\r\n]/', $cell) ? '"'.str_replace('"', '""', $cell).'"' : $cell, $row));
        }

        return self::BOM.implode("\r\n", $lines)."\r\n";
    }
}
