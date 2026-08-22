<?php

// Global helpers used by Blade templates. Loaded via composer.json
// "autoload.files" so they're available everywhere (no namespace).

if (! function_exists('formatDuration')) {
    /**
     * Format a number of seconds into a compact human duration ("1h 5m", "45s").
     */
    function formatDuration(?int $seconds): string
    {
        if ($seconds === null || $seconds === false) {
            return '—';
        }
        $seconds = (int) $seconds;
        if ($seconds < 1) {
            return '<1s';
        }
        if ($seconds < 60) {
            return $seconds.'s';
        }
        if ($seconds < 3600) {
            $m = (int) floor($seconds / 60);
            $s = (int) round($seconds % 60);

            return $s ? "{$m}m {$s}s" : "{$m}m";
        }
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(($seconds % 3600) / 60);

        return $m ? "{$h}h {$m}m" : "{$h}h";
    }
}

if (! function_exists('formatBytes')) {
    function formatBytes($bytes, int $decimals = 1): string
    {
        if ($bytes === null || $bytes === false) {
            return '—';
        }
        $bytes = (float) $bytes;
        if ($bytes <= 0 || ! is_finite($bytes)) {
            return '0 B';
        }
        $k = 1024;
        $sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) min(floor(log($bytes) / log($k)), count($sizes) - 1);

        return rtrim(rtrim(number_format($bytes / pow($k, $i), $decimals), '0'), '.').' '.$sizes[$i];
    }
}

if (! function_exists('formatDate')) {
    function formatDate(?string $iso, bool $withTime = true): string
    {
        if (! $iso) {
            return '—';
        }
        try {
            $d = \Illuminate\Support\Carbon::parse($iso);
        } catch (\Throwable $e) {
            return '—';
        }
        $date = $d->format('M j, Y');
        if (! $withTime) {
            return $date;
        }

        return $date.' · '.$d->format('H:i');
    }
}

if (! function_exists('timeAgo')) {
    function timeAgo(?string $iso): string
    {
        if (! $iso) {
            return '—';
        }
        try {
            return \Illuminate\Support\Carbon::parse($iso)->diffForHumans();
        } catch (\Throwable $e) {
            return '—';
        }
    }
}

if (! function_exists('severity_color')) {
    function severity_color(string $severity): string
    {
        return [
            'critical' => '#ef4444',
            'high'     => '#f97316',
            'medium'   => '#f59e0b',
            'low'      => '#06b6d4',
            'info'     => '#6b7280',
        ][$severity] ?? '#6b7280';
    }
}
