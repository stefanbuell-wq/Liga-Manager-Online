# Feature Backlog - HAFO Liga-Manager Online

## CMS - Content Management System (Prioritaet: Hoch)

### Stufe 1: Sektions-Verwaltung
- [ ] **Sektions-Toggle** - Sektionen auf Landingpage ein-/ausblenden (Hero, Live-Ticker, Forum, News, etc.)
- [ ] **Sektions-Reihenfolge** - Drag & Drop zum Umsortieren der Sektionen
- [ ] **Hero-Editor** - Titel, Untertitel, Badge-Text bearbeiten
- [ ] **Quick-Texte** - Einfache Textfelder fuer Taglines, Willkommensnachrichten

### Stufe 2: Content-Bloecke
- [ ] **Hero-Slides** - Mehrere Hero-Bilder mit Text als Slideshow
- [ ] **Banner-System** - Ankuendigungen, Warnungen, Info-Boxen erstellen
- [ ] **Sponsoren-Verwaltung** - Logos hochladen, Links setzen, Reihenfolge aendern
- [ ] **Footer-Editor** - Links verwalten, Kategorien erstellen
- [ ] **Social Media Links** - Icons und URLs konfigurieren
- [ ] **Info-Widgets** - Eigene Boxen mit Titel + Inhalt erstellen

### Stufe 3: Vollstaendiges CMS
- [ ] **Seiten-Builder** - Eigene Seiten erstellen (Ueber uns, Kontakt, Impressum, etc.)
- [ ] **WYSIWYG-Editor** - Rich-Text-Editor fuer Seiteninhalte
- [ ] **Menue-Builder** - Navigation frei konfigurieren (Header + Footer)
- [ ] **Widget-System** - Sidebar-Module hinzufuegen/entfernen (Tabelle, Naechste Spiele, Top-Scorer)
- [ ] **Media Library** - Zentrale Bildverwaltung mit Upload, Kategorien, Suche
- [ ] **SEO-Einstellungen** - Meta-Tags, Open Graph, Sitemap pro Seite
- [ ] **Vorschau-Modus** - Aenderungen vor Veroeffentlichung pruefen
- [ ] **Versionshistorie** - Aenderungen rueckgaengig machen
- [ ] **Mehrsprachigkeit** - DE/EN Unterstuetzung (optional)

### CMS Datenbank-Erweiterungen
- [ ] **pages** Tabelle - id, slug, title, content, meta_description, status, created_at, updated_at
- [ ] **sections** Tabelle - id, page_id, type, content_json, sort_order, visible
- [ ] **media** Tabelle - id, filename, path, alt_text, category, uploaded_at
- [ ] **menus** Tabelle - id, name, location (header/footer)
- [ ] **menu_items** Tabelle - id, menu_id, label, url, parent_id, sort_order
- [ ] **widgets** Tabelle - id, type, config_json, position, sort_order, visible

---

## Statistik & Analyse

- [ ] **Torjaegerliste** - Automatische Erfassung der Torschuetzen pro Spieler
- [ ] **Spielerstatistiken** - Einsaetze, Tore, Gelbe/Rote Karten
- [ ] **Formkurve** - Letzte 5 Spiele visualisiert (W-W-L-D-W)
- [ ] **Head-to-Head** - Direktvergleich zwischen zwei Teams
- [ ] **Saisonvergleich** - Tabelle zu verschiedenen Zeitpunkten der Saison

## Interaktive Features

- [ ] **Tippspiel** - User koennen Ergebnisse vorhersagen
- [ ] **Spieler des Spieltags** - Abstimmung durch Community
- [ ] **Live-Ticker** - Echtzeit-Updates waehrend Spielen (mit manueller Eingabe)
- [ ] **Push-Benachrichtigungen** - Bei Spielende des Lieblingsteams

## Content & Medien

- [ ] **Fotogalerie** - Bilder pro Spieltag/Spiel hochladen
- [ ] **Video-Highlights** - YouTube/Vimeo Einbettung pro Spiel
- [ ] **Saisonrueckblick** - Automatisch generierte Zusammenfassung

## Benutzer-Features

- [ ] **Favoriten-Team** - Personalisierte Ansicht
- [ ] **Benachrichtigungen** - Email bei News zum eigenen Team
- [ ] **Profil-Seiten** - Fuer registrierte User mit Tippspiel-Ranking

## Verwaltung

- [ ] **Spielverlegungen** - Mit Historie und Grund
- [ ] **Strafentabelle** - Sperren, Geldstrafen dokumentieren
- [ ] **Schiedsrichter-Ansetzungen** - Wer pfeift welches Spiel
- [ ] **Platz-/Hallensperre** - Bei Unbespielbarkeit

## Archiv & Historie

- [ ] **Ewige Tabelle** - Alle Saisons zusammengefasst
- [ ] **Meisterarchiv** - Historische Meister und Aufsteiger
- [ ] **Rekordecke** - Hoechste Siege, laengste Serien, etc.

## Mobile & UX

- [ ] **PWA** - Offline-faehig, installierbar auf Smartphone
- [ ] **Dark Mode** - Dunkles Design-Theme (teilweise via Design-Editor vorhanden)
- [ ] **Kalender-Export** - iCal fuer Spieltermine

---

## Priorisierung

| Prioritaet | Features |
|------------|----------|
| **Hoch** | CMS Stufe 1-3, Media Library, Seiten-Builder |
| Mittel | Torjaegerliste, Formkurve, Tippspiel, Fotogalerie |
| Niedrig | PWA, Ewige Tabelle, Mehrsprachigkeit, Saisonrueckblick |

## Abhaengigkeiten

```
CMS Stufe 1 (Sektions-Verwaltung)
    └── CMS Stufe 2 (Content-Bloecke)
        └── CMS Stufe 3 (Vollstaendiges CMS)
            ├── Media Library (wird von allen Content-Features genutzt)
            ├── Seiten-Builder
            └── Widget-System

Tippspiel
    └── Benutzer-System (vorhanden)
        └── Profil-Seiten

Torjaegerliste
    └── Spielerstatistiken
        └── Spieler des Spieltags
```

## Notizen

- CMS wird schrittweise implementiert (Stufe 1 → 2 → 3)
- Design-Editor (`admin/design.html`) bereits vorhanden - wird ins CMS integriert
- Media Library sollte frueh umgesetzt werden (wird von vielen Features benoetigt)
- Bei Umsetzung Checkbox abhaken und Datum notieren
