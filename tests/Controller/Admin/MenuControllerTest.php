<?php

namespace App\Tests\Controller\Admin;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Entity\Event;
use App\Entity\Product;
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
        self::assertEqualsCanonicalizing(['Hot-dog', 'Soda canette', 'Crêpe sucre'], $formula->getComponents()->map(static fn (Product $p) => $p->getName())->toArray());

        // Une seconde fois : refusé, le menu n'est pas vide
        $this->client->request('POST', '/admin/menu/exemple', ['_token' => $token]);
        self::assertSame(12, $this->productRepository()->count());
    }

    public function testAddProduct(): void
    {
        self::createEvent();
        $this->createProduct('Café', 100, position: 4);

        $crawler = $this->client->request('GET', '/admin/menu');
        $this->client->submit($crawler->selectButton('Ajouter l\'article')->form([
            'icon' => '🥤',
            'name' => ' Soda ',
            'category' => '',
            'price' => '2,5',
            'stock' => '',
            'kitchen' => false,
            'deposit' => true,
        ]));

        self::assertResponseRedirects('/admin/menu');
        $soda = $this->findProduct('Soda');
        self::assertSame(250, $soda->getPriceCents());
        self::assertNull($soda->getInitialStock());
        self::assertSame('Divers', $soda->getCategory());
        self::assertFalse($soda->goesToKitchen());
        self::assertTrue($soda->hasDeposit());
        self::assertSame(5, $soda->getPosition());
        self::assertSame(1, $this->auditCount('product.create'));
    }

    public function testAddInvalidProduct(): void
    {
        self::createEvent();

        $crawler = $this->client->request('GET', '/admin/menu');
        $this->client->submit($crawler->selectButton('Ajouter l\'article')->form([
            'name' => '',
            'price' => '2,505',
            'stock' => '-3',
        ]));

        self::assertResponseStatusCodeSame(422);
        $content = $this->content();
        self::assertStringContainsString('Indiquez le nom', $content);
        self::assertStringContainsString('Prix en euros', $content);
        self::assertStringContainsString('Nombre entier, ou vide', $content);
        self::assertSame(0, $this->productRepository()->count());
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
            'products' => [$otherProduct->getId() => ['position' => '1', 'name' => 'Piraté', 'price' => '0', 'stock' => '']],
        ]);

        self::assertSame('Bonbons', $this->findProductById($otherProduct->getId())->getName());
    }

    public function testFormulaComponents(): void
    {
        self::createEvent();
        $hotdog = $this->createProduct('Hot-dog', 300, stock: 80);
        $soda = $this->createProduct('Soda', 150, stock: 96);
        $menu = $this->createProduct('Menu', 450, stock: 10);

        $this->client->request('POST', '/admin/menu/'.$menu->getId().'/composants', [
            '_token' => $this->tokenOnPage('/admin/menu/'.$menu->getId().'/composants'),
            'components' => [(string) $hotdog->getId(), (string) $soda->getId()],
        ]);

        self::assertResponseRedirects('/admin/menu');
        $menu = $this->findProduct('Menu');
        self::assertTrue($menu->isFormula());
        self::assertNull($menu->getInitialStock(), 'Le stock d\'une formule se déduit de ses composants');
        self::assertSame(1, $this->auditCount('product.components'));

        // Un composant ne peut pas devenir une formule
        $this->client->request('GET', '/admin/menu/'.$hotdog->getId().'/composants');
        self::assertStringContainsString('fait déjà partie d\'une formule', $this->content());
    }

    public function testFormulaCannotContainItselfOrAnotherFormula(): void
    {
        self::createEvent();
        $hotdog = $this->createProduct('Hot-dog', 300);
        $menu = $this->createProduct('Menu', 450);
        $menu->addComponent($hotdog);
        self::entityManager()->flush();
        $maxi = $this->createProduct('Maxi menu', 600);

        foreach ([$maxi->getId(), $menu->getId()] as $forbiddenId) {
            $this->client->request('POST', '/admin/menu/'.$maxi->getId().'/composants', [
                '_token' => $this->tokenOnPage('/admin/menu/'.$maxi->getId().'/composants'),
                'components' => [(string) $forbiddenId],
            ]);
            self::assertResponseStatusCodeSame(422);
        }

        self::assertFalse($this->findProduct('Maxi menu')->isFormula());
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
        self::assertSame(0, $this->productRepository()->count());
        self::assertSame(1, $this->auditCount('product.delete'));
    }

    public function testProductOfAnotherEventCannotBeRemoved(): void
    {
        $other = self::createEvent('Halloween', active: false);
        $bonbons = $this->createProduct('Bonbons', 100, event: $other);
        self::createEvent('Loto de Noël');

        $this->client->request('GET', '/admin/menu/'.$bonbons->getId().'/retirer');

        self::assertResponseStatusCodeSame(404);
    }

    public function testEveryFormRequiresCsrfToken(): void
    {
        self::createEvent();
        $cafe = $this->createProduct('Café', 100);
        $id = $cafe->getId();

        $requests = [
            ['/admin/menu', ['products' => [$id => ['name' => 'X', 'price' => '1', 'position' => '1']]]],
            ['/admin/menu/ajouter', ['name' => 'Thé', 'price' => '1']],
            ['/admin/menu/'.$id.'/composants', ['components' => []]],
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

    public function testDepositAmountIsShown(): void
    {
        $event = self::createEvent();
        $event->setDepositCents(100);
        self::entityManager()->flush();

        $this->client->request('GET', '/admin/menu');

        self::assertStringContainsString("Consigne : 1,00\u{00A0}€", $this->content());
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
