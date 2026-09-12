<?php

namespace App\Entity\Commerce;

use App\Module\Settings\SettingKey;
use App\Repository\Commerce\StoreSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StoreSettingRepository::class)]
#[ORM\Table(name: 'store_setting')]
#[ORM\UniqueConstraint(name: 'uniq_store_setting_key', columns: ['setting_key'])]
class StoreSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $settingKey;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private mixed $value;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(SettingKey $key, mixed $value)
    {
        $this->settingKey = $key->value;
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function key(): SettingKey
    {
        return SettingKey::from($this->settingKey);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function setValue(mixed $value): void
    {
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
