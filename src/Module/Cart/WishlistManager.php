<?php

namespace App\Module\Cart;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\WishlistItem;
use App\Entity\Customer\CustomerUser;
use App\Module\Catalog\PublicationStatus;
use App\Module\Catalog\Query\CatalogQuery;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WishlistManager
{
    public const PAGE_SIZE = 24;

    public function __construct(
        private WishlistRepositoryInterface $wishlist,
        private CatalogQuery $catalog,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(CustomerUser $customer, Product $product): void
    {
        if (PublicationStatus::Published !== $product->publicationStatus()) {
            throw new CartViolation('Bu ürün istek listesine eklenemez.');
        }
        if (null !== $this->wishlist->findOne($customer, $product)) {
            return;
        }

        $this->wishlist->save(new WishlistItem($customer, $product));
        $this->entityManager->flush();
    }

    public function remove(CustomerUser $customer, int $itemId): void
    {
        $item = $this->wishlist->findOwnedById($customer, $itemId);
        if (null === $item) {
            throw new CartViolation('İstek listesi kaydı bulunamadı.');
        }

        $this->wishlist->remove($item);
        $this->entityManager->flush();
    }

    /**
     * Paged, because the wishlist had no cap at all: a customer who had saved a few hundred
     * parts made this read every one of them.
     *
     * @return list<SavedProductView>
     */
    public function items(CustomerUser $customer, int $page = 1, int $perPage = self::PAGE_SIZE): array
    {
        $items = $this->wishlist->findForCustomer($customer, $page, $perPage);

        // One batched read of price, stock and lead image for the whole page. Reading them per
        // item cost four queries per saved product, on a list with no cap at all.
        $snapshots = $this->catalog->snapshots(array_values(array_filter(array_map(
            static fn (WishlistItem $item): ?int => $item->product()->id(),
            $items,
        ), is_int(...))));

        return array_map(static function (WishlistItem $item) use ($snapshots): SavedProductView {
            $product = $item->product();
            $snapshot = $snapshots[$product->id() ?? 0] ?? null;

            return new SavedProductView(
                selectionId: $item->id() ?? 0,
                productId: $product->id() ?? 0,
                name: $product->name(),
                slug: $product->slug(),
                sku: $product->sku(),
                imagePath: $snapshot?->imagePath,
                price: $snapshot?->sellPrice,
                sellable: PublicationStatus::Published === $product->publicationStatus() && true === $snapshot?->sellable,
            );
        }, $items);
    }

    public function page(CustomerUser $customer, int $page = 1): WishlistPage
    {
        $page = max(1, $page);
        $total = $this->wishlist->countForCustomer($customer);
        // Overshooting a list must not repeat its first page or claim the entire list is empty.
        // Avoid computing an offset for an arbitrarily large page supplied by a client.
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));

        return new WishlistPage($page > $pages ? [] : $this->items($customer, $page), $page, self::PAGE_SIZE, $total);
    }
}
