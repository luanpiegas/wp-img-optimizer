<?php
/**
 * WebP serving: output buffer que rewrites <img src> para .webp
 * quando o arquivo existe e o navegador aceita image/webp.
 */

namespace ImageOptimizer;

if (!defined('ABSPATH')) {
    exit;
}

final class WebPServer
{
    /**
     * Hook template_redirect: inicia o output buffer se aplicável.
     */
    public function start_buffer(): void
    {
        if (is_admin() || is_feed() || is_robots() || is_preview()) {
            return;
        }

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (strpos($accept, 'image/webp') === false) {
            return;
        }

        ob_start(array($this, 'rewrite_html'));
    }

    /**
     * Substitui tags <img> apontando para uploads .jpg/.png por .webp
     * quando o arquivo .webp correspondente existe no disco.
     */
    public function rewrite_html($html): string
    {
        if (!is_string($html) || $html === '') {
            return $html;
        }

        // Processa apenas tags <img> com src apontando para wp-content/uploads
        $img_pattern = '/<img[^>]+src\s*=\s*["\'][^"\']+wp-content\/uploads\/[^"\']+\.(?:jpe?g|png)["\'][^>]*>/i';

        return preg_replace_callback($img_pattern, function (array $img_matches): string {
            $pattern = '/(<img[^>]+src\s*=\s*["\'])([^"\'\s]+wp-content\/uploads\/.+)\.(jpe?g|png)(["\'][^>]*>)/i';
            return preg_replace_callback($pattern, array($this, 'replace_callback'), $img_matches[0]);
        }, $html);
    }

    /**
     * Callback individual: verifica existência do .webp e reescreve o src.
     */
    private function replace_callback(array $matches): string
    {
        $image_url_without_ext = $matches[2];
        $webp_url              = $image_url_without_ext . '.webp';

        $upload_dir = wp_get_upload_dir();
        $file_path  = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $image_url_without_ext . '.webp');

        if (file_exists($file_path)) {
            return $matches[1] . $webp_url . $matches[4];
        }

        return $matches[0];
    }
}
