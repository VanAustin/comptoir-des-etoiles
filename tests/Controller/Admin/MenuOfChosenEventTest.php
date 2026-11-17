<?php

namespace App\Tests\Controller\Admin;

use App\Entity\AdminUser;
use App\Entity\AuditLog;
use App\Entity\Event;
use App\Entity\Product;
use App\Tests\FixturesTrait;
use App\Tests\ResetDatabaseTrait;
use App\Tests\RevisionsTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Menu d'un événement choisi (préparer le Loto sans l'activer) et
 * « Reprendre le menu d'un autre événement ».
 */
class MenuOfChosenEventTest extends WebTestCase
{
    use FixturesTrait;
    use ResetDatabaseTrait;
    use RevisionsTrait;

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

    public function testActiveEventByDefaultThenAnyChosenEvent(): void
    {
        $stand = self::createEvent('Stand de Noël', '+20 days');
        $loto = self::createEvent('Loto', '+30 days', active: false);
        $halloween = self::createEvent('Halloween', '-20 days', active: false);

        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertSame((string) $stand->getId(), $crawler->filterXPath('//select[@name="evenement"]/option[@selected]')->attr('value'));
        self::assertStringContainsString('Les tablettes voient les changements tout de suite.', $this->content());

        $crawler = $this->client->request('GET', '/admin/menu?evenement='.$loto->getId());
        self::assertResponseIsSuccessful();
        self::assertSame((string) $loto->getId(), $crawler->filterXPath('//select[@name="evenement"]/option[@selected]')->attr('value'));
        self::assertStringContainsString('En préparation', $this->content());
        self::assertStringContainsString('les tablettes ne voient pas ce menu', $this->content());
        self::assertCount(1, $crawler->filterXPath('//a[@href="/admin/evenements/'.$loto->getId().'/activer"]'), 'activation : action séparée, avec sa confirmation');

        $this->client->request('GET', '/admin/menu?evenement='.$halloween->getId());
        self::assertStringContainsString('Terminé', $this->content());

        foreach (['999999', 'abc', ''] as $id) {
            $this->client->request('GET', '/admin/menu?evenement='.$id);
            self::assertResponseStatusCodeSame(404, $id);
        }
    }

    public function testWithoutActiveEventTheLatestIsShown(): void
    {
        self::createEvent('Halloween', '-20 days', active: false);
        $loto = self::createEvent('Loto', '+30 days', active: false);

        $crawler = $this->client->request('GET', '/admin/menu');

        self::assertResponseIsSuccessful();
        self::assertSame((string) $loto->getId(), $crawler->filterXPath('//select[@name="evenement"]/option[@selected]')->attr('value'));
    }

    public function testPrepareAnInactiveEventWithoutChangingWhatTheTabletsSee(): void
    {
        $stand = self::createEvent('Stand de Noël');
        $this->createProduct($stand, 'Vin chaud', 250);
        $loto = self::createEvent('Loto', '+30 days', active: false);
        [, $token] = self::createPairedDevice($stand);

        $etag = $this->pollMenu($token);
        self::assertResponseIsSuccessful();
        $active = self::revisionsOf();

        // Ajouter un article au Loto, en passant par l'écran
        $crawler = $this->client->request('GET', '/admin/menu?evenement='.$loto->getId());
        $this->client->click($crawler->selectLink('Ajouter un article')->link());
        $this->client->submitForm('Ajouter l\'article', ['name' => 'Hot-dog', 'price' => '3']);
        $hotdog = $this->findProduct('Hot-dog');
        self::assertSame($loto->getId(), $hotdog->getEvent()->getId());
        self::assertResponseRedirects('/admin/menu?evenement='.$loto->getId().'#produit-'.$hotdog->getId());

        // Puis modifier son prix dans le tableau
        $crawler = $this->client->request('GET', '/admin/menu?evenement='.$loto->getId());
        $form = $crawler->selectButton('Enregistrer le menu')->form();
        $form['products['.$hotdog->getId().'][price]'] = '3,50';
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/menu?evenement='.$loto->getId());
        self::assertSame(350, $this->findProduct('Hot-dog')->getPriceCents());
        self::assertSame(1, self::entityManager()->getRepository(AuditLog::class)->count(['action' => 'product.update']), 'changement de prix tracé');

        // La caisse de l'événement actif : rien de changé (304), ni dans son menu
        self::assertRevisionsMoved($active, 0, 0);
        $this->pollMenu($token, $etag);
        self::assertResponseStatusCodeSame(304);
        $this->pollMenu($token);
        self::assertStringNotContainsString('Hot-dog', $this->content());
    }

