<?php

namespace App\Tests\Realtime;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/**
 * Hub Mercure des tests (config/services.yaml, when@test) : garde les publications
 * au lieu de les envoyer, ou échoue à la demande pour simuler un hub injoignable.
 */
final class RecordingHub implements HubInterface
{
    /** @var list<Update> */
    public static array $updates = [];
    public static bool $unreachable = false;

    public static function reset(): void
    {
        self::$updates = [];
        self::$unreachable = false;
    }

    /** @return list<string> sujets publiés, dans l'ordre */
    public static function topics(): array
    {
        return array_merge(...array_map(static fn (Update $u): array => $u->getTopics(), self::$updates));
    }

    public function getPublicUrl(): string
    {
        return 'http://hub.test/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    public function publish(Update $update): string
    {
        if (self::$unreachable) {
            throw new \RuntimeException('Hub injoignable (test)');
        }
        self::$updates[] = $update;

        return 'urn:uuid:'.\count(self::$updates);
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return ProtocolVersion::V1;
    }

    public function getCookieName(): string
    {
        return 'mercureAuthorization';
    }
}
