/**
 * Newsprint Modul - Live Match Display
 */

function populateNewsprintMatches(matches) {
    const container = document.getElementById('newsprint-matches');
    if (!container || !matches || typeof matches !== 'object') {
        console.log('Newsprint: Keine Matches verfügbar');
        return;
    }

    const allMatches = [];
    
    // Sammle Matches aus allen Runden
    for (const round in matches) {
        if (Array.isArray(matches[round])) {
            allMatches.push(...matches[round]);
        }
    }

    if (allMatches.length === 0) {
        container.innerHTML = '<div style="grid-column: span 2; padding: 2rem; text-align: center; color: #999;">Keine aktuellen Spiele</div>';
        return;
    }

    const html = [];
    html.push('<div class="newsprint-section-label">Aktuelle Spiele</div>');

    // Zeige maximal 8 Matches
    allMatches.slice(0, 8).forEach((match, idx) => {
        const isPlayed = match.played === true;
        const status = isPlayed ? '✓ BEENDET' : '● LIVE';
        const scoreDisplay = isPlayed 
            ? `${match.home_goals || 0}:${match.guest_goals || 0}`
            : '–:–';

        const homeName = (match.home || 'Team A').substring(0, 12).toUpperCase();
        const guestName = (match.guest || 'Team B').substring(0, 12).toUpperCase();
        const matchDate = match.date || 'TBD';

        html.push(`
            <div class="newsprint-match-row">
                <div class="newsprint-match-meta">
                    <span>${matchDate}</span>
                    <span class="${isPlayed ? '' : 'newsprint-status-live'}">${status}</span>
                </div>
                <div class="newsprint-match-teams">
                    <div>${homeName}</div>
                    <div class="newsprint-score">${scoreDisplay}</div>
                </div>
            </div>
        `);

        // Nach jedem Pair neue Section
        if ((idx + 1) % 2 === 0 && idx < 6) {
            html.push('<div class="newsprint-section-label">Weitere Spiele</div>');
        }
    });

    container.innerHTML = html.join('');
    console.log('Newsprint: ' + allMatches.length + ' Matches geladen');
}

// Hook in die bestehende Render-Funktion
document.addEventListener('DOMContentLoaded', () => {
    console.log('Newsprint: Initialisierung gestartet');

    // Beobachte das Tab-System
    const tabButtons = document.querySelectorAll('.tab-btn');
    tabButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            setTimeout(() => {
                if (btn.getAttribute('data-tab') === 'table' && window.currentLeagueData) {
                    populateNewsprintMatches(window.currentLeagueData.matches);
                }
            }, 100);
        });
    });
});

// Exportiere Funktion global
window.populateNewsprintMatches = populateNewsprintMatches;
