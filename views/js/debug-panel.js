/**
 * LiteSpeed Cache — Debug Panel
 * Fetches data via AJAX, populates the panel, handles toggle & collapsible sections.
 */
(function () {
  'use strict';

  var KEY = 'lsc_debug_open';
  var SEC_KEY = 'lsc_debug_sections';
  var panel = document.getElementById('lsc-debug-panel');
  var tab = document.getElementById('lsc-debug-tab');
  var body = panel ? panel.querySelector('.dbg-body') : null;
  if (!panel || !tab || !body) return;

  /* ---- Panel toggle ---- */
  function setState(open) {
    panel.style.transform = open ? 'translateY(0)' : 'translateY(100%)';
    tab.style.display = open ? 'none' : 'flex';
    try { localStorage.setItem(KEY, open ? '1' : '0'); } catch (e) {}
  }
  window._lscToggleDebug = function () { setState(tab.style.display !== 'none'); };
  var saved = '1';
  try { saved = localStorage.getItem(KEY); } catch (e) {}
  setState(saved !== '0');

  /* ---- Section state ---- */
  function getSecState() {
    try { var r = localStorage.getItem(SEC_KEY); return r ? JSON.parse(r) : {}; } catch (e) { return {}; }
  }
  function saveSecState(s) {
    try { localStorage.setItem(SEC_KEY, JSON.stringify(s)); } catch (e) {}
  }

  /* ---- HTML helpers ---- */
  function esc(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
  }
  function item(label, valueHtml) {
    return '<li class="list-group-item"><span class="dbg-label">' + label + '</span><span class="text-monospace">' + valueHtml + '</span></li>';
  }
  function badge(text, cls) {
    return '<span class="badge ' + cls + '">' + esc(text) + '</span>';
  }
  function onOff(v) {
    return v ? badge('ON', 'badge-success') : badge('OFF', 'badge-secondary');
  }
  function secHtml(title, id) {
    return '<div class="dbg-section" data-sec="' + id + '"><span>' + title + '</span><i class="material-icons chevron" aria-hidden="true" style="font-size:16px">keyboard_arrow_down</i></div>'
      + '<div class="dbg-sec-body" id="' + id + '"><ul class="list-group">';
  }
  function secEnd() { return '</ul></div>'; }
  function msBadge(v) {
    if (!v || v <= 0) return '<span class="dbg-label">-</span>';
    var cls = v < 200 ? 'badge-success' : v < 500 ? 'badge-warning' : 'badge-danger';
    return badge(Math.round(v) + ' MS', cls);
  }
  function fmtBytes(b) {
    if (b === null || b === undefined) return '-';
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
    if (b < 1073741824) return (b / 1048576).toFixed(1) + ' MB';
    return (b / 1073741824).toFixed(1) + ' GB';
  }
  function fmtTtl(s) {
    if (!s) return badge('OFF', 'badge-secondary');
    if (s < 60) return s + 's';
    if (s < 3600) return Math.round(s / 60) + 'm';
    if (s < 86400) return (s / 3600).toFixed(1) + 'h';
    return (s / 86400).toFixed(1) + 'd';
  }
  function logBadge(bytes) {
    if (bytes === null) return '<span class="dbg-label">-</span>';
    var cls = bytes > 52428800 ? 'badge-danger' : bytes > 10485760 ? 'badge-warning' : 'badge-success';
    return badge(fmtBytes(bytes), cls);
  }

  /* ---- Init sections (collapse/expand with persistence) ---- */
  function initSections() {
    var state = getSecState();
    var headers = panel.querySelectorAll('.dbg-section[data-sec]');
    for (var i = 0; i < headers.length; i++) {
      (function (hdr) {
        var id = hdr.getAttribute('data-sec');
        var bdy = document.getElementById(id);
        if (!bdy) return;
        var isOpen = state[id] === true;
        bdy.style.display = isOpen ? '' : 'none';
        if (!isOpen) hdr.classList.add('collapsed');
        hdr.addEventListener('click', function () {
          var open = bdy.style.display === 'none';
          bdy.style.display = open ? '' : 'none';
          open ? hdr.classList.remove('collapsed') : hdr.classList.add('collapsed');
          var s = getSecState();
          s[id] = open;
          saveSecState(s);
        });
      })(headers[i]);
    }
  }

  /* ---- Render server-side data ---- */
  function renderData(d) {
    var h = '';
    var c = d.cache || {};

    // Cache status (no section header, always visible)
    h += '<ul class="list-group">';
    h += item('ENTITY', esc(detectEntity()));
    var st = c.status || 'UNKNOWN';
    var stCls = st === 'HIT' ? 'badge-success' : st === 'MISS' ? 'badge-warning' : st === 'CACHEABLE' ? 'badge-success' : 'badge-danger';
    h += item('LITESPEED', badge(st, stCls));
    h += item('ESI', onOff(c.esi));
    h += item('GUEST', onOff(c.guest));
    h += item('CDN', c.cdn ? badge('ON', 'badge-info') : badge('OFF', 'badge-secondary'));
    h += '</ul>';

    // TTL
    var t = d.ttl || {};
    h += secHtml('TTL', 'sec-ttl');
    h += item('PUBLIC', fmtTtl(t.public));
    h += item('PRIVATE', fmtTtl(t.private));
    h += item('HOME', fmtTtl(t.home));
    if (t.mobile_separate) h += item('MOBILE', badge('SEPARATE', 'badge-info'));
    h += secEnd();

    // Redis
    var r = d.redis || {};
    h += secHtml('REDIS', 'sec-redis');
    var rCls = r.status === 'CONNECTED' ? 'badge-success' : r.status === 'ERROR' ? 'badge-danger' : 'badge-secondary';
    h += item('STATUS', badge(r.status || 'OFF', rCls));
    if (r.info) {
      h += item('VERSION', esc(r.info.version));
      h += item('MEMORY', esc(r.info.memory));
      h += item('HIT RATE', esc(r.info.hit_rate));
    }
    if (r.page_keys && r.page_keys.tables) {
      h += item('CACHED QUERIES', esc(String(r.page_keys.queries || 0)));
      var tables = r.page_keys.tables;
      var tkeys = Object.keys(tables);
      for (var ti = 0; ti < tkeys.length; ti++) {
        h += item(esc(tkeys[ti]), '<span class="badge badge-info">' + tables[tkeys[ti]] + '</span>');
      }
    }
    h += secEnd();

    // LiteSpeed Headers placeholder
    h += '<div id="lsc-debug-lsheaders"></div>';

    // Server
    var sv = d.server || {};
    h += secHtml('SERVER', 'sec-server');
    h += item('SOFTWARE', esc(sv.software || '-'));
    h += item('PHP', esc(sv.php || '-'));
    h += item('PRESTASHOP', esc(sv.prestashop || '-'));
    h += item('SAMPLED AT', '<span style="color:#6c868e">' + esc(sv.sampled_at || '-') + '</span>');
    h += secEnd();

    // Performance
    h += secHtml('PERFORMANCE', 'sec-perf');
    h += '</ul><div id="lsc-debug-perf" style="padding:6px 14px;color:#6c868e">measuring...</div>' + secEnd().replace('<ul class="list-group">', '');

    // Page
    h += secHtml('PAGE', 'sec-page');
    h += '</ul><div id="lsc-debug-page" style="padding:6px 14px;color:#6c868e">measuring...</div>' + secEnd().replace('<ul class="list-group">', '');

    // Logs
    var logs = d.logs || {};
    h += secHtml('LOGS FILESIZE', 'sec-logs');
    var logKeys = Object.keys(logs);
    for (var i = 0; i < logKeys.length; i++) {
      h += item(logKeys[i].toUpperCase(), logBadge(logs[logKeys[i]]));
    }
    h += secEnd();

    body.innerHTML = h;
    initSections();
    renderLsHeaders(d.ls_headers || {});
    measurePerformance();
  }

  /* ---- Render LiteSpeed headers from server-side probe ---- */
  function renderLsHeaders(hdrs) {
    var el = document.getElementById('lsc-debug-lsheaders');
    if (!el || !hdrs) return;

    var keys = Object.keys(hdrs);
    if (!keys.length) return;

    var o = '';
    for (var i = 0; i < keys.length; i++) {
      var key = keys[i];
      var v = hdrs[key];
      var name = key.replace('x-litespeed-', '').replace('x-lscache-debug-', '');
      var fv;

      if (key === 'x-litespeed-cache') {
        var u = v.toUpperCase();
        fv = badge(u, u === 'HIT' ? 'badge-success' : u === 'MISS' ? 'badge-warning' : 'badge-danger');
      } else if (key.indexOf('tag') > -1) {
        var tags = v.split(','); fv = '';
        for (var j = 0; j < tags.length; j++) fv += '<span class="badge badge-tag">' + tags[j].trim() + '</span>';
      } else if (key.indexOf('vary') > -1) {
        o += '<li class="list-group-item" style="display:block;padding:6px 14px"><div class="dbg-label" style="margin-bottom:4px">' + name + '</div><div style="color:#fff;font-size:11px">' + fmtVary(v) + '</div></li>';
        continue;
      } else {
        fv = '<span style="word-break:break-all">' + esc(v) + '</span>';
      }
      o += item(name, fv);
    }

    if (o) {
      el.innerHTML = secHtml('LITESPEED HEADERS', 'sec-lshdrs') + o + secEnd();
      var hdr = el.querySelector('.dbg-section[data-sec]');
      if (hdr) {
        var state = getSecState();
        var id = hdr.getAttribute('data-sec');
        var bdy = document.getElementById(id);
        if (bdy) {
          var isOpen = state[id] === true;
          bdy.style.display = isOpen ? '' : 'none';
          if (!isOpen) hdr.classList.add('collapsed');
          hdr.addEventListener('click', function () {
            var open = bdy.style.display === 'none';
            bdy.style.display = open ? '' : 'none';
            open ? hdr.classList.remove('collapsed') : hdr.classList.add('collapsed');
            var s = getSecState(); s[id] = open; saveSecState(s);
          });
        }
      }
    }
  }

  function fmtVary(v) {
    var p = v.indexOf('{');
    if (p < 0) return esc(v);
    try {
      var status = v.substring(0, p).trim();
      var j = JSON.parse(v.substring(p));
      var r = '<span style="color:#70b580;font-weight:700">' + esc(status) + '</span>';
      var cv = j.cv || {};
      r += '<div class="vary-box"><div class="vary-label">Cookie Vary</div>';
      r += '<div><span class="vary-dim">name:</span> ' + esc(cv.name || '-') + '</div>';
      r += '<div><span class="vary-dim">value:</span> ' + esc(cv.nv || cv.ov || '-') + '</div>';
      if (cv.data) { var dk = Object.keys(cv.data); for (var k = 0; k < dk.length; k++) r += '<span class="badge badge-tag">' + esc(dk[k]) + '=' + esc(String(cv.data[dk[k]])) + '</span>'; }
      r += '</div>';
      var vv = j.vv || {};
      r += '<div class="vary-box"><div class="vary-label">Vary Value</div>';
      r += '<div><span class="vary-dim">original:</span> ' + esc(vv.ov || 'null') + '</div>';
      r += '<div><span class="vary-dim">new:</span> ' + esc(vv.nv || 'null') + '</div></div>';
      var ps = j.ps || {};
      r += '<div class="vary-box"><div class="vary-label">PS Session</div>';
      r += '<div><span class="vary-dim">original:</span> <span style="word-break:break-all">' + esc(ps.ov || 'null') + '</span></div>';
      r += '<div><span class="vary-dim">new:</span> <span style="word-break:break-all">' + esc(ps.nv || 'null') + '</span></div></div>';
      return r;
    } catch (e) { return esc(v); }
  }

  /* ---- Performance metrics ---- */
  function measurePerformance() {
    var run = function () {
      setTimeout(function () {
        var perfEl = document.getElementById('lsc-debug-perf');
        var pageEl = document.getElementById('lsc-debug-page');
        if (!perfEl) return;

        var nav = performance.getEntriesByType('navigation')[0];
        var paints = performance.getEntriesByType('paint');
        var fcp = 0;
        for (var i = 0; i < paints.length; i++) {
          if (paints[i].name === 'first-contentful-paint') fcp = paints[i].startTime;
        }

        var h = '<ul class="list-group">';
        if (nav) {
          h += item('TTFB', msBadge(nav.responseStart - nav.requestStart));
          h += item('TTFB (FULL)', msBadge(nav.responseStart));
        }
        if (fcp) h += item('FCP', msBadge(fcp));
        try {
          var lcp = performance.getEntriesByType('largest-contentful-paint');
          if (lcp && lcp.length) h += item('LCP', msBadge(lcp[lcp.length - 1].startTime));
        } catch (e) {}
        if (nav) {
          h += item('DOM READY', msBadge(nav.domContentLoadedEventEnd));
          h += item('LOAD', msBadge(nav.loadEventEnd));
          h += item('INTERACTIVE', msBadge(nav.domInteractive));
        }
        h += '</ul>';
        if (nav) {
          h += '<div class="perf-detail">'
            + '<span>DNS ' + Math.round(nav.domainLookupEnd - nav.domainLookupStart) + 'ms</span>'
            + '<span>TCP ' + Math.round(nav.connectEnd - nav.connectStart) + 'ms</span>'
            + '<span>SSL ' + Math.round(nav.secureConnectionStart > 0 ? nav.connectEnd - nav.secureConnectionStart : 0) + 'ms</span></div>';
          h += '<ul class="list-group">' + item('DOWNLOAD', Math.round(nav.responseEnd - nav.responseStart) + ' ms') + '</ul>';
        }
        perfEl.innerHTML = h;

        if (pageEl) {
          var res = performance.getEntriesByType('resource');
          var total = 0;
          for (var k = 0; k < res.length; k++) total += res[k].transferSize || 0;
          var doc = nav ? nav.transferSize || 0 : 0;
          pageEl.innerHTML = '<ul class="list-group">'
            + item('RESOURCES', res.length + ' files')
            + item('PAGE SIZE', fmtBytes(doc) + ' &rarr; ' + fmtBytes(total + doc))
            + '</ul>';
        }
      }, 300);
    };
    if (document.readyState === 'complete') {
      run();
    } else {
      window.addEventListener('load', run);
    }
  }

  /* ---- Detect entity from PS frontend context ---- */
  function detectEntity() {
    try {
      if (typeof prestashop !== 'undefined' && prestashop.page) {
        var p = prestashop.page;
        var name = p.page_name || '';
        var label = name || 'unknown';

        // Extract entity ID from body classes (works for core and custom controllers)
        var bc = p.body_classes || {};
        for (var cls in bc) {
          if (bc[cls] === true) {
            var m = cls.match(/^(\w+)-id-(\d+)$/);
            if (m) { label = name + ' #' + m[2]; break; }
          }
        }
        return label;
      }
    } catch (e) {}
    return document.body.id || '-';
  }

  /* ---- Fetch debug data ---- */
  var url = panel.getAttribute('data-url');
  if (!url) return;

  var sep = url.indexOf('?') > -1 ? '&' : '?';
  var keysToken = panel.getAttribute('data-keys-token') || '';
  var fullUrl = url + sep + 'page_url=' + encodeURIComponent(location.href)
    + (keysToken ? '&keys_token=' + encodeURIComponent(keysToken) : '');

  var xhr2 = new XMLHttpRequest();
  xhr2.open('GET', fullUrl, true);
  xhr2.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
  xhr2.onload = function () {
    try {
      var data = JSON.parse(xhr2.responseText);
      if (data.error) {
        body.innerHTML = '<div style="padding:20px 14px;color:#e84e6a">' + esc(data.error) + '</div>';
        return;
      }
      renderData(data);
    } catch (e) {
      body.innerHTML = '<div style="padding:20px 14px;color:#e84e6a">Failed to load debug data</div>';
    }
  };
  xhr2.onerror = function () {
    body.innerHTML = '<div style="padding:20px 14px;color:#e84e6a">Connection error</div>';
  };
  xhr2.send();
})();
