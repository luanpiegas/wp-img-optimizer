# AGENTS.md

Single-file WordPress plugin (`wp_image_optimizer.php`, ~1000 lines). No build system, no `composer.json`/`package.json`, no tests, no CI, no lint config.

## Runtime requirements
- WordPress (this checkout lives inside a LocalWP site at `app/public/wp-content/plugins/`).
- PHP >= 7.4 with the **GD** extension. WebP generation additionally needs `imagewebp()` (GD compiled with WebP support).
- Async mode uses **WP-Cron** (`wp_schedule_single_event` on the `optimize_image_async` hook). If WP-Cron is disabled on the site, async optimization silently never runs.

## Internationalization (i18n)
- Source strings are **English**, wrapped in gettext calls (`__()`, `esc_html__()`, `esc_html_e()`, `esc_js__()`, `sprintf()`). Text domain: `image-optimizer`.
- `load_plugin_textdomain('image-optimizer', false, dirname(plugin_basename(__FILE__)) . '/languages/')` runs on the `plugins_loaded` hook. Translation `.mo` files must live in `languages/` and be named `image-optimizer-<locale>.mo` (e.g. `image-optimizer-pt_BR.mo`).
- Available locales: `pt_BR`, `es_ES`, `ja`, `fr_FR`, `ru_RU`, `de_DE`. The `.pot` template is `languages/image-optimizer.pot`.
- JS strings are **not** gettext-able inline; they are passed from PHP via `wp_localize_script` under `imgOptimizer.i18n` (see `enqueue_admin_scripts`). When adding JS user-facing strings, add them to that array and reference as `imgOptimizer.i18n.<key>` — do **not** hardcode translatable text in the `<script>` block.
- Code comments remain in **Portuguese (pt-BR)** by convention; do not translate them.
- Regenerating translation files: `xgettext` is available locally; `msgfmt` compiles `.po` → `.mo`. There is no WP-CLI/i18n toolchain configured — regenerate manually when strings change.

## Working in this repo
- All logic is in the `ImageOptimizerAdvanced` singleton class; the instance is created at the bottom of `wp_image_optimizer.php` via `get_instance()`.
- Do **not** invent commands — there is no `npm`, `composer`, `phpunit`, or `phpcs` workflow. Verify changes by activating the plugin in a WordPress admin and checking Settings → "Image Optimizer" (the menu label is itself translatable).
- Verify PHP syntax with `php -l wp_image_optimizer.php` (PHP 8.x CLI is available on this machine).

## Non-obvious behavior worth preserving
- **Dedup by hash**: optimized files are tracked by `md5_file()` in the `img_optimizer_processed` option (capped at 1000 entries). Re-running optimization on an unchanged file is a silent no-op; changing settings does **not** force re-optimization.
- **Bulk optimization AJAX flow**: `optimize_existing_images` builds a per-user queue in the `img_opt_batch_queue_<user_id>` transient. The `get_optimization_progress` action then **processes one image per call** (despite the name) and is polled by admin JS until `remaining` reaches 0. Don't rename it to something that sounds like a pure progress reporter without updating the JS.
- **Settings form is manual**: `options_page()` handles `$_POST['submit']` directly with `check_admin_referer('img_optimizer_settings-options')` and sanitizes inline (quality 1–100, dimensions 100–8000). Settings are also registered via `register_setting` but the sanitize-callback flow is not used — keep both in sync when adding fields.
- **Backups**: originals are copied to `<file>.backup` only when `img_optimizer_backup=1`; backups are never cleaned up automatically.
- **WebP serving** (requires `serve_webp=1` *and* `webp=1`): `template_redirect` starts an output buffer that rewrites `<img src>` URLs for files under `wp-content/uploads`, only when a matching `.webp` exists on disk and the browser sends `image/webp` in `Accept`. Skipped on admin/feed/robots/preview.

## State surface
- Options: `img_optimizer_quality`, `img_optimizer_enable_resize`, `img_optimizer_max_width`, `img_optimizer_max_height`, `img_optimizer_backup`, `img_optimizer_webp`, `img_optimizer_async`, `img_optimizer_thumbnails`, `img_optimizer_serve_webp`, `img_optimizer_stats`, `img_optimizer_logs` (last 100), `img_optimizer_processed` (last 1000 hashes).
- Transients: `img_opt_last_run_<user_id>` (2s rate limit per user), `img_opt_batch_queue_<user_id>` (bulk queue, 1h TTL).
- In-class caches: `$settings_cache`, `$stats_cache` — both nulled on save/reset.

## Security conventions
- AJAX actions use `check_ajax_referer('img_optimizer_ajax_nonce')` and require `manage_options`. The admin nonce is localized as `imgOptimizer.nonce` only on `settings_page_image-optimizer`. Preserve these when adding new AJAX endpoints.
