<?php

namespace App\Tests\EventListener;

use App\EventListener\SessionIdleListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class SessionIdleListenerTest extends TestCase
{
    public function testSessionIsClosedAfterTwoHoursWithoutActivity(): void
    {
        $session = $this->runListener(lastUsedSecondsAgo: SessionIdleListener::MAX_IDLE_SECONDS + 60);

        self::assertFalse($session->has('_security_main'));
        self::assertNotEmpty($session->getFlashBag()->peek('info'));
    }

    public function testNoExpiryMessageWhenRememberMeCookieWillReopenTheSession(): void
    {
        $session = $this->runListener(lastUsedSecondsAgo: SessionIdleListener::MAX_IDLE_SECONDS + 60, rememberMe: true);

        self::assertFalse($session->has('_security_main'), 'la session est fermée : le cookie en rouvre une, vérifiée par Symfony');
        self::assertEmpty($session->getFlashBag()->peek('info'));
    }

    public function testRecentSessionIsKept(): void
    {
        $session = $this->runListener(lastUsedSecondsAgo: SessionIdleListener::MAX_IDLE_SECONDS - 60);

        self::assertTrue($session->has('_security_main'));
        self::assertEmpty($session->getFlashBag()->peek('info'));
    }

    private function runListener(int $lastUsedSecondsAgo, bool $rememberMe = false): Session
    {
        $storage = new MockArraySessionStorage();
        $storage->setSessionData([
            '_sf2_meta' => ['u' => time() - $lastUsedSecondsAgo, 'c' => time() - 10000, 'l' => 0],
            '_sf2_attributes' => ['_security_main' => 'jeton-de-connexion'],
        ]);
        $session = new Session($storage);

        $request = Request::create('/admin');
        $request->setSession($session);
        $request->cookies->set($session->getName(), 'identifiant-de-session');
        if ($rememberMe) {
            $request->cookies->set(SessionIdleListener::REMEMBER_ME_COOKIE, 'cookie-de-connexion');
        }

        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        (new SessionIdleListener())($event);

        return $session;
    }
}
