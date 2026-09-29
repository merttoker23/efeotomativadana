<?php

declare(strict_types=1);

namespace App\Controller\Storefront;

use App\Module\Seo\RobotsTxtBuilder;
use App\Module\Seo\Sitemap\SitemapIndexBuilder;
use App\Module\Seo\Sitemap\SitemapKind;
use App\Module\Seo\Sitemap\SitemapSectionBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The addresses a crawler looks for by name.
 *
 * The paths here are relative, so the application's own import in config/routes.yaml registers
 * them under its `/yeni` prefix, and config/routes/seo.yaml registers the same actions again
 * at the domain root. A crawler asks for `/robots.txt` and `/sitemap.xml` at the root and has
 * no reason to know about a subdirectory; serving both costs one extra import and means the
 * document is findable wherever the front controller owns the request.
 */
final class SeoController extends AbstractController
{
    public function __construct(
        private readonly SitemapIndexBuilder $index,
        private readonly SitemapSectionBuilder $sections,
        private readonly RobotsTxtBuilder $robots,
    ) {
    }

    #[Route('/robots.txt', name: 'storefront_robots', methods: ['GET'])]
    public function robots(): Response
    {
        return new Response($this->robots->build(), Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    #[Route('/sitemap.xml', name: 'storefront_sitemap_index', methods: ['GET'])]
    public function sitemapIndex(): Response
    {
        return $this->xml($this->index->build());
    }

    #[Route('/sitemap-static.xml', name: 'storefront_sitemap_static', methods: ['GET'])]
    public function sitemapStatic(): Response
    {
        return $this->xml($this->sections->build(SitemapKind::Static, 1));
    }

    #[Route('/sitemap-{kind}-{page}.xml', name: 'storefront_sitemap_section', requirements: ['kind' => 'products|categories|brands|content', 'page' => '\d+'], methods: ['GET'])]
    public function sitemapSection(string $kind, string $page): Response
    {
        $sitemapKind = SitemapKind::tryFrom($kind);
        if (null === $sitemapKind) {
            throw $this->createNotFoundException();
        }

        $xml = $this->sections->buildOrNull($sitemapKind, (int) $page);
        if (null === $xml) {
            throw $this->createNotFoundException();
        }

        return $this->xml($xml);
    }

    /**
     * `text/xml` rather than `application/xml` because that is what every crawler and every
     * published sitemap guide uses, and a `Content-Type` a crawler does not recognise is a
     * document it declines to read.
     */
    private function xml(string $body): Response
    {
        return new Response($body, Response::HTTP_OK, [
            'Content-Type' => 'text/xml; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, follow',
        ]);
    }
}
