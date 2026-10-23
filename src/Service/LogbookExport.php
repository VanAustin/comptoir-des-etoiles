<?php

namespace App\Service;

use App\Entity\Event;
use App\Entity\Order;
use App\Enum\LineKind;
use App\Enum\OrderStatus;
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

    /**
     * Journal des commandes : une ligne par commande non annulée. Chaque colonne de montant
     * se somme telle quelle ; le chiffre d'affaires (articles) est séparé des consignes.
     * Pour chaque ligne : chiffre d'affaires + consignes encaissées − consignes rendues = total encaissé.
     */
    public function orders(Event $event): string
    {
        $rows = [['Numéro', 'Caisse', 'Date', 'Heure', 'Paiement', 'Articles', 'Chiffre d\'affaires (€)', 'Consignes encaissées (€)', 'Consignes rendues (€)', 'Total encaissé (€)', 'Reçu en espèces (€)', 'Rendu (€)', 'Écart de caisse (€)', 'Remarque']];

        foreach ($this->activeOrders($event) as $order) {
            $revenue = $depositsIn = $depositsOut = 0;
            $articles = [];
            foreach ($order->getLines() as $line) {
                match ($line->getKind()) {
                    LineKind::Article => $revenue += $line->getTotalCents(),
                    LineKind::Deposit => $depositsIn += $line->getTotalCents(),
                    LineKind::DepositReturn => $depositsOut -= $line->getTotalCents(),
                };
                if (LineKind::Article === $line->getKind()) {
                    $articles[] = $line->getQuantity().' × '.$line->getLabel();
                }
            }
            $change = $order->getChangeCents();
            $rows[] = [
                ...$this->orderColumns($order),
                self::text(implode(', ', $articles)),
                self::euros($revenue),
                self::euros($depositsIn),
                self::euros($depositsOut),
                self::euros($order->getTotalCents()),
                self::euros($order->getAmountReceivedCents()),
                null !== $change && $change > 0 ? self::euros($change) : '',
                null !== $change && $change < 0 ? self::euros(-$change) : '',
                self::text((string) $order->getNote()),
            ];
        }

        return self::csv($rows);
    }

    /**
     * Détail des lignes : une ligne par article, consigne ou retour de consigne des commandes
     * non annulées. Aucun montant de commande répété : la somme de « Total ligne » égale le
     * total encaissé.
     */
    public function lines(Event $event): string
    {
        $rows = [['Numéro', 'Caisse', 'Date', 'Heure', 'Paiement', 'Type de ligne', 'Article', 'Quantité', 'Prix unitaire (€)', 'Total ligne (€)']];

        foreach ($this->activeOrders($event) as $order) {
            foreach ($order->getLines() as $line) {
                $rows[] = [
                    ...$this->orderColumns($order),
                    self::LINE_KINDS[$line->getKind()->value],
                    self::text($line->getLabel()),
                    (string) $line->getQuantity(),
                    self::euros($line->getUnitPriceCents()),
                    self::euros($line->getTotalCents()),
                ];
            }
        }

        return self::csv($rows);
    }

    /**
     * Commandes non annulées, de la plus ancienne à la plus récente. Une commande annulée
     * fausserait les sommes : elle reste consultable dans le Carnet de bord et le journal.
     *
     * @return list<Order>
     */
    private function activeOrders(Event $event): array
    {
        $orders = array_filter($this->logbook->summarize($event)['orders'], static fn (Order $o): bool => OrderStatus::Cancelled !== $o->getStatus());

        return array_reverse(array_values($orders));
    }

    /** @return list<string> numéro, caisse, date, heure (heure locale de l'événement), paiement */
    private function orderColumns(Order $order): array
    {
        $createdAt = $order->getCreatedAt()->setTimezone(new \DateTimeZone($this->timezone));

        return [
            self::text($order->getDisplayNumber()),
            self::text($order->getTillLetter()),
            $createdAt->format('d/m/Y'),
            $createdAt->format('H:i'),
            PaymentMethod::Card === $order->getPaymentMethod() ? 'Carte' : 'Espèces',
        ];
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

    /** « Loto de Noël » du 18/12/2026 → carnet-loto-de-noel-2026-12-18-commandes.csv */
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
