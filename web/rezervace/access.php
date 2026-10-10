<?php
// Přístup k pacientské části ve zkušebním režimu: jen s kódem (?k=…), pak si ho prohlížeč pamatuje v cookie.
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

function rez_access_ok(): bool
{
    if (!rez_cfg('test_mode', true)) {
        return true;
    }
    $key = (string)rez_cfg('access_key', '');
    if ($key === '') {
        return false;
    }
    $got = (string)($_GET['k'] ?? ($_COOKIE['rez_k'] ?? ''));
    if (!hash_equals($key, $got)) {
        return false;
    }
    if (isset($_GET['k'])) {
        setcookie('rez_k', $key, ['expires' => time() + 86400 * 60, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
    }
    return true;
}
