<?php
/**
 * Kopieren nach config/mail.php und ausfuellen (config/ wird nicht versioniert und
 * ist per .htaccess gesperrt). Postfach in hPanel > E-Mails anlegen, z. B.
 * noreply@buell-digital.de. Passwort gehoert nach Bitwarden, nicht in Chats.
 */
return [
    'host'      => 'smtp.hostinger.com',
    'port'      => 465,            // 465 = SSL, 587 = STARTTLS
    'secure'    => 'ssl',          // 'ssl' oder 'tls'
    'username'  => 'noreply@buell-digital.de',
    'password'  => 'HIER-PASSWORT-EINTRAGEN',
    'from'      => 'noreply@buell-digital.de',
    'from_name' => 'HAFO.de',
    'reply_to'  => 'noreply@buell-digital.de',
    // Empfaenger fuer "Neue Registrierung wartet auf Freigabe"
    'admin_notify' => '',
    // Empfaenger fuer neue Kontaktanfragen (kontakt.html); leer = admin_notify
    'contact_notify' => '',
    // Basis-URL fuer Links in Mails
    'base_url'  => 'https://hafo.de',
];
