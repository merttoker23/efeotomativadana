<?php

declare(strict_types=1);

namespace App\Shared;

use App\Module\Settings\StoreConfiguration;
use App\Module\Settings\StorePhone;
use App\Repository\Cms\InformationPageRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Footer'ın okuduğu her şey, tek bir okuma katmanı üzerinden.
 *
 * Footer, `storefront/base.html.twig` içinde her sayfaya eklenir. Bilgi sayfaları buraya
 * ayrı ayrı eklendiğinde her controller'a bir depo sorgusu eklemek gerekirdi; bu okuma
 * katmanı sayesinde hiçbir controller değişmeden, tek bir servis üzerinden her sayfaya
 * ulaşır ve liste bir kez okunur.
 *
 * Bellek notu istek boyunca tutulur ve `kernel.reset` ile düşürülür — FrankenPHP ve Messenger
 * işçisi PHP sürecini birden çok istek boyunca ayakta tuttuğu için, notun süreç ömrü boyunca
 * yaşaması bir sonraki isteğin eski içeriği görmesine yol açardı.
 */
final class StorefrontFooter implements ResetInterface
{
    /**
     * Footer'da listelenen azami sayfa sayısı.
     *
     * Bir sınır olmadan bu liste, yayınlanan her sayfayla büyür ve footer o kadar büyür. Bu
     * sayı, gerçek bir mağazanın navigasyon sütununa sığdığından fazlası için başlıkların
     * uzunluğunu gerekçesiz kılar.
     */
    public const int MAX_INFORMATION_PAGES = 20;

    /**
     * @var array{
     *     informationPages: list<array{title: string, slug: string}>,
     *     contact: array{city: ?string, district: ?string, phone: ?string, phoneLink: ?string, email: ?string},
     * }|null
     */
    private ?array $read = null;

    public function __construct(
        private readonly InformationPageRepository $pages,
        private readonly StoreConfiguration $settings,
    ) {
    }

    public function reset(): void
    {
        $this->read = null;
    }

    /**
     * Footer'ın bastığı her değer buradan gelir ve burada boşsa şablonda hiç görünmez.
     *
     * Telefon ve il/ilçe için ikinci bir güvence: `StoreConfiguration` saklanmış bir değeri
     * okurken zaten kataloğa ve numara biçimine karşı yeniden sınıyor, yani veritabanına elle
     * yazılmış bir satır footer'a sızamaz.
     *
     * @return array{
     *     informationPages: list<array{title: string, slug: string}>,
     *     contact: array{city: ?string, district: ?string, phone: ?string, phoneLink: ?string, email: ?string},
     * }
     */
    public function read(): array
    {
        if (null !== $this->read) {
            return $this->read;
        }

        $phone = $this->settings->phone();

        return $this->read = [
            'informationPages' => $this->pages->publishedForNavigation(self::MAX_INFORMATION_PAGES),
            'contact' => [
                'city' => $this->settings->city(),
                'country' => $this->settings->country(),
                'phone' => $phone,
                'phoneLink' => StorePhone::link($phone),
                'email' => $this->settings->contactEmail(),
            ],
        ];
    }
}
