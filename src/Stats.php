<?php
/**
 * Stats storage: optimized count, bytes saved, last reset.
 */

namespace ImageOptimizer;

if (!defined('ABSPATH')) {
    exit;
}

final class Stats
{
    public const OPTION = 'img_optimizer_stats';

    /** @var array<string,int>|null */
    private $cache = null;

    /**
     * @return array<string,int>
     */
    public function get(): array
    {
        if ($this->cache === null) {
            $this->cache = wp_parse_args(
                get_option(self::OPTION, array()),
                array(
                    'total_optimized' => 0,
                    'bytes_saved'     => 0,
                    'last_reset'      => time(),
                )
            );
        }
        return $this->cache;
    }

    public function increment(int $bytes_saved): void
    {
        $stats = $this->get();
        $stats['total_optimized']++;
        $stats['bytes_saved'] += $bytes_saved;
        update_option(self::OPTION, $stats);
        $this->cache = $stats;
    }

    public function reset(): void
    {
        update_option(self::OPTION, array(
            'total_optimized' => 0,
            'bytes_saved'     => 0,
            'last_reset'      => time(),
        ));
        $this->cache = null;
    }
}
