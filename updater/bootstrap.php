<?php
/**
 * Updater-Bootstrap.
 *
 * Registriert einen minimalen Autoloader fuer den `Updater\`-Namespace.
 * Das Projekt hat keinen Composer/PSR-4-Autoloader – dieser Loader ist
 * vollstaendig isoliert und beeinflusst bestehende require-Aufrufe nicht.
 *
 * Beim Rueckbau des Updaters genuegt es, den Ordner `updater/` zu loeschen;
 * dieser Autoloader verschwindet damit ebenfalls.
 */

spl_autoload_register(function ($class) {
    $prefix = 'Updater\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
