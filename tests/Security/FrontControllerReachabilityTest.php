<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The web server's access rules, evaluated rather than grepped.
 *
 * This class exists because PHASE 20 shipped a rule that answered 403 Forbidden on every page
 * of the live site, and nothing in the repository could see it. The rule denied any filename
 * ending in `.php`; the Symfony front controller is `public/index.php`; every request that
 * reached the application was therefore refused before PHP started. The suite was green, the
 * deployment test passed and a live HTTP smoke test passed, because all three ran against
 * Caddy and FrankenPHP, which ignore `.htaccess` entirely. The single test that read the file
 * asserted that the words `FilesMatch`, `php` and `Require all denied` appeared in it — a test
 * of spelling, which a rule denying the whole server would also have passed.
 *
 * The two properties below are deliberately in tension, and both are asserted:
 *
 * 1. Nothing in either file may deny the front controller. One rule here is enough to take the
 *    whole site offline, and it is invisible to every test that goes through HTTP.
 * 2. A script under `public/uploads/` still must be denied, and an ordinary image beside it
 *    must not be. A fix that simply deletes the rule satisfies the first property and quietly
 *    removes the protection the rule was written for.
 *
 * `testTheEvaluatorDetectsTheRuleThatBrokeProduction` pins the evaluator itself: it is handed
 * the exact pattern that shipped and required to call it a denial. Without that, an evaluator
 * that always answered "served" would pass both properties above and be worthless.
 */
final class FrontControllerReachabilityTest extends TestCase
{
    private const string FRONT_CONTROLLER = 'public/index.php';

