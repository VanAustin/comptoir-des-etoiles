<?php

namespace App\Command;

use App\Entity\Device;
use App\Entity\Event;
use App\Entity\Order;
use App\Entity\Product;
use App\Enum\DeviceRole;
use App\Enum\LineKind;
use App\Enum\OrderStatus;
use App\Service\OrderPlacement;
use App\Service\OrderRejectedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Développement uniquement : importe des ventes reconstituées (bons papier…)
 * pour les voir dans le Carnet de bord. Chaque commande passe par le même code
 * que l'API (OrderPlacement) : totaux recalculés, composants figés, contrôles.
 */
#[AsCommand(
    name: 'app:dev:import-sales',
    description: 'Développement : importe des ventes reconstituées (format comptoir-import-ventes)',
)]
class DevImportSalesCommand extends Command
{
    public const DEVICE_NAME = 'Import bons papier';
    public const TILL_LETTER = 'A';
    public const NOTE_PREFIX = 'Bon papier : ';
    /** Premier horodatage, heure de l'association ; puis 2 minutes par commande, dans l'ordre des numéros. */
    public const FIRST_ORDER_TIME = '18:30';
    private const MINUTES_BETWEEN_ORDERS = 2;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderPlacement $placement,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%app.timezone%')]
        private readonly string $timezone,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Fichier JSON à importer, par exemple var/import/ventes.json')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'Supprime d\'abord l\'événement du même nom et de la même date, avec ses commandes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('dev' !== $this->environment) {
            $io->error(\sprintf('Commande réservée au développement (APP_ENV=dev), ici APP_ENV=%s : rien n\'est importé.', $this->environment));