    public function testCopyMenuWithFormulasRelinkedToTheCopies(): void
    {
        $halloween = self::createEvent('Halloween', '-20 days', active: false);
        $soda = $this->createProduct($halloween, 'Soda', 150, 'Boissons', stock: 96)->setHasDeposit(true);
        $crepe = $this->createProduct($halloween, 'Crêpe sucre', 200, 'Crêpes', stock: 70, position: 2)->setGoesToKitchen(true)->setIcon('crepe');
        $saucisse = $this->createProduct($halloween, 'Saucisse', 0, 'Crêpes', stock: 40, position: 3)->setSoldAlone(false);
        $this->createProduct($halloween, 'Ancienne crêpe', 100, 'Crêpes', position: 4)->archive();
        $gouter = $this->createProduct($halloween, 'Goûter', 400, 'Formules', position: 5)
            ->setComponentQuantity($crepe, 2)->setComponentQuantity($soda, 1)->setComponentQuantity($saucisse, 1);
        self::entityManager()->flush();

        self::createEvent('Stand de Noël');
        $loto = self::createEvent('Loto', '+30 days', active: false);
        $this->createProduct($loto, 'Café', 100, 'Boissons', position: 7);
        $existingSoda = $this->createProduct($loto, 'soda', 180, 'Boissons', position: 8);
        $active = self::revisionsOf();
        $source = self::revisionsOf($halloween->getId());
        $target = self::revisionsOf($loto->getId());

        $base = '/admin/menu/reprendre?evenement='.$loto->getId();
        $crawler = $this->client->request('GET', '/admin/menu?evenement='.$loto->getId());
        $this->client->click($crawler->selectLink('Reprendre le menu d\'un autre événement')->link());
        self::assertResponseIsSuccessful();
        self::assertSame(['Halloween'], $this->client->getCrawler()->filterXPath('//select[@name="depuis"]/option')->each(static fn ($o) => explode(' · ', $o->text())[0]), 'ni l\'événement lui-même, ni un événement sans menu');

        $crawler = $this->client->request('GET', $base.'&depuis='.$halloween->getId());
        self::assertSame(['Boissons', 'Crêpes', 'Formules'], $crawler->filterXPath('//input[@name="categories[]"]')->each(static fn ($i) => $i->attr('value')));
        self::assertStringContainsString('Crêpes (2 articles)', $this->content(), 'article retiré non compté');

        $crawler = $this->client->request('GET', $base.'&depuis='.$halloween->getId().'&voir=1&categories[]=Crêpes&categories[]=Formules');
        $page = $this->content();
        self::assertStringContainsString('3 articles seront ajoutés', $page);
        self::assertStringNotContainsString('Ancienne crêpe', $page);
        self::assertStringContainsString('Déjà dans ce menu sous le même nom', $page);
        self::assertSame(0, $this->productsOf($loto, 'Crêpe sucre'), 'rien de copié avant la confirmation');

        $this->client->submit($crawler->selectButton('Copier 3 articles')->form());
        self::assertResponseRedirects('/admin/menu?evenement='.$loto->getId());
        $this->client->followRedirect();
        self::assertStringContainsString('3 articles sont repris du menu de « Halloween ».', $this->content());

        self::entityManager()->clear();
        $menu = self::entityManager()->getRepository(Product::class)->findBy(['event' => $loto->getId()], ['position' => 'ASC']);
        self::assertSame(['Café', 'soda', 'Crêpe sucre', 'Saucisse', 'Goûter'], array_map(static fn (Product $p) => $p->getName(), $menu), 'à la suite, dans l\'ordre d\'origine');
        [, , $newCrepe, $newSaucisse, $newGouter] = $menu;
        self::assertSame([200, 70, true, 'crepe', 'Crêpes', false], [$newCrepe->getPriceCents(), $newCrepe->getInitialStock(), $newCrepe->goesToKitchen(), $newCrepe->getIcon(), $newCrepe->getCategory(), $newCrepe->hasDeposit()]);
        self::assertFalse($newSaucisse->isSoldAlone());
        self::assertSame(400, $newGouter->getPriceCents());
        self::assertSame('1 × soda, 2 × Crêpe sucre, 1 × Saucisse', $newGouter->describeComposition(), 'dans l\'ordre du menu du Loto');
        foreach ($newGouter->getComponents() as $component) {
            self::assertSame($loto->getId(), $component->getComponent()->getEvent()->getId(), 'relié aux articles du Loto, jamais à ceux d\'Halloween');
        }
        self::assertSame($existingSoda->getId(), $this->componentNamed($newGouter, 'soda')->getId(), 'article existant réutilisé');
        self::assertSame(180, $this->componentNamed($newGouter, 'soda')->getPriceCents(), 'prix existant gardé');

        // Halloween intact, commandes jamais copiées, tout tracé
        self::assertSame('1 × Soda, 2 × Crêpe sucre, 1 × Saucisse', self::entityManager()->find(Product::class, $gouter->getId())->describeComposition());
        self::assertSame(0, (int) self::getContainer()->get('doctrine')->getConnection()->fetchOne('SELECT COUNT(*) FROM orders WHERE event_id = ?', [$loto->getId()]));
        $audit = self::entityManager()->getRepository(AuditLog::class);
        self::assertSame(3, $audit->count(['action' => 'product.create']));
        $log = $audit->findOneBy(['action' => 'menu.copy']);
        self::assertSame('event:'.$loto->getId().' Loto', $log->getSubject());
        self::assertSame(3, $log->getAfter()['created']);
        self::assertSame(['soda'], $log->getAfter()['reused']);

        self::assertRevisionsMoved($active, 0, 0, message: 'événement actif : rien de changé');
        self::assertRevisionsMoved($source, 0, 0, $halloween->getId());
        self::assertRevisionsMoved($target, 0, 1, $loto->getId());
    }

