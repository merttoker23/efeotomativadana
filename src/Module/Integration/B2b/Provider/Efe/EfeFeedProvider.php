<?php

namespace App\Module\Integration\B2b\Provider\Efe;

use App\Module\Integration\B2b\B2bErrorType;
use App\Module\Integration\B2b\B2bFeedProviderInterface;
use App\Module\Integration\B2b\B2bFeedRecord;
use App\Module\Integration\B2b\B2bItemError;
use App\Module\Integration\B2b\B2bProviderStatus;
use App\Module\Integration\B2b\B2bSnapshot;
use App\Module\Integration\B2b\B2bSnapshotRequest;
use App\Module\Integration\B2b\B2bSyncCheckpoint;
use App\Module\Integration\B2b\Exception\B2bPermanentProviderException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Psr\Log\LoggerInterface;

final readonly class EfeFeedProvider implements B2bFeedProviderInterface
{
    /** @param list<string> $allowedHosts */
    public function __construct(
        private EfeSnapshotDownloader $downloader,
        private EfeFeedNormalizer $normalizer,
        private string $endpointUrl,
        private array $allowedHosts = [],
        private ?EfeBrandLogoSource $brandLogos = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function providerKey(): string
    {
        return 'efe';
    }

    public function status(): B2bProviderStatus
    {
        return B2bProviderStatus::fromEndpoint($this->providerKey(), $this->endpointUrl, $this->allowedHosts);
    }

    public function prepareSnapshot(B2bSnapshotRequest $request): B2bSnapshot
    {
        if ('efe' !== $request->providerKey) {
            throw new B2bPermanentProviderException('Efe provider cannot prepare another provider run.');
        }

        return $this->downloader->download($request);
    }

    public function streamItems(B2bSnapshot $snapshot, B2bSyncCheckpoint $checkpoint): iterable
    {
        if (!is_file($snapshot->path)) {
            throw new B2bPermanentProviderException('Efe snapshot file is missing.');
        }

        try {
            $logoUrls = [];
            try {
                $logoUrls = $this->brandLogos?->urls() ?? [];
            } catch (\Throwable) {
                $this->logger?->warning('Efe brand logo index could not be loaded; catalog synchronization continues.', ['run_snapshot' => basename($snapshot->path)]);
            }
            $items = Items::fromFile($snapshot->path, [
                'pointer' => '/data',
                'decoder' => new ExtJsonDecoder(true),
            ]);
            foreach ($items as $index => $record) {
                $position = $this->recordPosition($index);
                if ($position < $checkpoint->recordOffset) {
                    continue;
                }
                if (!is_array($record)) {
                    yield $position => B2bFeedRecord::failure(new B2bItemError(
                        B2bErrorType::InvalidItem,
                        'Efe feed row is not an object.',
                        null,
                        ['position' => $position],
                    ));
                    continue;
                }
                try {
                    $manufacturerId = $record['ureticiid'] ?? null;
                    $logoUrl = is_string($manufacturerId) ? ($logoUrls[trim($manufacturerId)] ?? null) : null;
                    yield $position => B2bFeedRecord::success($this->normalizer->normalize($record, $logoUrl));
                } catch (\Throwable $exception) {
                    yield $position => B2bFeedRecord::failure(new B2bItemError(
                        $this->itemErrorType($exception),
                        'Efe feed row is invalid.',
                        $this->safeExternalId($record['id'] ?? null),
                        ['position' => $position],
                    ));
                }
            }
        } catch (B2bPermanentProviderException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new B2bPermanentProviderException('Efe feed JSON contract is malformed.', 0, $exception);
        }
    }

    /**
     * A resumed run identifies every record by its absolute snapshot position, so the feed key
     * must stay numeric. Silently casting a non-numeric key to zero would turn an object shaped
     * "data" payload into an endless stream of position zero records.
     */
    private function recordPosition(mixed $index): int
    {
        if (is_int($index)) {
            return $index;
        }
        if (is_string($index) && '' !== $index && ctype_digit($index)) {
            return (int) $index;
        }

        throw new B2bPermanentProviderException('Efe feed rows must expose a numeric record position.');
    }

    private function itemErrorType(\Throwable $exception): B2bErrorType
    {
        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            $message = mb_strtolower($current->getMessage());
            if (str_contains($message, 'stock') || str_contains($message, 'mevcut_stok')) {
                return B2bErrorType::InvalidStock;
            }
            if (str_contains($message, 'price') || str_contains($message, 'discount') || str_contains($message, 'tax') || str_contains($message, 'vat')
                || str_contains($message, 'listefiyati') || str_contains($message, 'iskonto') || str_contains($message, 'kdvorani')) {
                return B2bErrorType::InvalidPrice;
            }
        }

        return B2bErrorType::InvalidItem;
    }

    private function safeExternalId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' === $value || mb_strlen($value) > 191 ? null : $value;
    }
}
