<?php
/**
 * Instanz-Einstellungen. Kopieren nach config/instance.php und anpassen.
 * config/instance.php ist nicht im Repository (siehe .gitignore).
 * Name und Untertitel lassen sich spaeter auch im Admin unter "Design" aendern.
 */
return [
    'site_name'    => 'Liga Manager Online',   // Anzeigename, z. B. "Musterliga.de"
    'site_tagline' => '',                      // Untertitel, z. B. "Fußball in Musterstadt"
    'base_url'     => '',                      // https://example.org (fuer Links in Mails; leer = aktuelle Adresse)
    'mail_tag'     => '',                      // Kuerzel im Betreff interner Mails: "[KUERZEL] ..."; leer = site_name
    'tip_label'    => 'Tipp',                  // Beschriftung des Tipps in Spieltagsvorschauen
    'region'       => '',                      // fuer Texte, z. B. "Musterstädter Amateurfußball"
    'legacy_forum' => false,                   // true: Hinweis zur Uebernahme alter Forenkonten bei der Registrierung
    'legacy_host'  => '',                      // Host der frueheren Website, deren Bild-Links auf legacy/ umgebogen werden
    'mail_mode'    => 'send',                  // 'log' = keine Mails versenden, nur nach data/mail-log.txt (Testumgebung)
    'test_banner'  => '',                      // z. B. 'Testumgebung – Daten sind eine Kopie' (roter Balken oben)
];
