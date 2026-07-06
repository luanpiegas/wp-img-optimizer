<?php
/**
 * Image optimizer: GD-based resize, compress, WebP, backup, dedup.
 *
 * Depends on Settings, Stats and Logger. No WP coupling beyond
 * option/transient helpers and the WP-Cron async hook.
 */

namespace ImageOptimizer;

if (!defined('ABSPATH')) {
    exit;
}

final class Optimizer
{
    public const OPTION_PROCESSED   = 'img_optimizer_processed';
    public const PROCESSED_CAP      = 1000;
    public const TRANSIENT_RATE     = 'img_opt_last_run_';
    public const RATE_LIMIT_SECONDS = 2;
    public const ASYNC_HOOK         = 'optimize_image_async';

    private const SUPPORTED_MIMES = array('image/jpeg', 'image/jpg', 'image/png');
    private const MIN_MEMORY      = 128; // MB

    /** @var Settings */
    private $settings;

    /** @var Stats */
    private $stats;

    /** @var Logger */
    private $logger;

    public function __construct(Settings $settings, Stats $stats, Logger $logger)
    {
        $this->settings = $settings;
        $this->stats    = $stats;
        $this->logger   = $logger;
    }

    /**
     * Hook wp_handle_upload: otimiza imagem recém-enviada.
     *
     * @param array<string,mixed> $upload
     * @return array<string,mixed>
     */
    public function on_upload(array $upload, string $context): array
    {
        if (!isset($upload['file'], $upload['type'])) {
            return $upload;
        }

        if (!$this->check_system_requirements()) {
            return $upload;
        }

        if (!$this->check_rate_limit()) {
            $this->logger->log(
                sprintf(__('Rate limit reached for user %d', 'image-optimizer'), get_current_user_id()),
                'warning'
            );
            return $upload;
        }

        $mime_type = $upload['type'];
        if (!in_array($mime_type, self::SUPPORTED_MIMES, true)) {
            return $upload;
        }

        $file_path = $upload['file'];
        if (getimagesize($file_path) === false) {
            $this->logger->log(sprintf(__('Invalid file: %s', 'image-optimizer'), $file_path), 'error');
            return $upload;
        }

        $settings = $this->settings->all();
        if ($settings['async'] === '1') {
            wp_schedule_single_event(time() + 5, self::ASYNC_HOOK, array($file_path, $mime_type));
        } else {
            $this->optimize($file_path, $mime_type);
        }

        return $upload;
    }

    /**
     * Hook wp_generate_attachment_metadata: otimiza thumbnails gerados.
     *
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public function on_thumbnails(array $metadata): array
    {
        if ($this->settings->all()['thumbnails'] !== '1' || !isset($metadata['sizes'])) {
            return $metadata;
        }

        $upload_dir = wp_upload_dir();
        $base_dir   = dirname($metadata['file']);

        foreach ($metadata['sizes'] as $size_data) {
            $thumbnail_path = $upload_dir['basedir'] . '/' . $base_dir . '/' . $size_data['file'];
            if (!file_exists($thumbnail_path)) {
                continue;
            }
            $mime = $this->normalize_mime($size_data['mime-type']);
            $this->optimize($thumbnail_path, $mime, false);
        }

        return $metadata;
    }

    /**
     * Hook optimize_image_async (WP-Cron): processa imagem agendada.
     */
    public function process_async(string $file_path, string $mime_type): void
    {
        if (file_exists($file_path)) {
            $this->optimize($file_path, $mime_type);
        }
    }

    /**
     * Otimização principal: resize + compress + webp + backup + dedup.
     */
    public function optimize(string $file_path, string $mime_type, bool $log = true): bool
    {
        if (!file_exists($file_path)) {
            return false;
        }

        $file_hash = md5_file($file_path);
        $processed = get_option(self::OPTION_PROCESSED, array());
        if (in_array($file_hash, $processed, true)) {
            return false;
        }

        $settings      = $this->settings->all();
        $original_size = filesize($file_path);

        // Backup deve ocorrer ANTES de qualquer modificação no arquivo
        if ($settings['backup'] === '1') {
            $this->backup($file_path);
        }

        $restored = $this->bump_memory();
        $image    = $this->load($file_path, $mime_type);
        if ($image === false) {
            if ($restored) {
                ini_set('memory_limit', $restored);
            }
            return false;
        }

        $image = $this->maybe_resize($image, $mime_type, $settings);
        $this->save($image, $file_path, $mime_type, $settings['quality']);
        $this->maybe_generate_webp($image, $file_path, $settings);
        imagedestroy($image);

        if ($restored) {
            ini_set('memory_limit', $restored);
        }

        clearstatcache();
        $new_size    = filesize($file_path);
        $bytes_saved = $original_size - $new_size;

        if ($bytes_saved > 0) {
            $this->stats->increment($bytes_saved);
            if ($log) {
                $reduction = round(($bytes_saved / $original_size) * 100, 2);
                $this->logger->log(
                    sprintf(
                        __('Image optimized: %1$s - %2$s%% reduction (%3$s saved)', 'image-optimizer'),
                        $file_path,
                        $reduction,
                        $this->format_bytes($bytes_saved)
                    ),
                    'success'
                );
            }
        }

        $processed[] = $file_hash;
        update_option(self::OPTION_PROCESSED, array_slice($processed, -self::PROCESSED_CAP));

        return true;
    }

