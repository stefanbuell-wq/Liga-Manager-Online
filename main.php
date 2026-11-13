<?php
// Alte Adresse main.php (Lesezeichen aus dem frueheren hafo.de) -> Startseite.
// Parameter der alten Seite werden bewusst nicht uebernommen.
header('Location: /', true, 301);
header('Cache-Control: public, max-age=86400');
exit;
