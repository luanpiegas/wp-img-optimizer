<?php
/**
 * Admin page: menu, settings render, form handling, enqueue,
 * AJAX endpoints (bulk queue + progress).
 */

namespace ImageOptimizer;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminPage
{
    public const CAPABILITY     = 'manage_options';
    public const SLUG           = 'image-optimizer';
    public const AJAX_NONCE     = 'img_optimizer_ajax_nonce';
    public const NONCE_SETTINGS = 'img_optimizer_settings-options';
    public const NONCE_RESET    = 'img_optimizer_reset_stats';
    public const TRANSIENT_QUEUE = 'img_opt_batch_queue_';

    /** @var Settings */
    private $settings;

    /** @var Stats */
    private $stats;

    /** @var Logger */
    private $logger;

    /** @var Optimizer */
    private $optimizer;

    public function __construct(Settings $settings, Stats $stats, Logger $logger, Optimizer $optimizer)
    {
        $this->settings = $settings;
        $this->stats    = $stats;
        $this->logger   = $logger;
        $this->optimizer = $optimizer;
    }

    /**
     * Hook admin_menu: registra a página de opções.
     */
    public function add_menu(): void
    {
        add_options_page(
            __('Advanced Image Optimizer', 'image-optimizer'),
            __('Image Optimizer', 'image-optimizer'),
            self::CAPABILITY,
            self::SLUG,
            array($this, 'render')
        );
    }

    /**
     * Hook admin_enqueue_scripts: carrega CSS/JS apenas na página do plugin.
     */
    public function enqueue(string $hook): void
    {
        if ($hook !== 'settings_page_' . self::SLUG) {
            return;
        }

        wp_enqueue_script('jquery');
        wp_register_script('image-optimizer-admin', false);
        wp_enqueue_script('image-optimizer-admin');
        wp_localize_script('image-optimizer-admin', 'imgOptimizer', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce(self::AJAX_NONCE),
            'i18n'     => $this->js_strings(),
        ));

