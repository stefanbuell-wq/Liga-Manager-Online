# Neue Features: Spielerstatistiken & Head-to-Head Vergleiche

## 1. Spielerstatistiken Feature

### Datenbankstruktur
Neue Tabellen wurden hinzugefügt:

#### `players` Tabelle
```sql
CREATE TABLE players (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    number INTEGER,
    position TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
    UNIQUE(team_id, number)
)
```

#### `match_events` Tabelle
```sql
CREATE TABLE match_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id INTEGER NOT NULL,
    event_type TEXT NOT NULL, -- 'goal', 'yellow_card', 'red_card', 'assist'
    player_id INTEGER,
    player_name TEXT,
    team_id INTEGER NOT NULL,
    minute INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(match_id) REFERENCES matches(id) ON DELETE CASCADE,
    FOREIGN KEY(player_id) REFERENCES players(id),
    FOREIGN KEY(team_id) REFERENCES teams(id)
)
```

### API Endpoints

#### `GET /api/get-player-stats.php?liga=file.lmo`
Lädt sämtliche Spielerstatistiken für eine Liga.

**Response:**
```json
{
  "success": true,
  "league_id": 1,
  "top_scorers": [
    {
      "id": 1,
      "name": "Max Mustermann",
      "number": 10,
      "team_id": 1,
      "team_name": "FC Hamburg",
      "goals": 15,
      "assists": 3
    }
  ],
  "yellow_cards": [...],
  "red_cards": [...],
  "top_assists": [...],
  "team_statistics": [
    {
      "team_id": 1,
      "team_name": "FC Hamburg",
      "players": [...]
    }
  ]
}
```

#### `POST /api/save-match-events.php`
Speichert Ereignisse (Tore, Karten, Assists) für Spiele.

**Actions:**
- `add_goal` - Tor registrieren
- `add_card` - Gelbe/Rote Karte registrieren
- `add_assist` - Assist registrieren
- `delete_event` - Ereignis löschen
- `get_match_events` - Alle Ereignisse eines Spiels abrufen

**Beispiel:**
```bash
POST /api/save-match-events.php
{
  "action": "add_goal",
  "match_id": 1,
  "player_id": 5,
  "player_name": "Max Mustermann",
  "team_id": 1,
  "minute": 45
}
```

#### `GET|POST /api/get-players.php`
Spielerverwaltung pro Team.

**GET Beispiel:**
```bash
GET /api/get-players.php?team_id=1
```

**POST Actions:**
- `create` - Neuen Spieler hinzufügen
- `update` - Spielerdaten aktualisieren
- `delete` - Spieler löschen
- `bulk_import` - Mehrere Spieler auf einmal importieren

---

## 2. Head-to-Head (H2H) Statistiken Feature

### API Endpoint

#### `GET /api/get-head-to-head.php?liga=file.lmo&team1=1&team2=2`
Vergleicht zwei Teams miteinander.

**Response:**
```json
{
  "success": true,
  "teams": {
    "1": "FC Hamburg",
    "2": "HSV Süd"
  },
  "head_to_head": {
    "matches": 5,
    "team1": {
      "wins": 2,
      "draws": 1,
      "losses": 2,
      "goals_for": 8,
      "goals_against": 7,
      "goal_difference": 1,
      "points": 7
    },
    "team2": {
      "wins": 2,
      "draws": 1,
      "losses": 2,
      "goals_for": 7,
      "goals_against": 8,
      "goal_difference": -1,
      "points": 7
    },
    "match_history": [...]
  },
  "home_advantage": {
    "team1": {
      "matches": 10,
      "wins": 6,
      "draws": 2,
      "losses": 2,
      "goals_for": 18,
      "goals_against": 10
    },
    "team2": {...}
  },
  "away_performance": {...},
  "recent_form": {
    "team1": [...],
    "team2": [...]
  }
}
```

### Frontend Integration

Die H2H-Funktionalität ist in `js/player-stats.js` implementiert:

```javascript
// H2H-Vergleich anzeigen
showHeadToHead(team1Id, team2Id);
```

Dies öffnet ein Modal mit:
- Kopf-zu-Kopf Bilanz
- Heimvorteil-Statistiken
- Auswärts-Performance
- Formkurve (letzte 5 Spiele)

---

## Anzeigeorte im Portal

### Spielerstatistiken
Im **Statistiken** Tab:
- 🏆 Ligastatistiken (Ligadaten)
- ⚽ Spielerstatistiken (Neu!)
  - Torschützenliste
  - Gelbe/Rote Karten
  - Assist-Rangliste
  - Spielerstatistiken pro Team

### Head-to-Head Vergleich
Geplant für zukünftige Updates:
- Clickable Team-Links in Tabellen zur H2H-Anzeige
- H2H-Vergleich-Widget in Spielplan-Ansicht

---

## Verwaltung im Admin-Panel

### Spielerverwaltung
Unter Admin > Teams:
- Spieler pro Team verwalten
- Nummern und Positionen vergeben
- Bulk-Import von Spielerlisten

### Match Events
Unter Admin > Liga Bearbeiten:
- Tore während eines Spiels registrieren
- Karten dokumentieren
- Assists zuordnen

---

## Zukünftige Erweiterungen

1. **Player Profiles** - Ausführliche Spielerseiten mit Karrierestatistiken
2. **Team Comparisons** - Detaillierte Team-vs-Team Analysen
3. **Fantasy Liga** - Punkte basierend auf Spielerleistungen
4. **Live Ticker** - Echtzeit Match Events während Spielen
5. **Video Highlights** - Integration von Spiel-Highlights