    public function testComponentOfAnUncheckedCategoryIsCopiedAndShown(): void
    {
        $halloween = self::createEvent('Halloween', '-20 days', active: false);
        $soda = $this->createProduct($halloween, 'Soda', 150, 'Boissons');
        $this->createProduct($halloween, 'Duo', 300, 'Formules', position: 2)->setComponentQuantity($soda, 2);
        self::entityManager()->flush();
        $stand = self::createEvent('Stand de Noël');
        $before = self::revisionsOf();

        $crawler = $this->client->request('GET', '/admin/menu/reprendre?depuis='.$halloween->getId().'&voir=1&categories[]=Formules');
        self::assertStringContainsString('ajouté : contenu dans une formule', $this->content());
        self::assertStringContainsString('Cet événement est actif', $this->content());
        $this->client->submit($crawler->selectButton('Copier 2 articles')->form());

        self::assertResponseRedirects('/admin/menu');
        $duo = self::entityManager()->getRepository(Product::class)->findOneBy(['event' => $stand->getId(), 'name' => 'Duo']);
        self::assertSame('2 × Soda', $duo->describeComposition());
        self::assertSame($stand->getId(), $this->componentNamed($duo, 'Soda')->getEvent()->getId());
        self::assertRevisionsMoved($before, 0, 1, message: 'événement actif : les caisses voient les articles copiés');
    }

    public function testNothingCheckedOrNothingToCopy(): void
    {
        $halloween = self::createEvent('Halloween', '-20 days', active: false);
        $this->createProduct($halloween, 'Soda', 150, 'Boissons');
        $this->createProduct($halloween, 'Crêpe', 200, 'Crêpes', position: 2);
        $stand = self::createEvent('Stand de Noël');
        $this->createProduct($stand, 'SODA', 200, 'Boissons');

        $this->client->request('GET', '/admin/menu/reprendre?depuis='.$halloween->getId().'&voir=1');
        self::assertStringContainsString('Cochez au moins une catégorie.', $this->content());

        $crawler = $this->client->request('GET', '/admin/menu/reprendre?depuis='.$halloween->getId().'&voir=1&categories[]=Boissons&categories[]=Inconnue');
        self::assertStringContainsString('Rien à copier', $this->content());
        self::assertCount(0, $crawler->filterXPath('//button[starts-with(normalize-space(), "Copier")]'));

        // Envoi forcé : rien de copié
        $this->client->request('POST', '/admin/menu/reprendre', [
            '_token' => $this->copyToken($stand, $halloween, 'Crêpes'),
            'depuis' => (string) $halloween->getId(),
            'categories' => ['Boissons'],
        ]);
        self::assertResponseRedirects('/admin/menu/reprendre?evenement='.$stand->getId());
        self::assertSame(1, $this->productsOf($stand));
        self::assertSame(0, self::entityManager()->getRepository(AuditLog::class)->count(['action' => 'menu.copy']));
    }

