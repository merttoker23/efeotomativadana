<?php

namespace App\Module\Integration\B2b;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.b2b_feed_provider')]
interface B2bFeedProviderInterface
{
    public function providerKey(): string;

    public function status(): B2bProviderStatus;

    public function prepareSnapshot(B2bSnapshotRequest $request): B2bSnapshot;

    /**
     * Streams the snapshot records from the checkpoint onwards. The iterable key must be the
     * absolute record position inside the snapshot, so a resumed run can prove that no record
     * was skipped or applied twice. Auto-generated iterable keys restart at zero after a
     * checkpoint skip and therefore break resume.
     *
     * @return iterable<int, B2bFeedRecord>
     */
    public function streamItems(B2bSnapshot $snapshot, B2bSyncCheckpoint $checkpoint): iterable;
}