    private static function evaluator(): HtaccessRuleEvaluator
    {
        return HtaccessRuleEvaluator::fromProjectRoot(\dirname(__DIR__, 2));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function requestsThatMustBeServed(): iterable
    {
        yield 'the storefront root' => ['/yeni/', 'public/index.php'];
        yield 'a catalogue page' => ['/yeni/katalog', 'public/index.php'];
        yield 'a product page' => ['/yeni/urun/bos-altili-filtre', 'public/index.php'];
        yield 'the checkout' => ['/yeni/odeme', 'public/index.php'];
        yield 'the sign-in page' => ['/yeni/giris', 'public/index.php'];
        yield 'a compiled stylesheet' => ['/yeni/assets/styles/storefront.css', 'public/assets/styles/storefront.css'];
        yield 'a stored image' => ['/yeni/uploads/cms/'.\str_repeat('a', 32).'.png', 'public/uploads/cms/'.\str_repeat('a', 32).'.png'];
        yield 'robots.txt at the application root' => ['/yeni/robots.txt', 'public/robots.txt'];
    }

    #[DataProvider('requestsThatMustBeServed')]
    public function testARequestThatShouldReachTheApplicationIsNotDenied(string $requestPath, string $resolvedFile): void
    {
        $evaluator = self::evaluator();
        $denial = $evaluator->denialFor($requestPath, $resolvedFile);

        self::assertNull(
            $denial,
            sprintf(
                "%s is refused by a shipped .htaccess rule, so the store answers 403 Forbidden.\nRules in force:\n%s",
                $requestPath,
                $evaluator->describeRules(),
            ),
        );
    }

    /**
     * The front controller named on its own, because it is the single file whose denial is
     * total. A failure here is a fully offline site, so it gets its own assertion and its own
     * message rather than being one row in a data provider.
     */
    public function testTheFrontControllerItselfIsNotDeniedByAnyRule(): void
    {
        $evaluator = self::evaluator();
        $denial = $evaluator->denialFor('/yeni/', self::FRONT_CONTROLLER);

        self::assertNull(
            $denial,
            sprintf(
                "The Symfony front controller is denied by:\n%s\n\nEvery page of the store answers 403 Forbidden.\n"
                ."Rules in force:\n%s",
                (string) $denial,
                $evaluator->describeRules(),
            ),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function uploadedScriptNames(): iterable
    {
        yield 'php' => ['shell.php'];
        yield 'phtml' => ['shell.phtml'];
        yield 'php7' => ['shell.php7'];
        yield 'phar' => ['shell.phar'];
        yield 'cgi' => ['shell.cgi'];
        yield 'py' => ['shell.py'];
        yield 'htaccess' => ['.htaccess'];
    }

    #[DataProvider('uploadedScriptNames')]
    public function testAScriptUnderUploadsIsStillDenied(string $name): void
    {
        $evaluator = self::evaluator();
        $request = '/yeni/uploads/cms/'.$name;
        $resolved = 'public/uploads/cms/'.$name;

        self::assertNotNull(
            $evaluator->denialFor($request, $resolved),
            sprintf('%s would be executable if it ever reached public/uploads/.', $name),
        );
    }

    /**
     * The protection is about the path, not the extension allow-list. A deny list that only
     * permitted .jpg/.png/.webp would break the day CmsMediaStorage accepts a fourth format,
     * so the rule must refuse scripts and pass everything else.
     */
    #[DataProvider('legitimateUploadNames')]
    public function testAnOrdinaryStoredFileIsNotDenied(string $name): void
    {
        $evaluator = self::evaluator();

        self::assertTrue(
            $evaluator->serves('/yeni/uploads/cms/'.$name, 'public/uploads/cms/'.$name),
            sprintf('%s is a stored file the storefront must be able to render.', $name),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function legitimateUploadNames(): iterable
    {
        yield 'a png' => [\str_repeat('a', 32).'.png'];
        yield 'a jpeg' => [\str_repeat('a', 32).'.jpg'];
        yield 'a webp' => [\str_repeat('a', 32).'.webp'];
    }

    /**
     * The upload protection must not have been bought by narrowing it to a prefix that misses
     * the directory B2B product images land in. CmsMediaStorage writes below public/uploads/
     * whatever the feature; the deny is on the path.
     */
    public function testAProductImageUnderUploadsIsServed(): void
    {
        $evaluator = self::evaluator();
        $resolved = 'public/uploads/b2b/'.\str_repeat('b', 32).'.webp';

        self::assertTrue($evaluator->serves('/yeni/uploads/b2b/'.\str_repeat('b', 32).'.webp', $resolved));
        self::assertNotNull($evaluator->denialFor('/yeni/uploads/b2b/shell.php', 'public/uploads/b2b/shell.php'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function applicationSourcePaths(): iterable
    {
        yield 'the environment file' => ['/yeni/.env', '.env'];
        yield 'composer.json' => ['/yeni/composer.json', 'composer.json'];
        yield 'a source file' => ['/yeni/src/Entity/Commerce/AuditLog.php', 'src/Entity/Commerce/AuditLog.php'];
        yield 'a config file' => ['/yeni/config/packages/security.yaml', 'config/packages/security.yaml'];
        yield 'a vendor file' => ['/yeni/vendor/autoload.php', 'vendor/autoload.php'];
        yield 'a template' => ['/yeni/templates/base.html.twig', 'templates/base.html.twig'];
        yield 'the console' => ['/yeni/bin/console', 'bin/console'];
        yield 'the log directory' => ['/yeni/var/log/prod.log', 'var/log/prod.log'];
    }

    #[DataProvider('applicationSourcePaths')]
    public function testApplicationSourceAndConfigurationRemainDenied(string $requestPath, string $resolvedFile): void
    {
        self::assertNotNull(
            self::evaluator()->denialFor($requestPath, $resolvedFile),
            sprintf('%s is application internals and must never be served.', $requestPath),
        );
    }

    /**
     * Pins the evaluator, not the configuration.
     *
     * Both properties above are satisfiable by an evaluator that never denies anything, and the
     * first one in particular is the dangerous direction: a broken evaluator would report the
     * front controller as reachable no matter what the files say. So it is handed the exact
     * pattern that broke production and required to call it a denial.
     */
    public function testTheEvaluatorDetectsTheRuleThatBrokeProduction(): void
    {
        $shipped = <<<'HTACCESS'
            <FilesMatch "\.(?i:php|phtml|php[0-9]|phar|cgi|pl|py|sh|asp|aspx|jsp|htaccess|htpasswd)$">
                <IfModule mod_authz_core.c>
                    Require all denied
                </IfModule>
            </FilesMatch>
            HTACCESS;

        $evaluator = new HtaccessRuleEvaluator(['/yeni/.htaccess' => $shipped]);

        self::assertNotNull(
            $evaluator->denialFor('/yeni/', self::FRONT_CONTROLLER),
            'The evaluator cannot see the rule this very test class was written for, so it proves nothing about the real files.',
        );
        self::assertTrue(
            $evaluator->serves('/yeni/uploads/cms/'.\str_repeat('a', 32).'.png', 'public/uploads/cms/'.\str_repeat('a', 32).'.png'),
            'A global filename deny must not swallow stored images either, or the evaluator is simply refusing everything.',
        );
    }

    /**
     * The uploaded-script protection is claimed at two levels on purpose: the document root is
     * one directory above the application on the live host, and a `.htaccess` cannot be
     * committed inside public/uploads/ because that whole directory is gitignored. So both files
     * have to carry the rule, and losing one of them is a silent loss of protection.
     */
    public function testBothHtaccessFilesCarryTheUploadedScriptDeny(): void
    {
        $root = \dirname(__DIR__, 2);

        foreach (['/.htaccess', '/public/.htaccess'] as $path) {
            $contents = (string) file_get_contents($root.$path);
            $evaluator = new HtaccessRuleEvaluator([$path => $contents]);

            self::assertNotNull(
                $evaluator->denialFor('/yeni/uploads/cms/shell.php', 'public/uploads/cms/shell.php'),
                sprintf('%s no longer denies a script under public/uploads/.', $path),
            );
            self::assertNull(
                $evaluator->denialFor('/yeni/', self::FRONT_CONTROLLER),
                sprintf('%s denies the Symfony front controller, which takes the whole site offline.', $path),
            );
        }
    }
}
