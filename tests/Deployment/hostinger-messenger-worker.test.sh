#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
LAUNCHER="$ROOT/bin/hostinger-messenger-worker"
TEST_ROOT=$(mktemp -d)
trap 'rm -rf "$TEST_ROOT"' EXIT

mkdir -p "$TEST_ROOT/app/var/log" "$TEST_ROOT/bin"
cat > "$TEST_ROOT/bin/php" <<'PHP'
#!/bin/sh
printf '%s\n' "$@" > "$ARGS_FILE"
PHP
cat > "$TEST_ROOT/bin/flock" <<'FLOCK'
#!/bin/sh
exit 0
FLOCK
chmod +x "$TEST_ROOT/bin/php" "$TEST_ROOT/bin/flock"

ARGS_FILE="$TEST_ROOT/args" \
PATH="$TEST_ROOT/bin:$PATH" \
EFE_B2B_APP_DIR="$TEST_ROOT/app" \
PHP_BIN="$TEST_ROOT/bin/php" \
"$LAUNCHER"

test -s "$TEST_ROOT/args"
grep -Fx -- 'messenger:consume' "$TEST_ROOT/args"
grep -Fx -- 'async' "$TEST_ROOT/args"
grep -Fx -- '--limit=1' "$TEST_ROOT/args"
grep -Fx -- '--env=prod' "$TEST_ROOT/args"
grep -Fx -- '--no-interaction' "$TEST_ROOT/args"
grep -Fx -- '--memory-limit=480M' "$TEST_ROOT/args"
test -f "$TEST_ROOT/app/var/log/messenger-worker.log"
