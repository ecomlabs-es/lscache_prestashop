<?php
/**
 * LiteSpeed Cache — Debug panel AJAX endpoint.
 *
 * Returns JSON with cache status, Redis, server info and log sizes.
 * Always sends no-cache headers so LiteSpeed never caches this response.
 *
 * @author   LiteSpeed Technologies
 * @copyright Copyright (c) 2017-2024 LiteSpeed Technologies, Inc.
 * @license  https://opensource.org/licenses/GPL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Config\CacheConfig;
use LiteSpeed\Cache\Config\CdnConfig;
use LiteSpeed\Cache\Core\CacheState;

class LiteSpeedCacheDebugModuleFrontController extends ModuleFrontController
{
    public function __construct()
    {
        $this->content_only = true;
        $this->display_header = false;
        $this->display_footer = false;
        parent::__construct();
    }

    public function initContent(): void
    {
        parent::initContent();

        // Prevent LiteSpeed from caching this response
        header('X-LiteSpeed-Cache-Control: no-cache');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        // Check debug header is enabled
        $config = CacheConfig::getInstance();
        if (!$config->get(CacheConfig::CFG_DEBUG_HEADER)) {
            $this->ajaxResponse(['error' => 'Debug disabled']);

            return;
        }

        // Check IP
        $ips = $config->getArray(CacheConfig::CFG_DEBUG_IPS);
        if (!empty($ips) && !in_array(\Tools::getRemoteAddr(), $ips, true)) {
            $this->ajaxResponse(['error' => 'IP not allowed']);

            return;
        }

        $data = [];

        // Cache status
        $data['cache'] = [
            'status' => CacheConfig::isBypassed() ? 'BYPASS' : 'UNKNOWN',
            'esi' => false,
            'guest' => (bool) $config->get(CacheConfig::CFG_GUESTMODE),
            'cdn' => !empty(CdnConfig::getAll()[CdnConfig::CF_ENABLE]),
        ];

        // Probe the actual page via HEAD to get real LiteSpeed headers
        $pageUrl = \Tools::getValue('page_url', '');
        $data['ls_headers'] = [];
        if ($pageUrl && filter_var($pageUrl, FILTER_VALIDATE_URL)) {
            $data['ls_headers'] = $this->probeLsHeaders($pageUrl);
            $cacheHeader = strtoupper($data['ls_headers']['x-litespeed-cache'] ?? '');
            if ($cacheHeader) {
                $data['cache']['status'] = $cacheHeader;
            }
            // Detect ESI from cache-control header (esi=on)
            $cc = $data['ls_headers']['x-litespeed-cache-control'] ?? ($data['ls_headers']['x-lscache-debug-cc'] ?? '');
            if (stripos($cc, 'esi') !== false) {
                $data['cache']['esi'] = true;
            }
        }

        // TTL
        $data['ttl'] = [
            'public' => (int) ($config->get(CacheConfig::CFG_PUBLIC_TTL) ?: 0),
            'private' => (int) ($config->get(CacheConfig::CFG_PRIVATE_TTL) ?: 0),
            'home' => (int) ($config->get(CacheConfig::CFG_HOME_TTL) ?: 0),
            'mobile_separate' => (bool) $config->get(CacheConfig::CFG_DIFFMOBILE),
        ];

        // Redis
        $data['redis'] = ['status' => 'OFF', 'info' => null];
        if (defined('_PS_CACHE_ENABLED_') && _PS_CACHE_ENABLED_
            && defined('_PS_CACHING_SYSTEM_') && _PS_CACHING_SYSTEM_ === 'CacheRedis') {
            $cache = \Cache::getInstance();
            if ($cache && method_exists($cache, 'isConnected') && $cache->isConnected()) {
                $data['redis']['status'] = 'CONNECTED';
                try {
                    if (method_exists($cache, 'getRedis') && $cache->getRedis()) {
                        $info = $cache->getRedis()->info();
                        $hits = (int) ($info['keyspace_hits'] ?? 0);
                        $misses = (int) ($info['keyspace_misses'] ?? 0);
                        $data['redis']['info'] = [
                            'version' => $info['redis_version'] ?? '-',
                            'memory' => $info['used_memory_human'] ?? '-',
                            'hit_rate' => ($hits + $misses) > 0 ? round($hits / ($hits + $misses) * 100, 1) . '%' : '-',
                        ];
                    }
                    if (method_exists($cache, 'getPrefix')) {
                        $data['redis']['prefix'] = $cache->getPrefix();
                    }
                    // Read page-specific cached query tables stored during render
                    $keysToken = \Tools::getValue('keys_token', '');
                    if ($keysToken && method_exists($cache, 'getDebugKeys')) {
                        $data['redis']['page_keys'] = $cache->getDebugKeys($keysToken);
                    }
                } catch (\Throwable $e) {
                }
            } else {
                $data['redis']['status'] = 'ERROR';
            }
        }

        // Server
        $data['server'] = [
            'software' => $_SERVER['SERVER_SOFTWARE'] ?? '-',
            'php' => PHP_VERSION,
            'prestashop' => _PS_VERSION_,
            'sampled_at' => date('Y-m-d H:i:s'),
        ];

        // Logs
        $data['logs'] = [];
        $logFiles = [
            'lscache.log' => _PS_ROOT_DIR_ . '/var/logs/lscache.log',
            'error.log' => '/usr/local/lsws/logs/error.log',
            'access.log' => '/usr/local/lsws/logs/localhost.access.log',
        ];
        foreach ($logFiles as $label => $path) {
            $data['logs'][$label] = is_file($path) ? filesize($path) : null;
        }

        $this->ajaxResponse($data);
    }

    private function probeLsHeaders(string $url): array
    {
        $headers = [];

        try {
            $ch = curl_init($url);

            // Forward the visitor's context so LSWS resolves the same
            // cache variant it would for their browser:
            //   - Cookie header: vary cookie, PrestaShop-*, lgcookieslaw,
            //     all customer state that affects vary keying.
            //   - X-Forwarded-For / X-Real-IP: the real visitor IP, so
            //     modules that check REMOTE_ADDR (allow_ips, debug_ips)
            //     behave identically to the original request.
            //   - User-Agent: the mobile vary rule in .htaccess keys off
            //     this, so using the visitor's UA preserves the variant.
            // Without these, the probe is effectively a cookieless
            // loopback request and LSWS returns a different (or no)
            // X-LiteSpeed-Cache header, producing UNKNOWN in the panel.
            $reqHeaders = [];

            $visitorIp = \Tools::getRemoteAddr();
            if ($visitorIp !== '' && !in_array($visitorIp, ['127.0.0.1', '::1'], true)) {
                $reqHeaders[] = 'X-Forwarded-For: ' . $visitorIp;
                $reqHeaders[] = 'X-Real-IP: ' . $visitorIp;
            }

            if (!empty($_COOKIE) && is_array($_COOKIE)) {
                $pairs = [];
                foreach ($_COOKIE as $name => $value) {
                    if (!is_string($name) || !is_string($value)) {
                        continue;
                    }
                    $pairs[] = $name . '=' . $value;
                }
                if ($pairs) {
                    $reqHeaders[] = 'Cookie: ' . implode('; ', $pairs);
                }
            }

            $ua = !empty($_SERVER['HTTP_USER_AGENT'])
                ? (string) $_SERVER['HTTP_USER_AGENT']
                : 'LiteSpeedCache-DebugProbe';

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => $ua,
                CURLOPT_HTTPHEADER => $reqHeaders,
                CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$headers) {
                    if (stripos($header, 'X-LiteSpeed') === 0 || stripos($header, 'X-LSCACHE') === 0) {
                        [$name, $value] = explode(':', $header, 2);
                        $headers[strtolower(trim($name))] = trim($value);
                    }

                    return strlen($header);
                },
                // Abort body download after headers are received
                CURLOPT_WRITEFUNCTION => function ($ch, $data) {
                    return -1;
                },
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
        }

        return $headers;
    }

    private function ajaxResponse(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
