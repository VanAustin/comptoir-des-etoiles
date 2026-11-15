<?php

namespace App\Tests\Controller\Admin;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Entity\Device;
use App\Entity\Event;
use App\Entity\Product;
use App\Enum\DeviceRole;
use App\Tests\FixturesTrait;
use App\Tests\ResetDatabaseTrait;
use App\Tests\RevisionsTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DeviceControllerTest extends WebTestCase
{
    use FixturesTrait;
    use ResetDatabaseTrait;
    use RevisionsTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetDatabase();
        self::getContainer()->get('cache.rate_limiter')->clear();

        $admin = (new AdminUser('camille'))->setPassword('inutile-ici');
        self::entityManager()->persist($admin);
        self::entityManager()->flush();
        $this->client->loginUser($admin);
    }

    public function testWithoutActiveEventAdminIsSentToEvents(): void
    {
        $this->client->request('GET', '/admin/tablettes');
        self::assertStringContainsString('Aucun événement n\'est actif', $this->content());

        $this->client->request('GET', '/admin/tablettes/nouvelle');
        self::assertResponseRedirects('/admin/evenements');
    }

    public function testAddDeviceShowsCodeOnlyOnce(): void
    {
        self::createEvent();

        $crawler = $this->client->request('GET', '/admin/tablettes/nouvelle');
        $this->client->submit($crawler->selectButton('Ajouter la tablette')->form([
            'name' => 'Caisse buvette',
            'role' => 'caisse',
        ]));

        $device = $this->findDevice('Caisse buvette');
        self::assertResponseRedirects('/admin/tablettes/'.$device->getId().'/code');

        $this->client->followRedirect();
        self::assertMatchesRegularExpression('/\b([A-HJKMNP-Z2-9]{4})-([A-HJKMNP-Z2-9]{4})\b/', $this->content());
        preg_match('/\b([A-HJKMNP-Z2-9]{4})-([A-HJKMNP-Z2-9]{4})\b/', $this->content(), $matches);
        self::assertSame(hash('sha256', $matches[1].$matches[2]), $device->getPairingCodeHash());
        self::assertSame(1, self::entityManager()->getRepository(AuditLog::class)->count(['action' => 'device.create']));

        // Un rechargement de la page n'affiche plus le code
        $this->client->request('GET', '/admin/tablettes/'.$device->getId().'/code');
        self::assertStringContainsString('n\'est plus affiché', $this->content());
        self::assertDoesNotMatchRegularExpression('/\b'.$matches[1].'-'.$matches[2].'\b/', $this->content());
    }

    public function testCodePageShowsQrCodeOfThePairingLinkAndRemainingTime(): void
    {
        self::createEvent();

        $crawler = $this->client->request('GET', '/admin/tablettes/nouvelle');
        $this->client->submit($crawler->selectButton('Ajouter la tablette')->form(['name' => 'Caisse buvette', 'role' => 'caisse']));
        $crawler = $this->client->followRedirect();
        $device = $this->findDevice('Caisse buvette');

        $code = trim($crawler->filterXPath('//*[contains(@class, "pairing-code")]')->text());
        $canvas = $crawler->filterXPath('//canvas[@data-pair-url]');
        self::assertSame('http://localhost/appairer#code='.$code, $canvas->attr('data-pair-url'));
        self::assertSame('img', $canvas->attr('role'));
        self::assertSame(1, $crawler->filterXPath('//script[contains(@src, "/js/pairing-qr.js")]')->count());

        $remaining = (int) $crawler->filterXPath('//*[@data-remaining-seconds]')->attr('data-remaining-seconds');
        self::assertEqualsWithDelta(600, $remaining, 5, 'calculé par le serveur');

        // « Nouveau code » sur la page même, avec son jeton CSRF
        $form = $crawler->selectButton('Nouveau code')->form();
        $previousHash = $device->getPairingCodeHash();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/tablettes/'.$device->getId().'/code');
        self::assertNotSame($previousHash, $this->findDevice('Caisse buvette')->getPairingCodeHash());
    }

    public function testCodePageWithoutCodeShowsNoQrCodeButOffersANewCode(): void
    {
        [$device] = self::createDeviceWithCode(self::createEvent());

        $crawler = $this->client->request('GET', '/admin/tablettes/'.$device->getId().'/code');

        self::assertSame(0, $crawler->filterXPath('//canvas')->count());
        self::assertSame(0, $crawler->filterXPath('//script[contains(@src, "/js/pairing-qr.js")]')->count());
        self::assertSame(1, $crawler->selectButton('Nouveau code')->count());
    }

    public function testPairedDeviceIsNotOfferedANewCode(): void
    {
        [$device] = self::createPairedDevice(self::createEvent());

        $crawler = $this->client->request('GET', '/admin/tablettes/'.$device->getId().'/code');

        self::assertSame(0, $crawler->selectButton('Nouveau code')->count());
    }

    public function testWithoutKitchenOnlyTillsAreOffered(): void
    {
        self::createEvent()->setWithKitchen(false);
        self::entityManager()->flush();

        $crawler = $this->client->request('GET', '/admin/tablettes/nouvelle');
        self::assertSame(['caisse'], $crawler->filterXPath('//input[@name="role"]')->each(static fn ($i) => $i->attr('value')));
        self::assertNotNull($crawler->filterXPath('//input[@name="role"]')->attr('checked'), 'choisi d\'office');
        self::assertStringContainsString('seules les tablettes caisse sont proposées', $this->content());

        // Rôle forcé dans la requête : refusé par le serveur
        $token = $crawler->filterXPath('//input[@name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/tablettes/nouvelle', ['name' => 'Cuisine 1', 'role' => 'cuisine', '_token' => $token]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Cet événement est sans cuisine', $this->content());
        self::assertSame(0, self::entityManager()->getRepository(Device::class)->count([]));
    }

    public function testInvalidFormIsShownAgain(): void
    {
        self::createEvent();

        $crawler = $this->client->request('GET', '/admin/tablettes/nouvelle');
        $form = $crawler->selectButton('Ajouter la tablette')->form(['name' => '']);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Donnez un nom', $this->content());
        self::assertStringContainsString('Choisissez le rôle', $this->content());
        self::assertSame(0, self::entityManager()->getRepository(Device::class)->count());
    }

    public function testAddDeviceRefusesInvalidCsrfToken(): void
    {
        self::createEvent();

        $this->client->request('POST', '/admin/tablettes/nouvelle', ['name' => 'Caisse', 'role' => 'caisse', '_token' => 'jeton-invalide']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, self::entityManager()->getRepository(Device::class)->count());
    }

    public function testNewCodeReplacesThePreviousOne(): void
    {
        [$device] = self::createDeviceWithCode(self::createEvent());
        $previousHash = $device->getPairingCodeHash();

        $this->clickInRow('Caisse buvette', 'Nouveau code');

        self::assertResponseRedirects('/admin/tablettes/'.$device->getId().'/code');
        self::assertNotSame($previousHash, $this->findDevice('Caisse buvette')->getPairingCodeHash());
    }

    public function testRevokeBlocksTheDevice(): void
    {
        [$device, $token] = self::createPairedDevice(self::createEvent());

        $this->clickInRow('Caisse buvette', 'Révoquer');

        self::assertResponseRedirects('/admin/tablettes');
        self::assertNotNull($this->findDevice('Caisse buvette')->getRevokedAt());
        self::assertSame(1, self::entityManager()->getRepository(AuditLog::class)->count(['action' => 'device.revoke']));

        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testRevokeAndNewCodeRefuseInvalidCsrfToken(): void
    {
        [$device] = self::createDeviceWithCode(self::createEvent());

        $this->client->request('POST', '/admin/tablettes/'.$device->getId().'/revoquer', ['_token' => 'jeton-invalide']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/tablettes/'.$device->getId().'/nouveau-code', ['_token' => 'jeton-invalide']);
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->findDevice('Caisse buvette')->getRevokedAt());
    }

    public function testListShowsStateAndLetter(): void
    {
        $event = self::createEvent();
        self::createPairedDevice($event, 'Caisse buvette');
        self::createDeviceWithCode($event, 'Écran arrivées');

        $this->client->request('GET', '/admin/tablettes');

        $content = $this->content();
        self::assertStringContainsString('Appairée', $content);
        self::assertStringContainsString('En attente d&#039;appairage', $content);
        self::assertMatchesRegularExpression('/<td class="num" data-label="Lettre">A<\/td>/', $content);
    }

    public function testNewTillSellsCheckedCategories(): void
    {
        $event = self::createEvent();
        $this->menu($event, ['Carton' => 'Loto', 'Jetons' => 'Loto', 'Crêpe' => 'Sucré', 'Café' => 'Boissons']);

        $crawler = $this->client->request('GET', '/admin/tablettes/nouvelle');
        self::assertCount(3, $crawler->filterXPath('//input[@name="categories[]"][@checked]'), 'tout coché par défaut');
        $form = $crawler->selectButton('Ajouter la tablette')->form(['name' => 'Caisse entrée', 'role' => 'caisse', 'direct_sale' => true]);
        $form['categories'][1]->untick();
        $form['categories'][2]->untick();
        $this->client->submit($form);
        self::assertResponseRedirects();

        $device = $this->findDevice('Caisse entrée');
        self::assertSame(['Loto'], $device->getCategories());
        self::assertTrue($device->isDirectSale());

        // Toutes cochées : tout le menu, y compris les catégories ajoutées plus tard
        $crawler = $this->client->request('GET', '/admin/tablettes/nouvelle');
        $this->client->submit($crawler->selectButton('Ajouter la tablette')->form(['name' => 'Caisse buvette', 'role' => 'caisse']));
        self::assertSame([], $this->findDevice('Caisse buvette')->getCategories());
        self::assertFalse($this->findDevice('Caisse buvette')->isDirectSale());
    }

    public function testTillNeedsAtLeastOneCategory(): void
    {
        $event = self::createEvent();
        $this->menu($event, ['Carton' => 'Loto', 'Café' => 'Boissons']);

        $crawler = $this->client->request('GET', '/admin/tablettes/nouvelle');
        $form = $crawler->selectButton('Ajouter la tablette')->form(['name' => 'Caisse entrée', 'role' => 'caisse']);
        $form['categories'][0]->untick();
        $form['categories'][1]->untick();
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Cochez au moins une catégorie', $this->content());
        self::assertSame(0, self::entityManager()->getRepository(Device::class)->count([]));
    }

    public function testKitchenDeviceIgnoresTillSettings(): void
    {
        $event = self::createEvent();
        $this->menu($event, ['Carton' => 'Loto', 'Café' => 'Boissons']);

        $crawler = $this->client->request('GET', '/admin/tablettes/nouvelle');
        $form = $crawler->selectButton('Ajouter la tablette')->form(['name' => 'Cuisine', 'role' => 'cuisine', 'direct_sale' => true]);
        $form['categories'][0]->untick();
        $form['categories'][1]->untick();
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertSame([], $this->findDevice('Cuisine')->getCategories());
        self::assertFalse($this->findDevice('Cuisine')->isDirectSale());
    }

    public function testSettingsOfAPairedTillAreTracedAndUnsoldCategoriesReported(): void
    {
        $event = self::createEvent();
        $this->menu($event, ['Carton' => 'Loto', 'Café' => 'Boissons']);
        [$till] = self::createPairedDevice($event, 'Caisse entrée');
        [$kitchen] = self::createPairedDevice($event, 'Cuisine', DeviceRole::Kitchen);

        $crawler = $this->client->request('GET', '/admin/tablettes');
        self::assertCount(0, $crawler->filterXPath('//*[@id="unsold-categories"]'));
        self::assertCount(1, $crawler->filterXPath('//a[normalize-space()="Réglages"]'), 'caisse seulement');

        $crawler = $this->client->request('GET', '/admin/tablettes/'.$till->getId().'/reglages');
        $form = $crawler->selectButton('Enregistrer les réglages')->form(['direct_sale' => true]);
        $form['categories'][1]->untick(); // Boissons
        $before = self::revisionsOf($event->getId());
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/tablettes');
        self::assertRevisionsMoved($before, 0, 1, $event->getId(), 'la caisse reçoit ses nouvelles catégories');

        self::assertSame(['Loto'], $this->findDevice('Caisse entrée')->getCategories());
        $log = self::entityManager()->getRepository(AuditLog::class)->findOneBy(['action' => 'device.update']);
        self::assertSame(['categories' => 'tout le menu', 'direct_sale' => false], $log?->getBefore());
        self::assertSame(['categories' => 'Loto', 'direct_sale' => true], $log->getAfter());

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Aucune caisse ne vend la catégorie « Boissons »', $crawler->filterXPath('//*[@id="unsold-categories"]')->text());
        self::assertStringContainsString('vente directe', $this->content());

        $this->client->request('GET', '/admin/tablettes/'.$kitchen->getId().'/reglages');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/admin/tablettes/'.$till->getId().'/reglages', ['_token' => 'jeton-invalide']);
        self::assertResponseStatusCodeSame(403);
    }

    /** @param array<string, string> $categories catégorie par nom d'article */
    private function menu(Event $event, array $categories): void
    {
        foreach ($categories as $name => $category) {
            self::entityManager()->persist((new Product($event, $name))->setCategory($category));
        }
        self::entityManager()->flush();
    }

    private function clickInRow(string $name, string $button): void
    {
        $crawler = $this->client->request('GET', '/admin/tablettes');
        $row = $crawler->filterXPath(\sprintf('//tr[td[1][normalize-space()="%s"]]', $name));
        $this->client->submit($row->selectButton($button)->form());
    }

    public function testRevokedDevicesAreHiddenUntilAsked(): void
    {
        $event = self::createEvent();
        self::createDeviceWithCode($event, 'Caisse buvette');
        [$old] = self::createDeviceWithCode($event, 'Vieux téléphone');
        $old->setRevokedAt(new \DateTimeImmutable());
        self::entityManager()->flush();

        $crawler = $this->client->request('GET', '/admin/tablettes');
        self::assertStringContainsString('Caisse buvette', $this->content());
        self::assertStringNotContainsString('Vieux téléphone', $this->content());
        $link = $crawler->filterXPath('//*[@class="revoked-toggle"]/a');
        self::assertSame('Afficher les tablettes retirées (1)', $link->text());

        $this->client->request('GET', (string) $link->attr('href'));
        self::assertStringContainsString('Vieux téléphone', $this->content());
        self::assertStringContainsString('Masquer les tablettes retirées (1)', $this->content());
    }

    public function testNoToggleWithoutRevokedDevices(): void
    {
        self::createDeviceWithCode(self::createEvent());

        $crawler = $this->client->request('GET', '/admin/tablettes');
        self::assertCount(0, $crawler->filterXPath('//*[@class="revoked-toggle"]'));
    }

    public function testDeleteNeverPairedDeviceAfterConfirmation(): void
    {
        [$device] = self::createDeviceWithCode(self::createEvent(), 'Téléphone de Paul');
        $id = $device->getId();

        $crawler = $this->client->request('GET', '/admin/tablettes');
        $this->client->click($crawler->filterXPath('//a[@href="/admin/tablettes/'.$id.'/supprimer"]')->link());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Supprimer la tablette « Téléphone de Paul » ?', $this->content());
        self::assertNotNull($this->findDevice('Téléphone de Paul'), 'rien avant la confirmation');

        $this->client->submit($this->client->getCrawler()->selectButton('Supprimer la tablette')->form());
        self::assertResponseRedirects('/admin/tablettes');
        self::assertNull($this->findDevice('Téléphone de Paul'));
        $log = self::entityManager()->getRepository(AuditLog::class)->findOneBy(['action' => 'device.delete']);
        self::assertSame('device:'.$id.' Téléphone de Paul', $log?->getSubject());
        self::assertSame(['role' => 'caisse', 'paired' => false, 'revoked' => false], $log->getBefore());
    }

    public function testPairedKitchenCanBeDeletedAndStopsWorking(): void
    {
        $event = self::createEvent();
        [$kitchen, $token] = self::createPairedDevice($event, 'Cuisine', DeviceRole::Kitchen);

        $crawler = $this->client->request('GET', '/admin/tablettes/'.$kitchen->getId().'/supprimer');
        self::assertStringContainsString('elle cessera aussitôt de fonctionner', $this->content());
        $this->client->submit($crawler->selectButton('Supprimer la tablette')->form());
        self::assertNull($this->findDevice('Cuisine'));

        $this->client->request('GET', '/api/orders', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testPairedTillIsRevokedNeverDeleted(): void
    {
        [$till] = self::createPairedDevice(self::createEvent(), 'Caisse buvette');

        $crawler = $this->client->request('GET', '/admin/tablettes');
        self::assertCount(0, $crawler->filterXPath('//a[contains(@href, "/supprimer")]'), 'pas de bouton');

        // Adresse saisie à la main, avec ou sans jeton : refusé, rien n'est supprimé
        $this->client->request('POST', '/admin/tablettes/'.$till->getId().'/supprimer', ['_token' => 'x']);
        self::assertResponseRedirects('/admin/tablettes');
        $this->client->followRedirect();
        self::assertStringContainsString('une caisse appairée se révoque', $this->content());
        self::assertNotNull($this->findDevice('Caisse buvette'));
    }

    public function testDeleteRefusesInvalidCsrfToken(): void
    {
        [$device] = self::createDeviceWithCode(self::createEvent(), 'Téléphone de Paul');

        $this->client->request('POST', '/admin/tablettes/'.$device->getId().'/supprimer', ['_token' => 'faux']);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->findDevice('Téléphone de Paul'));
    }

    private function findDevice(string $name): ?Device
    {
        self::entityManager()->clear();

        return self::entityManager()->getRepository(Device::class)->findOneBy(['name' => $name]);
    }

    private function content(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
