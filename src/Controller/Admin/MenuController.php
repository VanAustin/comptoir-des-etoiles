<?php

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Entity\Product;
use App\Entity\ProductComponent;
use App\Menu\IconCatalog;
use App\Repository\EventRepository;
use App\Repository\ProductRepository;
use App\Service\AuditLogger;
use App\Service\ExampleMenu;
use App\Service\Money;
use App\Service\Revisions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Menu de l'événement actif, en deux parties : les articles et les formules.
 *
 * - Le tableau de la page Menu modifie d'un coup ce qui change souvent
 *   (prix, stock, cuisine, consigne) et l'ordre d'affichage (boutons ↑ ↓).
 * - Chaque article et chaque formule a sa page pour le reste (nom, icône,
 *   catégorie, composition).
 *
 * Formulaires HTML traités ici, avec CSRF sur chacun.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/menu')]
class MenuController extends AbstractController
{
    private const NAME_MAX_LENGTH = 80;
    private const CATEGORY_MAX_LENGTH = 40;
    private const STOCK_MAX = 99999;
    private const DEFAULT_CATEGORY = 'Divers';
    private const FORMULA_CATEGORY = 'Formules';

    public function __construct(
        private readonly EventRepository $events,
        private readonly ProductRepository $products,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Revisions $revisions,
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

    /**
     * Enregistre tout le tableau d'un coup (tout ou rien), puis déplace
     * l'article demandé si l'on a touché ↑ ou ↓ : rien de ce qui a été saisi n'est perdu.
     */
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
            [$values, $errors, $clean] = $this->validateRow($input, $product->isFormula());
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
            $product
                ->setPriceCents($clean['priceCents'])
                ->setInitialStock($clean['initialStock'])
                // Colonne masquée sans cuisine : la valeur de l'article est gardée pour un prochain événement
                ->setGoesToKitchen($event->hasKitchen() ? $clean['kitchen'] : $product->goesToKitchen())
                ->setHasDeposit($clean['deposit'])
                // Une formule est toujours vendue seule : sa ligne n'a pas la case
                ->setSoldAlone($product->isFormula() || $clean['alone']);
            if ($this->logChanges($product, $before)) {
                ++$updated;
            }
        }

        $moved = $this->move($event, $request->getPayload()->getString('move'));
        $this->revisions->flushMenu($event);

        if ($updated > 0 || null === $moved) {
            $this->addFlash('info', match ($updated) {
                0 => 'Aucune modification à enregistrer.',
                1 => 'Le menu est enregistré : 1 article modifié.',
                default => \sprintf('Le menu est enregistré : %d articles modifiés.', $updated),
            });
        }

