# Liga Manager Online (LMO26)

Webbasiertes System für Amateurfußball-Portale: Ligen, Spielpläne und Tabellen, Spielberichte und Vorschauen,
Kader, Forum, Sponsoren-Banner und Kontaktformular. PHP 8 und SQLite, ohne Framework, ohne Build-Schritt.

LMO26 ist die Neuentwicklung des klassischen [Liga Manager Online](https://liga-manager-online.de) und liest dessen
`.l98`-Ligadateien. Es läuft produktiv auf [hafo.de](https://hafo.de) – Hamburger Fußball Online.

## Funktionen

- **Ligen:** Tabellen (auch Stand nach Spieltag, Heim/Auswärts), Spielplan, Kreuztabelle, Statistiken, Punktekorrekturen, Archiv aller Saisons
- **Saison-Assistent:** neue Saison aus einer Vorlage (Auf-/Absteiger) oder ohne Vorlage, Spielplan als automatische Doppelrunde
- **Ergebnisdienst:** Spieltag-Editor mit Heimrecht-Tausch
- **Berichte:** Spielberichte und Spieltagsvorschauen mit Vorlagen, automatische Verknüpfung mit Spielen, Volltextsuche
- **Kader:** Spieler, Profile, Vereinshistorie, Torschützen
- **Forum:** Kategorien, Ungelesen-Markierung, Zitate, Formatierung, Bilder, Abos per Mail, Melden, Moderation
- **Konten:** Selbstregistrierung mit Mail-Bestätigung und Freigabe, Profilbilder, Rollen Admin/Redaktion/Mitglied
- **Sponsoren:** Banner (Header, Seiten, im Bericht) mit Laufzeiten, Sichtkontakten und Klicks – ohne Cookies und Drittanbieter
- **Betrieb:** tägliches Mail-Briefing für das Team, Design-Editor (Farben, Name), Dunkelmodus, Release-Notes im Admin

## Voraussetzungen

- PHP 8.0 oder neuer mit `pdo_sqlite`, `mbstring`, `json` (für Bild-Uploads zusätzlich `gd`)
- Apache mit `.htaccess` (Zugriffssperren für `data/`, `config/`, `lib/`, `scripts/`, `cron/`)

## Installation

```bash
git clone https://github.com/stefanbuell-wq/Liga-Manager-Online.git
cd Liga-Manager-Online
php scripts/install.php --name="Musterliga.de" --tagline="Fußball in Musterstadt" --url=https://musterliga.de
```

Das Skript prüft die Voraussetzungen, legt `data/` und die Datenbank an, schreibt `config/instance.php`
und fragt das Passwort für das erste Admin-Konto ab. Danach:

1. Webserver auf den Ordner zeigen lassen, `/admin/` öffnen und anmelden.
2. Unter **Saison-Assistent → „+ Neue Liga ohne Vorlage“** die erste Liga mit Mannschaften anlegen.
3. Optional: `config/mail.example.php` nach `config/mail.php` kopieren (SMTP; ohne Datei wird PHP `mail()` benutzt).
4. Impressum und Datenschutzerklärung als `impressum.html` und `datenschutz.html` ablegen (nicht im Repository).
5. Vereinswappen als `img/teams/<Vereinsname ohne Sonderzeichen>.gif` ablegen (optional `img/teams/hd/*.png`).

Lokal ausprobieren: `php -S localhost:8000` im Projektordner, dann http://localhost:8000/.

### Demo

```bash
php scripts/install.php --demo
```

Installiert die Oberfläche im Stil von [hafo.de](https://hafo.de) (Name, Farben, „HAFO-Tipp“) mit Beispieldaten:
eine Liga mit zehn erfundenen Hamburger Vereinen, acht gespielte Spieltage, ein Spielbericht, eine Vorschau und ein
kleines Forum. Ohne `--demo` heißt die Seite „Liga Manager Online“ und startet leer.

Weitere Admin-Konten oder ein vergessenes Admin-Passwort: `php cron/create-admin.php <name>`.

## Eigene Installation anpassen

Alles, was eine Installation ausmacht, steht in `config/instance.php` (Vorlage: `config/instance.example.php`):
Name und Untertitel, Adresse für Links in Mails, Kürzel im Betreff, Beschriftung des Tipps in Vorschauen,
Region für Texte, Standardliga und Kurznamen im Liga-Menü. Name, Untertitel und Farben lassen sich zusätzlich
im Admin unter **Design** ändern.

## Regelmäßige Aufgaben (Cron)

| Skript | Zweck |
|--------|-------|
| `cron/daily-briefing.php` | Morgendliche Mail an das Team: offene Anfragen, Registrierungen, Meldungen (`--dry-run` zum Testen) |
| `cron/deactivate-inactive.php` | Inaktive Konten nach Frist deaktivieren |

## Aufbau

```
admin/    Verwaltung (Ligen, Ergebnisse, Saison-Assistent, Berichte, Kader, Forum, Benutzer, Sponsoren, Design)
api/      JSON-Endpunkte
config/   instance.php, mail.php (nicht im Repository; Vorlagen *.example.php)
cron/     Kommandozeilen-Aufgaben
css/ js/ fonts/ img/
data/     SQLite-Datenbank, Cache, Sitzungen (nicht im Repository)
lib/      PHP-Klassen (Datenbank, Repositories, Sicherheit, Mail, Instanz)
scripts/  Installation und Wartung (nur Kommandozeile)
docs/     Entwickler-Notizen
```

Hinweise für die Entwicklung (Architektur, Konventionen, Sicherheitsregeln): [CLAUDE.md](CLAUDE.md), [SECURITY.md](SECURITY.md).
Änderungen: [RELEASE-NOTES.md](RELEASE-NOTES.md).

## Herkunft und Lizenz

Der ursprüngliche Liga Manager Online stammt von Rene Marth ([liga-manager-online.de](https://liga-manager-online.de))
und steht unter der GNU General Public License. Rene Marth hat am 27.01.2026 der Weiterentwicklung unter dem Namen
„Liga Manager Online“ und der Veröffentlichung zugestimmt.

LMO26 wurde 2025/2026 von Stefan Büll ([Buell Digital](https://buell-digital.de)) für hafo.de neu entwickelt und steht
unter der **GNU General Public License v3.0** – siehe [LICENSE](LICENSE).
