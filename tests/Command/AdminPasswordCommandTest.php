<?php

namespace App\Tests\Command;

use App\Command\AdminPasswordCommand;
use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Repository\AdminUserRepository;
use App\Tests\ResetDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AdminPasswordCommandTest extends WebTestCase
{
    use ResetDatabaseTrait;

    private const OLD_PASSWORD = 'ancien-mot-de-passe';
    private const NEW_PASSWORD = 'nouveau-mot-de-passe';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::resetDatabase();
    }

    public function testReplacesPasswordOfTheOnlyAdmin(): void
    {
        $this->createAdmin('camille');

        $tester = $this->runCommand([self::NEW_PASSWORD, self::NEW_PASSWORD]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('« camille »', $tester->getDisplay());
        self::assertFalse($this->isPassword('camille', self::OLD_PASSWORD));
        self::assertTrue($this->isPassword('camille', self::NEW_PASSWORD));

        $log = $this->entityManager->getRepository(AuditLog::class)->findOneBy(['action' => 'admin.password']);
        self::assertSame('console', $log?->getActor());
        self::assertSame('admin:camille', $log->getSubject());
        self::assertNull($log->getBefore(), 'aucun mot de passe ni empreinte dans l\'historique');
        self::assertNull($log->getAfter());
    }

    public function testChoosesAdminByUsername(): void
    {
        $this->createAdmin('camille');
        $this->createAdmin('dominique');

        $tester = $this->runCommand([self::NEW_PASSWORD, self::NEW_PASSWORD], 'dominique');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertTrue($this->isPassword('dominique', self::NEW_PASSWORD));
        self::assertTrue($this->isPassword('camille', self::OLD_PASSWORD));
    }

    public function testAsksForUsernameWhenSeveralAdmins(): void
    {
        $this->createAdmin('camille');
        $this->createAdmin('dominique');

        $tester = $this->runCommand([self::NEW_PASSWORD, self::NEW_PASSWORD]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('plusieurs administrateurs', self::display($tester));
        self::assertStringContainsString('camille, dominique', self::display($tester));
    }

    public function testUnknownUsernameListsExistingOnes(): void
    {
        $this->createAdmin('camille');

        $tester = $this->runCommand([self::NEW_PASSWORD, self::NEW_PASSWORD], 'inconnu');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('« inconnu »', self::display($tester));
        self::assertStringContainsString('existants : camille', self::display($tester));
        self::assertTrue($this->isPassword('camille', self::OLD_PASSWORD));
    }

    public function testFailsWithoutAdmin(): void
    {
        $tester = $this->runCommand([self::NEW_PASSWORD, self::NEW_PASSWORD]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('app:install', $tester->getDisplay());
    }

    public function testKeepsPasswordWhenConfirmationDiffers(): void
    {
        $this->createAdmin('camille');

        $tester = $this->runCommand([self::NEW_PASSWORD, 'autre-mot-de-passe']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertTrue($this->isPassword('camille', self::OLD_PASSWORD));
        self::assertSame(0, $this->entityManager->getRepository(AuditLog::class)->count(['action' => 'admin.password']));
    }

    public function testRefusesShortPassword(): void
    {
        $this->createAdmin('camille');

        $tester = $this->runCommand(['trop-court', self::NEW_PASSWORD, self::NEW_PASSWORD]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('au moins 12 caractères', $tester->getDisplay());
    }

    public function testRefusesNonInteractiveMode(): void
    {
        $this->createAdmin('camille');

        $tester = $this->runCommand([], interactive: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertTrue($this->isPassword('camille', self::OLD_PASSWORD));
    }

    public function testOpenSessionIsClosedAfterChange(): void
    {
        $this->client->loginUser($this->createAdmin('camille'));
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $this->runCommand([self::NEW_PASSWORD, self::NEW_PASSWORD]);

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/login');
    }

    private function createAdmin(string $username): AdminUser
    {
        $admin = new AdminUser($username);
        $admin->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($admin, self::OLD_PASSWORD));
        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        return $admin;
    }

    private function isPassword(string $username, string $password): bool
    {
        $this->entityManager->clear();
        $admin = $this->entityManager->getRepository(AdminUser::class)->findOneBy(['username' => $username]);

        return self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($admin, $password);
    }

    /** Sortie sans les retours à la ligne ajoutés par la console */
    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /** @param list<string> $inputs */
    private function runCommand(array $inputs, ?string $username = null, bool $interactive = true): CommandTester
    {
        $container = self::getContainer();
        $tester = new CommandTester(new AdminPasswordCommand(
            $container->get(AdminUserRepository::class),
            $this->entityManager,
            $container->get(UserPasswordHasherInterface::class),
        ));
        $tester->setInputs($inputs);
        $tester->execute(null === $username ? [] : ['identifiant' => $username], ['interactive' => $interactive]);

        return $tester;
    }
}
