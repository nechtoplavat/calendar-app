<?php
// Společné pro stránky rezervací v administraci: přihlášení přes admin, ochrana formulářů, hlavičky.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/rezervace/lib.php';

// Stránky: běžné přihlášení administrace (jinak přesměruje na login.php)
if (!defined('REZ_API')) {
    $user = require_auth();
}

/**
 * Rozhraní: místo přesměrování vrací JSON 401. $touch = false → jen ověří přihlášení a neprodlouží ho
 * (pravidelná kontrola změn nesmí držet přihlášení věčně otevřené – odhlášení po 30 min nečinnosti platí dál).
 */
function rez_api_user(bool $touch): array
{
    if ($touch) {
        $u = auth_user();
    } else {
        $uid = $_SESSION['uid'] ?? null;
        $now = time();
        $ok = $uid && $now - (int)($_SESSION['last'] ?? 0) <= SESSION_IDLE && $now - (int)($_SESSION['started'] ?? 0) <= SESSION_ABSOLUTE;
        $u = $ok ? ['key' => (string)$uid] : null;
    }
    if (!$u) {
        rez_json(['ok' => false, 'error' => 'Přihlášení vypršelo – obnovte prosím stránku a přihlaste se znovu.'], 401);
    }
    return $u;
}
function rez_admin_csrf(): string { return csrf_token(); }
function rez_admin_csrf_ok(): bool
{
    $got = (string)($_SERVER['HTTP_X_REZ_CSRF'] ?? ($_POST['csrf'] ?? ''));
    return $got !== '' && hash_equals(csrf_token(), $got);
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
// Která hygienistka z rezervací odpovídá přihlášenému účtu administrace (podle pole „Přihlašovací e-mail“ / jména účtu)
function rez_staff_for_user(?array $S, array $user): string
{
    $key = mb_strtolower((string)($user['key'] ?? ''));
    foreach ($S['staff'] ?? [] as $x) {
        $login = mb_strtolower(trim((string)($x['login'] ?? '')));
        if ($login !== '' && ($login === $key || strstr($login, '@', true) === $key)) {
            return (string)$x['id'];
        }
    }
    return '';
}
function rez_topbar(): string
{
    // Stejná lišta jako zbytek administrace (z upraveného inc/view.php); starší view.php → jednoduchá navigace
    if (function_exists('admin_topbar')) {
        return admin_topbar('rezervace');
    }
    return '<header class="topbar"><div class="topbar-in"><a class="brand" href="index.php"><span class="wordmark">ADent.</span><span class="brand-sub">Admin</span></a>'
        . '<nav class="nav"><a href="index.php">Přehled</a><a href="rezervace.php" class="active">Rezervace</a><a href="site.php">Editor webu</a></nav></div></header>';
}
