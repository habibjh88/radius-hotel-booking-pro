#!/usr/bin/env bash
# Build vendor-prefixed/: dompdf and its libraries under the
# RadiusTheme\RadiusHotelBookingPro\Vendor namespace (ADR-005).
# PHP-Scoper needs PHP 8.2–8.4 (on 8.5 it reports success but prefixes nothing);
# point PHP_BIN at one if the default `php` is newer: PHP_BIN=/path/to/php8.4 composer scope
set -euo pipefail
cd "$(dirname "$0")/.."
PHP_BIN="${PHP_BIN:-php}"

composer install --working-dir=scoper --no-dev --no-interaction --quiet
composer install --working-dir=scoper/tools --no-interaction --quiet
rm -rf vendor-prefixed
"$PHP_BIN" -d xdebug.mode=off scoper/tools/vendor/bin/php-scoper add-prefix \
	--config=scoper/scoper.inc.php --output-dir=vendor-prefixed --force --no-interaction --quiet
composer dump-autoload --working-dir=vendor-prefixed --classmap-authoritative --no-interaction --quiet

# PHP-Scoper can "succeed" without prefixing; refuse to ship that.
if ! grep -q '^namespace RadiusTheme\\RadiusHotelBookingPro\\Vendor\\Dompdf;' vendor-prefixed/vendor/dompdf/dompdf/src/Dompdf.php; then
	echo "Scoping failed: dompdf is not prefixed. Run with PHP 8.2–8.4 (PHP_BIN=...)." >&2
	rm -rf vendor-prefixed
	exit 1
fi
echo "vendor-prefixed/ built."
