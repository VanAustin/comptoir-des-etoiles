<?php

namespace App\Enum;

/**
 * Type d'une ligne de commande. La consigne n'est pas du chiffre d'affaires :
 * le Carnet de bord sépare ces lignes des articles.
 */
enum LineKind: string
{
    case Article = 'article';
    case Deposit = 'consigne';
    case DepositReturn = 'retour_consigne';
}
