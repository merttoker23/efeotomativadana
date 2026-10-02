<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class ProductionDeploymentTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/deployment-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $stub = <<<'BASH'
#!/bin/sh
printf '%s %s\n' "$(basename "$0")" "$*" >> "$DEPLOY_LOG"
if [ -n "${FAIL_COMMAND:-}" ] && [ "${1:-} ${2:-}" = "$FAIL_COMMAND" ]; then
    exit 42
fi
BASH;
        foreach (['composer', 'php', 'docker-php-entrypoint'] as $command) {
            file_put_contents($this->directory.'/'.$command, $stub);
            chmod($this->directory.'/'.$command, 0700);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testDeploymentMigratesBeforePreparingTheNewRelease(): void
    {
        $process = $this->runScript('bin/deploy-prod');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame([
            'composer install --no-dev --optimize-autoloader --prefer-dist --no-progress --no-scripts',
            'php bin/console doctrine:migrations:migrate --env=prod --no-debug --no-interaction --allow-no-migration',
            'php bin/console doctrine:migrations:up-to-date --env=prod --no-debug --no-interaction',
            'php bin/console cache:clear --env=prod --no-debug',
            'php bin/console assets:install public --env=prod --no-debug',
            'php bin/console importmap:install --env=prod --no-debug',
            'php bin/console asset-map:compile --env=prod --no-debug',
        ], $this->commands());
    }

    public function testMigrationFailureStopsDeploymentBeforeCacheAndAssets(): void
    {
        $process = $this->runScript('bin/deploy-prod', [], 'bin/console doctrine:migrations:migrate');

        self::assertSame(42, $process->getExitCode());
        self::assertCount(2, $this->commands());
        self::assertStringNotContainsString('Deployment complete.', $process->getOutput());
    }

    public function testWebStartupMigratesBeforeLaunchingTheServer(): void
    {
        $process = $this->runScript('docker/production-entrypoint.sh', ['frankenphp', 'run']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame([
            'php bin/console doctrine:migrations:migrate --env=prod --no-debug --no-interaction --allow-no-migration',
            'php bin/console doctrine:migrations:up-to-date --env=prod --no-debug --no-interaction',
            'docker-php-entrypoint frankenphp run',
        ], $this->commands());
    }

    public function testFailedMigrationNeverLaunchesTheServer(): void
    {
        $process = $this->runScript('docker/production-entrypoint.sh', ['frankenphp', 'run'], 'bin/console doctrine:migrations:migrate');

        self::assertSame(42, $process->getExitCode());
        self::assertCount(1, $this->commands());
    }

    public function testInheritedFrankenphpOptionsCannotBypassTheMigrationGuard(): void
    {
        $process = $this->runScript('docker/production-entrypoint.sh', ['--config', '/etc/frankenphp/Caddyfile'], 'bin/console doctrine:migrations:migrate');

        self::assertSame(42, $process->getExitCode());
        self::assertCount(1, $this->commands());
    }

    public function testWorkerChecksTheSchemaWithoutRacingTheWebMigration(): void
    {
        $process = $this->runScript('docker/production-entrypoint.sh', ['php', 'bin/console', 'messenger:consume', 'async']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame([
            'php bin/console doctrine:migrations:up-to-date --env=prod --no-debug --no-interaction',
            'docker-php-entrypoint php bin/console messenger:consume async',
        ], $this->commands());
    }

    public function testStaleSchemaNeverStartsTheWorker(): void
    {
        $process = $this->runScript('docker/production-entrypoint.sh', ['php', 'bin/console', 'messenger:consume', 'async'], 'bin/console doctrine:migrations:up-to-date');

        self::assertSame(42, $process->getExitCode());
        self::assertCount(1, $this->commands());
    }

    public function testMissingSecretNeverChangesTheDatabaseOrLaunchesTheServer(): void
    {
        $process = $this->runScript('docker/production-entrypoint.sh', ['frankenphp', 'run'], '', '');

        self::assertNotSame(0, $process->getExitCode());
        self::assertSame([], $this->commands());
    }

    /** @param list<string> $arguments */
    private function runScript(string $script, array $arguments = [], string $failure = '', string $secret = 'deployment-test-secret'): Process
    {
        $process = new Process(['bash', $script, ...$arguments], \dirname(__DIR__, 2), [
            'PATH' => $this->directory.':'.getenv('PATH'),
            'DEPLOY_LOG' => $this->directory.'/commands',
            'FAIL_COMMAND' => $failure,
            'APP_SECRET' => $secret,
        ]);
        $process->run();

        return $process;
    }

    /** @return list<string> */
    private function commands(): array
    {
        return is_file($this->directory.'/commands') ? file($this->directory.'/commands', \FILE_IGNORE_NEW_LINES) ?: [] : [];
    }
}
