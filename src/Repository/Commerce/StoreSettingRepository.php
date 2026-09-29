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

    /**
     * Every known setting and its effective value, defaults filled in.
     *
     * One query rather than twelve, and the authoritative answer rather than whatever a
     * caller's memo happens to hold. The settings save uses this to work out what actually
     * changed, and a diff taken from a memo that was never populated reports every key as
     * changed — which is worse than no diff at all.
     *
     * @return array<string, bool|int|string|null>
     */
    public function allValues(): array
    {
        $values = [];
        foreach (SettingKey::cases() as $key) {
            $values[$key->value] = $key->defaultValue();
        }
        foreach ($this->findAll() as $setting) {
            // Through `key()` rather than a raw string, so `SettingKey::from()` validates every
            // row that is read here. A key this application no longer knows about would throw
            // loudly instead of being skipped, which is the behaviour a settings diff wants:
            // a silently ignored row would produce a diff that claims "nothing changed".
            $values[$setting->key()->value] = $setting->value();
        }

        return $values;
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