        // Retour à la ligne déplacée, pour enchaîner les ↑ ↓ sans chercher
        return $this->redirect($this->generateUrl('admin_menu').(null === $moved ? '' : '#produit-'.$moved));
    }

    #[Route('/article/nouveau', name: 'admin_menu_product_new', methods: ['GET', 'POST'])]
    public function newProduct(Request $request): Response
    {
        $event = $this->requireActiveEvent();

        return $this->editForm($request, $event, new Product($event, ''), isFormula: false);
    }

    #[Route('/formule/nouvelle', name: 'admin_menu_formula_new', methods: ['GET', 'POST'])]
    public function newFormula(Request $request): Response
    {
        $event = $this->requireActiveEvent();
        $formula = (new Product($event, ''))->setCategory(self::FORMULA_CATEGORY)->setIcon('star');

        return $this->editForm($request, $event, $formula, isFormula: true);
    }

    #[Route('/{id<\d+>}/modifier', name: 'admin_menu_edit', methods: ['GET', 'POST'])]
    public function edit(Product $product, Request $request): Response
    {
        $event = $this->requireActiveEvent();
        $this->denyUnlessInEvent($product, $event);

        return $this->editForm($request, $event, $product, $product->isFormula());
    }

    #[Route('/{id<\d+>}/retirer', name: 'admin_menu_remove', methods: ['GET', 'POST'])]
    public function remove(Product $product, Request $request): Response
    {
        $event = $this->requireActiveEvent();
        $this->denyUnlessInEvent($product, $event);

        if ($request->isMethod('POST')) {
            $this->denyUnlessCsrfValid($request, 'menu-remove-'.$product->getId());
            $this->auditLogger->log('product.archive', 'product:'.$product->getId().' '.$product->getName(), before: self::snapshot($product));
            // Archivé, pas supprimé : ventes, historique et commandes encore en attente sur une tablette restent valables
            foreach ($this->products->findForEvent($event) as $formula) {
                if ($formula->componentQuantity($product) > 0) {
                    $formula->removeComponent($product);
                }
            }
            $product->archive();
            $this->revisions->flushMenu($event);
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

    /** Page d'un article ou d'une formule : création et modification. */
    private function editForm(Request $request, Event $event, Product $product, bool $isFormula): Response
    {
        $isNew = null === $product->getId();
        $candidates = $isFormula ? $this->componentCandidates($event, $product) : [];
        $values = self::detailsOf($product) + ['components' => self::quantitiesOf($product, $candidates)];
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->denyUnlessCsrfValid($request, 'menu-product-'.($product->getId() ?? 'new'));
            $input = $request->getPayload()->all();
            [$values, $errors, $clean] = $this->validateDetails($input, $isFormula);
            [$values['components'], $componentErrors, $quantities] = $isFormula
                ? $this->validateComposition($input['components'] ?? [], $candidates)
                : [[], [], []];
            $errors += $componentErrors;

            if (!$errors) {
                $before = $isNew ? null : self::snapshot($product);
                $product
                    ->setName($clean['name'])
                    ->setIcon($clean['icon'])
                    ->setCategory($clean['category'])
                    ->setPriceCents($clean['priceCents'])
                    ->setInitialStock($clean['initialStock'])
                    // Case masquée sans cuisine : la valeur de l'article est gardée
                    ->setGoesToKitchen($event->hasKitchen() ? $clean['kitchen'] : $product->goesToKitchen())
                    ->setHasDeposit($clean['deposit'])
                    ->setSoldAlone($isFormula || $clean['alone']);
                foreach ($candidates as $candidate) {
                    $product->setComponentQuantity($candidate, $quantities[$candidate->getId()] ?? 0);
                }

                $this->revisions->flushMenu($event, function () use ($isNew, $isFormula, $product, $event, $before): void {
                    if ($isNew) {
                        $product->setPosition($this->products->nextPosition($event));
                        $this->entityManager->persist($product);
                        // Identifiant nécessaire au journal d'audit
                        $this->entityManager->flush();
                        $this->auditLogger->log('product.create', 'product:'.$product->getId().' '.$product->getName(), after: self::snapshot($product));
                        $this->addFlash('info', \sprintf($isFormula ? 'La formule « %s » est ajoutée au menu.' : '« %s » est ajouté au menu.', $product->getName()));
                    } else {
                        $this->logChanges($product, $before);
                        $this->addFlash('info', \sprintf('« %s » est enregistré.', $product->getName()));
                    }
                });

                return $this->redirect($this->generateUrl('admin_menu').'#produit-'.$product->getId());
            }
        }

        return $this->render($isFormula ? 'admin/menu/formula.html.twig' : 'admin/menu/product.html.twig', [
            'event' => $event,
            'product' => $product,
            'is_new' => $isNew,
            'values' => $values,
            'errors' => $errors,
            'icon_groups' => IconCatalog::groups(),
            'categories' => $this->categories($event),
            'candidates_by_category' => self::byCategory($candidates),
            'used_as_component' => !$isNew && !$isFormula && $this->products->isUsedAsComponent($product),
        ], new Response(status: $errors ? 422 : 200));
    }

    /**
     * @param array<int, array<string, mixed>>  $rowValues
     * @param array<int, array<string, string>> $rowErrors
     */
    private function renderMenu(Event $event, array $rowValues = [], array $rowErrors = [], int $status = 200): Response
    {
        $products = $this->products->findForEvent($event);
        foreach ($products as $product) {
            $rowValues[$product->getId()] ??= self::rowOf($product);
        }

        $articles = array_values(array_filter($products, static fn (Product $p) => !$p->isFormula()));
        $formulas = array_values(array_filter($products, static fn (Product $p) => $p->isFormula()));
        $inFormulas = [];
        foreach ($formulas as $formula) {
            foreach ($formula->getComponents() as $component) {
                $inFormulas[$component->getComponent()->getId()] = true;
            }
        }

        return $this->render('admin/menu/index.html.twig', [
            'event' => $event,
            'articles' => $articles,
            'formulas' => $formulas,
            // Ni vendus seuls, ni dans une formule : invisibles sur les caisses
            'unsellable' => array_values(array_filter($articles, static fn (Product $p) => !$p->isSoldAlone() && !isset($inFormulas[$p->getId()]))),
            'row_values' => $rowValues,
            'row_errors' => $rowErrors,
        ], new Response(status: $status));
    }

    /**
     * Ligne du tableau : prix, stock, cuisine, consigne.
     *
     * @param array<string, mixed> $input
     *
     * @return array{array<string, mixed>, array<string, string>, array<string, mixed>}
     */
    private function validateRow(array $input, bool $isFormula): array
    {
        $values = [
            'price' => self::text($input, 'price'),
            'stock' => self::text($input, 'stock'),
            'kitchen' => isset($input['kitchen']),
            'deposit' => isset($input['deposit']),
            'alone' => isset($input['alone']),
        ];
        $errors = [];
        $clean = ['kitchen' => $values['kitchen'], 'deposit' => $values['deposit'], 'alone' => $values['alone']];
        self::validatePriceAndStock($values, $isFormula, $errors, $clean);

        return [$values, $errors, $clean];
    }

    /**
     * Page de l'article ou de la formule : tous les champs sauf la composition.
     *
     * @param array<string, mixed> $input
     *
     * @return array{array<string, mixed>, array<string, string>, array<string, mixed>}
     */
    private function validateDetails(array $input, bool $isFormula): array
    {
        $values = [
            'name' => self::text($input, 'name'),
            'icon' => self::text($input, 'icon'),
            'category' => self::text($input, 'category'),
            'price' => self::text($input, 'price'),
            'stock' => $isFormula ? '' : self::text($input, 'stock'),
            'kitchen' => isset($input['kitchen']),
            'deposit' => isset($input['deposit']),
            'alone' => isset($input['alone']),
        ];
        $errors = [];
        $clean = [
            'alone' => $values['alone'],
            'name' => $values['name'],
            'icon' => '' === $values['icon'] ? null : $values['icon'],
            'category' => '' === $values['category'] ? ($isFormula ? self::FORMULA_CATEGORY : self::DEFAULT_CATEGORY) : $values['category'],
            'kitchen' => $values['kitchen'],
            'deposit' => $values['deposit'],
        ];

        if ('' === $values['name']) {
            $errors['name'] = $isFormula ? 'Indiquez le nom de la formule.' : 'Indiquez le nom de l\'article.';
        } elseif (mb_strlen($values['name']) > self::NAME_MAX_LENGTH) {
            $errors['name'] = \sprintf('%d caractères au maximum.', self::NAME_MAX_LENGTH);
        }
        if (null !== $clean['icon'] && !IconCatalog::has($clean['icon'])) {
            $errors['icon'] = 'Choisissez une icône dans la liste.';
        }
        if (mb_strlen($values['category']) > self::CATEGORY_MAX_LENGTH) {
            $errors['category'] = \sprintf('%d caractères au maximum.', self::CATEGORY_MAX_LENGTH);
        }
        self::validatePriceAndStock($values, $isFormula, $errors, $clean);

        return [$values, $errors, $clean];
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $errors
     * @param array<string, mixed>  $clean
     */
    private static function validatePriceAndStock(array $values, bool $isFormula, array &$errors, array &$clean): void
    {
        $clean['priceCents'] = Money::parseEuros($values['price']);
        if (null === $clean['priceCents']) {
            $errors['price'] = 'Prix en euros, par exemple 2,50.';
        }

        // Le stock d'une formule se déduit de celui de ses articles
        if ($isFormula || '' === $values['stock']) {
            $clean['initialStock'] = null;
        } elseif (ctype_digit($values['stock']) && (int) $values['stock'] <= self::STOCK_MAX) {
            $clean['initialStock'] = (int) $values['stock'];
        } else {
            $errors['stock'] = 'Nombre entier, ou vide si non compté.';
        }
    }

    /**
     * @param list<Product> $candidates
     *
     * @return array{array<int, string>, array<string, string>, array<int, int>}
     */
    private function validateComposition(mixed $input, array $candidates): array
    {
        $input = \is_array($input) ? $input : [];
        $values = [];
        $quantities = [];
        $errors = [];
        $allowed = array_flip(array_map(static fn (Product $p) => $p->getId(), $candidates));

        foreach ($input as $id => $raw) {
            $raw = \is_string($raw) ? trim($raw) : '';
            $quantity = '' === $raw ? 0 : (ctype_digit($raw) ? (int) $raw : -1);
            $values[(int) $id] = $raw;
            if ($quantity < 0 || $quantity > ProductComponent::MAX_QUANTITY) {
                $errors['components'] = \sprintf('Quantités : nombres entiers de 0 à %d.', ProductComponent::MAX_QUANTITY);
            } elseif ($quantity > 0 && !isset($allowed[(int) $id])) {
                $errors['components'] = 'Un des articles choisis ne peut pas faire partie de cette formule. Rechargez la page, puis réessayez.';
            } elseif ($quantity > 0) {
                $quantities[(int) $id] = $quantity;
            }
        }
        if (!$errors && [] === $quantities) {
            $errors['components'] = 'Ajoutez au moins un article à la formule.';
        }

        return [$values, $errors, $quantities];
    }

    /**
     * Déplace un article d'un cran parmi ceux de sa partie (articles ou formules).
     * Valeur attendue : « 12:up » ou « 12:down ».
     *
     * @return int|null l'identifiant déplacé
     */
    private function move(Event $event, string $move): ?int
    {
        if (!preg_match('/^(\d+):(up|down)$/', $move, $m)) {
            return null;
        }

        // Ordre remis à plat (1, 2, 3…) avant d'échanger deux voisins
        $all = $this->products->findForEvent($event);
        foreach ($all as $index => $product) {
            $product->setPosition($index + 1);
        }

        $target = null;
        foreach ($all as $product) {
            if ($product->getId() === (int) $m[1]) {
                $target = $product;
            }
        }
        if (null === $target) {
            return null;
        }
        $section = array_values(array_filter($all, static fn (Product $p) => $p->isFormula() === $target->isFormula()));
        $index = array_search($target, $section, true);
        $neighbour = $section[$index + ('up' === $m[2] ? -1 : 1)] ?? null;
        if (null !== $neighbour) {
            $position = $target->getPosition();
            $target->setPosition($neighbour->getPosition());
            $neighbour->setPosition($position);
        }

        return $target->getId();
    }

    /** Trace dans le journal les champs réellement modifiés. */
    private function logChanges(Product $product, array $before): bool
    {
        $after = self::snapshot($product);
        $changed = array_keys(array_diff_assoc(array_map('serialize', $after), array_map('serialize', $before)));
        if (!$changed) {
            return false;
        }
        $this->auditLogger->log(
            'product.update',
            'product:'.$product->getId().' '.$product->getName(),
            array_intersect_key($before, array_flip($changed)),
            array_intersect_key($after, array_flip($changed)),
        );

        return true;
    }

    /**
     * Articles simples pouvant entrer dans une formule (jamais une autre formule).
     *
     * @return list<Product>
     */
    private function componentCandidates(Event $event, Product $formula): array
    {
        return array_values(array_filter(
            $this->products->findForEvent($event),
            static fn (Product $p) => $p !== $formula && !$p->isFormula(),
        ));
    }

    /** @return list<string> catégories déjà utilisées, proposées à la saisie */
    private function categories(Event $event): array
    {
        $categories = array_map(static fn (Product $p) => $p->getCategory(), $this->products->findForEvent($event));

        return array_values(array_unique([...$categories, self::DEFAULT_CATEGORY, self::FORMULA_CATEGORY]));
    }

    /**
     * @param list<Product> $products
     *
     * @return array<string, list<Product>>
     */
    private static function byCategory(array $products): array
    {
        $groups = [];
        foreach ($products as $product) {
            $groups[$product->getCategory()][] = $product;
        }

        return $groups;
    }

    /** @param array<string, mixed> $input */
    private static function text(array $input, string $key): string
    {
        return \is_string($input[$key] ?? null) ? trim($input[$key]) : '';
    }

    /** @return array<string, mixed> valeurs affichées dans le tableau */
    private static function rowOf(Product $product): array
    {
        return [
            'price' => Money::formatEuros($product->getPriceCents()),
            'stock' => null === $product->getInitialStock() ? '' : (string) $product->getInitialStock(),
            'kitchen' => $product->goesToKitchen(),
            'deposit' => $product->hasDeposit(),
            'alone' => $product->isSoldAlone(),
        ];
    }

    /** @return array<string, mixed> valeurs affichées dans la page de l'article */
    private static function detailsOf(Product $product): array
    {
        return [
            'name' => $product->getName(),
            'icon' => (string) $product->getIcon(),
            'category' => null === $product->getId() && self::DEFAULT_CATEGORY === $product->getCategory() ? '' : $product->getCategory(),
            'price' => null === $product->getId() ? '' : Money::formatEuros($product->getPriceCents()),
            'stock' => null === $product->getInitialStock() ? '' : (string) $product->getInitialStock(),
            'kitchen' => $product->goesToKitchen(),
            'deposit' => $product->hasDeposit(),
            'alone' => $product->isSoldAlone(),
        ];
    }

    /**
     * @param list<Product> $candidates
     *
     * @return array<int, string>
     */
    private static function quantitiesOf(Product $formula, array $candidates): array
    {
        $quantities = [];
        foreach ($candidates as $candidate) {
            $quantity = $formula->componentQuantity($candidate);
            $quantities[$candidate->getId()] = $quantity > 0 ? (string) $quantity : '';
        }

        return $quantities;
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
            'sold_alone' => $product->isSoldAlone(),
            'composition' => $product->describeComposition(),
        ];
    }

    private function requireActiveEvent(): Event
    {
        return $this->events->findActive() ?? throw $this->createNotFoundException('Aucun événement actif.');
    }

    private function denyUnlessInEvent(Product $product, Event $event): void
    {
        if ($product->getEvent() !== $event || $product->isArchived()) {
            throw $this->createNotFoundException('Article d\'un autre événement, ou retiré du menu.');
        }
    }

    private function denyUnlessCsrfValid(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }
}
