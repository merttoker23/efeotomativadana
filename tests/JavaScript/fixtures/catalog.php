<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';
$request = Symfony\Component\HttpFoundation\Request::create('http://localhost'.($argv[1] ?? '/yeni/katalog'));
$request->attributes->set('_route', 'storefront_catalog_index');
$criteria = App\Module\Catalog\Query\CatalogCriteria::fromQuery($request->query);
$twig = new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__, 3).'/templates'), ['autoescape' => 'html']);
$twig->addExtension(new App\Twig\StorefrontMoneyExtension());
$twig->addFunction(new Twig\TwigFunction('path', static function (string $route, array $parameters): string {
    $fragment = $parameters['_fragment'] ?? null;
    unset($parameters['_fragment']);
    $path = '/yeni/katalog';
    if (isset($parameters['slug'])) {
        $path = '/yeni/'.(str_contains($route, 'brand') ? 'marka/' : 'kategori/').$parameters['slug'];
        unset($parameters['slug']);
    }
    return $path.($parameters ? '?'.http_build_query($parameters) : '').($fragment ? '#'.$fragment : '');
}));
$source = file_get_contents(dirname(__DIR__, 3).'/templates/storefront/catalog/index.html.twig');
preg_match_all('~<form\b.*?</form>~s', $source, $forms);
$filter = $forms[0][0];
$sort = $forms[0][1];
$data = ['app' => ['request' => $request], 'criteria' => $criteria, 'route_filter' => null,
    'price_bounds' => ['min' => 1, 'max' => 39_973_500],
    'categories' => [['slug' => 'parts', 'name' => 'Parçalar', 'productCount' => 1]],
    'brands' => [['slug' => 'brand', 'name' => 'Marka', 'productCount' => 1]]];
echo '<!doctype html><html lang="tr"><meta charset="utf-8"><body>';
echo $twig->createTemplate($filter)->render($data);
echo '<section id="catalog-results" tabindex="-1">'.$twig->createTemplate($sort)->render($data).'</section>';
echo '<script type="importmap">{"imports":{"@hotwired/stimulus":"/stimulus.js"}}</script><script type="module">import { Application } from "@hotwired/stimulus"; import Filters from "/filters.js"; import Range from "/range.js"; const app = Application.start(); app.register("catalog-filters", Filters); app.register("catalog-price-range", Range);</script></body></html>';
