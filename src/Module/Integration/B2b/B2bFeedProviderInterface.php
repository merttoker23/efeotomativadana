<?php

namespace App\Module\Integration\B2b;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.b2b_feed_provider')]
interface B2bFeedProviderInterface
{
    public function providerKey(): string;

    public function status(): B2bProviderStatus;

    public function prepareSnapshot(B2bSnapshotRequest $request): B2bSnapshot;

    /** @return iterable<B2bFeedRecord> */
    public function streamItems(B2bSnapshot $snapshot, B2bSyncCheckpoint $checkpoint): iterable;
}