        wp_register_style('image-optimizer-admin', false);
        wp_enqueue_style('image-optimizer-admin');
        wp_add_inline_style('image-optimizer-admin', $this->inline_css());
    }

    /**
     * Renderiza a página de configurações.
     */
    public function render(): void
    {
        $this->handle_post();

        $settings = $this->settings->all();
        $stats    = $this->stats->get();
        $logs     = $this->logger->recent(10);

        require __DIR__ . '/views/settings-page.php';
    }

    /**
     * AJAX: monta a fila de imagens existentes para otimização em lote.
     */
    public function ajax_build_queue(): void
    {
        check_ajax_referer(self::AJAX_NONCE);
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(array('message' => __('Permission denied.', 'image-optimizer')));
        }

        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type IN ('image/jpeg','image/png','image/jpg')"
        );

        if (empty($ids)) {
            wp_send_json_success(array('total' => 0, 'message' => __('No images found.', 'image-optimizer')));
            return;
        }

        set_transient(self::TRANSIENT_QUEUE . get_current_user_id(), $ids, HOUR_IN_SECONDS);
        wp_send_json_success(array('total' => count($ids)));
    }

    /**
     * AJAX: processa uma imagem da fila por chamada (polling).
     */
    public function ajax_process_one(): void
    {
        check_ajax_referer(self::AJAX_NONCE);
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(array('message' => __('Permission denied.', 'image-optimizer')));
        }

        $key   = self::TRANSIENT_QUEUE . get_current_user_id();
        $queue = get_transient($key);

        if ($queue === false || empty($queue)) {
            delete_transient($key);
            wp_send_json_success(array('remaining' => 0, 'processed_file' => __('None', 'image-optimizer')));
            return;
        }

        $image_id = array_shift($queue);
        set_transient($key, $queue, HOUR_IN_SECONDS);

        $file_path = get_attached_file($image_id);
        if (!$file_path || !file_exists($file_path)) {
            wp_send_json_success(array(
                'remaining'      => count($queue),
                'processed_file' => sprintf(__('File not found for ID: %d', 'image-optimizer'), $image_id),
            ));
            return;
        }

        $mime = (string) get_post_mime_type($image_id);
        $this->optimizer->optimize($file_path, $mime);
        wp_update_attachment_metadata($image_id, wp_generate_attachment_metadata($image_id, $file_path));

        wp_send_json_success(array(
            'remaining'      => count($queue),
            'processed_file' => basename($file_path),
        ));
    }

    /**
     * Processa o POST do formulário de configurações e do reset de stats.
     */
    private function handle_post(): void
    {
        if (isset($_POST['submit'])) {
            check_admin_referer(self::NONCE_SETTINGS);
            $this->settings->save_from_post($_POST);
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved successfully!', 'image-optimizer') . '</p></div>';
        }

        if (isset($_POST['reset_stats'])) {
            check_admin_referer(self::NONCE_RESET);
            $this->stats->reset();
            echo '<div class="notice notice-info"><p>' . esc_html__('Statistics have been reset!', 'image-optimizer') . '</p></div>';
        }
    }

    /**
     * Strings JS passadas via wp_localize_script (não hardcoded no <script>).
     *
     * @return array<string,string>
     */
    private function js_strings(): array
    {
        return array(
            'in_progress'       => __('Optimization is already in progress!', 'image-optimizer'),
            'optimizing'        => __('Optimizing...', 'image-optimizer'),
            'starting'          => __('Starting...', 'image-optimizer'),
            'found_images'      => __('Found %d images to optimize.', 'image-optimizer'),
            'no_images'         => __('No images found to optimize.', 'image-optimizer'),
            'start_error'       => __('Error starting optimization. Check the browser console.', 'image-optimizer'),
            'batch_done'        => __('Batch optimization completed successfully!', 'image-optimizer'),
            'batch_done_errors' => __('Batch optimization completed with errors.', 'image-optimizer'),
            'comm_error'        => __('A communication error occurred with the server. Optimization was interrupted.', 'image-optimizer'),
            'start_button'      => __('Start Bulk Optimization', 'image-optimizer'),
            'optimized_label'   => __('Optimized:', 'image-optimizer'),
            'remaining_label'   => __('Remaining:', 'image-optimizer'),
            'optimize_error'    => __('Error optimizing:', 'image-optimizer'),
            'unknown_error'     => __('Unknown error.', 'image-optimizer'),
            'none_sentinel'     => __('None', 'image-optimizer'),
        );
    }

    /**
     * CSS inline para a página de configurações.
     */
    private function inline_css(): string
    {
        return <<<'CSS'
.imgopt-toggle { position: relative; display: inline-block; width: 42px; height: 24px; vertical-align: middle; margin-right: 8px; }
.imgopt-toggle input { opacity: 0; width: 0; height: 0; }
.imgopt-toggle .imgopt-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; border-radius: 24px; transition: 0.2s; }
.imgopt-toggle .imgopt-slider:before { content: ""; position: absolute; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: #fff; border-radius: 50%; transition: 0.2s; }
.imgopt-toggle input:checked + .imgopt-slider { background-color: #2271b1; }
.imgopt-toggle input:checked + .imgopt-slider:before { transform: translateX(18px); }
.imgopt-toggle input:disabled + .imgopt-slider { opacity: 0.4; cursor: not-allowed; }
.imgopt-toggle-label { vertical-align: middle; }
.imgopt-status-list { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 12px 0; }
.imgopt-status-item { display: flex; align-items: center; padding: 10px 14px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; }
.imgopt-status-dot { width: 10px; height: 10px; border-radius: 50%; margin-right: 10px; flex-shrink: 0; }
.imgopt-status-dot.ok { background: #46b450; }
.imgopt-status-dot.fail { background: #dc3232; }
.imgopt-status-text { font-weight: 600; }
.imgopt-stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin: 16px 0; }
.imgopt-stat { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 16px; text-align: center; }
.imgopt-stat-value { font-size: 24px; font-weight: 700; color: #2271b1; line-height: 1.2; }
.imgopt-stat-label { color: #646970; margin-top: 4px; font-size: 13px; }
.imgopt-log-entry { margin-bottom: 6px; padding: 8px 12px; border-left: 3px solid #ccc; background: #fff; }
.imgopt-log-entry.error { border-left-color: #dc3545; }
.imgopt-log-entry.warning { border-left-color: #ffc107; }
.imgopt-log-entry.success, .imgopt-log-entry.info { border-left-color: #28a745; }
.imgopt-log-meta { color: #666; font-size: 12px; }
.imgopt-log-level { text-transform: uppercase; font-weight: 700; font-size: 11px; margin: 0 4px; }
.imgopt-log-level.error { color: #dc3545; }
.imgopt-log-level.warning { color: #856404; }
.imgopt-log-level.success, .imgopt-log-level.info { color: #155724; }
.imgopt-progress-bar { width: 100%; height: 24px; border-radius: 4px; overflow: hidden; background: #f0f0f1; }
.imgopt-progress-bar-fill { height: 100%; background: #2271b1; transition: width 0.3s ease; width: 0%; }
.imgopt-disabled-row { opacity: 0.55; }
@media screen and (max-width: 782px) {
    .imgopt-stats-grid, .imgopt-status-list { grid-template-columns: 1fr; }
}
CSS;
    }
}
