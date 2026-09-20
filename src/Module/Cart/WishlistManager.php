<?php

namespace App\Module\Cart;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\WishlistItem;
use App\Entity\Customer\CustomerUser;
use App\Module\Catalog\PublicationStatus;
use App\Module\Inventory\InventoryQuery;
use App\Module\Pricing\PricingQuery;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WishlistManager
{
    public function __construct(
        private WishlistRepositoryInterface $wishlist,
        private PricingQuery $pricing,
        private InventoryQuery $inventory,
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

    /** @return list<SavedProductView> */
    public function items(CustomerUser $customer): array
    {
        return array_map(function (WishlistItem $item): SavedProductView {
            $product = $item->product();
            $images = $product->images();
            $inventory = $this->inventory->forProduct($product);

            return new SavedProductView(
                selectionId: $item->id() ?? 0,
                productId: $product->id() ?? 0,
                name: $product->name(),
                slug: $product->slug(),
                sku: $product->sku(),
                imagePath: [] === $images ? null : $images[0]->path(),
                price: $this->pricing->forProduct($product)?->sellPrice(),
                sellable: PublicationStatus::Published === $product->publicationStatus() && $inventory->sellable(),
            );
        }, $this->wishlist->findForCustomer($customer));
    }
}
