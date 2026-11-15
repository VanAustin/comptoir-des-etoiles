<?php

namespace App\Tests\Controller\Admin;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Entity\Device;
use App\Entity\Event;
use App\Entity\Order;
use App\Enum\DeviceRole;
use App\Enum\PaymentMethod;
use App\Service\DevicePairing;
use App\Tests\ResetDatabaseTrait;
use App\Tests\RevisionsTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class EventControllerTest extends WebTestCase
{
    use ResetDatabaseTrait;
    use RevisionsTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::resetDatabase();

        $admin = (new AdminUser('camille'))->setPassword('inutile-ici');
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        $this->client->loginUser($admin);
    }

    public function testAnonymousVisitorIsRedirectedToLogin(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/admin/evenements');

        self::assertResponseRedirects('/login');
    }

    public function testCreateEvent(): void
    {
        $crawler = $this->client->request('GET', '/admin/evenements/nouveau');
        $this->client->submit($crawler->selectButton('Créer l\'événement')->form([
            'name' => '  Loto de Noël  ',
            'date' => '2026-12-18',
        ]));

        self::assertResponseRedirects('/admin/evenements');
        $event = $this->findEvent('Loto de Noël');
        self::assertNotNull($event);
        self::assertSame('2026-12-18', $event->getDate()->format('Y-m-d'));
        self::assertFalse($event->isActive());
        self::assertSame(1, $this->auditCount('event.create'));

        $this->client->followRedirect();
        self::assertStringContainsString('Loto de Noël', (string) $this->client->getResponse()->getContent());
    }

    public function testInvalidFormIsShownAgainWithErrors(): void
    {
        $crawler = $this->client->request('GET', '/admin/evenements/nouveau');
        $this->client->submit($crawler->selectButton('Créer l\'événement')->form([
            'name' => '',
            'date' => '2026-02-30',
        ]));

        self::assertResponseStatusCodeSame(422);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Indiquez le nom de l', $content);
        self::assertStringContainsString('Choisissez la date', $content);
        self::assertSame(0, $this->entityManager->getRepository(Event::class)->count());
    }

    public function testInvalidDepositIsRefused(): void
    {
        $crawler = $this->client->request('GET', '/admin/evenements/nouveau');
        $this->client->submit($crawler->selectButton('Créer l\'événement')->form([
            'name' => 'Loto de Noël',
            'date' => '2026-12-18',
            'deposit' => '-1',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('montant en euros', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->entityManager->getRepository(Event::class)->count());
    }

    public function testCreateRefusesInvalidCsrfToken(): void
    {
        $this->client->request('POST', '/admin/evenements/nouveau', [
            'name' => 'Loto de Noël',
            'date' => '2026-12-18',
            '_token' => 'jeton-invalide',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->entityManager->getRepository(Event::class)->count());
    }

    public function testEditEvent(): void
    {
        $event = $this->createEvent('Loto', '2026-12-18');

        $crawler = $this->client->request('GET', '/admin/evenements/'.$event->getId().'/modifier');
        $before = self::revisionsOf($event->getId());
        $this->client->submit($crawler->selectButton('Enregistrer l\'événement')->form([
            'name' => 'Loto de Noël',
            'date' => '2026-12-19',
            'deposit' => '1,50',
        ]));

        self::assertResponseRedirects('/admin/evenements');
        self::assertRevisionsMoved($before, 0, 1, $event->getId(), 'nom, date et consigne font partie du menu des caisses');
        $this->entityManager->clear();
        $event = $this->findEvent('Loto de Noël');
        self::assertSame('2026-12-19', $event?->getDate()->format('Y-m-d'));
        self::assertSame(150, $event->getDepositCents());

        $log = $this->entityManager->getRepository(AuditLog::class)->findOneBy(['action' => 'event.update']);
        self::assertSame('admin:camille', $log?->getActor());
        // assertEquals : MySQL ne garde pas l'ordre des clés d'une colonne JSON
        self::assertEquals(['name' => 'Loto', 'date' => '2026-12-18', 'deposit_cents' => 0, 'with_kitchen' => true, 'table_service' => false], $log->getBefore());
        self::assertEquals(['name' => 'Loto de Noël', 'date' => '2026-12-19', 'deposit_cents' => 150, 'with_kitchen' => true, 'table_service' => false], $log->getAfter());
    }

    public function testNewEventHasKitchenByDefault(): void
    {
        $crawler = $this->client->request('GET', '/admin/evenements/nouveau');
        self::assertNotNull($crawler->filterXPath('//input[@name="with_kitchen"]')->attr('checked'));

        $this->client->submit($crawler->selectButton('Créer l\'événement')->form(['name' => 'Loto de Noël', 'date' => '2026-12-18']));

        self::assertResponseRedirects('/admin/evenements');
        self::assertTrue($this->findEvent('Loto de Noël')?->hasKitchen());
    }

    public function testTableServiceIsOffByDefaultAndCanBeTicked(): void
    {
        $crawler = $this->client->request('GET', '/admin/evenements/nouveau');
        self::assertNull($crawler->filterXPath('//input[@name="table_service"]')->attr('checked'));
        self::assertSame(1, $crawler->filterXPath('//script[contains(@src, "/js/admin-event.js")]')->count());

        $form = $crawler->selectButton('Créer l\'événement')->form(['name' => 'Loto de Noël', 'date' => '2026-12-18']);
        $form['table_service']->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/evenements');
        self::assertTrue($this->findEvent('Loto de Noël')?->hasTableService());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Service à table', $crawler->filterXPath('//tr[td[contains(., "Loto de Noël")]]')->text());
    }

    public function testTableServiceNeedsTheKitchen(): void
    {
        $crawler = $this->client->request('GET', '/admin/evenements/nouveau');
        $form = $crawler->selectButton('Créer l\'événement')->form(['name' => 'Stand de Noël', 'date' => '2026-12-11']);
        $form['with_kitchen']->untick();
        $form['table_service']->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/evenements');
        self::assertFalse($this->findEvent('Stand de Noël')?->hasTableService());

        // Événement sans cuisine : la case est désactivée
        $crawler = $this->client->request('GET', '/admin/evenements/'.$this->findEvent('Stand de Noël')->getId().'/modifier');
        self::assertNotNull($crawler->filterXPath('//input[@name="table_service"]')->attr('disabled'));
    }

    public function testTableServiceCanChangeAfterOrdersAndMovesTheMenuRevision(): void
    {
        $event = $this->createEvent('Loto', '2026-12-18');
        $this->entityManager->persist(new Order('2b6a1f0e-3c4d-4e5f-8a9b-0c1d2e3f4a5c', $event, 'A', 1, PaymentMethod::Cash));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/admin/evenements/'.$event->getId().'/modifier');
        $before = self::revisionsOf($event->getId());
        // « Avec cuisine » est verrouillée (désactivée, donc absente de l'envoi) : son état enregistré fait foi
        $form = $crawler->selectButton('Enregistrer l\'événement')->form();
        $form['table_service']->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/evenements');
        self::assertRevisionsMoved($before, 0, 1, $event->getId(), 'le bouton « Table » des caisses dépend du réglage');
        $this->entityManager->clear();
        self::assertTrue($this->findEvent('Loto')?->hasTableService());
    }

    public function testCreateEventWithoutKitchen(): void
    {
        $crawler = $this->client->request('GET', '/admin/evenements/nouveau');
        $form = $crawler->selectButton('Créer l\'événement')->form(['name' => 'Stand de Noël', 'date' => '2026-12-11']);
        $form['with_kitchen']->untick();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/evenements');
        self::assertFalse($this->findEvent('Stand de Noël')?->hasKitchen());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Sans cuisine', $crawler->filterXPath('//tr[td[contains(., "Stand de Noël")]]')->text());
    }

    public function testTurningKitchenOffWarnsAboutKitchenAndDisplayDevices(): void
    {
        $event = $this->createEvent('Stand de Noël', '2026-12-11');
        $this->createDevice($event, 'Caisse buvette', DeviceRole::Till);
        $this->createDevice($event, 'Cuisine 1', DeviceRole::Kitchen);
        $this->createDevice($event, 'Écran salle', DeviceRole::Display);
        $this->createDevice($event, 'Ancienne cuisine', DeviceRole::Kitchen)->setRevokedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/admin/evenements/'.$event->getId().'/modifier');
        self::assertStringContainsString('Si vous décochez « Avec cuisine », 2 tablettes (« Cuisine 1 », « Écran salle ») ne fonctionneront plus.', $crawler->filterXPath('//*[@id="kitchen-devices"]')->text());

        $form = $crawler->selectButton('Enregistrer l\'événement')->form();
        $form['with_kitchen']->untick();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/evenements');
        $this->entityManager->clear();
        self::assertFalse($this->findEvent('Stand de Noël')?->hasKitchen());
        $log = $this->entityManager->getRepository(AuditLog::class)->findOneBy(['action' => 'event.update']);
        self::assertTrue($log?->getBefore()['with_kitchen']);
        self::assertFalse($log->getAfter()['with_kitchen']);

        $this->client->followRedirect();
        self::assertStringContainsString(
            'Événement sans cuisine : 2 tablettes (« Cuisine 1 », « Écran salle ») ne fonctionnent plus.',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testTableServiceExplainsWhyItIsGreyed(): void
    {
        $withKitchen = $this->createEvent('Loto', '2026-12-18');
        $crawler = $this->client->request('GET', '/admin/evenements/'.$withKitchen->getId().'/modifier');
        self::assertNull($crawler->filterXPath('//input[@name="table_service"]')->attr('disabled'));
        self::assertNotNull($crawler->filterXPath('//*[@id="table-blocked"]')->attr('hidden'), 'avec cuisine : rien à expliquer');

        $withoutKitchen = $this->createEvent('Stand de Noël', '2026-12-11')->setWithKitchen(false);
        $this->entityManager->flush();
        $crawler = $this->client->request('GET', '/admin/evenements/'.$withoutKitchen->getId().'/modifier');
        $blocked = $crawler->filterXPath('//*[@id="table-blocked"]');
        self::assertNull($blocked->attr('hidden'));
        self::assertSame('Cochez d\'abord « Avec cuisine ».', $blocked->text());

        $this->entityManager->persist(new Order('3c7b2a1f-4d5e-4f6a-9b0c-1d2e3f4a5b6c', $this->entityManager->find(Event::class, $withoutKitchen->getId()), 'A', 1, PaymentMethod::Cash));
        $this->entityManager->flush();
        $crawler = $this->client->request('GET', '/admin/evenements/'.$withoutKitchen->getId().'/modifier');
        self::assertSame('Impossible : l\'événement est sans cuisine et a déjà des commandes.', $crawler->filterXPath('//*[@id="table-blocked"]')->text());
    }

    public function testKitchenSettingIsLockedOnceTheEventHasOrders(): void
    {
        $event = $this->createEvent('Loto de Noël', '2026-12-18');
        $this->entityManager->persist(new Order('2b6a1f0e-3c4d-4e5f-8a9b-0c1d2e3f4a5b', $event, 'A', 1, PaymentMethod::Cash));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/admin/evenements/'.$event->getId().'/modifier');
        self::assertNotNull($crawler->filterXPath('//input[@name="with_kitchen"]')->attr('disabled'));
        $help = $crawler->filterXPath('//*[@id="kitchen-help"]');
        self::assertStringContainsString('Cet événement a déjà 1 commande : « Avec cuisine » ne peut plus être modifié', $help->text());
        self::assertStringContainsString('créez un nouvel événement', $help->text());
        self::assertStringContainsString('alert', (string) $help->attr('class'), 'bien visible, pas une petite aide');
        self::assertCount(0, $crawler->filterXPath('//*[@id="kitchen-devices"]'), 'aucune tablette cuisine : pas d\'annonce');

        // Case contournée : la valeur envoyée est ignorée, le reste est enregistré
        $this->client->request('POST', '/admin/evenements/'.$event->getId().'/modifier', [
            'name' => 'Loto du 18',
            'date' => '2026-12-18',
            'deposit' => '0',
            '_token' => $crawler->filterXPath('//input[@name="_token"]')->attr('value'),
        ]);

        self::assertResponseRedirects('/admin/evenements');
        $this->entityManager->clear();
        $event = $this->findEvent('Loto du 18');
        self::assertNotNull($event);
        self::assertTrue($event->hasKitchen());
    }

    public function testOnlyOneEventIsActive(): void
    {
        $halloween = $this->createEvent('Halloween', '2026-10-31');
        $loto = $this->createEvent('Loto de Noël', '2026-12-18');

        $this->activateFromList('Halloween');
        $this->activateFromList('Loto de Noël');

        $this->entityManager->clear();
        self::assertFalse($this->findEvent('Halloween')?->isActive());
        self::assertTrue($this->findEvent('Loto de Noël')?->isActive());
        self::assertSame(2, $this->auditCount('event.activate'));
        self::assertNotSame($halloween->getId(), $loto->getId());
    }

    public function testActivateRefusesInvalidCsrfToken(): void
    {
        $event = $this->createEvent('Loto de Noël', '2026-12-18');

        $this->client->request('POST', '/admin/evenements/'.$event->getId().'/activer', ['_token' => 'jeton-invalide']);

        self::assertResponseStatusCodeSame(403);
        $this->entityManager->clear();
        self::assertFalse($this->findEvent('Loto de Noël')?->isActive());
    }

    public function testActivateRefusesGet(): void
    {
        $event = $this->createEvent('Loto de Noël', '2026-12-18');

        $this->client->request('GET', '/admin/evenements/'.$event->getId().'/activer');

        self::assertResponseStatusCodeSame(405);
    }

    private function activateFromList(string $name): void
    {
        $crawler = $this->client->request('GET', '/admin/evenements');
        $row = $crawler->filterXPath(\sprintf('//tr[td[1][normalize-space()="%s"]]', $name));
        $this->client->submit($row->selectButton('Activer')->form());
        self::assertResponseRedirects('/admin/evenements');
    }

    private function createEvent(string $name, string $date): Event
    {
        $event = new Event($name, new \DateTimeImmutable($date));
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }

    public function testLockedEventWithoutKitchenOnlySuggestsRevokingDevices(): void
    {
        $event = $this->createEvent('Stand de Noël', '2026-12-11')->setWithKitchen(false);
        $this->createDevice($event, 'Cuisine 1', DeviceRole::Kitchen);

        $crawler = $this->client->request('GET', '/admin/evenements/'.$event->getId().'/modifier');
        self::assertStringContainsString('Révoquez-la dans Tablettes, ou cochez « Avec cuisine ».', $crawler->filterXPath('//*[@id="kitchen-devices"]')->text());

        $this->entityManager->persist(new Order('2b6a1f0e-3c4d-4e5f-8a9b-0c1d2e3f4a5b', $event, 'A', 1, PaymentMethod::Cash));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/admin/evenements/'.$event->getId().'/modifier');
        $warning = $crawler->filterXPath('//*[@id="kitchen-devices"]')->text();
        self::assertStringContainsString('Sans cuisine, la tablette « Cuisine 1 » ne fonctionne pas. Révoquez-la dans Tablettes.', $warning);
        self::assertStringNotContainsString('cochez', $warning, 'la case ne peut plus être cochée');
    }

    private function createDevice(Event $event, string $name, DeviceRole $role): Device
    {
        $device = new Device($event, $name, $role);
        self::getContainer()->get(DevicePairing::class)->generateCode($device);
        $this->entityManager->persist($device);
        $this->entityManager->flush();

        return $device;
    }

    private function findEvent(string $name): ?Event
    {
        return $this->entityManager->getRepository(Event::class)->findOneBy(['name' => $name]);
    }

    private function auditCount(string $action): int
    {
        return $this->entityManager->getRepository(AuditLog::class)->count(['action' => $action]);
    }
}
