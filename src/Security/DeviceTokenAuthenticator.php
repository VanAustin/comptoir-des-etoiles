<?php

namespace App\Security;

use App\Service\DevicePairing;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Authentifie les tablettes par l'en-tête « Authorization: Bearer <jeton> ».
 * Le jeton n'est jamais lu ailleurs (ni dans l'URL, ni dans un cookie).
 */
class DeviceTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    private const UNKNOWN_TOKEN = 'Cette tablette n\'est pas reconnue. Appairez-la à nouveau depuis l\'administration.';

    public function __construct(private readonly DevicePairing $pairing)
    {
    }

    public function supports(Request $request): ?bool
    {
        return str_starts_with((string) $request->headers->get('Authorization'), 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $token = substr((string) $request->headers->get('Authorization'), \strlen('Bearer '));
        $device = $this->pairing->findDeviceByToken(trim($token));
        $now = new \DateTimeImmutable();

        if (null === $device) {
            throw new CustomUserMessageAuthenticationException(self::UNKNOWN_TOKEN);
        }
        if ($device->isRevoked()) {
            throw new CustomUserMessageAuthenticationException('Cette tablette a été retirée depuis l\'administration. Appairez-la à nouveau pour continuer.');
        }
        if ($device->isExpired($now)) {
            throw new CustomUserMessageAuthenticationException('L\'appairage de cette tablette a expiré à la fin de l\'événement. Appairez-la à nouveau.');
        }
        if (!$device->getEvent()->isActive()) {
            throw new CustomUserMessageAuthenticationException('L\'événement de cette tablette n\'est plus l\'événement actif. Appairez-la au nouvel événement.');
        }

        return new SelfValidatingPassport(new UserBadge($device->getUserIdentifier(), static fn () => $device));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $message = $exception instanceof CustomUserMessageAuthenticationException ? $exception->getMessageKey() : self::UNKNOWN_TOKEN;

        return new JsonResponse(['error' => $message], Response::HTTP_UNAUTHORIZED);
    }

    /** Requête sans jeton sur une route protégée. */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(['error' => 'Cette tablette n\'est pas appairée. Appairez-la depuis l\'administration.'], Response::HTTP_UNAUTHORIZED);
    }
}
