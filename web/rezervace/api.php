<?php
// Rozhraní pro pacientskou stránku: volné termíny (bez osobních údajů), objednání, zrušení.
declare(strict_types=1);
require __DIR__ . '/access.php';

header('X-Robots-Tag: noindex, nofollow');
if (!rez_access_ok()) {
    rez_json(['ok' => false, 'error' => 'Zkušební verze – chybí přístupový kód.'], 403);
}
$a = (string)($_GET['a'] ?? 'state');
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0');

try {
    if ($a === 'state') {
        $cur = rez_load();
        if (!$cur['state']) {
            rez_json(['ok' => false, 'error' => 'Rezervace se teprve připravují. Zkuste to prosím později.'], 503);
        }
        rez_json(['ok' => true, 'version' => $cur['version'], 'state' => rez_public($cur['state'])]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        rez_json(['ok' => false, 'error' => 'Neplatný požadavek.'], 405);
    }
    $in = rez_body(20000);

    if ($a === 'book') {
        if (!empty($in['web'])) {   // robot vyplnil skryté pole
            rez_json(['ok' => false, 'error' => 'Rezervaci se nepodařilo odeslat.'], 400);
        }
        if (!rez_ip_limit($ip, 10, 3600)) {
            rez_json(['ok' => false, 'error' => 'Příliš mnoho pokusů. Zkuste to prosím za hodinu, nebo zavolejte 727 927 890.'], 429);
        }
        $name = rez_clean((string)($in['name'] ?? ''), 80);
        $phone = rez_clean((string)($in['phone'] ?? ''), 30);
        $email = rez_clean((string)($in['email'] ?? ''), 120);
        $note = rez_clean((string)($in['note'] ?? ''), 500);
        $date = (string)($in['date'] ?? '');
        $start = (int)($in['start'] ?? -1);
        $svcId = (string)($in['svc'] ?? '');
        if (count(preg_split('/\s+/u', $name)) < 2) rez_json(['ok' => false, 'error' => 'Napište prosím jméno i příjmení.']);
        if (strlen(preg_replace('/\D/', '', $phone)) < 9) rez_json(['ok' => false, 'error' => 'Telefon musí mít aspoň 9 číslic.']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) rez_json(['ok' => false, 'error' => 'Zkontrolujte prosím e-mail.']);

        $res = rez_tx(function () use ($name, $phone, $email, $note, $date, $start, $svcId) {
            $cur = rez_load();
            $S = $cur['state'];
            if (!$S) {
                return ['err' => 'Rezervace se teprve připravují.'];
            }
            $s = rez_service($S, $svcId);
            if (!$s || empty($s['online'])) {
                return ['err' => 'Tuto službu nelze objednat přes web.'];
            }
            $dur = (int)$s['dur'];
            $err = rez_check_slot($S, $date, $start, $dur);
            if ($err !== '') {
                return ['err' => $err];
            }
            $pct = rez_lm_pct($S, $date, $start);
            $id = (int)floor(microtime(true) * 1000);   // id z webu se nepotká s čísly z ordinace
            foreach ($S['bookings'] as $x) {
                if ((int)$x['id'] >= $id) $id = (int)$x['id'] + 1;
            }
            $b = ['id' => $id, 'staff' => rez_owner_at($S, $date, $start), 'date' => $date, 'start' => $start, 'dur' => $dur, 'svc' => $svcId,
                'name' => $name, 'phone' => $phone, 'email' => $email, 'note' => $note, 'inote' => '', 'status' => 'ok', 'src' => 'web',
                'price' => rez_discounted($s['price'], $pct), 'disc' => $pct, 'vok' => '',
                'log' => [['t' => time() * 1000, 'text' => 'Rezervace přes web' . ($pct ? ' (sleva last minute ' . $pct . ' %)' : '')]]];
            $S['bookings'][] = $b;
            rez_store($S, $cur['version'] + 1);
            $tok = bin2hex(random_bytes(16));
            rez_db()->prepare('INSERT OR REPLACE INTO tokens (booking_id, token) VALUES (?, ?)')->execute([$id, $tok]);
            return ['b' => $b, 'S' => $S, 'v' => $cur['version'] + 1, 'tok' => $tok];
        });
        if (isset($res['err'])) {
            rez_json(['ok' => false, 'error' => $res['err']], 409);
        }
        try {
            rez_notify_patient($res['S'], $res['b'], 'confirm');
            rez_notify_clinic($res['S'], $res['b']);
        } catch (Throwable $e) {
            // rezervace platí i když e-mail selže; chyba je vidět v přehledu zpráv
        }
        rez_json(['ok' => true, 'booking' => $res['b'], 'token' => $res['tok'], 'version' => $res['v'], 'state' => rez_public($res['S'])]);
    }

    if ($a === 'cancel') {
        $id = (int)($in['id'] ?? 0);
        $tok = (string)($in['token'] ?? '');
        $q = rez_db()->prepare('SELECT token FROM tokens WHERE booking_id = ?');
        $q->execute([$id]);
        $real = (string)$q->fetchColumn();
        if ($real === '' || !hash_equals($real, $tok)) {
            rez_json(['ok' => false, 'error' => 'Termín se nepodařilo ověřit. Zavolejte nám prosím na 727 927 890.'], 403);
        }
        $res = rez_tx(function () use ($id) {
            $cur = rez_load();
            $S = $cur['state'];
            foreach ($S['bookings'] as &$b) {
                if ((int)$b['id'] !== $id) continue;
                $when = rez_d($b['date'])->getTimestamp() + (int)$b['start'] * 60;
                if ($when - time() < (int)($S['cancelHours'] ?? 24) * 3600) {
                    return ['err' => 'Méně než 24 h předem jde termín zrušit jen telefonicky na 727 927 890.'];
                }
                if ($b['status'] !== 'ok') return ['err' => 'Termín už je zrušený.'];
                $b['status'] = 'cancelled';
                $b['cancelledBy'] = 'patient';
                $b['log'][] = ['t' => time() * 1000, 'text' => 'Zrušeno pacientem přes web'];
                $bk = $b;
                unset($b);
                rez_store($S, $cur['version'] + 1);
                return ['b' => $bk, 'S' => $S];
            }
            return ['err' => 'Termín nebyl nalezen.'];
        });
        if (isset($res['err'])) {
            rez_json(['ok' => false, 'error' => $res['err']], 409);
        }
        try {
            rez_mail('cancel', $id, (string)rez_cfg('clinic_email', ''), 'Pacient zrušil termín: ' . $res['b']['name'] . ' – ' . rez_dlong($res['b']['date']) . ' ' . rez_hm((int)$res['b']['start']),
                '<p style="font-family:Arial">Pacient <b>' . rez_e((string)$res['b']['name']) . '</b> zrušil termín ' . rez_e(rez_dlong($res['b']['date']) . ' ' . rez_hm((int)$res['b']['start'])) . ' přes web.</p>');
        } catch (Throwable $e) {
        }
        rez_json(['ok' => true]);
    }
    rez_json(['ok' => false, 'error' => 'Neznámá akce.'], 400);
} catch (Throwable $e) {
    rez_json(['ok' => false, 'error' => 'Na serveru nastala chyba. Zkuste to prosím znovu, nebo zavolejte 727 927 890.'], 500);
}
