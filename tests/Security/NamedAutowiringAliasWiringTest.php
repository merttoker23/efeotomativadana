<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\EventListener\AuthenticationAuditSubscriber;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The `securityLogger` wiring, proved against the container.
 *
 * Separate from `NamedAutowiringAliasTest`, and in its own file for the same reason
 * `LogRedactionWiringTest` has one: PHPUnit maps a `*Test.php` file to the single class whose
 * name matches the filename, and silently skips any second test class in the same file. These
 * three tests were in `NamedAutowiringAliasTest.php` and were collected by nothing — the file
 * reported 1 test where it contained 4.
 *
 * The attribute test proves the coupling is *declared*. This proves the declared target resolved
 * to the right service. If the `securityLogger` alias were re-pointed or removed,
 * `#[Target('securityLogger')]` would keep compiling happily while every sign-in record went
 * somewhere no operator ever reads, and the brute-force visibility the listener exists to provide
 * would be gone with nothing failing.
 *
 * The logger's channel name is checked by reflection rather than by dispatching a real login,
 * because a dispatched login would write an audit row and spend the firewall's throttle budget
 * to prove something reflection answers exactly and without side effects.
 */
final class NamedAutowiringAliasWiringTest extends KernelTestCase
{
    public function testTheListenerIsGivenTheSecurityChannelLogger(): void
    {
        $listener = self::getContainer()->get(AuthenticationAuditSubscriber::class);
        self::assertInstanceOf(AuthenticationAuditSubscriber::class, $listener);

        $injected = (new \ReflectionProperty(AuthenticationAuditSubscriber::class, 'securityLogger'))->getValue($listener);

        self::assertInstanceOf(\Psr\Log\LoggerInterface::class, $injected);
        self::assertInstanceOf(\Monolog\Logger::class, $injected, 'The security channel logger is a Monolog logger, and its channel name is the property under test.');
        self::assertSame(
            'security',
            $injected->getName(),
            'The listener logs to a channel other than `security`, so authentication outcomes land in a file nobody reads.',
        );
    }

    public function testTheSecurityChannelExistsForBinConsoleToShow(): void
    {
        self::assertTrue(
            self::getContainer()->has('monolog.logger.security'),
            'There is no `security` monolog channel, so `bin/console monolog:show security` cannot answer who tried to sign in.',
        );
    }

    /**
     * Records how this deprecation is actually detected, because the obvious way to test it does
     * not work and a future reader will otherwise try it.
     *
     * The notice is raised inside `AutowirePass` at container *compile* time, when the
     * `ContainerBuilder` holds an alias whose id is `"<Type> $<parsedName>"`. The compiled
     * container does not keep that alias: measured, the dumped test container has no
     * `getAliases()` method, and neither `Psr\Log\LoggerInterface $securityLogger` nor
     * `securityLogger` exists in it, because autowiring has already consumed and removed it. A
     * test therefore cannot read the alias map after the fact, and a sweep over "parameters whose
     * name looks like it could be an alias" would be guessing — `twig` is a service id and not a
     * named autowiring alias, so treating it as one would demand a change to unrelated code on
     * the strength of an invented rule.
     *
     * So the two things that can be asserted honestly are asserted above: the coupling is
     * declared with `#[Target]`, and it resolved to the right channel. That the deprecation is
     * gone is a compile-time fact, and it is worth saying *how* it was ever visible, because the
     * obvious gate does not catch it: `phpunit.dist.xml` sets `failOnDeprecation="true"` and
     * registers `trigger_deprecation` as a deprecation trigger, so the suite would fail — but
     * only on a *cold* test cache, because the notice is emitted while the container compiles.
     * With a warm `var/cache/test/` the container is never recompiled, the notice never fires,
     * and the suite is green. It surfaced on the server's `cache:clear --env=prod`.
     */
    public function testTheCompiledContainerNoLongerCarriesTheAliasThisCouplingRodeOn(): void
    {
        self::assertFalse(
            self::getContainer()->has('securityLogger'),
            'The compiled container now retains the `securityLogger` alias, so a real named-alias sweep has become '
            .'possible and should replace this test and the note above.',
        );
    }
}
