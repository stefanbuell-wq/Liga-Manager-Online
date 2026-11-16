/**
 * Hell/Dunkel-Umschalter fuer die oeffentlichen Seiten.
 * Im <head> (ohne defer) einbinden, damit die Seite nicht erst hell aufblitzt.
 * Ohne eigene Wahl folgt die Seite der Einstellung des Geraets (prefers-color-scheme);
 * die Wahl per Knopf wird im Browser gemerkt (localStorage 'hafo_color_mode').
 */
(function () {
    var KEY = 'hafo_color_mode';
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function stored() {
        try { var v = localStorage.getItem(KEY); return v === 'dark' || v === 'light' ? v : null; } catch (e) { return null; }
    }
    function current() {
        return stored() || (media && media.matches ? 'dark' : 'light');
    }
    function apply(mode) {
        document.documentElement.setAttribute('data-theme', mode);
        var label = mode === 'dark' ? 'Helles Design' : 'Dunkles Design';
        var icon = mode === 'dark' ? '☀️' : '🌙';
        var btns = document.querySelectorAll('.theme-toggle');
        for (var i = 0; i < btns.length; i++) {
            btns[i].textContent = icon;
            btns[i].title = label;
            btns[i].setAttribute('aria-label', label);
        }
        var links = document.querySelectorAll('.theme-toggle-link');
        for (var j = 0; j < links.length; j++) links[j].textContent = icon + ' ' + label;
    }

    apply(current());
    if (media) {
        var onChange = function () { if (!stored()) apply(current()); };
        if (media.addEventListener) media.addEventListener('change', onChange); else if (media.addListener) media.addListener(onChange);
    }

    window.toggleColorMode = function () {
        var next = current() === 'dark' ? 'light' : 'dark';
        try {
            // Entspricht die Wahl der Geraete-Einstellung, nichts festschreiben (folgt dann wieder dem Geraet)
            if ((media && media.matches ? 'dark' : 'light') === next) localStorage.removeItem(KEY); else localStorage.setItem(KEY, next);
        } catch (e) {}
        apply(next);
    };

    document.addEventListener('DOMContentLoaded', function () {
        var host = document.querySelector('.header .header-actions');
        if (host && !host.querySelector('.theme-toggle')) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'theme-toggle';
            b.onclick = window.toggleColorMode;
            host.insertBefore(b, host.firstChild);
        }
        // Handy: Eintrag im Menue (in der Kopfzeile ist dort kein Platz)
        var nav = document.querySelector('.mobile-nav');
        if (nav && !nav.querySelector('.theme-toggle-link')) {
            var l = document.createElement('button');
            l.type = 'button';
            l.className = 'mobile-nav-link theme-toggle-link';
            l.onclick = window.toggleColorMode;
            nav.appendChild(l);
            var hb = document.querySelector('.header .theme-toggle');
            if (hb) hb.className += ' has-menu-twin';
        }
        apply(current());
    });
})();
