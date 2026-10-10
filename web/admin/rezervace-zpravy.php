<?php
// Přehled e-mailů a SMS, které rezervace odeslaly (ve zkušebním režimu i náhled SMS) + vymazání zkušebních dat.
declare(strict_types=1);
require __DIR__ . '/rezervace-lib-admin.php';
rez_admin_headers();
header('Content-Type: text/html; charset=utf-8');
$h = 'h';
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rez_admin_csrf_ok()) {
        http_response_code(403);
        exit('Neplatný požadavek – obnovte stránku.');
    }
    if (($_POST['do'] ?? '') === 'test') {
        $to = rez_cfg('test_mode', true) ? (string)rez_cfg('test_email', '') : (string)rez_cfg('clinic_email', '');
        rez_mail('test', null, $to, 'Zkušební e-mail z rezervací', '<p style="font-family:Arial">Tento e-mail poslaly rezervace ADent., aby se ověřilo doručování. Pokud ho čtete, e-maily fungují.</p>');
        $last = rez_db()->query("SELECT sent, error, to_addr FROM outbox ORDER BY id DESC LIMIT 1")->fetch();
        $msg = $last && $last['sent'] ? 'Zkušební e-mail odešel na ' . $last['to_addr'] . '. Zkontrolujte schránku (i Spam).' : 'Zkušební e-mail se nepodařilo odeslat: ' . ($last['error'] ?? '');
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
$kinds = ['test' => 'Zkušební e-mail', 'confirm' => 'Potvrzení pacientovi', 'move' => 'Změna termínu', 'cancel' => 'Zrušení', 'clinic' => 'Upozornění ordinaci'];
?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Odeslané zprávy – rezervace</title>
<link rel="stylesheet" href="../rezervace/app.css?v=<?= (string)@filemtime(dirname(__DIR__) . '/rezervace/app.css') ?>">
</head>
<body class="in-admin">
<?= rez_topbar() ?>
<div class="wrap">
  <p style="margin-bottom:12px"><a class="btn ghost sm" href="rezervace.php">← Zpět do rezervací</a></p>
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
  <section class="panel">
    <div class="panel-h"><h2>Odesílání e-mailů</h2></div>
    <?php if ((string)rez_cfg('smtp_host', '') !== ''): ?>
      <p>E-maily odcházejí přes schránku <b><?= $h(rez_cfg('smtp_user', rez_cfg('mail_from'))) ?></b> (server <?= $h(rez_cfg('smtp_host')) ?>).</p>
    <?php else: ?>
      <p>E-maily odcházejí přes funkci hostingu z adresy <b><?= $h(rez_cfg('mail_from')) ?></b>. Gmail a další je často odmítnou nebo dají do spamu –
        doporučujeme v souboru <code>rezervace/config.php</code> vyplnit přihlášení ke skutečné schránce (smtp_…).</p>
    <?php endif; ?>
    <form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="<?= $h(rez_admin_csrf()) ?>"><input type="hidden" name="do" value="test">
      <button class="btn ghost">Poslat zkušební e-mail</button></form>
  </section>
  <?php if (rez_cfg('test_mode', true)): ?>
  <section class="panel">
    <div class="panel-h"><h2>Zkušební data</h2></div>
    <p class="hint">Smaže všechny zkušební rezervace, směny, nastavení rezervací a odeslané zprávy. Web ani administrace webu se nezmění.</p>
    <form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="<?= $h(rez_admin_csrf()) ?>"><input type="hidden" name="do" value="wipe">
      <button class="btn ghost" style="color:var(--bad)">Vymazat zkušební data</button></form>
  </section>
  <?php endif; ?>
</div>
</body>
</html>
