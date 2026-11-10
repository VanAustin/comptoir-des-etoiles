<?php

namespace App\Command;

use App\Entity\AuditLog;
use App\Entity\Device;
use App\Enum\DeviceRole;
use App\Repository\EventRepository;
use App\Service\DevicePairing;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Développement uniquement : crée pour l'événement actif une caisse, une cuisine et un écran
 * Arrivées, chacun avec un code d'appairage, et affiche l'adresse qui finit l'appairage dans Chrome.
 *
 * Le code est placé après le « # » de l'adresse : le navigateur ne l'envoie jamais au serveur.
 * Aucun jeton n'est créé ici : il naît dans la tablette, à l'appairage, et n'est jamais affiché.
 */
#[AsCommand(
    name: 'app:dev:test-devices',
    description: 'Développement : crée une caisse, une cuisine et un écran Arrivées prêts à appairer',
)]
final class DevTestDevicesCommand extends Command
{
    /** Auteur inscrit dans le journal d'audit (pas d'utilisateur connecté en ligne de commande). */
    public const AUDIT_ACTOR = 'commande app:dev:test-devices';

    private const DEVICES = [
        [DeviceRole::Till, 'Caisse d\'essai'],
        [DeviceRole::Kitchen, 'Cuisine d\'essai'],
        [DeviceRole::Display, 'Arrivées d\'essai'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventRepository $events,
        private readonly DevicePairing $pairing,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Crée les tablettes sans demander de confirmation (scripts)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('dev' !== $this->environment) {
            $io->error(\sprintf('Commande réservée au développement (APP_ENV=dev), ici APP_ENV=%s : aucune tablette n\'est créée.', $this->environment));

            return Command::FAILURE;
        }

        $event = $this->events->findActive();
        if (null === $event) {
            $io->error('Aucun événement actif. Activez-en un dans l\'administration, rubrique Événements, puis relancez la commande.');

            return Command::FAILURE;
        }
        if ($this->pairing->tokenExpiry($event) <= new \DateTimeImmutable()) {
            $io->error(\sprintf('L\'événement actif « %s » est terminé : aucune tablette ne peut y être appairée. Activez un événement daté d\'aujourd\'hui ou plus tard.', $event->getName()));

            return Command::FAILURE;
        }

        // Les tablettes vont sur l'événement actif : on le montre, et on demande confirmation
        $io->text(\sprintf('Événement actif : « %s », le %s, %s.', $event->getName(), $event->getDate()->format('d/m/Y'), $event->hasKitchen() ? 'avec cuisine' : 'sans cuisine'));
        $roles = $event->hasKitchen() ? 'une caisse, une cuisine et un écran Arrivées' : 'une caisse';
        if (!$input->getOption('yes')) {
            if (!$input->isInteractive()) {
                $io->error('Confirmation impossible sans terminal : relancez la commande dans un terminal, ou ajoutez --yes.');

                return Command::FAILURE;
            }
            // Question simple plutôt que confirm(), qui n'accepte que « yes » : ici « oui » (ou « o »)
            $answer = (string) $io->ask(\sprintf('Créer %s pour cet événement ? Tapez « oui » pour confirmer', $roles), 'non');
            if (!preg_match('/^\s*(oui|o|yes|y)\s*$/i', $answer)) {
                $io->text('Aucune tablette créée. Pour un autre événement, activez-le d\'abord dans l\'administration, rubrique Événements.');

                return Command::SUCCESS;
            }
        }

        $created = [];
        foreach (self::DEVICES as [$role, $name]) {
            if ($role->needsKitchen() && !$event->hasKitchen()) {
                continue;
            }
            $device = new Device($event, $name, $role);
            $code = $this->pairing->generateCode($device);
            $this->entityManager->persist($device);
            $created[] = [$device, $code];
        }
        $this->entityManager->flush();
        foreach ($created as [$device]) {
            $this->entityManager->persist(new AuditLog(self::AUDIT_ACTOR, 'device.create', 'device:'.$device->getId().' '.$device->getName(), after: ['role' => $device->getRole()->value]));
        }
        $this->entityManager->flush();

        $io->title(\sprintf('Tablettes d\'essai pour « %s »', $event->getName()));
        if (!$event->hasKitchen()) {
            $io->note('Événement sans cuisine : seule la caisse est créée (les écrans Cuisine et Arrivées ne sont pas proposés).');
        }
        $pairUrl = $this->urls->generate('app_pair', [], UrlGeneratorInterface::ABSOLUTE_URL);
        foreach ($created as [$device, $code]) {
            $io->section($device->getName().' ('.$device->getRole()->label().')');
            $io->text([
                'Ouvrez cette adresse dans Chrome :',
                '',
                \sprintf('  <info>%s#code=%s</info>', $pairUrl, DevicePairing::formatCode($code)),
                '',
                \sprintf('Ou saisissez le code %s sur la page d\'appairage.', DevicePairing::formatCode($code)),
            ]);
        }
        $io->success('Codes valables 10 minutes, à usage unique.');
        $io->text([
            'Chrome ne garde qu\'un appairage par adresse de site et par profil. Pour avoir les trois tablettes sur ce Mac,',
            'ouvrez chaque adresse dans un profil Chrome différent, ou une dans une fenêtre normale, une dans une fenêtre',
            'de navigation privée, et la troisième en remplaçant « localhost » par « 127.0.0.1 » dans l\'adresse.',
        ]);

        return Command::SUCCESS;
    }
}
