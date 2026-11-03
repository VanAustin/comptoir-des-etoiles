<?php

namespace App\Tests\Controller\Admin;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Entity\Device;
use App\Tests\FixturesTrait;
use App\Tests\ResetDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DeviceControllerTest extends WebTestCase
{
    use FixturesTrait;
    use ResetDatabaseTrait;

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

    private function clickInRow(string $name, string $button): void
    {
        $crawler = $this->client->request('GET', '/admin/tablettes');
        $row = $crawler->filterXPath(\sprintf('//tr[td[1][normalize-space()="%s"]]', $name));
        $this->client->submit($row->selectButton($button)->form());
    }

    private function findDevice(string $name): Device
    {
        self::entityManager()->clear();

        return self::entityManager()->getRepository(Device::class)->findOneBy(['name' => $name]);
    }

    private function content(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
