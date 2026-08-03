<?php
// /admin/mfa.php
require_once __DIR__ . '/init.php'; // starts session, CSRF, admin+MFA guard
require_once __DIR__ . '/../src/Security/MFA.php';
use App\Security\MFA;
$database = new \Database(); $pdo = $database->getConnection();
$user = $_SESSION['user'];

$siteName = 'Klassrumsverktyg';
$email = $user['email'];

$stat = $pdo->prepare("SELECT mfa_enabled, mfa_secret, mfa_enrolled_at, mfa_last_verified_at FROM users WHERE id=?");
$stat->execute([$user['id']]);
$u = $stat->fetch(PDO::FETCH_ASSOC);

$step = 'idle';
$qrData = $recoveryCodes = [];
$msg = $err = null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'start_enable') {
    $enr = MFA::startEnrollment($pdo, (int)$user['id']);
    $base32 = MFA::base32_encode($enr['secret_bin']);
    $uri = MFA::provisioningUri($siteName, $email, $base32);
    // QR genereras klient-side via qrcodejs (CDN), undvik Google Charts
    $qrData = ['base32' => $base32, 'uri' => $uri];
    $step = 'confirm';
  }
  elseif ($action === 'confirm_enable') {
    $code = trim($_POST['code'] ?? '');
    $res = MFA::confirmEnable($pdo, (int)$user['id'], $code, $email, $siteName);
    if ($res['ok']) {
      $msg = 'MFA aktiverad!';
      $recoveryCodes = $res['codes']; // visa EN gång
      $u['mfa_enabled'] = 1;
      $step = 'done';
    } else {
      $err = $res['msg'] ?? 'Kunde inte aktivera';
      $step = 'error';
    }
  }
  elseif ($action === 'disable') {
    // valfritt: kräv lösenord/TOTP igen
    MFA::disable($pdo, (int)$user['id']);
    $msg = 'MFA avaktiverad.';
    $u['mfa_enabled'] = 0;
  }
}
?>
<!DOCTYPE html><html lang="sv"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>MFA – Min säkerhet</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/vendor/fontawesome/all.min.css">
<script src="/assets/vendor/qrcodejs/qrcode.min.js"></script>
</head><body class="bg-gray-100 min-h-screen">
<?php include_once __DIR__ . '/nav.php'; ?>
<div class="container mx-auto px-4 py-8">
  <h1 class="text-3xl font-bold mb-6">Min säkerhet (MFA)</h1>

  <?php if ($msg): ?><div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded p-3"><?=$msg?></div><?php endif; ?>
  <?php if ($err): ?><div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded p-3"><?=$err?></div><?php endif; ?>

  <div class="bg-white border border-gray-200 rounded-lg shadow p-6 mb-6">
    <div class="flex items-center justify-between">
      <h2 class="text-xl font-semibold"><i class="fa-solid fa-shield-halved text-emerald-600 mr-2"></i>Status</h2>
      <span class="px-3 py-1 text-xs rounded-full <?=$u['mfa_enabled']?'bg-green-100 text-green-800':'bg-gray-100 text-gray-700'?>"><?=$u['mfa_enabled']?'Aktiverad':'Av'?></span>
    </div>
    <div class="mt-4 flex gap-3">
      <?php if (!$u['mfa_enabled']): ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="start_enable">
          <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Aktivera MFA</button>
        </form>
      <?php else: ?>
        <form method="post" onsubmit="return confirm('Säkert att stänga av MFA?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="disable">
          <button class="bg-gray-100 hover:bg-gray-200 text-gray-800 px-4 py-2 rounded-lg">Stäng av</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($step === 'confirm'): ?>
    <div class="bg-white border border-gray-200 rounded-lg shadow p-6 mb-6">
      <h2 class="text-lg font-semibold mb-2">Skanna QR i din autentiseringsapp</h2>
      <div id="qr" class="w-48 h-48 border"></div>
      <script>
        (function () {
          var el = document.getElementById('qr');
          if (el && window.QRCode) {
            new QRCode(el, {
              text: <?=json_encode($qrData['uri'])?>,
              width: 200,
              height: 200,
              correctLevel: QRCode.CorrectLevel.M
            });
          }
        })();
      </script>
      <p class="text-sm text-gray-600 mt-2"><strong>Manuell nyckel:</strong> <code><?=$qrData['base32']?></code></p>
      <form method="post" class="mt-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="confirm_enable">
        <label class="block text-sm text-gray-700">Ange 6-siffrig kod</label>
        <input name="code" class="w-full border rounded-lg px-3 py-2 mt-1 mb-3" required>
        <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Bekräfta</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($step === 'done' && !empty($recoveryCodes)): ?>
    <div class="bg-white border border-gray-200 rounded-lg shadow p-6">
      <h2 class="text-lg font-semibold mb-2">Säkerhetskopieringskoder</h2>
      <p class="text-sm text-gray-700 mb-2">Spara dessa koder på ett säkert ställe. Varje kod kan användas en gång.</p>
      <div class="grid grid-cols-2 md:grid-cols-3 gap-2 font-mono">
        <?php foreach ($recoveryCodes as $c): ?>
          <div class="px-3 py-2 bg-gray-50 border rounded"><?=$c?></div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
</body></html>