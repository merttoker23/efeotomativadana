<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';
$twig = new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__, 3).'/templates'), ['autoescape' => 'html']);
echo '<!doctype html><html lang="tr"><meta charset="utf-8"><link rel="stylesheet" href="/storefront.css"><body>';
$tabs = [];
foreach (['Çok Satanlar', 'Popüler', 'İndirimdekiler', 'Öne Çıkanlar'] as $i => $title) {
    $tabs[] = ['title' => $title, 'products' => [], 'source' => ['emptyMessage' => 'Empty source '.$i]];
}
echo $twig->render('storefront/home/sections/_product_tabs.html.twig', ['section' => ['title' => 'Ürünler', 'subtitle' => '', 'data' => ['tabs' => $tabs]]]);
echo '<script type="importmap">{"imports":{"@hotwired/stimulus":"/stimulus.js"}}</script><script type="module">import { Application } from "@hotwired/stimulus"; import Tabs from "/tabs.js"; Application.start().register("home-tabs", Tabs);</script></body></html>';
