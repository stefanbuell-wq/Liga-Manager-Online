/**
 * Tabellenrechner ("Was wäre wenn?"): Besucher tragen Ergebnisse offener Spiele ein und sehen sofort
 * die neue Tabelle. Rechnet nur im Browser, speichert nichts.
 * Zuschaltbar unter Admin -> Einstellungen (instance.features.calculator, Element mit data-feature="calculator").
 *
 *   TableCalc.open(leagueData, containerElement)
 */
(function () {
    var state = null;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function logo(name) {
        return 'img/teams/' + String(name).replace(/[^a-zA-Z0-9]/g, '') + '.gif' + (window.LMO_CREST_V || '');
    }

    function compute(data, extra) {
        var rows = {};
        Object.keys(data.teams || {}).forEach(function (id) {
            var t = data.teams[id];
            rows[id] = { id: id, name: t.name, played: 0, won: 0, draw: 0, lost: 0, gf: 0, ga: 0, points: 0, correction: 0 };
        });
        (data.table || []).forEach(function (t) {
            Object.keys(rows).forEach(function (id) { if (rows[id].name === t.name) rows[id].correction = t.correction || 0; });
        });
        Object.keys(data.matches || {}).forEach(function (r) {
            data.matches[r].forEach(function (m) {
                var hg, gg, hypo = false;
                if (m.played) { hg = m.home_goals; gg = m.guest_goals; }
                else if (extra[m.id]) { hg = extra[m.id][0]; gg = extra[m.id][1]; hypo = true; }
                else return;
                [[m.home_id, hg, gg], [m.guest_id, gg, hg]].forEach(function (x) {
                    var s = rows[x[0]]; if (!s) return;
                    s.played++; s.gf += x[1]; s.ga += x[2];
                    if (x[1] > x[2]) { s.won++; s.points += 3; } else if (x[1] === x[2]) { s.draw++; s.points++; } else s.lost++;
                    if (hypo) s.hypo = true;
                });
            });
        });
        var list = Object.keys(rows).map(function (id) { var s = rows[id]; s.total = s.points + s.correction; s.diff = s.gf - s.ga; return s; });
        list.sort(function (a, b) { return (b.total - a.total) || (b.diff - a.diff) || (b.gf - a.gf) || a.name.localeCompare(b.name, 'de'); });
        return list;
    }

    function firstOpenRound(data) {
        // naechster regulaerer Spieltag; sonst erster Spieltag mit offenen (z. B. verlegten) Spielen
        var cur = parseInt(data.current_round, 10);
        if (cur && (data.matches[cur] || []).some(function (m) { return !m.played; })) return cur;
        var rounds = Object.keys(data.matches || {}).map(Number).sort(function (a, b) { return a - b; });
        for (var i = 0; i < rounds.length; i++) {
            if (data.matches[rounds[i]].some(function (m) { return !m.played; })) return rounds[i];
        }
        return null;
    }

    function render() {
        var d = state.data, box = state.box;
        var rounds = Object.keys(d.matches || {}).map(Number).sort(function (a, b) { return a - b; })
            .filter(function (r) { return d.matches[r].some(function (m) { return !m.played; }); });
        if (!rounds.length) {
            box.innerHTML = '<p class="calc-empty">Alle Spiele dieser Saison sind gespielt – hier gibt es nichts mehr zu rechnen.</p>';
            return;
        }
        if (rounds.indexOf(state.round) < 0) state.round = rounds[0];
        var before = compute(d, {});
        var after = compute(d, state.extra);
        var rankBefore = {};
        before.forEach(function (t, i) { rankBefore[t.name] = i + 1; });
        var n = after.length;
        var count = Object.keys(state.extra).length;
        var matches = d.matches[state.round].filter(function (m) { return !m.played; });

        box.innerHTML =
            '<div class="calc-head"><div><strong>🧮 Was wäre wenn?</strong> <span class="calc-hint">Ergebnisse eintragen – die Tabelle rechnet sofort neu. Nichts wird gespeichert.</span></div>' +
            '<button type="button" class="btn btn-secondary calc-close">Schließen</button></div>' +
            '<div class="calc-grid"><div class="calc-games">' +
            '<label class="calc-round">Spieltag <select class="calc-round-select">' +
            rounds.map(function (r) { return '<option value="' + r + '"' + (r === state.round ? ' selected' : '') + '>' + r + '</option>'; }).join('') +
            '</select></label>' +
            matches.map(function (m) {
                var e = state.extra[m.id] || ['', ''];
                return '<div class="calc-game" data-id="' + m.id + '">' +
                    '<span class="calc-team h">' + esc(m.home) + '</span>' +
                    '<input type="number" min="0" max="30" inputmode="numeric" value="' + e[0] + '" aria-label="Tore ' + esc(m.home) + '">' +
                    '<span>:</span>' +
                    '<input type="number" min="0" max="30" inputmode="numeric" value="' + e[1] + '" aria-label="Tore ' + esc(m.guest) + '">' +
                    '<span class="calc-team g">' + esc(m.guest) + '</span></div>';
            }).join('') +
            '<div class="calc-actions"><button type="button" class="btn btn-secondary calc-tendency">Offene Spiele dieses Spieltags: 1:0</button> ' +
            '<button type="button" class="btn btn-secondary calc-reset"' + (count ? '' : ' disabled') + '>Zurücksetzen (' + count + ')</button></div>' +
            '</div><div class="calc-table"><table class="league-table"><thead><tr><th>#</th><th>Team</th><th style="text-align:center">Sp</th><th style="text-align:center">TD</th><th style="text-align:center">Pkt</th></tr></thead><tbody>' +
            after.map(function (t, i) {
                var rank = i + 1, move = rankBefore[t.name] - rank;
                var cls = rank <= 2 ? 'promotion' : rank >= n - 2 ? 'relegation' : '';
                return '<tr' + (t.hypo ? ' class="calc-hypo"' : '') + '><td class="table-rank ' + cls + '">' + rank +
                    (move ? ' <span class="calc-move ' + (move > 0 ? 'up' : 'down') + '">' + (move > 0 ? '▲' : '▼') + Math.abs(move) + '</span>' : '') + '</td>' +
                    '<td class="table-team"><img src="' + logo(t.name) + '" onerror="this.onerror=null;this.src=\'img/teams/blank.png\'" alt="">' + esc(t.name) + '</td>' +
                    '<td class="table-stats">' + t.played + '</td><td class="table-stats">' + (t.diff > 0 ? '+' : '') + t.diff + '</td>' +
                    '<td class="table-points">' + t.total + '</td></tr>';
            }).join('') + '</tbody></table></div></div>';
    }

    function onInput(e) {
        var row = e.target.closest('.calc-game'); if (!row) return;
        var inputs = row.querySelectorAll('input');
        var a = inputs[0].value, b = inputs[1].value;
        if (a === '' || b === '') delete state.extra[row.dataset.id];
        else state.extra[row.dataset.id] = [Math.max(0, Math.min(30, parseInt(a, 10) || 0)), Math.max(0, Math.min(30, parseInt(b, 10) || 0))];
        // nur Tabelle neu zeichnen, Eingabefokus behalten
        var focus = document.activeElement, idx = Array.prototype.indexOf.call(state.box.querySelectorAll('input'), focus);
        render();
        if (idx >= 0) { var el = state.box.querySelectorAll('input')[idx]; if (el) { el.focus(); var v = el.value; el.value = ''; el.value = v; } }
    }

    window.TableCalc = {
        open: function (data, box) {
            if (!data || !box) return;
            if (!state || state.data !== data) state = { data: data, box: box, extra: {}, round: firstOpenRound(data) };
            state.box = box;
            box.hidden = false;
            render();
            if (!box.dataset.bound) {
                box.dataset.bound = '1';
                box.addEventListener('input', onInput);
                box.addEventListener('change', function (e) {
                    if (e.target.classList.contains('calc-round-select')) { state.round = parseInt(e.target.value, 10); render(); }
                });
                box.addEventListener('click', function (e) {
                    if (e.target.closest('.calc-close')) { box.hidden = true; }
                    if (e.target.closest('.calc-reset')) { state.extra = {}; render(); }
                    if (e.target.closest('.calc-tendency')) {
                        (state.data.matches[state.round] || []).forEach(function (m) { if (!m.played && !state.extra[m.id]) state.extra[m.id] = [1, 0]; });
                        render();
                    }
                });
            }
            box.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
        reset: function () { state = null; }
    };
})();
