# CLAUDE.md — Radius Hotel Booking Pro

Pro add-on for the free plugin **Radius Hotel Booking** (sibling repo `../radius-hotel-booking`).

**The project is managed from the free repo.** Specs, roadmap and tracker, ADRs, conventions and
the `/next-module`, `/next-task` and `/module-status` commands all live there:

- `../radius-hotel-booking/docs/README.md`: start here
- `../radius-hotel-booking/docs/project/decisions.md`: ADR-014 (three plugins), ADR-015
  (extension points), ADR-016 (wordpress.org rules), ADR-017 (Pro feature switches)
- `../radius-hotel-booking/docs/modules/Mxx-*.md`: tasks tagged `[Pro]` are built here

Run the commands from the free repo. They switch into this repo for `[Pro]` tasks.

## Rules

- **Reuse the free plugin, never copy it.** PHP: extend its `Abstracts`, use its `Container`,
  `Schema`, router, `ApiResponse` and middleware. JS: import `@rtbp/ui` / `@rtbp/lib`
  (webpack externals to `window.rtbp`) and extend the app with the `rtbp.*` filters from
  `@wordpress/hooks`. If something shared is missing, add it to the **free** plugin first,
  as a seam.
- **Guard every free symbol** (`class_exists()` / `function_exists()`). A missing symbol must
  degrade gracefully, never fatal.
- **Every Pro feature is switchable** (ADR-017). A feature lives in
  `includes/Features/<Area>/<Name>Feature.php`, extends `Features\AbstractFeature`, and is listed in
  `Features\FeatureRegistry::definitions()` with its module, requirements and default. It hooks in
  **only** inside `boot()`, which runs only when it is switched on. Declare the free symbols it
  needs in `requires_free()`. Clean up cron in `on_disable()`, and never delete data there.
- `FeatureRegistry::definitions()` holds no translated strings: it is read before `init`. Labels
  go in `all()`.
- Check a feature from other Pro or client code with `rtbp_pro_feature_active( 'key' )`. The free
  plugin never checks Pro.
- Tables are registered through the free `rtbp_migration_classes` filter, so they exist even
  while a feature is off. Prefix Pro options and meta with `rtbp_pro_`.
- Text domain `radius-hotel-booking-pro`. Changelog in this repo's `readme.txt` (follow the global
  changelog rule).

## Commands

```bash
composer install          # autoloader (+ phpcs dev deps)
composer scope            # vendor-prefixed/: dompdf under RadiusTheme\RadiusHotelBookingPro\Vendor (ADR-005).
                          # Needs PHP 8.2–8.4 (PHP_BIN=/path/to/php8.4 composer scope); gitignored,
                          # must be built before zipping a release. Re-check the patchers in
                          # scoper/scoper.inc.php after updating dompdf.
npm install && npm run build
../radius-hotel-booking/vendor/bin/phpcs --standard=phpcs.xml radius-hotel-booking-pro.php includes
```

Local test site: `food-menu.local` has both plugins symlinked. The Pro tab is at
wp-admin → Radius Hotel Booking → Settings → Pro features.

## Layout

```
radius-hotel-booking-pro.php   bootstrap: constants, autoload, Plugin::instance()
includes/Plugin.php            boots on the free plugin's `rtbp_loaded`; dependency notice
includes/Features/             FeatureRegistry, FeatureManager, AbstractFeature, <Area>/<Name>Feature.php
includes/Settings/FeatureSettings.php   the `pro_features` settings section (option rtbp_pro_features_settings)
includes/Assets/Assets.php     enqueues build/admin.js on `rtbp_admin_enqueue_scripts`
src/admin/                     JS entry; registers the Settings → Pro features tab
```
