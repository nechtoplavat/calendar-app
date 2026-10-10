<?php
// Rezervace ADent. – společné funkce serveru (databáze, kontrola termínů, e-maily).
// Soubor se jen vkládá, sám nic nevypisuje.
declare(strict_types=1);

if (defined('REZ_LIB')) {
    return;
}
define('REZ_LIB', 1);
define('REZ_DIR', __DIR__);
date_default_timezone_set('Europe/Prague');
mb_internal_encoding('UTF-8');

/* ---------------- Nastavení ---------------- */

function rez_cfg(string $key, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $f = REZ_DIR . '/config.php';
        if (!is_file($f)) {
            rez_install_config($f);
        }
        $cfg = is_file($f) ? (require $f) : [];
        if (!is_array($cfg)) {
            $cfg = [];
        }
    }
    return $cfg[$key] ?? $default;
}

// Při prvním spuštění se vytvoří config.php s náhodným přístupovým kódem pro zkušební verzi.
function rez_install_config(string $f): void
{
    $key = bin2hex(random_bytes(8));
    $php = "<?php\n// Nastavení rezervací. Vytvořeno automaticky " . date('j. n. Y H:i') . ".\nreturn [\n"
        . "    // Zkušební režim: všechny e-maily jdou jen na test_email, pacientská stránka je jen s přístupovým kódem.\n"
        . "    'test_mode' => true,\n"
        . "    'test_email' => 't39.jonas8@gmail.com',\n"
        . "    // Kam chodí upozornění ordinaci na novou rezervaci\n"
        . "    'clinic_email' => 't39.jonas8@gmail.com',\n"
        . "    // Odesílatel e-mailů (musí být na doméně webu, jinak je pošta může odmítnout)\n"
        . "    'mail_from' => 'rezervace@adentdh.com',\n"
        . "    'mail_from_name' => 'ADent. Dentální hygiena',\n"
        . "    // Přístupový kód pro pacientskou část ve zkušebním režimu\n"
        . "    'access_key' => '" . $key . "',\n"
        . "];\n";
    @file_put_contents($f, $php, LOCK_EX);
    @chmod($f, 0600);
}

/* ---------------- Databáze ---------------- */

