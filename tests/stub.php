<?php

/**
 * Minimal WordPress stubs for ServicesAutoloaderService tests.
 */

if (!function_exists('apply_filters')) {
    function apply_filters($hook_name, $value, ...$args)
    {
        return $value;
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title($title, $fallback_title = '', $context = 'save')
    {
        $title = strtolower((string) $title);
        $title = preg_replace('/[^a-z0-9]+/', '-', $title);

        return trim((string) $title, '-') ?: $fallback_title;
    }
}
