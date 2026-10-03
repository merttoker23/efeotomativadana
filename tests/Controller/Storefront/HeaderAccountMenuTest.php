<?php

declare(strict_types=1);

namespace App\Tests\Controller\Storefront;

use App\Entity\Customer\CustomerUser;
use App\Tests\ResetsRateLimits;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The header's account pill, which is a menu rather than a link.
 *
 * What the menu holds is the only thing that changes between a visitor and a customer, and both
 * answers have to be the store's own routes: the two ways in for someone who has no account, the
 * account's own pages plus a CSRF-protected POST for someone who has one. A logout link would be a
 * GET that a crawler, a prefetcher or a `<img>` could follow, so the menu must not contain one.
 */
final class HeaderAccountMenuTest extends WebTestCase
{
    use ResetsRateLimits;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetRateLimits();
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
        parent::tearDown();
    }

    private function clear(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement("DELETE FROM customer_user WHERE email = ?", ['menu@example.com']);
    }

    public function testAVisitorIsOfferedTheStoresOwnTwoWaysIn(): void
    {
        $crawler = $this->client->request('GET', '/yeni/');

        self::assertResponseIsSuccessful();

        $trigger = $crawler->filter('button[data-header-account-target="trigger"]');
        self::assertSame(1, $trigger->count(), 'The account pill is a button, because it opens something.');
        self::assertStringContainsString('Hesabım', $trigger->text());
        self::assertSame('false', $trigger->attr('aria-expanded'));
        self::assertSame('true', $trigger->attr('aria-haspopup'));
        self::assertSame('header-account-menu', $trigger->attr('aria-controls'));

        $menu = $crawler->filter('#header-account-menu');
        self::assertTrue($menu->filter('[hidden]')->count() > 0, 'The menu is closed until the visitor opens it.');

        self::assertSame(1, $menu->filter('a[href="/yeni/giris"]')->count());
        self::assertSame('Giriş Yap', trim($menu->filter('a[href="/yeni/giris"]')->text()));
        self::assertSame(1, $menu->filter('a[href="/yeni/kayit"]')->count());
        self::assertSame('Kayıt Ol', trim($menu->filter('a[href="/yeni/kayit"]')->text()));

        self::assertSame(0, $menu->filter('form')->count(), 'A visitor has nothing to sign out of.');
        self::assertSame(0, $menu->filter('a[href*="hesabim"]')->count(), 'A visitor has no account pages to be offered.');
    }

    public function testACustomerIsOfferedTheirAccountAndASafeSignOut(): void
    {
        $this->signIn();
        $crawler = $this->client->request('GET', '/yeni/');

        self::assertResponseIsSuccessful();

        $menu = $crawler->filter('#header-account-menu');
        self::assertSame(1, $menu->count());

        self::assertSame(0, $menu->filter('a[href="/yeni/giris"]')->count());
        self::assertSame(0, $menu->filter('a[href="/yeni/kayit"]')->count());

        foreach ([
            '/yeni/hesabim',
            '/yeni/hesabim/profil',
            '/yeni/hesabim/adresler',
            '/yeni/hesabim/siparisler',
            '/yeni/hesabim/iadeler',
            '/yeni/hesabim/puanlarim',
        ] as $path) {
            self::assertSame(1, $menu->filter('a[href="'.$path.'"]')->count(), 'The menu does not offer '.$path.'.');
        }

        $form = $menu->filter('form');
        self::assertSame(1, $form->count());
        self::assertSame('POST', strtoupper((string) $form->attr('method')));
        self::assertSame('/yeni/cikis', $form->attr('action'));
        self::assertSame(1, $form->filter('input[name="_csrf_token"]')->count(), 'Signing out carries its CSRF token.');
        self::assertSame('Çıkış Yap', trim($form->filter('button')->text()));
        self::assertSame(0, $menu->filter('a[href="/yeni/cikis"]')->count(), 'Signing out is never a link.');
    }

    /** The dropdown is a desktop control; a small screen keeps the menu row it already had. */
    public function testTheSmallScreensOwnAccountRowIsUntouched(): void
    {
        $crawler = $this->client->request('GET', '/yeni/');

        self::assertSame(1, $crawler->filter('#mobile-navigation a[href="/yeni/giris"]')->count());
    }

    private function signIn(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $customer = new CustomerUser('menu@example.com', 'Menü', 'Müşterisi');
        $customer->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, 'menu-only-password'),
        );
        $manager->persist($customer);
        $manager->flush();

        $this->client->loginUser($customer, 'main');
    }
}
