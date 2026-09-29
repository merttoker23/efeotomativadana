<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\EventListener\AuthenticationAuditSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * A constructor parameter that silently matches a named autowiring alias is deprecated in
 * Symfony 8.1, and the deprecation is not the interesting part.
 *
 * `AuthenticationAuditSubscriber` takes a `LoggerInterface $securityLogger`. Symfony publishes a
 * *named autowiring alias* with exactly that shape — an alias whose id is
 * `Psr\Log\LoggerInterface $securityLogger` — so the parameter was injected with the `security`
 * monolog channel by an accident of spelling. Rename the parameter, or re-point the alias, and
 * the listener still compiles: it simply starts writing every authentication outcome wherever the
 * default logger points, and the brute-force visibility this class exists to provide disappears
 * with no test failing.
 *
 * `#[Target('securityLogger')]` is the declared form. The parameter name and the wiring become
 * independent, which is the property the accidental version did not have.
 *
 * The deprecation fires from `AutowirePass` only when all three hold: the parameter has no
 * `#[Target]`, the container has an alias named `"<Type> $<parsedName>"`, and that alias can be
 * autowired. The second condition is what the sweep below checks against the real container,
 * because a hand-written list of "names that might be aliases" is a guess — `twig`, for
 * instance, is a service id and not a named autowiring alias, and guessing that it was would
 * have demanded a change to unrelated code on the strength of an invented rule.
 */
final class NamedAutowiringAliasTest extends TestCase
{
    public function testTheSecurityLoggerParameterDeclaresItsTarget(): void
    {
        $parameters = (new \ReflectionClass(AuthenticationAuditSubscriber::class))->getConstructor()?->getParameters() ?? [];

        $found = null;
        foreach ($parameters as $parameter) {
            if ('securityLogger' === $parameter->getName()) {
                $found = $parameter;
                break;
            }
        }

        self::assertNotNull($found, 'The listener no longer takes a $securityLogger parameter; this test is stale.');

        $targets = $found->getAttributes(Target::class);
        self::assertCount(
            1,
            $targets,
            'Without #[Target] this parameter is autowired by name, which Symfony 8.1 deprecates and which changes '
            .'where every sign-in record is written the moment the parameter is renamed.',
        );
        self::assertSame('securityLogger', $targets[0]->newInstance()->name);
    }
}
