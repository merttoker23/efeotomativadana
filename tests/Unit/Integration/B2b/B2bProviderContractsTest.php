<?php

namespace App\Tests\Unit\Integration\B2b;

use App\Module\Catalog\ProductIdentifierType;
use App\Module\Integration\B2b\B2bErrorType;
use App\Module\Integration\B2b\B2bFeedProviderInterface;
use App\Module\Integration\B2b\B2bFeedRecord;
use App\Module\Integration\B2b\B2bItemError;
use App\Module\Integration\B2b\B2bProviderRegistry;
use App\Module\Integration\B2b\B2bProviderStatus;
use App\Module\Integration\B2b\B2bSnapshot;
use App\Module\Integration\B2b\B2bSnapshotRequest;
use App\Module\Integration\B2b\B2bSyncCheckpoint;
use App\Module\Integration\B2b\B2bSyncMode;
use App\Module\Integration\B2b\NormalizedCatalogFeedItem;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

final class B2bProviderContractsTest extends TestCase
{
    public function testSnapshotRequestAndCheckpointNormalizeSafeResumeMetadata(): void
    {
        $request = new B2bSnapshotRequest(' EFE ', 42, B2bSyncMode::Daily, '/tmp/snapshots/', null);

        self::assertSame('efe', $request->providerKey);
        self::assertSame(42, $request->runId);
        self::assertSame('/tmp/snapshots', $request->destinationDirectory);
        self::assertNull($request->existingSnapshotPath);
        self::assertSame(250, (new B2bSyncCheckpoint(250))->recordOffset);
    }

    public function testSnapshotCarriesVerifiedTransportMetadata(): void
    {
        $hash = hash('sha256', 'snapshot');
        $snapshot = new B2bSnapshot('/tmp/snapshots/42.json', 123, 92_175, strtoupper($hash), true);

        self::assertSame($hash, $snapshot->sha256);
        self::assertSame(92_175, $snapshot->declaredCount);
        self::assertTrue($snapshot->reused);
    }

    public function testFeedRecordIsExactlyOneSuccessOrOneError(): void
    {
        $item = new NormalizedCatalogFeedItem(
            '1001',
            'SKU-1001',
            'Product',
            null,
            null,
            null,
            'category-1',
            'Category',
            [[ProductIdentifierType::Manufacturer, 'M-1']],
            ['efe-unit' => 'Ad'],
            Money::ofMinor(10_000, 'TRY'),
            TaxRate::fromPercentage(20),
            2,
            [],
        );
        $error = new B2bItemError(B2bErrorType::InvalidItem, 'Invalid row.', '1002', ['field' => 'id']);

        $success = B2bFeedRecord::success($item);
        $failure = B2bFeedRecord::failure($error);

        self::assertTrue($success->isSuccess());
        self::assertSame($item, $success->item());
        self::assertNull($success->error());
        self::assertFalse($failure->isSuccess());
        self::assertNull($failure->item());
        self::assertSame($error, $failure->error());
    }

    public function testProviderRegistrySelectsByKeyAndRejectsDuplicates(): void
    {
        $efe = new FakeB2bProvider('efe');
        $registry = new B2bProviderRegistry([$efe]);

        self::assertTrue($registry->supports(' EFE '));
        self::assertSame($efe, $registry->get('efe'));
        self::assertSame(['efe'], $registry->keys());

        $this->expectException(\InvalidArgumentException::class);
        new B2bProviderRegistry([$efe, new FakeB2bProvider('efe')]);
    }
}

final class FakeB2bProvider implements B2bFeedProviderInterface
{
    public function __construct(private string $key)
    {
    }

    public function providerKey(): string { return $this->key; }
    public function status(): B2bProviderStatus { return B2bProviderStatus::fromEndpoint($this->key, 'https://example.com/feed'); }
    public function prepareSnapshot(B2bSnapshotRequest $request): B2bSnapshot
    {
        throw new \LogicException('Not used by the registry contract test.');
    }
    public function streamItems(B2bSnapshot $snapshot, B2bSyncCheckpoint $checkpoint): iterable
    {
        return [];
    }
}
