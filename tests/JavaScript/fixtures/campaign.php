<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

$twig = new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__, 3).'/templates'), ['autoescape' => 'html']);
$twig->addFunction(new Twig\TwigFunction('media_url', static fn (string $path): string => $path));
echo '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="/storefront.css"><body><button id="opener">Önceki odak</button>';
echo $twig->render('storefront/home/sections/_announcement_bar.html.twig', ['section' => ['data' => ['items' => ['Aynı gün kargo', '1.500 TL üzeri ücretsiz kargo', 'Güvenli alışveriş', 'Kolay iade']]]]);
echo $twig->render('storefront/home/sections/_popup_ad.html.twig', ['section' => ['title' => 'Kampanya', 'data' => ['description' => str_repeat('Güvenli alışveriş. ', 30), 'image' => '/uploads/cms/'.str_repeat('a', 32).'.png', 'mobileImage' => '/uploads/cms/'.str_repeat('b', 32).'.png', 'cta' => 'Ürünleri keşfet', 'link' => '/urunler', 'delay' => 0.15, 'allowDismiss' => true, 'campaignKey' => 'test-campaign-1']]]);
// Reuse the real gallery dialog markup so its close control shares the same visual check.
$productTemplate = file_get_contents(dirname(__DIR__, 3).'/templates/storefront/catalog/product.html.twig');
preg_match('~<dialog id="product-image-dialog".*?</dialog>~s', $productTemplate, $gallery);
echo $twig->createTemplate($gallery[0])->render(['product' => ['name' => 'Örnek ürün']]);
echo '<form id="admin-rows">'.$twig->render('admin/cms/home/_rows.html.twig', ['type' => App\Module\Cms\HomeSectionType::AnnouncementBar, 'items' => [['text' => 'Kargo'], ['text' => 'İade']], 'query' => '', 'options' => [], 'labels' => [], 'library' => []]).'</form>';
echo '<script type="importmap">{"imports":{"@hotwired/stimulus":"/stimulus.js"}}</script><script type="module">import { Application } from "@hotwired/stimulus"; import Popup from "/popup.js"; import Rows from "/rows.js"; const connect = Popup.prototype.connect; Popup.prototype.connect = function() { window.connectedAt = performance.now(); connect.call(this); }; window.app = Application.start(); app.register("campaign-popup", Popup); app.register("home-section-form", Rows); document.querySelector("#opener").focus();</script></body></html>';
