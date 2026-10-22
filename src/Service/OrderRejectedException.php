<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Response;

/** Commande refusée par le serveur ; le message s'adresse au bénévole. */
class OrderRejectedException extends \RuntimeException
{
    /** @param array<string, mixed> $details informations utiles à la tablette (prochain numéro libre…) */
    public function __construct(string $message, public readonly int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
