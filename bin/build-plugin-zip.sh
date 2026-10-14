#!/bin/bash
#
# Build a distributable plugin zip into dist/.
#
#   bun run package
#
# Installs production dependencies, builds the webpack bundles, prefixes
# dompdf into vendor-prefixed/ (bin/scope.sh), and archives only the files the
# plugin needs at runtime — never node_modules, scoper tooling or build configs.

set -e

cd "$(dirname "$0")"
cd ..

SLUG="radius-hotel-booking-pro"

BLUE_BOLD='\033[1;34m'
GREEN_BOLD='\033[1;32m'
COLOR_RESET='\033[0m'

status () { echo -e "\n${BLUE_BOLD}$1${COLOR_RESET}\n"; }
success () { echo -e "\n${GREEN_BOLD}$1${COLOR_RESET}\n"; }

# PHP-Scoper needs PHP 8.2–8.4. Use Homebrew's php@8.4 when PHP_BIN is not set
# and it is installed; scope.sh refuses to ship an unprefixed build either way.
if [ -z "$PHP_BIN" ] && [ -x /opt/homebrew/opt/php@8.4/bin/php ]; then
	export PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php
fi

status "Installing dependencies…"
bun install --frozen-lockfile
composer install --no-dev --optimize-autoloader

status "Building assets…"
bun run build

status "Prefixing vendor libraries (dompdf)…"
bash ./bin/scope.sh

# Source maps are dev-only.
find build -name "*.map" -type f -delete

status "Creating archive…"
# Stage and zip inside dist/ (git-ignored) so the plugin root stays clean.
DIST="dist"
mkdir -p "$DIST"
rm -rf "$DIST/$SLUG" "$DIST/$SLUG.zip"
STAGE="$DIST/$SLUG"
mkdir "$STAGE"

cp "$SLUG.php" index.php readme.txt composer.json "$STAGE/"
cp -r includes languages templates build vendor vendor-prefixed "$STAGE/"

# No hidden files (.DS_Store, .gitkeep …).
find "$STAGE" -name '.*' -not -name '.' -exec rm -rf {} + 2>/dev/null || true

# Languages: ship only the template (.pot) (ADR-019).
find "$STAGE/languages" -type f ! -name '*.pot' ! -name 'index.php' -delete 2>/dev/null || true

# Zip from inside dist/ so the archive root is "$SLUG/", as WordPress expects.
( cd "$DIST" && zip -rq "$SLUG.zip" "$SLUG/" )
rm -rf "$STAGE"

success "Built $DIST/$SLUG.zip"
