// Neue Spielerstatistiken und H2H Vergleich (reines JS-Modul)
// Global function für H2H-Vergleich
async function showHeadToHead(team1Id, team2Id) {
    const ligaFile = getCurrentLeagueFile();
    if (!ligaFile) return;
    
    try {
        const res = await fetch(`${API_BASE}/get-head-to-head.php?liga=${ligaFile}&team1=${team1Id}&team2=${team2Id}`);
        const data = await res.json();
        
        if (!data.success) {
            alert('H2H Daten nicht verfügbar');
            return;
        }
        
        const modalBody = document.getElementById('h2h-modal-body');
        const team1Name = data.teams[team1Id];
        const team2Name = data.teams[team2Id];
        const h2h = data.head_to_head;
        const home = data.home_advantage;
        const away = data.away_performance;
        const recent = data.recent_form;
        
        modalBody.innerHTML = `
            <div style="padding: var(--spacing-xl);">
                <h2 style="text-align: center; margin-bottom: var(--spacing-lg);">${team1Name} vs ${team2Name}</h2>
                
                <!-- Bilanz -->
                <div class="table-card" style="margin-bottom: var(--spacing-lg);">
                    <div class="table-header">
                        <h3>💫 Kopf-zu-Kopf Bilanz</h3>
                    </div>
                    <div style="padding: var(--spacing-lg);">
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--spacing-lg); margin-bottom: var(--spacing-lg);">
                            <div style="text-align: center; padding: var(--spacing-md); background: var(--color-bg-elevated); border-radius: 8px;">
                                <div style="font-size: 2rem; font-weight: 800; color: var(--color-live);">${h2h.team1.wins}</div>
                                <div style="font-size: 0.85rem; color: var(--color-text-muted);">Siege ${team1Name}</div>
                            </div>
                            <div style="text-align: center; padding: var(--spacing-md); background: var(--color-bg-elevated); border-radius: 8px;">
                                <div style="font-size: 2rem; font-weight: 800; color: var(--color-text-muted);">${h2h.matches}</div>
                                <div style="font-size: 0.85rem; color: var(--color-text-muted);">Spiele</div>
                            </div>
                            <div style="text-align: center; padding: var(--spacing-md); background: var(--color-bg-elevated); border-radius: 8px;">
                                <div style="font-size: 2rem; font-weight: 800; color: var(--color-primary);">${h2h.team2.wins}</div>
                                <div style="font-size: 0.85rem; color: var(--color-text-muted);">Siege ${team2Name}</div>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-lg);">
                            <div>
                                <h4 style="margin-bottom: var(--spacing-md);">${team1Name}</h4>
                                <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--color-bg-elevated);">
                                    <span>Siege:</span><strong>${h2h.team1.wins}</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--color-bg-elevated);">
                                    <span>Unentschieden:</span><strong>${h2h.team1.draws}</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--color-bg-elevated);">
                                    <span>Niederlagen:</span><strong>${h2h.team1.losses}</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; font-weight: 600;">
                                    <span>Tordiff:</span><strong style="color: ${h2h.team1.goal_difference > 0 ? 'var(--color-live)' : 'var(--color-primary)'};">${h2h.team1.goal_difference > 0 ? '+' : ''}${h2h.team1.goal_difference}</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; font-weight: 600;">
                                    <span>Punkte:</span><strong>${h2h.team1.points}</strong>
                                </div>
                            </div>
                            <div>
                                <h4 style="margin-bottom: var(--spacing-md);">${team2Name}</h4>
                                <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--color-bg-elevated);">
                                    <span>Siege:</span><strong>${h2h.team2.wins}</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--color-bg-elevated);">
                                    <span>Unentschieden:</span><strong>${h2h.team2.draws}</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--color-bg-elevated);">
                                    <span>Niederlagen:</span><strong>${h2h.team2.losses}</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; font-weight: 600;">
                                    <span>Tordiff:</span><strong style="color: ${h2h.team2.goal_difference > 0 ? 'var(--color-live)' : 'var(--color-primary)'};">${h2h.team2.goal_difference > 0 ? '+' : ''}${h2h.team2.goal_difference}</strong>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; font-weight: 600;">
                                    <span>Punkte:</span><strong>${h2h.team2.points}</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Heimvorteil -->
                <div class="table-card" style="margin-bottom: var(--spacing-lg);">
                    <div class="table-header">
                        <h3>🏠 Heimvorteil-Statistiken</h3>
                    </div>
                    <div style="padding: var(--spacing-lg);">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-lg);">
                            <div>
                                <h4 style="margin-bottom: var(--spacing-md);">${team1Name} zu Hause</h4>
                                <div style="font-size: 0.85rem;">
                                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0;">
                                        <span>Spiele:</span><strong>${home.team1.matches}</strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0;">
                                        <span>Siege:</span><strong>${home.team1.wins}</strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0;">
                                        <span>Tore:</span><strong>${home.team1.goals_for}:${home.team1.goals_against}</strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; font-weight: 600;">
                                        <span>Siegquote:</span><strong>${home.team1.matches > 0 ? ((home.team1.wins / home.team1.matches) * 100).toFixed(1) : '0'}%</strong>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <h4 style="margin-bottom: var(--spacing-md);">${team2Name} zu Hause</h4>
                                <div style="font-size: 0.85rem;">
                                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0;">
                                        <span>Spiele:</span><strong>${home.team2.matches}</strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0;">
                                        <span>Siege:</span><strong>${home.team2.wins}</strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0;">
                                        <span>Tore:</span><strong>${home.team2.goals_for}:${home.team2.goals_against}</strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; font-weight: 600;">
                                        <span>Siegquote:</span><strong>${home.team2.matches > 0 ? ((home.team2.wins / home.team2.matches) * 100).toFixed(1) : '0'}%</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Formkurve -->
                <div class="table-card">
                    <div class="table-header">
                        <h3>📈 Letzte 5 Spiele</h3>
                    </div>
                    <div style="padding: var(--spacing-lg);">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-lg);">
                            <div>
                                <h4 style="margin-bottom: var(--spacing-md);">${team1Name}</h4>
                                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                    ${(recent.team1 || []).map(m => {
                                        const isHome = m.home_team_id === team1Id;
                                        const w = isHome ? m.home_goals > m.guest_goals : m.guest_goals > m.home_goals;
                                        const d = m.home_goals === m.guest_goals;
                                        return `<div style="padding: 0.5rem; background: var(--color-bg-elevated); border-radius: 4px; font-size: 0.85rem;">
                                            <div style="color: ${w ? 'var(--color-live)' : d ? 'var(--color-text-muted)' : 'var(--color-primary)'}; font-weight: 600;">
                                                ${isHome ? m.home_goals + ':' + m.guest_goals : m.guest_goals + ':' + m.home_goals} ${w ? '✓' : d ? '=' : '✗'}
                                            </div>
                                            <div style="color: var(--color-text-muted); font-size: 0.75rem;">Spieltag ${m.round_nr}</div>
                                        </div>`;
                                    }).join('')}
                                </div>
                            </div>
                            <div>
                                <h4 style="margin-bottom: var(--spacing-md);">${team2Name}</h4>
                                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                    ${(recent.team2 || []).map(m => {
                                        const isHome = m.home_team_id === team2Id;
                                        const w = isHome ? m.home_goals > m.guest_goals : m.guest_goals > m.home_goals;
                                        const d = m.home_goals === m.guest_goals;
                                        return `<div style="padding: 0.5rem; background: var(--color-bg-elevated); border-radius: 4px; font-size: 0.85rem;">
                                            <div style="color: ${w ? 'var(--color-live)' : d ? 'var(--color-text-muted)' : 'var(--color-primary)'}; font-weight: 600;">
                                                ${isHome ? m.home_goals + ':' + m.guest_goals : m.guest_goals + ':' + m.home_goals} ${w ? '✓' : d ? '=' : '✗'}
                                            </div>
                                            <div style="color: var(--color-text-muted); font-size: 0.75rem;">Spieltag ${m.round_nr}</div>
                                        </div>`;
                                    }).join('')}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        openModal('h2h-modal');
    } catch (e) {
        console.error('H2H Fehler:', e);
        alert('Fehler beim Laden der H2H-Daten');
    }
}

function getCurrentLeagueFile() {
    const select = document.getElementById('league-select');
    if (select && select.value && select.value !== 'Liga wählen...') {
        return select.value;
    }
    return null;
}
