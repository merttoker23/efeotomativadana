<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testHomeRespondsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/yeni/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Efe Otomotiv Adana');
    }
}
