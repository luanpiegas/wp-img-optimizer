<?php
/**
 * Plugin Name: Image Optimizer
 * Plugin URI: https://luanpiegas.com
 * Description: Professional PNG, JPG and WebP image optimizer with advanced features
 * Version: 3.0.0
 * Author: Luan Piegas
 * License: GPL v2 or later
 * Text Domain: image-optimizer
 * Domain Path: /languages
 * Requires PHP: 7.4
 */

// Previne acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Carrega as classes do plugin (sem autoloader por ser um plugin simples)
require_once __DIR__ . '/src/Settings.php';
require_once __DIR__ . '/src/Stats.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/Optimizer.php';
require_once __DIR__ . '/src/WebPServer.php';
require_once __DIR__ . '/src/AdminPage.php';

use ImageOptimizer\AdminPage;
use ImageOptimizer\Logger;
use ImageOptimizer\Optimizer;
use ImageOptimizer\Settings;
use ImageOptimizer\Stats;
use ImageOptimizer\WebPServer;

/**
 * Composition root: instancia dependências e conecta hooks.
 *
 * O plugin inteiro é organizado em classes com responsabilidade única:
 * - Settings:  chaves de opção, defaults, sanitização, cache
 * - Stats:     contador de otimizações e bytes economizados
 * - Logger:    logs estruturados (capped em 100)
 * - Optimizer: processamento GD (resize, compress, webp, backup, dedup)
 * - WebPServer: output buffer que reescreve <img src> para .webp
 * - AdminPage:  UI, AJAX, fila de otimização em lote
 */
final class ImageOptimizerPlugin
{
    /** @var self|null */
    private static $instance = null;

    /** @var Settings */
    private $settings;

    /** @var Stats */
    private $stats;

    /** @var Logger */
    private $logger;

    /** @var Optimizer */
    private $optimizer;

    /** @var WebPServer */
    private $webp;

    /** @var AdminPage */
    private $admin;

    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Composição de dependências
        $this->settings = new Settings();
        $this->stats    = new Stats();
        $this->logger   = new Logger();
        $this->optimizer = new Optimizer($this->settings, $this->stats, $this->logger);
        $this->webp     = new WebPServer();
        $this->admin    = new AdminPage($this->settings, $this->stats, $this->logger, $this->optimizer);

        $this->settings->install_defaults();
        $this->register_hooks();
    }

    /**
     * Conecta todos os hooks do plugin.
     */
    private function register_hooks(): void
    {
        // i18n
        add_action('plugins_loaded', array($this, 'load_textdomain'));

        // Otimização (upload + thumbnails + async)
        add_action('wp_handle_upload', array($this->optimizer, 'on_upload'), 10, 2);
        add_filter('wp_generate_attachment_metadata', array($this->optimizer, 'on_thumbnails'));
        add_action(Optimizer::ASYNC_HOOK, array($this->optimizer, 'process_async'), 10, 2);

        // Admin
        add_action('admin_menu', array($this->admin, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this->admin, 'enqueue'));

        // AJAX (bulk optimization)
        add_action('wp_ajax_optimize_existing_images', array($this->admin, 'ajax_build_queue'));
        add_action('wp_ajax_get_optimization_progress', array($this->admin, 'ajax_process_one'));

        // Limpeza de logs antigos
        add_action('wp_scheduled_delete', array($this->logger, 'cleanup_old'));

        // WebP serving (apenas se ambas as opções estiverem ativas)
        $s = $this->settings->all();
        if ($s['serve_webp'] === '1' && $s['webp'] === '1') {
            add_action('template_redirect', array($this->webp, 'start_buffer'), 1);
        }
    }

    /**
     * Hook plugins_loaded: carrega o domínio de tradução.
     */
    public function load_textdomain(): void
    {
        load_plugin_textdomain(
            'image-optimizer',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }

    /**
     * Hook admin_init: registra as settings via Settings API.
     */
    public function register_settings(): void
    {
        $this->settings->register();
    }
}

// Inicializa o plugin
ImageOptimizerPlugin::get_instance();
