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

final readonly class EfeFeedProvider implements B2bFeedProviderInterface
{
    /** @param list<string> $allowedHosts */
    public function __construct(
        private EfeSnapshotDownloader $downloader,
        private EfeFeedNormalizer $normalizer,
        private string $endpointUrl,
        private array $allowedHosts = [],
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
            $items = Items::fromFile($snapshot->path, [
                'pointer' => '/data',
                'decoder' => new ExtJsonDecoder(true),
            ]);
            foreach ($items as $index => $record) {
                $position = (int) $index;
                if ($position < $checkpoint->recordOffset) {
                    continue;
                }
                if (!is_array($record)) {
                    yield B2bFeedRecord::failure(new B2bItemError(
                        B2bErrorType::InvalidItem,
                        'Efe feed row is not an object.',
                        null,
                        ['position' => $position],
                    ));
                    continue;
                }
                try {
                    yield B2bFeedRecord::success($this->normalizer->normalize($record));
                } catch (\Throwable $exception) {
                    yield B2bFeedRecord::failure(new B2bItemError(
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

    private function itemErrorType(\Throwable $exception): B2bErrorType
    {
        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            $message = mb_strtolower($current->getMessage());
            if (str_contains($message, 'stock')) {
                return B2bErrorType::InvalidStock;
            }
            if (str_contains($message, 'price') || str_contains($message, 'discount') || str_contains($message, 'tax') || str_contains($message, 'vat')) {
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
