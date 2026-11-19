/**
 * Kommentare unter Artikeln: LmoComments.mount(element, newsId)
 * Nur aktiv, wenn die Zusatzfunktion eingeschaltet ist (api/comments.php antwortet sonst 404).
 */
(function () {
    'use strict';
    var base = (function () {
        var s = document.currentScript && document.currentScript.src;
        return s ? s.replace(/js\/comments\.js.*$/, '') : '';
    })();
    var API = base + 'api/comments.php';
    var csrf = '';

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function when(s) {
        var d = new Date(String(s).replace(' ', 'T') + 'Z');
        if (isNaN(d)) return '';
        return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ', ' + d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) + ' Uhr';
    }
    function avatar(path, name) {
        var ok = typeof path === 'string' && /^img\/(avatars\/(legacy\/)?[A-Za-z0-9_-]+\.(webp|jpg|png|gif)|teams\/(hd\/)?[A-Za-z0-9]+\.(gif|png))$/.test(path);
        return ok ? '<img src="' + base + path + '" alt="">' : esc(String(name || '?').trim().charAt(0).toUpperCase());
    }

    var css = '.lc{margin-top:32px;border-top:1px solid var(--border-color);padding-top:20px;text-align:left}'
        + '.lc h3{margin:0 0 12px;font-size:1.15rem}.lc .lc-note{color:var(--color-text-muted);font-size:.9rem}'
        + '.lc-item{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid var(--border-color)}'
        + '.lc-av{flex:0 0 36px;height:36px;border-radius:50%;background:var(--color-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;overflow:hidden}'
        + '.lc-av img{width:100%;height:100%;object-fit:cover;background:#fff}'
        + '.lc-body{flex:1;min-width:0}.lc-head{font-size:.85rem;color:var(--color-text-muted)}.lc-head b{color:var(--color-text-primary)}'
        + '.lc-text{white-space:pre-wrap;overflow-wrap:anywhere;margin-top:4px;line-height:1.55}'
        + '.lc-tools{margin-top:4px;display:flex;gap:12px;flex-wrap:wrap}.lc-tools button{background:none;border:0;padding:0;color:var(--color-text-muted);font-size:.8rem;cursor:pointer}'
        + '.lc-tools button:hover{color:var(--color-primary)}.lc-flag{display:inline-block;font-size:.72rem;font-weight:700;border-radius:4px;padding:1px 6px;margin-left:6px;background:#d4a017;color:#111}'
        + '.lc-flag.hidden{background:#777;color:#fff}.lc-flag.rep{background:#b00020;color:#fff}'
        + '.lc-form textarea{width:100%;min-height:90px;padding:10px;border:1px solid var(--border-color);border-radius:8px;background:var(--color-bg-card);color:var(--color-text-primary);font:inherit;box-sizing:border-box}'
        + '.lc-form .lc-row{display:flex;justify-content:space-between;align-items:center;margin-top:8px;gap:8px;flex-wrap:wrap}'
        + '.lc-btn{background:var(--color-primary);color:#fff;border:0;border-radius:8px;padding:9px 18px;font-weight:700;cursor:pointer}'
        + '.lc-mod{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:.85rem;background:rgba(0,0,0,.04);border-radius:8px;padding:8px 10px;margin-bottom:12px}'
        + '.lc-mod select{padding:4px 6px;border-radius:6px;border:1px solid var(--border-color);background:var(--color-bg-card);color:var(--color-text-primary)}'
        + '.lc-msg{font-weight:600;font-size:.9rem}.lc-msg.err{color:#b00020}.lc-msg.ok{color:#12805c}';

    function injectCss() {
        if (document.getElementById('lc-css')) return;
        var st = document.createElement('style'); st.id = 'lc-css'; st.textContent = css; document.head.appendChild(st);
    }

    async function req(method, params, body) {
        var url = API + (params ? '?' + new URLSearchParams(params) : '');
        var opt = { method: method, credentials: 'same-origin', headers: {} };
        if (body) {
            body.csrf_token = csrf;
            opt.headers['Content-Type'] = 'application/json';
            opt.headers['X-CSRF-Token'] = csrf;
            opt.body = JSON.stringify(body);
        }
        var r = await fetch(url, opt);
        var d = await r.json().catch(function () { return {}; });
        if (d.csrf_token) csrf = d.csrf_token;
        if (!d.success) { var e = new Error(d.error || 'Fehler'); e.status = r.status; throw e; }
        return d;
    }

    function mount(el, newsId) {
        if (!el) return;
        injectCss();
        el.innerHTML = '';
        el.className = 'lc';
        load(el, newsId);
    }

    async function load(el, newsId, msg) {
        var d;
        try { d = (await req('GET', { news: newsId })).data; }
        catch (e) { el.innerHTML = ''; el.hidden = true; return; } // ausgeschaltet oder Fehler: nichts zeigen
        el.hidden = false;
        if (d.mode === 'off' && !d.moderator) { el.innerHTML = ''; el.hidden = true; return; }

        var h = '<h3>💬 Kommentare' + (d.comments.length ? ' (' + d.comments.filter(function (c) { return c.status === 'visible'; }).length + ')' : '') + '</h3>';
        if (d.moderator) {
            h += '<div class="lc-mod">🛡️ Redaktion: Kommentare für diesen Artikel <select data-lc="mode">'
                + [['open', 'offen'], ['closed', 'geschlossen (alte sichtbar)'], ['off', 'ausgeblendet']].map(function (o) {
                    return '<option value="' + o[0] + '"' + (o[0] === d.mode ? ' selected' : '') + '>' + o[1] + '</option>';
                }).join('') + '</select>'
                + (d.global === 'off' ? ' <span class="lc-note">(Funktion ist unter Einstellungen ausgeschaltet – Besucher sehen nichts)</span>' : '')
                + (d.approval ? ' <span class="lc-note">· neue Kommentare brauchen Freigabe</span>' : '') + '</div>';
        }
        if (!d.comments.length) h += '<p class="lc-note">Noch keine Kommentare.</p>';
        d.comments.forEach(function (c) {
            var flags = '';
            if (c.status === 'pending') flags += '<span class="lc-flag">' + (d.moderator ? 'wartet auf Freigabe' : 'noch nicht freigegeben') + '</span>';
            if (c.status === 'hidden') flags += '<span class="lc-flag hidden">ausgeblendet</span>';
            if (c.reports) flags += '<span class="lc-flag rep">' + c.reports + '× gemeldet</span>';
            var tools = [];
            if (d.moderator) {
                if (c.status !== 'visible') tools.push('<button data-lc="approve" data-id="' + c.id + '">✓ Freigeben</button>');
                if (c.status !== 'hidden') tools.push('<button data-lc="hide" data-id="' + c.id + '">Ausblenden</button>');
                tools.push('<button data-lc="delete" data-id="' + c.id + '">Löschen</button>');
                if (!c.own) tools.push('<button data-lc="block" data-user="' + c.user_id + '">Nutzer sperren</button>');
            } else if (c.own) {
                tools.push('<button data-lc="delete" data-id="' + c.id + '">Löschen</button>');
            } else if (d.logged_in && c.status === 'visible') {
                tools.push('<button data-lc="report" data-id="' + c.id + '">Melden</button>');
            }
            h += '<div class="lc-item"><div class="lc-av">' + avatar(c.avatar, c.name) + '</div><div class="lc-body">'
                + '<div class="lc-head"><b>' + esc(c.name) + '</b> · ' + when(c.created_at) + flags + '</div>'
                + '<div class="lc-text">' + esc(c.content) + '</div>'
                + (tools.length ? '<div class="lc-tools">' + tools.join('') + '</div>' : '') + '</div></div>';
        });

        if (d.mode === 'closed') h += '<p class="lc-note" style="margin-top:12px">Für diesen Artikel sind die Kommentare geschlossen.</p>';
        else if (d.global === 'off' || d.mode === 'off') { /* Redaktion sieht nur die Liste */ }
        else if (!d.logged_in) h += '<p class="lc-note" style="margin-top:12px">Zum Kommentieren bitte <a href="' + base + 'konto.html">anmelden oder ein Konto anlegen</a>.</p>';
        else if (d.blocked) h += '<p class="lc-note" style="margin-top:12px">Du kannst zurzeit nicht kommentieren.</p>';
        else if (d.can_post) {
            h += '<form class="lc-form" style="margin-top:16px"><textarea maxlength="' + d.max_len + '" placeholder="Dein Kommentar …" required></textarea>'
                + '<div class="lc-row"><span class="lc-note">Bitte sachlich bleiben.' + (d.approval ? ' Kommentare erscheinen nach Freigabe durch die Redaktion.' : '') + '</span>'
                + '<button class="lc-btn" type="submit">Kommentieren</button></div></form>';
        }
        h += '<div class="lc-msg" aria-live="polite"></div>';
        el.innerHTML = h;
        if (msg) setMsg(el, msg[0], msg[1]);

        el.onsubmit = async function (ev) {
            ev.preventDefault();
            var ta = el.querySelector('textarea'); var btn = el.querySelector('.lc-btn');
            btn.disabled = true;
            try {
                var r = await req('POST', null, { action: 'add', news: newsId, content: ta.value });
                load(el, newsId, [r.message, false]);
            } catch (e) { setMsg(el, e.message, true); btn.disabled = false; }
        };
        el.onchange = async function (ev) {
            if (ev.target.dataset.lc !== 'mode') return;
            try { await req('POST', null, { action: 'article_mode', news: newsId, mode: ev.target.value }); load(el, newsId, ['Gespeichert.', false]); }
            catch (e) { setMsg(el, e.message, true); }
        };
        el.onclick = async function (ev) {
            var b = ev.target.closest('button[data-lc]'); if (!b) return;
            var a = b.dataset.lc, body = { action: a, id: +b.dataset.id };
            if (a === 'delete' && !confirm('Kommentar wirklich löschen?')) return;
            if (a === 'block') { if (!confirm('Diesen Nutzer für Kommentare sperren?')) return; body = { action: 'block', user: +b.dataset.user }; }
            if (a === 'report') { var why = prompt('Warum meldest du diesen Kommentar? (optional)'); if (why === null) return; body.reason = why; }
            try { var r = await req('POST', null, body); load(el, newsId, [r.message || 'Erledigt.', false]); }
            catch (e) { setMsg(el, e.message, true); }
        };
    }

    function setMsg(el, text, err) {
        var m = el.querySelector('.lc-msg'); if (!m) return;
        m.className = 'lc-msg ' + (err ? 'err' : 'ok'); m.textContent = text;
    }

    window.LmoComments = { mount: mount };
})();
