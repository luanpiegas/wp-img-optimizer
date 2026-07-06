# AGENTS.md

WordPress plugin (`wp_image_optimizer.php` entry point + `src/` classes). No build system, no `composer.json`/`package.json`, no tests, no CI, no lint config.

## Architecture
- `wp_image_optimizer.php` — composition root: instantiates dependencies, wires hooks, boots `ImageOptimizerPlugin::get_instance()`.
- `src/Settings.php` — option keys (class constants), defaults, sanitization, cached access. Single source of truth for config.
- `src/Stats.php` — optimized count / bytes saved / last reset, with cache.
- `src/Logger.php` — structured logs in `img_optimizer_logs` option (capped at 100), cleanup of 30-day-old entries.
- `src/Optimizer.php` — GD image processing: resize, compress, WebP, backup, dedup by hash, rate limiting, system checks. Dependencies: Settings, Stats, Logger (constructor-injected).
- `src/WebPServer.php` — `template_redirect` output buffer that rewrites `<img src>` to `.webp` for uploads when the file exists and the browser accepts WebP.
- `src/AdminPage.php` — menu, settings render (delegates to view), form POST handling, enqueue, AJAX endpoints (bulk queue + per-call processing).
- `src/views/settings-page.php` — settings page HTML/JS template. Required by `AdminPage::render()`; `$this` is `AdminPage`, with `$settings`, `$stats`, `$logs` in scope.
- No autoloader; the entry point `require_once`s each `src/*.php`. All classes live in the `ImageOptimizer` namespace and are `final`.

## Runtime requirements
- WordPress (this checkout lives inside a LocalWP site at `app/public/wp-content/plugins/`).
- PHP >= 7.4 with the **GD** extension. WebP generation additionally needs `imagewebp()` (GD compiled with WebP support).
- Async mode uses **WP-Cron** (`wp_schedule_single_event` on the `optimize_image_async` hook, see `Optimizer::ASYNC_HOOK`). If WP-Cron is disabled on the site, async optimization silently never runs.

## Internationalization (i18n)
- Source strings are **English**, wrapped in gettext calls (`__()`, `esc_html__()`, `esc_html_e()`, `esc_js__()`, `sprintf()`). Text domain: `image-optimizer`.
- `load_plugin_textdomain('image-optimizer', false, dirname(plugin_basename(__FILE__)) . '/languages/')` runs on the `plugins_loaded` hook. Translation `.mo` files must live in `languages/` and be named `image-optimizer-<locale>.mo` (e.g. `image-optimizer-pt_BR.mo`).
- Available locales: `pt_BR`, `es_ES`, `ja`, `fr_FR`, `ru_RU`, `de_DE`. The `.pot` template is `languages/image-optimizer.pot`.
- JS strings are **not** gettext-able inline; they are passed from PHP via `wp_localize_script` under `imgOptimizer.i18n` (see `AdminPage::js_strings()`). When adding JS user-facing strings, add them to that array and reference as `imgOptimizer.i18n.<key>` — do **not** hardcode translatable text in the `<script>` block. The `none_sentinel` key is used to compare the AJAX `processed_file` value (do not rename without updating the JS comparison).
- Code comments remain in **Portuguese (pt-BR)** by convention; do not translate them.
- Regenerating translation files: `xgettext` is available locally; `msgfmt` compiles `.po` → `.mo`. Regenerate with: `xgettext --language=PHP --from-code=UTF-8 --keyword=__ --keyword=_e --keyword=esc_html__ --keyword=esc_html_e --keyword=esc_js__ --keyword=sprintf:1 -o languages/image-optimizer.pot wp_image_optimizer.php src/*.php src/views/*.php`. There is no WP-CLI/i18n toolchain.

## Working in this repo
- Do **not** invent commands — there is no `npm`, `composer`, `phpunit`, or `phpcs` workflow. Verify changes by activating the plugin in a WordPress admin and checking Settings → "Image Optimizer" (the menu label is itself translatable).
- Verify PHP syntax with `php -l <file>` (PHP 8.x CLI is available). Check all changed files, not just the entry point.

## Non-obvious behavior worth preserving
- **Dedup by hash**: optimized files are tracked by `md5_file()` in `Optimizer::OPTION_PROCESSED` (capped at 1000 entries). Re-running optimization on an unchanged file is a silent no-op; changing settings does **not** force re-optimization.
- **Bulk optimization AJAX flow**: `optimize_existing_images` builds a per-user queue in `AdminPage::TRANSIENT_QUEUE<user_id>`. The `get_optimization_progress` action then **processes one image per call** (despite the name) and is polled by admin JS until `remaining` reaches 0. Don't rename the AJAX actions without updating the JS in `settings-page.php`.
- **Settings form is manual**: `AdminPage::handle_post()` handles `$_POST['submit']` directly with `check_admin_referer(AdminPage::NONCE_SETTINGS)` and delegates sanitization to `Settings::save_from_post()` (quality 1–100, dimensions 100–8000). Settings are also registered via `Settings::register()` but the Settings API sanitize-callback flow is not used — keep both in sync when adding fields.
- **Backups**: originals are copied to `<file>.backup` only when `img_optimizer_backup=1`; backups are never cleaned up automatically.
- **WebP serving** (requires `serve_webp=1` *and* `webp=1`): `template_redirect` starts an output buffer that rewrites `<img src>` URLs for files under `wp-content/uploads`, only when a matching `.webp` exists on disk and the browser sends `image/webp` in `Accept`. Skipped on admin/feed/robots/preview.

## State surface
- Options: `img_optimizer_quality`, `img_optimizer_enable_resize`, `img_optimizer_max_width`, `img_optimizer_max_height`, `img_optimizer_backup`, `img_optimizer_webp`, `img_optimizer_async`, `img_optimizer_thumbnails`, `img_optimizer_serve_webp`, `img_optimizer_stats`, `img_optimizer_logs` (last 100), `img_optimizer_processed` (last 1000 hashes).
- Transients: `img_opt_last_run_<user_id>` (2s rate limit per user), `img_opt_batch_queue_<user_id>` (bulk queue, 1h TTL).
- In-class caches: `Settings::$cache`, `Stats::$cache` — both nulled on save/reset.

## Security conventions
- AJAX actions use `check_ajax_referer(AdminPage::AJAX_NONCE)` and require `manage_options` (`AdminPage::CAPABILITY`). The admin nonce is localized as `imgOptimizer.nonce` only on `settings_page_image-optimizer`. Preserve these when adding new AJAX endpoints.
