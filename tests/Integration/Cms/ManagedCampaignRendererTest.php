<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cms;

use App\Entity\Cms\HomeSection;
use App\Module\Cms\{HomepageRenderer, HomeSectionType};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ManagedCampaignRendererTest extends KernelTestCase
{
    public function testOnlyFirstEnabledPopupOccupiesOverlayAndContentChangesItsKey(): void
    {
        $previousServer = $_SERVER['DATABASE_URL'] ?? null;
        $previousEnv = $_ENV['DATABASE_URL'] ?? null;
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        try {
            self::bootKernel();
            $manager = self::getContainer()->get(EntityManagerInterface::class);
            (new SchemaTool($manager))->createSchema([$manager->getClassMetadata(HomeSection::class)]);
            $config = ['description' => 'Kampanya', 'image' => '', 'mobileImage' => '', 'cta' => '', 'link' => '', 'delay' => 5, 'allowDismiss' => true];
            $disabled = new HomeSection(HomeSectionType::PopupAd, 'Kapalı', $config);
            $first = new HomeSection(HomeSectionType::PopupAd, 'Birinci', $config);
            $first->setEnabled(true);
            $first->setSortOrder(10);
            $second = new HomeSection(HomeSectionType::PopupAd, 'İkinci', $config);
            $second->setEnabled(true);
            $second->setSortOrder(20);
            $features = new HomeSection(HomeSectionType::Features, 'Güvence', ['features' => [['title' => 'İade', 'description' => 'Kolay iade']]]);
            $features->setEnabled(true);
            $features->setSortOrder(15);
            foreach ([$disabled, $first, $second, $features] as $section) { $manager->persist($section); }
            $manager->flush();
            $renderer = self::getContainer()->get(HomepageRenderer::class);
            $view = $renderer->render();
            self::assertSame('Birinci', $view->popup?->title);
            self::assertCount(1, $view->blocks);
            self::assertSame('Güvence', $view->blocks[0]->title);
            $key = $view->popup->data['campaignKey'];
            self::assertSame($key, $renderer->render()->popup->data['campaignKey']);
            $first->update('Yeni kampanya', null, $config);
            $manager->flush();
            self::assertNotSame($key, $renderer->render()->popup->data['campaignKey']);
            $first->setEnabled(false);
            $manager->flush();
            self::assertSame('İkinci', $renderer->render()->popup->title);
            $second->setEnabled(false);
            $manager->flush();
            self::assertNull($renderer->render()->popup);
        } finally {
            self::ensureKernelShutdown();
            $_SERVER['DATABASE_URL'] = $previousServer;
            $_ENV['DATABASE_URL'] = $previousEnv;
        }
    }
}
