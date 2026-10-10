<?php
// Přehled e-mailů a SMS, které rezervace odeslaly (ve zkušebním režimu i náhled SMS) + vymazání zkušebních dat.
declare(strict_types=1);
require __DIR__ . '/rezervace-lib-admin.php';
rez_admin_headers();
header('Content-Type: text/html; charset=utf-8');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rez_admin_csrf_ok()) {
        http_response_code(403);
        exit('Neplatný požadavek – obnovte stránku.');
    }
    if (($_POST['do'] ?? '') === 'wipe' && rez_cfg('test_mode', true)) {
        $db = rez_db();
        $db->exec('DELETE FROM state');
        $db->exec('DELETE FROM outbox');
        $db->exec('DELETE FROM tokens');
        $msg = 'Zkušební data jsou vymazaná. Po otevření ordinace se začne nanovo.';
    }
}
$rows = rez_db()->query('SELECT * FROM outbox ORDER BY id DESC LIMIT 200')->fetchAll();
$kinds = ['confirm' => 'Potvrzení pacientovi', 'move' => 'Změna termínu', 'cancel' => 'Zrušení', 'clinic' => 'Upozornění ordinaci'];
?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Odeslané zprávy – rezervace</title>
<link rel="stylesheet" href="../rezervace/app.css?v=<?= (string)@filemtime(dirname(__DIR__) . '/rezervace/app.css') ?>">
</head>
<body>
<div class="wrap">
  <header class="top">
    <div class="brand">ADent<span>.</span><small>zprávy</small></div>
    <nav class="row admnav"><a class="btn ghost sm" href="rezervace.php">← Rezervace</a><a class="btn ghost sm" href="./">Administrace webu</a></nav>
  </header>
  <?php if ($msg): ?><div class="testbar"><?= $h($msg) ?></div><?php endif; ?>
  <section class="panel">
    <div class="panel-h"><h2>Odeslané zprávy</h2><span class="hint">posledních 200</span></div>
    <?php if (!$rows): ?><p class="hint">Zatím nic neodešlo. Zkuste se objednat přes pacientskou stránku.</p><?php endif; ?>
    <div class="stack" style="gap:10px">
    <?php foreach ($rows as $r): ?>
      <details class="msgrow">
        <summary><b><?= $h(date('j. n. H:i', (int)$r['t'])) ?></b> · <?= $r['channel'] === 'sms' ? 'SMS' : 'E-mail' ?> · <?= $h($kinds[$r['kind']] ?? $r['kind']) ?>
          · <?= $h($r['orig_to']) ?><?= $r['to_addr'] !== $r['orig_to'] ? ' → ' . $h($r['to_addr']) : '' ?>
          · <?= $r['sent'] ? '<span class="pill good">odesláno</span>' : '<span class="pill">' . $h($r['error'] ?: 'neodesláno') . '</span>' ?></summary>
        <?php if ($r['channel'] === 'sms'): ?><div class="sms" style="margin-top:10px"><?= $h($r['body']) ?></div>
        <?php else: ?><p class="hint" style="margin:10px 0 6px">Předmět: <?= $h($r['subject']) ?></p><div class="mail"><?= $r['body'] ?></div><?php endif; ?>
      </details>
    <?php endforeach; ?>
    </div>
  </section>
  <?php if (rez_cfg('test_mode', true)): ?>
  <section class="panel">
    <div class="panel-h"><h2>Zkušební data</h2></div>
    <p class="hint">Smaže všechny zkušební rezervace, směny, nastavení rezervací a odeslané zprávy. Web ani administrace webu se nezmění.</p>
    <form method="post" style="margin-top:12px"><input type="hidden" name="rez_csrf" value="<?= $h(rez_admin_csrf()) ?>"><input type="hidden" name="do" value="wipe">
      <button class="btn ghost" style="color:var(--bad)">Vymazat zkušební data</button></form>
  </section>
  <?php endif; ?>
</div>
</body>
</html>
