<?php

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Environnement prod : le cache se prépare et les pages s'affichent.
 * Dans un processus à part : les autres tests tournent en environnement test et ne
 * verraient jamais une configuration réservée à la production.
 */
final class ProductionBootTest extends TestCase
{
    private const ENV = [
        'APP_ENV' => 'prod',
        'APP_DEBUG' => '0',
        'APP_SECRET' => 'secret-de-test-production',
    ];

    public function testCacheClearAndPagesWorkInProduction(): void
    {
        [$code, $output] = self::execute([\PHP_BINARY, 'bin/console', 'cache:clear', '--no-interaction']);
        self::assertSame(0, $code, $output);

        $script = <<<'PHP'
            require 'vendor/autoload.php';
            (new Symfony\Component\Dotenv\Dotenv())->bootEnv('.env');
            $response = (new App\Kernel('prod', false))->handle(Symfony\Component\HttpFoundation\Request::create('/login'), 1, false);
            fwrite(STDERR, (string) $response->getStatusCode());
            PHP;
        [$code, $output] = self::execute([\PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script]);
        self::assertSame(0, $code, $output);
        self::assertSame('200', trim($output));
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string} code de sortie, sorties d'erreur et standard
     */
    private static function execute(array $command): array
    {
        $env = self::ENV + getenv();
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, \dirname(__DIR__), $env);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stderr.$stdout];
    }
}
