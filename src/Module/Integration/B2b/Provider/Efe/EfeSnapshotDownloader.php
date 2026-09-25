<?php

namespace App\Module\Integration\B2b\Provider\Efe;

use App\Module\Integration\B2b\B2bSnapshot;
use App\Module\Integration\B2b\B2bSnapshotRequest;
use App\Module\Integration\B2b\Exception\B2bPermanentProviderException;
use App\Module\Integration\B2b\Exception\B2bRetryableProviderException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\HttpClient\Response\StreamableInterface;

final class EfeSnapshotDownloader
{
    /** @var array<string, true> */
    private array $allowedHosts;

    /** @param list<string> $allowedHosts */
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $endpointUrl,
        array $allowedHosts,
        private string $snapshotDirectory,
        private int $maxBytes,
        private int $maxConnectDuration,
        private int $readTimeout,
        private int $overallTimeout,
        private int $maxRedirects,
        private ?string $caBundle,
    ) {
        if ($maxBytes < 1 || $maxConnectDuration < 1 || $readTimeout < 1 || $overallTimeout < 1 || $maxRedirects < 0) {
            throw new \InvalidArgumentException('Efe snapshot transport limits are invalid.');
        }
        $this->caBundle = null === $this->caBundle ? null : trim($this->caBundle);
        if ('' === $this->caBundle) {
            $this->caBundle = null;
        }
        $hosts = [];
        foreach ($allowedHosts as $allowedHost) {
            $allowedHost = mb_strtolower(trim($allowedHost));
            if ('' !== $allowedHost) {
                $hosts[$allowedHost] = true;
            }
        }
        $this->allowedHosts = $hosts;
        $this->snapshotDirectory = rtrim($snapshotDirectory, "/\\");
        $this->endpointUrl = trim($endpointUrl);
    }

    public function download(B2bSnapshotRequest $request): B2bSnapshot
    {
        if (null !== $request->existingSnapshotPath) {
            return $this->snapshotFromExisting($request);
        }
        $this->assertAllowedUrl($this->endpointUrl);
        if (!is_dir($this->snapshotDirectory) && !mkdir($this->snapshotDirectory, 0755, true) && !is_dir($this->snapshotDirectory)) {
            throw new B2bPermanentProviderException('B2B snapshot directory cannot be created.');
        }

        $path = rtrim($request->destinationDirectory, "/\\").\DIRECTORY_SEPARATOR.$request->runId.'.json';
        $part = $path.'.part';
        @unlink($part);
        $response = null;
        $stream = null;
        $output = null;
        $hash = hash_init('sha256');
        $bytes = 0;
        $keepFinal = false;
        $deadline = $this->deadlineAfter($this->overallTimeout);

        try {
            $options = [
                'max_connect_duration' => $this->maxConnectDuration,
                'timeout' => $this->readTimeout,
                'max_duration' => $this->overallTimeout,
                'max_redirects' => $this->maxRedirects,
                'headers' => ['Accept' => 'application/json'],
            ];
            if (null !== $this->caBundle) {
                if (!is_file($this->caBundle) || !is_readable($this->caBundle)) {
                    throw new B2bPermanentProviderException('Configured B2B CA bundle is not readable.');
                }
                $options['cafile'] = $this->caBundle;
            }
            $response = $this->request($options, $deadline);
            $status = $response->getStatusCode();
            if ($status >= 500 || in_array($status, [408, 425, 429], true)) {
                throw new B2bRetryableProviderException('Efe feed returned a temporary HTTP failure.', $status);
            }
            if ($status < 200 || $status >= 300) {
                throw new B2bPermanentProviderException('Efe feed returned an unexpected HTTP status.', $status);
            }
            $contentType = strtolower((string) ($response->getHeaders(false)['content-type'][0] ?? ''));
            if (!str_starts_with($contentType, 'application/json')) {
                throw new B2bPermanentProviderException('Efe feed content type is not JSON.');
            }
            $contentLength = $response->getHeaders(false)['content-length'][0] ?? null;
            if (null !== $contentLength && (!ctype_digit((string) $contentLength) || (int) $contentLength > $this->maxBytes)) {
                throw new B2bPermanentProviderException('Efe feed exceeds the configured snapshot size limit.');
            }
            $this->assertAllowedUrl((string) ($response->getInfo('url') ?: $this->endpointUrl));
            if (!$response instanceof StreamableInterface) {
                throw new B2bPermanentProviderException('Efe feed response is not streamable.');
            }
            $stream = $response->toStream(false);
            $output = fopen($part, 'wb');
            if (false === $output) {
                throw new B2bPermanentProviderException('B2B snapshot temporary file cannot be opened.');
            }
            while (!feof($stream)) {
                if ($this->deadlineExceeded($deadline)) {
                    throw new B2bRetryableProviderException('Efe feed snapshot transport timed out.');
                }
                $chunk = fread($stream, 65536);
                if (false === $chunk) {
                    throw new B2bRetryableProviderException('Efe feed stream failed while reading.');
                }
                if ('' === $chunk) {
                    continue;
                }
                $bytes += strlen($chunk);
                if ($bytes > $this->maxBytes) {
                    throw new B2bPermanentProviderException('Efe feed exceeds the configured snapshot size limit.');
                }
                $this->writeAll($output, $chunk);
                hash_update($hash, $chunk);
            }
            fclose($output);
            $output = null;
            if (!rename($part, $path)) {
                throw new B2bPermanentProviderException('B2B snapshot cannot be finalized.');
            }
            @chmod($path, 0444);
            [$declaredCount] = $this->inspect($path, $deadline);
            if ($this->deadlineExceeded($deadline)) {
                throw new B2bRetryableProviderException('Efe feed snapshot validation timed out.');
            }
            if ($bytes < 1) {
                throw new B2bPermanentProviderException('Efe feed snapshot is empty.');
            }

            $keepFinal = true;

            return new B2bSnapshot($path, $bytes, $declaredCount, hash_final($hash));
        } catch (B2bPermanentProviderException|B2bRetryableProviderException $exception) {
            throw $exception;
        } catch (TransportExceptionInterface $exception) {
            throw new B2bRetryableProviderException('Efe feed transport failed.', 0, $exception);
        } catch (\Throwable $exception) {
            throw new B2bPermanentProviderException('Efe feed snapshot could not be persisted.', 0, $exception);
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (!$keepFinal && is_file($path)) {
                @unlink($path);
            }
            if (is_file($part)) {
                @unlink($part);
            }
        }
    }

    /** @param array<string, mixed> $options */
    private function request(array $options, int $deadline): ResponseInterface
    {
        $url = $this->endpointUrl;
        for ($redirects = 0; ; ++$redirects) {
            $remaining = $this->remainingSeconds($deadline);
            if ($remaining <= 0) {
                throw new B2bRetryableProviderException('Efe feed snapshot transport timed out.');
            }
            $this->assertAllowedUrl($url);
            $hopOptions = array_replace($options, ['max_duration' => $remaining, 'max_redirects' => 0]);
            $response = $this->httpClient->request('GET', $url, $hopOptions);
            $status = $response->getStatusCode();
            if (!in_array($status, [301, 302, 303, 307, 308], true)) {
                return $response;
            }
            if ($redirects >= $this->maxRedirects) {
                throw new B2bPermanentProviderException('Efe feed redirect limit exceeded.');
            }
            $location = $response->getHeaders(false)['location'][0] ?? null;
            if (!is_string($location) || '' === trim($location)) {
                throw new B2bPermanentProviderException('Efe feed redirect is missing a valid location.');
            }
            $this->discardRedirectResponse($response, $deadline);
            $url = $this->redirectUrl($url, trim($location));
        }
    }

    private function discardRedirectResponse(ResponseInterface $response, int $deadline): void
    {
        $length = $response->getHeaders(false)['content-length'][0] ?? null;
        if (null !== $length && (!ctype_digit((string) $length) || (int) $length > 65_536)) {
            throw new B2bPermanentProviderException('Efe feed redirect body exceeds the safety limit.');
        }
        if (!$response instanceof StreamableInterface) {
            $body = $response->getContent(false);
            if (strlen($body) > 65_536) {
                throw new B2bPermanentProviderException('Efe feed redirect body exceeds the safety limit.');
            }

            return;
        }
        $stream = $response->toStream(false);
        $bytes = 0;
        try {
            while (!feof($stream)) {
                if ($this->deadlineExceeded($deadline)) {
                    throw new B2bRetryableProviderException('Efe feed redirect transport timed out.');
                }
                $chunk = fread($stream, 8192);
                if (false === $chunk) {
                    throw new B2bRetryableProviderException('Efe feed redirect stream failed.');
                }
                $bytes += strlen($chunk);
                if ($bytes > 65_536) {
                    throw new B2bPermanentProviderException('Efe feed redirect body exceeds the safety limit.');
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
            throw new B2bPermanentProviderException('Efe feed redirect base URL is invalid.');
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

    private function snapshotFromExisting(B2bSnapshotRequest $request): B2bSnapshot
    {
        $path = $request->existingSnapshotPath;
        if (null === $path || !is_file($path)) {
            throw new B2bPermanentProviderException('The persisted B2B snapshot is missing; resume was refused.');
        }
        $real = realpath($path);
        $root = realpath($request->destinationDirectory);
        if (false === $real || false === $root || !str_starts_with($real, rtrim($root, "/\\").DIRECTORY_SEPARATOR)) {
            throw new B2bPermanentProviderException('Existing B2B snapshot path is outside the run directory.');
        }
        $deadline = $this->deadlineAfter($this->overallTimeout);
        $bytes = filesize($path);
        $sha256 = hash_file('sha256', $path);
        if (false === $bytes || $bytes < 1 || $bytes > $this->maxBytes || false === $sha256) {
            throw new B2bPermanentProviderException('The persisted B2B snapshot is invalid; resume was refused.');
        }
        if (null === $request->existingSnapshotHash || !hash_equals($request->existingSnapshotHash, $sha256)) {
            throw new B2bPermanentProviderException('The persisted B2B snapshot digest is missing or changed; resume was refused.');
        }
        [$declaredCount] = $this->inspect($path, $deadline);

        return new B2bSnapshot($real, $bytes, $declaredCount, $sha256, true);
    }

    /** @return array{int} */
    private function inspect(string $path, ?int $deadline = null): array
    {
        $values = [];
        try {
            foreach (Items::fromFile($path, [
                'pointer' => ['/ok', '/count'],
                'decoder' => new ExtJsonDecoder(true),
            ]) as $key => $value) {
                if (null !== $deadline && $this->deadlineExceeded($deadline)) {
                    throw new B2bRetryableProviderException('Efe feed metadata validation timed out.');
                }
                $values[$key] = $value;
            }
        } catch (B2bPermanentProviderException|B2bRetryableProviderException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new B2bPermanentProviderException('Efe feed metadata is malformed.', 0, $exception);
        }
        if (true !== ($values['ok'] ?? null) || !is_int($values['count'] ?? null) || $values['count'] < 0) {
            throw new B2bPermanentProviderException('Efe feed metadata does not match the production contract.');
        }
        $actualCount = 0;
        try {
            foreach (Items::fromFile($path, [
                'pointer' => '/data',
                'decoder' => new ExtJsonDecoder(true),
            ]) as $_) {
                if (null !== $deadline && $this->deadlineExceeded($deadline)) {
                    throw new B2bRetryableProviderException('Efe feed data validation timed out.');
                }
                ++$actualCount;
                if ($actualCount > $values['count']) {
                    throw new B2bPermanentProviderException('Efe feed contains more rows than declared.');
                }
            }
        } catch (B2bPermanentProviderException|B2bRetryableProviderException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new B2bPermanentProviderException('Efe feed data array is malformed.', 0, $exception);
        }
        if ($actualCount !== $values['count']) {
            throw new B2bPermanentProviderException('Efe feed data length does not match its declared count.');
        }

        return [$values['count']];
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

    private function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (!is_array($parts)
            || 'https' !== strtolower((string) ($parts['scheme'] ?? ''))
            || '' === $host
            || !isset($this->allowedHosts[$host])
            || (isset($parts['port']) && 443 !== (int) $parts['port'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new B2bPermanentProviderException('Efe feed URL is not allowed.');
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
                throw new B2bPermanentProviderException('B2B snapshot temporary file cannot be written.');
            }
            $offset += $written;
        }
    }
}
