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
        self::assertEquals(['name' => 'Loto', 'date' => '2026-12-18', 'deposit_cents' => 0, 'with_kitchen' => true, 'table_service' => false, 'rows_per_zone' => 3], $log->getBefore());
        self::assertEquals(['name' => 'Loto de Noël', 'date' => '2026-12-19', 'deposit_cents' => 150, 'with_kitchen' => true, 'table_service' => false, 'rows_per_zone' => 3], $log->getAfter());
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

    public function testRowsPerZoneDefaultsToThreeAndIsTraced(): void
    {
        $crawler = $this->client->request('GET', '/admin/evenements/nouveau');
        self::assertSame('3', $crawler->filterXPath('//input[@name="rows_per_zone"]')->attr('value'));
        $this->client->submit($crawler->selectButton('Créer l\'événement')->form(['name' => 'Loto', 'date' => '2026-12-18']));
        self::assertSame(3, $this->findEvent('Loto')?->getRowsPerZone());

        $event = $this->findEvent('Loto');
        $crawler = $this->client->request('GET', '/admin/evenements/'.$event->getId().'/modifier');
        $before = self::revisionsOf($event->getId());
        $this->client->submit($crawler->selectButton('Enregistrer l\'événement')->form(['rows_per_zone' => '2']));

        self::assertResponseRedirects('/admin/evenements');
        self::assertRevisionsMoved($before, 0, 1, $event->getId(), 'les tickets de la cuisine affichent la zone');
        $this->entityManager->clear();
        self::assertSame(2, $this->findEvent('Loto')?->getRowsPerZone());
        $log = $this->entityManager->getRepository(AuditLog::class)->findOneBy(['action' => 'event.update']);
        self::assertSame(['rows_per_zone' => 2], array_intersect_key($log->getAfter(), ['rows_per_zone' => true]));
        self::assertSame(3, $log->getBefore()['rows_per_zone']);
    }

    public function testRowsPerZoneIsValidated(): void
    {
        $event = $this->createEvent('Loto', '2026-12-18');

        foreach (['0', '10', 'trois', '-1', '2,5'] as $wrong) {
            $crawler = $this->client->request('GET', '/admin/evenements/'.$event->getId().'/modifier');
            $this->client->submit($crawler->selectButton('Enregistrer l\'événement')->form(['rows_per_zone' => $wrong]));

            self::assertResponseStatusCodeSame(422, $wrong);
            self::assertStringContainsString('Indiquez un nombre de rangées de 1 à 9', (string) $this->client->getResponse()->getContent());
        }
        $this->entityManager->clear();
        self::assertSame(3, $this->findEvent('Loto')?->getRowsPerZone());
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

    public function testActivateLinkOnlyAsksForConfirmation(): void
    {
        $this->createEvent('Loto de Noël', '2026-12-18');

        $crawler = $this->client->request('GET', '/admin/evenements');
        $row = $crawler->filterXPath('//tr[td[1][normalize-space()="Loto de Noël"]]');
        $this->client->click($row->selectLink('Activer')->link());

        self::assertResponseIsSuccessful();
        self::assertSame('Activer « Loto de Noël » ?', $this->text('//h1'));
        $this->entityManager->clear();
        self::assertFalse($this->findEvent('Loto de Noël')?->isActive());
        self::assertSame(0, $this->auditCount('event.activate'));
    }

    public function testConfirmationShowsActiveEventTabletsOrdersAndWarning(): void
    {
        $stand = $this->createEvent('Stand de Noël', '2026-12-11');
        $this->activateFromList('Stand de Noël');
        $stand = $this->entityManager->find(Event::class, $stand->getId());
        // Comptées : appairées, en service
        $this->createPairedDevice($stand, 'Caisse buvette', DeviceRole::Till, 'A');
        $this->createPairedDevice($stand, 'Caisse cartons', DeviceRole::Till, 'B');
        // Pas comptées : révoquée, expirée, jamais appairée
        $this->createPairedDevice($stand, 'Caisse retirée', DeviceRole::Till, 'C')->setRevokedAt(new \DateTimeImmutable('-1 hour'));
        $this->createPairedDevice($stand, 'Caisse expirée', DeviceRole::Till, 'D')->setExpiresAt(new \DateTimeImmutable('-1 hour'));
        $this->createDevice($stand, 'Caisse en attente', DeviceRole::Till);
        $this->entityManager->persist(new Order('4d8c3b2a-5e6f-4a7b-8c9d-0e1f2a3b4c5d', $stand, 'A', 1, PaymentMethod::Cash));
        $this->entityManager->persist(new Order('5e9d4c3b-6f7a-4b8c-9d0e-1f2a3b4c5d6e', $stand, 'A', 2, PaymentMethod::Card));
        $this->entityManager->persist(new Order('6f0e5d4c-7a8b-4c9d-8e1f-2a3b4c5d6e7f', $stand, 'B', 1, PaymentMethod::Cash));
        $this->entityManager->flush();
        $loto = $this->createEvent('Loto de Noël', '2026-12-18');

        $crawler = $this->client->request('GET', '/admin/evenements/'.$loto->getId().'/activer');

        self::assertResponseIsSuccessful();
        $summary = $crawler->filterXPath('//dl[contains(@class, "device-info")]');
        self::assertStringContainsString('« Stand de Noël », du 11/12/2026', $summary->text());
        self::assertSame('2', $summary->filterXPath('//dt[normalize-space()="Tablettes appairées"]/following-sibling::dd[1]')->text());
        self::assertSame('3', $summary->filterXPath('//dt[normalize-space()="Commandes"]/following-sibling::dd[1]')->text());
        self::assertSame('Toutes les tablettes de cet événement cesseront de fonctionner. Vérifiez d\'abord qu\'aucune caisse n\'a de commande en attente.', $this->text('//p[contains(@class, "alert-warning")]'));
        self::assertSame('Garder « Stand de Noël » actif', $this->text('//section[contains(@class, "form-card")]//form//a[contains(@class, "btn")]'));
        self::assertSame('Activer « Loto de Noël »', $this->text('//section[contains(@class, "form-card")]//form//button[@type="submit"]'));
    }

    public function testConfirmationWithoutActiveEventHasNoWarning(): void
    {
        $loto = $this->createEvent('Loto de Noël', '2026-12-18');

        $this->client->request('GET', '/admin/evenements/'.$loto->getId().'/activer');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Aucun événement n\'est actif pour l\'instant : aucune tablette ne sera arrêtée.', $this->text('//section[contains(@class, "form-card")]'));
        self::assertCount(0, $this->client->getCrawler()->filterXPath('//p[contains(@class, "alert-warning")]'));
        self::assertCount(0, $this->client->getCrawler()->filterXPath('//dl'));
        self::assertSame('Revenir aux événements', $this->text('//section[contains(@class, "form-card")]//form//a[contains(@class, "btn")]'));
    }

    public function testFlashAfterActivationNamesStoppedEvent(): void
    {
        $this->createEvent('Stand de Noël', '2026-12-11');
        $this->createEvent('Loto de Noël', '2026-12-18');

        $this->activateFromList('Stand de Noël');
        $this->client->followRedirect();
        self::assertSame('« Stand de Noël » est maintenant l\'événement actif : seules ses tablettes fonctionnent (rubrique Tablettes).', $this->text('//*[contains(@class, "alert-info")]'));

        $this->activateFromList('Loto de Noël');
        $this->client->followRedirect();
        self::assertSame('« Loto de Noël » est maintenant l\'événement actif : seules ses tablettes fonctionnent. Celles de « Stand de Noël » sont arrêtées.', $this->text('//*[contains(@class, "alert-info")]'));
        self::assertStringNotContainsString('Les tablettes l\'utiliseront', (string) $this->client->getResponse()->getContent());
    }

    public function testActiveEventIsNotActivatedAgain(): void
    {
        $this->createEvent('Loto de Noël', '2026-12-18');
        $this->activateFromList('Loto de Noël');
        $loto = $this->findEvent('Loto de Noël');
        self::assertNotNull($loto);

        $crawler = $this->client->request('GET', '/admin/evenements');
        $row = $crawler->filterXPath('//tr[td[1][normalize-space()="Loto de Noël"]]');
        self::assertCount(0, $row->selectLink('Activer'));

        $this->client->request('GET', '/admin/evenements/'.$loto->getId().'/activer');
        self::assertResponseRedirects('/admin/evenements');
        $this->client->followRedirect();
        self::assertSame('« Loto de Noël » est déjà l\'événement actif.', $this->text('//*[contains(@class, "alert-info")]'));
        self::assertSame(1, $this->auditCount('event.activate'));
    }

    /** Texte d'un élément unique de la dernière page reçue. */
    private function text(string $xpath): string
    {
        $nodes = $this->client->getCrawler()->filterXPath($xpath);
        self::assertCount(1, $nodes, $xpath);

        return $nodes->text();
    }

    /** Lien « Activer » de la liste, puis confirmation dans la page. */
    private function activateFromList(string $name): void
    {
        $crawler = $this->client->request('GET', '/admin/evenements');
        $row = $crawler->filterXPath(\sprintf('//tr[td[1][normalize-space()="%s"]]', $name));
        $crawler = $this->client->click($row->selectLink('Activer')->link());
        $this->client->submit($crawler->selectButton(\sprintf('Activer « %s »', $name))->form());
        self::assertResponseRedirects('/admin/evenements');
    }

    private function createPairedDevice(Event $event, string $name, DeviceRole $role, ?string $tillLetter = null): Device
    {
        $device = new Device($event, $name, $role);
        $device->setTillLetter($tillLetter);
        $device->setTokenHash(hash('sha256', $name));
        $device->setExpiresAt(new \DateTimeImmutable('+1 day'));
        $this->entityManager->persist($device);
        $this->entityManager->flush();

        return $device;
    }

    private function createEvent(string $name, string $date): Event
    {
        $event = new Event($name, new \DateTimeImmutable($date));
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }

    public function testLockedEventWithKitchenDoesNotWarnAboutUnchecking(): void
    {
        // Déjà des commandes : « Avec cuisine » ne peut plus être décochée, l'annonce n'a pas lieu d'être
        $event = $this->createEvent('Loto', '2026-12-18');
        $this->createDevice($event, 'Cuisine 1', DeviceRole::Kitchen);
        $this->entityManager->persist(new Order('4d8c3b2a-5e6f-4a7b-8c9d-0e1f2a3b4c5d', $this->entityManager->find(Event::class, $event->getId()), 'A', 1, PaymentMethod::Cash));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/admin/evenements/'.$event->getId().'/modifier');

        self::assertCount(0, $crawler->filterXPath('//*[@id="kitchen-devices"]'));
        self::assertSame('kitchen-help', $crawler->filterXPath('//input[@name="with_kitchen"]')->attr('aria-describedby'));
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
