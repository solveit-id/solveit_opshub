# ADR-0004: Composer vendor recovery

**Status:** resolved

## Evidence

The first scaffold was created in `.opshub-scaffold` and moved to the root before the Composer installation had produced a complete vendor tree. `vendor/composer/installed.php` listed `inertiajs/inertia-laravel`, while `vendor/inertiajs/inertia-laravel/helpers.php` was absent. `php artisan about` failed from `autoload_real.php` on that missing file.

`composer.json` was valid, `composer.lock` was compatible with PHP 8.5.7, `vendor-dir` resolved to the project `vendor/`, and filesystem ACLs allowed modification. The minimal repair removed only the demonstrably partial `vendor/` directory and ran `composer install --no-scripts --no-autoloader` from the existing lockfile. The missing package file then existed.

This environment does not finish optimized autoload generation within the command-process window. A non-optimized development autoloader was generated successfully after temporarily setting `optimize-autoloader=false`. Production installs must run Composer's normal optimized autoload step in their own deployment environment; this is not treated as a package/platform error.
