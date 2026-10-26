<?php

namespace App\Tests\Controller\Admin;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Entity\Event;
use App\Tests\ResetDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class EventControllerTest extends WebTestCase
{
    use ResetDatabaseTrait;

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
        $this->client->submit($crawler->selectButton('Enregistrer l\'événement')->form([
            'name' => 'Loto de Noël',
            'date' => '2026-12-19',
            'deposit' => '1,50',
        ]));

        self::assertResponseRedirects('/admin/evenements');
        $this->entityManager->clear();
        $event = $this->findEvent('Loto de Noël');
        self::assertSame('2026-12-19', $event?->getDate()->format('Y-m-d'));
        self::assertSame(150, $event->getDepositCents());

        $log = $this->entityManager->getRepository(AuditLog::class)->findOneBy(['action' => 'event.update']);
        self::assertSame('admin:camille', $log?->getActor());
        // assertEquals : MySQL ne garde pas l'ordre des clés d'une colonne JSON
        self::assertEquals(['name' => 'Loto', 'date' => '2026-12-18', 'deposit_cents' => 0], $log->getBefore());
        self::assertEquals(['name' => 'Loto de Noël', 'date' => '2026-12-19', 'deposit_cents' => 150], $log->getAfter());
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

    private function findEvent(string $name): ?Event
    {
        return $this->entityManager->getRepository(Event::class)->findOneBy(['name' => $name]);
    }

    private function auditCount(string $action): int
    {
        return $this->entityManager->getRepository(AuditLog::class)->count(['action' => $action]);
    }
}
