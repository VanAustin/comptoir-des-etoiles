<?php

namespace App\Command;

use App\Entity\Device;
use App\Entity\Event;
use App\Enum\DeviceRole;
use App\Repository\EventRepository;
use App\Service\DevicePairing;
use App\Service\ExampleMenu;
use App\Service\Revisions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Développement uniquement : prépare la base des essais Bruno (comptoir_bruno), lancée par bin/bruno.
 * Refuse toute autre base : la base de développement n'est jamais touchée par les essais.
 *
 * Étapes, dans l'ordre :
 *  - preparer : un événement avec cuisine, actif, avec le menu d'exemple ; une cuisine et un écran
 *    Arrivées, puis une caisse (appairée par « Appairage / 1. Échanger le code ») ;
 *  - caisse-boissons : une seconde caisse qui n'affiche que la catégorie « Boissons » (refus
 *    d'un article d'une autre catégorie, acceptation hors ligne) ;
 *  - sans-cuisine : un second événement, activé, avec une tablette cuisine à appairer ;
 *  - couper-cuisine : « Avec cuisine » décoché sur ce second événement, tablette déjà appairée.
 *
 * Sortie : une ligne « rôle code » par tablette créée, rien d'autre (lue par bin/bruno).
 * Aucun jeton créé ni affiché : il naît à l'appairage.
 */
#[AsCommand(
    name: 'app:dev:bruno-fixtures',
    description: 'Développement : prépare la base comptoir_bruno pour la collection Bruno (bin/bruno)',
)]
final class DevBrunoFixturesCommand extends Command
{
    public const DATABASE = 'comptoir_bruno';
    public const STEPS = ['preparer', 'caisse-boissons', 'sans-cuisine', 'couper-cuisine'];
    public const DRINKS_TILL = 'Caisse Boissons Bruno';
    public const EVENT_WITH_KITCHEN = 'Bruno avec cuisine';
    public const EVENT_WITHOUT_KITCHEN = 'Bruno sans cuisine';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventRepository $events,
        private readonly DevicePairing $pairing,
        private readonly ExampleMenu $exampleMenu,
        private readonly Revisions $revisions,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        /** Seule base acceptée ; changée uniquement par les tests (base comptoir_test). */
        private readonly string $allowedDatabase = self::DATABASE,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('etape', InputArgument::REQUIRED, 'Étape : '.implode(', ', self::STEPS));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        if ('dev' !== $this->environment) {
            $error->writeln(\sprintf('<error>Commande réservée au développement (APP_ENV=dev), ici APP_ENV=%s.</error>', $this->environment));

            return Command::FAILURE;
        }
        $database = $this->entityManager->getConnection()->getDatabase();
        if ($this->allowedDatabase !== $database) {
            $error->writeln(\sprintf('<error>Commande réservée à la base %s, ici %s : rien n\'est modifié. Lancez bin/bruno.</error>', $this->allowedDatabase, $database ?? 'aucune'));

            return Command::FAILURE;
        }

        $step = (string) $input->getArgument('etape');
        $devices = match ($step) {
            'preparer' => $this->prepare(),
            'caisse-boissons' => $this->drinksTill(),
            'sans-cuisine' => $this->eventWithoutKitchen(),
            'couper-cuisine' => $this->turnKitchenOff(),
            default => null,
        };
        if (null === $devices) {
            $error->writeln(\sprintf('<error>Étape inconnue « %s » ou base dans un état inattendu. Étapes : %s. Lancez bin/bruno, qui repart d\'une base vide.</error>', $step, implode(', ', self::STEPS)));

            return Command::FAILURE;
        }

        foreach ($devices as [$device, $code]) {
            $output->writeln($device->getRole()->value.' '.$code);
        }

        return Command::SUCCESS;
    }

    /** @return list<array{Device, string}>|null */
    private function prepare(): ?array
    {
        if ([] !== $this->events->findAll()) {
            return null;
        }
        $event = new Event(self::EVENT_WITH_KITCHEN, new \DateTimeImmutable('today'));
        $this->entityManager->persist($event);
        $this->entityManager->flush();
        $this->events->activate($event);
        $this->exampleMenu->load($event);

        // La caisse en dernier : bin/bruno la donne à « Appairage / 1. Échanger le code »
        return $this->createDevices($event, [
            [DeviceRole::Kitchen, 'Cuisine Bruno'],
            [DeviceRole::Display, 'Arrivées Bruno'],
            [DeviceRole::Till, 'Caisse Bruno'],
        ]);
    }

    /** @return list<array{Device, string}>|null */
    private function drinksTill(): ?array
    {
        $event = $this->events->findOneBy(['name' => self::EVENT_WITH_KITCHEN, 'active' => true]);
        if (null === $event || null !== $this->entityManager->getRepository(Device::class)->findOneBy(['name' => self::DRINKS_TILL])) {
            return null;
        }
        $created = $this->createDevices($event, [[DeviceRole::Till, self::DRINKS_TILL]]);
        $created[0][0]->setCategories(['Boissons']);
        $this->entityManager->flush();

        return $created;
    }

    /** @return list<array{Device, string}>|null */
    private function eventWithoutKitchen(): ?array
    {
        if (null === $this->events->findOneBy(['name' => self::EVENT_WITH_KITCHEN]) || null !== $this->events->findOneBy(['name' => self::EVENT_WITHOUT_KITCHEN])) {
            return null;
        }
        // Avec cuisine le temps d'appairer sa tablette cuisine, comme dans la réalité
        $event = new Event(self::EVENT_WITHOUT_KITCHEN, new \DateTimeImmutable('today'));
        $this->entityManager->persist($event);
        $this->entityManager->flush();
        $this->events->activate($event);
        $this->entityManager->flush();

        return $this->createDevices($event, [[DeviceRole::Kitchen, 'Cuisine Bruno sans cuisine']]);
    }

    /** @return list<array{Device, string}>|null */
    private function turnKitchenOff(): ?array
    {
        $event = $this->events->findOneBy(['name' => self::EVENT_WITHOUT_KITCHEN, 'active' => true]);
        if (null === $event || !$event->hasKitchen()) {
            return null;
        }
        $event->setWithKitchen(false);
        $this->revisions->flushMenu($event);

        return [];
    }

    /**
     * @param list<array{DeviceRole, string}> $roles
     *
     * @return list<array{Device, string}>
     */
    private function createDevices(Event $event, array $roles): array
    {
        $created = [];
        foreach ($roles as [$role, $name]) {
            $device = new Device($event, $name, $role);
            $created[] = [$device, DevicePairing::formatCode($this->pairing->generateCode($device))];
            $this->entityManager->persist($device);
        }
        $this->entityManager->flush();

        return $created;
    }
}
