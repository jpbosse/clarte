<?php
/**
 * Autoloader PSR-4 minimal (sans dependance Composer).
 * Si vous preferez Composer : `composer install` remplacera ce fichier.
 */
spl_autoload_register(function ($class) {
    $prefix = 'Clarte\\';
    $baseDir = dirname(__DIR__) . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});
