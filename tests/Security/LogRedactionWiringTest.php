<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Shared\Logging\RedactingLogProcessor;
use Monolog\Handler\TestHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The redaction wiring, proved against the container.
 *
 * Separate from the policy tests on purpose: those can all pass with the processor registered
 * nowhere, and the application's guarantee is that a log line is redacted *by configuration*.
 *
 * This class used to live in `LogRedactionTest.php`, and that is worth recording. PHPUnit's
 * suite is `<directory>tests</directory>`, and PHPUnit maps a `*Test.php` file to the single
 * class whose name matches the filename — a second test class in the same file is silently
 * skipped, with no warning and no failure. So these three tests were committed and never once
 * executed, while the phase that added them reported the wiring as asserted. It is the same
 * failure as a test that asserts a string is present instead of a behaviour: the guarantee
 * exists on paper and nothing checks it. Hence its own file.
 */
final class LogRedactionWiringTest extends KernelTestCase
{
    public function testTheContainerRegistersTheProcessorAsAMonologProcessor(): void
    {
        // Note what is deliberately *not* asserted here. This test used to begin with
        // `getContainer()->has('monolog.logger_prototype')`, and it had never run — it sat in a
        // second class inside LogRedactionTest.php, which PHPUnit silently skips. The moment it
        // was moved into a collected file it failed, because the Monolog logger prototype is a
        // private service the compiler inlines and removes, so its absence from the compiled
        // container says nothing about whether a processor is attached to anything.
        //
        // An assertion about a private service is a claim about the compiler's output, and it
        // broke for a reason that had nothing to do with redaction. The two behavioural tests
        // below are the real evidence: they log through the container's own logger and read the
        // record back, which is the only way to show that a line this application writes is
        // redacted.
        self::assertTrue(
            self::getContainer()->has(RedactingLogProcessor::class),
            'The redaction processor is not registered; every log line in this application is unredacted.',
        );
    }

    public function testLoggingThroughTheContainerProducesARedactedRecord(): void
    {
        // The container logger, reached by the service id rather than by a real request: the
        // property under test is that the *logger* carries the processor, and a real sign-in
        // would spend the firewall's throttle budget this class has no reason to touch.
        $logger = self::getContainer()->get('monolog.logger.security');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $logger->warning('A sign-in failed.', [
            'firewall' => 'main',
            'context' => ['api_key' => 'sk_live_51H8xY2abc', 'user' => 'someone@example.com'],
        ]);

        foreach ($logger->getHandlers() as $registered) {
            if ($registered !== $handler) {
                $logger->popHandler();
            }
        }

        $records = $handler->getRecords();
        self::assertNotEmpty($records, 'The container logger produced no record, so nothing can be asserted about it.');
        $encoded = var_export($records[array_key_last($records)]->context, true);

        self::assertStringNotContainsString('sk_live_51H8xY2abc', $encoded, 'The container logger is not redacting.');
        self::assertStringContainsString('someone@example.com', $encoded);
    }

    public function testTheAuditChannelIsAlsoRedacted(): void
    {
        $logger = self::getContainer()->get('monolog.logger.audit');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $logger->info('payment.refunded_by_staff', ['token' => 'paytr_token_abcdef1234567890']);

        foreach ($logger->getHandlers() as $registered) {
            if ($registered !== $handler) {
                $logger->popHandler();
            }
        }

        $records = $handler->getRecords();
        self::assertNotEmpty($records);
        self::assertStringNotContainsString('paytr_token_abcdef1234567890', var_export($records[array_key_last($records)]->context, true));
    }
}
