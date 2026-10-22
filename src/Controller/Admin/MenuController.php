<?php

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Entity\Product;
use App\Repository\EventRepository;
use App\Repository\ProductRepository;
use App\Service\AuditLogger;
use App\Service\ExampleMenu;
use App\Service\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Menu de l'événement actif : articles, prix, stocks de départ, passage en cuisine,
 * consigne et formules. Formulaires HTML traités ici, avec CSRF sur chacun.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/menu')]
class MenuController extends AbstractController
{
    private const NAME_MAX_LENGTH = 80;
    private const CATEGORY_MAX_LENGTH = 40;
    private const ICON_MAX_LENGTH = 16;
    private const STOCK_MAX = 99999;
    private const POSITION_MAX = 9999;
    private const DEFAULT_CATEGORY = 'Divers';
    private const EMPTY_NEW_VALUES = ['icon' => '', 'name' => '', 'category' => '', 'price' => '', 'stock' => '', 'kitchen' => false, 'deposit' => false];

    public function __construct(
        private readonly EventRepository $events,
        private readonly ProductRepository $products,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'admin_menu', methods: ['GET'])]
    public function index(): Response
    {
        $event = $this->events->findActive();
        if (null === $event) {
            return $this->render('admin/menu/no_event.html.twig');
        }

        return $this->renderMenu($event);
    }

    /** Enregistre toutes les lignes du tableau d'un coup : tout ou rien. */
    #[Route('', name: 'admin_menu_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyUnlessCsrfValid($request, 'menu-save');
        $event = $this->requireActiveEvent();

        $submitted = $request->getPayload()->all('products');
        $rowValues = [];
        $rowErrors = [];
        $changes = [];

        foreach ($this->products->findForEvent($event) as $product) {
            $input = $submitted[$product->getId()] ?? null;
            if (!\is_array($input)) {
                continue;
            }
            [$values, $errors, $clean] = $this->validate($input, $product->isFormula());
            $rowValues[$product->getId()] = $values;
            if ($errors) {
                $rowErrors[$product->getId()] = $errors;
                continue;
            }
            $changes[] = [$product, $clean];
        }

        if ($rowErrors) {
            return $this->renderMenu($event, $rowValues, $rowErrors, status: 422);
        }

        $updated = 0;
        foreach ($changes as [$product, $clean]) {
            $before = self::snapshot($product);
            self::apply($product, $clean);
            $after = self::snapshot($product);
            $changed = array_keys(array_diff_assoc(array_map('serialize', $after), array_map('serialize', $before)));
            if ($changed) {
                $this->auditLogger->log(
                    'product.update',
                    'product:'.$product->getId().' '.$product->getName(),
                    array_intersect_key($before, array_flip($changed)),
                    array_intersect_key($after, array_flip($changed)),
                );
                ++$updated;
            }
        }
        $this->entityManager->flush();

        $this->addFlash('info', match ($updated) {
            0 => 'Aucune modification à enregistrer.',
            1 => 'Le menu est enregistré : 1 article modifié.',
            default => \sprintf('Le menu est enregistré : %d articles modifiés.', $updated),
        });

        return $this->redirectToRoute('admin_menu');
    }

    #[Route('/ajouter', name: 'admin_menu_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $this->denyUnlessCsrfValid($request, 'menu-add');
        $event = $this->requireActiveEvent();

        [$values, $errors, $clean] = $this->validate($request->getPayload()->all(), isFormula: false, withPosition: false);
        if ($errors) {
            return $this->renderMenu($event, newValues: $values, newErrors: $errors, status: 422);
        }

        $product = new Product($event, $clean['name']);
        self::apply($product, $clean + ['position' => $this->products->nextPosition($event)]);
        $this->entityManager->persist($product);
        $this->entityManager->flush();
        $this->auditLogger->log('product.create', 'product:'.$product->getId().' '.$product->getName(), after: self::snapshot($product));
        $this->entityManager->flush();

        $this->addFlash('info', \sprintf('« %s » est ajouté au menu.', $product->getName()));

        return $this->redirectToRoute('admin_menu');
    }

    #[Route('/{id<\d+>}/composants', name: 'admin_menu_components', methods: ['GET', 'POST'])]
    public function components(Product $product, Request $request): Response
    {
        $event = $this->requireActiveEvent();
        $this->denyUnlessInEvent($product, $event);

        $usedAsComponent = $this->products->isUsedAsComponent($product);
        // Composants possibles : les autres articles, sauf les formules (pas de formule dans une formule)
        $candidates = array_values(array_filter(
            $this->products->findForEvent($event),
            static fn (Product $p) => $p !== $product && !$p->isFormula(),
        ));
        $error = null;

        if ($request->isMethod('POST') && !$usedAsComponent) {
            $this->denyUnlessCsrfValid($request, 'menu-components-'.$product->getId());
            $selectedIds = array_map('intval', $request->getPayload()->all('components'));
            $byId = [];
            foreach ($candidates as $candidate) {
                $byId[$candidate->getId()] = $candidate;
            }

            if (array_diff($selectedIds, array_keys($byId))) {
                $error = 'Un des articles choisis ne peut pas faire partie de cette formule. Rechargez la page, puis réessayez.';
            } else {
                $before = self::componentNames($product);
                foreach ($product->getComponents()->toArray() as $component) {
                    $product->removeComponent($component);
                }
                foreach (array_unique($selectedIds) as $id) {
                    $product->addComponent($byId[$id]);
                }
                // Le stock d'une formule se déduit de celui de ses composants
                if ($product->isFormula()) {
                    $product->setInitialStock(null);
                }
                $this->auditLogger->log('product.components', 'product:'.$product->getId().' '.$product->getName(), ['components' => $before], ['components' => self::componentNames($product)]);
                $this->entityManager->flush();

                $this->addFlash('info', $product->isFormula()
                    ? \sprintf('« %s » est une formule de %d articles.', $product->getName(), $product->getComponents()->count())
                    : \sprintf('« %s » n\'est plus une formule.', $product->getName()));

                return $this->redirectToRoute('admin_menu');
            }
        }

        return $this->render('admin/menu/components.html.twig', [
            'product' => $product,
            'candidates' => $candidates,
            'used_as_component' => $usedAsComponent,
            'error' => $error,
        ], new Response(status: $error ? 422 : 200));
    }

    #[Route('/{id<\d+>}/retirer', name: 'admin_menu_remove', methods: ['GET', 'POST'])]
    public function remove(Product $product, Request $request): Response
    {
        $event = $this->requireActiveEvent();
        $this->denyUnlessInEvent($product, $event);

        if ($request->isMethod('POST')) {
            $this->denyUnlessCsrfValid($request, 'menu-remove-'.$product->getId());
            $this->auditLogger->log('product.delete', 'product:'.$product->getId().' '.$product->getName(), before: self::snapshot($product));
            $this->entityManager->remove($product);
            $this->entityManager->flush();
            $this->addFlash('info', \sprintf('« %s » est retiré du menu.', $product->getName()));

            return $this->redirectToRoute('admin_menu');
        }

        return $this->render('admin/menu/remove.html.twig', [
            'product' => $product,
            'used_as_component' => $this->products->isUsedAsComponent($product),
        ]);
    }

    #[Route('/exemple', name: 'admin_menu_example', methods: ['POST'])]
    public function loadExample(Request $request, ExampleMenu $exampleMenu): Response
    {
        $this->denyUnlessCsrfValid($request, 'menu-example');
        $event = $this->requireActiveEvent();

        if ($this->products->findForEvent($event)) {
            $this->addFlash('error', 'Le menu d\'exemple ne peut être chargé que dans un menu vide.');
        } else {
            $count = $exampleMenu->load($event);
            $this->addFlash('info', \sprintf('Le menu d\'exemple du Loto est chargé : %d articles. Adaptez les prix et les stocks avant l\'événement.', $count));
        }

        return $this->redirectToRoute('admin_menu');
    }

    /**
     * @param array<int, array<string, mixed>>  $rowValues
     * @param array<int, array<string, string>> $rowErrors
     * @param array<string, mixed>|null         $newValues
     * @param array<string, string>             $newErrors
     */
    private function renderMenu(Event $event, array $rowValues = [], array $rowErrors = [], ?array $newValues = null, array $newErrors = [], int $status = 200): Response
    {
        $products = $this->products->findForEvent($event);
        foreach ($products as $product) {
            $rowValues[$product->getId()] ??= self::valuesOf($product);
        }

        return $this->render('admin/menu/index.html.twig', [
            'event' => $event,
            'products' => $products,
            'row_values' => $rowValues,
            'row_errors' => $rowErrors,
            'new_values' => $newValues ?? self::EMPTY_NEW_VALUES,
            'new_errors' => $newErrors,
        ], new Response(status: $status));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{array<string, mixed>, array<string, string>, array<string, mixed>}
     */
    private function validate(array $input, bool $isFormula, bool $withPosition = true): array
    {
        $text = static fn (string $key): string => \is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $values = [
            'position' => $text('position'),
            'icon' => $text('icon'),
            'name' => $text('name'),
            'category' => $text('category'),
            'price' => $text('price'),
            'stock' => $text('stock'),
            'kitchen' => isset($input['kitchen']),
            'deposit' => isset($input['deposit']),
        ];
        $errors = [];
        $clean = [
            'icon' => '' === $values['icon'] ? null : $values['icon'],
            'name' => $values['name'],
            'category' => '' === $values['category'] ? self::DEFAULT_CATEGORY : $values['category'],
            'kitchen' => $values['kitchen'],
            'deposit' => $values['deposit'],
        ];

        if ('' === $values['name']) {
            $errors['name'] = 'Indiquez le nom de l\'article.';
        } elseif (mb_strlen($values['name']) > self::NAME_MAX_LENGTH) {
            $errors['name'] = \sprintf('%d caractères au maximum.', self::NAME_MAX_LENGTH);
        }
        if (mb_strlen($values['icon']) > self::ICON_MAX_LENGTH) {
            $errors['icon'] = 'Un émoji suffit.';
        }
        if (mb_strlen($values['category']) > self::CATEGORY_MAX_LENGTH) {
            $errors['category'] = \sprintf('%d caractères au maximum.', self::CATEGORY_MAX_LENGTH);
        }

        $clean['priceCents'] = Money::parseEuros($values['price']);
        if (null === $clean['priceCents']) {
            $errors['price'] = 'Prix en euros, par exemple 2,50.';
        }

        if ($isFormula || '' === $values['stock']) {
            $clean['initialStock'] = null;
        } elseif (ctype_digit($values['stock']) && (int) $values['stock'] <= self::STOCK_MAX) {
            $clean['initialStock'] = (int) $values['stock'];
        } else {
            $errors['stock'] = 'Nombre entier, ou vide si non compté.';
        }

        if ($withPosition) {
            if (ctype_digit($values['position']) && (int) $values['position'] <= self::POSITION_MAX) {
                $clean['position'] = (int) $values['position'];
            } else {
                $errors['position'] = 'Nombre entier.';
            }
        }

        return [$values, $errors, $clean];
    }

    /** @param array<string, mixed> $clean */
    private static function apply(Product $product, array $clean): void
    {
        $product
            ->setName($clean['name'])
            ->setIcon($clean['icon'])
            ->setCategory($clean['category'])
            ->setPriceCents($clean['priceCents'])
            ->setInitialStock($clean['initialStock'])
            ->setGoesToKitchen($clean['kitchen'])
            ->setHasDeposit($clean['deposit']);
        if (isset($clean['position'])) {
            $product->setPosition($clean['position']);
        }
    }

    /** @return array<string, mixed> valeurs affichées dans le tableau */
    private static function valuesOf(Product $product): array
    {
        return [
            'position' => (string) $product->getPosition(),
            'icon' => (string) $product->getIcon(),
            'name' => $product->getName(),
            'category' => $product->getCategory(),
            'price' => Money::formatEuros($product->getPriceCents()),
            'stock' => null === $product->getInitialStock() ? '' : (string) $product->getInitialStock(),
            'kitchen' => $product->goesToKitchen(),
            'deposit' => $product->hasDeposit(),
        ];
    }

    /** @return array<string, mixed> */
    private static function snapshot(Product $product): array
    {
        return [
            'name' => $product->getName(),
            'icon' => $product->getIcon(),
            'category' => $product->getCategory(),
            'price_cents' => $product->getPriceCents(),
            'initial_stock' => $product->getInitialStock(),
            'kitchen' => $product->goesToKitchen(),
            'deposit' => $product->hasDeposit(),
            'position' => $product->getPosition(),
        ];
    }

    /** @return list<string> */
    private static function componentNames(Product $product): array
    {
        return array_values(array_map(static fn (Product $c) => $c->getName(), $product->getComponents()->toArray()));
    }

    private function requireActiveEvent(): Event
    {
        return $this->events->findActive() ?? throw $this->createNotFoundException('Aucun événement actif.');
    }

    private function denyUnlessInEvent(Product $product, Event $event): void
    {
        if ($product->getEvent() !== $event) {
            throw $this->createNotFoundException('Article d\'un autre événement.');
        }
    }

    private function denyUnlessCsrfValid(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }
}
