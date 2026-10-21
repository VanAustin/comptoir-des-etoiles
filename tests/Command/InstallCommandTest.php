<?php

namespace App\Tests\Command;

use App\Command\InstallCommand;
use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Repository\AdminUserRepository;
use App\Tests\ResetDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class InstallCommandTest extends KernelTestCase
{
    use ResetDatabaseTrait;

    private const PASSWORD = 'un-mot-de-passe-solide';

    private EntityManagerInterface $entityManager;
    private string $envLocalPath;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::resetDatabase();
        $this->envLocalPath = sys_get_temp_dir().'/env-local-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->envLocalPath);
        parent::tearDown();
    }

    public function testCreatesAdminWithHashedPasswordAndAppSecret(): void
    {
        $tester = $this->runInstall(['camille', self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $admin = $this->entityManager->getRepository(AdminUser::class)->findOneBy(['username' => 'camille']);
        self::assertNotNull($admin);
        self::assertNotSame(self::PASSWORD, $admin->getPassword());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($admin, self::PASSWORD));
        self::assertSame(['ROLE_ADMIN'], $admin->getRoles());

        self::assertMatchesRegularExpression('/^APP_SECRET=[0-9a-f]{64}\n$/', (string) file_get_contents($this->envLocalPath));
        self::assertSame(0600, fileperms($this->envLocalPath) & 0777);

        self::assertSame(1, $this->entityManager->getRepository(AuditLog::class)->count(['action' => 'admin.create']));
    }

    public function testKeepsExistingAppSecret(): void
    {
        file_put_contents($this->envLocalPath, "APP_DEBUG=0\nAPP_SECRET=secret-existant");

        $this->runInstall(['camille', self::PASSWORD, self::PASSWORD]);

        self::assertSame("APP_DEBUG=0\nAPP_SECRET=secret-existant", file_get_contents($this->envLocalPath));
    }

    public function testAddsAppSecretWhenEnvLocalHasNone(): void
    {
        // Une ligne APP_SECRET vide ne compte pas comme un secret défini
        file_put_contents($this->envLocalPath, "APP_SECRET=\nAPP_DEBUG=0");

        $this->runInstall(['camille', self::PASSWORD, self::PASSWORD]);

        self::assertMatchesRegularExpression('/^APP_SECRET=\nAPP_DEBUG=0\nAPP_SECRET=[0-9a-f]{64}\n$/', (string) file_get_contents($this->envLocalPath));
    }

    public function testRefusesShortPasswordThenAcceptsValidOne(): void
    {
        $tester = $this->runInstall(['camille', 'trop-court', self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('au moins 12 caractères', $tester->getDisplay());
    }

    public function testRefusesInvalidUsernameThenAcceptsValidOne(): void
    {
        $tester = $this->runInstall(['é', 'camille', self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('au moins 3 caractères', $tester->getDisplay());
    }

    public function testFailsWhenConfirmationDiffers(): void
    {
        $tester = $this->runInstall(['camille', self::PASSWORD, 'autre-mot-de-passe']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(0, $this->adminCount());
    }

    public function testRefusesSecondAdmin(): void
    {
        $this->runInstall(['camille', self::PASSWORD, self::PASSWORD]);
        $tester = $this->runInstall(['dominique', self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('existe déjà', $tester->getDisplay());
        self::assertSame(1, $this->adminCount());
    }

    public function testRestoresLostAppSecretWhenAdminExists(): void
    {
        $this->runInstall(['camille', self::PASSWORD, self::PASSWORD]);
        unlink($this->envLocalPath);

        $tester = $this->runInstall([], interactive: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/^APP_SECRET=[0-9a-f]{64}\n$/', (string) file_get_contents($this->envLocalPath));
        self::assertSame(1, $this->adminCount());
    }

    public function testRefusesNonInteractiveMode(): void
    {
        $tester = $this->runInstall([], interactive: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(0, $this->adminCount());
        self::assertFileDoesNotExist($this->envLocalPath);
    }

    /** @param list<string> $inputs */
    private function runInstall(array $inputs, bool $interactive = true): CommandTester
    {
        $container = self::getContainer();
        $command = new InstallCommand(
            $container->get(AdminUserRepository::class),
            $this->entityManager,
            $container->get(UserPasswordHasherInterface::class),
            $this->envLocalPath,
        );

        $tester = new CommandTester($command);
        $tester->setInputs($inputs);
        $tester->execute([], ['interactive' => $interactive]);

        return $tester;
    }

    private function adminCount(): int
    {
        return $this->entityManager->getRepository(AdminUser::class)->count();
    }
}
