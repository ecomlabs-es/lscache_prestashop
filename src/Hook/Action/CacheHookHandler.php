<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author     LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license    https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Hook\Action;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Config\CacheConfig as Conf;
use LiteSpeed\Cache\Config\CdnConfig;
use LiteSpeed\Cache\Core\CacheManager;
use LiteSpeed\Cache\Core\CacheState;
use LiteSpeed\Cache\Helper\CacheHelper;
use LiteSpeed\Cache\Integration\Cloudflare;
use LiteSpeed\Cache\Logger\CacheLogger as LSLog;

/** Handles cache lifecycle hooks (purge API, cache clear, htaccess, watermark). */
class CacheHookHandler
{
    private CacheManager $cache;
    private Conf $config;

    /** Idempotency flag: the PS cache clear flow fires several hooks in one
     *  request. We only want to run the external purge once per request. */
    private bool $externalPurgeDone = false;

    public function __construct(CacheManager $cache, Conf $config)
    {
        $this->cache = $cache;
        $this->config = $config;
    }

    /**
     * Public purge API for third-party modules.
     *
     * Required: $params['from'] (caller identifier).
     * One of: $params['public'] (tags), $params['private'] (tags), $params['ALL'].
     */
    public function onCachePurge(array $params): void
    {
        if (!isset($params['from'])) {
            if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_PURGE_EVENT) {
                LSLog::log(__METHOD__ . ' Illegal entrance - missing from', LSLog::LEVEL_PURGE_EVENT);
            }

            return;
        }

        $msg = 'hookLitespeedCachePurge from ' . $params['from'];

        if (!CacheState::isActive()) {
            if (isset($params['public']) && $params['public'] === '*') {
                $this->cache->purgeByTags('*', false, $msg);
            } elseif (_LITESPEED_DEBUG_ >= LSLog::LEVEL_PURGE_EVENT) {
                LSLog::log($msg . ' Illegal tags - module not activated', LSLog::LEVEL_PURGE_EVENT);
            }

            return;
        }

        if (isset($params['public'])) {
            $this->cache->purgeByTags($params['public'], false, $msg);
            if ($params['public'] === '*') {
                $this->purgeCloudflare();
            }
        } elseif (isset($params['private'])) {
            $this->cache->purgeByTags($params['private'], true, $msg);
        } elseif (isset($params['ALL'])) {
            $this->cache->purgeEntireStorage($msg);
            $this->purgeCloudflare();
        } elseif (_LITESPEED_DEBUG_ >= LSLog::LEVEL_PURGE_EVENT) {
            LSLog::log($msg . ' Illegal - missing public/private/ALL', LSLog::LEVEL_PURGE_EVENT);
        }

        \Configuration::updateGlobalValue('LITESPEED_STAT_PURGE_COUNT',
            (int) \Configuration::getGlobalValue('LITESPEED_STAT_PURGE_COUNT') + 1
        );
        \Configuration::updateGlobalValue('LITESPEED_STAT_LAST_PURGE', time());