    /**
     * Verifica requisitos do sistema (GD + memória).
     */
    public function check_system_requirements(): bool
    {
        if (!extension_loaded('gd')) {
            $this->logger->log(__('GD extension not found', 'image-optimizer'), 'error');
            return false;
        }

        $memory_bytes = $this->string_to_bytes(ini_get('memory_limit'));
        if ($memory_bytes < self::MIN_MEMORY * 1024 * 1024) {
            $this->logger->log(
                sprintf(__('Insufficient memory: %s', 'image-optimizer'), ini_get('memory_limit')),
                'warning'
            );
        }

        return true;
    }

    /**
     * Rate limiting por usuário (evita sobrecarga em uploads rápidos).
     */
    private function check_rate_limit(): bool
    {
        $key = self::TRANSIENT_RATE . get_current_user_id();
        $last = get_transient($key);
        if ($last && (time() - (int) $last) < self::RATE_LIMIT_SECONDS) {
            return false;
        }
        set_transient($key, time(), 10);
        return true;
    }

    /**
     * Carrega a imagem GD conforme o mime type.
     *
     * @return resource|\GdImage|false
     */
    private function load(string $file_path, string $mime_type)
    {
        switch ($mime_type) {
            case 'image/jpeg':
            case 'image/jpg':
                return imagecreatefromjpeg($file_path);
            case 'image/png':
                return imagecreatefrompng($file_path);
            default:
                return false;
        }
    }

    /**
     * Salva a imagem otimizada no disco.
     *
     * @param resource|\GdImage $image
     */
    private function save($image, string $file_path, string $mime_type, int $quality): void
    {
        switch ($mime_type) {
            case 'image/jpeg':
            case 'image/jpg':
                imagejpeg($image, $file_path, $quality);
                break;
            case 'image/png':
                // PNG usa escala 0-9 (inversa à qualidade 0-100)
                imagepng($image, $file_path, (int) round(9 - ($quality / 100) * 9));
                break;
        }
    }

    /**
     * Redimensiona mantendo proporção, se necessário.
     *
     * @param resource|\GdImage $image
     * @return resource|\GdImage
     */
    private function maybe_resize($image, string $mime_type, array $settings)
    {
        $orig_w = imagesx($image);
        $orig_h = imagesy($image);

        $new = $this->calculate_dimensions($orig_w, $orig_h, $settings['max_width'], $settings['max_height']);

        $should_resize = ($settings['enable_resize'] === '1')
            && ($new['width'] !== $orig_w || $new['height'] !== $orig_h);

        if (!$should_resize) {
            return $image;
        }

        $new_image = imagecreatetruecolor($new['width'], $new['height']);

        // Preserva transparência para PNG
        if ($mime_type === 'image/png') {
            imagealphablending($new_image, false);
            imagesavealpha($new_image, true);
            $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
            imagefill($new_image, 0, 0, $transparent);
        }

        imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new['width'], $new['height'], $orig_w, $orig_h);
        imagedestroy($image);

        return $new_image;
    }

    /**
     * Gera versão WebP se habilitado e suportado.
     *
     * @param resource|\GdImage $image
     */
    private function maybe_generate_webp($image, string $file_path, array $settings): void
    {
        if ($settings['webp'] !== '1' || !function_exists('imagewebp')) {
            return;
        }
        $webp_path = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $file_path);
        imagewebp($image, $webp_path, $settings['quality']);
    }

    /**
     * Cria backup da imagem original (uma única vez por arquivo).
     */
    private function backup(string $file_path): void
    {
        $backup_path = $file_path . '.backup';
        if (!file_exists($backup_path)) {
            copy($file_path, $backup_path);
        }
    }

    /**
     * Aumenta o limite de memória temporariamente se abaixo de 512MB.
     *
     * @return string|false Retorna o limite antigo (para restaurar) ou false se não alterou.
     */
    private function bump_memory()
    {
        $old = ini_get('memory_limit');
        if ($this->string_to_bytes($old) < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
            return $old;
        }
        return false;
    }

    /**
     * Calcula novas dimensões mantendo proporção de aspecto.
     *
     * @return array{width:int,height:int}
     */
    private function calculate_dimensions(int $orig_w, int $orig_h, int $max_w, int $max_h): array
    {
        if ($orig_w <= $max_w && $orig_h <= $max_h) {
            return array('width' => $orig_w, 'height' => $orig_h);
        }

        $ratio = $orig_w / $orig_h;
        if ($max_w / $max_h > $ratio) {
            return array(
                'width'  => (int) round($max_h * $ratio),
                'height' => $max_h,
            );
        }
        return array(
            'width'  => $max_w,
            'height' => (int) round($max_w / $ratio),
        );
    }

    /**
     * Normaliza mime types do WordPress para os esperados pela GD.
     */
    private function normalize_mime(string $mime): string
    {
        if ($mime === 'image/jpg') {
            return 'image/jpeg';
        }
        return $mime;
    }

    /**
     * Converte string de memória (ex: "256M") para bytes.
     */
    private function string_to_bytes(string $val): int
    {
        $val  = trim($val);
        $last = strtolower($val[strlen($val) - 1] ?? '');
        $num  = intval($val);

        switch ($last) {
            case 'g':
                $num *= 1024;
                // fall through
            case 'm':
                $num *= 1024;
                // fall through
            case 'k':
                $num *= 1024;
        }
        return $num;
    }

    /**
     * Formata bytes para exibição humana.
     */
    public function format_bytes(float $bytes, int $precision = 2): string
    {
        $units = array('B', 'KB', 'MB', 'GB');
        $bytes = max($bytes, 0);
        $pow   = (int) floor(($bytes > 0 ? log($bytes) : 0) / log(1024));
        $pow   = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
