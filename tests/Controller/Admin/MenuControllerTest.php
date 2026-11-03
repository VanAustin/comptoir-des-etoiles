<?php

namespace App\Tests\Controller\Admin;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Entity\Event;
use App\Entity\Product;
use App\Menu\IconCatalog;
use App\Tests\FixturesTrait;
use App\Tests\ResetDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class MenuControllerTest extends WebTestCase
{
    use FixturesTrait;
    use ResetDatabaseTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetDatabase();

        $admin = (new AdminUser('camille'))->setPassword('inutile-ici');
        self::entityManager()->persist($admin);
        self::entityManager()->flush();
        $this->client->loginUser($admin);
    }

    public function testWithoutActiveEvent(): void
    {
        $this->client->request('GET', '/admin/menu');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Aucun événement n\'est actif', $this->content());
    }

    public function testLoadExampleMenuOnlyOnce(): void
    {
        self::createEvent();

        $token = $this->tokenOnPage('/admin/menu', '/admin/menu/exemple');
        $this->client->request('POST', '/admin/menu/exemple', ['_token' => $token]);
        self::assertResponseRedirects('/admin/menu');

        self::assertSame(12, $this->productRepository()->count());
        $formula = $this->findProduct('Menu Loto');
        self::assertSame(550, $formula->getPriceCents());
        self::assertNull($formula->getInitialStock());
        self::assertSame('1 × Hot-dog, 1 × Crêpe sucre, 1 × Soda canette', $formula->describeComposition(), 'dans l\'ordre du menu');
        foreach ($this->productRepository()->findAll() as $product) {
            self::assertTrue(IconCatalog::has($product->getIcon()), $product->getName().' : icône du catalogue');
        }

        // Une seconde fois : refusé, le menu n'est pas vide
        $this->client->request('POST', '/admin/menu/exemple', ['_token' => $token]);
        self::assertSame(12, $this->productRepository()->count());
    }

    public function testAddProduct(): void
    {
        self::createEvent();
        $this->createProduct('Café', 100, position: 4);

        $crawler = $this->client->request('GET', '/admin/menu');
        $this->client->click($crawler->selectLink('Ajouter un article')->link());
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Ajouter l\'article', [
            'name' => ' Soda ',
            'icon' => 'cup-soda',
            'category' => '',
            'price' => '2,5',
            'stock' => '',
            'deposit' => true,
        ]);

        $soda = $this->findProduct('Soda');
        self::assertResponseRedirects('/admin/menu#produit-'.$soda->getId());
        self::assertSame(250, $soda->getPriceCents());
        self::assertSame('cup-soda', $soda->getIcon());
        self::assertNull($soda->getInitialStock());
        self::assertSame('Divers', $soda->getCategory());
        self::assertFalse($soda->goesToKitchen());
        self::assertTrue($soda->hasDeposit());
        self::assertSame(5, $soda->getPosition(), 'ajouté à la fin du menu');
        self::assertSame(1, $this->auditCount('product.create'));
    }

    public function testAddInvalidProduct(): void
    {
        self::createEvent();

        $this->client->request('GET', '/admin/menu/article/nouveau');
        $this->client->submitForm('Ajouter l\'article', [
            'name' => '',
            'price' => '2,505',
            'stock' => '-3',
        ]);

        self::assertResponseStatusCodeSame(422);
        $content = $this->content();
        self::assertStringContainsString('Indiquez le nom', $content);
        self::assertStringContainsString('Prix en euros', $content);
        self::assertStringContainsString('Nombre entier, ou vide', $content);
        self::assertSame(0, $this->productRepository()->count());
    }

    public function testIconMustComeFromTheCatalog(): void
    {
        self::createEvent();

        $this->client->request('POST', '/admin/menu/article/nouveau', [
            '_token' => $this->tokenOnPage('/admin/menu/article/nouveau'),
            'name' => 'Thé',
            'icon' => '🍵',
            'price' => '1',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choisissez une icône dans la liste', $this->content());
        self::assertSame(0, $this->productRepository()->count());
    }

    public function testCategoriesAlreadyUsedAreSuggested(): void
    {
        self::createEvent();
        $this->createProduct('Café', 100)->setCategory('Boissons chaudes');
        self::entityManager()->flush();

        $crawler = $this->client->request('GET', '/admin/menu/article/nouveau');

        self::assertCount(1, $crawler->filterXPath('//datalist[@id="categories"]/option[@value="Boissons chaudes"]'));
    }

    public function testEditProductTracesChanges(): void
    {
        self::createEvent();
        $cafe = $this->createProduct('Café', 100);

        $this->client->request('GET', '/admin/menu/'.$cafe->getId().'/modifier');
        $this->client->submitForm('Enregistrer l\'article', ['name' => 'Café long', 'icon' => 'coffee']);

        self::assertResponseRedirects('/admin/menu#produit-'.$cafe->getId());
        $cafe = $this->findProduct('Café long');
        self::assertSame('coffee', $cafe->getIcon());
        $log = self::entityManager()->getRepository(AuditLog::class)->findOneBy(['action' => 'product.update']);
        // assertEquals : MySQL ne garde pas l'ordre des clés d'une colonne JSON
        self::assertEquals(['name' => 'Café', 'icon' => null], $log->getBefore());
        self::assertEquals(['name' => 'Café long', 'icon' => 'coffee'], $log->getAfter());
    }

    public function testCreateFormulaWithQuantities(): void
    {
        self::createEvent();
        $crepe = $this->createProduct('Crêpe sucre', 150, stock: 70);
        $soda = $this->createProduct('Soda', 150, stock: 96);
        $this->createProduct('Café', 100);

        $crawler = $this->client->request('GET', '/admin/menu');
        $this->client->click($crawler->selectLink('Créer une formule')->link());
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Créer la formule', [
            'name' => 'Goûter',
            'price' => '4',
            'components['.$crepe->getId().']' => '2',
            'components['.$soda->getId().']' => '1',
        ]);

        $formula = $this->findProduct('Goûter');
        self::assertResponseRedirects('/admin/menu#produit-'.$formula->getId());
        self::assertSame('2 × Crêpe sucre, 1 × Soda', $formula->describeComposition());
        self::assertSame('Formules', $formula->getCategory());
        self::assertSame('star', $formula->getIcon());
        self::assertNull($formula->getInitialStock());
        self::assertSame('2 × Crêpe sucre, 1 × Soda', self::entityManager()->getRepository(AuditLog::class)->findOneBy(['action' => 'product.create'])->getAfter()['composition']);

        // Modifier la composition : une crêpe de plus, plus de soda
        $this->client->request('GET', '/admin/menu/'.$formula->getId().'/modifier');
        $this->client->submitForm('Enregistrer la formule', [
            'components['.$crepe->getId().']' => '3',
            'components['.$soda->getId().']' => '',
        ]);
        self::assertSame('3 × Crêpe sucre', $this->findProduct('Goûter')->describeComposition());
    }

    public function testFormulaCompositionIsValidated(): void
    {
        self::createEvent();
        $crepe = $this->createProduct('Crêpe sucre', 150);
        $menu = $this->createProduct('Menu', 450);
        $menu->addComponent(self::entityManager()->find(Product::class, $crepe->getId()));
        self::entityManager()->flush();
        $url = '/admin/menu/formule/nouvelle';

        $cases = [
            'aucun article' => [[], 'au moins un article'],
            'quantité trop grande' => [[$crepe->getId() => '21'], 'de 0 à 20'],
            'quantité négative' => [[$crepe->getId() => '-1'], 'de 0 à 20'],
            'formule dans une formule' => [[$menu->getId() => '1'], 'ne peut pas faire partie'],
            'article d\'un autre événement' => [[999999 => '1'], 'ne peut pas faire partie'],
        ];
        foreach ($cases as $case => [$components, $message]) {
            $this->client->request('POST', $url, [
                '_token' => $this->tokenOnPage($url),
                'name' => 'Formule test',
                'price' => '5',
                'components' => $components,
            ]);
            self::assertResponseStatusCodeSame(422, $case);
            self::assertStringContainsString($message, $this->content(), $case);
        }

        self::assertNull($this->productRepository()->findOneBy(['name' => 'Formule test']));
    }

    public function testFormulaPageOnlyOffersSimpleArticles(): void
    {
        self::createEvent();
        $crepe = $this->createProduct('Crêpe sucre', 150);
        $menu = $this->createProduct('Menu', 450);
        $menu->addComponent(self::entityManager()->find(Product::class, $crepe->getId()));
        self::entityManager()->flush();

        $crawler = $this->client->request('GET', '/admin/menu/formule/nouvelle');

        self::assertCount(1, $crawler->filterXPath('//input[@name="components['.$crepe->getId().']"]'));
        self::assertCount(0, $crawler->filterXPath('//input[@name="components['.$menu->getId().']"]'));
    }

    public function testMenuShowsArticlesAndFormulasSeparately(): void
    {
        self::createEvent();
        $crepe = $this->createProduct('Crêpe sucre', 150);
        $menu = $this->createProduct('Goûter', 300);
        $menu->addComponent(self::entityManager()->find(Product::class, $crepe->getId()), 2);
        self::entityManager()->flush();

        $crawler = $this->client->request('GET', '/admin/menu');

        $sections = $crawler->filterXPath('//section[contains(@class, "menu-section")]');
        self::assertStringContainsString('Crêpe sucre', $sections->eq(0)->text());
        self::assertStringNotContainsString('Goûter', $sections->eq(0)->text());
        self::assertStringContainsString('Goûter', $sections->eq(1)->text());
        self::assertStringContainsString('2 × Crêpe sucre', $sections->eq(1)->text());
        // Pas de stock pour une formule dans le tableau
        self::assertCount(0, $crawler->filterXPath('//input[@name="products['.$menu->getId().'][stock]"]'));
    }

    public function testMoveKeepsUnsavedChanges(): void
    {
        self::createEvent();
        $crepe = $this->createProduct('Crêpe sucre', 150, position: 1);
        $cafe = $this->createProduct('Café', 100, position: 2);
        $the = $this->createProduct('Thé', 100, position: 3);

        $crawler = $this->client->request('GET', '/admin/menu');
        $form = $crawler->filterXPath('//button[@name="move" and @value="'.$the->getId().':up"]')->form();
        $form['products['.$crepe->getId().'][price]'] = '1,80';
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/menu#produit-'.$the->getId());
        $names = array_map(static fn (Product $p) => $p->getName(), $this->orderedProducts());
        self::assertSame(['Crêpe sucre', 'Thé', 'Café'], $names);
        self::assertSame(180, $this->findProduct('Crêpe sucre')->getPriceCents(), 'la saisie n\'est pas perdue');
    }

    public function testMoveStaysWithinItsSection(): void
    {
        self::createEvent();
        $crepe = $this->createProduct('Crêpe sucre', 150, position: 1);
        $menu = $this->createProduct('Goûter', 300, position: 2);
        $menu->addComponent(self::entityManager()->find(Product::class, $crepe->getId()));
        self::entityManager()->flush();
        $cafe = $this->createProduct('Café', 100, position: 3);

        // Le café monte au-dessus de la crêpe, pas de la formule
        $this->client->request('POST', '/admin/menu', [
            '_token' => $this->tokenOnPage('/admin/menu', '/admin/menu'),
            'move' => $cafe->getId().':up',
        ]);

        $names = array_map(static fn (Product $p) => $p->getName(), $this->orderedProducts());
        self::assertSame(['Café', 'Goûter', 'Crêpe sucre'], $names);
    }

    public function testEveryFormRequiresCsrfToken(): void
    {
        self::createEvent();
        $cafe = $this->createProduct('Café', 100);
        $id = $cafe->getId();

        $requests = [
            ['/admin/menu', ['products' => [$id => ['price' => '1']]]],
            ['/admin/menu', ['move' => $id.':down']],
            ['/admin/menu/article/nouveau', ['name' => 'Thé', 'price' => '1']],
            ['/admin/menu/formule/nouvelle', ['name' => 'Menu', 'price' => '1', 'components' => [$id => '1']]],
            ['/admin/menu/'.$id.'/modifier', ['name' => 'Piraté', 'price' => '1']],
            ['/admin/menu/'.$id.'/retirer', []],
            ['/admin/menu/exemple', []],
        ];
        foreach ($requests as [$url, $data]) {
            $this->client->request('POST', $url, $data + ['_token' => 'jeton-invalide']);
            self::assertResponseStatusCodeSame(403, $url);
        }

        self::assertSame(1, $this->productRepository()->count());
        self::assertSame('Café', $this->findProduct('Café')->getName());
    }

    public function testProductOfAnotherEventCannotBeEdited(): void
    {
        $other = self::createEvent('Halloween', active: false);
        $bonbons = $this->createProduct('Bonbons', 100, event: $other);
        self::createEvent('Loto de Noël');

        $this->client->request('GET', '/admin/menu/'.$bonbons->getId().'/modifier');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSaveMenuTracesOnlyChangedFields(): void
    {
        self::createEvent();
        $crepe = $this->createProduct('Crêpe sucre', 150, stock: 70);
        $this->createProduct('Café', 100);

        $crawler = $this->client->request('GET', '/admin/menu');
        $form = $crawler->selectButton('Enregistrer le menu')->form();
        $form['products['.$crepe->getId().'][price]'] = '1,80';
        $form['products['.$crepe->getId().'][stock]'] = '80';
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/menu');
        $crepe = $this->findProduct('Crêpe sucre');
        self::assertSame(180, $crepe->getPriceCents());
        self::assertSame(80, $crepe->getInitialStock());

        $logs = self::entityManager()->getRepository(AuditLog::class)->findBy(['action' => 'product.update']);
        self::assertCount(1, $logs);
        self::assertSame(['price_cents' => 150, 'initial_stock' => 70], $logs[0]->getBefore());
        self::assertSame(['price_cents' => 180, 'initial_stock' => 80], $logs[0]->getAfter());
        self::assertSame('admin:camille', $logs[0]->getActor());

        $this->client->followRedirect();
        self::assertStringContainsString('1 article modifié', $this->content());
    }

    public function testSaveMenuIsAllOrNothing(): void
    {
        self::createEvent();
        $crepe = $this->createProduct('Crêpe sucre', 150);
        $cafe = $this->createProduct('Café', 100);

        $crawler = $this->client->request('GET', '/admin/menu');
        $form = $crawler->selectButton('Enregistrer le menu')->form();
        $form['products['.$crepe->getId().'][price]'] = '1,80';
        $form['products['.$cafe->getId().'][price]'] = 'gratuit';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Rien n\'a été enregistré', $this->content());
        self::assertSame(150, $this->findProduct('Crêpe sucre')->getPriceCents());
        self::assertSame(0, $this->auditCount('product.update'));
    }

    public function testSaveMenuIgnoresProductsOfAnotherEvent(): void
    {
        $other = self::createEvent('Halloween', active: false);
        $otherProduct = $this->createProduct('Bonbons', 100, event: $other);
        self::createEvent('Loto de Noël');
        $this->createProduct('Café', 100);

        $this->client->request('POST', '/admin/menu', [
            '_token' => $this->tokenOnPage('/admin/menu', '/admin/menu'),
            'products' => [$otherProduct->getId() => ['price' => '0', 'stock' => '']],
        ]);

        self::assertSame(100, $this->findProductById($otherProduct->getId())->getPriceCents());
    }

    public function testRemoveProductAfterConfirmation(): void
    {
        self::createEvent();
        $cafe = $this->createProduct('Café', 100);

        $crawler = $this->client->request('GET', '/admin/menu/'.$cafe->getId().'/retirer');
        self::assertStringContainsString('Retirer « Café » du menu ?', $this->content());
        self::assertSame(1, $this->productRepository()->count(), 'Rien n\'est retiré avant confirmation');

        $this->client->submit($crawler->selectButton('Retirer l\'article')->form());

        self::assertResponseRedirects('/admin/menu');
        // Archivé : hors du menu, mais toujours en base pour l'historique
        self::assertSame(1, $this->productRepository()->count());
        self::assertTrue($this->findProductById($cafe->getId())->isArchived());
        self::assertSame([], $this->productRepository()->findForEvent($this->findProductById($cafe->getId())->getEvent()));
        self::assertSame(1, $this->auditCount('product.archive'));

        $this->client->request('GET', '/admin/menu');
        self::assertStringNotContainsString('id="produit-'.$cafe->getId().'"', $this->content());
        $this->client->request('GET', '/admin/menu/'.$cafe->getId().'/modifier');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRemovedProductLeavesItsFormulas(): void
    {
        self::createEvent();
        $crepe = $this->createProduct('Crêpe', 200);
        $soda = $this->createProduct('Soda', 150);
        $duo = $this->createProduct('Duo', 300);
        $duo->addComponent($crepe)->addComponent($soda);
        self::entityManager()->flush();

        $crawler = $this->client->request('GET', '/admin/menu/'.$crepe->getId().'/retirer');
        $this->client->submit($crawler->selectButton('Retirer l\'article')->form());

        self::entityManager()->clear();
        self::assertSame('1 × Soda', $this->findProductById($duo->getId())->describeComposition());
    }

    public function testProductOfAnotherEventCannotBeRemoved(): void
    {
        $other = self::createEvent('Halloween', active: false);
        $bonbons = $this->createProduct('Bonbons', 100, event: $other);
        self::createEvent('Loto de Noël');

        $this->client->request('GET', '/admin/menu/'.$bonbons->getId().'/retirer');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDepositAmountIsShown(): void
    {
        $event = self::createEvent();
        $event->setDepositCents(100);
        self::entityManager()->flush();

        $this->client->request('GET', '/admin/menu');

        self::assertStringContainsString("Consigne : 1,00\u{00A0}€", $this->content());
    }

    public function testWithoutKitchenTheKitchenSettingIsHiddenAndKept(): void
    {
        $event = self::createEvent();
        $event->setWithKitchen(false);
        $hotdog = $this->createProduct('Hot-dog', 300)->setGoesToKitchen(true);
        $formula = $this->createProduct('Menu', 500, position: 2)->setGoesToKitchen(true)->setComponentQuantity($hotdog, 1);
        self::entityManager()->flush();

        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertCount(0, $crawler->filterXPath('//th[normalize-space()="Cuisine"]'));
        self::assertCount(0, $crawler->filterXPath('//input[contains(@name, "[kitchen]")]'));

        $form = $crawler->selectButton('Enregistrer le menu')->form();
        $form['products['.$hotdog->getId().'][price]'] = '3,50';
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/menu');
        self::assertSame(350, $this->findProduct('Hot-dog')->getPriceCents());
        self::assertTrue($this->findProduct('Hot-dog')->goesToKitchen(), 'gardé pour un prochain événement avec cuisine');

        foreach (['/admin/menu/'.$hotdog->getId().'/modifier' => 'Enregistrer l\'article', '/admin/menu/'.$formula->getId().'/modifier' => 'Enregistrer la formule'] as $url => $button) {
            $crawler = $this->client->request('GET', $url);
            self::assertCount(0, $crawler->filterXPath('//input[@name="kitchen"]'), $url);
            $this->client->submitForm($button);
            self::assertResponseRedirects();
        }
        self::assertTrue($this->findProduct('Hot-dog')->goesToKitchen());
        self::assertTrue($this->findProduct('Menu')->goesToKitchen());
    }

    public function testWithKitchenTheKitchenColumnIsShown(): void
    {
        self::createEvent();
        $this->createProduct('Hot-dog', 300);

        $crawler = $this->client->request('GET', '/admin/menu');

        self::assertCount(1, $crawler->filterXPath('//th[normalize-space()="Cuisine"]'));
        self::assertCount(1, $crawler->filterXPath('//input[contains(@name, "[kitchen]")]'));
    }

    public function testSoldAloneCanBeUncheckedInTheTable(): void
    {
        self::createEvent();
        $sausage = $this->createProduct('Saucisse', 0);
        $this->createProduct('Café', 100, position: 2);

        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertNotNull($crawler->filterXPath('//input[@name="products['.$sausage->getId().'][alone]"]')->attr('checked'), 'coché par défaut');
        $form = $crawler->selectButton('Enregistrer le menu')->form();
        $form['products['.$sausage->getId().'][alone]']->untick();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/menu');
        self::assertFalse($this->findProduct('Saucisse')->isSoldAlone());
        self::assertTrue($this->findProduct('Café')->isSoldAlone());
        $log = self::entityManager()->getRepository(AuditLog::class)->findOneBy(['action' => 'product.update']);
        self::assertSame(['sold_alone' => true], $log?->getBefore());
        self::assertSame(['sold_alone' => false], $log->getAfter());
    }

    public function testSoldAloneOnTheArticlePageButNotOnFormulas(): void
    {
        self::createEvent();
        $sausage = $this->createProduct('Saucisse', 200);
        $formula = $this->createProduct('Menu', 500, position: 2)->setComponentQuantity($sausage, 2);
        self::entityManager()->flush();

        $this->client->request('GET', '/admin/menu/'.$sausage->getId().'/modifier');
        $this->client->submitForm('Enregistrer l\'article', ['alone' => false]);
        self::assertResponseRedirects();
        self::assertFalse($this->findProduct('Saucisse')->isSoldAlone());

        $crawler = $this->client->request('GET', '/admin/menu/'.$formula->getId().'/modifier');
        self::assertCount(0, $crawler->filterXPath('//input[@name="alone"]'));
        $this->client->submitForm('Enregistrer la formule');
        self::assertResponseRedirects();
        self::assertTrue($this->findProduct('Menu')->isSoldAlone(), 'une formule est toujours vendue seule');

        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertCount(0, $crawler->filterXPath('//input[@name="products['.$formula->getId().'][alone]"]'));
    }

    public function testArticleSoldNeitherAloneNorInAFormulaIsReported(): void
    {
        self::createEvent();
        $this->createProduct('Saucisse', 200)->setSoldAlone(false);
        self::entityManager()->flush();

        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertStringContainsString('« Saucisse » n\'est vendu ni seul ni dans une formule', $crawler->filterXPath('//*[@id="unsellable"]')->text());

        $sausage = $this->findProduct('Saucisse');
        $this->createProduct('Menu', 500, position: 2)->setComponentQuantity($sausage, 2);
        self::entityManager()->flush();
        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertCount(0, $crawler->filterXPath('//*[@id="unsellable"]'));
    }

    private function createProduct(string $name, int $priceCents, ?int $stock = null, int $position = 1, ?Event $event = null): Product
    {
        $event = self::entityManager()->find(Event::class, ($event ?? self::entityManager()->getRepository(Event::class)->findOneBy(['active' => true]))->getId());
        $product = (new Product($event, $name))->setPriceCents($priceCents)->setInitialStock($stock)->setPosition($position);
        self::entityManager()->persist($product);
        self::entityManager()->flush();

        return $product;
    }

    /** Jeton CSRF lu dans le formulaire de la page, comme le ferait le navigateur. */
    private function tokenOnPage(string $url, ?string $formAction = null): string
    {
        $crawler = $this->client->request('GET', $url);
        $form = null === $formAction ? '//form[not(@action)]' : \sprintf('//form[@action="%s"]', $formAction);

        return $crawler->filterXPath($form.'//input[@name="_token"]')->attr('value');
    }

    /** @return list<Product> */
    private function orderedProducts(): array
    {
        self::entityManager()->clear();

        return $this->productRepository()->findBy([], ['position' => 'ASC', 'id' => 'ASC']);
    }

    private function productRepository(): \Doctrine\ORM\EntityRepository
    {
        return self::entityManager()->getRepository(Product::class);
    }

    private function findProduct(string $name): Product
    {
        self::entityManager()->clear();

        return $this->productRepository()->findOneBy(['name' => $name]);
    }

    private function findProductById(int $id): Product
    {
        self::entityManager()->clear();

        return $this->productRepository()->find($id);
    }

    private function auditCount(string $action): int
    {
        return self::entityManager()->getRepository(AuditLog::class)->count(['action' => $action]);
    }

    private function content(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
