<?php
/**
 * View: página de configurações do Image Optimizer.
 *
 * Variáveis disponíveis (escopo do require em AdminPage::render):
 * @var array<string,mixed> $settings
 * @var array<string,int>   $stats
 * @var array<int,array<string,mixed>> $logs
 * @var \ImageOptimizer\Optimizer $optimizer  (via $this->optimizer no AdminPage)
 *
 * Nota: $this aponta para AdminPage quando render() faz require.
 */
use ImageOptimizer\AdminPage;
use ImageOptimizer\Optimizer;

if (!defined('ABSPATH')) {
    exit;
}

/** @var AdminPage $this */
$optimizer = $this->optimizer;
?>
<div class="wrap">
    <h1><?php esc_html_e('Advanced Image Optimizer', 'image-optimizer'); ?></h1>

    <div class="imgopt-stats-grid">
        <div class="imgopt-stat">
            <div class="imgopt-stat-value"><?php echo number_format($stats['total_optimized']); ?></div>
            <div class="imgopt-stat-label"><?php esc_html_e('Images optimized', 'image-optimizer'); ?></div>
        </div>
        <div class="imgopt-stat">
            <div class="imgopt-stat-value"><?php echo esc_html($optimizer->format_bytes($stats['bytes_saved'])); ?></div>
            <div class="imgopt-stat-label"><?php esc_html_e('Space saved', 'image-optimizer'); ?></div>
        </div>
        <div class="imgopt-stat">
            <div class="imgopt-stat-value"><?php echo esc_html(date_i18n(get_option('date_format') . ' H:i', $stats['last_reset'])); ?></div>
            <div class="imgopt-stat-label"><?php esc_html_e('Last reset', 'image-optimizer'); ?></div>
        </div>
    </div>

    <form method="post" style="display: inline;">
        <?php wp_nonce_field(AdminPage::NONCE_RESET); ?>
        <button type="submit" name="reset_stats" class="button" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to reset the statistics?', 'image-optimizer')); ?>')">
            <?php esc_html_e('Reset Statistics', 'image-optimizer'); ?>
        </button>
    </form>

    <h2 class="title"><?php esc_html_e('Server Status', 'image-optimizer'); ?></h2>
    <div class="imgopt-status-list">
        <div class="imgopt-status-item">
            <span class="imgopt-status-dot <?php echo extension_loaded('gd') ? 'ok' : 'fail'; ?>"></span>
            <span class="imgopt-status-text"><?php esc_html_e('GD Extension', 'image-optimizer'); ?></span>
        </div>
        <div class="imgopt-status-item">
            <span class="imgopt-status-dot <?php echo function_exists('imagewebp') ? 'ok' : 'fail'; ?>"></span>
            <span class="imgopt-status-text"><?php esc_html_e('WebP Support', 'image-optimizer'); ?></span>
        </div>
    </div>

    <form method="post" action="">
        <?php wp_nonce_field(AdminPage::NONCE_SETTINGS); ?>

        <h2 class="title"><?php esc_html_e('Compression', 'image-optimizer'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="img_optimizer_quality"><?php esc_html_e('Quality (%)', 'image-optimizer'); ?></label></th>
                <td>
                    <input type="number" id="img_optimizer_quality" name="img_optimizer_quality" value="<?php echo esc_attr($settings['quality']); ?>" min="1" max="100" />
                    <p class="description"><?php esc_html_e('Compression quality (1-100). Lower value = higher compression.', 'image-optimizer'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Enable Resizing', 'image-optimizer'); ?></th>
                <td>
                    <label class="imgopt-toggle">
                        <input type="checkbox" id="img_optimizer_enable_resize" name="img_optimizer_enable_resize" value="1" <?php checked('1', $settings['enable_resize']); ?> />
                        <span class="imgopt-slider"></span>
                    </label>
                    <span class="imgopt-toggle-label"><?php esc_html_e('Resize images automatically', 'image-optimizer'); ?></span>
                    <p class="description"><?php esc_html_e('If checked, images will be resized according to the maximum dimensions.', 'image-optimizer'); ?></p>
                </td>
            </tr>
            <tr id="row-max-width">
                <th scope="row"><label for="max-width-field"><?php esc_html_e('Maximum Width (px)', 'image-optimizer'); ?></label></th>
                <td>
                    <input type="number" id="max-width-field" name="img_optimizer_max_width" value="<?php echo esc_attr($settings['max_width']); ?>" min="100" max="8000" />
                    <p class="description"><?php esc_html_e('Maximum width in pixels (100-8000).', 'image-optimizer'); ?></p>
                </td>
            </tr>
            <tr id="row-max-height">
                <th scope="row"><label for="max-height-field"><?php esc_html_e('Maximum Height (px)', 'image-optimizer'); ?></label></th>
                <td>
                    <input type="number" id="max-height-field" name="img_optimizer_max_height" value="<?php echo esc_attr($settings['max_height']); ?>" min="100" max="8000" />
                    <p class="description"><?php esc_html_e('Maximum height in pixels (100-8000).', 'image-optimizer'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Create Backup', 'image-optimizer'); ?></th>
                <td>
                    <label class="imgopt-toggle">
                        <input type="checkbox" id="img_optimizer_backup" name="img_optimizer_backup" value="1" <?php checked('1', $settings['backup']); ?> />
                        <span class="imgopt-slider"></span>
                    </label>
                    <span class="imgopt-toggle-label"><?php esc_html_e('Create backup of original images', 'image-optimizer'); ?></span>
                    <p class="description"><?php esc_html_e('Saves a copy of the original image before optimization.', 'image-optimizer'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Optimize Thumbnails', 'image-optimizer'); ?></th>
                <td>
                    <label class="imgopt-toggle">
                        <input type="checkbox" id="img_optimizer_thumbnails" name="img_optimizer_thumbnails" value="1" <?php checked('1', $settings['thumbnails']); ?> />
                        <span class="imgopt-slider"></span>
                    </label>
                    <span class="imgopt-toggle-label"><?php esc_html_e('Optimize thumbnails generated by WordPress', 'image-optimizer'); ?></span>
                    <p class="description"><?php esc_html_e('Applies optimization to automatically created thumbnails as well.', 'image-optimizer'); ?></p>
                </td>
            </tr>
        </table>

        <h2 class="title"><?php esc_html_e('WebP', 'image-optimizer'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Generate WebP', 'image-optimizer'); ?></th>
                <td>
                    <label class="imgopt-toggle">
                        <input type="checkbox" id="img_optimizer_webp" name="img_optimizer_webp" value="1" <?php checked('1', $settings['webp']); ?> <?php if (!function_exists('imagewebp')) echo 'disabled'; ?> />
                        <span class="imgopt-slider"></span>
                    </label>
                    <span class="imgopt-toggle-label"><?php esc_html_e('Generate WebP versions of images', 'image-optimizer'); ?></span>
                    <?php if (!function_exists('imagewebp')): ?>
                        <p class="description imgopt-error"><?php esc_html_e('imagewebp() function not available on the server.', 'image-optimizer'); ?></p>
                    <?php else: ?>
                        <p class="description"><?php esc_html_e('Creates optimized WebP versions for modern browsers.', 'image-optimizer'); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr id="row-serve-webp">
                <th scope="row"><?php esc_html_e('Serve WebP Images', 'image-optimizer'); ?></th>
                <td>
                    <label class="imgopt-toggle">
                        <input type="checkbox" id="img_optimizer_serve_webp" name="img_optimizer_serve_webp" value="1" <?php checked('1', $settings['serve_webp']); ?> />
                        <span class="imgopt-slider"></span>
                    </label>
                    <span class="imgopt-toggle-label"><?php esc_html_e('Serve WebP images automatically', 'image-optimizer'); ?></span>
                    <p class="description"><?php esc_html_e('If enabled, the plugin will modify the page HTML to serve .webp versions of images to compatible browsers. Requires the "Generate WebP" option to be active.', 'image-optimizer'); ?></p>
                </td>
            </tr>
        </table>

        <h2 class="title"><?php esc_html_e('Advanced', 'image-optimizer'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Asynchronous Processing', 'image-optimizer'); ?></th>
                <td>
                    <label class="imgopt-toggle">
                        <input type="checkbox" id="img_optimizer_async" name="img_optimizer_async" value="1" <?php checked('1', $settings['async']); ?> />
                        <span class="imgopt-slider"></span>
                    </label>
                    <span class="imgopt-toggle-label"><?php esc_html_e('Process images in the background', 'image-optimizer'); ?></span>
                    <p class="description"><?php esc_html_e('Optimizes images using WP Cron to avoid blocking uploads.', 'image-optimizer'); ?></p>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Save Settings', 'image-optimizer')); ?>
    </form>

    <hr>

    <div class="card">
        <h2><?php esc_html_e('Optimize Existing Images', 'image-optimizer'); ?></h2>
        <p><?php esc_html_e('Click the button below to optimize all images already existing in the media library.', 'image-optimizer'); ?></p>
        <button type="button" class="button button-primary" id="optimize-existing">
            <?php esc_html_e('Start Bulk Optimization', 'image-optimizer'); ?>
        </button>
        <div id="optimization-progress" style="display:none; margin-top: 15px;">
            <p><?php esc_html_e('Optimizing images...', 'image-optimizer'); ?> <span id="progress-text">0%</span></p>
            <div class="imgopt-progress-bar"><div id="progress-bar-fill" class="imgopt-progress-bar-fill"></div></div>
            <p id="current-file"></p>
        </div>
    </div>

    <?php if (!empty($logs)): ?>
        <div class="card" style="margin-top: 20px;">
            <h2><?php esc_html_e('Recent Logs', 'image-optimizer'); ?></h2>
            <div style="max-height: 300px; overflow-y: auto; background: #f5f5f5; padding: 10px; border-radius: 4px;">
                <?php foreach (array_reverse($logs) as $log): ?>
                    <div class="imgopt-log-entry <?php echo esc_attr($log['level']); ?>">
                        <span class="imgopt-log-meta"><?php echo esc_html($log['timestamp']); ?></span>
                        <span class="imgopt-log-level <?php echo esc_attr($log['level']); ?>">[<?php echo esc_html($log['level']); ?>]</span>
                        <?php echo esc_html($log['message']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

</div>
