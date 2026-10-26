<?php

namespace App\Command;

use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Saisie masquée d'un nouveau mot de passe administrateur, avec confirmation.
 * Mêmes règles pour app:install et app:admin:password.
 */
final class NewPasswordPrompt
{
    public const MIN_LENGTH = 12;
    private const MAX_LENGTH = 4096;
    private const MAX_ATTEMPTS = 3;

    /** @return string|null le mot de passe, ou null si la confirmation diffère */
    public static function ask(SymfonyStyle $io): ?string
    {
        $question = new Question(\sprintf('Choisissez un mot de passe (%d caractères minimum ; rien ne s\'affiche pendant la saisie, c\'est normal)', self::MIN_LENGTH));
        $question->setHidden(true);
        $question->setMaxAttempts(self::MAX_ATTEMPTS);
        $question->setValidator(static function (?string $value): string {
            $length = mb_strlen((string) $value);
            if ($length < self::MIN_LENGTH) {
                throw new \RuntimeException(\sprintf('Le mot de passe doit faire au moins %d caractères.', self::MIN_LENGTH));
            }
            if ($length > self::MAX_LENGTH) {
                throw new \RuntimeException('Le mot de passe est trop long.');
            }

            return (string) $value;
        });
        $password = $io->askQuestion($question);

        $confirmation = new Question('Saisissez à nouveau le même mot de passe');
        $confirmation->setHidden(true);

        return hash_equals($password, (string) $io->askQuestion($confirmation)) ? $password : null;
    }
}
