<?php
header('Content-Type: text/plain; charset=utf-8');
echo "PHP: " . PHP_VERSION . "\n";
echo "SQLite: " . (extension_loaded('pdo_sqlite') ? 'ANO' : 'NE') . "\n";
echo "MySQL: " . (extension_loaded('pdo_mysql') ? 'ANO' : 'NE') . "\n";
echo "mail(): " . (function_exists('mail') ? 'ANO' : 'NE') . "\n";
echo "Zápis do složky: " . (is_writable(__DIR__) ? 'ANO' : 'NE') . "\n";
