<?php
// Ordinace – kalendář rezervací, směny, pacienti, vyúčtování (uvnitř administrace webu).
declare(strict_types=1);
require __DIR__ . '/rezervace-lib-admin.php';
rez_admin_headers();
header('Content-Type: text/html; charset=utf-8');
$cur = rez_load();
$v = (string)@filemtime(dirname(__DIR__) . '/rezervace/app.js');
?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>Rezervace · ADent. Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,500;0,600;1,400&family=Crimson+Pro:wght@400;500;600&display=swap">
<link rel="stylesheet" href="../rezervace/app.css?v=<?= $v ?>">
</head>
<body class="in-admin" data-rez-mode="adm" data-rez-api="rezervace-api.php" data-rez-csrf="<?= h(rez_admin_csrf()) ?>" data-rez-me="<?= h(rez_staff_for_user($cur['state'], $user)) ?>">
<?= rez_topbar() ?>
<div class="wrap">
  <?php if (rez_cfg('test_mode', true)): ?>
  <div class="testbar"><b>Zkušební verze</b> · data jsou ve zkušební databázi, e-maily chodí jen na <?= h(rez_cfg('test_email')) ?>, SMS se jen zapisují ·
    <a href="rezervace-zpravy.php">Odeslané zprávy</a> ·
    pacientská stránka pro zkoušku: <a href="<?= h(rez_patient_url()) ?>" target="_blank" rel="noopener"><?= h(rez_patient_url()) ?></a></div>
  <?php else: ?>
  <p class="hint" style="margin-bottom:10px"><a href="rezervace-zpravy.php">Odeslané zprávy</a></p>
  <?php endif; ?>
  <main id="app"></main>
</div>
<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
<script type="application/json" id="rez-state"><?= json_encode($cur['state'] ? $cur : null, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="../rezervace/app.js?v=<?= $v ?>"></script>
</body>
</html>
