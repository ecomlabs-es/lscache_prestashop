<?php
/**
 * LiteSpeed Cache for Prestashop — ESI front controller.
 *
 * Handles Edge Side Include sub-requests from LiteSpeed Web Server.
 * Each ESI block (widget, hook, token, env) is rendered individually
 * so the main page can be served from full-page cache.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc.
 * @license  https://opensource.org/licenses/GPL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Esi\EsiItem;
use LiteSpeed\Cache\Helper\CacheHelper as LSHelper;
use LiteSpeed\Cache\Logger\CacheLogger as LSLog;
use LiteSpeed\Cache\Resolver\HookParamsResolver;

class LiteSpeedCacheEsiModuleFrontController extends ModuleFrontController
{
    /** @var array<string, callable> ESI type → handler map */
    private array $handlers = [];

    public function __construct()
    {
        $this->content_only = true;
        $this->display_header = false;
        $this->display_header_javascript = false;
        $this->display_footer = false;
        parent::__construct();

        $this->handlers = [
            EsiItem::ESI_RENDERWIDGET => [$this, 'processRenderWidget'],
            EsiItem::ESI_CALLHOOK => [$this, 'processCallHook'],
            EsiItem::ESI_SMARTYFIELD => [$this, 'processSmartyField'],
            EsiItem::ESI_JSDEF => [LscIntegration::class, 'processJsDef'],
            EsiItem::ESI_TOKEN => [$this, 'processToken'],
            EsiItem::ESI_ENV => [$this, 'processEnv'],
        ];
    }

    public function display()
    {
        // Vary cookie changed (login/register/logout)
        if (LiteSpeedCache::setVaryCookie()) {
            $this->handleVaryChange();

            return;
        }

        $item = EsiItem::decodeEsiUrl();
        if (is_string($item)) {
            LSLog::log('Invalid ESI url ' . $item, LSLog::LEVEL_EXCEPTION);

            return;
        }

        $this->processItem($item);
        $html = $item->getContent();

        if ($html === EsiItem::RES_FAILED) {
            LSLog::log('ESI module not found', LSLog::LEVEL_EXCEPTION);

            return;
        }

        $lsc = Module::getInstanceByName(LiteSpeedCache::MODULE_NAME);
        $lsc->addCacheControlByEsiModule($item);

        // Process related items (inline JS/CSS)
        $inline = $this->processRelatedItems($item->getId());

        if ($inline) {
            $lsc->setEsiOn();
        }

        $this->output($inline . $html);
    }

    // -------------------------------------------------------------------------
    // Vary change handling
    // -------------------------------------------------------------------------

    private function handleVaryChange(): void
    {
        // Browser navigated directly to ESI URL after login/register.
        // Redirect back instead of showing blank page.
        if ($this->isBrowserRequest()) {
            $home = $this->context->link->getPageLink('index', true);
            Tools::redirect($_SERVER['HTTP_REFERER'] ?? $home);

            return;
        }

        // Normal ESI sub-request: return reload marker
        $this->output('<!-- EnvChange - reload -->');
    }

    /**
     * ESI sub-requests from LiteSpeed don't send Accept: text/html.
     * A real browser navigation does.
     */
    private function isBrowserRequest(): bool
    {
        return strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== false;
    }

    // -------------------------------------------------------------------------
    // Item processing
    // -------------------------------------------------------------------------

    private function processItem(EsiItem $item): void
    {
        $type = $item->getParam('pt');

        if (isset($this->handlers[$type])) {
            call_user_func($this->handlers[$type], $item);
        } else {
            $item->setFailed();
        }

        LSLog::log('processItem ' . $item->getInfoLog(), LSLog::LEVEL_ESI_INCLUDE);
    }

    private function processRelatedItems($related): string
    {
        $inline = '';
        foreach (LSHelper::getRelatedItems($related) as $ri) {
            $this->processItem($ri);
            $inline .= $ri->getInline();
        }

        return $inline;
    }

    // -------------------------------------------------------------------------
    // ESI type handlers
    // -------------------------------------------------------------------------

    private function processRenderWidget(EsiItem $item): void
    {
        $module = $this->loadModule($item);
        if (!$module) {
            return;
        }
        $item->preRenderWidget();
        $item->setContent($module->renderWidget($item->getParam('h'), $this->buildParams()));
    }

    private function processCallHook(EsiItem $item): void
    {
        $module = $this->loadModule($item);
        if (!$module) {
            return;
        }

        $params = $this->buildParams();
        $this->resolveModuleVariables($params, $item);

        if (method_exists($module, 'getWidgetVariables')) {
            $this->context->smarty->assign($module->getWidgetVariables('', $params));
        }

        $content = $module->{$item->getParam('mt')}($params);
        $item->setContent(is_string($content) ? $content : '');
    }

    private function processToken(EsiItem $item): void
    {
        $item->setContent(Tools::getToken(false));
    }

    private function processEnv(EsiItem $item): void
    {
        $item->setContent('');
    }

    private function processSmartyField(EsiItem $item): void
    {
        switch ($item->getParam('f')) {
            case 'widget':
                $this->processRenderWidget($item);
                break;
            case 'widget_block':
                $this->processWidgetBlock($item);
                break;
            default:
                LscIntegration::processModField($item);
                break;
        }
    }

    private function processWidgetBlock(EsiItem $item): void
    {
        $module = $this->loadModule($item);
        if (!$module) {
            return;
        }

        $params = $this->buildParams();
        $this->resolveModuleVariables($params, $item);
        $this->context->smarty->assign($module->getWidgetVariables('', $params));
        $item->setContent($this->context->smarty->fetch($item->getParam('t')));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function loadModule(EsiItem $item): ?Module
    {
        $module = Module::getInstanceByName($item->getParam('m'));
        if (!$module) {
            $item->setFailed();

            return null;
        }

        return $module;
    }

    private function buildParams(): array
    {
        return [
            'smarty' => $this->context->smarty,
            'cookie' => $this->context->cookie,
            'cart' => $this->context->cart,
        ];
    }

    private function resolveModuleVariables(array &$params, EsiItem $item): void
    {
        $mp = $item->getParam('mp');
        $tas = $item->getConf()->getTemplateArgs();

        if (!$mp || !$tas) {
            (new HookParamsResolver($this->context))->resolve($item, $params);

            return;
        }

        $keys = explode(',', $tas);
        $values = json_decode($mp, true) ?? [];
        $smarty = $params['smarty'];

        foreach ($keys as $i => $key) {
            if (!isset($values[$i])) {
                continue;
            }

            $parts = explode('.', trim($key));
            $val = $values[$i];

            if ($parts[0] === 'smarty') {
                if (empty($parts[2])) {
                    $smarty->assign($parts[1], $val);
                } else {
                    $existing = $smarty->getTemplateVars($parts[1]) ?: [];
                    $existing[$parts[2]] = $val;
                    $smarty->assign($parts[1], $existing);
                }
            } elseif (empty($parts[1])) {
                $params[$parts[0]] = $val;
            } else {
                $params[$parts[0]] = array_merge($params[$parts[0]] ?? [], [$parts[1] => $val]);
            }
        }

        (new HookParamsResolver($this->context))->resolve($item, $params);
    }

    private function output(string $content): void
    {
        // ESI sub-requests are render-one-block fragments; anything beyond
        // what this controller emits is pollution — PS display hooks, third
        // party modules (analytics, cookie banners, chat widgets), and the
        // standard shutdown pipeline all echo into the parent buffer and
        // LSWS would cache that bleed under the ESI URL, ballooning a
        // "cuerpo vacío" env block to tens of kilobytes and gating every
        // HIT on a full PHP bootstrap roundtrip.
        //
        // Unwind every buffer above the cache-capture callback (started in
        // DispatcherHookHandler/CacheManager) to drop whatever leaked in
        // before we got here, emit exactly the ESI content, then exit so
        // no subsequent hook or shutdown handler can append more bytes.
        $callbackLevel = defined('_LITESPEED_CALLBACK_LEVEL_') ? _LITESPEED_CALLBACK_LEVEL_ : 1;
        while (ob_get_level() > $callbackLevel) {
            ob_end_clean();
        }
        // Also wipe whatever already landed in the cache-capture buffer
        // itself (PS init/setMedia/initContent + early display hooks run
        // before this controller's display() and their output ends up
        // here). Without this, the callback still captures ~13 KB of
        // pre-display pollution per env sub-request.
        if (ob_get_level() >= $callbackLevel) {
            ob_clean();
        }
        echo $content;
        exit;
    }
}
