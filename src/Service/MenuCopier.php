<?php

namespace App\Service;

use App\Entity\Event;
use App\Entity\Product;
use App\Menu\MenuCopyPlan;
use App\Menu\ProductSnapshot;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Reprendre le menu d'un autre événement » : recopie les articles et formules des catégories
 * choisies dans un autre événement. Jamais les commandes, jamais les articles retirés du menu.
 *
 * Les formules copiées sont reliées aux articles copiés (ou à ceux qui existaient déjà sous le
 * même nom), jamais à ceux de l'événement d'origine.
 */
final class MenuCopier
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Revisions $revisions,
    ) {
    }

    /**
     * Catégories du menu d'origine, dans l'ordre du menu, avec leur nombre d'articles.
     *
     * @return array<string, int>
     */
    public function categories(Event $source): array
    {
        $counts = [];
        foreach ($this->products->findForEvent($source) as $product) {
            $counts[$product->getCategory()] = ($counts[$product->getCategory()] ?? 0) + 1;
        }

        return $counts;
    }

    /** @param list<string> $categories */
    public function plan(Event $source, Event $target, array $categories): MenuCopyPlan
    {
        $menu = $this->products->findForEvent($source);
        $selected = [];
        foreach ($menu as $product) {
            if (\in_array($product->getCategory(), $categories, true)) {
                $selected[$product->getId()] = true;
            }
        }

        // Composants d'une formule choisie, rangés dans une catégorie non cochée
        $added = [];
        foreach ($menu as $product) {
            if (!isset($selected[$product->getId()])) {
                continue;
            }
            foreach ($product->getComponents() as $component) {
                $id = $component->getComponent()->getId();
                if (!isset($selected[$id])) {
                    $added[$id] = true;
                }
            }
        }

        $existing = [];
        foreach ($this->products->findForEvent($target) as $product) {
            $existing[self::key($product)] ??= $product;
        }

        $copied = [];
        $addedComponents = [];
        $reused = [];
        foreach ($menu as $product) {
            if (!isset($selected[$product->getId()]) && !isset($added[$product->getId()])) {
                continue;
            }
            $match = $existing[self::key($product)] ?? null;
            if (null !== $match) {
                $reused[] = ['source' => $product, 'existing' => $match];
                continue;
            }
            $copied[] = $product;
            if (isset($added[$product->getId()])) {
                $addedComponents[] = $product;
            }
        }

        return new MenuCopyPlan($source, $target, array_values($categories), $copied, $addedComponents, $reused);
    }

    /** @return int nombre d'articles et de formules créés */
    public function copy(MenuCopyPlan $plan): int
    {
        if ($plan->isEmpty()) {
            return 0;
        }

        return $this->revisions->flushMenu($plan->target, function () use ($plan): int {
            /** @var array<int, Product> $copies identifiant d'origine → article de destination */
            $copies = [];
            foreach ($plan->reused as ['source' => $source, 'existing' => $existing]) {
                $copies[$source->getId()] = $existing;
            }

            // À la suite du menu de destination, dans l'ordre du menu d'origine
            $position = $this->products->nextPosition($plan->target);
            foreach ($plan->copied as $source) {
                $copy = (new Product($plan->target, $source->getName()))
                    ->setIcon($source->getIcon())
                    ->setCategory($source->getCategory())
                    ->setPriceCents($source->getPriceCents())
                    ->setInitialStock($source->getInitialStock())
                    ->setGoesToKitchen($source->goesToKitchen())
                    ->setHasDeposit($source->hasDeposit())
                    ->setSoldAlone($source->isSoldAlone())
                    ->setPosition($position++);
                $this->entityManager->persist($copy);
                $copies[$source->getId()] = $copy;
            }

            // Une fois tous les articles créés : les formules se relient aux copies
            foreach ($plan->copied as $source) {
                foreach ($source->getComponents() as $component) {
                    // Un composant retiré du menu sort aussi de ses formules : ce cas ne devrait pas exister
                    $target = $copies[$component->getComponent()->getId()] ?? null;
                    if (null !== $target) {
                        $copies[$source->getId()]->setComponentQuantity($target, $component->getQuantity());
                    }
                }
            }

            // Identifiants nécessaires au journal d'audit
            $this->entityManager->flush();
            foreach ($plan->copied as $source) {
                $copy = $copies[$source->getId()];
                $this->auditLogger->log('product.create', 'product:'.$copy->getId().' '.$copy->getName(), after: ProductSnapshot::of($copy));
            }
            $this->auditLogger->log('menu.copy', 'event:'.$plan->target->getId().' '.$plan->target->getName(), after: [
                'from' => 'event:'.$plan->source->getId().' '.$plan->source->getName(),
                'categories' => $plan->categories,
                'created' => \count($plan->copied),
                'reused' => array_map(static fn (array $r): string => $r['existing']->getName(), $plan->reused),
            ]);

            return \count($plan->copied);
        });
    }

    /** Même nom (sans tenir compte des majuscules) et même nature : article simple ou formule. */
    private static function key(Product $product): string
    {
        return ($product->isFormula() ? 'formule:' : 'article:').mb_strtolower(trim($product->getName()));
    }
}
