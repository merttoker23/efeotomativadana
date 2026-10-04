<?php

declare(strict_types=1);

namespace App\Repository\Commerce;

use App\Entity\Commerce\CustomerOrder;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Presentation-only read of current catalogue images through OrderItem's product relation. */
final readonly class OrderThumbnailRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<CustomerOrder> $orders Already restricted to the current customer's orders.
     * @return array<int, string|null> Image paths keyed by order item id.
     */
    public function forOrders(array $orders): array
    {
        $ids = [];
        foreach ($orders as $order) {
            if (null !== $order->id()) {
                $ids[] = $order->id();
            }
        }
        if ([] === $ids) {
            return [];
        }

        // Same primary-image ordering as CatalogReadRepository; no product proxies or image
        // collections are hydrated. One bounded query regardless of order/product/line count.
        $rows = $this->connection->executeQuery(
            'SELECT item.id, (SELECT image.path FROM catalog_product_image image
                WHERE image.product_id = item.product_id
                ORDER BY image.sort_order ASC, image.id ASC LIMIT 1) AS image_path
             FROM commerce_order_item item WHERE item.order_id IN (?)',
            [$ids],
            [ArrayParameterType::INTEGER],
        )->fetchAllAssociative();
        $paths = [];
        foreach ($rows as $row) {
            $paths[(int) $row['id']] = null === $row['image_path'] ? null : (string) $row['image_path'];
        }

        return $paths;
    }
}
