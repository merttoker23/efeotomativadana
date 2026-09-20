<?php

namespace App\Repository\Commerce;

use App\Entity\Commerce\Cart;
use App\Entity\Customer\CustomerUser;
use App\Module\Cart\CartRepositoryInterface;
use App\Module\Cart\CartSummary;
use App\Module\Catalog\PublicationStatus;
use App\Shared\Money\Money;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Cart> */
final class CartRepository extends ServiceEntityRepository implements CartRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Cart::class);
    }

    public function findOneByCustomer(CustomerUser $customer): ?Cart
    {
        return $this->findOneBy(['customer' => $customer]);
    }

    public function findOneByGuestToken(string $token): ?Cart
    {
        return $this->findOneBy(['guestToken' => $token]);
    }

    public function summary(Cart $cart, \DateTimeImmutable $now): CartSummary
    {
        if (null === $cart->id()) {
            return new CartSummary(0, null);
        }

        $validLine = <<<'SQL'
            product.publication_status = :published
            AND price.id IS NOT NULL
            AND inventory.available_for_sale = 1
            AND inventory.quantity > 0
            AND item.quantity <= inventory.quantity
            SQL;
        $effectivePrice = <<<'SQL'
            CASE
                WHEN price.sale_minor_amount IS NOT NULL
                  AND (price.sale_starts_at IS NULL OR price.sale_starts_at <= :now)
                  AND (price.sale_ends_at IS NULL OR :now < price.sale_ends_at)
                THEN price.sale_minor_amount
                ELSE price.base_minor_amount
            END
            SQL;
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            <<<SQL
                SELECT
                    COALESCE(SUM(item.quantity), 0) AS item_count,
                    SUM(CASE WHEN {$validLine} THEN ({$effectivePrice}) * item.quantity ELSE 0 END) AS total_minor_amount,
                    SUM(CASE WHEN {$validLine} THEN 0 ELSE 1 END) AS invalid_count,
                    COUNT(DISTINCT CASE WHEN {$validLine} THEN price.currency ELSE NULL END) AS currency_count,
                    MIN(CASE WHEN {$validLine} THEN price.currency ELSE NULL END) AS currency
                FROM commerce_cart_item item
                INNER JOIN catalog_product product ON product.id = item.product_id
                LEFT JOIN commerce_product_price price ON price.product_id = product.id
                LEFT JOIN commerce_product_inventory inventory ON inventory.product_id = product.id
                WHERE item.cart_id = :cart_id
                SQL,
            [
                'cart_id' => $cart->id(),
                'published' => PublicationStatus::Published->value,
                'now' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ],
        );

        if (false === $row) {
            return new CartSummary(0, null);
        }

        $itemCount = (int) $row['item_count'];
        $total = 0 === (int) $row['invalid_count']
            && 1 === (int) $row['currency_count']
            && null !== $row['total_minor_amount']
            && null !== $row['currency']
                ? Money::ofMinor((int) $row['total_minor_amount'], (string) $row['currency'])
                : null;

        return new CartSummary($itemCount, $total);
    }

    public function save(Cart $cart): void
    {
        $this->getEntityManager()->persist($cart);
    }

    public function remove(Cart $cart): void
    {
        $this->getEntityManager()->remove($cart);
    }
}
