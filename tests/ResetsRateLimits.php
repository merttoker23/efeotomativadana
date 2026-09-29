<?php

declare(strict_types=1);

namespace App\Tests;

/**
 * Empties the rate limiter's cache pool for a test that needs to sign in through a form.
 *
 * The pool is file-backed and deliberately shared across requests, because a login budget that
 * reset per request would throttle nobody. That same property makes it shared across a whole
 * suite run, so one test's spent budget is the next test's first failure — which is how adding
 * `login_throttling` made the admin login test fail only when it ran after a security test.
 *
 * The pool is cleared rather than replaced with an in-memory adapter. An in-memory pool is the
 * obvious alternative and it does not work: the pool is reset between requests, so counters never
 * accumulate and the limiter refuses nobody. Clearing keeps these tests running against the same
 * storage production uses, which is also the only way a test here can catch a limit that an
 * in-memory pool would happily accept.
 *
 * Only a test that signs in *through a form* needs this. `loginUser()` injects a token directly
 * and never consults the limiter, so a suite that authenticates that way is unaffected.
 */
trait ResetsRateLimits
{
    protected function resetRateLimits(): void
    {
        $container = static::getContainer();

        self::assertTrue(
            $container->has('cache.rate_limiter'),
            'The rate limiter pool does not exist; login throttling cannot be exercised at all.',
        );
        $container->get('cache.rate_limiter')->clear();
    }
}
