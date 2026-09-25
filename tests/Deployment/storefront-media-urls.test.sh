#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
HTACCESS="$ROOT/.htaccess"
PLACEHOLDER="$ROOT/assets/storefront/images/product-placeholder.svg"
CARD="$ROOT/templates/components/product_card.html.twig"
PRODUCT="$ROOT/templates/storefront/catalog/product.html.twig"

# Uploaded media lives under public/ but the document root is one level above the
# application directory, so both the rewrite rule and the prefixed URLs are required.
test -f "$HTACCESS"
test -f "$PLACEHOLDER"
grep -F -- 'RewriteCond %{REQUEST_URI} ^/yeni/uploads/(.+)$' "$HTACCESS"
grep -F -- 'RewriteCond %{DOCUMENT_ROOT}/yeni/public/uploads/%1 -f' "$HTACCESS"
grep -F -- 'RewriteRule ^.*$ public/uploads/%1 [L]' "$HTACCESS"

# The rewrite must stay ahead of the front controller fallback.
UPLOADS_LINE=$(grep -nF -- 'RewriteRule ^.*$ public/uploads/%1 [L]' "$HTACCESS" | cut -d: -f1)
INDEX_LINE=$(grep -nF -- 'RewriteRule ^.*$ public/index.php [L]' "$HTACCESS" | cut -d: -f1)
test "$UPLOADS_LINE" -lt "$INDEX_LINE"

# A product without a stored image must fall back to the placeholder, never to the
# homepage hero artwork, and stored media must be rendered through media_url().
grep -F -- 'product_image_url(product.imagePath)' "$CARD"
grep -F -- 'product_image_url(null)' "$PRODUCT"
grep -F -- 'media_url(image.path)' "$PRODUCT"
! grep -F -- "asset(image.path)" "$PRODUCT"
! grep -F -- 'storefront/images/hero-automotive.svg' "$CARD"
