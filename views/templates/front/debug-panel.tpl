{**
 * LiteSpeed Cache — Debug Panel (Smarty template)
 * Shell loaded in footer, data populated via AJAX.
 *}

{literal}
<style>
#lsc-debug-tab,#lsc-debug-panel,#lsc-debug-panel *,#lsc-debug-tab *{box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
#lsc-debug-panel .material-icons,#lsc-debug-tab .material-icons{font-family:'Material Icons'!important}
#lsc-debug-tab{position:fixed;bottom:20px;right:20px;z-index:100000;width:56px;height:56px;background:#282b30;border:none;border-radius:50%;cursor:pointer;display:none;align-items:center;justify-content:center;box-shadow:0 2px 12px rgba(0,0,0,.4)}
#lsc-debug-panel{position:fixed;bottom:0;right:0;z-index:100000;width:320px;height:100vh;background:#282b30;color:#bbcdd2;font-size:12px;line-height:1.5;border-left:2px solid #25b9d7;transition:transform .3s ease;display:flex;flex-direction:column;overflow:hidden}
#lsc-debug-panel .dbg-header{padding:10px 14px;display:flex;align-items:center;gap:8px;border-bottom:1px solid #434750;background:#363a41;flex-shrink:0}
#lsc-debug-panel .dbg-header img{width:22px;height:22px}
#lsc-debug-panel .dbg-header .title{color:#fff;font-weight:700;font-size:13px;letter-spacing:1px;flex:1}
#lsc-debug-panel .dbg-close{color:#6c868e;font-size:18px;cursor:pointer;line-height:1;border:none;background:none;padding:0 4px}
#lsc-debug-panel .dbg-close:hover{color:#fff}
#lsc-debug-panel .dbg-body{overflow-y:auto;flex:1}
#lsc-debug-panel .dbg-loader{display:flex;align-items:center;justify-content:center;padding:40px;color:#6c868e}
#lsc-debug-panel .dbg-loader .spinner-border{width:1.5rem;height:1.5rem;border-width:2px;margin-right:8px}
#lsc-debug-panel .dbg-section{background:#363a41;padding:8px 14px;border-bottom:1px solid #434750;color:#fff;font-weight:700;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;display:flex;justify-content:space-between;align-items:center;user-select:none}
#lsc-debug-panel .dbg-section .chevron{transition:transform .2s}
#lsc-debug-panel .dbg-section.collapsed .chevron{transform:rotate(-90deg)}
#lsc-debug-panel .list-group{margin:0;padding:0;list-style:none;border-radius:0}
#lsc-debug-panel .list-group-item{background:transparent;border:none;border-bottom:1px solid #363a41;padding:5px 14px;display:flex;justify-content:space-between;align-items:center;gap:8px;color:#bbcdd2;font-size:12px}
#lsc-debug-panel .list-group-item:last-child{border-bottom:none}
#lsc-debug-panel .list-group-item .dbg-label{color:#6c868e;font-size:11px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap}
#lsc-debug-panel .dbg-label{color:#6c868e}
#lsc-debug-panel .list-group-item .text-monospace{font-family:SFMono-Regular,Menlo,Monaco,Consolas,monospace;color:#fff;font-size:12px;text-align:right;word-break:break-all}
#lsc-debug-panel .badge{display:inline-block;padding:2px 10px;border-radius:10px;font-size:10px;font-weight:700;line-height:1.4;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;color:#fff}
#lsc-debug-panel .badge-success{background:#70b580}
#lsc-debug-panel .badge-danger{background:#e84e6a}
#lsc-debug-panel .badge-warning{background:#f0ad4e;color:#000}
#lsc-debug-panel .badge-info{background:#25b9d7}
#lsc-debug-panel .badge-secondary{background:#6c757d}
#lsc-debug-panel .badge-tag{background:#25b9d7;color:#000;padding:1px 6px;border-radius:3px;margin:1px;font-size:10px;font-weight:600}
#lsc-debug-panel .perf-detail{border-top:1px solid #363a41;padding:6px 14px;display:flex;gap:14px;font-size:10px;color:#6c868e}
#lsc-debug-panel .vary-box{margin:4px 0;padding:5px 8px;background:rgba(255,255,255,.05);border-radius:4px;font-size:10px;line-height:1.6}
#lsc-debug-panel .vary-label{color:#25b9d7;font-weight:700;margin-bottom:2px}
#lsc-debug-panel .vary-dim{color:#6c868e}
</style>
{/literal}

<div id="lsc-debug-tab" onclick="window._lscToggleDebug()">
  <img src="{$lsc_logo_url|escape:'htmlall':'UTF-8'}" style="width:30px;height:30px" alt="LSC">
</div>

<div id="lsc-debug-panel">
  <div class="dbg-header">
    <img src="{$lsc_logo_url|escape:'htmlall':'UTF-8'}" alt="LSC">
    <span class="title">LiteSpeed Cache Debug Bar</span>
    <button class="dbg-close" onclick="window._lscToggleDebug()"><i class="material-icons" aria-hidden="true">keyboard_arrow_down</i></button>
  </div>
  <div class="dbg-body">
    <div class="dbg-loader">
      <div class="spinner-border" role="status"></div>
      <span>Loading debug data...</span>
    </div>
  </div>
</div>

<script src="{$lsc_debug_js_url|escape:'htmlall':'UTF-8'}"></script>
