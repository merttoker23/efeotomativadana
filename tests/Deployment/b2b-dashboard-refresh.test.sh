#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
CONTROLLER="$ROOT/assets/controllers/b2b_dashboard_controller.js"
TEMPLATE="$ROOT/templates/admin/integration/b2b.html.twig"
BASE="$ROOT/templates/admin/base.html.twig"

test -f "$CONTROLLER"
test -f "$TEMPLATE"
test -f "$BASE"
grep -F -- 'data-controller="b2b-dashboard"' "$TEMPLATE"
grep -F -- 'data-b2b-dashboard-interval-value' "$TEMPLATE"
grep -F -- "importmap('app')" "$BASE"
grep -F -- 'setInterval' "$CONTROLLER"
grep -F -- 'fetch(window.location.href' "$CONTROLLER"
grep -F -- 'replaceWith' "$CONTROLLER"
