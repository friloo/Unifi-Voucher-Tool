<?php
/**
 * Routen-Registrierung fuer den Updater.
 *
 * Dieses Projekt besitzt KEIN zentrales Routing-System (Slim/FastRoute/o.ae.) –
 * die Auslieferung erfolgt datei-basiert ueber den Webserver. Die Updater-Route
 * `/admin/update` wird daher durch die reale Datei `admin/update.php`
 * bereitgestellt (duenner Shim -> \Updater\UpdateController).
 *
 * Diese Datei existiert, um die Routing-Konvention der Vorlage zu erfuellen und
 * dokumentiert die einzige Routing-Einklink-Stelle. Sollte das Projekt spaeter
 * einen echten Router erhalten, koennen hier die Routen registriert werden:
 *
 *   $router->get('/admin/update',  fn() => (new \Updater\UpdateController($db, $auth))->handle());
 *   $router->post('/admin/update', fn() => (new \Updater\UpdateController($db, $auth))->handle());
 *
 * Aktuelle Endpunkte (alle ueber admin/update.php, Parameter `action`):
 *   GET  /admin/update.php                  -> Admin-UI
 *   GET  /admin/update.php?action=check     -> Update-Pruefung (JSON)
 *   GET  /admin/update.php?action=progress  -> Fortschritt (JSON)
 *   GET  /admin/update.php?action=migrations-> Migrations-Status (JSON)
 *   POST /admin/update.php  action=install      (+csrf_token)
 *   POST /admin/update.php  action=set_channel  (+csrf_token, channel)
 */

return [
    ['method' => 'GET',  'path' => '/admin/update', 'handler' => 'admin/update.php'],
    ['method' => 'POST', 'path' => '/admin/update', 'handler' => 'admin/update.php'],
];
