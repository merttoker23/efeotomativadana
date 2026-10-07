<?php

declare(strict_types=1);

namespace App\Tests\Controller\Storefront;

use App\Entity\Cms\FaqItem;
use App\Entity\Customer\CustomerUser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FaqPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM cms_faq_item');
        $this->manager = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnonymousFooterHasRealReturnsAndFaqLinksAndReturnsStillRequiresLogin(): void
    {
        $crawler = $this->client->request('GET', '/sikca-sorulan-sorular');

        self::assertResponseIsSuccessful();
        $service = $crawler->filter('section[aria-labelledby="footer-service-title"]');
        self::assertCount(3, $service->filter('a'));
        self::assertSame('/hesabim/iadeler', $service->selectLink('İade ve değişim')->attr('href'));
        self::assertSame('/sikca-sorulan-sorular', $service->selectLink('Sıkça sorulan sorular')->attr('href'));

        $this->client->request('GET', '/hesabim/iadeler');
        self::assertResponseRedirects('/giris');
    }

    public function testSignedInCustomerCanReachReturnsFromTheSameProtectedRoute(): void
    {
        $customer = new CustomerUser('faq-footer-customer@example.com', 'Efe', 'Müşteri');
        $customer->setPassword('test-only-hash');
        $this->manager->persist($customer);
        $this->manager->flush();

        $this->client->loginUser($customer, 'main');
        $this->client->request('GET', '/hesabim/iadeler');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'İadelerim');
    }

    public function testStorefrontShowsOnlyActiveFaqsInStableSortOrderAndEscapesAnswers(): void
    {
        $last = $this->faq('Son soru', 'Son cevap', true, 20);
        $first = $this->faq('İlk soru', "İlk satır\n<script>alert('x')</script>", true, 10);
        $second = $this->faq('Aynı sıradaki ikinci soru', '<b>Kalın çalışmamalı</b>', true, 10);
        $hidden = $this->faq('Gizli soru', 'Görünmemeli', false, 0);
        self::assertNotNull($last->id());
        self::assertNotNull($first->id());
        self::assertNotNull($second->id());
        self::assertNotNull($hidden->id());

        $crawler = $this->client->request('GET', '/sikca-sorulan-sorular');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['İlk soru', 'Aynı sıradaki ikinci soru', 'Son soru'],
            $crawler->filter('.faq-item > summary')->each(static fn ($node): string => $node->text()),
        );
        self::assertSelectorTextNotContains('main', 'Gizli soru');
        self::assertCount(0, $crawler->filter('.faq-answer script'));
        self::assertCount(0, $crawler->filter('.faq-answer b'));
        self::assertSelectorTextContains('.faq-answer', "<script>alert('x')</script>");
        self::assertStringContainsString('<br', (string) $this->client->getResponse()->getContent());
    }

    public function testFaqKeepsNativeAccordionMarkupAndMobileOverflowGuards(): void
    {
        $this->faq(str_repeat('Uzun soru ', 18), str_repeat('uzuncavapkelimesi ', 30), true, 0);

        $crawler = $this->client->request('GET', '/sikca-sorulan-sorular');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('details.faq-item > summary'));
        self::assertStringNotContainsString('data-controller=', (string) $crawler->filter('.faq-list')->html());
        self::assertStringContainsString('@media (max-width: 680px)', $html);
        self::assertStringContainsString('min-width: 0', $html);
        self::assertStringContainsString('overflow-wrap: anywhere', $html);
    }

    private function faq(string $question, string $answer, bool $active, int $sortOrder): FaqItem
    {
        $item = new FaqItem($question, $answer);
        $item->setActive($active);
        $item->setSortOrder($sortOrder);
        $this->manager->persist($item);
        $this->manager->flush();

        return $item;
    }
}