    public function testCannotCopyAnEventIntoItself(): void
    {
        $halloween = self::createEvent('Halloween', '-20 days', active: false);
        $this->createProduct($halloween, 'Crêpe', 200, 'Crêpes');
        $stand = self::createEvent('Stand de Noël');
        $this->createProduct($stand, 'Soda', 150, 'Boissons');

        $this->client->request('POST', '/admin/menu/reprendre', [
            '_token' => $this->copyToken($stand, $halloween, 'Crêpes'),
            'depuis' => (string) $stand->getId(),
            'categories' => ['Boissons'],
        ]);

        self::assertResponseRedirects();
        self::assertSame(1, $this->productsOf($stand));
    }

    public function testCopyNeedsAValidCsrfToken(): void
    {
        $halloween = self::createEvent('Halloween', '-20 days', active: false);
        $this->createProduct($halloween, 'Soda', 150, 'Boissons');
        $stand = self::createEvent('Stand de Noël');
        $loto = self::createEvent('Loto', '+30 days', active: false);
        $data = ['depuis' => (string) $halloween->getId(), 'categories' => ['Boissons']];

        $this->client->request('POST', '/admin/menu/reprendre', $data + ['_token' => 'jeton-invalide']);
        self::assertResponseStatusCodeSame(403);

        // Jeton d'un autre événement : refusé aussi
        $this->client->request('POST', '/admin/menu/reprendre?evenement='.$loto->getId(), $data + ['_token' => $this->copyToken($stand, $halloween, 'Boissons')]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(0, $this->productsOf($stand) + $this->productsOf($loto));
    }

    public function testAdministrationOnly(): void
    {
        $halloween = self::createEvent('Halloween', '-20 days', active: false);
        $this->createProduct($halloween, 'Soda', 150, 'Boissons');
        $loto = self::createEvent('Loto', '+30 days', active: false);
        $token = $this->copyToken($loto, $halloween, 'Boissons');
        $this->client->getCookieJar()->clear();

        foreach (['/admin/menu?evenement='.$loto->getId(), '/admin/menu/reprendre?evenement='.$loto->getId()] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/login', message: $url);
        }
        $this->client->request('POST', '/admin/menu/reprendre?evenement='.$loto->getId(), [
            '_token' => $token,
            'depuis' => (string) $halloween->getId(),
            'categories' => ['Boissons'],
        ]);
        self::assertResponseRedirects('/login');
        self::assertSame(0, $this->productsOf($loto));
    }

    /** Jeton de la confirmation, propre à l'événement de destination, lu sur sa page. */
    private function copyToken(Event $target, Event $source, string $category): string
    {
        $crawler = $this->client->request('GET', '/admin/menu/reprendre', [
            'evenement' => $target->getId(),
            'depuis' => $source->getId(),
            'voir' => 1,
            'categories' => [$category],
        ]);

        return $crawler->filterXPath('//form[@method="post"]//input[@name="_token"]')->attr('value');
    }

    private function pollMenu(string $token, ?string $etag = null): string
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        if (null !== $etag) {
            $server['HTTP_IF_NONE_MATCH'] = $etag;
        }
        $this->client->request('GET', '/api/menu', server: $server);

        return (string) $this->client->getResponse()->getEtag();
    }

    private function createProduct(Event $event, string $name, int $priceCents, string $category = 'Divers', ?int $stock = null, int $position = 1): Product
    {
        $event = self::entityManager()->find(Event::class, $event->getId());
        $product = (new Product($event, $name))->setPriceCents($priceCents)->setCategory($category)->setInitialStock($stock)->setPosition($position);
        self::entityManager()->persist($product);
        self::entityManager()->flush();

        return $product;
    }

    private function componentNamed(Product $formula, string $name): Product
    {
        foreach ($formula->getComponents() as $component) {
            if ($component->getComponent()->getName() === $name) {
                return $component->getComponent();
            }
        }
        self::fail('composant introuvable : '.$name);
    }

    private function productsOf(Event $event, ?string $name = null): int
    {
        return self::entityManager()->getRepository(Product::class)->count(['event' => $event->getId()] + (null === $name ? [] : ['name' => $name]));
    }

    private function findProduct(string $name): Product
    {
        self::entityManager()->clear();

        return self::entityManager()->getRepository(Product::class)->findOneBy(['name' => $name]);
    }

    private function content(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
