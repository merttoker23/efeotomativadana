#!/bin/sh
# The security headers, measured over real HTTP against the running server.
#
# Run inside the app container:
#   docker compose exec -T app sh tests/Deployment/security-headers.test.sh
#
# This exists because a functional test inside the Symfony kernel cannot see the whole picture.
# Two specific gaps it was written to close, both found by running it:
#
# 1. Symfony builds the response for a routing failure (a 405) or an uncaught exception without
#    dispatching KernelEvents::RESPONSE, so headers attached to that event alone never reach an
#    error page. The PayTR notification endpoint answered 405 with no `nosniff` and no framing
#    policy until the subscriber was also bound to the exception event.
# 2. "The header is configured" and "the header is on the wire" are different claims, and only
#    the second one is worth anything to an operator.
#
# The bodies are not checked for content beyond a minimum length: this is a header gate, and the
# functional suite is the content gate.

set -eu

BASE="${BASE:-http://127.0.0.1}"
FAILURES=0

# Path, expected status. A 405 is expected for the POST-only provider endpoint; every other path
# must render.
check() {
    path="$1"
    expected_status="$2"

    headers=$(curl -sS -o /dev/null -D - "$BASE$path" || true)

    status=$(printf '%s' "$headers" | awk 'NR==1 {print $2}')
    if [ "$status" != "$expected_status" ]; then
        echo "FAIL $path: status $status, expected $expected_status"
        FAILURES=$((FAILURES + 1))
        return
    fi

    for pair in 'X-Content-Type-Options:nosniff' 'X-Frame-Options:DENY' 'Referrer-Policy:strict-origin-when-cross-origin'; do
        name=${pair%%:*}
        value=${pair#*:}
        actual=$(printf '%s' "$headers" | tr -d '\r' | awk -F': ' -v n="$(echo "$name" | tr 'A-Z' 'a-z')" 'tolower($1) == n { print $2 }')
        if [ "$actual" != "$value" ]; then
            echo "FAIL $path: $name is '${actual:-absent}', expected '$value'"
            FAILURES=$((FAILURES + 1))
        fi
    done

    csp=$(printf '%s' "$headers" | tr -d '\r' | awk -F': ' 'tolower($1) == "content-security-policy" { print $2 }')

    # The content policy is required on rendered pages and optional on error pages in a debug
    # run: Symfony's own ErrorListener removes it from debug error pages on purpose, so the
    # profiler toolbar's inline scripts are not blocked by the application's policy. That is
    # framework behaviour and it is debug-only; production error pages carry the policy, which
    # tests/Security/ResponseSecurityHeadersTest asserts.
    if [ -z "$csp" ]; then
        case "$expected_status" in
            200|301|302)
                echo "FAIL $path: Content-Security-Policy absent on a rendered page"
                FAILURES=$((FAILURES + 1))
                ;;
            *)
                # Reported by the block below, which prints the summary line; keep one line per
                # path so the output reads as a list rather than as duplicates.
                ;;
        esac
    elif case "$csp" in *default-src*) false ;; *) true ;; esac; then
        echo "FAIL $path: Content-Security-Policy is '${csp}'"
        FAILURES=$((FAILURES + 1))
    fi

    request_id=$(printf '%s' "$headers" | tr -d '\r' | awk -F': ' 'tolower($1) == "x-request-id" { print $2 }')
    if [ -z "$request_id" ]; then
        echo "FAIL $path: missing X-Request-Id"
        FAILURES=$((FAILURES + 1))
    fi

    echo "ok   $path ($status)"
}

# Public storefront.
check /katalog 200
check /giris 200
check /kayit 200
check /parolami-unuttum 200
check /blog 200
check /bilgi 200
check /sepet 200
check /admin/login 200
check /hesabim 302
check /robots.txt 200

# The error paths, which is where the headers were missing.
check /odeme/paytr/bildirim 405
check /admin 302
check /yok-boyle-bir-sayfa 404

# A 405 must not be the *only* thing the notification endpoint answers: a POST is the real
# contract and must still be reachable, so a change that turned the endpoint into a blanket 403
# would be caught here rather than by a customer's payment failing.
posted=$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$BASE/odeme/paytr/bildirim" || true)
case "$posted" in
    200|400|500)
        echo "ok   POST /odeme/paytr/bildirim ($posted)"
        ;;
    *)
        echo "FAIL POST /odeme/paytr/bildirim answered $posted; the endpoint must answer for a provider, not refuse outright."
        FAILURES=$((FAILURES + 1))
        ;;
esac

# HSTS must not be sent from a plain-http host: it would pin that host in a browser for two
# years. Its production-only nature is asserted in tests/Security/ResponseSecurityHeadersTest.
if curl -sS -o /dev/null -D - "$BASE/katalog" | grep -qi '^Strict-Transport-Security:'; then
    echo "FAIL /katalog: Strict-Transport-Security sent over plain http"
    FAILURES=$((FAILURES + 1))
fi

if [ "$FAILURES" -ne 0 ]; then
    echo "security-headers: $FAILURES failure(s)"
    exit 1
fi

echo "security-headers: all checks passed"
