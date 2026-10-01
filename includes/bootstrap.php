<?php
/**
 * Uygulama genel baslangic ayarlari
 * Tek merkezden timezone standardi uygular.
 */

if (!defined('APP_BOOTSTRAPPED')) {
    define('APP_BOOTSTRAPPED', true);

    $timezone = 'Europe/Istanbul';

    // Ortam degiskeni tanimliysa merkez timezone degeri olarak kullan.
    $envTimezone = getenv('APP_TIMEZONE');
    if (is_string($envTimezone) && trim($envTimezone) !== '') {
        $timezone = trim($envTimezone);
    }

    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        $timezone = 'UTC';
    }

    date_default_timezone_set($timezone);

    if (!defined('APP_TIMEZONE')) {
        define('APP_TIMEZONE', $timezone);
    }
}

if (!function_exists('app_now')) {
    function app_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('app_today_compact')) {
    function app_today_compact(): string
    {
        return date('Ymd');
    }
}
