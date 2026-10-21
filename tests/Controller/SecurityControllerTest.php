<?php

namespace App\Tests\Controller;

use App\Entity\AdminUser;
use App\Tests\ResetDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SecurityControllerTest extends WebTestCase
{
    use ResetDatabaseTrait;

    private const PASSWORD = 'un-mot-de-passe-solide';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $entityManager = self::resetDatabase();

        // Les compteurs de tentatives survivent d'un test à l'autre : on les vide
        self::getContainer()->get('cache.rate_limiter')->clear();

        $admin = new AdminUser('camille');
        $admin->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($admin, self::PASSWORD));
        $entityManager->persist($admin);
        $entityManager->flush();
    }

    public function testAdminRedirectsAnonymousVisitorToLogin(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/login');
    }

    public function testLoginWithValidCredentials(): void
    {
        $this->login('camille', self::PASSWORD);

        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $this->assertClassContains('who', 'camille');
    }

    public function testLoginWithWrongPassword(): void
    {
        $this->login('camille', 'mauvais-mot-de-passe');

        $this->client->followRedirect();
        $this->assertClassContains('alert-error', 'Identifiant ou mot de passe incorrect.');
        $this->assertNotLoggedIn();
    }

    public function testLoginRejectsInvalidCsrfToken(): void
    {
        $this->client->request('POST', '/login', [
            '_username' => 'camille',
            '_password' => self::PASSWORD,
            '_csrf_token' => 'jeton-invalide',
        ]);

        $this->client->followRedirect();
        $this->assertClassContains('alert-error', 'La page de connexion a expiré');
        $this->assertNotLoggedIn();
    }

    public function testLoginIsBlockedAfterFiveFailedAttempts(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->login('camille', 'mauvais-mot-de-passe');
        }

        // Même avec le bon mot de passe, la sixième tentative est refusée
        $this->login('camille', self::PASSWORD);

        $this->client->followRedirect();
        $this->assertClassContains('alert-error', 'Trop de tentatives');
        $this->assertNotLoggedIn();
    }

    public function testLogoutRequiresCsrfToken(): void
    {
        $this->login('camille', self::PASSWORD);

        $this->client->request('POST', '/logout', ['_csrf_token' => 'jeton-invalide']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
    }

    public function testLogoutWithButton(): void
    {
        $this->login('camille', self::PASSWORD);
        $crawler = $this->client->followRedirect();

        $this->client->submit($crawler->selectButton('Se déconnecter')->form());

        self::assertResponseRedirects('/');
        $this->assertNotLoggedIn();
    }

    public function testSessionCookieIsHttpOnlyAndSameSiteLax(): void
    {
        $this->login('camille', self::PASSWORD);

        $cookie = $this->client->getResponse()->headers->getCookies()[0] ?? null;
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());
    }

    public function testHomeAndLogoArePublic(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/logo');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
    }

    private function login(string $username, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => $username,
            '_password' => $password,
        ]));
    }

    /** Équivalent de assertSelectorTextContains('.classe', …) sans le composant css-selector. */
    private function assertClassContains(string $class, string $text): void
    {
        $nodes = $this->client->getCrawler()->filterXPath(\sprintf('//*[contains(concat(" ", normalize-space(@class), " "), " %s ")]', $class));
        self::assertGreaterThan(0, $nodes->count(), \sprintf('Aucun élément avec la classe « %s ».', $class));
        self::assertStringContainsString($text, $nodes->text());
    }

    private function assertNotLoggedIn(): void
    {
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/login');
    }
}
