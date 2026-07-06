<?php
/**
 * Logger: structured log entries stored in an option (capped at 100).
 */

namespace ImageOptimizer;

if (!defined('ABSPATH')) {
    exit;
}

final class Logger
{
    public const OPTION     = 'img_optimizer_logs';
    public const MAX_ENTRIES = 100;
    public const RETENTION_DAYS = 30;

    /**
     * Registra uma mensagem de log.
     */
    public function log(string $message, string $level = 'info'): void
    {
        $entry = array(
            'timestamp' => current_time('mysql'),
            'level'     => $level,
            'message'   => $message,
            'user_id'   => get_current_user_id(),
        );

        $logs = get_option(self::OPTION, array());
        $logs[] = $entry;
        $logs = array_slice($logs, -self::MAX_ENTRIES);

        update_option(self::OPTION, $logs);

        if ($level === 'error') {
            error_log('Image Optimizer: ' . $message);
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 10): array
    {
        $logs = get_option(self::OPTION, array());
        return array_slice($logs, -$limit);
    }

    /**
     * Remove logs com mais de RETENTION_DAYS dias.
     */
    public function cleanup_old(): void
    {
        $logs = get_option(self::OPTION, array());
        $cutoff = strtotime('-' . self::RETENTION_DAYS . ' days');

        $logs = array_filter($logs, static function (array $log) use ($cutoff): bool {
            return strtotime($log['timestamp']) > $cutoff;
        });

        update_option(self::OPTION, array_values($logs));
    }
}
