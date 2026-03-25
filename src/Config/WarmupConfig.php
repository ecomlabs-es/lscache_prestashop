<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

class WarmupConfig
{
    public const ENTRY = 'LITESPEED_WARMUP_SETTINGS';

    public const PROFILE = 'profile';
    public const CRAWL_DELAY = 'crawl_delay';
    public const CONCURRENT_REQUESTS = 'concurrent';
    public const CRAWL_TIMEOUT = 'crawl_timeout';
    public const SERVER_LOAD_LIMIT = 'load_limit';
    public const MOBILE_CRAWL = 'mobile_crawl';
    public const MOBILE_USER_AGENT = 'mobile_useragent';

    public const PROFILE_LOW = 'low';
    public const PROFILE_MEDIUM = 'medium';
    public const PROFILE_HIGH = 'high';
    public const PROFILE_CUSTOM = 'custom';

    /** @var array Delay values in milliseconds */
    /** @var array Profile presets: Low=shared (1 core/2GB), Medium=VPS (4 cores/8GB), High=dedicated */
    private static array $profiles = [
        self::PROFILE_LOW => [
            self::CONCURRENT_REQUESTS => 1,
            self::CRAWL_DELAY => 500,
            self::CRAWL_TIMEOUT => 30,
            self::SERVER_LOAD_LIMIT => 0.7,
        ],
        self::PROFILE_MEDIUM => [
            self::CONCURRENT_REQUESTS => 4,
            self::CRAWL_DELAY => 100,
            self::CRAWL_TIMEOUT => 30,
            self::SERVER_LOAD_LIMIT => 3.0,
        ],
        self::PROFILE_HIGH => [
            self::CONCURRENT_REQUESTS => 10,
            self::CRAWL_DELAY => 25,
            self::CRAWL_TIMEOUT => 30,
            self::SERVER_LOAD_LIMIT => 0,
        ],
    ];

    public static function getProfileSettings(string $profile): ?array
    {
        return self::$profiles[$profile] ?? null;
    }

    public static function getProfiles(): array
    {
        return self::$profiles;
    }

    /** @var array|null */
    private static $data;

    public static function getAll(): array
    {
        if (self::$data === null) {
            $raw = \Configuration::getGlobalValue(self::ENTRY);
            $decoded = $raw ? json_decode($raw, true) : [];
            self::$data = array_merge(self::getDefaults(), is_array($decoded) ? $decoded : []);
        }

        return self::$data;
    }

    public static function get(string $key)
    {
        return self::getAll()[$key] ?? null;
    }

    public static function saveAll(array $data): void
    {
        self::$data = $data;
        \Configuration::updateGlobalValue(self::ENTRY, json_encode($data));
    }

    public static function getDefaults(): array
    {
        return [
            self::PROFILE => self::PROFILE_MEDIUM,
            self::CRAWL_DELAY => 100,
            self::CONCURRENT_REQUESTS => 3,
            self::CRAWL_TIMEOUT => 30,
            self::SERVER_LOAD_LIMIT => 2.0,
            self::MOBILE_CRAWL => 0,
            self::MOBILE_USER_AGENT => 'lscache_runner Mobile Safari/537.36 iPhone',
        ];
    }

    public static function reset(): void
    {
        self::$data = null;
    }
}
