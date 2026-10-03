<?php

declare(strict_types=1);

namespace App\Module\Catalog;

/**
 * Where a named list of products comes from.
 *
 * A tab on the storefront's product block used to be a title and a handful of product slugs, which
 * meant the tab's name was the only thing it had: four tabs could all have said "Ürünler" and all
 * four could have shown the same five products, and nothing in the configuration said which
 * products a "Çok Satanlar" tab was supposed to mean. This enum is that missing answer, and it is
 * answered once here rather than inferred anywhere else — never from a tab's title, which is free
 * text an administrator types.
 *
 * Three of the four are read from data the store already keeps, and each says the question the
 * shop actually wants answered:
 *
 *  - `BestSellers` aggregates the quantities on real order lines whose order the domain accepts as
 *    sold. Cancelled orders are not sales and are not counted.
 *  - `Popular` counts real wishlist entries per product.
 *  - `OnSale` reuses the pricing module's own definition of an active discount, so a product can
 *    only appear here if its card would print a struck-through price beside the sale price.
 *  - `Featured` is the manual one: the slugs an administrator picked in the CMS, in the order they
 *    picked them. A configuration written before this enum existed is exactly that, which is why a
 *    row with no `source` is read as `featured` rather than rejected.
 */
enum ProductFeedSource: string
{
    case BestSellers = 'best_sellers';
    case Popular = 'popular';
    case OnSale = 'on_sale';
    case Featured = 'featured';

    /** The Turkish name an administrator chooses this source by in the CMS form. */
    public function label(): string
    {
        return match ($this) {
            self::BestSellers => 'Çok Satanlar',
            self::Popular => 'Popüler',
            self::OnSale => 'İndirimdekiler',
            self::Featured => 'Öne Çıkanlar',
        };
    }

    /** Whether this source's products are the ones a person chose, rather than the ones data chose. */
    public function isManual(): bool
    {
        return self::Featured === $this;
    }

    /**
     * What the panel says when this source currently has nothing to show.
     *
     * Each source has its own reason to be empty and its own sentence for it: a shop with no sales
     * yet and a shop with no discounts running are in different positions, and telling a customer
     * "no products" in both cases would hide that. The words live here beside the source they
     * describe rather than in the template, which does not know which question it is looking at.
     */
    public function emptyMessage(): string
    {
        return match ($this) {
            self::BestSellers => 'Henüz satışı tamamlanmış ürün bulunmuyor.',
            self::Popular => 'Henüz favorilere eklenmiş ürün bulunmuyor.',
            self::OnSale => 'Şu anda indirimli ürün bulunmuyor.',
            self::Featured => 'Bu sekmede seçili ürün bulunmuyor.',
        };
    }

    /**
     * The source a configuration written before `source` existed is read as.
     *
     * Such a row is a title and a hand-picked list of slugs, which is exactly what `featured` is.
     * Reading it as anything else would either discard the products it really has or invent
     * products it never named.
     */
    public static function legacyDefault(): self
    {
        return self::Featured;
    }

    /**
     * Every source, in the order the form and the storefront both present them.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * The source a stored value or a submitted field names, or the manual one when it names
     * nothing this store has.
     *
     * The fallback is what makes an existing configuration readable: a row written before `source`
     * existed carries a title and a hand-picked list of products, which is `featured`. A value this
     * store does not recognise falls back the same way rather than raising, because the only thing
     * an unknown source can cost is that the tab shows the products it was configured with.
     */
    public static function normalize(mixed $value): self
    {
        if (!\is_string($value)) {
            return self::legacyDefault();
        }

        return self::tryFrom(mb_strtolower(trim($value))) ?? self::legacyDefault();
    }
}