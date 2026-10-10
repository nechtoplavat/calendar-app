<?php
// Pacientská stránka rezervací (ve zkušebním režimu jen s přístupovým kódem).
declare(strict_types=1);
require __DIR__ . '/access.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'self'");

if (!rez_access_ok()) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>Rezervace</title><p style="font-family:Arial;padding:40px">Zkušební verze rezervací. Otevřete prosím odkaz s přístupovým kódem.</p>';
    exit;
}
if (isset($_GET['k'])) {   // kód si prohlížeč pamatuje, odkaz bez něj se dá sdílet bezpečněji
    header('Location: ./', true, 303);
    exit;
}
$cur = rez_load();
$v = (string)@filemtime(__DIR__ . '/app.js');
?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Objednání – ADent. Dentální hygiena</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,500;0,600;1,400&family=Crimson+Pro:wght@400;500;600&display=swap">
<link rel="stylesheet" href="app.css?v=<?= $v ?>">
</head>
<body data-rez-mode="pac" data-rez-api="api.php">
<div class="wrap">
  <header class="top"><div class="brand">ADent<span>.</span><small>rezervace</small></div></header>
  <?php if (rez_cfg('test_mode', true)): ?><div class="testbar"><b>Zkušební verze</b> · objednávky se ukládají do zkušební databáze, e-maily chodí jen na zkušební adresu.</div><?php endif; ?>
  <?php if (!$cur['state']): ?><p class="panel">Rezervace se teprve připravují – otevřete nejdřív ordinaci v administraci.</p><?php endif; ?>
  <main id="app"></main>
</div>
<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
<script type="application/json" id="rez-state"><?= json_encode($cur['state'] ? ['version' => $cur['version'], 'state' => rez_public($cur['state'])] : null, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="app.js?v=<?= $v ?>"></script>
</body>
</html>
