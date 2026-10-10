<?php
declare(strict_types=1);
defined('ADMIN_ROOT') or exit;

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function flash_set(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flash_render(): string
{
    $out = '';
    foreach ((array)($_SESSION['flash'] ?? []) as $f) {
        $out .= '<div class="alert alert-' . h($f['type']) . '" role="status">' . h($f['msg']) . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

/**
 * Začátek stránky. $active: dashboard | settings | account | ''.
 * $user = null → stránka bez navigace (login, setup).
 */
function page_start(string $title, ?array $user = null, string $active = '', array $opts = []): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="cs"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow, noarchive">'
        . '<title>' . h($title) . ' · ADent. Admin</title>'
        . '<link rel="stylesheet" href="assets/admin.css">';
    foreach ((array)($opts['css'] ?? []) as $css) {
        echo '<link rel="stylesheet" href="assets/' . h($css) . '">';
    }
    echo '</head><body>';

    if ($user === null) {
        echo '<main class="auth-wrap"><div class="auth-card"><div class="brand brand-lg"><span class="wordmark">ADent.</span><span class="brand-sub">Admin</span></div>';
        return;
    }

    echo admin_topbar($active) . '<main class="page' . (!empty($opts['wide']) ? ' page-wide' : '') . '">';
}

/** Horní lišta administrace (používá ji i stránka Rezervace). */
function admin_topbar(string $active): string
{
    $nav = [
        'dashboard' => ['index.php', 'Přehled'],
        'rezervace' => ['rezervace.php', 'Rezervace'],
        'settings' => ['settings.php', 'Napojení Google'],
        'site' => ['site.php', 'Editor webu'],
        'account' => ['account.php', 'Účet'],
    ];
    $o = '<header class="topbar"><div class="topbar-in">'
        . '<a class="brand" href="index.php"><span class="wordmark">ADent.</span><span class="brand-sub">Admin</span></a><nav class="nav">';
    foreach ($nav as $key => [$href, $label]) {
        $o .= '<a href="' . $href . '"' . ($key === $active ? ' class="active" aria-current="page"' : '') . '>' . h($label) . '</a>';
    }
    return $o . '<span class="nav-soon" title="Připravujeme">Tržby <i>brzy</i></span>'
        . '</nav><form method="post" action="logout.php" class="logout">' . csrf_field()
        . '<button class="btn btn-ghost" type="submit">Odhlásit</button></form></div></header>';
}

function page_end(bool $card = false, bool $qr = false, array $scripts = []): void
{
    echo $card ? '</div></main>' : '</main>';
    if ($qr) {
        echo '<script src="assets/qrcode.js"></script>';
    }
    echo '<script src="assets/app.js"></script>';
    foreach ($scripts as $js) {
        echo '<script src="assets/' . h($js) . '"></script>';
    }
    echo '</body></html>';
}

/* ---------- Formátování ---------- */

function fmt_int($n): string
{
    return number_format((float)$n, 0, ',', "\u{00A0}");
}

function fmt_dec($n, int $d = 1): string
{
    return number_format((float)$n, $d, ',', "\u{00A0}");
}

function fmt_pct($ratio, int $d = 1): string
{
    return fmt_dec((float)$ratio * 100, $d) . "\u{00A0}%";
}

function fmt_date(string $ymd): string
{
    $t = strtotime($ymd);
    return $t ? date('j. n.', $t) : $ymd;
}

function fmt_datetime(?int $ts): string
{
    return $ts ? date('j. n. Y H:i', $ts) : '–';
}

/** Změna proti předchozímu období. $lowerBetter pro pozici ve vyhledávání (nižší = lepší). */
function delta_badge(float $cur, float $prev, bool $absolute = false, bool $lowerBetter = false): string
{
    if ($prev == 0.0 && !$absolute) {
        return '<span class="delta flat">bez srovnání</span>';
    }
    $diff = $absolute ? $cur - $prev : ($cur - $prev) / $prev * 100;
    if (abs($diff) < 0.05) {
        return '<span class="delta flat">beze změny</span>';
    }
    $up = $diff > 0;
    $good = $lowerBetter ? !$up : $up;
    $txt = $absolute ? fmt_dec(abs($diff), 1) : fmt_dec(abs($diff), 0) . "\u{00A0}%";
    return '<span class="delta ' . ($good ? 'good' : 'bad') . '">' . ($up ? '▲' : '▼') . ' ' . $txt . '</span>';
}

function kpi(string $label, string $value, string $deltaHtml = ''): string
{
    return '<div class="kpi"><div class="kpi-label">' . h($label) . '</div><div class="kpi-value">' . $value . '</div>'
        . '<div class="kpi-delta">' . $deltaHtml . '</div></div>';
}

/* ---------- Grafy (čisté SVG, bez JS a bez inline stylů kvůli CSP) ---------- */

/** @param array<int,array{label:string,value:float|int}> $points */
function svg_line(array $points, string $unitLabel = ''): string
{
    $n = count($points);
    if ($n < 2) {
        return '<p class="muted">Pro graf zatím nejsou dostupná data.</p>';
    }
    $w = 760; $hgt = 220; $padL = 44; $padR = 12; $padT = 14; $padB = 26;
    $max = 0.0;
    foreach ($points as $p) {
        $max = max($max, (float)$p['value']);
    }
    $max = $max <= 0 ? 1.0 : $max;
    // hezké zaokrouhlení maxima
    $pow = 10 ** floor(log10($max));
    $max = ceil($max / $pow * 2) / 2 * $pow;
    $iw = $w - $padL - $padR; $ih = $hgt - $padT - $padB;

    $coords = [];
    foreach ($points as $i => $p) {
        $x = $padL + $iw * ($i / ($n - 1));
        $y = $padT + $ih * (1 - (float)$p['value'] / $max);
        $coords[] = [round($x, 1), round($y, 1)];
    }
    $line = implode(' ', array_map(fn($c) => $c[0] . ',' . $c[1], $coords));
    $area = $padL . ',' . ($padT + $ih) . ' ' . $line . ' ' . ($padL + $iw) . ',' . ($padT + $ih);

    $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $hgt . '" role="img" aria-label="Graf vývoje' . ($unitLabel ? ' – ' . h($unitLabel) : '') . '">';
    for ($g = 0; $g <= 4; $g++) {
        $y = $padT + $ih * ($g / 4);
        $val = $max * (1 - $g / 4);
        $svg .= '<line class="grid" x1="' . $padL . '" x2="' . ($padL + $iw) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '"/>'
            . '<text class="axis" x="' . ($padL - 6) . '" y="' . round($y + 4, 1) . '" text-anchor="end">' . h(fmt_int(round($val))) . '</text>';
    }
    $svg .= '<polygon class="area" points="' . $area . '"/><polyline class="line" points="' . $line . '"/>';
    $last = $coords[$n - 1];
    $svg .= '<circle class="dot" cx="' . $last[0] . '" cy="' . $last[1] . '" r="3.5"/>';
    $svg .= '<text class="axis" x="' . $padL . '" y="' . ($hgt - 6) . '" text-anchor="start">' . h(fmt_date($points[0]['label'])) . '</text>'
        . '<text class="axis" x="' . ($padL + $iw) . '" y="' . ($hgt - 6) . '" text-anchor="end">' . h(fmt_date($points[$n - 1]['label'])) . '</text>';
    // neviditelné cíle s tooltipem (title)
    foreach ($coords as $i => $c) {
        $svg .= '<circle class="hit" cx="' . $c[0] . '" cy="' . $c[1] . '" r="7"><title>' . h(fmt_date($points[$i]['label'])) . ': ' . h(fmt_int($points[$i]['value'])) . '</title></circle>';
    }
    return $svg . '</svg>';
}

/** Horizontální pruh v tabulce (šířka v % z $max). */
function svg_bar(float $value, float $max): string
{
    $pct = $max > 0 ? max(1, min(100, $value / $max * 100)) : 0;
    return '<svg class="bar" viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true"><rect class="bar-bg" width="100" height="6" rx="3"/>'
        . '<rect class="bar-fg" width="' . round($pct, 1) . '" height="6" rx="3"/></svg>';
}
