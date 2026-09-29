<?php

declare(strict_types=1);

namespace App\Module\Audit;

/**
 * Who is acting, as far as an audit row can tell.
 *
 * The distinction the audit trail needs is not "logged in or not", it is "a person, the
 * system's own machinery, or an external provider speaking for itself". A payment captured
 * by a webhook was not done by a person; recording it as one would be a lie an operator later
 * has to disprove.
 */
enum AuditActorType: string
{
    /** A signed-in staff member. */
    case Administrator = 'administrator';
    /** A signed-in customer. */
    case Customer = 'customer';
    /** This application's own code: a Messenger handler, a console command, a scheduled run. */
    case System = 'system';
    /** An external party whose own signature verified the message — a payment or carrier webhook. */
    case Provider = 'provider';
    /** Nobody in particular: a rejected request that never authenticated. */
    case Anonymous = 'anonymous';
}
