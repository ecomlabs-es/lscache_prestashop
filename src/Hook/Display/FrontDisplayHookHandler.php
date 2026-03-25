<?php
/**
 * LiteSpeed Cache for PrestaShop.
 *
 * NOTICE OF LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see https://opensource.org/licenses/GPL-3.0 .
 *
 * @author   LiteSpeed Technologies
 * @copyright Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license  https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Hook\Display;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Context;
use LiteSpeed\Cache\Config\CacheConfig;
use LiteSpeed\Cache\Config\CdnConfig;
use LiteSpeed\Cache\Core\CacheManager;
use LiteSpeed\Cache\Core\CacheState;
use LiteSpeed\Cache\Logger\CacheLogger as LSLog;

class FrontDisplayHookHandler
{
    /** @var CacheConfig */
    private $config;

    public function __construct(CacheConfig $config)
    {
        $this->config = $config;
    }

    public function onDisplayFooterAfter(array $params): string
    {
        $output = '';

        // Cache debug panel — shown when debug headers are enabled and IP is allowed
        if ($this->config->get(CacheConfig::CFG_DEBUG_HEADER) && $this->isDebugIpAllowed()) {
            $output .= $this->renderDebugPanel();
        }

        if (!CacheState::isCacheable() || !_LITESPEED_DEBUG_) {
            return $output;
        }
        $comment = isset($_SERVER['HTTP_USER_AGENT'])
            ? '<!-- LiteSpeed Cache created with user_agent: ' . $_SERVER['HTTP_USER_AGENT'] . ' -->' . PHP_EOL
            : '<!-- LiteSpeed Cache snapshot generated at ' . gmdate('Y/m/d H:i:s') . ' GMT -->';

        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_FOOTER_COMMENT) {
            LSLog::log('Add html comments in footer ' . $comment, LSLog::LEVEL_FOOTER_COMMENT);
        }

        return $output . $comment;
    }

    public function onOverrideLayoutTemplate(array $params, CacheManager $cache): void
    {
        if (!CacheState::isCacheable()) {
            return;
        }
        if ($cache->hasNotification()) {
            CacheState::markNotCacheable('Has private notification');
        } elseif (!CacheState::isEsiRequest()) {
            $cache->initCacheTagsByController($params);
        }
    }

    public function onDisplayOverrideTemplate(array $params, CacheManager $cache): void
    {
        if (!CacheState::isCacheable()) {
            return;
        }
        $cache->initCacheTagsByController($params);
    }

    /**
     * Renders a debug panel shell. Data is loaded via AJAX from the debug front controller.
     * The shell contains no dynamic data so LiteSpeed can safely cache the page.
     */
    private function renderDebugPanel(): string
    {
        $baseUri = __PS_BASE_URI__ . 'modules/litespeedcache/views/';
        $debugUrl = \Context::getContext()->link->getModuleLink('litespeedcache', 'debug', [], true);

        // Store Redis keys accessed during this page render
        $debugToken = '';
        if (defined('_PS_CACHE_ENABLED_') && _PS_CACHE_ENABLED_
            && defined('_PS_CACHING_SYSTEM_') && _PS_CACHING_SYSTEM_ === 'CacheRedis') {
            $cache = \Cache::getInstance();
            if ($cache && method_exists($cache, 'storeDebugKeys')) {
                $debugToken = bin2hex(random_bytes(8));
                $cache->storeDebugKeys($debugToken);
            }
        }

        $smarty = \Context::getContext()->smarty;
        $smarty->assign([
            'lsc_logo_url' => $baseUri . 'img/litespeed-icon.svg',
            'lsc_debug_js_url' => $baseUri . 'js/debug-panel.js',
        ]);

        $html = $smarty->fetch('module:litespeedcache/views/templates/front/debug-panel.tpl');

        $attrs = 'data-url="' . htmlspecialchars($debugUrl, ENT_QUOTES) . '"';
        if ($debugToken) {
            $attrs .= ' data-keys-token="' . $debugToken . '"';
        }
        $html = str_replace('id="lsc-debug-panel"', 'id="lsc-debug-panel" ' . $attrs, $html);

        return $html;
    }

    /**
     * Detects the current entity type and ID from the controller context.
     */
    private function detectEntity(): array
    {
        $result = ['type' => '', 'id' => 0];

        try {
            $context = \Context::getContext();
            $controller = $context->controller ?? null;
            if (!$controller) {
                return $result;
            }

            $class = get_class($controller);

            if (isset($controller->php_self)) {
                $page = $controller->php_self;
            } elseif (method_exists($controller, 'getPageName')) {
                $page = $controller->getPageName();
            } else {
                $page = '';
            }

            switch ($page) {
                case 'product':
                    $result['type'] = 'Product';
                    $result['id'] = (int) \Tools::getValue('id_product');
                    break;
                case 'category':
                    $result['type'] = 'Category';
                    $result['id'] = (int) \Tools::getValue('id_category');
                    break;
                case 'cms':
                    $result['type'] = 'CMS';
                    $result['id'] = (int) \Tools::getValue('id_cms');
                    break;
                case 'manufacturer':
                    $result['type'] = 'Brand';
                    $result['id'] = (int) \Tools::getValue('id_manufacturer');
                    break;
                case 'supplier':
                    $result['type'] = 'Supplier';
                    $result['id'] = (int) \Tools::getValue('id_supplier');
                    break;
                case 'index':
                    $result['type'] = 'Home';
                    break;
                case 'search':
                    $result['type'] = 'Search';
                    break;
                case 'cart':
                    $result['type'] = 'Cart';
                    break;
                case 'order':
                case 'checkout':
                    $result['type'] = 'Checkout';
                    break;
                case 'my-account':
                    $result['type'] = 'Account';
                    break;
                case 'pagenotfound':
                    $result['type'] = '404';
                    break;
                case 'best-sales':
                    $result['type'] = 'Best Sales';
                    break;
                case 'new-products':
                    $result['type'] = 'New Products';
                    break;
                case 'prices-drop':
                    $result['type'] = 'Prices Drop';
                    break;
                default:
                    if ($page) {
                        $result['type'] = $page;
                    } else {
                        $result['type'] = str_replace('Controller', '', $class);
                    }
            }
        } catch (\Throwable $e) {
            $result['type'] = 'Unknown';
        }

        return $result;
    }

    private function debugRow(string $label, string $value, string $color): string
    {
        return '<div style="display:flex;justify-content:space-between;gap:8px">'
            . '<span>' . $label . '</span>'
            . '<strong style="color:' . $color . '">' . $value . '</strong></div>';
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return round($bytes / 1073741824, 1) . ' GB';
    }

    private function formatTtl(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        }
        if ($seconds < 86400) {
            return round($seconds / 3600, 1) . 'h';
        }

        return round($seconds / 86400, 1) . 'd';
    }

    private function isDebugIpAllowed(): bool
    {
        $ips = $this->config->getArray(CacheConfig::CFG_DEBUG_IPS);

        if (empty($ips)) {
            return true;
        }

        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';

        return in_array($remoteIp, $ips, true);
    }

    private function formatHeaderValue(string $name, string $value): string
    {
        // Tags — show as pills
        if (strpos($name, 'tag') !== false) {
            $tags = explode(',', $value);
            $pills = '';
            foreach ($tags as $t) {
                $pills .= '<span style="display:inline-block;background:#25b9d7;color:#000;padding:0 4px;border-radius:3px;margin:1px;font-size:10px">'
                    . htmlspecialchars(trim($t)) . '</span>';
            }

            return $pills;
        }

        // Vary — parse JSON and format nicely
        if (strpos($name, 'vary') !== false) {
            $jsonPos = strpos($value, '{');
            if ($jsonPos === false) {
                return '<span style="color:#fff">' . htmlspecialchars($value) . '</span>';
            }

            $status = trim(substr($value, 0, $jsonPos));
            $json = json_decode(substr($value, $jsonPos), true);
            if (!is_array($json)) {
                return '<span style="color:#fff;word-break:break-all">' . htmlspecialchars($value) . '</span>';
            }

            $box = 'style="margin:2px 0;padding:3px 5px;background:rgba(255,255,255,.07);border-radius:3px;font-size:10px"';
            $out = '<span style="color:#70b580;font-weight:bold">' . htmlspecialchars($status) . '</span>';

            // Cookie Vary
            $cv = $json['cv'] ?? [];
            $out .= '<div ' . $box . '>';
            $out .= '<div style="color:#25b9d7;margin-bottom:2px">Cookie Vary</div>';
            $out .= '<div><span style="color:#adb5bd">name:</span> <span style="color:#fff">' . htmlspecialchars($cv['name'] ?? "\xe2\x80\x94") . '</span></div>';
            $out .= '<div><span style="color:#adb5bd">original:</span> <span style="color:#fff">' . htmlspecialchars($cv['ov'] ?? 'null') . '</span></div>';
            $out .= '<div><span style="color:#adb5bd">new:</span> <span style="color:#fff">' . htmlspecialchars($cv['nv'] ?? 'null') . '</span></div>';
            if (!empty($cv['data']) && is_array($cv['data'])) {
                $out .= '<div><span style="color:#adb5bd">data:</span> ';
                foreach ($cv['data'] as $k => $v) {
                    $out .= '<span style="display:inline-block;background:#25b9d7;color:#000;padding:0 3px;border-radius:2px;margin:1px;font-size:9px">'
                        . htmlspecialchars($k) . '=' . htmlspecialchars((string) $v) . '</span>';
                }
                $out .= '</div>';
            }
            $out .= '</div>';

            // Vary Value
            $vv = $json['vv'] ?? [];
            $out .= '<div ' . $box . '>';
            $out .= '<div style="color:#25b9d7;margin-bottom:2px">Vary Value</div>';
            $out .= '<div><span style="color:#adb5bd">original:</span> <span style="color:#fff">' . htmlspecialchars($vv['ov'] ?? 'null') . '</span></div>';
            $out .= '<div><span style="color:#adb5bd">new:</span> <span style="color:#fff">' . htmlspecialchars($vv['nv'] ?? 'null') . '</span></div>';
            $out .= '</div>';

            // PS Session
            $ps = $json['ps'] ?? [];
            $out .= '<div ' . $box . '>';
            $out .= '<div style="color:#25b9d7;margin-bottom:2px">PS Session</div>';
            $out .= '<div><span style="color:#adb5bd">original:</span> <span style="color:#fff;word-break:break-all">' . htmlspecialchars($ps['ov'] ?? 'null') . '</span></div>';
            $out .= '<div><span style="color:#adb5bd">new:</span> <span style="color:#fff;word-break:break-all">' . htmlspecialchars($ps['nv'] ?? 'null') . '</span></div>';
            $out .= '</div>';

            return $out;
        }

        // Cache status — colored
        if (strpos($name, 'cache') !== false && strlen($value) < 10) {
            $v = strtoupper($value);
            if ($v === 'HIT') {
                $color = '#70b580';
            } elseif ($v === 'MISS') {
                $color = '#f0ad4e';
            } else {
                $color = '#e84e6a';
            }

            return '<span style="color:' . $color . ';font-weight:bold">' . htmlspecialchars($v) . '</span>';
        }

        return '<span style="color:#fff;word-break:break-all">' . htmlspecialchars($value) . '</span>';
    }
}
