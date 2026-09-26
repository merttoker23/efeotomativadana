<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Gateway\PayTR;

use App\Module\Payment\Gateway\PayTR\PaytrClientIp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PayTR requires the customer's own IP on every token request and rejects a private or local
 * address outright, so this is read per request and never invented.
 *
 * Symfony keeps the trusted-proxy list statically, so every test states its own proxy trust
 * explicitly. Without that, one test's trusted proxy would silently make the next test believe
 * a spoofed header — which is exactly the bug this class must not have.
 */
final class PaytrClientIpTest extends TestCase
{
    protected function setUp(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    public function testTheConnectingAddressIsUsedWhenThereIsNoProxy(): void
    {
        $resolver = new PaytrClientIp($this->stackFor($this->request(['REMOTE_ADDR' => '88.99.140.12'])));

        self::assertSame('88.99.140.12', $resolver->customerIp());
    }

    public function testTheForwardedAddressIsUsedWhenTheProxyIsTrusted(): void
    {
        // Behind a TLS-terminating load balancer the real client only appears in this header.
        $request = $this->request([
            'REMOTE_ADDR' => '172.18.0.9',
            'HTTP_X_FORWARDED_FOR' => '88.99.140.12',
        ]);
        $request->setTrustedProxies(['172.18.0.9'], Request::HEADER_X_FORWARDED_FOR);

        self::assertSame('88.99.140.12', (new PaytrClientIp($this->stackFor($request)))->customerIp());
    }

    public function testAnUntrustedForwardedHeaderIsIgnored(): void
    {
        $resolver = new PaytrClientIp($this->stackFor($this->request([
            'REMOTE_ADDR' => '172.18.0.9',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ])));

        self::assertSame('172.18.0.9', $resolver->customerIp());
    }

    public function testASpoofedForwardedChainIsIgnoredWithoutATrustedProxy(): void
    {
        $resolver = new PaytrClientIp($this->stackFor($this->request([
            'REMOTE_ADDR' => '172.18.0.9',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 5.6.7.8',
        ])));

        self::assertSame('172.18.0.9', $resolver->customerIp());
    }

    public function testAForwardedHeaderFromSomebodyElseIsIgnoredEvenWithAProxyConfigured(): void
    {
        $request = $this->request([
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);
        $request->setTrustedProxies(['172.18.0.9'], Request::HEADER_X_FORWARDED_FOR);

        self::assertSame('203.0.113.7', (new PaytrClientIp($this->stackFor($request)))->customerIp());
    }

    public function testNoRequestAtAllIsReportedAsUnknownRatherThanGuessed(): void
    {
        self::assertNull((new PaytrClientIp(new RequestStack()))->customerIp());
    }

    public function testAnIpv6CustomerIsAccepted(): void
    {
        $resolver = new PaytrClientIp($this->stackFor($this->request(['REMOTE_ADDR' => '2001:db8:85a3::8a2e:370:7334'])));

        self::assertSame('2001:db8:85a3::8a2e:370:7334', $resolver->customerIp());
    }

    /**
     * @param array<string, string> $server
     */
    private function request(array $server): Request
    {
        return Request::create('https://store.test/odeme', 'POST', server: $server);
    }

    private function stackFor(Request $request): RequestStack
    {
        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }
}
