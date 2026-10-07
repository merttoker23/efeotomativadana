<?php

declare(strict_types=1);

namespace App\Tests\Controller\Loyalty;

use App\Entity\Customer\AdminUser;
use App\Entity\Customer\CustomerUser;
use App\Module\Loyalty\RewardService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class RewardControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private EntityManagerInterface $em;
    private CustomerUser $customer;
    private AdminUser $admin;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->db = self::getContainer()->get(Connection::class);
        $this->db->beginTransaction();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        $this->customer = new CustomerUser('reward-ui-'.bin2hex(random_bytes(5)).'@example.com', 'Efe', 'Customer');
        $this->customer->setPassword('test-hash');
        $this->admin = new AdminUser('reward-admin-'.bin2hex(random_bytes(5)).'@example.com');
        $this->admin->setPassword('test-hash');
        $this->em->persist($this->customer);
        $this->em->persist($this->admin);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function testAnonymousCannotReadRewardsOrAdminLedger(): void
    {
        $this->client->request('GET', '/hesabim/puanlarim');
        self::assertResponseRedirects();
        $this->client->request('GET', '/admin/puanlar');
        self::assertResponseRedirects();
    }

    public function testCustomerHistoryIsOwnedPaginatedAndPrivate(): void
    {
        $rewards = self::getContainer()->get(RewardService::class);
        for ($i = 0; $i < 26; ++$i) {
            $rewards->adjust($this->customer, 1, 'Own entry '.$i, $this->admin, 'entry-'.$i);
        }
        $foreign = new CustomerUser('other-reward-'.bin2hex(random_bytes(5)).'@example.com', 'Other', 'Customer');
        $foreign->setPassword('test-hash');
        $this->em->persist($foreign);
        $this->em->flush();
        $rewards->adjust($foreign, 1000, 'PRIVATE FOREIGN HISTORY', $this->admin, 'foreign');
        $this->client->loginUser($this->customer, 'main');
        $this->client->request('GET', '/hesabim/puanlarim');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(25, 'tbody tr');
        self::assertSelectorExists('a[rel="next"]');
        self::assertSelectorTextContains('p[role="status"]', '26');
        self::assertSelectorNotExists('body:contains("PRIVATE FOREIGN HISTORY")');
        self::assertSelectorExists('meta[name="robots"][content*="noindex"]');
        self::assertSelectorExists('#main-content');
        $this->client->request('GET', '/hesabim/puanlarim?page=2');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'tbody tr');
        self::assertSelectorExists('a[rel="prev"]');
    }

    public function testNonAdminCannotReadLedger(): void
    {
        $this->client->loginUser(new InMemoryUser('viewer@example.com', 'test-only-not-used-for-form-login', ['ROLE_USER']), 'admin');
        $this->client->request('GET', '/admin/puanlar');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanAdjustAndReplayBrowserSubmissionWithoutDuplicateEntry(): void
    {
        $this->client->loginUser($this->admin, 'admin');
        $url = '/admin/puanlar/'.$this->customer->id();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Record adjustment')->form(['reward_adjustment[points]' => 5, 'reward_adjustment[reason]' => 'Goodwill']);
        $payload = $form->getPhpValues();
        $this->client->submit($form);
        self::assertResponseRedirects($url);
        $this->client->request('POST', $url, $payload);
        self::assertResponseRedirects($url);
        self::assertSame(5, self::getContainer()->get(RewardService::class)->balance($this->customer));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM loyalty_reward_transaction WHERE customer_id = ?', [$this->customer->id()]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('tbody', $this->admin->getUserIdentifier());
    }

    public function testAdjustmentWithoutCsrfOrReasonCannotMutateBalance(): void
    {
        $this->client->loginUser($this->admin, 'admin');
        $url = '/admin/puanlar/'.$this->customer->id();
        $this->client->request('POST', $url, ['reward_adjustment' => ['points' => 5, 'reason' => 'Missing token', 'requestKey' => 'key']]);
        self::assertResponseStatusCodeSame(422);
        $crawler = $this->client->request('GET', $url);
        $form = $crawler->selectButton('Record adjustment')->form(['reward_adjustment[points]' => 5, 'reward_adjustment[reason]' => '']);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, self::getContainer()->get(RewardService::class)->balance($this->customer));
    }
}