function rez_db(): PDO
{
    static $db = null;
    if ($db) {
        return $db;
    }
    $dir = REZ_DIR . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    $db = new PDO('sqlite:' . $dir . '/rezervace.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('CREATE TABLE IF NOT EXISTS state (id INTEGER PRIMARY KEY CHECK (id = 1), version INTEGER NOT NULL, json TEXT NOT NULL, updated INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, t INTEGER NOT NULL, channel TEXT NOT NULL, kind TEXT NOT NULL,
        booking_id INTEGER, to_addr TEXT, orig_to TEXT, subject TEXT, body TEXT, sent INTEGER NOT NULL DEFAULT 0, error TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS tokens (booking_id INTEGER PRIMARY KEY, token TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS hits (ip TEXT NOT NULL, t INTEGER NOT NULL)');
    return $db;
}

// Zápis vždy v transakci, aby se dvě současné objednávky nepřepsaly
function rez_tx(callable $fn)
{
    $db = rez_db();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $r = $fn($db);
        $db->exec('COMMIT');
        return $r;
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function rez_load(): array
{
    $row = rez_db()->query('SELECT version, json FROM state WHERE id = 1')->fetch();
    if (!$row) {
        return ['version' => 0, 'state' => null];
    }
    $st = json_decode((string)$row['json'], true);
    return ['version' => (int)$row['version'], 'state' => is_array($st) ? $st : null];
}

function rez_store(array $state, int $version): void
{
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $q = rez_db()->prepare('INSERT INTO state (id, version, json, updated) VALUES (1, :v, :j, :t)
        ON CONFLICT(id) DO UPDATE SET version = :v, json = :j, updated = :t');
    $q->execute([':v' => $version, ':j' => $json, ':t' => time()]);
}

function rez_json(array $d, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rez_body(int $max = 6000000): array
{
    $raw = (string)file_get_contents('php://input', false, null, 0, $max + 1);
    if (strlen($raw) > $max) {
        rez_json(['ok' => false, 'error' => 'Data jsou příliš velká.'], 413);
    }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/* ---------------- Čas a formát ---------------- */

const REZ_DAYS = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];
const REZ_MONTHS_G = ['ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

function rez_today(): string { return date('Y-m-d'); }
function rez_now_min(): int { return (int)date('G') * 60 + (int)date('i'); }
function rez_hm(int $m): string { return intdiv($m, 60) . ':' . str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT); }
function rez_d(string $iso): DateTime { return new DateTime($iso . ' 00:00:00'); }
function rez_dlong(string $iso): string
{
    $d = rez_d($iso);
    return REZ_DAYS[(int)$d->format('w')] . ' ' . (int)$d->format('j') . '. ' . REZ_MONTHS_G[(int)$d->format('n') - 1];
}
function rez_money($n): string { return number_format((float)round((float)$n), 0, ',', "\u{00A0}") . "\u{00A0}Kč"; }
function rez_valid_date(string $s): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $s));
    return checkdate($m, $d, $y);
}

/* ---------------- Doména (stejná pravidla jako v aplikaci) ---------------- */

function rez_staff(array $S, string $id): ?array
{
    foreach ($S['staff'] ?? [] as $x) {
        if (($x['id'] ?? '') === $id) {
            return $x;
        }
    }
    return null;
}
function rez_def_staff(array $S): string
{
    $d = (string)($S['defStaff'] ?? '');
    if ($d !== '' && rez_staff($S, $d)) {
        return $d;
    }
    foreach ($S['staff'] ?? [] as $x) {
        if (($x['role'] ?? '') === 'admin') {
            return (string)$x['id'];
        }
    }
    return (string)($S['staff'][0]['id'] ?? '');
}
function rez_shifts_on(array $S, string $date): array
{
    $out = [];
    foreach ($S['dayShifts'][$date] ?? [] as $x) {
        if (rez_staff($S, (string)($x['staff'] ?? ''))) {
            $out[] = $x;
        }
    }
    usort($out, fn($a, $b) => $a['start'] <=> $b['start']);
    return $out;
}
function rez_merge_iv(array $iv): array
{
    usort($iv, fn($a, $b) => $a[0] <=> $b[0]);
    $out = [];
    foreach ($iv as $x) {
        $n = count($out);
        if ($n && $x[0] <= $out[$n - 1][1]) {
            $out[$n - 1][1] = max($out[$n - 1][1], $x[1]);
        } else {
            $out[] = $x;
        }
    }
    return $out;
}
// Kdy ošetřuje daná hygienistka: její směny; výchozí navíc vše v otevíracím rozsahu, co nepokrývá jiná směna
function rez_owned(array $S, string $sid, string $date): array
{
    $sh = rez_shifts_on($S, $date);
    $own = [];
    foreach ($sh as $x) {
        if ($x['staff'] === $sid) {
            $own[] = [(int)$x['start'], (int)$x['end']];
        }
    }
    if ($sid === rez_def_staff($S)) {
        $win = $S['win'] ?? [420, 1200];
        $free = [[(int)$win[0], (int)$win[1]]];
        foreach ($sh as $x) {
            if ($x['staff'] === $sid) {
                continue;
            }
            $nf = [];
            foreach ($free as $f) {
                if ($x['end'] <= $f[0] || $x['start'] >= $f[1]) {
                    $nf[] = $f;
                } else {
                    if ($x['start'] > $f[0]) $nf[] = [$f[0], (int)$x['start']];
                    if ($x['end'] < $f[1]) $nf[] = [(int)$x['end'], $f[1]];
                }
            }
            $free = $nf;
        }
        $own = array_merge($own, $free);
    }
    return rez_merge_iv($own);
}
function rez_owner_at(array $S, string $date, int $t): string
{
    foreach ($S['staff'] ?? [] as $x) {
        foreach (rez_owned($S, (string)$x['id'], $date) as $h) {
            if ($t >= $h[0] && $t < $h[1]) {
                return (string)$x['id'];
            }
        }
    }
    return rez_def_staff($S);
}
function rez_open(array $S, string $date): array
{
    $iv = [];
    foreach ($S['staff'] ?? [] as $x) {
        $iv = array_merge($iv, rez_owned($S, (string)$x['id'], $date));
    }
    return rez_merge_iv($iv);
}
function rez_lm_pct(array $S, string $date, int $start): int
{
    $lm = $S['lm'] ?? [];
    if (empty($lm['on'])) {
        return 0;
    }
    $when = rez_d($date)->getTimestamp() + $start * 60;
    $h = ($when - time()) / 3600;
    return ($h > 0 && $h <= (float)($lm['hours'] ?? 48)) ? (int)($lm['pct'] ?? 0) : 0;
}
function rez_discounted($price, int $pct): int { return (int)round((float)$price * (100 - $pct) / 100); }
function rez_service(array $S, string $id): ?array
{
    foreach ($S['services'] ?? [] as $s) {
        if (($s['id'] ?? '') === $id) {
            return $s;
        }
    }
    return null;
}

// Je čas volný pro objednání přes web? Vrací chybovou hlášku, nebo ''.
function rez_check_slot(array $S, string $date, int $start, int $dur): string
{
    if (!rez_valid_date($date)) {
        return 'Neplatné datum.';
    }
    $min = (new DateTime('tomorrow'))->format('Y-m-d');
    $t = new DateTime('today');
    $months = max(1, (int)($S['horizonMonths'] ?? 3));
    $y = (int)$t->format('Y');
    $m = (int)$t->format('n') + $months;
    $y += intdiv($m - 1, 12);
    $m = ($m - 1) % 12 + 1;
    $day = min((int)$t->format('j'), (int)(new DateTime(sprintf('%04d-%02d-01', $y, $m)))->format('t'));
    $max = sprintf('%04d-%02d-%02d', $y, $m, $day);
    if ($date < $min) {
        return 'Přes web se lze objednat nejdříve na zítřek.';
    }
    if ($date > $max) {
        return 'Tak daleko dopředu se zatím objednat nedá.';
    }
    $step = max(5, (int)($S['step'] ?? 15));
    if ($start < 0 || $start % $step !== 0 || $dur < 5) {
        return 'Neplatný čas.';
    }
    $inside = false;
    foreach (rez_open($S, $date) as $h) {
        if ($start >= $h[0] && $start + $dur <= $h[1]) {
            $inside = true;
            break;
        }
    }
    if (!$inside) {
        return 'V tuto dobu je zavřeno.';
    }
    $owner = rez_owner_at($S, $date, $start);
    foreach ($S['bookings'] ?? [] as $b) {
        if (($b['date'] ?? '') === $date && ($b['status'] ?? '') !== 'cancelled'
            && $start < $b['start'] + $b['dur'] && $start + $dur > $b['start']) {
            return 'Tento čas si mezitím někdo zarezervoval. Vyberte prosím jiný.';
        }
    }
    foreach ($S['blocks'] ?? [] as $k) {
        if (($k['date'] ?? '') === $date && (empty($k['staff']) || $k['staff'] === $owner)
            && $start < $k['end'] && $start + $dur > $k['start']) {
            return 'V tuto dobu je zavřeno.';
        }
    }
    return '';
}

// Co smí vidět pacientská stránka: žádná jména, telefony ani poznámky jiných pacientů
function rez_public(array $S): array
{
    $from = (new DateTime('yesterday'))->format('Y-m-d');
    $bk = [];
    foreach ($S['bookings'] ?? [] as $b) {
        if (($b['status'] ?? '') !== 'cancelled' && ($b['date'] ?? '') >= $from) {
            $bk[] = ['id' => $b['id'], 'date' => $b['date'], 'start' => $b['start'], 'dur' => $b['dur'], 'status' => $b['status'], 'staff' => $b['staff'] ?? '', 'svc' => $b['svc'] ?? ''];
        }
    }
    $bl = [];
    foreach ($S['blocks'] ?? [] as $k) {
        if (($k['date'] ?? '') >= $from) {
            $bl[] = ['id' => $k['id'], 'date' => $k['date'], 'start' => $k['start'], 'end' => $k['end'], 'staff' => $k['staff'] ?? '', 'reason' => 'Zavřeno'];
        }
    }
    $ds = [];
    foreach ($S['dayShifts'] ?? [] as $d => $list) {
        if ($d >= $from) {
            $ds[$d] = array_map(fn($x) => ['id' => $x['id'], 'staff' => $x['staff'], 'start' => $x['start'], 'end' => $x['end']], (array)$list);
        }
    }
    $staff = array_map(fn($x) => ['id' => $x['id'], 'role' => $x['role'] ?? 'staff', 'name' => '', 'short' => '', 'color' => $x['color'] ?? 'st1', 'share' => null, 'login' => ''], $S['staff'] ?? []);
    $msg = $S['msg'] ?? [];
    return [
        'services' => array_values(array_filter($S['services'] ?? [], fn($s) => !empty($s['online']))),
        'staff' => $staff, 'bookings' => $bk, 'blocks' => $bl, 'dayShifts' => $ds,
        'win' => $S['win'] ?? [420, 1200], 'defStaff' => rez_def_staff($S), 'step' => $S['step'] ?? 15,
        'horizonMonths' => $S['horizonMonths'] ?? 3, 'lm' => $S['lm'] ?? ['on' => false], 'cancelHours' => $S['cancelHours'] ?? 24,
        'msg' => ['intro' => $msg['intro'] ?? '', 'info' => $msg['info'] ?? new stdClass(), 'smsOn' => !empty($msg['smsOn']), 'smsUni' => !empty($msg['smsUni']), 'sms' => ''],
        'seq' => 1, 'cutV' => 1, 'noHyg' => 1, 'todo' => ['before' => [], 'after' => []], 'todoDone' => new stdClass(), 'todoExtra' => [],
    ];
}

/* ---------------- Zprávy pacientům ---------------- */

const REZ_VOC_EXC = [
    'karel' => 'Karle', 'pavel' => 'Pavle', 'havel' => 'Havle', 'lev' => 'Lve', 'petr' => 'Petře', 'michal' => 'Michale', 'kamil' => 'Kamile',
    'daniel' => 'Danieli', 'samuel' => 'Samueli', 'gabriel' => 'Gabrieli', 'rafael' => 'Rafaeli', 'emanuel' => 'Emanueli', 'marcel' => 'Marceli', 'noel' => 'Noeli',
    'ester' => 'Ester', 'dagmar' => 'Dagmar', 'miriam' => 'Miriam', 'ingrid' => 'Ingrid', 'karin' => 'Karin', 'carmen' => 'Carmen', 'nikol' => 'Nikol', 'rút' => 'Rút', 'ruth' => 'Ruth', 'kim' => 'Kim', 'sharon' => 'Sharon',
];
// Oslovení v 5. pádě – stejná pravidla jako v aplikaci
function rez_vocative(string $full): string
{
    $parts = preg_split('/\s+/u', trim($full));
    $w = (string)($parts[0] ?? '');
    if ($w === '') {
        return '';
    }
    $low = mb_strtolower($w);
    if (isset(REZ_VOC_EXC[$low])) return REZ_VOC_EXC[$low];
    if (preg_match('/a$/u', $low)) return mb_substr($w, 0, -1) . 'o';
    if (preg_match('/[eéěiíoóuúůyý]$/u', $low)) return $w;
    if (preg_match('/něk$/u', $low)) return mb_substr($w, 0, -3) . 'ňku';
    if (preg_match('/děk$/u', $low)) return mb_substr($w, 0, -3) . 'ďku';
    if (preg_match('/ek$/u', $low)) return mb_substr($w, 0, -2) . 'ku';
    if (preg_match('/(k|h|g|ch)$/u', $low)) return $w . 'u';
    if (preg_match('/[šžčřcjďťňx]$/u', $low)) return $w . 'i';
    if (preg_match('/[^aeiouyáéíóúůý]r$/u', $low)) return mb_substr($w, 0, -1) . 'ře';
    return $w . 'e';
}
function rez_fill(array $S, string $tpl, array $b): string
{
    $s = rez_service($S, (string)($b['svc'] ?? '')) ?? ['name' => ''];
    $v = ['jmeno' => ($b['vok'] ?? '') !== '' ? $b['vok'] : rez_vocative((string)$b['name']), 'celejmeno' => $b['name'], 'datum' => rez_dlong($b['date']),
        'cas' => rez_hm((int)$b['start']), 'sluzba' => $s['name'], 'cena' => rez_money($b['price'] ?? 0)];
    return preg_replace_callback('/\{(\w+)\}/u', fn($m) => array_key_exists($m[1], $v) ? (string)$v[$m[1]] : $m[0], $tpl);
}
function rez_e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function rez_mail_html(array $S, array $b, string $kind): string
{
    $s = rez_service($S, (string)$b['svc']) ?? ['name' => (string)$b['svc'], 'price' => $b['price'] ?? 0];
    $when = rez_dlong($b['date']) . ', ' . rez_hm((int)$b['start']) . '–' . rez_hm((int)$b['start'] + (int)$b['dur']);
    $p = fn($t) => '<p style="margin:0 0 12px">' . $t . '</p>';
    $greet = 'Dobrý den, ' . rez_e(rez_vocative((string)$b['name'])) . ',';
    if ($kind === 'confirm') {
        $intro = '';
        foreach (explode("\n", rez_fill($S, (string)($S['msg']['intro'] ?? ''), $b)) as $l) {
            $intro .= $p(rez_e($l));
        }
    } elseif ($kind === 'move') {
        $intro = $p($greet) . $p('váš termín jsme přesunuli. Nový termín je <b>' . rez_e($when) . '</b>.');
    } else {
        $intro = $p($greet) . $p('váš termín <b>' . rez_e($when) . '</b> je zrušený. Pokud si chcete objednat nový, napište nebo zavolejte na 727 927 890.');
    }
    $price = !empty($b['disc']) ? '<s>' . rez_money($s['price'] ?? 0) . '</s> <b>' . rez_money($b['price']) . '</b> (last minute −' . (int)$b['disc'] . ' %)' : rez_money($b['price'] ?? 0);
    $info = $kind === 'confirm' ? (string)($S['msg']['info'][$b['svc']] ?? '') : '';
    $rows = $kind === 'cancel' ? '' : '<table style="border-collapse:collapse;margin:4px 0 14px">'
        . '<tr><td style="padding:3px 14px 3px 0;color:#7a6a62">Kdy</td><td>' . rez_e($when) . '</td></tr>'
        . '<tr><td style="padding:3px 14px 3px 0;color:#7a6a62">Služba</td><td>' . rez_e((string)$s['name']) . '</td></tr>'
        . '<tr><td style="padding:3px 14px 3px 0;color:#7a6a62">Cena</td><td>' . $price . '</td></tr>'
        . '<tr><td style="padding:3px 14px 3px 0;color:#7a6a62">Kde</td><td>Poliklinika DAM, Stamicova 1968/21, Praha 6 – 2. patro, dveře 207</td></tr></table>';
    return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#332622;max-width:560px">'
        . '<p style="font-size:20px;margin:0 0 16px;font-family:Georgia,serif">ADent.</p>' . $intro . $rows
        . ($info !== '' ? $p('<b>' . rez_e($info) . '</b>') : '')
        . ($kind !== 'cancel' ? $p('Platit můžete hotově nebo QR kódem. Pokud nemůžete přijít, zrušte prosím termín nejpozději 24 hodin předem na 727 927 890.') : '')
        . '<p style="color:#7a6a62;font-size:13px;margin-top:20px">ADent. Dentální hygiena · adentdh.com</p></div>';
}

function rez_sms_text(array $S, array $b): string
{
    $t = rez_fill($S, (string)($S['msg']['sms'] ?? ''), $b);
    if (empty($S['msg']['smsUni'])) {
        $t = class_exists('Normalizer') ? preg_replace('/\p{Mn}/u', '', (string)Normalizer::normalize($t, Normalizer::FORM_D)) : $t;
    }
    return $t;
}

// Odeslání e-mailu (ve zkušebním režimu vždy jen na zkušební adresu) a záznam do přehledu zpráv
function rez_mail(string $kind, ?int $bookingId, string $to, string $subject, string $html): void
{
    $test = (bool)rez_cfg('test_mode', true);
    $orig = $to;
    if ($test) {
        $to = (string)rez_cfg('test_email', '');
        $subject = '[ZKOUŠKA] ' . $subject;
        $html = '<div style="background:#FFF2C4;padding:8px 12px;margin-bottom:14px;font:13px Arial">Zkušební verze rezervací – zpráva by šla na: <b>'
            . rez_e($orig) . '</b></div>' . $html;
    }
    $ok = false;
    $err = '';
    if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $from = (string)rez_cfg('mail_from', 'rezervace@adentdh.com');
        $name = (string)rez_cfg('mail_from_name', 'ADent.');
        $headers = 'From: ' . mb_encode_mimeheader($name, 'UTF-8') . ' <' . $from . ">\r\n"
            . 'Reply-To: ' . (string)rez_cfg('clinic_email', $from) . "\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
        $ok = @mail($to, mb_encode_mimeheader($subject, 'UTF-8'), chunk_split(base64_encode($html)), $headers, '-f' . $from);
        if (!$ok) {
            $ok = @mail($to, mb_encode_mimeheader($subject, 'UTF-8'), chunk_split(base64_encode($html)), $headers);
        }
        if (!$ok) {
            $err = 'Server e-mail nepřijal k odeslání.';
        }
    } else {
        $err = 'Chybí platná adresa.';
    }
    rez_db()->prepare('INSERT INTO outbox (t, channel, kind, booking_id, to_addr, orig_to, subject, body, sent, error) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([time(), 'email', $kind, $bookingId, $to, $orig, $subject, $html, $ok ? 1 : 0, $err]);
}

// SMS zatím jen zapisujeme do přehledu (SMS brána se napojí před ostrým provozem)
function rez_sms(string $kind, ?int $bookingId, string $phone, string $text): void
{
    rez_db()->prepare('INSERT INTO outbox (t, channel, kind, booking_id, to_addr, orig_to, subject, body, sent, error) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([time(), 'sms', $kind, $bookingId, $phone, $phone, '', $text, 0, 'SMS brána zatím není napojená – jen náhled.']);
}

function rez_notify_patient(array $S, array $b, string $kind): void
{
    $subj = ['confirm' => 'Potvrzení rezervace – ', 'move' => 'Změna termínu – ', 'cancel' => 'Zrušení termínu – '][$kind] . rez_dlong($b['date']) . ' v ' . rez_hm((int)$b['start']);
    if (!empty($b['email'])) {
        rez_mail($kind, (int)$b['id'], (string)$b['email'], $subj, rez_mail_html($S, $b, $kind));
    }
    if ($kind === 'confirm' && !empty($S['msg']['smsOn']) && !empty($b['phone'])) {
        rez_sms($kind, (int)$b['id'], (string)$b['phone'], rez_sms_text($S, $b));
    }
}

function rez_notify_clinic(array $S, array $b): void
{
    $to = (string)rez_cfg('clinic_email', '');
    if ($to === '') {
        return;
    }
    $s = rez_service($S, (string)$b['svc']) ?? ['name' => (string)$b['svc']];
    $who = rez_staff($S, (string)($b['staff'] ?? ''));
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#332622">'
        . '<p><b>Nová rezervace z webu</b></p><table style="border-collapse:collapse">'
        . '<tr><td style="padding:3px 14px 3px 0;color:#7a6a62">Kdy</td><td>' . rez_e(rez_dlong($b['date']) . ' ' . rez_hm((int)$b['start'])) . '</td></tr>'
        . '<tr><td style="padding:3px 14px 3px 0;color:#7a6a62">Služba</td><td>' . rez_e((string)$s['name']) . '</td></tr>'
        . '<tr><td style="padding:3px 14px 3px 0;color:#7a6a62">Pacient</td><td>' . rez_e((string)$b['name']) . '</td></tr>'
        . '<tr><td style="padding:3px 14px 3px 0;color:#7a6a62">Telefon</td><td>' . rez_e((string)$b['phone']) . '</td></tr>'
        . '<tr><td style="padding:3px 14px 3px 0;color:#7a6a62">E-mail</td><td>' . rez_e((string)$b['email']) . '</td></tr>'
        . (($b['note'] ?? '') !== '' ? '<tr><td style="padding:3px 14px 3px 0;color:#7a6a62">Poznámka</td><td>' . rez_e((string)$b['note']) . '</td></tr>' : '')
        . '<tr><td style="padding:3px 14px 3px 0;color:#7a6a62">Cena</td><td>' . rez_money($b['price']) . (!empty($b['disc']) ? ' (last minute −' . (int)$b['disc'] . ' %)' : '') . '</td></tr>'
        . ($who ? '<tr><td style="padding:3px 14px 3px 0;color:#7a6a62">Ošetří</td><td>' . rez_e((string)$who['name']) . ' (podle směny)</td></tr>' : '')
        . '</table></div>';
    rez_mail('clinic', (int)$b['id'], $to, 'Nová rezervace: ' . $b['name'] . ' – ' . rez_dlong($b['date']) . ' ' . rez_hm((int)$b['start']), $html);
}

/* ---------------- Uložení z ordinace ---------------- */

// Ordinace posílá celý stav. Objednávky a zrušení z webu, které mezitím přišly, se doplní – nic se nepřepíše.
function rez_merge_save(array $client, ?array $server, array &$added, array &$cancelled): array
{
    $ids = [];
    foreach ($client['bookings'] ?? [] as $i => $b) {
        $ids[(string)$b['id']] = $i;
    }
    foreach (($server['bookings'] ?? []) as $b) {
        $k = (string)$b['id'];
        if (!isset($ids[$k])) {
            $client['bookings'][] = $b;
            $added[] = $b;
        } elseif (($b['status'] ?? '') === 'cancelled' && ($b['cancelledBy'] ?? '') === 'patient'
            && ($client['bookings'][$ids[$k]]['status'] ?? '') === 'ok') {
            $client['bookings'][$ids[$k]]['status'] = 'cancelled';
            $client['bookings'][$ids[$k]]['cancelledBy'] = 'patient';
            $client['bookings'][$ids[$k]]['log'] = $b['log'] ?? ($client['bookings'][$ids[$k]]['log'] ?? []);
            $cancelled[] = $b['id'];
        }
    }
    return $client;
}

// Které zprávy vyvolala změna z ordinace (nový termín zadaný ručně, přesun s upozorněním, zrušení)
function rez_diff_notify(?array $old, array $new): void
{
    $prev = [];
    foreach ($old['bookings'] ?? [] as $b) {
        $prev[(string)$b['id']] = $b;
    }
    $today = rez_today();
    foreach ($new['bookings'] ?? [] as $b) {
        if (($b['date'] ?? '') < $today) {
            continue;
        }
        $o = $prev[(string)$b['id']] ?? null;
        if (!$o) {
            if (($b['status'] ?? '') === 'ok' && ($b['src'] ?? '') !== 'web' && $old !== null) {
                rez_notify_patient($new, $b, 'confirm');
            }
            continue;
        }
        if (($o['status'] ?? '') === 'ok' && ($b['status'] ?? '') === 'cancelled' && ($b['cancelledBy'] ?? '') !== 'patient') {
            rez_notify_patient($new, $b, 'cancel');
        } elseif (($b['status'] ?? '') === 'ok' && ($o['date'] !== $b['date'] || (int)$o['start'] !== (int)$b['start']) && !empty($b['ntf'])) {
            rez_notify_patient($new, $b, 'move');
        }
    }
}

function rez_valid_state(array $s): bool
{
    foreach (['services', 'staff', 'bookings', 'blocks'] as $k) {
        if (!isset($s[$k]) || !is_array($s[$k])) {
            return false;
        }
    }
    return count($s['staff']) > 0;
}

function rez_clean(string $s, int $max): string
{
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '');
    return mb_substr($s, 0, $max);
}

function rez_ip_limit(string $ip, int $max, int $window): bool
{
    $db = rez_db();
    $db->prepare('DELETE FROM hits WHERE t < ?')->execute([time() - $window]);
    $q = $db->prepare('SELECT COUNT(*) FROM hits WHERE ip = ?');
    $q->execute([$ip]);
    if ((int)$q->fetchColumn() >= $max) {
        return false;
    }
    $db->prepare('INSERT INTO hits (ip, t) VALUES (?, ?)')->execute([$ip, time()]);
    return true;
}
