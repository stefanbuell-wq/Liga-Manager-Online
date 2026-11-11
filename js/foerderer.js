/**
 * Sponsoren-Banner: Header (728x90 / mobil 320x50), links/rechts (160x600)
 *
 * - Mehrere Banner je Position rotieren alle 9 Sekunden (Gewichtung beachtet).
 * - Seitenbanner erscheinen nur auf breiten Bildschirmen (>= 1780px); darunter
 *   wandern sie in einen Inhalts-Slot (Kompakt-Bild 300x250, falls vorhanden).
 *   Der Slot ist ein Element mit data-fd-slot="inline", sonst vor dem Footer.
 * - Sichtkontakt = Banner war mind. 1 s zu mind. 50 % sichtbar bei aktivem Tab.
 *
 * Einbinden: <script src="js/foerderer.js" defer></script>
 */
(function () {
    'use strict';

    var script = document.currentScript;
    var BASE = script ? script.src.replace(/js\/foerderer\.js.*$/, '') : '';
    var API = BASE + 'api/foerderer.php';
    var ROTATE_MS = 9000;
    var SEEN_MS = 1000;
    var WIDE = '(min-width: 1780px) and (min-height: 720px)';

    var css = '' +
        '.fd-top{background:var(--color-bg-elevated,#fafafa);border-bottom:1px solid var(--border-color,rgba(0,0,0,.08));padding:10px 0}' +
        '.fd-box{position:relative;margin:0 auto;line-height:0;text-align:center}' +
        '.fd-box a,.fd-box span.fd-img{display:inline-block;line-height:0}' +
        '.fd-box img{max-width:100%;height:auto;border:0}' +
        '.fd-top .fd-box{max-width:728px}' +
        '.fd-lbl{display:block;font:500 10px/1.4 var(--font-primary,sans-serif);letter-spacing:.06em;text-transform:uppercase;color:var(--color-text-muted,#767676);margin-bottom:3px;text-align:center}' +
        '.fd-side{display:none;position:fixed;top:96px;width:160px;z-index:50}' +
        '.fd-l{left:calc(50% - 884px)}.fd-r{right:calc(50% - 884px)}' +
        '.fd-inline{padding:24px 16px;text-align:center}' +
        '.fd-inline .fd-row{display:flex;flex-wrap:wrap;gap:16px;justify-content:center;align-items:flex-start}' +
        '.fd-fade{animation:fdIn .5s ease}' +
        '@keyframes fdIn{from{opacity:0}to{opacity:1}}' +
        '@media (prefers-reduced-motion:reduce){.fd-fade{animation:none}}' +
        '@media ' + WIDE + '{.fd-side{display:block}.fd-inline{display:none}}';

    function el(tag, cls) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        return e;
    }

    /* ---------- Zaehlung ---------- */
    var pending = {};
    var hasPending = false;

    function record(id) {
        pending[id] = (pending[id] || 0) + 1;
        hasPending = true;
    }

    function flush() {
        if (!hasPending) return;
        var payload = JSON.stringify({ action: 'seen', v: pending });
        pending = {};
        hasPending = false;
        try {
            if (navigator.sendBeacon) {
                navigator.sendBeacon(API, new Blob([payload], { type: 'text/plain' }));
                return;
            }
        } catch (e) { /* weiter mit fetch */ }
        fetch(API, { method: 'POST', body: payload, keepalive: true, credentials: 'omit' }).catch(function () {});
    }

    setInterval(flush, 30000);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') flush();
    });
    window.addEventListener('pagehide', flush);

    /* ---------- Rotator ---------- */
    function pickNext(items, current) {
        if (items.length === 1) return 0;
        var total = 0, i;
        for (i = 0; i < items.length; i++) if (i !== current) total += items[i].weight;
        var r = Math.random() * total;
        for (i = 0; i < items.length; i++) {
            if (i === current) continue;
            r -= items[i].weight;
            if (r < 0) return i;
        }
        return current === 0 ? 1 : 0;
    }

    /**
     * @param {HTMLElement} box   Container, in den das Banner gerendert wird
     * @param {Array} items       Banner
     * @param {Function} render   (item) => Element
     */
    function Rotator(box, items, render) {
        var self = this;
        this.box = box;
        this.items = items;
        this.render = render;
        this.index = pickNext(items, -1);
        this.visible = false;
        this.seenTimer = null;
        this.counted = false;

        this.show(false);

        if ('IntersectionObserver' in window) {
            new IntersectionObserver(function (entries) {
                self.visible = entries[0].isIntersecting && entries[0].intersectionRatio >= 0.5;
                self.armSeen();
            }, { threshold: [0, 0.5, 1] }).observe(box);
        }
        document.addEventListener('visibilitychange', function () { self.armSeen(); });

        if (items.length > 1) {
            setInterval(function () {
                if (document.visibilityState !== 'visible' || !self.visible) return;
                self.index = pickNext(self.items, self.index);
                self.show(true);
            }, ROTATE_MS);
        }
    }

    Rotator.prototype.show = function (fade) {
        var node = this.render(this.items[this.index]);
        if (fade) node.classList.add('fd-fade');
        this.box.replaceChildren(node);
        this.counted = false;
        this.armSeen();
    };

    Rotator.prototype.armSeen = function () {
        var self = this;
        clearTimeout(this.seenTimer);
        if (this.counted || !this.visible || document.visibilityState !== 'visible') return;
        var shownIndex = this.index;
        this.seenTimer = setTimeout(function () {
            if (self.index === shownIndex && self.visible && document.visibilityState === 'visible' && !self.counted) {
                self.counted = true;
                record(self.items[shownIndex].id);
            }
        }, SEEN_MS);
    };

    /* ---------- Darstellung ---------- */
    function wrapLink(item, inner) {
        if (!item.link) {
            var s = el('span', 'fd-img');
            s.appendChild(inner);
            return s;
        }
        var a = el('a');
        a.href = API + '?go=' + encodeURIComponent(item.id);
        a.target = '_blank';
        a.rel = 'sponsored noopener';
        a.title = item.alt;
        a.appendChild(inner);
        return a;
    }

    function img(src, w, h, alt) {
        var i = el('img');
        i.src = src;
        i.width = w;
        i.height = h;
        i.alt = alt;
        i.decoding = 'async';
        return i;
    }

    function renderTop(item) {
        var pic = el('picture');
        if (item.imgAlt) {
            var source = el('source');
            source.media = '(max-width: 767px)';
            source.srcset = item.imgAlt;
            pic.appendChild(source);
        }
        pic.appendChild(img(item.img, 728, 90, item.alt));
        return wrapLink(item, pic);
    }

    function renderSide(item) {
        return wrapLink(item, img(item.img, 160, 600, item.alt));
    }

    function renderInline(item) {
        return item.imgAlt
            ? wrapLink(item, img(item.imgAlt, 300, 250, item.alt))
            : wrapLink(item, img(item.img, 160, 600, item.alt));
    }

    function slot(cls) {
        var wrap = el('div', cls);
        wrap.setAttribute('role', 'complementary');
        wrap.setAttribute('aria-label', 'Sponsor');
        var lbl = el('span', 'fd-lbl');
        lbl.textContent = 'Sponsor';
        wrap.appendChild(lbl);
        var box = el('div', 'fd-box');
        wrap.appendChild(box);
        return { wrap: wrap, box: box };
    }

    function mount(data) {
        var style = el('style');
        style.textContent = css;
        document.head.appendChild(style);

        // Header
        if (data.top && data.top.length) {
            var t = slot('fd-top');
            var header = document.querySelector('body > header, header.header');
            if (header && header.parentNode) header.parentNode.insertBefore(t.wrap, header.nextSibling);
            else document.body.insertBefore(t.wrap, document.body.firstChild);
            new Rotator(t.box, data.top, renderTop);
        }

        // Seiten (breite Bildschirme)
        [['left', 'fd-side fd-l'], ['right', 'fd-side fd-r']].forEach(function (p) {
            var items = data[p[0]];
            if (!items || !items.length) return;
            var s = slot(p[1]);
            document.body.appendChild(s.wrap);
            new Rotator(s.box, items, renderSide);
        });

        // Inhalts-Slot (schmale Bildschirme): links und rechts nebeneinander
        var side = [data.left || [], data.right || []].filter(function (l) { return l.length; });
        if (side.length) {
            var target = document.querySelector('[data-fd-slot="inline"]');
            var inline = el('div', 'fd-inline');
            var row = el('div', 'fd-row');
            inline.appendChild(row);
            side.forEach(function (items) {
                var s = slot('fd-cell');
                row.appendChild(s.wrap);
                new Rotator(s.box, items, renderInline);
            });
            if (target) {
                target.appendChild(inline);
            } else {
                var footer = document.querySelector('footer.footer, body > footer');
                if (footer && footer.parentNode) footer.parentNode.insertBefore(inline, footer);
                else document.body.appendChild(inline);
            }
        }
    }

    function start() {
        fetch(API, { credentials: 'omit' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (res) { if (res && res.success && res.data) mount(res.data); })
            .catch(function () {});
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
