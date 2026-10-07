<?php

namespace App\Tests\Controller\Storefront;

use App\Module\Contact\ContactMailer;
use App\Module\Settings\SettingKey;
use App\Module\Settings\StoreConfiguration;
use App\Repository\Commerce\StoreSettingRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ContactControllerTest extends WebTestCase
{
    private Connection $db;

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function clientWithRecipient(?string $recipient = 'contact@example.com', string $dsn = 'smtp://mailer:1025'): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = self::createClient();
        $client->disableReboot();
        $client->setServerParameter('REMOTE_ADDR', '2001:db8:'.implode(':', str_split(bin2hex(random_bytes(12)), 4)));
        $container = self::getContainer();
        $this->db = $container->get(Connection::class);
        $this->db->beginTransaction();
        $container->get(StoreSettingRepository::class)->put(SettingKey::ContactEmail, $recipient);
        $container->get(EntityManagerInterface::class)->flush();
        $settings = $container->get(StoreConfiguration::class);
        $settings->reset();
        $mailer = $this->createMock(MailerInterface::class);
        if (null !== $recipient && 'contact@example.com' === $recipient && !str_contains($dsn, 'null://')) {
            $mailer->expects(self::once())->method('send')->with(self::callback(static function (Email $email): bool {
                return 'contact@example.com' === $email->getTo()[0]->getAddress()
                    && 'visitor@example.com' === $email->getReplyTo()[0]->getAddress()
                    && null === $email->getHtmlBody()
                    && str_contains($email->getTextBody(), '<b>Mesaj</b>');
            }));
        } else {
            $mailer->expects(self::never())->method('send');
        }
        $container->set(ContactMailer::class, new ContactMailer($settings, $container->get(ValidatorInterface::class), $mailer, $dsn));

        return $client;
    }

    public function testValidPostUsesConfiguredRecipientAndPrg(): void
    {
        $client = $this->clientWithRecipient();
        $crawler = $client->request('GET', '/iletisim');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('header a.contact[href="/iletisim"]');
        self::assertSelectorExists('footer a[href="/iletisim"]');
        self::assertSelectorCount(3, '.payments img');
        $client->submit($crawler->selectButton('Gönder')->form($this->values()));
        self::assertResponseRedirects('/iletisim', 303);
        $client->followRedirect();
        self::assertSelectorTextContains('.storefront-flash-success', 'Mesajınız alındı');
    }

    public function testMissingRecipientCannotSilentlySucceed(): void
    {
        $client = $this->clientWithRecipient(null);
        $crawler = $client->request('GET', '/iletisim');
        $client->submit($crawler->selectButton('Gönder')->form($this->values()));
        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextContains('.contact-errors', 'Mesajınız gönderilemedi');
        self::assertSelectorNotExists('.storefront-flash-success');
    }

    public function testInvalidRecipientCannotSilentlySucceed(): void
    {
        $client = $this->clientWithRecipient('invalid-address');
        $crawler = $client->request('GET', '/iletisim');
        $client->submit($crawler->selectButton('Gönder')->form($this->values()));
        self::assertResponseStatusCodeSame(503);
    }

    public function testNullTransportCannotDiscardMessage(): void
    {
        $client = $this->clientWithRecipient('contact@example.com', 'null://null');
        $crawler = $client->request('GET', '/iletisim');
        $client->submit($crawler->selectButton('Gönder')->form($this->values()));
        self::assertResponseStatusCodeSame(503);
    }

    public function testValidationCsrfAndPostRateLimit(): void
    {
        $client = $this->clientWithRecipient(null);
        $crawler = $client->request('GET', '/iletisim');
        $form = $crawler->selectButton('Gönder')->form($this->values());
        $form['contact[_token]'] = 'invalid';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorNotExists('.storefront-flash-success');
        for ($i = 0; $i < 5; ++$i) {
            $client->request('POST', '/iletisim', ['contact' => ['email' => 'invalid']]);
        }
        self::assertResponseStatusCodeSame(429);
        $client->setServerParameter('REMOTE_ADDR', '2001:db8:'.implode(':', str_split(bin2hex(random_bytes(12)), 4)));
        $client->request('POST', '/iletisim', ['contact' => ['email' => 'invalid']]);
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', '/iletisim');
        self::assertResponseIsSuccessful();
    }

    private function values(): array
    {
        return ['contact[firstName]' => 'Efe', 'contact[lastName]' => 'Ziyaretçi', 'contact[email]' => 'visitor@example.com', 'contact[phone]' => '+90 555 123 4567', 'contact[message]' => '<b>Mesaj</b>'];
    }
}
