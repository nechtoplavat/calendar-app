<?php
// Rozhraní ordinace: načtení a uložení stavu rezervací (jen pro přihlášené do administrace).
declare(strict_types=1);
require __DIR__ . '/rezervace-lib-admin.php';

$a = (string)($_GET['a'] ?? 'state');
try {
    if ($a === 'ver') {
        rez_json(['ok' => true, 'version' => rez_load()['version']]);
    }
    if ($a === 'state') {
        $cur = rez_load();
        rez_json(['ok' => true, 'version' => $cur['version'], 'state' => $cur['state']]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !rez_admin_csrf_ok()) {
        rez_json(['ok' => false, 'error' => 'Neplatný požadavek – obnovte prosím stránku.'], 403);
    }
    if ($a === 'save') {
        $in = rez_body();
        $state = $in['state'] ?? null;
        if (!is_array($state) || !rez_valid_state($state)) {
            rez_json(['ok' => false, 'error' => 'Neplatná data.'], 400);
        }
        $added = [];
        $cancelled = [];
        $res = rez_tx(function () use ($state, &$added, &$cancelled) {
            $cur = rez_load();
            $merged = rez_merge_save($state, $cur['state'], $added, $cancelled);
            $v = $cur['version'] + 1;
            rez_store($merged, $v);
            return ['old' => $cur['state'], 'new' => $merged, 'v' => $v];
        });
        try {
            rez_diff_notify($res['old'], $res['new']);
        } catch (Throwable $e) {
        }
        rez_json(['ok' => true, 'version' => $res['v'], 'added' => $added, 'cancelled' => $cancelled]);
    }
    rez_json(['ok' => false, 'error' => 'Neznámá akce.'], 400);
} catch (Throwable $e) {
    rez_json(['ok' => false, 'error' => 'Chyba serveru: ' . $e->getMessage()], 500);
}
