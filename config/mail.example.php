<?php
/**
 * Kopieren nach config/mail.php und ausfuellen (config/ wird nicht versioniert und
 * ist per .htaccess gesperrt). Postfach beim Hoster anlegen, z. B.
 * noreply@example.org. Passwort nicht weitergeben oder committen.
 */
return [
    'host'      => 'smtp.example.org',
    'port'      => 465,            // 465 = SSL, 587 = STARTTLS
    'secure'    => 'ssl',          // 'ssl' oder 'tls'
    'username'  => 'noreply@example.org',
    'password'  => 'HIER-PASSWORT-EINTRAGEN',
    'from'      => 'noreply@example.org',
    'from_name' => '',            // leer = Name aus config/instance.php
    'reply_to'  => 'noreply@example.org',
    // Empfaenger fuer "Neue Registrierung wartet auf Freigabe"
    'admin_notify' => '',
    // Empfaenger fuer neue Kontaktanfragen (kontakt.html); leer = admin_notify
    'contact_notify' => '',
    // Basis-URL fuer Links in Mails
    'base_url'  => '',            // leer = base_url aus config/instance.php
];
