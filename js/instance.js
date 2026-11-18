/**
 * Instanz-Branding fuer die oeffentlichen Seiten: Name, Untertitel, Copyright, Seitentitel
 * und instanzabhaengige Texte. Quelle: api/theme.php (Design-Editor bzw. config/instance.php).
 *
 * Markup:
 *   <span data-site-logo></span>        Name als Logo; Endung (".de") in Primaerfarbe
 *   <span data-site-name></span>        Name als Text
 *   <span data-site-tagline></span>     Untertitel
 *   <p data-site-copyright></p>         "© Jahr Name – Untertitel"
 *   [data-site-region]                  Region (z. B. "Hamburger Amateurfußball"), Element mit
 *                                       data-region-empty wird ausgeblendet, wenn keine Region gesetzt ist
 *   [data-legacy-forum]                 nur sichtbar, wenn die Instanz ein Altforum uebernommen hat
 *   <title data-page="Forum">           Seitentitel wird zu "Forum - Name"; data-page="": "Name - Untertitel"; ohne Attribut unveraendert
 */
(function () {
    var KEY = 'hafo_theme';

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function logoHtml(name) {
        var m = /^(.+)(\.[A-Za-z]{2,})$/.exec(name);
        return m ? esc(m[1]) + '<span style="color: var(--color-primary);">' + esc(m[2]) + '</span>' : esc(name);
    }

    function each(sel, fn) {
        var list = document.querySelectorAll(sel);
        for (var i = 0; i < list.length; i++) fn(list[i]);
    }

    function apply(t) {
        if (!t || !t.siteName) return;
        var name = t.siteName, tagline = t.siteTagline || '', inst = t.instance || {};
        each('[data-site-logo]', function (el) { el.innerHTML = logoHtml(name); });
        each('[data-site-name]', function (el) { el.textContent = name; });
        each('[data-site-tagline]', function (el) { el.textContent = tagline; });
        each('[data-site-copyright]', function (el) {
            el.textContent = '© ' + new Date().getFullYear() + ' ' + name + (tagline ? ' - ' + tagline : '');
        });
        each('[data-site-region]', function (el) { el.textContent = inst.region || el.getAttribute('data-site-region') || ''; });
        each('[data-region-empty]', function (el) { el.hidden = !inst.region; });
        each('[data-legacy-forum]', function (el) { el.hidden = !inst.legacyForum; });
        // Zuschaltbare Funktionen (Admin -> Einstellungen): Links/Bereiche nur zeigen, wenn aktiv
        var feat = inst.features || {};
        each('[data-feature]', function (el) { el.hidden = !feat[el.getAttribute('data-feature')]; });
        var title = document.querySelector('title');
        if (title && title.hasAttribute('data-page')) {
            var page = title.getAttribute('data-page');
            document.title = page ? page + ' - ' + name : name + (tagline ? ' - ' + tagline : '');
        }
        if (inst.testBanner && !document.getElementById('lmo-test-banner')) {
            var bar = document.createElement('div');
            bar.id = 'lmo-test-banner';
            bar.setAttribute('role', 'status');
            bar.title = inst.testBanner;
            bar.setAttribute('aria-label', inst.testBanner);
            bar.style.cssText = 'position:fixed;left:0;top:0;bottom:0;width:22px;z-index:10000;background:#b00020;color:#fff;'
                + 'display:flex;align-items:center;justify-content:center;writing-mode:vertical-rl;transform:rotate(180deg);'
                + 'font:700 12px/1 system-ui,sans-serif;letter-spacing:.25em;text-transform:uppercase;cursor:help;user-select:none';
            bar.textContent = 'Testumgebung';
            document.body.appendChild(bar);
            document.body.style.paddingLeft = '22px';
        }
        window.LMO_INSTANCE = { siteName: name, siteTagline: tagline, region: inst.region || '', tipLabel: inst.tipLabel || 'Tipp', legacyForum: !!inst.legacyForum };
        try { document.dispatchEvent(new CustomEvent('lmo:instance', { detail: window.LMO_INSTANCE })); } catch (e) {}
    }

    var cached = null;
    try { cached = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) {}
    apply(cached);

    // Basis aus der eigenen Script-Adresse (funktioniert auch aus admin/)
    var base = (document.currentScript && document.currentScript.src) ? document.currentScript.src.replace(/js\/instance\.js.*$/, '') : '';
    fetch(base + 'api/theme.php').then(function (r) { return r.json(); }).then(function (t) {
        try { localStorage.setItem(KEY, JSON.stringify(t)); } catch (e) {}
        apply(t);
    }).catch(function () {});
})();
