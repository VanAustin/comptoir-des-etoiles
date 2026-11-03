<?php

namespace App\Command;

/** Import différent du bloc « expected » : annule la transaction de l'import. */
final class ImportMismatchException extends \RuntimeException
{
    /** @param list<string> $gaps */
    public function __construct(public readonly array $gaps)
    {
        parent::__construct('Import différent du résultat attendu.');
    }
}
