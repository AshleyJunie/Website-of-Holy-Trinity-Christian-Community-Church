<?php
/* ---------- ADD BELOW THIS LINE: Admin profile session loader (uses your real columns) ---------- */
if (!function_exists('htccc_admin_load_profile_sessions')) {
    function htccc_admin_load_profile_sessions_mysqli(mysqli $db, int $adminId): void {
        try {
            $sql = "SELECT admin_id, admin_username, admin_emailaddress,
                           admin_firstname, admin_middlename, admin_lastname, admin_contactnum
                    FROM admin_table WHERE admin_id = ? LIMIT 1";
            if ($st = mysqli_prepare($db, $sql)) {
                mysqli_stmt_bind_param($st, "i", $adminId);
                mysqli_stmt_execute($st);
                $res = mysqli_stmt_get_result($st);
                if ($row = mysqli_fetch_assoc($res)) {
                    $_SESSION['admin_username']     = (string)$row['admin_username'];
                    $_SESSION['admin_emailaddress'] = (string)($row['admin_emailaddress'] ?? '');
                    $_SESSION['admin_firstname']    = (string)($row['admin_firstname'] ?? '');
                    $_SESSION['admin_middlename']   = (string)($row['admin_middlename'] ?? '');
                    $_SESSION['admin_lastname']     = (string)($row['admin_lastname'] ?? '');
                    $_SESSION['admin_contactnum']   = (string)($row['admin_contactnum'] ?? '');

                    // Back-compat keys already used elsewhere in your code:
                    $_SESSION['admin_user']  = $_SESSION['admin_username'];
                    $_SESSION['admin_email'] = $_SESSION['admin_emailaddress'];

                    $mid = trim($_SESSION['admin_middlename'] ?? '');
                    $mid = $mid !== '' ? (' ' . strtoupper($mid[0]) . '.') : '';
                    $full = trim(($_SESSION['admin_firstname'] ?? '') . $mid . ' ' . ($_SESSION['admin_lastname'] ?? ''));
                    $_SESSION['admin_fullname']     = trim($full) !== '' ? $full : ($_SESSION['admin_username'] ?? 'Admin');
                    $_SESSION['admin_display_name'] = $_SESSION['admin_firstname'] ?: ($_SESSION['admin_fullname'] ?? 'Admin');
                }
                mysqli_stmt_close($st);
            }
        } catch (Throwable $e) { /* silent */ }
    }
    function htccc_admin_load_profile_sessions_pdo(?PDO $pdo, int $adminId): void {
        if (!$pdo) return;
        try {
            $st = $pdo->prepare("SELECT admin_id, admin_username, admin_emailaddress,
                                        admin_firstname, admin_middlename, admin_lastname, admin_contactnum
                                 FROM admin_table WHERE admin_id = :id LIMIT 1");
            $st->execute([':id'=>$adminId]);
            if ($row = $st->fetch()) {
                $_SESSION['admin_username']     = (string)$row['admin_username'];
                $_SESSION['admin_emailaddress'] = (string)($row['admin_emailaddress'] ?? '');
                $_SESSION['admin_firstname']    = (string)($row['admin_firstname'] ?? '');
                $_SESSION['admin_middlename']   = (string)($row['admin_middlename'] ?? '');
                $_SESSION['admin_lastname']     = (string)($row['admin_lastname'] ?? '');
                $_SESSION['admin_contactnum']   = (string)($row['admin_contactnum'] ?? '');

                $_SESSION['admin_user']  = $_SESSION['admin_username'];
                $_SESSION['admin_email'] = $_SESSION['admin_emailaddress'];

                $mid = trim($_SESSION['admin_middlename'] ?? '');
                $mid = $mid !== '' ? (' ' . strtoupper($mid[0]) . '.') : '';
                $full = trim(($_SESSION['admin_firstname'] ?? '') . $mid . ' ' . ($_SESSION['admin_lastname'] ?? ''));
                $_SESSION['admin_fullname']     = trim($full) !== '' ? $full : ($_SESSION['admin_username'] ?? 'Admin');
                $_SESSION['admin_display_name'] = $_SESSION['admin_firstname'] ?: ($_SESSION['admin_fullname'] ?? 'Admin');
            }
        } catch (Throwable $e) { /* silent */ }
    }
}
?>
<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin' || empty($_SESSION['admin_id'])) {
    header('Location: all_log-in.php'); exit;
}

