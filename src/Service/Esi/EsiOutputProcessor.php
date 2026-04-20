<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author     LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license    https://opensource.org/licenses/GPL-3.0
 */

declare(strict_types=1);

namespace LiteSpeed\Cache\Service\Esi;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Core\CacheManager;
use LiteSpeed\Cache\Core\CacheState;
use LiteSpeed\Cache\Helper\CacheHelper;
use LiteSpeed\Cache\Logger\CacheLogger as LSLog;

/**
 * EsiOutputProcessor -- output buffer callback for ESI processing.
 *
 * Handles HTTP response code checks, ESI marker replacement delegation,
 * and cache-control header finalisation.
 */
class EsiOutputProcessor
{
    private CacheManager $cache;
    private EsiMarkerManager $markerManager;

    public function __construct(CacheManager $cache, EsiMarkerManager $markerManager)
    {
        $this->cache = $cache;
        $this->markerManager = $markerManager;
    }

    /**
     * Process the output buffer: check response codes, replace ESI markers,
     * and set cache-control headers.
     */
    public function processBuffer(string $buffer): string
    {
        // ESI sub-requests short-circuit via exit() in the front controller,
        // but PS shutdown work (session close, kernel terminate) sometimes
        // produces a second buffer flush cycle in the same request. That
        // second invocation captures whatever got emitted during shutdown
        // and would overwrite the already-delivered empty body with ~8 KB
        // of noise. Guard with a static latch so only the first invocation
        // emits content; later ones return an empty string.
        static $alreadyEmitted = false;
        if ($alreadyEmitted) {
            return '';
        }
        $alreadyEmitted = true;

        $bufferInLen = strlen($buffer);

        if (CacheState::isFrontController()) {
            \LiteSpeedCache::setVaryCookie();
        }

        $code = http_response_code();
        if ($code === 404) {
            CacheState::set(CacheState::ERROR_CODE);
            if (CacheHelper::isStaticResource($_SERVER['REQUEST_URI'])) {
                $buffer = '<!-- 404 not found -->';
                CacheState::clear(CacheState::CAN_INJECT_ESI);
            }
        } elseif ($code !== 200) {
            CacheState::set(CacheState::ERROR_CODE);
            CacheState::markNotCacheable('Response code is ' . $code);
            if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_NOCACHE_REASON) {
                LSLog::log('setNotCacheable - Response code is ' . $code, LSLog::LEVEL_NOCACHE_REASON);
            }
        }

        if (CacheState::canInjectEsi()
            && ($this->markerManager->hasMarkers() || CacheState::isCacheable())) {
            $buffer = $this->markerManager->replaceMarkers($buffer);
        }

        $bufferOutLen = strlen($buffer);

        // Forensic line for cacheable responses: records buffer sizes at entry
        // and exit of the callback plus the response headers that most often
        // interfere with caching. Gated behind an active debug level because
        // LEVEL_FORCE writes unconditionally (default $isDebug=0 still matches
        // 0 >= 0), and this sits on the hot path — every cacheable response
        // would otherwise append to var/logs/lscache.log via file_put_contents
        // with no rotation, turning the logger into the bottleneck under
        // load. Debug off (production default) skips it entirely; flipping
        // debug on in the admin UI re-enables the diagnostic.
        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_NOCACHE_REASON
            && $code === 200 && CacheState::isCacheable()) {
            $sensitive = [];
            foreach (headers_list() as $h) {
                $lh = strtolower($h);
                if (strpos($lh, 'cache-control:') === 0
                    || strpos($lh, 'pragma:') === 0
                    || strpos($lh, 'expires:') === 0
                    || strpos($lh, 'content-length:') === 0
                    || strpos($lh, 'location:') === 0
                    || strpos($lh, 'set-cookie:') === 0
                    || strpos($lh, 'x-litespeed-cache-control:') === 0) {
                    $sensitive[] = $h;
                }
            }
            LSLog::log(
                sprintf(
                    'processBuffer cacheable uri=%s bufIn=%d bufOut=%d code=%d state=[%s] hdrs=[%s]',
                    $_SERVER['REQUEST_URI'] ?? '?',
                    $bufferInLen,
                    $bufferOutLen,
                    $code,
                    CacheState::debugInfo(),
                    implode(' | ', $sensitive)
                ),
                LSLog::LEVEL_FORCE
            );
        }

        $this->cache->setCacheControlHeader();

        return $buffer;
    }
}
