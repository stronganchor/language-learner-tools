<?php
/** Isolated BSD-3-Clause firebase/php-jwt runtime; see provenance.json. */
if (!defined('WPINC')) { die; }
spl_autoload_register(static function (string $class): void {
    $prefix = 'LLTools\\Vendor\\Firebase\\JWT\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $name = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) {
        return;
    }
    $file = __DIR__ . '/src/' . $name . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