/* DB (uses your remembered connection.php: PDO -> htccc-data-base on localhost root/no pass) */
$pdo = null;
@include_once __DIR__ . '/connection.php';
if (!$pdo instanceof PDO) {
  try {
    $pdo = new PDO('mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4','root','',[
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
  } catch(Throwable $e){ die('DB connection error'); }
}

$adminId = (int)$_SESSION['admin_id'];

/* Refresh row (source of truth) */
$admin = [];
try {
  $st = $pdo->prepare("SELECT admin_id, admin_username, admin_emailaddress,
                              admin_firstname, admin_middlename, admin_lastname, admin_contactnum, admin_password, password_request
                       FROM admin_table WHERE admin_id = :id LIMIT 1");
  $st->execute([':id'=>$adminId]);
  $admin = $st->fetch() ?: [];
} catch(Throwable $e){}

/* ---------- ADD BELOW THIS LINE: helper + final fallback normalizer (ADD-ONLY) ---------- */
if (!function_exists('htccc_pick')) {
  function htccc_pick(array $row, array $keys, $def='') {
    foreach ($keys as $k) { if (array_key_exists($k,$row) && $row[$k]!=='' && $row[$k]!==null) return (string)$row[$k]; }
    return $def;
  }
}
try {
  $need = (
    empty($admin['admin_firstname'])  ||
    empty($admin['admin_lastname'])   ||
    (!isset($admin['admin_middlename'])) ||
    empty($admin['admin_contactnum'])
  );
  if ($need) {
    $stN = $pdo->prepare("
      SELECT
        COALESCE(admin_firstname, firstname, first_name)                          AS _fn,
        COALESCE(admin_middlename, middlename, middle_name, mi)                   AS _mn,
        COALESCE(admin_lastname, lastname, last_name)                             AS _ln,
        COALESCE(admin_contactnum, admin_contactnumber, contact_no, contact_number, phone, mobile, cellphone) AS _ct
      FROM admin_table
      WHERE admin_id = :id
      LIMIT 1
    ");
    $stN->execute([':id'=>$adminId]);
    if ($rN = $stN->fetch()) {
      if (empty($admin['admin_firstname']) && !empty($rN['_fn']))  $admin['admin_firstname']  = $rN['_fn'];
      if (!isset($admin['admin_middlename']) || $admin['admin_middlename']==='') $admin['admin_middlename'] = (string)$rN['_mn'];
      if (empty($admin['admin_lastname'])  && !empty($rN['_ln']))  $admin['admin_lastname']   = $rN['_ln'];
      if (empty($admin['admin_contactnum']) && !empty($rN['_ct'])) $admin['admin_contactnum'] = $rN['_ct'];

      // sync sessions for UI
      if (!empty($admin['admin_firstname']))  $_SESSION['admin_firstname']  = (string)$admin['admin_firstname'];
      if (isset($admin['admin_middlename']))  $_SESSION['admin_middlename'] = (string)$admin['admin_middlename'];
      if (!empty($admin['admin_lastname']))   $_SESSION['admin_lastname']   = (string)$admin['admin_lastname'];
      if (!empty($admin['admin_contactnum'])) $_SESSION['admin_contactnum'] = (string)$admin['admin_contactnum'];
    }
  }
} catch(Throwable $e){ /* ignore */ }
/* ---------- ADD ABOVE THIS LINE ---------- */

$fn  = trim($admin['admin_firstname']  ?? ($_SESSION['admin_firstname']  ?? ''));
$mn  = trim($admin['admin_middlename'] ?? ($_SESSION['admin_middlename'] ?? ''));
$ln  = trim($admin['admin_lastname']   ?? ($_SESSION['admin_lastname']   ?? ''));
$mid = $mn ? (' ' . strtoupper($mn[0]) . '.') : '';
$fullname = trim($fn . $mid . ' ' . $ln);
if ($fullname === '') $fullname = $_SESSION['admin_fullname'] ?? ($_SESSION['admin_username'] ?? 'Admin');
$greet = $fn ?: ($fullname ?: 'Admin');

$email = trim($admin['admin_emailaddress'] ?? ($_SESSION['admin_emailaddress'] ?? ($_SESSION['admin_email'] ?? '')));
$user  = trim($admin['admin_username'] ?? ($_SESSION['admin_username'] ?? $_SESSION['admin_user'] ?? ''));
$contact = trim(htccc_pick($admin, ['admin_contactnum','admin_contactnumber','contact_no','contact_number','phone','mobile','cellphone'], $_SESSION['admin_contactnum'] ?? ''));
$pwdRequest = strtoupper(trim((string)($admin['password_request'] ?? 'No'))) ?: 'No';

/* ---------- ADD BELOW THIS LINE: DATA DISPLAY HOTFIX (READ-ONLY FIELDS) ---------- */
try {
  if ($fn === '' || $ln === '' || !isset($mn) || $contact === '') {
    $stFix = $pdo->prepare("SELECT admin_firstname, admin_middlename, admin_lastname, admin_contactnumber, password_request
                            FROM admin_table WHERE admin_id = :id LIMIT 1");
    $stFix->execute([':id'=>$adminId]);
    if ($rFix = $stFix->fetch()) {
      if ($fn === '' && !empty($rFix['admin_firstname']))      $fn      = (string)$rFix['admin_firstname'];
      if (!isset($mn) || $mn === '')                           $mn      = (string)($rFix['admin_middlename'] ?? '');
      if ($ln === '' && !empty($rFix['admin_lastname']))       $ln      = (string)$rFix['admin_lastname'];
      if ($contact === '' && !empty($rFix['admin_contactnumber'])) $contact = (string)$rFix['admin_contactnumber'];
      if (!empty($rFix['password_request'])) $pwdRequest = strtoupper((string)$rFix['password_request']);
    }
  }
  // Rebuild greeting/fullname
  $mid = $mn ? (' ' . strtoupper($mn[0]) . '.') : '';
  $fullname = trim($fn . $mid . ' ' . $ln);
  if ($fullname === '') $fullname = $_SESSION['admin_fullname'] ?? ($_SESSION['admin_username'] ?? 'Admin');
  $greet = $fn ?: ($fullname ?: 'Admin');
} catch (Throwable $e) { /* no-op */ }
/* ---------- ADD ABOVE THIS LINE: DATA DISPLAY HOTFIX (READ-ONLY FIELDS) ---------- */

$flash_ok = ''; $flash_err = '';

/* CSRF */
if (empty($_SESSION['__csrf'])) $_SESSION['__csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['__csrf'];
$check_csrf = function($t){ return isset($_SESSION['__csrf']) && hash_equals($_SESSION['__csrf'], (string)$t); };

/* Update Email (editable) */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['__action'] ?? '')==='update_email') {
    if (!$check_csrf($_POST['__csrf'] ?? '')) {
        $flash_err = 'Security check failed.'; 
    } else {
        $new = trim((string)($_POST['new_email'] ?? ''));
        if ($new === '' || !filter_var($new, FILTER_VALIDATE_EMAIL)) {
            $flash_err = 'Please enter a valid email.';
        } else {
            try {
                $st = $pdo->prepare("UPDATE admin_table SET admin_emailaddress = :e WHERE admin_id = :id");
                $st->execute([':e'=>$new, ':id'=>$adminId]);
                $email = $new;
                $_SESSION['admin_emailaddress'] = $new;
                $_SESSION['admin_email'] = $new; // keep your compatibility key in sync
                $flash_ok = 'Email updated successfully.';
            } catch(Throwable $e){ $flash_err = 'Email update failed.'; }
        }
    }
}

/* ========== NEW: Password Reset Request (sets password_request = 'Yes') ========== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['__action'] ?? '')==='request_password_reset') {
    if (!$check_csrf($_POST['__csrf'] ?? '')) {
        $flash_err = 'Security check failed.';
    } else {
        try {
            $st = $pdo->prepare("UPDATE admin_table SET password_request = 'Yes' WHERE admin_id = :id");
            $st->execute([':id'=>$adminId]);
            $pwdRequest = 'YES';
            $flash_ok = 'Password reset request recorded. An admin can now assist you.';
        } catch (Throwable $e) {
            $flash_err = 'Failed to record password reset request.';
        }
    }
}

/* Refresh row after updates for display */
try {
  $st = $pdo->prepare("SELECT admin_id, admin_username, admin_emailaddress,
                              admin_firstname, admin_middlename, admin_lastname,
                              COALESCE(admin_contactnum, admin_contactnumber) AS admin_contactnum,
                              password_request
                       FROM admin_table WHERE admin_id = :id LIMIT 1");
  $st->execute([':id'=>$adminId]);
  $admin = $st->fetch() ?: $admin;
} catch(Throwable $e){}
$email = trim($admin['admin_emailaddress'] ?? $email);
$user  = trim($admin['admin_username'] ?? $user);
$fn    = trim($admin['admin_firstname']  ?? $fn);
$mn    = trim($admin['admin_middlename'] ?? $mn);
$ln    = trim($admin['admin_lastname']   ?? $ln);
$contact = trim(htccc_pick($admin, ['admin_contactnum','admin_contactnumber','contact_no','contact_number','phone','mobile','cellphone'],$contact));
$pwdRequest = strtoupper(trim((string)($admin['password_request'] ?? $pwdRequest)));
$mid = $mn ? (' ' . strtoupper($mn[0]) . '.') : '';
$fullname = trim($fn . $mid . ' ' . $ln);
if ($fullname === '') $fullname = $_SESSION['admin_fullname'] ?? $user;
$greet = $fn ?: $fullname;

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<title>Admin – Account Settings</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<style>
  body{margin:0;background:#f8fafc;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial}
  .wrap{max-width:900px;margin:24px auto;padding:0 16px}
  .title{font-weight:800;font-size:22px;color:#0f172a;margin:8px 0 16px}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 10px 30px rgba(2,6,23,.06);margin-bottom:16px;overflow:hidde; margin-left: 200px;}
  .card-h{padding:14px 18px;background:#f8fafc;border-bottom:1px solid #e5e7eb;font-weight:700;color:#0f172a}
  .card-b{padding:18px;}
  .greet{font-size:18px;font-weight:800;margin-bottom:8px}
  .grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
  @media (max-width:720px){.grid{grid-template-columns:1fr}}
  .field{margin-bottom:12px}
  .label{display:block;font-size:13px;font-weight:700;color:#0f172a;margin-bottom:6px}
  .input{width:100%;padding:12px;border:1px solid #d1d5db;border-radius:12px;font-size:14px;outline:none}
  .input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
  .ro{background:#f9fafb;color:#334155}
  .help{font-size:12px;color:#64748b;margin-top:4px}
  .actions{display:flex;gap:10px;justify-content:flex-end;margin-top:8px}
  .btn{background:#2563eb;color:#fff;border:none;border-radius:12px;padding:10px 14px;font-weight:700;cursor:pointer}
  .btn:hover{background:#1e40af}
  .btn.dark{background:#0f172a}
  .btn.dark:hover{background:#0b1224}
  .banner{padding:12px 14px;border-radius:12px;margin-bottom:12px;font-size:14px}
  .ok{background:#ecfdf5;color:#065f46;border:1px solid #10b981}
  .err{background:#fef2f2;color:#991b1b;border:1px solid #ef4444}
  .badge{display:inline-block;border:1px solid #e5e7eb;border-radius:999px;padding:4px 10px;font-size:12px;background:#fff;color:#0f172a}
  .badge.success{background:#ecfdf5;border-color:#10b981;color:#065f46}
</style>
</head>
<body>
  <div class="wrap">
    <div class="title">Account Settings</div>

    <?php if ($flash_ok): ?><div class="banner ok"><?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
    <?php if ($flash_err): ?><div class="banner err"><?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

    <div class="card">
      <div class="card-h">Profile</div>
      <div class="card-b">
        <div class="greet">Hi, <?= htmlspecialchars($greet) ?>!</div>
        <div class="grid">
          <div class="field">
            <label class="label">Username</label>
            <input class="input ro" type="text" value="<?= htmlspecialchars($user) ?>" readonly>
          </div>
          <div class="field">
            <label class="label">Email</label>
            <input class="input ro" type="text" value="<?= htmlspecialchars($email) ?>" readonly>
          </div>
          <div class="field">
            <label class="label">First name</label>
            <input class="input ro" type="text" value="<?= htmlspecialchars($fn) ?>" readonly>
          </div>
          <div class="field">
            <label class="label">Middle name</label>
            <input class="input ro" type="text" value="<?= htmlspecialchars($mn) ?>" readonly>
          </div>
          <div class="field">
            <label class="label">Last name</label>
            <input class="input ro" type="text" value="<?= htmlspecialchars($ln) ?>" readonly>
          </div>
          <div class="field">
            <label class="label">Contact No.</label>
            <input class="input ro" type="text" value="<?= htmlspecialchars($contact) ?>" readonly>
          </div>
        </div>
        <div class="help">All profile fields are read-only. You can only modify your Email below. To change your password, request a reset.</div>
      </div>
    </div>

    <div class="card">
      <div class="card-h">Update Email</div>
      <div class="card-b">
        <form method="post">
          <input type="hidden" name="__action" value="update_email">
          <input type="hidden" name="__csrf" value="<?= htmlspecialchars($csrf) ?>">
          <div class="field">
            <label class="label" for="new_email">New Email</label>
            <input class="input" id="new_email" name="new_email" type="email" value="<?= htmlspecialchars($email) ?>" required>
            <div class="help">This will become your login email. (Your username remains <?= htmlspecialchars($user) ?>.)</div>
          </div>
          <div class="actions">
            <button class="btn" type="submit">Save Email</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ===== REPLACED: Change Password -> Password Reset Request ===== -->
    <div class="card">
      <div class="card-h">Password Reset Request</div>
      <div class="card-b">
        <p class="help" style="margin-top:0">
          Click the button below to flag your account for a password reset. An admin can then process your request.
        </p>
        <p>
          Current request status:
          <?php if ($pwdRequest === 'YES'): ?>
            <span class="badge success">Requested</span>
          <?php else: ?>
            <span class="badge">Not Requested</span>
          <?php endif; ?>
        </p>
        <!-- REMOVED NATIVE confirm(): keep SweetAlert only -->
        <form method="post">
          <input type="hidden" name="__action" value="request_password_reset">
          <input type="hidden" name="__csrf" value="<?= htmlspecialchars($csrf) ?>">
          <div class="actions">
            <button class="btn dark" type="submit"><i>↻</i> Flag for Password Reset</button>
          </div>
        </form>
      </div>
    </div>
  </div>

<!-- ---------- ADD BELOW THIS LINE: De-dup guard (safe even if included twice) ---------- -->
<style>
  .account-wrap.htccc-dupe { display: none !important; }
</style>
<script>
(function(){
  // Remove duplicate Account Settings wrappers if this file is included/rendered twice
  const wraps = Array.from(document.querySelectorAll('.account-wrap'));
  if (wraps.length > 1) {
    wraps.slice(1).forEach(w => w.remove());
  }
  // Also avoid duplicate sidebars if any layout wraps exist on the site template
  const sidebars = Array.from(document.querySelectorAll('.sidebar'));
  if (sidebars.length > 1) {
    sidebars.slice(1).forEach(s => s.remove());
  }
})();
</script>
<!-- ---------- ADD ABOVE THIS LINE ---------- -->

</body>
</html>



<!-- BEGIN FILE: admin-ministry-women.php (Sidebar Only + Account Settings + ADD-ONLY PATCH) -->
<?php
/* ============================================================================
   ADD-ONLY: Inline Dompdf Certificate Generator
   ============================================================================ */

/* ===== ADD BELOW THIS LINE: PHP 7 polyfill for str_ends_with used later ===== */
if (!function_exists('str_ends_with')) {
  function str_ends_with($haystack, $needle) {
    $haystack = (string)$haystack;
    $needle   = (string)$needle;
    if ($needle === '') return true;
    $hlen = strlen($haystack);
    $nlen = strlen($needle);
    if ($nlen > $hlen) return false;
    return substr($haystack, -$nlen) === $needle;
  }
}
if (!function_exists('str_ends_with')) {
  function str_ends_with($haystack, $needle) {
    $haystack = (string)$haystack;
    $needle   = (string)$needle;
    if ($needle === '') return true;
    $hlen = strlen($haystack);
    $nlen = strlen($needle);
    if ($nlen > $hlen) return false;
    return substr($haystack, -$nlen) === $needle;
  }
}
?>
<?php
if (isset($_GET['htccc_certificate'])) {
  /* ---------- Config (edit if needed) ---------- */
  $CHURCH_SHORT    = 'HTCCC';
  $CHURCH_NAME     = 'Holy Trinity Christian Community Church (HTCCC)';
  $CHURCH_LINE1    = 'Blk 1 Lot 2, Sample Street, Sample Barangay, Sample City';
  $CHURCH_LINE2    = 'Sunday Service 10:00 AM • www.htccc.example.com • (000) 000-0000';
  $OFFICIANT_NAME  = 'Pastor Irving Demesa';
  $CITY_PLACE_TEXT = 'at HTCCC';

  /* ---------- DB bootstrap ---------- */
  $pdo = null;
  if (!isset($pdo)) { @include_once __DIR__ . '/connection.php'; }
  if (!isset($pdo) || !($pdo instanceof PDO)) {
    try {
      $pdo = new PDO(
        'mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4',
        'root','',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
      );
    } catch (Throwable $e) {
      http_response_code(500); echo 'Database connection failed.'; exit;
    }
  }

  /* ---------- Helpers ---------- */
  $type = isset($_GET['type']) ? strtolower(trim((string)$_GET['type'])) : '';
  $id   = isset($_GET['id'])   ? (string)$_GET['id'] : '';
  $preview = isset($_GET['preview']);

  $tableMap = [
    'baptism'    => 'service_baptism',
    'dedication' => 'service_dedication',
    'house'      => 'service_house',
    'wedding'    => 'service_wedding',
  ];
  $table = $tableMap[$type] ?? null;
  if (!$table || $id==='') { http_response_code(400); echo 'Missing or invalid parameters.'; exit; }

  // ADD: preferred PK per table (your real primary keys)
  $preferredPkMap = [
    'service_baptism'    => 'baptism_id',
    'service_dedication' => 'dedicationId',
    'service_house'      => 'appointmentId',
    'service_wedding'    => 'wedding_id',
  ];
  $preferredPk = $preferredPkMap[$table] ?? null;

  $pick = function(array $row, array $keys, $def=null){
    foreach ($keys as $k) if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) return $row[$k];
    return $def;
  };
  $ordinal = function(int $n){
    $s='th'; $v=$n%100; if($v<11||$v>13){$m=$n%10; if($m===1)$s='st'; elseif($m===2)$s='nd'; elseif($m===3)$s='rd';}
    return $n.$s;
  };
  $parseDate = function(?string $s){
    if(!$s) return null; $s=trim($s);
    $try=['Y-m-d H:i A','Y-m-d h:i A','Y-m-d','m/d/Y h:i A','m/d/Y','d/m/Y','F d, Y','M d, Y'];
    foreach($try as $fmt){ $dt=DateTimeImmutable::createFromFormat($fmt,$s); if($dt){return $dt;} }
    $ts=strtotime($s); return $ts? (new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone(date_default_timezone_get())) : null;
  };
  $datePhrase = function($dt) use ($ordinal){
    if(!$dt) return ''; return 'on the '.$ordinal((int)$dt->format('j')).' day of '.$dt->format('F').' in the year our '.$dt->format('Y');
  };
  $recipientFrom = function($type,$row) use($pick){
    switch($type){
      case 'baptism':    return $pick($row,['baptized_name','full_name','name'],'—');
      case 'dedication': return $pick($row,['service_dedication','dedication_name','child_name','full_name','name'],'—');
      case 'house':      return $pick($row,['owner_full_name','owner_name','full_name','name'],'—');
      case 'wedding':    $g=$pick($row,['groom_name','groom'],'Groom'); $b=$pick($row,['bride_name','bride'],'Bride'); return trim($g.' & '.$b);
      default: return '—';
    }
  };
  $serviceDate = function($row) use($pick,$parseDate){
    $date=$pick($row,['service_date','schedule_date','appointment_date','date','event_date']);
    $time=$pick($row,['service_time','schedule_time','appointment_time','time','event_time']);
    $dt=trim(($date??' ').' '.($time??'')); return $parseDate($dt ?: ($date ?? ''));
  };
  $base64Logo = function(){
    $p = __DIR__.'/image/httc_main-logo.jpg';
    if(!is_file($p)) $p = $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/image/httc_main-logo.jpg';
    if(!is_file($p)) return null;
    $bin=@file_get_contents($p); if(!$bin) return null;
    $mime='image/jpeg'; $l=strtolower($p);
    if (substr($l,-4)==='.png') $mime='image/png';
    if (substr($l,-5)==='.webp') $mime='image/webp';
    return 'data:'.$mime.';base64,'.base64_encode($bin);
  };

  /* ---------- Fetch row ---------- */
  $pkCandidates=['id','ID','service_id','appointment_id','record_id'];

  // ADD: prepend real PKs so they are tried first
  $pkCandidates = array_values(array_unique(array_merge(
    array_filter([$preferredPk]),
    ['baptism_id','dedicationId','appointmentId','wedding_id'],
    $pkCandidates
  )));

  // Optional fast-path: try preferred PK directly
  $row = null;
  if ($preferredPk) {
    try {
      $st = $pdo->prepare("SELECT * FROM `$table` WHERE `$preferredPk` = :id LIMIT 1");
      $st->execute([':id'=>$id]);
      $row = $st->fetch() ?: null;
    } catch (Throwable $e) { /* ignore */ }
  }
  if(!$row){
    foreach($pkCandidates as $pk){
      try{
        $st=$pdo->prepare("SELECT * FROM `$table` WHERE `$pk`=:id LIMIT 1");
        $st->execute([':id'=>$id]); $r=$st->fetch();
        if($r){ $row=$r; break; }
      }catch(Throwable $e){}
    }
  }
  if(!$row){ http_response_code(404); echo 'Record not found.'; exit; }

  $recipient = $recipientFrom($type,$row);
  $dt        = $serviceDate($row);
  $date_line = $datePhrase($dt);
  $logo_src  = $base64Logo();

  $titleMap = [
    'baptism'    => 'CERTIFICATE OF BAPTISM',
    'dedication' => 'CERTIFICATE OF CHILD DEDICATION',
    'house'      => 'CERTIFICATE OF HOUSE BLESSING',
    'wedding'    => 'CERTIFICATE OF MARRIAGE',
  ];
  $bodyMap = [
    'baptism'    => 'was baptized in the name of the Father, the Son, and the Holy Spirit',
    'dedication' => 'was dedicated to the Lord in the presence of family and church',
    'house'      => 'received a prayer of blessing and dedication unto the Lord',
    'wedding'    => 'entered into holy matrimony before God and witnesses',
  ];
  $title = $titleMap[$type] ?? 'CERTIFICATE';
  $body_line = $bodyMap[$type] ?? 'is recognized with this certificate';

  /* ---------- HTML ---------- */
  ob_start(); ?>
  <!doctype html>
  <html><head><meta charset="utf-8"><title><?= htmlspecialchars($title) ?></title>
  <style>
    @page { margin: 40px; }
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; color: #111; }
    .frame { position: relative; padding: 32px; height: 100%;
      border: 12px solid #cda434; border-radius: 18px;
      background: linear-gradient(#f7e7a7,#fef7d0) padding-box; }
    .frame:before, .frame:after { content:""; position:absolute; inset:10px;
      border:2px solid #cda434; border-radius:12px; pointer-events:none; }
    .top-cross { text-align:center; margin-top:4px; margin-bottom:10px; }
    .top-cross svg { width:26px; height:26px; }
    .title { text-align:center; font-weight:800; letter-spacing:2px; font-size:30px; margin:6px 0 12px; }
    .subtitle { text-align:center; font-size:14px; font-weight:700; margin-top:10px; }
    .recipient { text-align:center; font-size:50px; margin:10px 0 6px; font-style:italic; font-weight:600; line-height:1.1; }
    .rule { width:82%; height:1px; margin:6px auto 10px; background:#1f2937; }
    .line { text-align:center; font-size:14px; }
    .line strong { font-weight:800; }
    .church-line { text-align:center; margin-top:8px; font-size:14px; }
    .logo { margin:6px auto 2px; text-align:center; }
    .logo img { height:54px; }
    .para { margin:14px auto 0; width:85%; text-align:center; font-size:13px; line-height:1.5; color:#374151; }
    .signatures { display:table; width:100%; margin-top:40px; }
    .sig-col { display:table-cell; width:50%; padding:0 24px; vertical-align:bottom; }
    .sig-line { border-bottom:1.8px solid #1f2937; margin:0 auto 6px; height:48px; }
    .sig-name { text-align:center; font-size:13px; font-weight:700; }
    .footer { margin-top:22px; text-align:center; font-size:10.5px; color:#374151; }
    .seal { position:absolute; right:46px; bottom:110px; width:96px; height:96px; border-radius:50%; border:3px solid #cda434; opacity:.2; }
  </style></head>
  <body>
    <div class="frame">
      <div class="top-cross">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#cda434" d="M10 2h4v6h6v4h-6v10h-4V12H4V8h6z"/></svg>
      </div>
      <div class="title"><?= htmlspecialchars($title) ?></div>
      <div class="subtitle">THIS CERTIFIES THAT</div>
      <div class="recipient"><?= htmlspecialchars($recipient) ?></div>
      <div class="rule"></div>
      <div class="line"><?= htmlspecialchars($body_line) ?></div>
      <?php if ($dt): ?><div class="line"><?= htmlspecialchars($date_line) ?></div><?php endif; ?>
      <div class="church-line"><?= htmlspecialchars($CITY_PLACE_TEXT) ?></div>
      <div class="logo"><?php if ($logo_src): ?><img src="<?= $logo_src ?>" alt="HTCCC Logo"><?php endif; ?></div>
      <div class="line"><strong><?= htmlspecialchars($CHURCH_NAME) ?></strong></div>
      <div class="para">This certificate is presented as a testimony to the grace of God and the faith of the recipient, in the fellowship of <?= htmlspecialchars($CHURCH_SHORT) ?>.</div>
      <div class="signatures">
        <div class="sig-col"><div class="sig-line"></div><div class="sig-name">Signature</div></div>
        <div class="sig-col"><div class="sig-line"></div><div class="sig-name"><?= htmlspecialchars($OFFICIANT_NAME) ?><br>Officiating Minister</div></div>
      </div>
      <div class="footer"><div><?= htmlspecialchars($CHURCH_LINE1) ?></div><div><?= htmlspecialchars($CHURCH_LINE2) ?></div></div>
      <div class="seal"></div>
    </div>
  </body></html>
  <?php
  $html = ob_get_clean();

  if ($preview) { header('Content-Type: text/html; charset=utf-8'); echo $html; exit; }

  require_once __DIR__ . '/vendor/autoload.php';
  $options = new Dompdf\Options(); $options->set('isRemoteEnabled', true); $options->set('defaultFont', 'DejaVu Sans');
  $dompdf = new Dompdf\Dompdf($options);
  $dompdf->loadHtml($html, 'UTF-8'); $dompdf->setPaper('A4','landscape'); $dompdf->render();
  $filename = sprintf('%s_%s_%s.pdf',
    preg_replace('/\s+/', '_', ucfirst($type)),
    preg_replace('/\s+/', '_', $recipient),
    date('Ymd_His')
  );
  $dompdf->stream($filename, ['Attachment'=>false]);
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1"/>
<title>Admin – Generate Certificate</title>

<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link rel="stylesheet" href="/HTCCC-SYSTEM/css/admin-ministry-women.css<?php
  // keep cache-busting only if the CSS file exists
  $cssPath = $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/admin-ministry-women.css';
  echo file_exists($path = $cssPath) ? ('?v=' . filemtime($path)) : '';
?>">

<style>
/* Minimal internal CSS to present the sidebar cleanly on an otherwise empty page */
html, body { height: 100%; margin: 0; }
body { display: flex; background: #f8fafc; }
.sidebar { flex: 0 0 280px; }
.main-spacer { flex: 1; }

/* ========================================= */
/* ====== ADD BELOW THIS LINE (STYLES) ===== */
.main-container { padding: 20px 24px; max-width: 1200px; margin: 0 auto; }
.h1-title { font-size: 20px; font-weight: 700; margin: 8px 0 16px 0; color: #0f172a; }
.card { background: #fff; border-radius: 14px; box-shadow: 0 6px 20px rgba(2,6,23,0.06); border: 1px solid #e5e7eb; overflow: hidden; }
.card-header { padding: 14px 16px; font-weight: 600; background: #f1f5f9; border-bottom: 1px solid #e5e7eb; color: #0f172a; }
.table-wrap { overflow: auto; }
.table { width: 100%; border-collapse: collapse; min-width: 980px; }
.table th, .table td { padding: 12px 14px; border-bottom: 1px solid #eef2f7; text-align: left; font-size: 14px; color: #0f172a; }
.table thead th { background: #f8fafc; position: sticky; top: 0; z-index: 1; }
.badge { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; padding: 4px 8px; border-radius: 999px; border: 1px solid #e5e7eb; background: #fff; color: #0f172a; }
.badge i { font-size: 12px; }
.btn { appearance: none; border: none; cursor: pointer; border-radius: 10px; padding: 8px 12px; font-size: 14px; font-weight: 600; background: #2563eb; color: #fff; transition: transform .03s ease, box-shadow .2s ease, background .2s ease; display: inline-flex; align-items: center; gap: 8px; }
.btn:hover { background: #1e40af; }
.btn:active { transform: translateY(1px); }
.util-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 16px; border-bottom: 1px solid #e5e7eb; background: #fafafa; }
.util-left { display: flex; align-items: center; gap: 8px; }
.search-input { width: 320px; max-width: 60vw; padding: 10px 12px; border-radius: 10px; border: 1px solid #d1d5db; outline: none; }
.empty-state { padding: 28px 16px; text-align: center; color: #475569; font-size: 14px; }
.meta { font-size: 12px; color: #64748b; }

/* Table with Service column sizing */
.table--with-service { min-width: 980px; }
.table--with-service th:nth-child(1), .table--with-service td:nth-child(1) { width: 36%; }
.table--with-service th:nth-child(2), .table--with-service td:nth-child(2) { width: 18%; }
.table--with-service th:nth-child(3), .table--with-service td:nth-child(3) { width: 26%; white-space: nowrap; }
.table--with-service th:nth-child(4), .table--with-service td:nth-child(4) { width: 20%; text-align: right; }
.table--with-service td:nth-child(1) { font-weight: 600; }

/* Chips */
.controls-row { display:flex; align-items:center; gap:12px; flex-wrap:wrap; padding:10px 16px; }
.chip-group { display:flex; gap:8px; background:#fff; border:1px solid #e5e7eb; padding:6px; border-radius:12px; position: relative; }
.chip { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:999px; border:1px solid #e5e7eb; background:#f8fafc; cursor:pointer; font-weight:600; font-size:13px; }
.chip i { font-size:12px; }
.chip.active { background:#111e6c; color:#fff; border-color:#111e6c; box-shadow:0 2px 6px rgba(17,30,108,.25); }
.chip:not(.active):hover { border-color:#cbd5e1; }

/* Hide any previous Newest/Oldest groups if present */
#dateSort, #dateSort2 { display: none !important; }

/* Month-Year popover */
.popover { position:absolute; top:100%; left:0; margin-top:8px; background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 10px 30px rgba(2,6,23,.12); padding:12px; width: 280px; z-index: 10; display:none; }
.popover.open { display:block; }
.popover .row { display:flex; gap:8px; margin-bottom:10px; }
.popover select { flex:1; padding:8px 10px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; }
.popover .actions { display:flex; gap:8px; justify-content:flex-end; }
.popover .actions .btn { padding:8px 10px; }
.popover .actions .btn.clear { background:#0f172a; }
.date-chip { min-width: 170px; justify-content: space-between; }
.date-chip .label { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

/* ===== Profile (read-only) styles - ADD-ONLY ===== */
.profile-greet { font-weight:800; font-size:18px; color:#0f172a; margin-bottom:6px; }
.profile-grid { display:grid; grid-template-columns: repeat(2,1fr); gap:12px; }
@media (max-width: 720px){ .profile-grid { grid-template-columns: 1fr; } }
.input.readonly { background:#f9fafb; color:#334155; }
.input.readonly:focus { border-color:#d1d5db; box-shadow:none; }

/* ====== ADD ABOVE THIS LINE (STYLES) ===== */
/* ========================================= */
</style>
</head>
<body>

<aside class="sidebar">
  <div class="brand">
    <img src="image/httc_main-logo.jpg" alt="" />
    <span>HTCCC SYSTEM</span>
  </div>

  <div class="user-card">
    <img src="css/image/profile.png" alt="user">
    <div>
      <div class="user-title">Secretary</div>
      <div class="user-sub">Dashboard</div>
    </div>
  </div>

  <nav class="nav">
    <div class="section-title">Main</div>
    <a class="navlink" href="secretary_dashboard.php"><i class="fas fa-home"></i>Dashboard</a>

    <div class="section-title">Online Requests</div>
    <a class="navlink" href="admin-schedule-request.php">
      <i class="fas fa-calendar-plus"></i>Schedule Requests
    </a>
    <a class="navlink" href="admin-prayer-request.php">
      <i class="fas fa-praying-hands"></i><span>Prayer Requests</span>
    </a>

    <div class="section-title">Online Applications</div>
        <a class="navlink" href="">
      <i class="fas fa-water"></i>Baptismal Applications
    </a>
                <a class="navlink" href="admin-application.php">
      <i class="fas fa-user-cog"></i>Baptismal Account Verification
    </a>
    <a class="navlink" href="application_ministry.php">
      <i class="fas fa-users"></i>Ministry Applications
    </a>

    <div class="section-title">Schedule</div>
    <a class="navlink" href="appointment-schedule.php">
      <i class="fas fa-calendar-check"></i>Service Schedule
    </a>

    <div class="section-title">All Done Services</div>
    <a class="navlink" href="done-service-wedding.php">
      <i class="fas fa-ring"></i>Wedding Service
    </a>
    <a class="navlink" href="done-service-dedication.php">
      <i class="fas fa-baby"></i>Child Dedication
    </a>
    <a class="navlink" href="done-service-funeral.php">
      <i class="fas fa-cross"></i>Funeral Service
    </a>
    <a class="navlink" href="done-service-house.php">
      <i class="fas fa-home"></i>House Blessing
    </a>
    <a class="navlink" href="done-service-baptism.php">
      <i class="fas fa-tint"></i>Water Baptism
    </a>

    <div class="section-title">Streaming</div>
    <a class="navlink" href="admin-multimedia.php">
      <i class="fas fa-broadcast-tower"></i>Streaming
    </a>


    <div class="section-title">Ministry Management</div>
    <a class="navlink" href="admin-ministry-women.php">
      <i class="fas fa-female"></i>Handmaid's of the Lord
    </a>
    <a class="navlink" href="admin-ministry-men.php">
      <i class="fas fa-male"></i>Men's Ministry
    </a>
    <a class="navlink" href="admin-ministry-music.php">
      <i class="fas fa-music"></i>Music Ministry
    </a>
    <a class="navlink" href="admin-ministry-usher.php">
      <i class="fas fa-hands-helping"></i>Usher &amp; Usherette
    </a>
    <a class="navlink" href="admin-ministry-junior.php">
      <i class="fas fa-child"></i>Junior Christ Ambassador
    </a>

    <div class="section-title">Reports</div>
    <a class="navlink" href="admin-reports.php">
      <i class="fas fa-file-alt"></i>Reports
    </a>

    <div class="section-title">Content</div>
    <a class="navlink" href="content-management_home-page.php">
      <i class="fas fa-edit"></i>Content Management
    </a>

    <div class="section-title">Certificates</div>
    <a class="navlink" href="certificate-table.php">
      <i class="fas fa-award"></i>Generate Certificate
    </a>

    <div class="section-title">Account</div>
    <a class="navlink active" href="admin-account-settings.php">
      <i class="fas fa-user-shield"></i>Account Settings
    </a>

    <div class="section-title">More</div>
    <a class="navlink logout" href="all_log-in.php">
      <i class="fas fa-sign-out-alt"></i>Log Out
    </a>
  </nav>
</aside>

<div class="main-spacer"></div>

<!-- =================================================================== -->
<!-- ====== ADD BELOW THIS LINE (SIDEBAR OVERLAP FIX) ================== -->
<style>
  #svcSort { display: none !important; }
  thead{background-color: #0f172a;}
  :root { --sidebar-width: 280px; }
  .sidebar { position: fixed; left: 0; top: 0; bottom: 0; width: var(--sidebar-width); height: 100vh; overflow-y: auto; z-index: 1000; }
  .main-spacer { margin-left: var(--sidebar-width); min-width: 0; padding-left: 16px; box-sizing: border-box; }

  /* ====== Keep prior pages hidden ===== */
  .main-spacer { display: none !important; }
  body { display: block !important; padding-left: 0 !important; background: #f8fafc; }

  @media (max-width: 720px) { .main-spacer { margin-left: var(--sidebar-width); padding-left: 8px; } .table-wrap { overflow-x: auto; } }
</style>
<!-- ====== ADD ABOVE THIS LINE (SIDEBAR OVERLAP FIX) ================== -->
<!-- =================================================================== -->


<!-- =================================================================== -->
<!-- ====== ADD BELOW THIS LINE (ACCOUNT SETTINGS UI + LOGIC) ========= -->
<?php
// ---- Server-side: Account Settings handlers (ADD-ONLY) ----
@session_start();
$flash_ok = '';
$flash_err = '';

function htccc_csrf_token() {
  if (empty($_SESSION['__csrf'])) {
    $_SESSION['__csrf'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['__csrf'];
}
function htccc_csrf_check($token) {
  return isset($_SESSION['__csrf']) && hash_equals($_SESSION['__csrf'], (string)$token);
}

// Bootstrap PDO
if (!isset($pdo)) { @include_once __DIR__ . '/connection.php'; }
if (!isset($pdo) || !($pdo instanceof PDO)) {
  try {
    $pdo = new PDO('mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4','root','',[
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
  } catch (Throwable $e) { $pdo = null; }
}

// Identify current admin
$admin_id = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : 0;
$current_email = isset($_SESSION['admin_emailaddress']) ? (string)$_SESSION['admin_emailaddress'] : '';
$session_admin_user = isset($_SESSION['admin_user']) ? (string)$_SESSION['admin_user'] : '';

// Try to read from DB for freshest email + full profile row
$admin_row = [];
$admin_name = $session_admin_user;
$pwd_request_status = 'No';
if ($pdo && $admin_id > 0) {
  try {
    $st = $pdo->prepare("SELECT * FROM admin_table WHERE admin_id = :id LIMIT 1");
    $st->execute([':id'=>$admin_id]);
    $row = $st->fetch();
    if ($row) {
      $admin_row = $row;
      if (!empty($row['admin_emailaddress'])) $current_email = (string)$row['admin_emailaddress'];
      if (!empty($row['password_request'])) $pwd_request_status = (string)$row['password_request'];
      // Prefer full name, else username, else session
      if (empty($admin_name)) {
        $admin_name = (string)($row['admin_fullname'] ?? ($row['admin_username'] ?? ''));
      }
    }
  } catch (Throwable $e) { /* keep session value */ }
}
if (empty($admin_name)) { $admin_name = 'Admin'; }

/* =========================== */
/* REMOVE: change_password logic (per request) */
/* =========================== */

/* SEND OTP endpoint (kept unchanged) */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['__action']) && $_POST['__action']==='send_otp') {
  header('Content-Type: application/json; charset=utf-8');
  $resp = ['ok'=>false,'message'=>''];
  if (!htccc_csrf_check($_POST['__csrf'] ?? '')) {
    $resp['message'] = 'Security check failed.'; echo json_encode($resp); exit;
  }
  $targetEmail = trim((string)($_POST['email'] ?? ''));
  if ($targetEmail === '' || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
    $resp['message'] = 'Please enter a valid email.'; echo json_encode($resp); exit;
  }

  // Throttle re-sends: 60 seconds
  $now = time();
  $ses = $_SESSION['email_otp'] ?? null;
  if ($ses && isset($ses['sent_at']) && ($now - (int)$ses['sent_at']) < 60) {
    $remaining = 60 - ($now - (int)$ses['sent_at']);
    $resp['message'] = 'Please wait '.$remaining.'s before requesting another OTP.';
    echo json_encode($resp); exit;
  }

  // Generate 6-digit OTP
  $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  $_SESSION['email_otp'] = [
    'code'    => $code,
    'email'   => $targetEmail,
    'expires' => $now + 600, // 10 minutes
    'sent_at' => $now,
    'attempts'=> 0
  ];

  // Compose email with greeting using logged-in admin name
  $fromDomain = $_SERVER['SERVER_NAME'] ?? 'localhost';
  $subject = 'HTCCC Email Verification OTP';
  $greet = $admin_name ? "Hi {$admin_name},\n\n" : '';
  $body = $greet.
          "Your verification code is: {$code}\n\n".
          "This code will expire in 10 minutes.\n\n".
          "Requested for: {$targetEmail}\n".
          "If you did not request this, you can ignore this email.";
  $headers = "From: HTCCC System <no-reply@{$fromDomain}>\r\n".
             "Reply-To: no-reply@{$fromDomain}\r\n".
             "X-Mailer: PHP/".PHP_VERSION;

  $sent = @mail($targetEmail, $subject, $body, $headers);

  if ($sent) {
    $resp['ok'] = true;
    $resp['message'] = 'OTP sent to '.$targetEmail.'. Please check your inbox or spam.';
  } else {
    unset($_SESSION['email_otp']);
    $resp['message'] = 'Failed to send OTP. Please try again later.';
  }
  echo json_encode($resp); exit;
}

// Handle Email Update (with OTP)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['__action']) && $_POST['__action']==='update_email') {
  if (!htccc_csrf_check($_POST['__csrf'] ?? '')) {
    $flash_err = 'Security check failed. Please try again.';
  } elseif (!$pdo || $admin_id<=0) {
    $flash_err = 'Unable to update email at the moment.';
  } else {
    $new_email = trim((string)($_POST['new_email'] ?? ''));
    $otp_input = trim((string)($_POST['otp_code'] ?? ''));
    if ($new_email === '') {
      $flash_err = 'Email cannot be empty.';
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
      $flash_err = 'Please enter a valid email address.';
    } else {
      $otpData = $_SESSION['email_otp'] ?? null;
      if (!$otpData) {
        $flash_err = 'Please request and enter the OTP sent to your email.';
      } else {
        $otpData['attempts'] = (int)($otpData['attempts'] ?? 0) + 1;
        $_SESSION['email_otp']['attempts'] = $otpData['attempts'];
        if ($otpData['attempts'] > 5) {
          unset($_SESSION['email_otp']);
          $flash_err = 'Too many attempts. Please request a new OTP.';
        } elseif ($otpData['email'] !== $new_email) {
          $flash_err = 'The OTP was sent to a different email. Please resend OTP for this email.';
        } elseif ($otpData['expires'] < time()) {
          unset($_SESSION['email_otp']);
          $flash_err = 'OTP has expired. Please request a new one.';
        } elseif (!hash_equals($otpData['code'], $otp_input)) {
          $flash_err = 'Invalid OTP. Please try again.';
        } else {
          try {
            $st = $pdo->prepare("UPDATE admin_table SET admin_emailaddress = :e WHERE admin_id = :id");
            $st->execute([':e'=>$new_email, ':id'=>$admin_id]);
            $current_email = $new_email;
            $_SESSION['admin_emailaddress'] = $new_email;
            $flash_ok = 'Email updated successfully.';
            unset($_SESSION['email_otp']);
          } catch (Throwable $e) {
            $flash_err = 'Email update failed.';
          }
        }
      }
    }
  }
}

/* ===== NEW: Request Password Reset (sets password_request='Yes') ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['__action']) && $_POST['__action']==='request_password_reset') {
  if (!htccc_csrf_check($_POST['__csrf'] ?? '')) {
    $flash_err = 'Security check failed. Please try again.';
  } elseif (!$pdo || $admin_id<=0) {
    $flash_err = 'Unable to send password reset request at the moment.';
  } else {
    try {
      $st = $pdo->prepare("UPDATE admin_table SET password_request = 'Yes' WHERE admin_id = :id");
      $st->execute([':id'=>$admin_id]);
      $pwd_request_status = 'Yes';
      $flash_ok = 'Password reset request recorded. An admin can now assist you.';
    } catch (Throwable $e) {
      $flash_err = 'Failed to record password reset request.';
    }
  }
}
$__csrf = htccc_csrf_token();

/* ===== ADD-ONLY: helper to pretty label keys ===== */
function htccc_label($key){
  $map = [
    'admin_id'=>'Admin ID',
    'admin_username'=>'Username',
    'admin_fullname'=>'Full Name',
    'admin_emailaddress'=>'Email',
    'admin_contact'=>'Contact No.',
    'admin_phone'=>'Phone',
    'admin_address'=>'Address',
    'admin_role'=>'Role',
    'created_at'=>'Created At',
    'updated_at'=>'Updated At',
  ];
  if (isset($map[$key])) return $map[$key];
  $x = trim(str_replace(['_','-'],' ',(string)$key));
  $x = ucwords($x);
  return $x;
}
?>
<style>
  /* ===== Account Settings Layout (ADD-ONLY) ===== */
  .account-wrap { margin-left: var(--sidebar-width); padding: 24px; }
  .account-container { max-width: 780px; margin: 0 auto; }
  .account-title { font-size: 22px; font-weight: 800; color: #0f172a; margin: 8px 0 16px; }
  .section { background:#fff; border:1px solid #e5e7eb; border-radius: 16px; box-shadow: 0 10px 30px rgba(2,6,23,.06); margin-bottom: 16px; overflow: hidden; }
  .section-header { padding: 16px 18px; background:#f8fafc; border-bottom:1px solid #e5e7eb; font-weight:700; color:#0f172a; }
  .section-body { padding: 18px; }
  .field { margin-bottom: 14px; }
  .label { display:block; font-weight: 700; font-size: 13px; color:#0f172a; margin-bottom:6px; }
  .input { width:100%; padding:12px 12px; border:1px solid #d1d5db; border-radius:12px; font-size:14px; outline:none; }
  .input:focus { border-color:#2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.15); }
  .help { font-size:12px; color:#64748b; margin-top:4px; }
  .actions { display:flex; gap:10px; justify-content:flex-end; margin-top: 8px; }
  .btn-primary { background:#2563eb; color:#fff; border:none; border-radius:12px; padding:10px 14px; font-weight:700; cursor:pointer; }
  .btn-primary:hover { background:#1e40af; }
  .btn-dark { background:#0f172a; color:#fff; border:none; border-radius:12px; padding:10px 14px; font-weight:700; cursor:pointer; }
  .btn-dark:hover { background:#0b1224; }
  .banner { padding:12px 14px; border-radius:12px; margin-bottom: 12px; font-size:14px; }
  .banner.ok { background:#ecfdf5; color:#065f46; border:1px solid #10b981; }
  .banner.err { background:#fef2f2; color:#991b1b; border:1px solid #ef4444; }

  .otp-row { display:flex; gap:8px; align-items:center; }
  .otp-row .input { flex:1; }
  .tiny { font-size:12px; color:#64748b; margin-top:6px; }

  .badge { display:inline-block; border:1px solid #e5e7eb; border-radius:999px; padding:4px 10px; font-size:12px; background:#fff; color:#0f172a; }
  .badge.success{ background:#ecfdf5; border-color:#10b981; color:#065f46; }
</style>

<main class="account-wrap" aria-label="Account Settings">
  <div class="account-container">
    <div class="account-title"><i class="fas fa-user-cog"></i> Account Settings</div>

    <?php if ($flash_ok): ?>
      <div class="banner ok" role="status"><?= htmlspecialchars($flash_ok) ?></div>
    <?php endif; ?>
    <?php if ($flash_err): ?>
      <div class="banner err" role="alert"><?= htmlspecialchars($flash_err) ?></div>
    <?php endif; ?>

    <!-- ===== PROFILE (READ-ONLY) ===== -->
    <section class="section" aria-labelledby="profileSection">
      <div class="section-header" id="profileSection">Profile</div>
      <div class="section-body">
        <div class="profile-greet">Hi, <?= htmlspecialchars($admin_name) ?>!</div>
        <div class="profile-grid">
          <?php
          $displayOrder = [
            'admin_username','admin_fullname','admin_emailaddress',
            'admin_role','admin_contact','admin_phone','admin_address',
            'created_at','updated_at'
          ];
          $rendered = false;
          foreach ($displayOrder as $k) {
            if (!array_key_exists($k, $admin_row)) continue;
            $v = $admin_row[$k];
            if ($k === 'admin_password') continue;
            if (in_array($k, ['created_at','updated_at'], true) && !empty($v)) {
              $ts = strtotime((string)$v);
              if ($ts) { $v = date('F j, Y g:i A', $ts); }
            }
            ?>
            <div class="field">
              <label class="label"><?= htmlspecialchars(htccc_label($k)) ?></label>
              <input class="input readonly" type="text" value="<?= htmlspecialchars((string)$v) ?>" readonly>
            </div>
            <?php
            $rendered = true;
          }
          if (!$rendered) {
            ?>
            <div class="field">
              <label class="label">Username</label>
              <input class="input readonly" type="text" value="<?= htmlspecialchars($session_admin_user ?: '—') ?>" readonly>
            </div>
            <div class="field">
              <label class="label">Email</label>
              <input class="input readonly" type="text" value="<?= htmlspecialchars($current_email ?: '—') ?>" readonly>
            </div>
            <?php
          }
          ?>
        </div>
        <div class="tiny">All fields here are read-only. To change your email or password, use the sections below.</div>
      </div>
    </section>

    <!-- Email Update -->
    <section class="section" aria-labelledby="emailSection">
      <div class="section-header" id="emailSection">Email Address</div>
      <div class="section-body">
        <form method="post" onsubmit="return validateEmailForm(this);">
          <input type="hidden" name="__action" value="update_email">
          <input type="hidden" name="__csrf" value="<?= htmlspecialchars($__csrf) ?>">
          <div class="field">
            <label class="label" for="new_email">Current / New Email</label>
            <div class="otp-row">
              <input class="input" id="new_email" name="new_email" type="email" value="<?= htmlspecialchars($current_email) ?>" autocomplete="email" required>
              <button class="btn-dark" type="button" id="btnSendOtp" onclick="sendOtp()">
                <i class="fas fa-paper-plane"></i> Send OTP
              </button>
            </div>
            <div class="help">Enter the email you want on your account, then click <b>Send OTP</b>. Code is valid for 10 minutes.</div>
            <div class="tiny" id="otpInfo" aria-live="polite"></div>
          </div>

          <div class="field">
            <label class="label" for="otp_code">Verification Code (OTP)</label>
            <input class="input" id="otp_code" name="otp_code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="6-digit code" required>
            <div class="help">Check your inbox/spam. You can resend after the timer finishes.</div>
          </div>

          <div class="actions">
            <button class="btn-primary" type="submit"><i class="fas fa-save"></i> Update Email</button>
          </div>
        </form>
      </div>
    </section>

    <!-- ===== REPLACED: Change Password -> Password Reset Request ===== -->
    <section class="section" aria-labelledby="passwordSection">
      <div class="section-header" id="passwordSection">Password Reset Request</div>
      <div class="section-body">
        <p class="help" style="margin-top:0">
          Click the button below to flag your account for a password reset. An admin can then process your request.
        </p>
        <p>
          Current request status:
          <?php if (strtoupper($pwd_request_status) === 'YES'): ?>
            <span class="badge success">Requested</span>
          <?php else: ?>
            <span class="badge">Not Requested</span>
          <?php endif; ?>
        </p>
        <form method="post">
          <input type="hidden" name="__action" value="request_password_reset">
          <input type="hidden" name="__csrf" value="<?= htmlspecialchars($__csrf) ?>">
          <div class="actions">
            <button class="btn-dark" type="submit"><i class="fas fa-sync"></i> Flag for Password Reset</button>
          </div>
        </form>
      </div>
    </section>
  </div>
</main>

<script>
  function validateEmailForm(f){
    const email = f.new_email.value.trim();
    if(!email){ alert('Email cannot be empty.'); return false; }
    const ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    if(!ok){ alert('Please enter a valid email address.'); return false; }
    const otp = f.otp_code.value.trim();
    if(!/^\d{6}$/.test(otp)){ alert('Please enter the 6-digit OTP.'); return false; }
    return true;
  }

  // Send OTP logic (AJAX + cooldown)
  let otpCooldown = 0, otpTimer = null;

  function sendOtp(){
    const emailEl = document.getElementById('new_email');
    const infoEl  = document.getElementById('otpInfo');
    const btn     = document.getElementById('btnSendOtp');
    const email   = (emailEl.value || '').trim();
    if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){
      alert('Please enter a valid email first.'); emailEl.focus(); return;
    }
    if(otpCooldown > 0){ return; }

    const fd = new FormData();
    fd.append('__action','send_otp');
    fd.append('__csrf','<?= htmlspecialchars($__csrf) ?>');
    fd.append('email', email);

    btn.disabled = true;
    infoEl.textContent = 'Sending OTP...';

    fetch(location.href, { method:'POST', body: fd })
      .then(r=>r.json()).then(j=>{
        if(j.ok){
          infoEl.textContent = j.message;
          startOtpCooldown(btn, infoEl, 60);
        } else {
          infoEl.textContent = j.message || 'Failed to send OTP.';
          btn.disabled = false;
        }
      }).catch(()=>{
        infoEl.textContent = 'Network error while sending OTP.';
        btn.disabled = false;
      });
  }

  function startOtpCooldown(btn, infoEl, seconds){
    otpCooldown = seconds;
    tick();
    otpTimer = setInterval(tick, 1000);
    function tick(){
      if(otpCooldown <= 0){
        clearInterval(otpTimer);
        btn.disabled = false;
        infoEl.textContent = 'You can request a new OTP again.';
        return;
      }
      btn.disabled = true;
      infoEl.textContent = 'OTP sent. You can resend in ' + otpCooldown + 's.';
      otpCooldown--;
    }
  }
</script>
<!-- ====== ADD ABOVE THIS LINE (ACCOUNT SETTINGS UI + LOGIC) ========= -->
<!-- =================================================================== -->

<!-- ---------- ADD BELOW THIS LINE: De-dup guard for injected Account Settings UI ---------- -->
<style>
  .account-wrap.htccc-dupe { display: none !important; }
</style>
<script>
(function(){
  const sidebars = Array.from(document.querySelectorAll('.sidebar'));
  if (sidebars.length > 1) {
    sidebars.slice(1).forEach(s => s.remove());
  }
  const wraps = Array.from(document.querySelectorAll('.account-wrap'));
  if (wraps.length > 1) {
    wraps.slice(1).forEach(w => w.remove());
  }
  const ids = ['profileSection','emailSection','passwordSection'];
  ids.forEach(id => {
    const nodes = Array.from(document.querySelectorAll('#'+id));
    if (nodes.length > 1) nodes.slice(1).forEach(n => {
      const sec = n.closest('.section'); (sec ? sec : n).remove();
    });
  });
})();
</script>
<!-- ---------- ADD ABOVE THIS LINE ---------- -->

</body>
</html>
<!-- ===== ADD-ONLY: Dedupe & Cleanup Patch (place before </body>) ===== -->
<style>
  .htccc-hide { display: none !important; }
</style>
<script>
(function(){
  try {
    const accWraps = document.querySelectorAll('.account-wrap');
    accWraps.forEach(w => w.remove());
  } catch(e){}
  try {
    const ACTION_SELECTORS = [
      'form:has(input[name="__action"][value="edit_ministry"])',
      'form:has(input[name="__action"][value="edit_ministry_men"])',
      'form:has(input[name="__action"][value="edit_ministry_music"])',
      'form:has(input[name="__action"][value="edit_ministry_usher"])',
      'form:has(input[name="__action"][value="edit_ministry_junior"])'
    ];
    ACTION_SELECTORS.forEach(sel => {
      const forms = Array.from(document.querySelectorAll(sel));
      if (forms.length > 1) {
        forms.slice(1).forEach(f => {
          const modal = f.closest('.modal, .swal2-container, .dialog, .card, .section');
          (modal || f).remove();
        });
      }
    });
  } catch(e){}
  try {
    const allForms = Array.from(document.forms || []);
    const seen = new Set();
    allForms.forEach(f => {
      const act = (f.getAttribute('action') || '').trim();
      const aa  = (f.querySelector('input[name="__action"]') || {}).value || '';
      const names = Array.from(f.querySelectorAll('input[name],select[name],textarea[name]'))
                     .map(el => el.getAttribute('name')).sort().join('|');
      const sig = [act, aa, names].join('::');
      if (seen.has(sig)) {
        const wrap = f.closest('.modal, .swal2-container, .dialog, .card, .section');
        (wrap || f).remove();
      }
      seen.add(sig);
    });
  } catch(e){}
})();
</script>
<!-- ADD BELOW THIS LINE: SweetAlert2 integration -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
  // Reusable toast
  function toast(icon, title, text) {
    const T = Swal.mixin({
      toast: true, position: 'top-end', showConfirmButton: false, timer: 2600, timerProgressBar: true
    });
    T.fire({ icon: icon || 'info', title: title || '', text: text || '' });
  }

  // Expose helpers if you want to call them later
  window.HTCCC = window.HTCCC || {};
  HTCCC.swal = Swal;
  HTCCC.toast = toast;

  // Upgrade simple alert() calls to SweetAlert2 (keeps existing code working)
  window.alert = function (msg) {
    Swal.fire({ icon: 'info', title: 'Notice', text: String(msg || '') });
  };

  // Show PHP flash banners as SweetAlerts (and keep the banners on the page as a fallback)
  (function promoteBanners() {
    const okEl  = document.querySelector('.banner.ok');
    const errEl = document.querySelector('.banner.err');
    if (okEl && okEl.textContent.trim()) {
      Swal.fire({ icon: 'success', title: 'Success', text: okEl.textContent.trim(), confirmButtonColor: '#2563eb' });
    }
    if (errEl && errEl.textContent.trim()) {
      Swal.fire({ icon: 'error', title: 'Oops', text: errEl.textContent.trim(), confirmButtonColor: '#2563eb' });
    }
  })();

  // Confirm before submitting the "Flag for Password Reset" form
  (function hookPasswordRequestConfirm() {
    document.querySelectorAll('form input[name="__action"][value="request_password_reset"]').forEach(inp => {
      const form = inp.closest('form');
      if (!form) return;
      // Guard against double-binding
      if (form.dataset.swalBound === '1') return;
      form.dataset.swalBound = '1';

      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        Swal.fire({
          icon: 'question',
          title: 'Request password reset?',
          text: 'Are you sure you would like to reset your password? A request will be sent to Pastor.',
          showCancelButton: true,
          confirmButtonText: 'Yes, continue',
          cancelButtonText: 'Cancel',
          confirmButtonColor: '#2563eb'
        }).then(res => {
          if (res.isConfirmed) form.submit();
        });
      });
    });
  })();

  // Nicer email + OTP validation (replaces alerts inside validateEmailForm if present)
  if (typeof window.validateEmailForm === 'function') {
    const orig = window.validateEmailForm;
    window.validateEmailForm = function (f) {
      const email = (f.new_email?.value || '').trim();
      const otp   = (f.otp_code?.value || '').trim();

      if (!email) {
        Swal.fire({ icon: 'warning', title: 'Missing email', text: 'Email cannot be empty.' });
        return false;
      }
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        Swal.fire({ icon: 'error', title: 'Invalid email', text: 'Please enter a valid email address.' });
        return false;
      }
      if (!/^\d{6}$/.test(otp)) {
        Swal.fire({ icon: 'warning', title: 'Invalid OTP', text: 'Please enter the 6-digit OTP.' });
        return false;
      }
      return true; // let the original submit proceed
    };
  }

  // Pop up feedback whenever the OTP status text changes
  (function observeOtpInfo() {
    const infoEl = document.getElementById('otpInfo');
    if (!infoEl) return;
    const observer = new MutationObserver(() => {
      const t = (infoEl.textContent || '').trim();
      if (!t) return;
      const icon = /error|fail|invalid|expired|network/i.test(t)
        ? 'error'
        : (/wait|resend|cooldown/i.test(t) ? 'info' : 'success');
      Swal.fire({ icon, title: icon === 'success' ? 'OTP Sent' : (icon === 'error' ? 'OTP Error' : 'Please wait'), text: t, confirmButtonColor: '#2563eb' });
    });
    observer.observe(infoEl, { childList: true, characterData: true, subtree: true });
  })();

})();
</script>
<!-- ADD ABOVE THIS LINE -->

<!-- ===== END ADD-ONLY PATCH ===== -->

END FILE
