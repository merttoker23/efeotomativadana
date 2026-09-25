<?php

namespace App\Module\Integration\B2b;

use App\Module\Integration\B2b\Exception\B2bPermanentProviderException;
use App\Module\Integration\B2b\Exception\B2bRetryableProviderException;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ProductMediaStorage implements ProductMediaStorageInterface
{
    private string $directory;
    private readonly ?string $caBundle;
    /** @var array<string, true> */
    private array $allowedHosts;

    /** @param list<string> $allowedHosts */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        string $directory,
        array $allowedHosts,
        private readonly int $maxBytes,
        private readonly int $maxConnectDuration,
        private readonly int $readTimeout,
        private readonly int $overallTimeout,
        private readonly int $maxRedirects,
        ?string $caBundle,
    ) {
        $caBundle = null === $caBundle ? null : trim($caBundle);
        if ($maxBytes < 1 || $maxConnectDuration < 1 || $readTimeout < 1 || $overallTimeout < 1 || $maxRedirects < 0) {
            throw new \InvalidArgumentException('Product media transport limits are invalid.');
        }
        $this->caBundle = '' === $caBundle ? null : $caBundle;
        $this->directory = rtrim($directory, "/\\");
        if ('' === $this->directory) {
            throw new \InvalidArgumentException('Product media directory is required.');
        }
        $hosts = [];
        foreach ($allowedHosts as $host) {
            $host = mb_strtolower(trim($host));
            if ('' !== $host) {
                $hosts[$host] = true;
            }
        }
        $this->allowedHosts = $hosts;
    }

    public function store(string $sourceUrl, string $altText): StoredProductImage
    {
        $this->assertAllowedUrl($sourceUrl);
        if (!is_dir($this->directory) && !mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new B2bPermanentProviderException('Product media directory cannot be created.');
        }
        $this->publish($this->directory, 0o755);
        $temporary = $this->directory.'/'.bin2hex(random_bytes(16)).'.part';
        $stream = null;
        $output = null;
        $bytes = 0;
        $deadline = $this->deadlineAfter($this->overallTimeout);

        try {
            $options = [
                'max_connect_duration' => $this->maxConnectDuration,
                'timeout' => $this->readTimeout,
                'max_duration' => $this->overallTimeout,
                'max_redirects' => $this->maxRedirects,
            ];
            if (null !== $this->caBundle) {
                if (!is_file($this->caBundle) || !is_readable($this->caBundle)) {
                    throw new B2bPermanentProviderException('Configured product media CA bundle is not readable.');
                }
                $options['cafile'] = $this->caBundle;
            }
            $response = $this->request($sourceUrl, $options, $deadline);
            $status = $response->getStatusCode();
            if ($status >= 500 || in_array($status, [408, 425, 429], true)) {
                throw new B2bRetryableProviderException('Product image source returned a temporary failure.', $status);
            }
            if ($status < 200 || $status >= 300) {
                throw new B2bPermanentProviderException('Product image source returned an unexpected status.', $status);
            }
            $contentLength = $response->getHeaders(false)['content-length'][0] ?? null;
            if (null !== $contentLength && (!ctype_digit((string) $contentLength) || (int) $contentLength > $this->maxBytes)) {
                throw new B2bPermanentProviderException('Product image exceeds the configured size limit.');
            }
            $this->assertAllowedUrl((string) ($response->getInfo('url') ?: $sourceUrl));
            if (!$response instanceof StreamableInterface) {
                throw new B2bPermanentProviderException('Product image response is not streamable.');
            }
            $stream = $response->toStream(false);
            $output = fopen($temporary, 'wb');
            if (false === $output) {
                throw new B2bPermanentProviderException('Product image temporary file cannot be opened.');
            }
            while (!feof($stream)) {
                if ($this->deadlineExceeded($deadline)) {
                    throw new B2bRetryableProviderException('Product image transport timed out.');
                }
                $chunk = fread($stream, 65536);
                if (false === $chunk) {
                    throw new B2bRetryableProviderException('Product image stream failed while reading.');
                }
                if ('' === $chunk) {
                    continue;
                }
                $bytes += strlen($chunk);
                if ($bytes > $this->maxBytes) {
                    throw new B2bPermanentProviderException('Product image exceeds the configured size limit.');
                }
                $this->writeAll($output, $chunk);
            }
            fclose($output);
            $output = null;
            if ($bytes < 1) {
                throw new B2bPermanentProviderException('Product image body is empty.');
            }

            $mime = (new \finfo(\FILEINFO_MIME_TYPE))->file($temporary);
            $extension = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => null,
            };
            $dimensions = @getimagesize($temporary);
            if (null === $extension || false === $dimensions || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > 6000 || $dimensions[1] > 6000 || $dimensions['mime'] !== $mime) {
                throw new B2bPermanentProviderException('Product media must be a valid JPEG, PNG or WebP image.');
            }
            $name = bin2hex(random_bytes(16)).'.'.$extension;
            $absolutePath = $this->directory.'/'.$name;
            if (!rename($temporary, $absolutePath)) {
                throw new B2bPermanentProviderException('Product image cannot be finalized.');
            }
            $this->publish($absolutePath, 0o644);

            return new StoredProductImage('/uploads/products/'.$name, $absolutePath);
        } catch (B2bPermanentProviderException|B2bRetryableProviderException $exception) {
            throw $exception;
        } catch (TransportExceptionInterface $exception) {
            throw new B2bRetryableProviderException('Product image transport failed.', 0, $exception);
        } catch (\Throwable $exception) {
            throw new B2bPermanentProviderException('Product image could not be stored.', 0, $exception);
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @param array<string, mixed> $options */
    private function request(string $sourceUrl, array $options, int $deadline): ResponseInterface
    {
        $url = $sourceUrl;
        for ($redirects = 0; ; ++$redirects) {
            $remaining = $this->remainingSeconds($deadline);
            if ($remaining <= 0) {
                throw new B2bRetryableProviderException('Product image transport timed out.');
            }
            $this->assertAllowedUrl($url);
            $hopOptions = array_replace($options, ['max_duration' => $remaining, 'max_redirects' => 0]);
            $response = $this->httpClient->request('GET', $url, $hopOptions);
            $status = $response->getStatusCode();
            if (!in_array($status, [301, 302, 303, 307, 308], true)) {
                return $response;
            }
            if ($redirects >= $this->maxRedirects) {
                throw new B2bPermanentProviderException('Product image redirect limit exceeded.');
            }
            $location = $response->getHeaders(false)['location'][0] ?? null;
            if (!is_string($location) || '' === trim($location)) {
                throw new B2bPermanentProviderException('Product image redirect is missing a valid location.');
            }
            $this->discardRedirectResponse($response, $deadline);
            $url = $this->redirectUrl($url, trim($location));
        }
    }

    private function discardRedirectResponse(ResponseInterface $response, int $deadline): void
    {
        $length = $response->getHeaders(false)['content-length'][0] ?? null;
        if (null !== $length && (!ctype_digit((string) $length) || (int) $length > 65_536)) {
            throw new B2bPermanentProviderException('Product image redirect body exceeds the safety limit.');
        }
        if (!$response instanceof StreamableInterface) {
            $body = $response->getContent(false);
            if (strlen($body) > 65_536) {
                throw new B2bPermanentProviderException('Product image redirect body exceeds the safety limit.');
            }

            return;
        }
        $stream = $response->toStream(false);
        $bytes = 0;
        try {
            while (!feof($stream)) {
                if ($this->deadlineExceeded($deadline)) {
                    throw new B2bRetryableProviderException('Product image redirect transport timed out.');
                }
                $chunk = fread($stream, 8192);
                if (false === $chunk) {
                    throw new B2bRetryableProviderException('Product image redirect stream failed.');
                }
                $bytes += strlen($chunk);
                if ($bytes > 65_536) {
                    throw new B2bPermanentProviderException('Product image redirect body exceeds the safety limit.');
                }
            }
        } finally {
            fclose($stream);
        }
    }

    private function redirectUrl(string $base, string $location): string
    {
        if (1 === preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new B2bPermanentProviderException('Product image redirect base URL is invalid.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        $authority = $scheme.'://'.$parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':'.$parts['port'];
        }
        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }
        $path = str_starts_with($location, '/')
            ? $location
            : rtrim(dirname((string) ($parts['path'] ?? '/')), '/').'/'.$location;
        $query = parse_url($location, PHP_URL_QUERY);
        if (is_string($query) && '' !== $query) {
            $path .= '?'.$query;
        }
        $fragment = parse_url($location, PHP_URL_FRAGMENT);
        if (is_string($fragment) && '' !== $fragment) {
            $path .= '#'.$fragment;
        }

        return $authority.$path;
    }

    public function remove(StoredProductImage $image): void
    {
        $root = realpath($this->directory) ?: $this->directory;
        $path = realpath($image->absolutePath) ?: $image->absolutePath;
        if (!str_starts_with($path, rtrim($root, "/\\").DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Product image path is outside the managed directory.');
        }
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function deadlineAfter(int $seconds): int
    {
        $now = (int) hrtime(true);

        return $now + ($seconds * 1_000_000_000);
    }

    private function deadlineExceeded(int $deadline): bool
    {
        $now = (int) hrtime(true);

        return $now >= $deadline;
    }

    private function remainingSeconds(int $deadline): float
    {
        $now = (int) hrtime(true);

        return max(0.0, ($deadline - $now) / 1_000_000_000);
    }

    /**
     * Applies an explicit file mode instead of leaving the stored image at whatever the process
     * umask produced. The synchronization worker is started from cron, which commonly runs with
     * a restrictive umask such as 0077; an image created 0600 is readable by the worker but not
     * by the web server process, so the storefront would answer 403 for an image that exists.
     *
     * Best effort on purpose: the mode must not depend on the umask, but a filesystem that
     * refuses chmod must not turn an otherwise valid catalog import into a failure.
     */
    private function publish(string $path, int $mode): void
    {
        @chmod($path, $mode);
    }

    private function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? mb_strtolower((string) ($parts['host'] ?? '')) : '';
        if (!is_array($parts)
            || 'https' !== strtolower((string) ($parts['scheme'] ?? ''))
            || '' === $host
            || !isset($this->allowedHosts[$host])
            || (isset($parts['port']) && 443 !== (int) $parts['port'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new B2bPermanentProviderException('Product image URL is not allowed.');
        }
    }

    /** @param resource $stream */
    private function writeAll($stream, string $chunk): void
    {
        $offset = 0;
        $length = strlen($chunk);
        while ($offset < $length) {
            $written = fwrite($stream, substr($chunk, $offset));
            if (false === $written || 0 === $written) {
                throw new B2bPermanentProviderException('Product image temporary file cannot be written.');
            }
            $offset += $written;
        }
    }
}
