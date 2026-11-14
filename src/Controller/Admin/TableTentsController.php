<?php

namespace App\Controller\Admin;

use App\Service\TableTents;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Chevalets de table à imprimer pour le service à table : rien n'est enregistré,
 * la page se calcule à partir du nombre de rangées et de tables par rangée.
 */
#[IsGranted('ROLE_ADMIN')]
class TableTentsController extends AbstractController
{
    #[Route('/admin/chevalets', name: 'admin_table_tents', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $query = $request->query;
        $submitted = $query->has('rangees') || $query->has('tables');
        $values = [
            'rows' => trim($query->getString('rangees', '8')),
            'tables' => trim($query->getString('tables', '8')),
        ];
        $errors = [];
        $rows = self::readInt($values['rows'], TableTents::MAX_ROWS);
        $tables = self::readInt($values['tables'], TableTents::MAX_TABLES_PER_ROW);
        if (null === $rows) {
            $errors['rows'] = \sprintf('Indiquez un nombre de rangées de 1 à %d.', TableTents::MAX_ROWS);
        }
        if (null === $tables) {
            $errors['tables'] = \sprintf('Indiquez un nombre de tables par rangée de 1 à %d.', TableTents::MAX_TABLES_PER_ROW);
        }

        $numbers = $submitted && !$errors ? TableTents::numbers((int) $rows, (int) $tables) : [];

        return $this->render('admin/table_tents.html.twig', [
            'values' => $values,
            'errors' => $submitted ? $errors : [],
            'numbers' => $numbers,
            'sheets' => TableTents::sheets($numbers),
            'max_rows' => TableTents::MAX_ROWS,
            'max_tables' => TableTents::MAX_TABLES_PER_ROW,
        ], new Response(status: $submitted && $errors ? 422 : 200));
    }

    private static function readInt(string $value, int $max): ?int
    {
        if (!preg_match('/^[1-9][0-9]{0,1}$/', $value)) {
            return null;
        }

        return (int) $value <= $max ? (int) $value : null;
    }
}
