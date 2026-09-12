<?php

namespace App\Repository\Commerce;

use App\Entity\Commerce\StoreSetting;
use App\Module\Settings\SettingKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StoreSetting>
 */
final class StoreSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StoreSetting::class);
    }

    public function findOneByKey(SettingKey $key): ?StoreSetting
    {
        return $this->findOneBy(['settingKey' => $key->value]);
    }

    public function put(SettingKey $key, bool|int|string|null $value): void
    {
        $setting = $this->findOneByKey($key);

        if (null === $setting) {
            $setting = new StoreSetting($key, $value);
            $this->getEntityManager()->persist($setting);

            return;
        }

        $setting->setValue($value);
    }
}
