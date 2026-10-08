<div align="center">

# Efe Otomotiv Adana

**Otomotiv Yedek Parça E-Ticaret Platformu**

[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/Symfony-8.1-000000?logo=symfony&logoColor=white)](https://symfony.com/)
[![MySQL](https://img.shields.io/badge/MySQL-8.4-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker&logoColor=white)](https://www.docker.com/)
[![Son commit](https://img.shields.io/github/last-commit/merttoker23/efeotomativadana?label=Son%20commit)](https://github.com/merttoker23/efeotomativadana/commits/main)

[**Canlı Site**](https://efeotomotivadana.com)

</div>

## Proje Hakkında

**Efe Otomotiv Adana**, otomotiv yedek parçaları için özel olarak geliştirilen modern bir e-ticaret platformudur. Kullanıcı dostu mağaza arayüzünü; ürün ve stok yönetimi, tedarikçi entegrasyonları ve kapsamlı bir yönetim paneliyle bir araya getirir.

## Öne Çıkan Özellikler

- **Gelişmiş ürün arama** — Ürün adı, SKU, OEM ve parça koduyla arama; kategori ve marka bazlı keşif.
- **Alışveriş deneyimi** — Sepet, istek listesi, ürün karşılaştırma ve müşteri hesapları.
- **Sipariş yönetimi** — Sipariş, ödeme, kargo ve iade süreçleri.
- **B2B entegrasyonu** — Ürün ve stok bilgilerinin tedarikçi sistemiyle senkronizasyonu.
- **İçerik ve SEO yönetimi** — Ana sayfa bölümleri, bannerlar, blog ve arama motoru ayarları.
- **Duyarlı tasarım** — Masaüstü, tablet ve mobil cihazlara uyumlu arayüz.

## Kullanılan Teknolojiler

| Katman | Teknolojiler |
| --- | --- |
| Sunucu tarafı | PHP 8.4 · Symfony 8.1 · Doctrine ORM |
| Arayüz | Twig · Symfony UX · Stimulus · Turbo |
| Veritabanı | MySQL 8.4 |
| Altyapı | Docker Compose · FrankenPHP · Caddy |

## Hızlı Kurulum

**Gereksinimler:** Git, Docker ve Docker Compose.

```bash
git clone https://github.com/merttoker23/efeotomativadana.git
cd efeotomativadana
cp .env .env.local
```

`.env.local` dosyasında `APP_SECRET`, `MYSQL_PASSWORD` ve `MYSQL_ROOT_PASSWORD` değişkenlerine güvenli, size özel değerler atayın. Ardından yerel geliştirme ortamını başlatın:

```bash
docker compose --env-file .env.local up -d --build
docker compose --env-file .env.local exec app php bin/console doctrine:migrations:migrate --no-interaction
```

Uygulamaya yerel ortamda **http://localhost:8080** adresinden erişebilirsiniz.

---

<div align="center">
  <sub><a href="https://github.com/merttoker23">Mert Toker</a> tarafından geliştirilmektedir.</sub>
</div>
