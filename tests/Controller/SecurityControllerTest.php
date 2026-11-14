<?php

namespace App\Tests\Controller;

use App\Entity\AdminUser;
use App\Tests\ResetDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;
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

    public function testLoginWithoutRememberMeSetsNoRememberMeCookie(): void
    {
        $this->login('camille', self::PASSWORD);

        self::assertResponseRedirects('/admin');
        self::assertNull($this->rememberMeCookie());
    }

    public function testRememberMeCookieIsHttpOnlyLaxAndLasts30Days(): void
    {
        $this->login('camille', self::PASSWORD, rememberMe: true);

        $cookie = $this->rememberMeCookie();
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());
        self::assertEqualsWithDelta(time() + 30 * 86400, $cookie->getExpiresTime(), 60);
    }

    public function testRememberMeCookieIsSecureOverHttps(): void
    {
        $this->client->setServerParameter('HTTPS', 'on');
        $this->login('camille', self::PASSWORD, rememberMe: true);

        self::assertTrue($this->rememberMeCookie()?->isSecure());
    }

    public function testRememberMeCookieAloneReopensTheAdministration(): void
    {
        $this->login('camille', self::PASSWORD, rememberMe: true);
        $this->keepOnlyRememberMeCookie();

        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $this->assertClassContains('who', 'camille');
    }

    public function testWithoutRememberMeClosingTheBrowserLogsOut(): void
    {
        $this->login('camille', self::PASSWORD);
        $this->client->getCookieJar()->clear();

        $this->assertNotLoggedIn();
    }

    public function testLogoutDeletesRememberMeCookie(): void
    {
        $this->login('camille', self::PASSWORD, rememberMe: true);
        $crawler = $this->client->followRedirect();

        $this->client->submit($crawler->selectButton('Se déconnecter')->form());

        self::assertNull($this->client->getCookieJar()->get('REMEMBERME'));
        $this->assertNotLoggedIn();
    }

    public function testLoginPageHasPasswordToggleAndUncheckedRememberMe(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $toggle = $crawler->filterXPath('//button[@aria-controls="password"]');
        self::assertSame('false', $toggle->attr('aria-pressed'));
        self::assertSame('Afficher le mot de passe', $toggle->attr('aria-label'));
        self::assertNotNull($toggle->attr('hidden'), 'masqué sans JavaScript');
        self::assertSame(1, $crawler->filterXPath('//script[contains(@src, "/js/password-toggle.js")]')->count());

        $rememberMe = $crawler->filterXPath('//input[@name="_remember_me"]');
        self::assertSame('checkbox', $rememberMe->attr('type'));
        self::assertNull($rememberMe->attr('checked'));
    }

    public function testHomeAndLogoArePublic(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/logo');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
    }

    private function login(string $username, string $password, bool $rememberMe = false): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => $username,
            '_password' => $password,
        ]);
        if ($rememberMe) {
            $form['_remember_me']->tick();
        }
        $this->client->submit($form);
    }

    private function rememberMeCookie(): ?Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            // Symfony envoie aussi un cookie vide et périmé, qui efface un ancien cookie
            if ('REMEMBERME' === $cookie->getName() && !$cookie->isCleared()) {
                return $cookie;
            }
        }

        return null;
    }

    /** Comme un navigateur fermé puis rouvert : la session est perdue, le cookie de 30 jours reste. */
    private function keepOnlyRememberMeCookie(): void
    {
        $jar = $this->client->getCookieJar();
        $rememberMe = $jar->get('REMEMBERME');
        self::assertNotNull($rememberMe);
        $jar->clear();
        $jar->set($rememberMe);
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
