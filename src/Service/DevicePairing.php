<?php

namespace App\Service;

use App\Entity\Device;
use App\Entity\Event;
use App\Enum\DeviceRole;
use App\Repository\DeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Codes d'appairage, jetons des tablettes et lettres de caisse.
 *
 * Le serveur ne garde que des empreintes SHA-256 : ni le code ni le jeton
 * ne sont jamais enregistrés en clair.
 */
class DevicePairing
{
    /** Sans 0, O, 1, I ni L, pour éviter les confusions à la saisie. */
    public const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    public const CODE_LENGTH = 8;
    public const CODE_LIFETIME = '+10 minutes';

    /** Lettres de caisse, sans I ni O (confusion avec 1 et 0). */
    public const TILL_LETTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const TOKEN_BYTES = 32;
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        #[Autowire('%app.timezone%')]
        private readonly string $timezone,
    ) {
    }

    /**
     * Crée un nouveau code pour une tablette pas encore appairée et le renvoie en clair,
     * pour un affichage unique. Le code précédent ne fonctionne plus.
     */
    public function generateCode(Device $device): string
    {
        if ($device->isPaired() || $device->isRevoked()) {
            throw new \LogicException('Un code ne peut être généré que pour une tablette en attente d\'appairage.');
        }

        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; ++$i) {
            $code .= self::CODE_ALPHABET[random_int(0, \strlen(self::CODE_ALPHABET) - 1)];
        }

        $device->setPairingCodeHash(self::hash($code));
        $device->setPairingCodeExpiresAt(new \DateTimeImmutable(self::CODE_LIFETIME));

        return $code;
    }

    /** « K7QM3XRP » s'affiche « K7QM-3XRP ». */
    public static function formatCode(string $code): string
    {
        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    /**
     * Échange un code contre un jeton. Renvoie la tablette et le jeton en clair,
     * que seule la tablette conservera.
     *
     * @return array{Device, string}
     *
     * @throws PairingException
     */
    public function pair(string $rawCode): array
    {
        $now = new \DateTimeImmutable();
        $code = self::normalizeCode($rawCode);
        $device = null === $code ? null : $this->devices->findOneByPairingCodeHash(self::hash($code));

        if (null === $device || !hash_equals((string) $device->getPairingCodeHash(), self::hash($code)) || $device->isRevoked()) {
            throw new PairingException('Ce code n\'est pas valide, ou il a déjà servi. Vérifiez-le, ou demandez un nouveau code dans l\'administration.');
        }
        if (!$device->hasValidPairingCode($now)) {
            throw new PairingException('Ce code a expiré. Demandez un nouveau code dans l\'administration.');
        }

        $event = $device->getEvent();
        if (!$event->isActive()) {
            throw new PairingException('L\'événement de cette tablette n\'est plus l\'événement actif. Activez-le, ou ajoutez la tablette au nouvel événement.');
        }
        if (!$device->isRoleAvailable()) {
            throw new PairingException('Cet événement est sans cuisine : seules les tablettes caisse peuvent être appairées.');
        }
        $expiresAt = $this->tokenExpiry($event);
        if ($expiresAt <= $now) {
            throw new PairingException('Cet événement est terminé : une tablette ne peut plus y être appairée.');
        }

        if (DeviceRole::Till === $device->getRole()) {
            $device->setTillLetter($this->nextTillLetter($event));
        }

        $token = rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
        $device->setTokenHash(self::hash($token));
        $device->setExpiresAt($expiresAt);
        // Code à usage unique
        $device->setPairingCodeHash(null);
        $device->setPairingCodeExpiresAt(null);

        $this->auditLogger->log('device.pair', 'device:'.$device->getId().' '.$device->getName(), after: [
            'role' => $device->getRole()->value,
            'till_letter' => $device->getTillLetter(),
            'expires_at' => $expiresAt->format(\DATE_ATOM),
        ], actor: $device);
        $this->entityManager->flush();

        return [$device, $token];
    }

    /**
     * Retrouve la tablette d'un jeton, sans vérifier son état (voir DeviceTokenAuthenticator).
     */
    public function findDeviceByToken(string $token): ?Device
    {
        if (!preg_match(self::TOKEN_PATTERN, $token)) {
            return null;
        }

        $hash = self::hash($token);
        $device = $this->devices->findOneByTokenHash($hash);

        return null !== $device && hash_equals((string) $device->getTokenHash(), $hash) ? $device : null;
    }

    /** Le jeton expire le lendemain de l'événement à 6 h, heure de l'association. */
    public function tokenExpiry(Event $event): \DateTimeImmutable
    {
        $local = new \DateTimeImmutable($event->getDate()->format('Y-m-d').' 06:00:00', new \DateTimeZone($this->timezone));

        return $local->modify('+1 day')->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /** Première lettre jamais attribuée pour l'événement, même à une tablette révoquée. */
    public function nextTillLetter(Event $event): string
    {
        $used = $this->devices->findUsedTillLetters($event);
        foreach (str_split(self::TILL_LETTERS) as $letter) {
            if (!\in_array($letter, $used, true)) {
                return $letter;
            }
        }

        throw new PairingException('Toutes les lettres de caisse sont déjà utilisées pour cet événement.');
    }

    private static function normalizeCode(string $rawCode): ?string
    {
        $code = strtoupper(str_replace([' ', '-'], '', trim($rawCode)));

        return self::CODE_LENGTH === \strlen($code) && \strlen($code) === strspn($code, self::CODE_ALPHABET) ? $code : null;
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