            return Command::FAILURE;
        }

        try {
            $file = SalesImportFile::read((string) $input->getArgument('file'));
        } catch (\InvalidArgumentException $e) {
            $io->error('Fichier refusé : '.$e->getMessage());

            return Command::FAILURE;
        }
        foreach ($file->orders as $i => $order) {
            if (self::TILL_LETTER !== $order['letter']) {
                $io->error(\sprintf('orders[%d] : seule la lettre %s est importée (une seule caisse fictive).', $i, self::TILL_LETTER));

                return Command::FAILURE;
            }
        }

        $existing = $this->entityManager->getRepository(Event::class)->findBy(['name' => $file->event['name'], 'date' => $file->event['date']]);
        if ($existing && !$input->getOption('replace')) {
            $io->error(\sprintf('L\'événement « %s » du %s existe déjà. Relancez avec --replace pour le remplacer (ses commandes seront supprimées).', $file->event['name'], $file->event['date']->format('d/m/Y')));

            return Command::FAILURE;
        }

        try {
            $gaps = $this->entityManager->wrapInTransaction(function () use ($file, $existing): array {
                foreach ($existing as $event) {
                    // Articles, tablettes, commandes et lignes suivent, supprimés par la base (ON DELETE CASCADE)
                    $this->entityManager->remove($event);
                }
                $this->entityManager->flush();
                // Plus rien en mémoire de l'ancien événement, supprimé par la base
                $this->entityManager->clear();

                $event = $this->import($file);
                $gaps = $this->compare($event, $file);
                if ($gaps) {
                    // Annule la transaction : rien n'est créé
                    throw new ImportMismatchException($gaps);
                }

                return $gaps;
            });
        } catch (ImportMismatchException $e) {
            $io->error('Le résultat ne correspond pas au bloc « expected » : rien n\'est importé.');
            $io->listing($e->gaps);

            return Command::FAILURE;
        } catch (OrderRejectedException $e) {
            $io->error('Une commande est refusée, rien n\'est importé : '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            'OK : %d commandes, %d articles, %s €, détail par article conforme. Événement « %s » créé, inactif : choisissez-le dans le Carnet de bord.',
            $file->expected['orders'],
            $file->expected['items'],
            number_format($file->expected['totalCents'] / 100, 2, ',', ' '),
            $file->event['name'],
        ));
        if ($file->assumptions) {
            $io->section('Hypothèses du fichier');
            $io->listing($file->assumptions);
        }
        if ($file->skipped) {
            $io->section('Non importé');
            $io->listing($file->skipped);
        }

        return Command::SUCCESS;
    }

    private function import(SalesImportFile $file): Event
    {
        $data = $file->event;
        $event = (new Event($data['name'], $data['date']))
            ->setWithKitchen($data['withKitchen'])
            ->setDepositCents($data['depositCents']);
        $this->entityManager->persist($event);

        /** @var array<string, Product> $products */
        $products = [];
        foreach ($file->products as $position => $p) {
            $products[$p['key']] = (new Product($event, $p['name']))
                ->setCategory($p['category'])
                ->setPriceCents($p['priceCents'])
                ->setGoesToKitchen($p['kitchen'])
                ->setHasDeposit($p['deposit'])
                ->setInitialStock($p['startStock'])
                ->setPosition($position + 1);
            $this->entityManager->persist($products[$p['key']]);
        }
        foreach ($file->products as $p) {
            foreach ($p['components'] as $c) {
                $products[$p['key']]->addComponent($products[$c['product']], $c['qty']);
            }
        }

        $device = (new Device($event, self::DEVICE_NAME, DeviceRole::Till))->setTillLetter(self::TILL_LETTER);
        $this->entityManager->persist($device);
        $this->entityManager->flush();

        $orders = $file->orders;
        usort($orders, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);
        $time = new \DateTimeImmutable($data['date']->format('Y-m-d').' '.self::FIRST_ORDER_TIME, new \DateTimeZone($this->timezone));
        foreach ($orders as $o) {
            [$order] = $this->placement->place($device, [
                'uuid' => self::uuid(),
                'number' => $o['number'],
                // Moyen de paiement inconnu : carte, le total est encaissé tel quel
                'paymentMethod' => 'card',
                'lines' => array_map(static fn (array $l): array => ['productId' => $products[$l['product']]->getId(), 'quantity' => $l['qty']], $o['lines']),
            ]);
            // Après l'API, qui remplace toute heure de plus de 24 h et envoie en cuisine
            $order->setCreatedAt($time->setTimezone(new \DateTimeZone(date_default_timezone_get())));
            $order->setStatus(OrderStatus::Served);
            $order->setNote(null === $o['importNote'] ? null : self::NOTE_PREFIX.$o['importNote']);
            $time = $time->modify(\sprintf('+%d minutes', self::MINUTES_BETWEEN_ORDERS));
        }

        $device->setRevokedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $event;
    }

    /**
     * Relit la base, pas le fichier : écarts avec le bloc « expected ».
     *
     * @return list<string>
     */
    private function compare(Event $event, SalesImportFile $file): array
    {
        $this->entityManager->clear();
        $orders = $this->entityManager->getRepository(Order::class)->findBy(['event' => $event->getId()]);
        $names = array_column($file->products, 'name', 'key');

        $items = 0;
        $total = 0;
        $byName = [];
        foreach ($orders as $order) {
            $total += $order->getTotalCents();
            foreach ($order->getLines() as $line) {
                if (LineKind::Article === $line->getKind()) {
                    $items += $line->getQuantity();
                    $byName[$line->getLabel()] = ($byName[$line->getLabel()] ?? 0) + $line->getQuantity();
                }
            }
        }

        $expected = $file->expected;
        $gaps = [];
        if (\count($orders) !== $expected['orders']) {
            $gaps[] = \sprintf('Commandes : %d au lieu de %d', \count($orders), $expected['orders']);
        }
        if ($items !== $expected['items']) {
            $gaps[] = \sprintf('Articles : %d au lieu de %d', $items, $expected['items']);
        }
        if ($total !== $expected['totalCents']) {
            $gaps[] = \sprintf('Total : %s € au lieu de %s €', number_format($total / 100, 2, ',', ' '), number_format($expected['totalCents'] / 100, 2, ',', ' '));
        }
        foreach ($names as $key => $name) {
            $sold = $byName[$name] ?? 0;
            $wanted = $expected['itemsByProduct'][$key] ?? 0;
            if ($sold !== $wanted) {
                $gaps[] = \sprintf('%s : %d au lieu de %d', $name, $sold, $wanted);
            }
        }

        return $gaps;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr(\ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = \chr(\ord($bytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
