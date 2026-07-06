<?php
/**
 * Settings storage: option keys, defaults, sanitization, cached access.
 *
 * Single source of truth for plugin configuration. All option keys,
 * defaults, and sanitization rules live here.
 */

namespace ImageOptimizer;

if (!defined('ABSPATH')) {
    exit;
}

final class Settings
{
    public const OPTION_QUALITY       = 'img_optimizer_quality';
    public const OPTION_ENABLE_RESIZE = 'img_optimizer_enable_resize';
    public const OPTION_MAX_WIDTH     = 'img_optimizer_max_width';
    public const OPTION_MAX_HEIGHT    = 'img_optimizer_max_height';
    public const OPTION_BACKUP        = 'img_optimizer_backup';
    public const OPTION_WEBP          = 'img_optimizer_webp';
    public const OPTION_ASYNC         = 'img_optimizer_async';
    public const OPTION_THUMBNAILS    = 'img_optimizer_thumbnails';
    public const OPTION_SERVE_WEBP    = 'img_optimizer_serve_webp';

    public const QUALITY_MIN = 1;
    public const QUALITY_MAX = 100;
    public const DIMENSION_MIN = 100;
    public const DIMENSION_MAX = 8000;

    /** @var array<string,mixed>|null */
    private $cache = null;

    /**
     * Cria opções padrão se não existirem.
     */
    public function install_defaults(): void
    {
        $defaults = $this->defaults();
        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }

    /**
     * Retorna todas as configurações resolvidas (com cache em memória).
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = array(
                'quality'       => intval(get_option(self::OPTION_QUALITY, 85)),
                'enable_resize' => get_option(self::OPTION_ENABLE_RESIZE, '1'),
                'max_width'     => intval(get_option(self::OPTION_MAX_WIDTH, 1920)),
                'max_height'    => intval(get_option(self::OPTION_MAX_HEIGHT, 1080)),
                'backup'        => get_option(self::OPTION_BACKUP, '0'),
                'webp'          => get_option(self::OPTION_WEBP, '1'),
                'async'         => get_option(self::OPTION_ASYNC, '0'),
                'thumbnails'    => get_option(self::OPTION_THUMBNAILS, '1'),
                'serve_webp'    => get_option(self::OPTION_SERVE_WEBP, '0'),
            );
        }
        return $this->cache;
    }

    /**
     * Invalida o cache de configurações (após salvar/resetar).
     */
    public function flush_cache(): void
    {
        $this->cache = null;
    }

    /**
     * Sanitiza e persiste os valores enviados via POST.
     *
     * @param array<string,mixed> $post {@} _POST
     */
    public function save_from_post(array $post): void
    {
        $quality    = max(self::QUALITY_MIN, min(self::QUALITY_MAX, absint($post['img_optimizer_quality'] ?? self::QUALITY_MAX)));
        $max_width  = max(self::DIMENSION_MIN, min(self::DIMENSION_MAX, absint($post['img_optimizer_max_width'] ?? 1920)));
        $max_height = max(self::DIMENSION_MIN, min(self::DIMENSION_MAX, absint($post['img_optimizer_max_height'] ?? 1080)));

        update_option(self::OPTION_QUALITY, $quality);
        update_option(self::OPTION_ENABLE_RESIZE, isset($post['img_optimizer_enable_resize']) ? '1' : '0');
        update_option(self::OPTION_MAX_WIDTH, $max_width);
        update_option(self::OPTION_MAX_HEIGHT, $max_height);
        update_option(self::OPTION_BACKUP, isset($post['img_optimizer_backup']) ? '1' : '0');
        update_option(self::OPTION_WEBP, isset($post['img_optimizer_webp']) ? '1' : '0');
        update_option(self::OPTION_ASYNC, isset($post['img_optimizer_async']) ? '1' : '0');
        update_option(self::OPTION_THUMBNAILS, isset($post['img_optimizer_thumbnails']) ? '1' : '0');
        update_option(self::OPTION_SERVE_WEBP, isset($post['img_optimizer_serve_webp']) ? '1' : '0');

        $this->flush_cache();
    }

    /**
     * Registra as settings no WordPress (necessário para Settings API).
     */
    public function register(): void
    {
        $group = 'img_optimizer_settings';
        register_setting($group, self::OPTION_QUALITY);
        register_setting($group, self::OPTION_ENABLE_RESIZE);
        register_setting($group, self::OPTION_MAX_WIDTH);
        register_setting($group, self::OPTION_MAX_HEIGHT);
        register_setting($group, self::OPTION_BACKUP);
        register_setting($group, self::OPTION_WEBP);
        register_setting($group, self::OPTION_ASYNC);
        register_setting($group, self::OPTION_THUMBNAILS);
        register_setting($group, self::OPTION_SERVE_WEBP);
    }

    /**
     * @return array<string,mixed>
     */
    private function defaults(): array
    {
        return array(
            self::OPTION_QUALITY       => 85,
            self::OPTION_ENABLE_RESIZE => '1',
            self::OPTION_MAX_WIDTH     => 1920,
            self::OPTION_MAX_HEIGHT    => 1080,
            self::OPTION_BACKUP        => '0',
            self::OPTION_WEBP          => '1',
            self::OPTION_ASYNC         => '0',
            self::OPTION_THUMBNAILS    => '1',
            self::OPTION_SERVE_WEBP    => '0',
        );
    }
}
