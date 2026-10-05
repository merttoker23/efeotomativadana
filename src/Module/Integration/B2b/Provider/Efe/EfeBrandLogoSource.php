<?php

namespace App\Module\Integration\B2b\Provider\Efe;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Reads the logo links actually published by Efe; never fabricates a URL for an ID. */
final readonly class EfeBrandLogoSource
{
    /** @param list<string> $allowedHosts */
    public function __construct(private HttpClientInterface $httpClient, private string $endpointUrl, private array $allowedHosts, private ?string $caBundle)
    {
    }

    /** @return array<string|int, string> */
    public function urls(): array
    {
        $parts = parse_url($this->endpointUrl);
        if (!is_array($parts) || !isset($parts['host'])) {
            throw new \RuntimeException('Efe logo index endpoint is invalid.');
        }
        $origin = ($parts['scheme'] ?? '').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $this->assertAllowed($origin.'/');
        $options = ['max_redirects' => 0, 'max_connect_duration' => 10, 'timeout' => 30, 'max_duration' => 60, 'buffer' => false];
        if (null !== $this->caBundle && '' !== trim($this->caBundle)) {
            if (!is_readable($this->caBundle)) throw new \RuntimeException('Efe logo CA bundle is not readable.');
            $options['cafile'] = $this->caBundle;
        }
        $response = $this->httpClient->request('GET', $origin.'/', $options);
        $html = '';
        try {
            // Efe publishes its manufacturer marquee in the HTML body of both its 200
            // page and its 302 login response. Read those advertised links without
            // following the Location header or opening a second endpoint.
            if (!in_array($response->getStatusCode(), [200, 302], true) || !str_starts_with(strtolower($response->getHeaders(false)['content-type'][0] ?? ''), 'text/html')) {
                throw new \RuntimeException('Efe logo index response is invalid.');
            }
            $this->assertAllowed((string) ($response->getInfo('url') ?: $origin.'/'));
            foreach ($this->httpClient->stream($response) as $chunk) {
                $html .= $chunk->getContent();
                if (strlen($html) > 1_048_576) throw new \RuntimeException('Efe logo index exceeds the size limit.');
            }
        } finally {
            $response->cancel();
        }
        if ('' === trim($html)) return [];
        $document = new \DOMDocument();
        @$document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $urls = [];
        foreach ($document->getElementsByTagName('img') as $image) {
            $src = trim($image->getAttribute('src'));
            // This path is observed in Efe's manufacturer marquee. Only advertised links
            // qualify; vehicle/product images and absent manufacturer IDs do not.
            $url = str_starts_with($src, 'img/') || str_starts_with($src, '/img/') ? $origin.'/'.ltrim($src, '/') : $src;
            try {
                $this->assertAllowed($url);
            } catch (\RuntimeException) {
                continue;
            }
            if (preg_match('~^/img/markalar/([1-9][0-9]*)\.jpg$~D', (string) parse_url($url, PHP_URL_PATH), $match)) {
                $urls[$match[1]] = $url;
            }
        }
        return $urls;
    }

    private function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || 'https' !== strtolower($parts['scheme'] ?? '')
            || !in_array(strtolower($parts['host'] ?? ''), array_map('strtolower', $this->allowedHosts), true)
            || (isset($parts['port']) && 443 !== $parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('Efe logo URL is not allowed.');
        }
    }
}
