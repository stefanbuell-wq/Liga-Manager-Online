/**
 * Live-Ticker auf allen oeffentlichen Seiten:
 *  - roter LIVE-Streifen oben, sobald ein Spiel getickert wird
 *  - Kasten #live-box (Startseite) mit den laufenden Tickern
 *  - LIVE-Markierung an Spielen in Listen (.mrow[data-mid])
 * Fragt api/live.php?action=active alle 30 s ab; ist der Ticker ausgeschaltet (404), passiert nichts.
 */
(function () {
    'use strict';
    var base = (function () {
        var s = document.currentScript && document.currentScript.src;
        return s ? s.replace(/js\/live\.js.*$/, '') : '';
    })();
    var API = base + 'api/live.php?action=active';
    var data = [], timer = null, stopped = false;
    var PHASE = { pre: 'gleich', first: '1. Halbzeit', half: 'Halbzeit', second: '2. Halbzeit', end: 'Abpfiff' };

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function score(m) { return m.score ? m.score[0] + ':' + m.score[1] : '–:–'; }
    function link(m) { return base + 'live.html?spiel=' + m.id; }

    var css = '@keyframes lmoPulse{0%,100%{opacity:1}50%{opacity:.35}}'
        + '#lmo-live-bar{position:sticky;top:0;z-index:150;background:#e2001a;color:#fff;font:600 14px/1.3 system-ui,sans-serif;display:flex;align-items:center;gap:14px;padding:8px 16px;overflow-x:auto;white-space:nowrap;box-shadow:0 2px 8px rgba(0,0,0,.25)}'
        + 'body.lmo-live-on .header{top:var(--lmo-live-h,38px)}'
        + '#lmo-live-bar a{color:#fff;text-decoration:none}#lmo-live-bar a:hover{text-decoration:underline}'
        + '.lmo-live-dot{display:inline-block;width:10px;height:10px;border-radius:50%;background:#fff;margin-right:6px;animation:lmoPulse 1.2s infinite;vertical-align:middle}'
        + '#lmo-live-bar .lbl{font-weight:900;letter-spacing:.12em}#lmo-live-bar .g{background:rgba(0,0,0,.18);border-radius:999px;padding:3px 10px}'
        + '#lmo-live-bar .sc{font-weight:900;margin:0 4px}'
        + '.lmo-live-box{border:2px solid #e2001a;border-radius:16px;padding:14px 16px;margin:16px 0;background:var(--color-bg-card,#fff)}'
        + '.lmo-live-box h2{margin:0 0 10px;font-size:1.2rem;color:#e2001a}.lmo-live-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px}'
        + '.lmo-live-card{display:block;border:1px solid var(--border-color,#ddd);border-radius:12px;padding:10px 12px;text-decoration:none;color:inherit}'
        + '.lmo-live-card:hover{border-color:#e2001a}.lmo-live-card .lg{font-size:.78rem;color:var(--color-text-muted,#777)}'
        + '.lmo-live-card .row{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:4px;font-weight:700}'
        + '.lmo-live-card .sc{font-size:1.3rem;font-weight:900;color:#e2001a;white-space:nowrap}.lmo-live-card .ph{font-size:.8rem;color:#e2001a;font-weight:700}'
        + '.lmo-live-tag{display:inline-flex;align-items:center;background:#e2001a;color:#fff;border-radius:999px;padding:1px 8px;font-size:.72rem;font-weight:800;text-decoration:none;margin-left:6px;white-space:nowrap}'
        + '.lmo-live-tag .lmo-live-dot{width:7px;height:7px;margin-right:4px}';

    function injectCss() {
        if (document.getElementById('lmo-live-css')) return;
        var st = document.createElement('style'); st.id = 'lmo-live-css'; st.textContent = css; document.head.appendChild(st);
    }

    function renderBar() {
        var bar = document.getElementById('lmo-live-bar');
        var onLivePage = /live\.html/.test(location.pathname) || document.body.dataset.livePage === '1';
        if (!data.length || onLivePage) { if (bar) bar.remove(); document.body.classList.remove('lmo-live-on'); return; }
        if (!bar) {
            bar = document.createElement('div'); bar.id = 'lmo-live-bar'; bar.setAttribute('role', 'status');
            document.body.insertBefore(bar, document.body.firstChild);
        }
        bar.innerHTML = '<a href="' + base + 'live.html" class="lbl"><span class="lmo-live-dot"></span>LIVE</a>'
            + data.map(function (m) {
                return '<a class="g" href="' + link(m) + '">' + esc(m.home) + '<span class="sc">' + score(m) + '</span>' + esc(m.guest) + '</a>';
            }).join('');
        // Kopfzeile der Seite bleibt beim Scrollen unter dem Streifen sichtbar
        document.body.classList.add('lmo-live-on');
        document.documentElement.style.setProperty('--lmo-live-h', bar.offsetHeight + 'px');
    }

    function renderBox() {
        var box = document.getElementById('live-box');
        if (!box) return;
        if (!data.length) { box.hidden = true; box.innerHTML = ''; return; }
        box.hidden = false;
        box.className = 'lmo-live-box';
        box.innerHTML = '<h2><span class="lmo-live-dot" style="background:#e2001a"></span>Jetzt live getickert</h2><div class="lmo-live-cards">'
            + data.map(function (m) {
                return '<a class="lmo-live-card" href="' + link(m) + '"><div class="lg">' + esc(m.league) + (m.time ? ' · ' + esc(m.time.slice(0, 5)) + ' Uhr' : '') + '</div>'
                    + '<div class="row"><span>' + esc(m.home) + '</span><span class="sc">' + score(m) + '</span><span style="text-align:right">' + esc(m.guest) + '</span></div>'
                    + '<div class="ph">● ' + esc(PHASE[m.phase] || '') + (m.reporters ? ' · ' + m.reporters + ' vor Ort' : '') + '</div></a>';
            }).join('') + '</div>';
    }

    function decorate() {
        var byId = {};
        data.forEach(function (m) { byId[m.id] = m; });
        var rows = document.querySelectorAll('[data-mid]');
        for (var i = 0; i < rows.length; i++) {
            var el = rows[i], m = byId[el.getAttribute('data-mid')], tag = el.querySelector('.lmo-live-tag');
            if (!m) { if (tag) tag.remove(); continue; }
            if (!tag) {
                tag = document.createElement('a'); tag.className = 'lmo-live-tag';
                (el.querySelector('.mrow-meta') || el).appendChild(tag);
            }
            var html = '<span class="lmo-live-dot"></span>LIVE ' + score(m);
            if (tag.getAttribute('href') !== link(m)) tag.setAttribute('href', link(m));
            if (tag.innerHTML !== html) tag.innerHTML = html;
        }
    }

    function renderAll() { injectCss(); renderBar(); renderBox(); decorate(); }

    async function poll() {
        if (stopped) return;
        if (document.hidden) { schedule(); return; }
        try {
            var r = await fetch(API, { credentials: 'omit' });
            if (r.status === 404) { stopped = true; data = []; renderAll(); return; }
            var d = await r.json();
            data = d.success ? d.data : [];
            renderAll();
        } catch (e) { /* naechster Versuch */ }
        schedule();
    }
    function schedule() { clearTimeout(timer); timer = setTimeout(poll, 30000); }

    // neue Listen (Spieltag gewechselt) sofort markieren
    var pending = null;
    new MutationObserver(function () {
        if (!data.length || pending) return;
        pending = setTimeout(function () { pending = null; decorate(); }, 150);
    }).observe(document.documentElement, { childList: true, subtree: true });
    document.addEventListener('visibilitychange', function () { if (!document.hidden && !stopped) poll(); });

    window.LmoLive = { refresh: poll, get data() { return data; } };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', poll); else poll();
})();
