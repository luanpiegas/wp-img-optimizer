<?php
/**
 * Plugin Name: Advanced Image Optimizer
 * Plugin URI: https://seusite.com
 * Description: Professional PNG, JPG and WebP image optimizer with advanced features
 * Version: 2.1.0
 * Author: Seu Nome
 * License: GPL v2 or later
 * Text Domain: image-optimizer
 * Domain Path: /languages
 * Requires PHP: 7.4
 */

// Previne acesso direto
if (!defined('ABSPATH')) {
    exit;
}

class ImageOptimizerAdvanced
{

    private static $instance = null;
    private $settings_cache = null;
    private $stats_cache = null;

    /**
     * Singleton pattern
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->init_hooks();
        $this->create_default_options();
    }

    /**
     * Inicializa todos os hooks
     */
    private function init_hooks()
    {
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('wp_handle_upload', array($this, 'optimize_uploaded_image'), 10, 2);
        add_filter('wp_generate_attachment_metadata', array($this, 'optimize_thumbnails'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        add_action('wp_ajax_optimize_existing_images', array($this, 'optimize_existing_images'));
        add_action('wp_ajax_get_optimization_progress', array($this, 'get_optimization_progress'));
        add_action('optimize_image_async', array($this, 'process_image_async'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Hook para limpeza de logs antigos
        add_action('wp_scheduled_delete', array($this, 'cleanup_old_logs'));

        $settings = $this->get_settings();
        if ($settings['serve_webp'] === '1' && $settings['webp'] === '1') {
            add_action('template_redirect', array($this, 'start_html_buffer'), 1);
        }
    }

    /**
     * Carrega o domínio de tradução do plugin
     */
    public function load_textdomain()
    {
        load_plugin_textdomain(
            'image-optimizer',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }

    /**
     * Enfileira scripts do admin
     */
    public function enqueue_admin_scripts($hook)
    {
        if ($hook === 'settings_page_image-optimizer') {
            wp_enqueue_script('jquery');
            wp_register_script('image-optimizer-admin', false);
            wp_enqueue_script('image-optimizer-admin');
            wp_localize_script('image-optimizer-admin', 'imgOptimizer', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('img_optimizer_ajax_nonce'),
                'i18n'     => array(
                    'in_progress'       => __('Optimization is already in progress!', 'image-optimizer'),
                    'optimizing'        => __('Optimizing...', 'image-optimizer'),
                    'starting'          => __('Starting...', 'image-optimizer'),
                    'found_images'      => __('Found %d images to optimize.', 'image-optimizer'),
                    'no_images'         => __('No images found to optimize.', 'image-optimizer'),
                    'start_error'       => __('Error starting optimization. Check the browser console.', 'image-optimizer'),
                    'batch_done'        => __('Batch optimization completed successfully!', 'image-optimizer'),
                    'batch_done_errors' => __('Batch optimization completed with errors.', 'image-optimizer'),
                    'comm_error'        => __('A communication error occurred with the server. Optimization was interrupted.', 'image-optimizer'),
                    'start_button'      => __('🚀 Start Bulk Optimization', 'image-optimizer'),
                    'optimized_label'   => __('Optimized:', 'image-optimizer'),
                    'remaining_label'   => __('Remaining:', 'image-optimizer'),
                    'optimize_error'    => __('Error optimizing:', 'image-optimizer'),
                    'unknown_error'     => __('Unknown error.', 'image-optimizer'),
                ),
            ));
        }
    }

    /**
     * Cria opções padrão se não existirem
     */
    private function create_default_options()
    {
        $defaults = array(
            'img_optimizer_quality' => 85,
            'img_optimizer_enable_resize' => '1',
            'img_optimizer_max_width' => 1920,
            'img_optimizer_max_height' => 1080,
            'img_optimizer_backup' => '0',
            'img_optimizer_webp' => '1',
            'img_optimizer_async' => '0',
            'img_optimizer_thumbnails' => '1',
            'img_optimizer_stats' => array(
                'total_optimized' => 0,
                'bytes_saved' => 0,
                'last_reset' => time()
            ),
            'img_optimizer_serve_webp' => '0', // Desativado por padrão
        );

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }

    /**
     * Obtém configurações com cache
     */
    private function get_settings()
    {
        if ($this->settings_cache === null) {
            $this->settings_cache = array(
                'quality' => intval(get_option('img_optimizer_quality', 85)),
                'enable_resize' => get_option('img_optimizer_enable_resize', '1'),
                'max_width' => intval(get_option('img_optimizer_max_width', 1920)),
                'max_height' => intval(get_option('img_optimizer_max_height', 1080)),
                'backup' => get_option('img_optimizer_backup', '0'),
                'webp' => get_option('img_optimizer_webp', '1'),
                'async' => get_option('img_optimizer_async', '0'),
                'thumbnails' => get_option('img_optimizer_thumbnails', '1'),
                'serve_webp' => get_option('img_optimizer_serve_webp', '0'),
            );
        }
        return $this->settings_cache;
    }

    /**
     * Obtém estatísticas com cache
     */
    private function get_stats()
    {
        if ($this->stats_cache === null) {
            $this->stats_cache = get_option('img_optimizer_stats', array(
                'total_optimized' => 0,
                'bytes_saved' => 0,
                'last_reset' => time()
            ));
        }
        return $this->stats_cache;
    }

    /**
     * Atualiza estatísticas
     */
    private function update_stats($bytes_saved)
    {
        $stats = $this->get_stats();
        $stats['total_optimized']++;
        $stats['bytes_saved'] += $bytes_saved;

        update_option('img_optimizer_stats', $stats);
        $this->stats_cache = $stats;
    }

    /**
     * Verifica se sistema está pronto para otimização
     */
    private function check_system_requirements()
    {
        if (!extension_loaded('gd')) {
            $this->log_message(__('GD extension not found', 'image-optimizer'), 'error');
            return false;
        }

        $memory_limit = ini_get('memory_limit');
        $memory_bytes = $this->convert_to_bytes($memory_limit);

        if ($memory_bytes < 128 * 1024 * 1024) { // 128MB mínimo
            $this->log_message(sprintf(__('Insufficient memory: %s', 'image-optimizer'), $memory_limit), 'warning');
        }

        return true;
    }

    /**
     * Converte string de memória para bytes
     */
    private function convert_to_bytes($val)
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $val = intval($val);

        switch ($last) {
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }

        return $val;
    }

    /**
     * Rate limiting para evitar sobrecarga
     */
    private function check_rate_limit()
    {
        $last_optimization = get_transient('img_opt_last_run_' . get_current_user_id());
        if ($last_optimization && (time() - $last_optimization) < 2) {
            return false;
        }

        set_transient('img_opt_last_run_' . get_current_user_id(), time(), 10);
        return true;
    }

    /**
     * Otimiza a imagem após o upload
     */
    public function optimize_uploaded_image($upload, $context)
    {
        if (!isset($upload['file']) || !isset($upload['type'])) {
            return $upload;
        }

        // Verificações de sistema
        if (!$this->check_system_requirements()) {
            return $upload;
        }

        if (!$this->check_rate_limit()) {
            $this->log_message(sprintf(__('Rate limit reached for user %d', 'image-optimizer'), get_current_user_id()), 'warning');
            return $upload;
        }

        $file_path = $upload['file'];
        $mime_type = $upload['type'];

        // Verifica se é uma imagem suportada
        if (!in_array($mime_type, array('image/jpeg', 'image/jpg', 'image/png'))) {
            return $upload;
        }

        // Validação rigorosa do arquivo
        $image_info = getimagesize($file_path);
        if ($image_info === false) {
            $this->log_message(sprintf(__('Invalid file: %s', 'image-optimizer'), $file_path), 'error');
            return $upload;
        }

        $settings = $this->get_settings();

        // Processamento assíncrono se habilitado
        if ($settings['async'] === '1') {
            wp_schedule_single_event(time() + 5, 'optimize_image_async', array($file_path, $mime_type));
        } else {
            $this->optimize_image($file_path, $mime_type);
        }

        return $upload;
    }

    /**
     * Otimiza thumbnails gerados pelo WordPress
     */
    public function optimize_thumbnails($metadata)
    {
        $settings = $this->get_settings();

        if ($settings['thumbnails'] !== '1') {
            return $metadata;
        }

        if (!isset($metadata['sizes'])) {
            return $metadata;
        }

        $upload_dir = wp_upload_dir();
        $base_dir = dirname($metadata['file']);

        foreach ($metadata['sizes'] as $size => $size_data) {
            $thumbnail_path = $upload_dir['basedir'] . '/' . $base_dir . '/' . $size_data['file'];

            if (file_exists($thumbnail_path)) {
                $mime_type = 'image/' . ($size_data['mime-type'] === 'image/jpg' ? 'jpeg' : str_replace('image/', '', $size_data['mime-type']));
                $this->optimize_image($thumbnail_path, $mime_type, false); // Sem log para thumbnails
            }
        }

        return $metadata;
    }

    /**
     * Processamento assíncrono de imagem
     */
    public function process_image_async($file_path, $mime_type)
    {
        if (file_exists($file_path)) {
            $this->optimize_image($file_path, $mime_type);
        }
    }

    /**
     * Função principal de otimização
     */
    private function optimize_image($file_path, $mime_type, $log = true)
    {
        if (!file_exists($file_path)) {
            return false;
        }

        // Verifica se já foi otimizada
        $file_hash = md5_file($file_path);
        $optimized_files = get_option('img_optimizer_processed', array());

        if (in_array($file_hash, $optimized_files)) {
            return false; // Já foi otimizada
        }

        $settings = $this->get_settings();
        $original_size = filesize($file_path);

        // Aumenta limite de memória temporariamente
        $old_limit = ini_get('memory_limit');
        if ($this->convert_to_bytes($old_limit) < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
        }

        // Cria backup se habilitado
        if ($settings['backup'] === '1') {
            $backup_path = $file_path . '.backup';
            if (!file_exists($backup_path)) {
                copy($file_path, $backup_path);
            }
        }

        // Carrega a imagem baseado no tipo
        switch ($mime_type) {
            case 'image/jpeg':
            case 'image/jpg':
                $image = imagecreatefromjpeg($file_path);
                break;
            case 'image/png':
                $image = imagecreatefrompng($file_path);
                break;
            default:
                ini_set('memory_limit', $old_limit);
                return false;
        }

        if (!$image) {
            ini_set('memory_limit', $old_limit);
            return false;
        }

        // Obtém dimensões originais
        $original_width = imagesx($image);
        $original_height = imagesy($image);

        // Calcula novas dimensões se necessário
        $new_dimensions = $this->calculate_dimensions(
            $original_width,
            $original_height,
            $settings['max_width'],
            $settings['max_height']
        );

        // Redimensiona se necessário e se a opção estiver habilitada
        $should_resize = ($settings['enable_resize'] === '1') &&
            ($new_dimensions['width'] != $original_width ||
                $new_dimensions['height'] != $original_height);

        if ($should_resize) {
            $new_image = imagecreatetruecolor($new_dimensions['width'], $new_dimensions['height']);

            // Preserva transparência para PNG
            if ($mime_type == 'image/png') {
                imagealphablending($new_image, false);
                imagesavealpha($new_image, true);
                $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
                imagefill($new_image, 0, 0, $transparent);
            }

            imagecopyresampled(
                $new_image,
                $image,
                0,
                0,
                0,
                0,
                $new_dimensions['width'],
                $new_dimensions['height'],
                $original_width,
                $original_height
            );

            imagedestroy($image);
            $image = $new_image;
        }

        // Salva a imagem otimizada
        switch ($mime_type) {
            case 'image/jpeg':
            case 'image/jpg':
                imagejpeg($image, $file_path, $settings['quality']);
                break;
            case 'image/png':
                // Para PNG, converte qualidade de 0-100 para 0-9
                $png_quality = round(9 - ($settings['quality'] / 100) * 9);
                imagepng($image, $file_path, $png_quality);
                break;
        }

        // Gera versão WebP se habilitado e suportado
        if ($settings['webp'] === '1' && function_exists('imagewebp')) {
            $webp_path = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $file_path);
            imagewebp($image, $webp_path, $settings['quality']);
        }

        imagedestroy($image);

        // Restaura limite de memória
        ini_set('memory_limit', $old_limit);

        // Calcula economia e atualiza estatísticas
        clearstatcache();
        $new_size = filesize($file_path);
        $bytes_saved = $original_size - $new_size;

        if ($bytes_saved > 0) {
            $this->update_stats($bytes_saved);

            if ($log) {
                $reduction = round(($bytes_saved / $original_size) * 100, 2);
                $this->log_message(sprintf(__('Image optimized: %1$s - %2$s%% reduction (%3$s saved)', 'image-optimizer'), $file_path, $reduction, $this->format_bytes($bytes_saved)), 'success');
            }
        }

        // Marca como processada
        $optimized_files[] = $file_hash;
        update_option('img_optimizer_processed', array_slice($optimized_files, -1000)); // Mantém apenas as últimas 1000

        return true;
    }

    /**
     * Calcula novas dimensões mantendo proporção
     */
    private function calculate_dimensions($original_width, $original_height, $max_width, $max_height)
    {
        // Se a imagem já está dentro dos limites, não redimensiona
        if ($original_width <= $max_width && $original_height <= $max_height) {
            return array(
                'width' => $original_width,
                'height' => $original_height
            );
        }

        $ratio = $original_width / $original_height;

        if ($max_width / $max_height > $ratio) {
            $new_width = $max_height * $ratio;
            $new_height = $max_height;
        } else {
            $new_height = $max_width / $ratio;
            $new_width = $max_width;
        }

        return array(
            'width' => round($new_width),
            'height' => round($new_height)
        );
    }

    /**
     * Sistema de logs
     */
    private function log_message($message, $level = 'info')
    {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'message' => $message,
            'user_id' => get_current_user_id()
        );

        $logs = get_option('img_optimizer_logs', array());
        $logs[] = $log_entry;

        // Mantém apenas os últimos 100 logs
        $logs = array_slice($logs, -100);

        update_option('img_optimizer_logs', $logs);

        // Log no error_log do WordPress se necessário
        if ($level === 'error') {
            error_log('Image Optimizer: ' . $message);
        }
    }

    /**
     * Formata bytes para exibição
     */
    private function format_bytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Limpeza de logs antigos
     */
    public function cleanup_old_logs()
    {
        $logs = get_option('img_optimizer_logs', array());
        $cutoff_time = strtotime('-30 days');

        $logs = array_filter($logs, function ($log) use ($cutoff_time) {
            return strtotime($log['timestamp']) > $cutoff_time;
        });

        update_option('img_optimizer_logs', array_values($logs));
    }

    /**
     * Adiciona menu de administração
     */
    public function add_admin_menu()
    {
        add_options_page(
            __('Advanced Image Optimizer', 'image-optimizer'),
            __('Image Optimizer', 'image-optimizer'),
            'manage_options',
            'image-optimizer',
            array($this, 'options_page')
        );
    }

    /**
     * Inicializa configurações
     */
    public function settings_init()
    {
        register_setting('img_optimizer_settings', 'img_optimizer_quality');
        register_setting('img_optimizer_settings', 'img_optimizer_enable_resize');
        register_setting('img_optimizer_settings', 'img_optimizer_max_width');
        register_setting('img_optimizer_settings', 'img_optimizer_max_height');
        register_setting('img_optimizer_settings', 'img_optimizer_backup');
        register_setting('img_optimizer_settings', 'img_optimizer_webp');
        register_setting('img_optimizer_settings', 'img_optimizer_async');
        register_setting('img_optimizer_settings', 'img_optimizer_thumbnails');
        register_setting('img_optimizer_settings', 'img_optimizer_serve_webp');
    }

    /**
     * Página de opções
     */
    public function options_page()
    {
        // Processa o formulário se foi submetido
        if (isset($_POST['submit'])) {
            check_admin_referer('img_optimizer_settings-options');

            // Sanitização rigorosa dos inputs
            $quality = absint($_POST['img_optimizer_quality']);
            $quality = max(1, min(100, $quality));

            $max_width = absint($_POST['img_optimizer_max_width']);
            $max_width = max(100, min(8000, $max_width));

            $max_height = absint($_POST['img_optimizer_max_height']);
            $max_height = max(100, min(8000, $max_height));

            update_option('img_optimizer_quality', $quality);
            update_option('img_optimizer_enable_resize', isset($_POST['img_optimizer_enable_resize']) ? '1' : '0');
            update_option('img_optimizer_max_width', $max_width);
            update_option('img_optimizer_max_height', $max_height);
            update_option('img_optimizer_backup', isset($_POST['img_optimizer_backup']) ? '1' : '0');
            update_option('img_optimizer_webp', isset($_POST['img_optimizer_webp']) ? '1' : '0');
            update_option('img_optimizer_async', isset($_POST['img_optimizer_async']) ? '1' : '0');
            update_option('img_optimizer_thumbnails', isset($_POST['img_optimizer_thumbnails']) ? '1' : '0');
            update_option('img_optimizer_serve_webp', isset($_POST['img_optimizer_serve_webp']) ? '1' : '0');

            // Limpa cache de configurações
            $this->settings_cache = null;

            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved successfully!', 'image-optimizer') . '</p></div>';
        }

        // Reset de estatísticas
        if (isset($_POST['reset_stats'])) {
            check_admin_referer('img_optimizer_reset_stats');

            update_option('img_optimizer_stats', array(
                'total_optimized' => 0,
                'bytes_saved' => 0,
                'last_reset' => time()
            ));

            $this->stats_cache = null;
            echo '<div class="notice notice-info"><p>' . esc_html__('Statistics have been reset!', 'image-optimizer') . '</p></div>';
        }

        // Obtém valores atuais
        $settings = $this->get_settings();
        $stats = $this->get_stats();
        $logs = array_slice(get_option('img_optimizer_logs', array()), -10); // Últimos 10 logs
?>
        <div class="wrap">
            <h1>🚀 <?php esc_html_e('Advanced Image Optimizer', 'image-optimizer'); ?></h1>

            <div class="card" style="margin-bottom: 20px;">
                <h2>📊 <?php esc_html_e('Statistics', 'image-optimizer'); ?></h2>
                <p><strong><?php esc_html_e('Total images optimized:', 'image-optimizer'); ?></strong> <?php echo number_format($stats['total_optimized']); ?></p>
                <p><strong><?php esc_html_e('Space saved:', 'image-optimizer'); ?></strong> <?php echo esc_html($this->format_bytes($stats['bytes_saved'])); ?></p>
                <p><strong><?php esc_html_e('Last update:', 'image-optimizer'); ?></strong> <?php echo esc_html(date('d/m/Y H:i:s', $stats['last_reset'])); ?></p>

                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('img_optimizer_reset_stats'); ?>
                    <button type="submit" name="reset_stats" class="button" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to reset the statistics?', 'image-optimizer')); ?>')">
                        <?php esc_html_e('Reset Statistics', 'image-optimizer'); ?>
                    </button>
                </form>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('img_optimizer_settings-options'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">🎛️ <?php esc_html_e('Quality (%)', 'image-optimizer'); ?></th>
                        <td>
                            <input type="number" name="img_optimizer_quality" value="<?php echo esc_attr($settings['quality']); ?>" min="1" max="100" />
                            <p class="description"><?php esc_html_e('Compression quality (1-100). Lower value = higher compression.', 'image-optimizer'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">📐 <?php esc_html_e('Enable Resizing', 'image-optimizer'); ?></th>
                        <td>
                            <label for="img_optimizer_enable_resize">
                                <input type="checkbox" id="img_optimizer_enable_resize" name="img_optimizer_enable_resize" value="1" <?php checked('1', $settings['enable_resize']); ?> />
                                <?php esc_html_e('Resize images automatically', 'image-optimizer'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('If checked, images will be resized according to the maximum dimensions.', 'image-optimizer'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">📏 <?php esc_html_e('Maximum Width (px)', 'image-optimizer'); ?></th>
                        <td>
                            <input type="number" id="max-width-field" name="img_optimizer_max_width" value="<?php echo esc_attr($settings['max_width']); ?>" min="100" max="8000" />
                            <p class="description"><?php esc_html_e('Maximum width in pixels (100-8000).', 'image-optimizer'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">📏 <?php esc_html_e('Maximum Height (px)', 'image-optimizer'); ?></th>
                        <td>
                            <input type="number" id="max-height-field" name="img_optimizer_max_height" value="<?php echo esc_attr($settings['max_height']); ?>" min="100" max="8000" />
                            <p class="description"><?php esc_html_e('Maximum height in pixels (100-8000).', 'image-optimizer'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">💾 <?php esc_html_e('Create Backup', 'image-optimizer'); ?></th>
                        <td>
                            <label for="img_optimizer_backup">
                                <input type="checkbox" id="img_optimizer_backup" name="img_optimizer_backup" value="1" <?php checked('1', $settings['backup']); ?> />
                                <?php esc_html_e('Create backup of original images', 'image-optimizer'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Saves a copy of the original image before optimization.', 'image-optimizer'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">🌐 <?php esc_html_e('Generate WebP', 'image-optimizer'); ?></th>
                        <td>
                            <label for="img_optimizer_webp">
                                <input type="checkbox" id="img_optimizer_webp" name="img_optimizer_webp" value="1" <?php checked('1', $settings['webp']); ?> <?php if (!function_exists('imagewebp')) echo 'disabled'; ?> />
                                <?php esc_html_e('Generate WebP versions of images', 'image-optimizer'); ?>
                            </label>
                            <?php if (!function_exists('imagewebp')): ?>
                                <p class="description" style="color: red;">⚠️ <?php esc_html_e('imagewebp() function not available on the server.', 'image-optimizer'); ?></p>
                            <?php else: ?>
                                <p class="description"><?php esc_html_e('Creates optimized WebP versions for modern browsers.', 'image-optimizer'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">🚀 <?php esc_html_e('Serve WebP Images', 'image-optimizer'); ?></th>
                        <td>
                            <label for="img_optimizer_serve_webp">
                                <input type="checkbox" id="img_optimizer_serve_webp" name="img_optimizer_serve_webp" value="1" <?php checked('1', $settings['serve_webp']); ?> />
                                <?php esc_html_e('Serve WebP images automatically', 'image-optimizer'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('If enabled, the plugin will modify the page HTML to serve .webp versions of images to compatible browsers. Requires the "Generate WebP" option to be active.', 'image-optimizer'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">⚡ <?php esc_html_e('Asynchronous Processing', 'image-optimizer'); ?></th>
                        <td>
                            <label for="img_optimizer_async">
                                <input type="checkbox" id="img_optimizer_async" name="img_optimizer_async" value="1" <?php checked('1', $settings['async']); ?> />
                                <?php esc_html_e('Process images in the background', 'image-optimizer'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Optimizes images using WP Cron to avoid blocking uploads.', 'image-optimizer'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">🖼️ <?php esc_html_e('Optimize Thumbnails', 'image-optimizer'); ?></th>
                        <td>
                            <label for="img_optimizer_thumbnails">
                                <input type="checkbox" id="img_optimizer_thumbnails" name="img_optimizer_thumbnails" value="1" <?php checked('1', $settings['thumbnails']); ?> />
                                <?php esc_html_e('Optimize thumbnails generated by WordPress', 'image-optimizer'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Applies optimization to automatically created thumbnails as well.', 'image-optimizer'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('💾 Save Settings', 'image-optimizer')); ?>
            </form>

            <hr>

            <div class="card">
                <h2>🔄 <?php esc_html_e('Optimize Existing Images', 'image-optimizer'); ?></h2>
                <p><?php esc_html_e('Click the button below to optimize all images already existing in the media library.', 'image-optimizer'); ?></p>
                <button type="button" class="button button-primary" id="optimize-existing">
                    🚀 <?php esc_html_e('Start Bulk Optimization', 'image-optimizer'); ?>
                </button>
                <div id="optimization-progress" style="display:none; margin-top: 15px;">
                    <p><?php esc_html_e('Optimizing images...', 'image-optimizer'); ?> <span id="progress-text">0%</span></p>
                    <progress id="progress-bar" max="100" value="0" style="width: 100%; height: 30px;"></progress>
                    <p id="current-file"></p>
                </div>
            </div>

            <?php if (!empty($logs)): ?>
                <div class="card" style="margin-top: 20px;">
                    <h2>📝 <?php esc_html_e('Recent Logs', 'image-optimizer'); ?></h2>
                    <div style="max-height: 300px; overflow-y: auto; background: #f5f5f5; padding: 10px; border-radius: 5px;">
                        <?php foreach (array_reverse($logs) as $log): ?>
                            <div style="margin-bottom: 5px; padding: 5px; border-left: 3px solid <?php echo $log['level'] === 'error' ? '#dc3545' : ($log['level'] === 'warning' ? '#ffc107' : '#28a745'); ?>; background: white;">
                                <small style="color: #666;"><?php echo esc_html($log['timestamp']); ?></small>
                                <span style="text-transform: uppercase; font-weight: bold; color: <?php echo $log['level'] === 'error' ? '#dc3545' : ($log['level'] === 'warning' ? '#856404' : '#155724'); ?>;">[<?php echo esc_html($log['level']); ?>]</span>
                                <?php echo esc_html($log['message']); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <script>
                jQuery(document).ready(function($) {
                    let optimizationInProgress = false;
                    const i18n = imgOptimizer.i18n;

                    // Controla a habilitação/desabilitação dos campos de dimensão
                    function toggleDimensionFields() {
                        var isEnabled = $('#img_optimizer_enable_resize').is(':checked');
                        $('#max-width-field, #max-height-field').prop('disabled', !isEnabled);
                    }

                    // Inicializa o estado dos campos
                    toggleDimensionFields();
                    $('#img_optimizer_enable_resize').change(toggleDimensionFields);

                    // Otimização em lote com progresso real
                    $('#optimize-existing').click(function() {
                        if (optimizationInProgress) {
                            alert(i18n.in_progress);
                            return;
                        }

                        optimizationInProgress = true;
                        const button = $(this);
                        button.prop('disabled', true).text(i18n.optimizing);
                        $('#optimization-progress').show();
                        $('#progress-text').text('0%');
                        $('#current-file').text(i18n.starting);

                        let totalImages = 0;
                        let processedImages = 0;

                        // Step 1: Get the list of images to process
                        $.post(imgOptimizer.ajax_url, {
                            action: 'optimize_existing_images',
                            _ajax_nonce: imgOptimizer.nonce
                        }).done(function(response) {
                            if (response.success && response.data.total > 0) {
                                totalImages = response.data.total;
                                $('#progress-bar').attr('max', totalImages);
                                $('#current-file').text(i18n.found_images.replace('%d', totalImages));
                                // Step 2: Start processing the queue
                                processQueue();
                            } else {
                                $('#current-file').text(response.data.message || i18n.no_images);
                                resetUI();
                            }
                        }).fail(function() {
                            alert(i18n.start_error);
                            resetUI();
                        });

                        function processQueue() {
                            if (!optimizationInProgress) {
                                return; // Stop if cancelled
                            }

                            $.post(imgOptimizer.ajax_url, {
                                action: 'get_optimization_progress', // This action will process one image
                                _ajax_nonce: imgOptimizer.nonce
                            }).done(function(response) {
                                if (response.success) {
                                    processedImages++;
                                    const progress = (totalImages > 0) ? (processedImages / totalImages) * 100 : 0;

                                    $('#progress-bar').val(processedImages);
                                    $('#progress-text').text(Math.round(progress) + '%');
                                    if (response.data.processed_file !== '<?php echo esc_js(__('None', 'image-optimizer')); ?>') {
                                        $('#current-file').html(i18n.optimized_label + ' <strong>' + response.data.processed_file + '</strong>. ' + i18n.remaining_label + ' ' + response.data.remaining);
                                    }

                                    if (response.data.remaining > 0) {
                                        processQueue(); // Process next image
                                    } else {
                                        $('#current-file').text(i18n.batch_done);
                                        setTimeout(resetUI, 2000);
                                    }
                                } else {
                                    // Handle error for a single image and continue
                                    processedImages++; // count it as processed to not get stuck
                                    $('#current-file').append('<br/><span style="color:red;">' + i18n.optimize_error + ' ' + (response.data.message || i18n.unknown_error) + '</span>');
                                    if (totalImages > processedImages) {
                                        setTimeout(processQueue, 1000); // Wait a bit before continuing
                                    } else {
                                        $('#current-file').append('<br/>' + i18n.batch_done_errors);
                                        setTimeout(resetUI, 3000);
                                    }
                                }
                            }).fail(function() {
                                alert(i18n.comm_error);
                                resetUI();
                            });
                        }

                        function resetUI() {
                            optimizationInProgress = false;
                            button.prop('disabled', false).text(i18n.start_button);
                            $('#optimization-progress').slideUp();
                        }
                    });
                });
            </script>
        </div>
<?php
    }

    /**
     * Prepara a otimização em lote pegando todos os IDs de imagem
     */
    public function optimize_existing_images()
    {
        check_ajax_referer('img_optimizer_ajax_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'image-optimizer')));
        }

        global $wpdb;
        $query = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg', 'image/png', 'image/jpg')";
        $image_ids = $wpdb->get_col($query);

        if (empty($image_ids)) {
            wp_send_json_success(array('total' => 0, 'message' => __('No images found.', 'image-optimizer')));
            return;
        }

        // Usar um transiente específico do usuário para evitar conflitos
        $transient_key = 'img_opt_batch_queue_' . get_current_user_id();
        set_transient($transient_key, $image_ids, HOUR_IN_SECONDS);

        wp_send_json_success(array('total' => count($image_ids)));
    }

    /**
     * Inicia o buffer de saída para capturar o HTML
     */
    public function start_html_buffer()
    {
        // Não executa no admin, feeds, etc.
        if (is_admin() || is_feed() || is_robots() || is_preview()) {
            return;
        }

        // Verifica se o navegador do visitante aceita WebP
        if (strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') === false) {
            return;
        }

        ob_start(array($this, 'replace_images_with_webp'));
    }

    /**
     * Função principal que busca e substitui as imagens no HTML
     */
    private function replace_images_with_webp($html)
    {
        // Garante que $html é uma string antes de aplicar o regex
        if (!is_string($html)) {
            return $html;
        }

        // Otimização: processa apenas as tags <img> para evitar sobrecarga em grandes páginas
        $img_pattern = '/<img[^>]+src\s*=\s*["\'][^"\']+wp-content\/uploads\/[^"\']+\.(?:jpe?g|png)["\'][^>]*>/i';

        $html = preg_replace_callback($img_pattern, function ($img_matches) {
            // Agora, dentro de cada <img>, aplicamos o padrão mais específico
            $pattern = '/(<img[^>]+src\s*=\s*["\'])([^"\'\s]+wp-content\/uploads\/.+)\.(jpe?g|png)(["\'][^>]*>)/i';
            return preg_replace_callback($pattern, array($this, 'webp_replace_callback'), $img_matches[0]);
        }, $html);

        return $html;
    }

    /**
     * Callback para a substituição. Verifica a existência do arquivo WebP.
     */
    private function webp_replace_callback($matches)
    {
        // A URL da imagem original, sem a extensão
        $image_url_without_ext = $matches[2];

        // A URL completa da imagem original (para checagem)
        $original_image_url = $image_url_without_ext . '.' . $matches[3];

        // Constrói a URL da imagem WebP
        $webp_url = $image_url_without_ext . '.webp';

        // Converte a URL em um caminho de arquivo no servidor para ver se ele existe
        // ABSPATH é a raiz do WordPress. get_site_url() é a URL base.
        $site_url = get_site_url();
        $upload_dir = wp_get_upload_dir();
        $base_url = $upload_dir['baseurl'];

        // Garante que a substituição seja robusta para diferentes configurações de URL
        $file_path = str_replace($base_url, $upload_dir['basedir'], $image_url_without_ext . '.webp');

        // Se o arquivo .webp existir no servidor, substitui
        if (file_exists($file_path)) {
            // Retorna a tag <img> completa com a URL .webp
            return $matches[1] . $webp_url . $matches[4];
        }

        // Se o arquivo .webp não for encontrado, retorna a tag <img> original sem modificação
        return $matches[0];
    }

    /**
     * Processa uma imagem da fila de otimização em lote
     */
    public function get_optimization_progress()
    {
        check_ajax_referer('img_optimizer_ajax_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'image-optimizer')));
        }

        $transient_key = 'img_opt_batch_queue_' . get_current_user_id();
        $queue = get_transient($transient_key);

        if ($queue === false || empty($queue)) {
            delete_transient($transient_key);
            wp_send_json_success(array('remaining' => 0, 'processed_file' => __('None', 'image-optimizer')));
            return;
        }

        $image_id = array_shift($queue);

        // Atualiza o transiente imediatamente
        set_transient($transient_key, $queue, HOUR_IN_SECONDS);

        $file_path = get_attached_file($image_id);

        if (!$file_path || !file_exists($file_path)) {
            // Se o arquivo não existe, apenas continuamos para o próximo
            wp_send_json_success(array(
                'remaining' => count($queue),
                'processed_file' => sprintf(__('File not found for ID: %d', 'image-optimizer'), $image_id)
            ));
            return;
        }

        $mime_type = get_post_mime_type($image_id);

        // Otimiza a imagem principal
        $this->optimize_image($file_path, $mime_type);

        // Otimiza thumbnails
        wp_update_attachment_metadata($image_id, wp_generate_attachment_metadata($image_id, $file_path));

        wp_send_json_success(array(
            'remaining' => count($queue),
            'processed_file' => basename($file_path)
        ));
    }
}

// Inicializa o plugin
ImageOptimizerAdvanced::get_instance();
