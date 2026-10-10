<?php
// Společné pro stránky rezervací v administraci: přihlášení přes admin, ochrana formulářů, hlavičky.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
$user = require_auth();
require_once dirname(__DIR__) . '/rezervace/lib.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
if (empty($_SESSION['rez_csrf'])) {
    $_SESSION['rez_csrf'] = bin2hex(random_bytes(16));
}
function rez_admin_csrf(): string { return (string)$_SESSION['rez_csrf']; }
function rez_admin_csrf_ok(): bool
{
    $got = (string)($_SERVER['HTTP_X_REZ_CSRF'] ?? ($_POST['rez_csrf'] ?? ''));
    return $got !== '' && hash_equals(rez_admin_csrf(), $got);
}
function rez_admin_headers(): void
{
    // Kalendář potřebuje styly vložené skriptem (pozice termínů) a písma z Google Fonts
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
}
function rez_patient_url(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'adentdh.com');
    return 'https://' . $host . '/rezervace/' . (rez_cfg('test_mode', true) ? '?k=' . rawurlencode((string)rez_cfg('access_key', '')) : '');
}
