document.addEventListener('DOMContentLoaded', () => {
    // === GLOBAL APP ===
    window.app = window.app || {};

    // === STATE ===
    const urlParams = new URLSearchParams(window.location.search);
    const liga = urlParams.get('liga') || 'hhoberliga2425.l98';
    let currentRound = parseInt(urlParams.get('round')) || null;
    let leagueData = null;

    // === INIT ===
    initTabs();
    initLeagueSelector();
    fetchData(liga);

    // === LEAGUE SELECTOR ===
    async function initLeagueSelector() {
        const select = document.getElementById('league-select');
        try {
            const res = await fetch('api/list-leagues.php');
            const data = await res.json();
            if (data.leagues) {
                data.leagues.forEach(l => {
                    const opt = document.createElement('option');
                    opt.value = l.file;
                    opt.textContent = l.name;
                    if (l.file === liga) opt.selected = true;
                    select.appendChild(opt);
                });
            }
        } catch (e) { console.warn('Could not load leagues list'); }

        select.addEventListener('change', (e) => {
            if (e.target.value) {
                window.location.href = `?liga=${e.target.value}`;
            }
        });
    }

    // === TABS LOGIC ===
    function initTabs() {
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                // Remove active
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

                // Add active
                btn.classList.add('active');
                const targetId = btn.getAttribute('data-tab');
                document.getElementById(targetId).classList.add('active');

                // Render specific views on demand if needed
                if (targetId === 'tab-stats' && leagueData) renderFeverCurve(leagueData);
                if (targetId === 'tab-crosstable' && leagueData) renderCrossTable(leagueData);
                if (targetId === 'tab-schedule' && leagueData) renderFullSchedule(leagueData);
                if (targetId === 'tab-news') renderNewsArchive();

                // Allow re-render on same tab (like news)
                if (!targetId) return;
            });
        });
    }

    // === DATA FETCHING ===
    async function fetchData(ligaFile) {
        const app = document.getElementById('app');
        try {
            document.querySelector('#league-table tbody').innerHTML = '<tr><td colspan="6" style="text-align:center; padding: 20px;">Lade Daten...</td></tr>';

            const response = await fetch(`api/get-league-data.php?liga=${ligaFile}`);
            const data = await response.json();

            if (data.error) {
                app.innerHTML = `<div style="text-align:center; padding: 20px; color: red;">Fehler: ${data.error}</div>`;
                return;
            }

            leagueData = data;
            renderAll(data);
        } catch (e) {
            console.error(e);
            console.warn("API Error");
        }
    }

    function renderAll(data) {
        // Title
        const title = data.options.Name || 'Liga';
        document.title = title;
        document.querySelector('h1').textContent = title;

        const totalRounds = parseInt(data.options.Rounds);
        const actualRound = parseInt(data.options.Actual);

        if (!currentRound) currentRound = actualRound;

        // Render Overview (Default)
        renderNav(totalRounds, currentRound, liga);
        renderTable(data.table);
        renderMatches(currentRound);

        // Pre-render others 
        renderFullSchedule(data);
        renderCrossTable(data);
        renderFeverCurve(data);
    }

    // === OVERVIEW RENDERERS ===
    function renderNav(total, current, ligaFile) {
        const nav = document.getElementById('round-nav');
        let html = '';
        const prev = current > 1 ? current - 1 : 1;
        const next = current < total ? current + 1 : total;

        html += `<a href="?liga=${ligaFile}&round=${prev}" class="btn">&laquo;</a>`;
        html += `<select onchange="window.location.href='?liga=${ligaFile}&round='+this.value">`;
        for (let i = 1; i <= total; i++) {
            const sel = i === current ? 'selected' : '';
            html += `<option value="${i}" ${sel}>Spieltag ${i}</option>`;
        }
        html += `</select>`;
        html += `<a href="?liga=${ligaFile}&round=${next}" class="btn">&raquo;</a>`;
        nav.innerHTML = html;
    }

    function renderTable(table) {
        const tbody = document.querySelector('#league-table tbody');
        let html = '';
        let rank = 1;
        const totalTeams = table.length;

        table.forEach(team => {
            let rowClass = '';
            if (rank === 1) rowClass = 'promotion-zone';
            else if (rank > totalTeams - 3) rowClass = 'relegation-zone';

            html += `
                <tr class="${rowClass}">
                    <td class="rank">${rank}</td>
                    <td class="team-name">${team.name}</td>
                    <td class="matches">${team.played}</td>
                    <td class="goals hidden-xs">${team.goals_for}:${team.goals_against}</td>
                    <td class="diff">${team.diff}</td>
                    <td class="points">${team.points}</td>
                </tr>
            `;
            rank++;
        });
        tbody.innerHTML = html;
    }

    // === HELPERS ===
    function getLogoUrl(teamName) {
        // Sanitize name to match filename: remove spaces/special chars, keep alphanumeric
        // List showed: "Altona93.gif", "TuSDassendorf.gif" (spaces removed)
        // Check "1. FC ..." -> "1FC..."
        if (!teamName) return 'img/teams/blank.png';
        const clean = teamName.replace(/[^a-zA-Z0-9]/g, '');
        return `img/teams/${clean}.gif`;
    }

    // === GLOBAL APP ===
    window.app = {
        loadRound: (r) => {
            if (!leagueData) return;
            const total = parseInt(leagueData.options.Rounds);
            if (r < 1) r = 1;
            if (r > total) r = total;
            currentRound = r;
            renderMatches(currentRound);

            // Also update Nav Dropdown if exists
            const select = document.querySelector('#round-nav select');
            if (select) select.value = r;
        }
    };

    function renderMatches(startRound) {
        const container = document.getElementById('matches-container');
        const totalRounds = parseInt(leagueData.options.Rounds);

        // Nav Buttons (Prev/Next)
        // We shift by 1 for smooth navigation
        const prevBtn = startRound > 1 ? `<a class="match-nav-btn" onclick="app.loadRound(${startRound - 1})">← vorheriger Spieltag</a>` : '<div></div>';
        const nextBtn = startRound < totalRounds ? `<a class="match-nav-btn" onclick="app.loadRound(${startRound + 1})">nächster Spieltag →</a>` : '<div></div>';

        let html = `
            <div class="match-nav-header">
                ${prevBtn}
                ${nextBtn}
            </div>
        `;

        // Render 3 Rounds (or fewer if near end)
        for (let r = startRound; r < startRound + 3; r++) {
            if (r > totalRounds) break;

            const matches = leagueData.matches[r];
            if (!matches) continue;

            html += `<h3 style="margin-top:20px; border-bottom: 2px solid #333; padding-bottom:5px;">Spieltag ${r}</h3>`;

            // Group by Date for this round
            const matchesByDate = {};
            matches.forEach(m => {
                const dateKey = m.date || 'Ohne Datum';
                if (!matchesByDate[dateKey]) matchesByDate[dateKey] = [];
                matchesByDate[dateKey].push(m);
            });

            const dates = Object.keys(matchesByDate);
            dates.forEach(date => {
                html += `<div class="match-date-header">${date}</div>`;
                matchesByDate[date].forEach(m => {
                    const hGoals = m.played ? m.home_goals : '-';
                    const gGoals = m.played ? m.guest_goals : '-';
                    const scoreClass = m.played ? '' : 'pending';
                    const scoreDisplay = m.played ? `${hGoals}:${gGoals}` : '-:-';

                    const homeLogo = `<img src="${getLogoUrl(m.home)}" class="team-logo" onerror="this.src='img/teams/blank.png'">`;
                    const guestLogo = `<img src="${getLogoUrl(m.guest)}" class="team-logo" onerror="this.src='img/teams/blank.png'">`;

                    html += `
                        <div class="match-row">
                            <div class="match-info">
                                <div class="team-home">${m.home}</div>
                                ${homeLogo}
                                <div class="score-box ${scoreClass}">${scoreDisplay}</div>
                                ${guestLogo}
                                <div class="team-guest">${m.guest}</div>
                            </div>
                            ${m.match_note ? `<div class="match-note-inline">${m.match_note}</div>` : ''}
                            ${(m.report_url || m.has_news) ? `
                            <div class="match-extras">
                                ${m.report_url ? `<a href="${m.report_url}" target="_blank" class="match-link">📄 Bericht</a>` : ''}
                                ${m.has_news ? `<a href="javascript:void(0)" onclick="app.showNews(${m.news_id})" class="match-news-btn">📰 Spielbericht</a>` : ''}
                            </div>
                            ` : ''}
                        </div>
                    `;
                });
            });

            html += '<div style="margin-bottom: 40px;"></div>'; // Spacer between rounds
        }

        container.innerHTML = html;
    }

    // === CROSS TABLE RENDERER ===
    function renderCrossTable(data) {
        const container = document.getElementById('cross-table');
        if (!container) return;

        const teams = Object.values(data.teams);
        const matches = data.matches;

        let html = '<div style="overflow-x:auto;"><table class="lmo-table" style="font-size:0.9em;"><thead><tr><th></th>';

        // Header
        teams.forEach(t => {
            html += `<th title="${t.name}" style="text-align:center;"><img src="${getLogoUrl(t.name)}" style="height:20px;"></th>`;
        });
        html += '</tr></thead><tbody>';

        // Rows
        teams.forEach(home => {
            html += `<tr>`;
            html += `<td style="font-weight:bold; white-space:nowrap;"><img src="${getLogoUrl(home.name)}" style="height:20px; vertical-align:middle; margin-right:5px;"> ${home.name}</td>`;

            teams.forEach(guest => {
                if (home.name === guest.name) {
                    html += `<td style="background:#eee;"></td>`;
                } else {
                    let result = '-';
                    // Find match
                    for (const round in matches) {
                        const match = matches[round].find(m => m.home === home.name && m.guest === guest.name);
                        if (match && match.played) {
                            result = `${match.home_goals}:${match.guest_goals}`;
                            break;
                        }
                    }
                    html += `<td style="text-align:center;">${result}</td>`;
                }
            });
            html += `</tr>`;
        });

        html += '</tbody></table></div>';
        container.innerHTML = html;
    }

    // === SCHEDULE RENDERER ===
    function renderFullSchedule(data) {
        const container = document.getElementById('full-schedule');
        const totalRounds = parseInt(data.options.Rounds);
        let html = '';

        for (let i = 1; i <= totalRounds; i++) {
            const matches = data.matches[i];
            if (!matches) continue;

            html += `<div class="schedule-group"><h3>Spieltag ${i}</h3><div class="match-list" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 10px;">`;
            matches.forEach(m => {
                const hGoals = m.played ? m.home_goals : '-';
                const gGoals = m.played ? m.guest_goals : '-';
                const dateStr = m.date ? `<span style="font-size:0.8em; color:#666;">${m.date} ${m.time || ''}</span>` : '';
                const homeLogo = `<img src="${getLogoUrl(m.home)}" class="team-logo-small" style="height:20px; vertical-align:middle; margin-right:5px;" onerror="this.src='img/teams/blank.png'">`;
                const guestLogo = `<img src="${getLogoUrl(m.guest)}" class="team-logo-small" style="height:20px; vertical-align:middle; margin-left:5px;" onerror="this.src='img/teams/blank.png'">`;

                html += `
                    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #f0f0f0; padding:5px;">
                        <div style="flex:1;">
                            ${dateStr}<br>
                            ${homeLogo} ${m.home} - ${m.guest} ${guestLogo}
                        </div>
                        <strong style="margin-left:10px;">${hGoals}:${gGoals}</strong>
                    </div>
                 `;
            });
            html += `</div></div>`;
        }

        container.innerHTML = html;
    }

    // === FEVER CURVE (CHART) ===
    function renderFeverCurve(data) {
        const container = document.getElementById('fever-chart');
        const totalRounds = parseInt(data.options.Rounds);
        const teams = data.teams;
        const teamIds = Object.keys(teams).map(Number);
        const history = {};

        teamIds.forEach(id => history[id] = []);
        const currentStats = {};
        teamIds.forEach(id => currentStats[id] = { id: id, points: 0, diff: 0, goals: 0 });

        for (let r = 1; r <= totalRounds; r++) {
            const matches = data.matches[r];
            if (matches) {
                matches.forEach(m => {
                    if (m.played) {
                        currentStats[m.home_id].goals += m.home_goals;
                        currentStats[m.home_id].diff += (m.home_goals - m.guest_goals);
                        if (m.home_goals > m.guest_goals) currentStats[m.home_id].points += 3;
                        else if (m.home_goals === m.guest_goals) currentStats[m.home_id].points += 1;

                        currentStats[m.guest_id].goals += m.guest_goals;
                        currentStats[m.guest_id].diff += (m.guest_goals - m.home_goals);
                        if (m.guest_goals > m.home_goals) currentStats[m.guest_id].points += 3;
                        else if (m.guest_goals === m.home_goals) currentStats[m.guest_id].points += 1;
                    }
                });
            }

            const sorted = Object.values(currentStats).sort((a, b) => {
                if (b.points !== a.points) return b.points - a.points;
                if (b.diff !== a.diff) return b.diff - a.diff;
                return b.goals - a.goals;
            });

            sorted.forEach((stat, index) => {
                history[stat.id].push(index + 1);
            });
        }

        const w = 800; // SVG ViewBox width
        const h = 400; // SVG ViewBox height
        const pad = 40;
        const xStep = (w - 2 * pad) / (totalRounds - 1);
        const yStep = (h - 2 * pad) / (teamIds.length - 1);

        let svg = `<svg class="fever-chart" viewBox="0 0 ${w} ${h}">`;

        // Axes
        for (let i = 0; i < teamIds.length; i++) {
            const y = pad + i * yStep;
            svg += `<line x1="${pad}" y1="${y}" x2="${w - pad}" y2="${y}" class="chart-axis-line" stroke="#eee" />`;
        }

        for (let i = 0; i < totalRounds; i++) {
            const x = pad + i * xStep;
            svg += `<line x1="${x}" y1="${pad}" x2="${x}" y2="${h - pad}" class="chart-axis-line" stroke="#eee" />`;
            if (i % 5 === 0 || i === totalRounds - 1) {
                svg += `<text x="${x}" y="${h - pad + 15}" text-anchor="middle" class="chart-text">${i + 1}</text>`;
            }
        }

        // Lines
        teamIds.forEach((tid, idx) => {
            const points = history[tid];
            const color = `hsl(${(idx * 360) / teamIds.length}, 70%, 50%)`;
            let pathD = "";

            points.forEach((rank, rIdx) => {
                const x = pad + rIdx * xStep;
                const y = pad + (rank - 1) * yStep;
                if (rIdx === 0) pathD += `M ${x} ${y}`;
                else pathD += ` L ${x} ${y}`;
            });

            svg += `<path d="${pathD}" class="chart-line" stroke="${color}" data-team="${teams[tid].name}"><title>${teams[tid].name}</title></path>`;

            // Label at the end
            const lastRank = points[points.length - 1];
            const lastX = pad + (points.length - 1) * xStep;
            const lastY = pad + (lastRank - 1) * yStep;
            svg += `<text x="${lastX + 5}" y="${lastY + 3}" class="chart-text" fill="${color}" font-weight="bold">${teams[tid].name}</text>`;
        });

        svg += '</svg>';
        container.innerHTML = svg;
    }


    // === HELPER: Legacy Content Decoder ===

    // === NEWS ARCHIVE RENDERER ===
    async function renderNewsArchive() {
        const container = document.getElementById('news-archive');
        if (!container) return;

        container.innerHTML = '<div style="text-align:center; padding:40px;">Lade Spielberichte...</div>';

        try {
            const res = await fetch('api/get-news.php?list=1&limit=50');
            const data = await res.json();

            if (!data.news || data.news.length === 0) {
                container.innerHTML = '<div style="text-align:center; padding:40px;">Keine Spielberichte vorhanden.</div>';
                return;
            }

            // Search Box
            let html = `
                <div style="margin-bottom:20px;">
                    <input type="text" id="news-search" placeholder="Suche nach Team, Spieler..."
                           style="width:100%; padding:12px 15px; border:1px solid #ddd; border-radius:8px; font-size:1rem;"
                           onkeyup="app.searchNews(this.value)">
                </div>
                <div id="news-list">
            `;

            html += data.news.map(n => {
                const date = n.timestamp > 0 ? new Date(n.timestamp * 1000).toLocaleDateString('de-DE') : '';

                // Preview from short_content or strip tags from content
                let preview = n.short_content || n.content || '';
                preview = preview.replace(/<[^>]*>?/gm, '').replace(/&[^;]+;/g, ' ');
                if (preview.length > 180) preview = preview.substring(0, 180) + '...';

                return `
                <div class="news-card" onclick="app.showNews(${n.id})">
                    <div class="news-card-date">${date}${n.author ? ' | ' + n.author : ''}</div>
                    <div class="news-card-title">${n.title}</div>
                    <div class="news-card-preview">${preview}</div>
                </div>
            `;
            }).join('');

            html += '</div>';
            container.innerHTML = html;

        } catch (e) {
            console.error(e);
            container.innerHTML = '<div style="text-align:center; padding:40px; color:red;">Fehler beim Laden der Spielberichte.</div>';
        }
    }

    // === EXTEND GLOBAL APP ===
    Object.assign(window.app, {
        loadLeague: async (file) => {
            currentLeagueFile = file;
            await fetchData(file);
        },

        // Show News in Modal
        showNews: async (id) => {
            const modal = document.getElementById('news-modal');
            const body = document.getElementById('news-modal-body');

            if (!modal || !body) {
                console.error('News modal not found');
                return;
            }

            body.innerHTML = '<div style="text-align:center; padding:40px;">Lade Spielbericht...</div>';
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';

            try {
                const res = await fetch(`api/get-news.php?id=${id}`);
                const data = await res.json();

                if (data.error || !data.news) {
                    body.innerHTML = '<div style="text-align:center; padding:40px; color:red;">Spielbericht nicht gefunden.</div>';
                    return;
                }

                const news = data.news;
                const date = news.timestamp > 0 ? new Date(news.timestamp * 1000).toLocaleDateString('de-DE', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                }) : '';

                body.innerHTML = `
                    <div class="news-header">
                        <div class="news-meta">${date}${news.author ? ' | ' + news.author : ''}</div>
                        <h1 class="news-title">${news.title}</h1>
                    </div>
                    <div class="news-content">${news.content}</div>
                `;

            } catch (e) {
                console.error(e);
                body.innerHTML = '<div style="text-align:center; padding:40px; color:red;">Fehler beim Laden.</div>';
            }
        },

        // Close News Modal
        closeNewsModal: () => {
            const modal = document.getElementById('news-modal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
        },

        // Search News
        searchNews: async (query) => {
            if (!query || query.length < 2) {
                renderNewsArchive();
                return;
            }

            const container = document.getElementById('news-list');
            if (!container) return;

            try {
                const res = await fetch(`api/get-news.php?search=${encodeURIComponent(query)}&limit=30`);
                const data = await res.json();

                if (!data.news || data.news.length === 0) {
                    container.innerHTML = '<div style="text-align:center; padding:20px; color:#888;">Keine Ergebnisse gefunden.</div>';
                    return;
                }

                container.innerHTML = data.news.map(n => {
                    const date = n.timestamp > 0 ? new Date(n.timestamp * 1000).toLocaleDateString('de-DE') : '';
                    let preview = n.short_content || '';
                    preview = preview.replace(/<[^>]*>?/gm, '').replace(/&[^;]+;/g, ' ');
                    if (preview.length > 180) preview = preview.substring(0, 180) + '...';

                    return `
                    <div class="news-card" onclick="app.showNews(${n.id})">
                        <div class="news-card-date">${date}${n.author ? ' | ' + n.author : ''}</div>
                        <div class="news-card-title">${n.title}</div>
                        <div class="news-card-preview">${preview}</div>
                    </div>
                `;
                }).join('');

            } catch (e) {
                console.error(e);
            }
        },

        // Legacy alias
        loadNews: (id) => window.app.showNews(id)
    });

    // Close modal on ESC key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            window.app.closeNewsModal();
        }
    });

});
