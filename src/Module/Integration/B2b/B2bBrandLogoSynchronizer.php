<?php

namespace App\Module\Integration\B2b;

use App\Module\Catalog\BrandLogoStorage;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class B2bBrandLogoSynchronizer
{
    private ?int $runId = null;
    /** @var array<string, true> */
    private array $handled = [];
    private bool $transaction = false;
    /** @var list<string> */
    private array $transactionKeys = [];
    /** @var array<int, array{previous: ?string, keys: list<string>}> */
    private array $pending = [];

    public function __construct(private ProductMediaStorage $media, private BrandLogoStorage $logos, #[Autowire(service: 'cache.app')] private CacheItemPoolInterface $cache)
    {
    }

    public function beginTransaction(): void { $this->transaction = true; $this->transactionKeys = []; }
    public function commit(): void { $this->pending = []; $this->transactionKeys = []; $this->transaction = false; }
    public function rollback(): void
    {
        foreach ($this->pending as $id => $entry) {
            $this->logos->restore($id, $entry['previous']);
            foreach ($entry['keys'] as $key) {
                $this->cache->deleteItem($key);
                unset($this->handled[$key]);
            }
        }
        foreach ($this->transactionKeys as $key) unset($this->handled[$key]);
        $this->commit();
    }

    public function sync(int $localBrandId, string $sourceUrl, string $externalId, int $runId): ?B2bItemError
    {
        if ($this->runId !== $runId) {
            $this->runId = $runId;
            $this->handled = [];
        }
        $key = 'b2b_brand_logo_'.hash('sha256', $localBrandId.'\0'.$sourceUrl);
        if (isset($this->handled[$key])) return null;
        $this->handled[$key] = true;
        if ($this->transaction) $this->transactionKeys[] = $key;
        $stored = null;
        try {
            $previous = $this->logos->snapshot($localBrandId);
            $entry = $this->cache->getItem($key);
            $metadata = $entry->get();
            $unchanged = is_array($metadata) && null !== $previous && ($metadata['localHash'] ?? null) === hash('sha256', $previous);
            $stored = $this->media->storeIfModified($sourceUrl, $unchanged ? ($metadata['etag'] ?? null) : null, $unchanged ? ($metadata['lastModified'] ?? null) : null);
            if (null === $stored) return null;
            if ($this->transaction) {
                $this->pending[$localBrandId] ??= ['previous' => $previous, 'keys' => []];
                $this->pending[$localBrandId]['keys'][] = $key;
            }
            $this->logos->store($localBrandId, new UploadedFile($stored->absolutePath, 'brand-logo', null, null, true));
            $entry->set(['etag' => $stored->etag, 'lastModified' => $stored->lastModified, 'localHash' => hash('sha256', $this->logos->snapshot($localBrandId) ?? '')]);
            $this->cache->save($entry);
        } catch (\Throwable) {
            return new B2bItemError(B2bErrorType::Image, 'The brand logo could not be synchronized.', $externalId, ['local_brand_id' => $localBrandId, 'media' => 'brand_logo']);
        } finally {
            if (null !== $stored) {
                try { $this->media->remove($stored); } catch (\Throwable) { }
            }
        }
        return null;
    }
}