        $logDetail = 'Cache purge from ' . $params['from'];
        if (isset($params['public'])) {
            $tags = is_array($params['public']) ? implode(', ', $params['public']) : $params['public'];
            $logDetail .= ' — public tags: ' . $tags;
        } elseif (isset($params['private'])) {
            $tags = is_array($params['private']) ? implode(', ', $params['private']) : $params['private'];
            $logDetail .= ' — private tags: ' . $tags;
        } elseif (isset($params['ALL'])) {
            $logDetail .= ' — entire storage';
        }
        \PrestaShopLogger::addLog($logDetail, 1, null, 'LiteSpeedCache', 0, true);
    }

    public function onNotCacheable(array $params): void
    {
        if (!CacheState::isActiveForUser()) {
            return;
        }
        $reason = ($params['reason'] ?? '') . (isset($params['from']) ? ' from ' . $params['from'] : '');
        CacheState::markNotCacheable($reason);
        if ($reason && _LITESPEED_DEBUG_ >= LSLog::LEVEL_NOCACHE_REASON) {
            LSLog::log('setNotCacheable - ' . $reason, LSLog::LEVEL_NOCACHE_REASON);
        }
    }

    /**
     * Fired inline from Tools::clearCompile() when PrestaShop clears its
     * Smarty compile cache. This runs during the admin request, BEFORE
     * the response is flushed, so `header('X-LiteSpeed-Purge: *')` is
     * guaranteed to reach LSWS.
     *
     * The backoffice "Clear cache" button (PerformanceController::clearCacheAction)
     * invokes CacheClearerChain which includes SmartyCacheClearer, which
     * calls Tools::clearSmartyCache() and from there Tools::clearCompile().
     * This hook is the reliable trigger for an LSWS purge in that flow.
     */
    public function onClearCompileCache(array $params): void
    {
        $this->runExternalPurge();
    }

    /**
     * Fired inside SymfonyCacheClearer's register_shutdown_function, after
     * the admin response has been flushed — headers_sent() is true here,
     * so emitting `X-LiteSpeed-Purge: *` from this hook is a no-op.
     *
     * Kept as a safety net in case some flow fires only this hook
     * (idempotency flag prevents double-execution when both fire).
     */
    public function onClearSf2Cache(array $params): void
    {
        // PS only warms prod cache — warm dev to prevent login redirect
        $this->warmupDevCache();

        $this->runExternalPurge();
    }

    /**
     * Run the external purge (LSWS + Redis + CDN). Idempotent per request:
     * multiple hook firings in the same PS cache clear sequence result in
     * a single purge.
     */
    private function runExternalPurge(): void
    {
        if ($this->externalPurgeDone) {
            return;
        }
        $this->externalPurgeDone = true;

        try {
            $flushAll = (bool) $this->config->get(Conf::CFG_FLUSH_ALL);
            $cdnCfg = CdnConfig::getAll();
            $cdnPurge = !empty($cdnCfg[CdnConfig::CF_ENABLE])
                && !empty($cdnCfg[CdnConfig::CF_PURGE])
                && !empty($cdnCfg[CdnConfig::CF_ZONE_ID])
                && !empty($cdnCfg[CdnConfig::CF_KEY]);

            // LiteSpeed cache purge
            if ($flushAll && !headers_sent()) {
                header('X-LiteSpeed-Purge: *');
                CacheHelper::clearInternalCache();
            }

            // Redis flush
            if ($flushAll
                && defined('_PS_CACHE_ENABLED_') && _PS_CACHE_ENABLED_
                && defined('_PS_CACHING_SYSTEM_') && _PS_CACHING_SYSTEM_ === 'CacheRedis'
            ) {
                $cache = \Cache::getInstance();
                if ($cache instanceof \LiteSpeed\Cache\Cache\CacheRedis) {
                    $cache->flush();
                }
            }

            // Cloudflare CDN purge
            if ($cdnPurge) {
                (new Cloudflare(
                    $cdnCfg[CdnConfig::CF_KEY],
                    $cdnCfg[CdnConfig::CF_EMAIL]
                ))->purgeAll($cdnCfg[CdnConfig::CF_ZONE_ID]);
            }

            \PrestaShopLogger::addLog(
                'External caches purged after PrestaShop cache clear'
                . ($flushAll ? ' (LS+Redis)' : ' (skipped, flush_all off)')
                . ($cdnPurge ? ' + CDN' : ''),
                1, null, 'LiteSpeedCache', 0, true
            );
        } catch (\Throwable $e) {
        }
    }

    /**
     * PS SymfonyCacheClearer only warms 'prod'. When running in 'dev',
     * the missing cache causes AdminController::isLoggedBack() to fail
     * and redirects to login. This warms 'dev' to prevent that.
     */
    private function warmupDevCache(): void
    {
        if (!defined('_PS_MODE_DEV_') || !_PS_MODE_DEV_) {
            return;
        }

        $devCacheDir = _PS_ROOT_DIR_ . '/var/cache/dev';
        if (is_dir($devCacheDir) && count(scandir($devCacheDir)) > 2) {
            return; // already warmed
        }

        try {
            global $kernel;
            if (!$kernel) {
                return;
            }

            $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
            $application->setAutoExit(false);
            $application->doRun(
                new \Symfony\Component\Console\Input\ArrayInput([
                    'command' => 'cache:warmup',
                    '--no-optional-warmers' => true,
                    '--env' => 'dev',
                ]),
                new \Symfony\Component\Console\Output\NullOutput()
            );
        } catch (\Throwable $e) {
            // Warmup failure is not critical — dev cache rebuilds on next request
        }
    }

    public function onHtaccessCreate(array $params): void
    {
        $enable = (bool) $this->config->get(Conf::CFG_ENABLED);
        $guest = ($this->config->get(Conf::CFG_GUESTMODE) == 1);
        $mobile = (bool) $this->config->get(Conf::CFG_DIFFMOBILE);
        $loginCookie = (string) $this->config->get(Conf::CFG_LOGIN_COOKIE);
        $varyCookies = (string) $this->config->get(Conf::CFG_VARY_COOKIES);
        CacheHelper::htAccessUpdate($enable, $guest, $mobile, $loginCookie, $varyCookies);
    }

    public function onWatermark(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookActionWatermark', $params);
    }

    public function onWebserviceResources(array $params): void
    {
        $this->cache->purgeByCatchAllMethod('hookAddWebserviceResources', $params);
    }

    private function purgeCloudflare(): void
    {
        $cdn = CdnConfig::getAll();
        $zoneId = $cdn[CdnConfig::CF_ZONE_ID] ?? '';

        if (!$cdn[CdnConfig::CF_ENABLE] || !$cdn[CdnConfig::CF_PURGE] || !$zoneId || !$cdn[CdnConfig::CF_KEY]) {
            return;
        }

        (new Cloudflare($cdn[CdnConfig::CF_KEY], $cdn[CdnConfig::CF_EMAIL]))->purgeAll($zoneId);
        \PrestaShopLogger::addLog('CDN purge — Cloudflare zone ' . $zoneId, 1, null, 'LiteSpeedCache', 0, true);
    }

    /** @internal Reserved for future use — currently unused. */
    private function flushExternalCaches(): void
    {
        // Flush Redis object cache if active
        if (
            defined('_PS_CACHE_ENABLED_') && _PS_CACHE_ENABLED_
            && defined('_PS_CACHING_SYSTEM_') && _PS_CACHING_SYSTEM_ === 'CacheRedis'
        ) {
            $instance = \Cache::getInstance();
            if ($instance instanceof \LiteSpeed\Cache\Cache\CacheRedis) {
                $instance->flush();
            }
        }

        // Purge Cloudflare if CDN purge is enabled
        if ((bool) $this->config->get(Conf::CFG_FLUSH_ALL)) {
            $cdnCfg = CdnConfig::getAll();
            if (
                (bool) $cdnCfg[CdnConfig::CF_PURGE]
                && (bool) $cdnCfg[CdnConfig::CF_ENABLE]
                && !empty($cdnCfg[CdnConfig::CF_ZONE_ID])
            ) {
                try {
                    $cf = new Cloudflare($cdnCfg[CdnConfig::CF_KEY], $cdnCfg[CdnConfig::CF_EMAIL]);
                    $cf->purgeAll($cdnCfg[CdnConfig::CF_ZONE_ID]);
                } catch (\Exception $e) {
                }
            }
        }
    }
}
