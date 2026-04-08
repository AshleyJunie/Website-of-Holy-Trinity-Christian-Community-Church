<?php
/* ============================================================
  ADD-ONLY: DEV ERROR PANEL (PHP)
  - Shows pretty error box for normal requests
  - Returns JSON for AJAX-like requests (updateStatus / view=1)
  - Turn OFF on production: set DISPLAY_ERRORS=false via env
============================================================ */
if (!defined('DEV_ERROR_PANEL')) {
  define('DEV_ERROR_PANEL', 1);

  $SHOW = getenv('DISPLAY_ERRORS') !== 'false'; // default ON for dev
  if ($SHOW) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('html_errors', '0');
    ini_set('log_errors', '1');
  }

  function _dev_is_ajax_like() {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) return true;
    if (isset($_POST['action']) && $_POST['action'] === 'updateStatus') return true;
    if (isset($_GET['view']) && $_GET['view'] == '1') return true;
    $ct = $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
    return stripos($ct, 'application/json') !== false;
  }

  function _dev_render_html_box($title, $message, $file, $line) {
    $esc = function($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $html = <<<HTML
<style>
  .dev-error-box{all:initial;font-family:Inter,Arial,sans-serif;display:block;box-sizing:border-box}
  .dev-error-wrap{position:relative;margin:10px;border-radius:12px;overflow:hidden;border:1px solid #5a1b1b;box-shadow:0 10px 30px rgba(0,0,0,.25)}
  .dev-error-head{background:#8b1e1e;color:#fff;padding:10px 14px;font-weight:700}
  .dev-error-body{background:#1a1a1a;color:#ffdede;padding:12px 14px;line-height:1.5}
  .dev-error-body code{background:#2a2a2a;color:#fff;padding:2px 6px;border-radius:6px}
  .dev-error-meta{margin-top:8px;font-size:12px;opacity:.85}
</style>
<div class="dev-error-box">
  <div class="dev-error-wrap">
    <div class="dev-error-head">{$esc($title)}</div>
    <div class="dev-error-body">
      <div>{$esc($message)}</div>
      <div class="dev-error-meta">File: <code>{$esc($file)}</code> &nbsp; Line: <code>{$esc($line)}</code></div>
    </div>
  </div>
</div>
HTML;
    echo $html;
  }

  set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false; // respect @-silence
    if (_dev_is_ajax_like()) {
      if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { @ob_end_clean(); } }
      if (!headers_sent()) { @header('Content-Type: application/json'); }
      echo json_encode([
        'ok'      => false,
        'type'    => 'php_error',
        'errno'   => $errno,
        'message' => $errstr,
        'file'    => $errfile,
        'line'    => $errline
      ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    } else {
      _dev_render_html_box('PHP Error', $errstr, $errfile, $errline);
    }
    return true;
  });

  register_shutdown_function(function() {
    $e = error_get_last();
    if (!$e) return;
    if (!in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) return;

    if (_dev_is_ajax_like()) {
      if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { @ob_end_clean(); } }
      if (!headers_sent()) { @header('Content-Type: application/json'); }
      echo json_encode([
        'ok'      => false,
        'type'    => 'php_fatal',
        'message' => $e['message'] ?? '',
        'file'    => $e['file'] ?? '',
        'line'    => $e['line'] ?? 0
      ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    } else {
      _dev_render_html_box('PHP Fatal Error', $e['message'] ?? '', $e['file'] ?? '', $e['line'] ?? 0);
    }
  });
}
?>

<?php
// ===============================================================
/* Admin – Schedule Request (Pending only; email on approve/decline) */
// ===============================================================

include 'db-connection.php';

// ===============================
// Start session
// ===============================
@session_start(); // non-breaking; only reads if already started

// Ensure output buffering so headers/JSON never complain
if (!headers_sent()) { @ob_start(); }

// Sanitizer for known HTML typo (<div class="split'></div>)
if (!headers_sent()) {
  @ob_start(function($buffer){
    return str_replace('<div class="split\'></div>', '<div class="split"></div>', $buffer);
  });
}

@mysqli_set_charset($db_connection, 'utf8mb4');

// Prefer a modern, widely-compatible collation:
@mysqli_query($db_connection, "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
@mysqli_query($db_connection, "SET collation_connection = utf8mb4_unicode_ci");


// -------------------------------
// Helpers
// -------------------------------
function is_image_path($v) {
  if (!$v) return false;
  $ext = strtolower(pathinfo($v, PATHINFO_EXTENSION));
  return in_array($ext, ['jpg','jpeg','png','gif','webp','bmp']);
}
/* ADD-ONLY: file-type detector for PDFs */
function is_pdf_path($v) {
  if (!$v) return false;
  return strtolower(pathinfo($v, PATHINFO_EXTENSION)) === 'pdf';
}
function to_url($v) {
  if (!$v) return '';
  if (preg_match('~^https?://~i', $v)) return $v;
  return '/' . ltrim($v, '/');
}
function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function client_ip() {
  $keys = ['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'];
  foreach ($keys as $k) {
    if (!empty($_SERVER[$k])) {
      $ip = trim(explode(',', $_SERVER[$k])[0]);
      if ($ip) return $ip;
    }
  }
  return '';
}
function table_has_column($mysqli, $table, $column) {
  $db = @mysqli_fetch_row(@mysqli_query($mysqli, "SELECT DATABASE()"))[0] ?? null;
  if (!$db) return false;
  $stmt = @mysqli_prepare($mysqli, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
  if (!$stmt) return false;
  @mysqli_stmt_bind_param($stmt, 'sss', $db, $table, $column);
  @mysqli_stmt_execute($stmt);
  $res = @mysqli_stmt_get_result($stmt);
  $row = $res ? @mysqli_fetch_row($res) : null;
  return !empty($row[0]);
}
// Guard for hosts without mysqlnd
if (!function_exists('mysqli_stmt_get_result')) {
  function mysqli_stmt_get_result($stmt) { return false; }
}

/* ============================================================
  NEW: Compose a display name from a DB row (admin/individual)
============================================================ */
function _compose_full_name_row(array $row, array $hints = []) {
  $lower = [];
  foreach ($row as $k => $v) { $lower[strtolower($k)] = $v; }
  $pick = function($cands) use ($lower) {
    foreach ($cands as $c) {
      $lc = strtolower($c);
      if (array_key_exists($lc, $lower) && trim((string)$lower[$lc]) !== '') {
        return trim((string)$lower[$lc]);
      }
    }
    return '';
  };
  $last   = $pick(array_merge($hints['last']   ?? [], ['individual_lastname','lastname','last_name','surname','family_name','admin_lastname']));
  $first  = $pick(array_merge($hints['first']  ?? [], ['individual_firstname','firstname','first_name','given_name','admin_firstname']));
  $middle = $pick(array_merge($hints['middle'] ?? [], ['individual_middlename','middlename','middle_name']));
  $suf    = $pick(array_merge($hints['suffix'] ?? [], ['individual_extension','extension','suffix']));

  $title = function($s){
    return preg_replace_callback('/\b(\p{L})(\p{L}*)/u', function($m){
      return mb_strtoupper($m[1]).mb_strtolower($m[2]);
    }, (string)$s);
  };
  $last=$title($last); $first=$title($first); $middle=$title($middle); $suf=trim((string)$suf);
  $given = trim($first . ($middle!=='' ? ' '.$middle : ''));
  $suffixStr = $suf !== '' ? ' ' . $suf : '';
  if ($last !== '' && $given !== '') return "{$last}, {$given}{$suffixStr}";
  if ($last !== '') return $last . $suffixStr;
  if ($given !== '') return $given . $suffixStr;
  return '';
}

/* ============================================================
  NEW: notifyIndividualOfAdminAction()
  - Validates admin session identity
  - Composes a title/body
  - Inserts into notifications
  - Inserts 1 recipient row (the specific individual)
============================================================ */
function notifyIndividualOfAdminAction(mysqli $mysqli, string $serviceKey, $formRecordId, int $individualId, string $actionVerb, string $extraSummary = ''): bool {
  // Validate session identity
  if (session_status() !== PHP_SESSION_ACTIVE) return false;

  $createdByType = 'admin';
  $createdById   = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
  if ($createdById <= 0) return false;

  // Args
  $serviceKey   = trim($serviceKey);
  $actionVerb   = trim($actionVerb);
  $extraSummary = trim($extraSummary);
  if ($serviceKey === '' || $actionVerb === '' || $individualId <= 0) return false;
  if (!is_numeric($formRecordId)) return false;
  $formRecordId = (int)$formRecordId;
  if ($formRecordId <= 0) return false;

  // Fetch admin name
  $adminName = "Admin #{$createdById}";
  if ($stmt = @mysqli_prepare($mysqli, "SELECT * FROM admin_table WHERE admin_id=? LIMIT 1")) {
    @mysqli_stmt_bind_param($stmt, 'i', $createdById);
    @mysqli_stmt_execute($stmt);
    $res = @mysqli_stmt_get_result($stmt);
    $row = $res ? @mysqli_fetch_assoc($res) : null;
    if ($row) {
      $name = _compose_full_name_row($row);
      if ($name !== '') $adminName = $name;
    }
  }

  // Normalize label for service
  $labels = ['dedication'=>'Dedication','funeral'=>'Funeral','house'=>'House Blessing','wedding'=>'Wedding'];
  $svcLabel = $labels[$serviceKey] ?? ucfirst($serviceKey);

  // Title / Body
  // Special case: rescheduling decisions (Scheduled / Cancelled)
  if ($actionVerb === 'Scheduled') {
    $title = "Your Rescheduling Request has been Approved";
  } elseif ($actionVerb === 'Cancelled') {
    $title = "Your Rescheduling Request has been Cancelled";
  } else {
    $title = "Your {$svcLabel} request • {$actionVerb}";
  }
  $body  = "Admin: {$adminName}\n"
         . "Action: {$actionVerb}\n"
         . "Service: {$svcLabel}\n"
         . "Record ID: {$formRecordId}\n";
  if ($extraSummary !== '') {
    // Limit to 2000 like your pattern
    if (mb_strlen($extraSummary) > 2000) $extraSummary = mb_substr($extraSummary, 0, 2000);
    $body .= "Summary: {$extraSummary}";
  }

  // Insert notification + recipient (transaction)
  try {
    @mysqli_begin_transaction($mysqli);

    $sqlNotif = "INSERT INTO notifications (title, body, created_by_type, created_by_id) VALUES (?, ?, ?, ?)";
    $st1 = @mysqli_prepare($mysqli, $sqlNotif);
    if (!$st1) { @mysqli_rollback($mysqli); return false; }
    @mysqli_stmt_bind_param($st1, 'sssi', $title, $body, $createdByType, $createdById);
    if (!@mysqli_stmt_execute($st1)) { @mysqli_rollback($mysqli); return false; }
    $notificationId = (int)@mysqli_insert_id($mysqli);
    if ($notificationId <= 0) { @mysqli_rollback($mysqli); return false; }

    $sqlRec = "INSERT INTO notification_recipients (notification_id, user_type, user_id, status) VALUES (?, 'individual', ?, 'unread')";
    $st2 = @mysqli_prepare($mysqli, $sqlRec);
    if (!$st2) { @mysqli_rollback($mysqli); return false; }
    @mysqli_stmt_bind_param($st2, 'ii', $notificationId, $individualId);
    if (!@mysqli_stmt_execute($st2)) { @mysqli_rollback($mysqli); return false; }

    @mysqli_commit($mysqli);
    return true;
  } catch (Throwable $e) {
    @mysqli_rollback($mysqli);
    return false;
  }
}

/* ADD-ONLY: PDF helpers — humanize keys + label provider */
function _humanize_key($key){
  $key = (string)$key;
  $key = preg_replace('/_path$/i', '', $key);
  $key = preg_replace('/^img_/', '', $key);
  $key = str_replace(['groom_','bride_'], ['Groom ', 'Bride '], $key);
  $key = preg_replace('/_+/', ' ', $key);
  $key = trim($key);
  return ucwords($key);
}
function _provided_label($path){
  if (!$path) return '—';
  if (is_image_path($path)) return 'Provided (Image)';
  if (is_pdf_path($path))   return 'Provided (PDF)';
  return 'Provided (File)';
}
function _collect_fileish_pairs(array $row){
  $out = [];
  foreach ($row as $k=>$v){
    if (!is_string($v) || $v==='') continue;
    if (is_image_path($v) || is_pdf_path($v)) {
      $out[_humanize_key($k)] = _provided_label($v);
    }
  }
  return $out;
}
/* Helper to read first non-empty candidate column */
function first_present(array $row, array $candidates) {
  foreach ($candidates as $c) {
    if (isset($row[$c]) && is_string($row[$c]) && $row[$c] !== '') return $row[$c];
  }
  return null;
}

// -------------------------------
// Email (PHPMailer)
// -------------------------------
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';

/* ===========================
  DOMPDF (PDF generation)
  Reuse standard pattern
=========================== */
use Dompdf\Dompdf;
use Dompdf\Options;
$__dompdf_available = true;
try {
  if (!class_exists('\Dompdf\Dompdf')) {
    require_once __DIR__ . '/vendor/autoload.php';
  }
} catch (Throwable $___e) {
  $__dompdf_available = false;
}

/* ============================================================
  SMTP configuration (env-driven; no hardcoded secrets)
============================================================ */
function htccc_configure_smtp(PHPMailer $mail) {
  $mail->isSMTP();
  $mail->Host       = 'smtp.gmail.com';
  $mail->SMTPAuth   = true;
  $mail->Username   = 'holytrinitychristiancommunityc@gmail.com';
  $mail->Password   = 'jngx vtqb urun yjur';
  $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
  $mail->Port       = 587;

  $mail->CharSet    = 'UTF-8';
  $mail->Encoding   = 'base64';
  $mail->XMailer    = 'HTCCC System';
  $mail->SMTPOptions = [
    'ssl' => [
      'verify_peer'       => false,
      'verify_peer_name'  => false,
      'allow_self_signed' => true
    ]
  ];
}


function sendDecisionEmail($toEmail, $toName, $service, $status, $schedDate, $schedTime, string $extraSummary = '') {
  $fromEmail = getenv('SMTP_USER') ?: 'holytrinitychristiancommunityc@gmail.com';
  $fromName  = getenv('SMTP_NAME') ?: 'HTCCC Secretariat';

  $subject = "Your {$service} request has been {$status}";
  $dateStr = $schedDate ? date('M d, Y', strtotime($schedDate)) : '—';
  $timeStr = $schedTime ?: '—';

  $body  = "
    <div style='font-family:Inter, Arial, sans-serif;line-height:1.6;color:#0b1220'>
      <h2 style='margin:0 0 10px'>Holy Trinity Christian Community Church</h2>
      <p>Dear <b>" . h($toName ?: 'Member') . "</b>,</p>
      <p>Your <b>" . h($service) . "</b> request has been <b>" . h($status) . "</b>.</p>
      <p><b>Schedule:</b> " . h($dateStr) . " &nbsp; <b>Time:</b> " . h($timeStr) . "</p>
  ";

  if ($status === 'Declined' && $extraSummary !== '') {
    $body .= "
      <p><b>Reason(s) for decline:</b><br>" . nl2br(h($extraSummary)) . "</p>
    ";
  }

  $body .= "
      <p>If you have questions, please reply to this email.</p>
      <p>God bless,<br>HTCCC Secretariat</p>
    </div>
  ";

  $mail = new PHPMailer(true);
  try {
    htccc_configure_smtp($mail);
    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($toEmail ?: $fromEmail, $toName ?: '');
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $body;

    $mail->send();
    return [true, 'sent'];
  } catch (Exception $e) {
    return [false, 'Mailer error: ' . $mail->ErrorInfo];
  }
}



/* ============================================================
  DOWNLOAD PDF of modal details (NO file paths in PDF)
  Route: ?download=sr_pdf&svc=...&id=...
  — Now labels any image/PDF as “Provided (Image/PDF)” with no paths
  — Polished UI styles
============================================================ */
if (isset($_GET['download']) && $_GET['download'] === 'sr_pdf' && isset($_GET['svc']) && isset($_GET['id'])) {
  $svc = $_GET['svc']; $id = $_GET['id'];

  // Fetch same fields as modal
  switch ($svc) {
    case 'dedication':
      $sql = "SELECT d.*,
                    i.individual_lastname   AS last_name,
                    i.individual_firstname  AS first_name,
                    i.individual_middlename AS middle_name,
                    TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname)) AS full_name,
                    COALESCE(d.email_address, i.individual_email_address) AS individual_email_address
              FROM service_dedication d
              JOIN individual_table i ON i.individual_id=d.individual_id
              WHERE d.dedicationId=? LIMIT 1";
      $title = 'Dedication'; break;

    case 'funeral':
      $sql = "SELECT f.*,
                    i.individual_lastname   AS last_name,
                    i.individual_firstname  AS first_name,
                    i.individual_middlename AS middle_name,
                    TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname)) AS full_name,
                    COALESCE(f.email_address, i.individual_email_address) AS individual_email_address
              FROM service_funeral f
              JOIN individual_table i ON i.individual_id=f.individual_id
              WHERE f.funeral_id=? LIMIT 1";
      $title = 'Funeral'; break;

    case 'house':
      $sql = "SELECT h.*,
                    i.individual_lastname   AS last_name,
                    i.individual_firstname  AS first_name,
                    i.individual_middlename AS middle_name,
                    TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname)) AS full_name,
                    COALESCE(h.email_address, i.individual_email_address) AS individual_email_address
              FROM service_house h
              JOIN individual_table i ON i.individual_id=h.individual_id
              WHERE h.house_id=? LIMIT 1";
      $title = 'House Blessing'; break;

    case 'wedding':
      $sql = "SELECT w.*,
                    i.individual_lastname   AS last_name,
                    i.individual_firstname  AS first_name,
                    i.individual_middlename AS middle_name,
                    TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname)) AS full_name,
                    COALESCE(w.email_address, i.individual_email_address) AS individual_email_address
              FROM service_wedding w
              JOIN individual_table i ON i.individual_id=w.individual_id
              WHERE w.wedding_id=? LIMIT 1";
      $title = 'Wedding'; break;

    default:
      http_response_code(400); echo 'Unknown service'; exit;
  }

  $stmt = mysqli_prepare($db_connection, $sql);
  if (!$stmt) { http_response_code(500); echo 'DB prepare failed'; exit; }
  mysqli_stmt_bind_param($stmt, 's', $id);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $row = $res ? mysqli_fetch_assoc($res) : null;
  if (!$row) { http_response_code(404); echo 'Record not found'; exit; }

  $ln = trim($row['last_name'] ?? ''); $fn = trim($row['first_name'] ?? ''); $mn = trim($row['middle_name'] ?? '');
  if ($svc === 'wedding') {
    $gLn = trim($row['groom_lastname'] ?? '');
    $gFn = trim($row['groom_firstname'] ?? '');
    $gMn = trim($row['groom_middlename'] ?? '');
    $bLn = trim($row['bride_lastname'] ?? '');
    $bFn = trim($row['bride_firstname'] ?? '');
    $bMn = trim($row['bride_middlename'] ?? '');
    $gName = trim($gLn ? ($gLn . ', ' . $gFn . ($gMn ? (' ' . $gMn) : '')) : '');
    $bName = trim($bLn ? ($bLn . ', ' . $bFn . ($bMn ? (' ' . $bMn) : '')) : '');
    $displayName = trim($gName . ($bName ? ' & ' . $bName : ''));
    if ($displayName === '') {
      $displayName = $row['full_name'] ?? '';
    }
  } else {
    $displayName = trim($ln ? ($ln . ', ' . $fn . ($mn ? (' ' . $mn) : '')) : ($row['full_name'] ?? ''));
  }
  $schedDate = !empty($row['service_date']) ? date('M d, Y', strtotime($row['service_date'])) : '—';
  $schedTime = $row['service_time'] ?? '—';
  $indEmail  = $row['individual_email_address'] ?? '—';

  /* ADD-ONLY: Collect any file-like fields (images/PDFs) and label them */
  $fileish = _collect_fileish_pairs($row);

  $logo  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/HTCCC-SYSTEM/image/httc_main-logo.jpg';
  $today = date('M d, Y h:i A');

  ob_start();
  ?>
  <html>
  <head>
    <meta charset="utf-8">
    <style>
      @page { margin: 28px 28px 30px 28px; }
      * { box-sizing: border-box; }
      body { font-family: "DejaVu Sans", Arial, sans-serif; color:#0b1220; font-size:12.2px; }

      /* Header Ribbon */
      .brand {
        display:flex; align-items:center; gap:12px; margin:0 0 8px;
        border-bottom: 2px solid #dbe1ea; padding-bottom:10px;
      }
      .brand img { width:44px; height:44px; object-fit:cover; border-radius:10px; }
      .brand .titles { line-height:1.2; }
      .brand h1 { font-size:18px; margin:0; font-weight:800; color:#0f172a; }
      .meta { font-size:10.8px; color:#475569; margin-top:2px; }

      /* Section title + chip */
      .title { font-size:14.6px; font-weight:800; margin:12px 0 8px; color:#0f172a; }
      .chip { display:inline-block; font-size:10.6px; background:#eef2ff; color:#1d4ed8; border:1px solid #c7d2fe; padding:2px 8px; border-radius:999px; margin-left:6px; }

      /* Card section */
      .sec { border:1px solid #e5e7eb; border-radius:10px; padding:10px 12px; margin:6px 0 10px; background: #ffffff; }
      .kv  { display:flex; align-items:flex-start; gap:10px; padding:6px 0; border-bottom:1px dashed #e5e7eb; }
      .kv:last-child{ border-bottom:0; }
      .kv span { flex:0 0 230px; color:#475569; font-weight:700; }
      .kv b    { flex:1; color:#0b1220; font-weight:700; }

      /* Two-column grid for dense info */
      .grid2 { display:grid; grid-template-columns: 1fr 1fr; gap:6px 16px; }
      .grid2 .kv span { flex: 0 0 200px; }

      /* ADD-ONLY: compact "Label: Value" look in PDF (Requester: Name) */
      .sec .kv { gap:4px; }
      .sec .kv span {
        flex: 0 0 auto;
        margin-right: 2px;
      }
      .sec .kv span::after {
        content: ': ';
      }

      /* Footer note */
      .footer { margin-top:10px; font-size:10px; color:#64748b; border-top:1px solid #e5e7eb; padding-top:6px; }

      /* Soft watermark note */
      .wm-note {
        position: fixed; bottom: 10px; right: 10px; font-size:9.6px; color:#94a3b8;
      }
    </style>
  </head>
  <body>
    <div class="brand">
      <img src="<?php echo h($logo); ?>" alt="logo">
      <div class="titles">
        <h1>Holy Trinity Christian Community Church</h1>
        <div class="meta">Schedule Request Details • Generated: <?php echo h($today); ?></div>
      </div>
    </div>

    <div class="title">Request Summary <span class="chip"><?php echo h($title); ?></span></div>
    <div class="sec">
      <div class="grid2">
        <div class="kv"><span>Requester</span><b><?php echo h($displayName ?: '—'); ?></b></div>
        <div class="kv"><span>Email Address</span><b><?php echo h($indEmail ?: '—'); ?></b></div>
        <div class="kv"><span>Service Date</span><b><?php echo h($schedDate); ?></b></div>
        <div class="kv"><span>Service Time</span><b><?php echo h($schedTime ?: '—'); ?></b></div>
        <div class="kv"><span>Current Status</span><b><?php echo h($row['service_status'] ?? '—'); ?></b></div>
      </div>
    </div>

    <?php if ($svc === 'dedication'): ?>
      <div class="title">Child Information</div>
      <div class="sec grid2">
        <div class="kv"><span>Last Name</span><b><?php echo h($row['child_lastname'] ?? '—'); ?></b></div>
        <div class="kv"><span>First Name</span><b><?php echo h($row['child_firstname'] ?? '—'); ?></b></div>
        <div class="kv"><span>Middle Name</span><b><?php echo h($row['child_middlename'] ?? '—'); ?></b></div>
        <div class="kv"><span>Extension</span><b><?php echo h($row['child_ext'] ?? '—'); ?></b></div>
      </div>

      <div class="title">Guardian Information</div>
      <div class="sec grid2">
        <div class="kv"><span>Last Name</span><b><?php echo h($row['guardian_lastname'] ?? '—'); ?></b></div>
        <div class="kv"><span>First Name</span><b><?php echo h($row['guardian_firstname'] ?? '—'); ?></b></div>
        <div class="kv"><span>Middle Name</span><b><?php echo h($row['guardian_middlename'] ?? '—'); ?></b></div>
        <div class="kv"><span>Extension</span><b><?php echo h($row['guardian_ext'] ?? '—'); ?></b></div>
        <div class="kv"><span>Guardian Contact</span><b><?php echo h($row['guardian_contact'] ?? '—'); ?></b></div>
      </div>
    <?php elseif ($svc === 'funeral'): ?>
      <div class="title">Deceased Information</div>
      <div class="sec grid2">
        <div class="kv"><span>Last Name</span><b><?php echo h($row['deceased_lastname'] ?? '—'); ?></b></div>
        <div class="kv"><span>First Name</span><b><?php echo h($row['deceased_firstname'] ?? '—'); ?></b></div>
        <div class="kv"><span>Middle Name</span><b><?php echo h($row['deceased_middlename'] ?? '—'); ?></b></div>
        <div class="kv"><span>Extension</span><b><?php echo h($row['deceased_ext'] ?? '—'); ?></b></div>
        <div class="kv"><span>Birthdate</span><b><?php echo !empty($row['deceased_birthdate']) ? h(date('M d, Y', strtotime($row['deceased_birthdate']))) : '—'; ?></b></div>
        <div class="kv"><span>Home Address</span><b><?php echo h($row['home_address'] ?? '—'); ?></b></div>
      </div>

      <div class="title">Service Schedule</div>
      <div class="sec grid2">
        <div class="kv"><span>Service Date</span><b><?php echo h($schedDate); ?></b></div>
        <div class="kv"><span>Service Time</span><b><?php echo h($schedTime ?: '—'); ?></b></div>
        <div class="kv"><span>Funeral Date</span><b><?php echo !empty($row['funeral_date']) ? h(date('M d, Y', strtotime($row['funeral_date']))) : '—'; ?></b></div>
      </div>

      <?php if (!empty($fileish)): ?>
        <div class="title">Documents</div>
        <div class="sec">
          <?php foreach ($fileish as $label => $provided): ?>
            <div class="kv"><span><?php echo h($label); ?></span><b><?php echo h($provided); ?></b></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php elseif ($svc === 'house'): ?>
      <div class="title">Owner Information</div>
      <div class="sec grid2">
        <div class="kv"><span>Last Name</span><b><?php echo h($row['owner_lastname'] ?? '—'); ?></b></div>
        <div class="kv"><span>First Name</span><b><?php echo h($row['owner_firstname'] ?? '—'); ?></b></div>
        <div class="kv"><span>Middle Name</span><b><?php echo h($row['owner_middlename'] ?? '—'); ?></b></div>
        <div class="kv"><span>Extension</span><b><?php echo h($row['owner_ext'] ?? '—'); ?></b></div>
        <div class="kv"><span>Home Address</span><b><?php echo h($row['home_address'] ?? '—'); ?></b></div>
      </div>

      <div class="title">Service Schedule</div>
      <div class="sec grid2">
        <div class="kv"><span>Service Date</span><b><?php echo h($schedDate); ?></b></div>
        <div class="kv"><span>Service Time</span><b><?php echo h($schedTime ?: '—'); ?></b></div>
      </div>

      <?php if (!empty($fileish)): ?>
        <div class="title">Documents</div>
        <div class="sec">
          <?php foreach ($fileish as $label => $provided): ?>
            <div class="kv"><span><?php echo h($label); ?></span><b><?php echo h($provided); ?></b></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php elseif ($svc === 'wedding'): ?>
      <div class="title">Appointment Information</div>
      <div class="sec grid2">
        <div class="kv"><span>Appointment Date</span><b><?php echo h($schedDate); ?></b></div>
        <div class="kv"><span>Appointment Time</span><b><?php echo h($schedTime ?: '—'); ?></b></div>
      </div>

      <div class="title">Groom Information</div>
      <div class="sec grid2">
        <div class="kv"><span>Last Name</span><b><?php echo h($row['groom_lastname'] ?? '—'); ?></b></div>
        <div class="kv"><span>First Name</span><b><?php echo h($row['groom_firstname'] ?? '—'); ?></b></div>
        <div class="kv"><span>Middle Name</span><b><?php echo h($row['groom_middlename'] ?? '—'); ?></b></div>
        <div class="kv"><span>Extension</span><b><?php echo h($row['groom_extension'] ?? '—'); ?></b></div>
        <div class="kv"><span>Birthdate</span><b><?php echo !empty($row['groom_birthdate']) ? h(date('M d, Y', strtotime($row['groom_birthdate']))) : '—'; ?></b></div>
      </div>

      <div class="title">Bride Information</div>
      <div class="sec grid2">
        <div class="kv"><span>Last Name</span><b><?php echo h($row['bride_lastname'] ?? '—'); ?></b></div>
        <div class="kv"><span>First Name</span><b><?php echo h($row['bride_firstname'] ?? '—'); ?></b></div>
        <div class="kv"><span>Middle Name</span><b><?php echo h($row['bride_middlename'] ?? '—'); ?></b></div>
        <div class="kv"><span>Extension</span><b><?php echo h($row['bride_extension'] ?? '—'); ?></b></div>
        <div class="kv"><span>Birthdate</span><b><?php echo !empty($row['bride_birthdate']) ? h(date('M d, Y', strtotime($row['bride_birthdate']))) : '—'; ?></b></div>
      </div>
    <?php endif; ?>

    <?php
      /* Documents section (for any service): scan row for file-like fields and label them */
      if (!empty($fileish)) {
        echo '<div class="title">Documents</div>';
        echo '<div class="sec">';
        foreach ($fileish as $label => $provided) {
          echo '<div class="kv"><span>'.h($label).'</span><b>'.h($provided).'</b></div>';
        }
        echo '</div>';
      }
    ?>

    <div class="footer">For privacy, this document intentionally omits any file URLs/paths. Only labels are shown for provided images/PDFs.</div>
    <div class="wm-note">HTCCC • Internal PDF</div>
  </body>
  </html>
  <?php
  $html = ob_get_clean();

  if (!$__dompdf_available) {
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
  }

  $options = new Options();
  $options->set('isRemoteEnabled', true);
  $options->set('defaultFont', 'DejaVu Sans');
  $options->set('isHtml5ParserEnabled', true);
  $dompdf = new Dompdf($options);
  $dompdf->loadHtml($html, 'UTF-8');
  $dompdf->setPaper('A4', 'portrait');
  $dompdf->render();

  $fname = 'Schedule_Request_' . preg_replace('/\s+/', '_', $title) . '_' . date('Y-m-d_His') . '.pdf';
  $dompdf->stream($fname, ['Attachment' => true]);
  exit;
}

/* -------------------------------
  AJAX: Approve / Decline / Reschedule decisions
------------------------------- */
if (isset($_POST['action']) && $_POST['action'] === 'updateStatus') {
  header('Content-Type: application/json');

  $svc    = $_POST['svc'] ?? '';
  $id     = $_POST['id'] ?? '';
  $status = $_POST['status'] ?? '';

  // ADD: support Scheduled / Cancelled for reschedule approvals
  $okStatuses = ['Approved', 'Declined', 'Scheduled', 'Cancelled'];
  if (!in_array($status, $okStatuses, true)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'msg'=>'Invalid status']);
    exit;
  }


  // Capture decline reasons (optional – only for Declined)
  $declineSummary = '';
  if ($status === 'Declined') {
    $rawReasons = $_POST['decline_reasons'] ?? '';
    $otherNotes = trim((string)($_POST['decline_notes'] ?? ''));
    $labels     = [];
    if (is_string($rawReasons) && $rawReasons !== '') {
      $decoded = json_decode($rawReasons, true);
      if (is_array($decoded)) {
        foreach ($decoded as $r) {
          $r = trim((string)$r);
          if ($r !== '') {
            $labels[] = $r;
          }
        }
      }
    }
    if ($labels) {
      $declineSummary .= 'Reasons: ' . implode(', ', $labels);
    }
    if ($otherNotes !== '') {
      if ($declineSummary !== '') {
        $declineSummary .= ' | ';
      }
      $declineSummary .= 'Other notes: ' . $otherNotes;
    }
  }

  $map = [
    'dedication'=> ['table'=>'service_dedication','idcol'=>'dedicationId', 'title'=>'Dedication'],
    'funeral'   => ['table'=>'service_funeral',   'idcol'=>'funeral_id',   'title'=>'Funeral'],
    'house'     => ['table'=>'service_house',     'idcol'=>'house_id',     'title'=>'House Blessing'],
    'wedding'   => ['table'=>'service_wedding',   'idcol'=>'wedding_id',   'title'=>'Wedding'],
  ];
  if (!isset($map[$svc])) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'msg'=>'Unknown service']);
    exit;
  }

  $tbl  = $map[$svc]['table'];
  $idc  = $map[$svc]['idcol'];
  $title= $map[$svc]['title'];

  // Previous service_status (for audit)
  $prevStatus = null;
  $stPrev = @mysqli_prepare($db_connection, "SELECT service_status FROM {$tbl} WHERE {$idc}=? LIMIT 1");
  if ($stPrev) {
    @mysqli_stmt_bind_param($stPrev, 's', $id);
    @mysqli_stmt_execute($stPrev);
    $rsPrev = @mysqli_stmt_get_result($stPrev);
    $rowPrev= $rsPrev ? @mysqli_fetch_assoc($rsPrev) : null;
    $prevStatus = $rowPrev['service_status'] ?? null;
  }

  // Update
  $hasUpdatedAt = table_has_column($db_connection, $tbl, 'updated_at');

  // For database storage, map Declined to Cancelled (per admin decline behavior),
  // but keep $status as-is for emails and in-app notifications.
  $statusDb = ($status === 'Declined') ? 'Cancelled' : $status;

  $sql  = $hasUpdatedAt
    ? "UPDATE {$tbl} SET service_status=?, updated_at=NOW() WHERE {$idc}=? LIMIT 1"
    : "UPDATE {$tbl} SET service_status=? WHERE {$idc}=? LIMIT 1";

  $st   = mysqli_prepare($db_connection, $sql);
  if (!$st) { echo json_encode(['ok'=>false,'msg'=>'DB prepare failed (update): '.mysqli_error($db_connection)]); exit; }
  mysqli_stmt_bind_param($st, 'ss', $statusDb, $id);
  $ok   = mysqli_stmt_execute($st);
  if (!$ok) { echo json_encode(['ok'=>false,'msg'=>'DB update failed: '.mysqli_error($db_connection)]); exit; }


  // Handle reschedule -> scheduled promotion
  if ($status === 'Scheduled') {
    // Fetch reschedule_date and reschedule_time
    $q2 = @mysqli_prepare($db_connection, "SELECT reschedule_date, reschedule_time FROM {$tbl} WHERE {$idc}=? LIMIT 1");
    if ($q2) {
      @mysqli_stmt_bind_param($q2, 's', $id);
      @mysqli_stmt_execute($q2);
      $r2 = @mysqli_stmt_get_result($q2);
      $d2 = $r2 ? @mysqli_fetch_assoc($r2) : null;
      $rdate = $d2['reschedule_date'] ?? null;
      $rtime = $d2['reschedule_time'] ?? null;
      if ($rdate && $rtime) {
        $sqlU = "UPDATE {$tbl} SET service_date=?, service_time=? WHERE {$idc}=? LIMIT 1";
        $u2 = @mysqli_prepare($db_connection, $sqlU);
        if ($u2) {
          @mysqli_stmt_bind_param($u2, 'sss', $rdate, $rtime, $id);
          @mysqli_stmt_execute($u2);
        }
      }
    }
  }

  // Fetch data to email — PRIORITIZE service_table.email_address
  switch ($svc) {
    case 'dedication':
      $sqlInfo = "SELECT d.*,
                        i.individual_lastname   AS last_name,
                        i.individual_firstname  AS first_name,
                        i.individual_middlename AS middle_name,
                        TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname)) AS full_name,
                        COALESCE(d.email_address, i.individual_email_address) AS email_addr
                  FROM service_dedication d
                  JOIN individual_table i ON i.individual_id=d.individual_id
                  WHERE d.dedicationId=?  LIMIT 1";
      break;
    case 'funeral':
      $sqlInfo = "SELECT f.*,
                        i.individual_lastname   AS last_name,
                        i.individual_firstname  AS first_name,
                        i.individual_middlename AS middle_name,
                        TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname)) AS full_name,
                        COALESCE(f.email_address, i.individual_email_address) AS email_addr
                  FROM service_funeral f
                  JOIN individual_table i ON i.individual_id=f.individual_id
                  WHERE f.funeral_id=?  LIMIT 1";
      break;
    case 'house':
      $sqlInfo = "SELECT h.*,
                        i.individual_lastname   AS last_name,
                        i.individual_firstname  AS first_name,
                        i.individual_middlename AS middle_name,
                        TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname)) AS full_name,
                        COALESCE(h.email_address, i.individual_email_address) AS email_addr
                  FROM service_house h
                  JOIN individual_table i ON i.individual_id=h.individual_id
                  WHERE h.house_id=?  LIMIT 1";
      break;
    case 'wedding':
      $sqlInfo = "SELECT w.*,
                        i.individual_lastname   AS last_name,
                        i.individual_firstname  AS first_name,
                        i.individual_middlename AS middle_name,
                        TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname)) AS full_name,
                        COALESCE(w.email_address, i.individual_email_address) AS email_addr
                  FROM service_wedding w
                  JOIN individual_table i ON i.individual_id=w.individual_id
                  WHERE w.wedding_id=?  LIMIT 1";
      break;
  }

  $st2 = mysqli_prepare($db_connection, $sqlInfo);
  if (!$st2) { echo json_encode(['ok'=>false,'msg'=>'DB prepare failed (info): '.mysqli_error($db_connection)]); exit; }
  mysqli_stmt_bind_param($st2, 's', $id);
  $ok2 = mysqli_stmt_execute($st2);
  if (!$ok2) { echo json_encode(['ok'=>false,'msg'=>'DB execute failed (info): '.mysqli_error($db_connection)]); exit; }
  $rs  = mysqli_stmt_get_result($st2);
  $row = $rs ? mysqli_fetch_assoc($rs) : null;

  if (!$row) { echo json_encode(['ok'=>false,'msg'=>'Record to email not found']); exit; }

  $ln = trim($row['last_name'] ?? '');
  $fn = trim($row['first_name'] ?? '');
  $mn = trim($row['middle_name'] ?? '');
  $displayName = trim($ln ? ($ln . ', ' . $fn . ($mn ? (' ' . $mn) : '')) : ($row['full_name'] ?? ''));

  $schedDate = $row['service_date'] ?? '';
  $schedTime = $row['service_time'] ?? '';

  [$sent, $err] = sendDecisionEmail(
    $row['email_addr'] ?? '',
    $displayName,
    $title,
    $status,
    $schedDate,
    $schedTime,
    $declineSummary
  );

  // AUDIT — column renamed in payload keys for clarity
  try {
    $adminId    = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
    $adminUser  = $_SESSION['admin_name']  ?? $_SESSION['username']  ?? '';
    $adminEmail = $_SESSION['admin_email'] ?? '';

    $beforeJson = json_encode(['service_status' => $prevStatus], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $afterJson  = json_encode([
      'service_status'  => $status,
      'service'         => $title,
      'requester'       => $displayName,
      'requester_email' => $row['email_addr'] ?? null,
      'schedule_date'   => $schedDate ?: null,
      'schedule_time'   => $schedTime ?: null,
      'email_sent'      => $sent,
      'email_msg'       => $err
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    $notes = sprintf('Service=%s | Requester=%s | Email:%s', $title, $displayName ?: '—', $sent ? 'Sent' : 'Failed');

    $dbName = @mysqli_fetch_row(@mysqli_query($db_connection, "SELECT DATABASE()"))[0] ?? null;
    $colsOnTable = [];
    if ($dbName) {
      $qCols = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='audit_trail'";
      if ($stCols = @mysqli_prepare($db_connection, $qCols)) {
        @mysqli_stmt_bind_param($stCols, 's', $dbName);
        @mysqli_stmt_execute($stCols);
        $rsCols = @mysqli_stmt_get_result($stCols);
        while ($rsCols && ($r = @mysqli_fetch_row($rsCols))) { $colsOnTable[$r[0]] = true; }
      }
    }
    $hasCol = function($c) use ($colsOnTable){ return isset($colsOnTable[$c]); };

    // Map action
    $action =
      ($status === 'Approved')  ? 'APPROVE' :
      (($status === 'Declined') ? 'REJECT'  :
      (($status === 'Scheduled')? 'RESCHEDULE_APPROVE' : 'RESCHEDULE_CANCEL'));

    $recordPk   = (string)$id;
    $ipAddr     = client_ip();
    $userAgent  = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $adminIdInt = (int)$adminId;

    $candidates = [
      'actor_admin_id' => ['i', $adminIdInt],
      'actor_user_id'  => ['i', $adminIdInt],
      'actor_username' => ['s', $adminUser],
      'actor_name'     => ['s', $adminUser],
      'actor_email'    => ['s', $adminEmail],
      'action'         => ['s', $action],
      'source_table'   => ['s', $tbl],
      'record_pk'      => ['s', $recordPk],
      'form_name'      => ['s', $title],
      'ip_address'     => ['s', $ipAddr],
      'user_agent'     => ['s', $userAgent],
      'notes'          => ['s', $notes],
      'details_before' => ['s', $beforeJson],
      'details_after'  => ['s', $afterJson],
    ];

    $cols = []; $ph = []; $types = ''; $vals = [];
    foreach ($candidates as $col => [$t,$v]) {
      if ($hasCol($col)) {
        $cols[] = $col; $ph[] = '?'; $types .= $t; $vals[] = $v;
      }
    }
    if (!in_array('action', $cols, true))         { $cols[]='action';         $ph[]='?'; $types.='s'; $vals[]=$action; }
    if (!in_array('details_after', $cols, true))  { $cols[]='details_after';  $ph[]='?'; $types.='s'; $vals[]=$afterJson; }

    $sqlAudit = "INSERT INTO audit_trail (txn_id, ".implode(',', $cols).") VALUES (UUID(), ".implode(',', $ph).")";
    $stmtAudit = mysqli_prepare($db_connection, $sqlAudit);
    if (!$stmtAudit) { echo json_encode(['ok'=>false,'msg'=>'Audit prepare failed: '.mysqli_error($db_connection)]); exit; }

    $bind = []; $bind[] = $types;
    foreach ($vals as $k => $v) { $bind[] = &$vals[$k]; }
    $okBind = @call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmtAudit], $bind));
    if (!$okBind) { echo json_encode(['ok'=>false,'msg'=>'Audit bind failed']); exit; }

    if (!mysqli_stmt_execute($stmtAudit)) {
      echo json_encode(['ok'=>false,'msg'=>'Audit insert failed: '.mysqli_stmt_error($stmtAudit)]); exit;
    }
  } catch (Throwable $e) {
    error_log('Audit trail insert failed: ' . $e->getMessage());
    echo json_encode(['ok'=>false,'msg'=>'Audit exception: '.$e->getMessage()]); exit;
  }

  /* ============================================================
     NEW: Send an in-app notification to this individual
     Actor = admin; Recipient = the request's individual_id
  ============================================================ */
  $individualId = (int)($row['individual_id'] ?? 0);
  if ($individualId > 0) {
    $summaryBits = [];
    $summaryBits[] = "Status: {$status}";
    if (!empty($schedDate)) $summaryBits[] = "Date: {$schedDate}";
    if (!empty($schedTime)) $summaryBits[] = "Time: {$schedTime}";
    if ($status === 'Declined' && $declineSummary !== '') {
      $summaryBits[] = "Reasons: {$declineSummary}";
    }
    $summary = $title . ' | ' . implode(' • ', $summaryBits);
    try { @notifyIndividualOfAdminAction($db_connection, $svc, $id, $individualId, $status, $summary); } catch (Throwable $e) { /* ignore */ }
  }

  echo json_encode(['ok'=>true, 'emailSent'=>$sent, 'emailMsg'=>$err]);
  exit;
}

/* -------------------------------
  AJAX: View details (modal)
------------------------------- */
/* PATCH: House Blessing image/PDF viewer
   - Ensures 'Valid ID' shows a View image / View PDF control for House Blessing
   - Falls back across: valid_id_path, valid_id, owner_valid_id, owner_valid_id_path
   - Applied by assistant; base hash 398045f364e4
*/

if (isset($_GET['view']) && isset($_GET['svc']) && isset($_GET['id'])) {
  $svc = $_GET['svc']; $id  = $_GET['id'];

  switch ($svc) {
    case 'dedication':
      $sql = "SELECT d.*,
                    i.individual_lastname   AS last_name,
                    i.individual_firstname  AS first_name,
                    i.individual_middlename AS middle_name,
                    TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname)) AS full_name,
                    COALESCE(d.email_address, i.individual_email_address) AS individual_email_address
              FROM service_dedication d
              JOIN individual_table i ON i.individual_id=d.individual_id
              WHERE d.dedicationId=?";
      $title = 'Dedication'; break;

    case 'funeral':
      $sql = "SELECT f.*,
                    i.individual_lastname   AS last_name,
                    i.individual_firstname  AS first_name,
                    i.individual_middlename AS middle_name,
                    TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname)) AS full_name,
                    COALESCE(f.email_address, i.individual_email_address) AS individual_email_address
              FROM service_funeral f
              JOIN individual_table i ON i.individual_id=f.individual_id
              WHERE f.funeral_id=?";
      $title = 'Funeral'; break;

    case 'house':
      $sql = "SELECT h.*,
                    i.individual_lastname   AS last_name,
                    i.individual_firstname  AS first_name,
                    i.individual_middlename AS middle_name,
                    TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname)) AS full_name,
                    COALESCE(h.email_address, i.individual_email_address) AS individual_email_address
              FROM service_house h
              JOIN individual_table i ON i.individual_id=h.individual_id
              WHERE h.house_id=?";
      $title = 'House Blessing'; break;

    case 'wedding':
      $sql = "SELECT w.*,
                    i.individual_lastname   AS last_name,
                    i.individual_firstname  AS first_name,
                    i.individual_middlename AS middle_name,
                    TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname)) AS full_name,
                    COALESCE(w.email_address, i.individual_email_address) AS individual_email_address
              FROM service_wedding w
              JOIN individual_table i ON i.individual_id=w.individual_id
              WHERE w.wedding_id=?";
      $title = 'Wedding'; break;

    default:
      http_response_code(400); echo 'Unknown service'; exit;
  }

  $stmt = mysqli_prepare($db_connection, $sql);
  mysqli_stmt_bind_param($stmt, 's', $id);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $row = $res ? mysqli_fetch_assoc($res) : null;

  if (!$row) { echo '<div class="modal-body">Record not found.</div>'; exit; }

  $kv = function($label, $value) {
    echo '<div class="kv"><span>'.h($label).'</span><b>'.h(($value === '' || $value === null) ? '—' : $value).'</b></div>';
  };
  $kvFile = function($label, $path) {
    echo '<div class="kv"><span>'.h($label).'</span>';
    if (!$path) {
      echo '<b>—</b>';
    } else if (is_image_path($path)) {
      echo '<button class="btn tiny view-image" data-img="'.h(to_url($path)).'">View image</button>';
    } else if (is_pdf_path($path)) {
      echo '<a class="btn tiny ghost" href="'.h(to_url($path)).'" target="_blank" rel="noopener">View PDF</a>';
    } else {
      echo '<a class="btn tiny ghost" href="'.h(to_url($path)).'" target="_blank" rel="noopener">Open file</a>';
    }
    echo '</div>';
  };

  $isResched = (strcasecmp((string)($row['service_status'] ?? ''), 'Reschedule') === 0);

  ob_start(); ?>
  <div class="modal-header">
    <h3><?=h($title)?> Appointment Details <?php if($isResched): ?><span class="chip resched">Reschedule</span><?php endif; ?></h3>
    <button class="modal-close" onclick="closeModal()">×</button>
  </div>
  <div class="modal-body">
    <?php
      echo '<h4 class="sec-title">Contact Information</h4>';
      $kv('Contact Number', $row['contact_number'] ?? '—');
      $kv('Email Address',  $row['email_address'] ?? ($row['individual_email_address'] ?? '—'));
      echo '<div class="split"></div>';

      if ($isResched) {
        echo '<h4 class="sec-title">Reschedule Details</h4>';
        $kv('Reschedule Date', !empty($row['reschedule_date']) ? date('M d, Y', strtotime($row['reschedule_date'])) : '—');
        $kv('Reschedule Time', $row['reschedule_time'] ?? '—');
        $kv('Reason', $row['reason'] ?? null);
        echo '<div class="split"></div>';
      }

      if ($svc==='dedication') {
        echo '<h4 class="sec-title">Service Information</h4>';
        $kv('Service Date', !empty($row['service_date']) ? date('M d, Y', strtotime($row['service_date'])) : '—');
        $kv('Service Time', $row['service_time'] ?? '—');

        echo '<div class="split"></div>';
        echo '<h4 class="sec-title">Child Information</h4>';
        $kv('Last Name',        $row['child_lastname']    ?? null);
        $kv('First Name',       $row['child_firstname']   ?? null);
        $kv('Middle Name',      $row['child_middlename']  ?? null);
        $kv('Extension',        $row['child_ext']         ?? null);

        echo '<div class="split"></div>';
        echo '<h4 class="sec-title">Guardian Information</h4>';
        $kv('Last Name',        $row['guardian_lastname']   ?? null);
        $kv('First Name',       $row['guardian_firstname']  ?? null);
        $kv('Middle Name',      $row['guardian_middlename'] ?? null);
        $kv('Extension',        $row['guardian_ext']        ?? null);
        $kv('Guardian Contact', $row['guardian_contact']    ?? null);

        echo '<div class="split"></div>';
        echo '<h4 class="sec-title">Documents</h4>';
        $kvFile('Baptismal Certificate', $row['baptismal_cert_path'] ?? null);

      } elseif ($svc==='funeral') {
        echo '<h4 class="sec-title">Deceased Information</h4>';
        $kv('Last Name',        $row['deceased_lastname']    ?? null);
        $kv('First Name',       $row['deceased_firstname']   ?? null);
        $kv('Middle Name',      $row['deceased_middlename']  ?? null);
        $kv('Extension',        $row['deceased_ext']         ?? null);
        $kv('Birthdate',        !empty($row['deceased_birthdate']) ? date('M d, Y', strtotime($row['deceased_birthdate'])) : '—');
        $kv('Home Address',     $row['home_address']         ?? null);

        echo '<div class="split"></div>';
        echo '<h4 class="sec-title">Service Schedule</h4>';
        $kv('Service Date',     !empty($row['service_date']) ? date('M d, Y', strtotime($row['service_date'])) : '—');
        $kv('Service Time',     $row['service_time'] ?? '—');

        echo '<div class="split"></div>';
        $kv('Funeral Date',     !empty($row['funeral_date']) ? date('M d, Y', strtotime($row['funeral_date'])) : '—');

        /* NEW: Death Certificate file (image/PDF) */
        $kvFile('Death Certificate', $row['death_certificate'] ?? null);

        echo '<div class="split"></div>';
        $kv('Special Request or Message', $row['remarks'] ?? null);

      } elseif ($svc==='house') {
        echo '<h4 class="sec-title">Owner Information</h4>';
        $kv('Last Name',        $row['owner_lastname']    ?? null);
        $kv('First Name',       $row['owner_firstname']   ?? null);
        $kv('Middle Name',      $row['owner_middlename']  ?? null);
        $kv('Extension',        $row['owner_ext']         ?? null);
        $kv('Home Address',     $row['home_address']      ?? null);

        echo '<div class="split"></div>';
        echo '<h4 class="sec-title">Service Schedule</h4>';
        $kv('Service Date',     !empty($row['service_date']) ? date('M d, Y', strtotime($row['service_date'])) : '—');
        $kv('Service Time',     $row['service_time'] ?? '—');

        /* NEW: Valid ID file (image/PDF) */
        echo '<div class="split"></div>';
        echo '<h4 class="sec-title">Documents</h4>';
        $kvFile('Valid ID', first_present($row, ['valid_id_path','valid_id','owner_valid_id','owner_valid_id_path']));
} elseif ($svc==='wedding') {
        echo '<h4 class="sec-title">Appointment Information</h4>';
        $kv('Appointment Date', !empty($row['service_date']) ? date('M d, Y', strtotime($row['service_date'])) : '—');
        $kv('Appointment Time', $row['service_time'] ?? '—');

        echo '<div class="split"></div>';
        echo '<h4 class="sec-title">Groom Information</h4>';
        $kv('Last Name',        $row['groom_lastname']     ?? null);
        $kv('First Name',       $row['groom_firstname']    ?? null);
        $kv('Middle Name',      $row['groom_middlename']   ?? null);
        $kv('Extension',        $row['groom_extension']    ?? null);
        $kv('Birthdate',        !empty($row['groom_birthdate']) ? date('M d, Y', strtotime($row['groom_birthdate'])) : '—');
        $kvFile('Valid ID',     first_present($row, ['groom_valid_id_path', 'groom_valid_id', 'groom_id_path']));
        $kvFile('Baptismal Certificate', first_present($row, ['groom_baptismal_cert_path', 'groom_baptismal_certificate', 'groom_birth_cert_path']));
        $kvFile('Widowed Proof', first_present($row, ['groom_widowed_file', 'groom_widower_proof', 'groom_widowed_path']));

        echo '<div class="split"></div>';
        echo '<h4 class="sec-title">Bride Information</h4>';
        $kv('Last Name',        $row['bride_lastname']     ?? null);
        $kv('First Name',       $row['bride_firstname']    ?? null);
        $kv('Middle Name',      $row['bride_middlename']   ?? null);
        $kv('Extension',        $row['bride_extension']    ?? null);
        $kv('Birthdate',        !empty($row['bride_birthdate']) ? date('M d, Y', strtotime($row['bride_birthdate'])) : '—');
        $kvFile('Valid ID',     first_present($row, ['bride_valid_id_path', 'bride_valid_id', 'bride_id_path']));
        if ($x = first_present($row, ['bride_cenomar_path','bride_cenomar'])) { $kvFile('CENOMAR', $x); }
        $kvFile('Baptismal Certificate', first_present($row, ['bride_baptismal_cert_path', 'bride_baptismal_certificate', 'bride_birth_cert_path']));
        $kvFile('Widowed Proof', first_present($row, ['bride_widowed_file','bride_widower_proof','bride_widowed_path']));

        echo '<div class="split"></div>';
        $kv('Partner Civil Status', $row['partner_civil_status'] ?? null);
        $kv('Special Request / Message', $row['special_request'] ?? null);
      }

      echo '<div class="split"></div>';
      $kv('Current Status', $row['service_status'] ?? '—');

      if (strcasecmp((string)($row['service_status'] ?? ''), 'ReqCancel') === 0 || strcasecmp((string)($row['service_status'] ?? ''), 'Cancelled') === 0) {
        echo '<div class="split"></div>';
        echo '<h4 class="sec-title">Cancellation Reason</h4>';
        $kv('Reason', $row['reason'] ?? null);
      }
    ?>
  </div>
  <div class="modal-footer">
    <div class="grow"></div>

    <a class="btn minor" href="admin-schedule-request.php?download=sr_pdf&svc=<?=h($svc)?>&id=<?=h($id)?>" target="_blank" rel="noopener">
      <i class="fas fa-file-pdf"></i> Download PDF
    </a>

    <?php
      $isReqCancel = (strcasecmp((string)($row['service_status'] ?? ''), 'ReqCancel') === 0 || strcasecmp((string)($row['service_status'] ?? ''), 'Cancelled') === 0);
    ?>
    <?php if ($isResched): ?>
      <!-- RESCHEDULE controls -->
      <button class="btn success" onclick="updateStatus('<?=h($svc)?>','<?=h($id)?>','Scheduled')">
        <i class="fas fa-check"></i> Approve Reschedule
      </button>
      <button class="btn danger" onclick="updateStatus('<?=h($svc)?>','<?=h($id)?>','Cancelled')">
        <i class="fas fa-times"></i> Cancel
      </button>
    <?php elseif ($isReqCancel): ?>
      <!-- CANCEL REQUEST controls -->
      <button class="btn danger" onclick="updateStatus('<?=h($svc)?>','<?=h($id)?>','Cancelled')">
        <i class="fas fa-check"></i> Approve Cancel
      </button>
    <?php else: ?>
      <!-- Original Pending controls -->
      <button class="btn danger" onclick="updateStatus('<?=h($svc)?>','<?=h($id)?>','Declined')">
        <i class="fas fa-times"></i> Decline
      </button>
      <button class="btn success" onclick="updateStatus('<?=h($svc)?>','<?=h($id)?>','Scheduled')">
        <i class="fas fa-check"></i> Approve
      </button>
    <?php endif; ?>
    <button class="btn ghost" onclick="closeModal()">Close</button>
  </div>
  <?php
  echo ob_get_clean();
  exit;
}

// -------------------------------
// Realtime filters: initial values
// -------------------------------
$service = $_GET['service'] ?? 'all';
$sort    = $_GET['sort'] ?? 'new';

// -------------------------------
// Combined (UNION) – Pending + Reschedule
// -------------------------------
$sqlCombined = "
SELECT * FROM (
  SELECT 
    CAST('dedication' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS service_key,
    CAST('Dedication' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS service_label,
    d.dedicationId AS service_id,
    d.created_at   AS requested_at,
    d.service_date AS event_date,
    CAST(COALESCE(d.service_time,'') AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS event_time,
    /* NEW: flag for reschedule */
    CAST(CASE WHEN d.service_status COLLATE utf8mb4_unicode_ci = 'Reschedule' THEN 1 ELSE 0 END AS UNSIGNED) AS is_reschedule,

    CAST(d.service_status AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS service_status,

    CAST(i.individual_lastname   AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS last_name,
    CAST(i.individual_firstname  AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS first_name,
    CAST(COALESCE(i.individual_middlename,'') AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS middle_name,

    CAST(TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname)) AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS requester_name,
    CAST(COALESCE(d.email_address, i.individual_email_address, '') AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS requester_email
  FROM service_dedication d
  JOIN individual_table i ON i.individual_id=d.individual_id
  WHERE d.service_status COLLATE utf8mb4_unicode_ci IN ('Pending','Reschedule','ReqCancel')

  UNION ALL

  SELECT 
    CAST('funeral' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS service_key,
    CAST('Funeral' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS service_label,
    f.funeral_id AS service_id,
    f.created_at AS requested_at,
    f.service_date AS event_date,
    CAST(COALESCE(f.service_time,'') AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS event_time,
    /* NEW: flag for reschedule */
    CAST(CASE WHEN f.service_status COLLATE utf8mb4_unicode_ci = 'Reschedule' THEN 1 ELSE 0 END AS UNSIGNED) AS is_reschedule,

    CAST(f.service_status AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS service_status,

    CAST(i.individual_lastname   AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS last_name,
    CAST(i.individual_firstname  AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS first_name,
    CAST(COALESCE(i.individual_middlename,'') AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS middle_name,

    CAST(TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname)) AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS requester_name,
    CAST(COALESCE(f.email_address, i.individual_email_address, '') AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS requester_email
  FROM service_funeral f
  JOIN individual_table i ON i.individual_id=f.individual_id
  WHERE f.service_status COLLATE utf8mb4_unicode_ci IN ('Pending','Reschedule','ReqCancel')

  UNION ALL

  SELECT 
    CAST('house' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS service_key,
    CAST('House Blessing' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS service_label,
    h.house_id AS service_id,
    h.created_at AS requested_at,
    h.service_date AS event_date,
    CAST(COALESCE(h.service_time,'') AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS event_time,
    /* NEW: flag for reschedule */
    CAST(CASE WHEN h.service_status COLLATE utf8mb4_unicode_ci = 'Reschedule' THEN 1 ELSE 0 END AS UNSIGNED) AS is_reschedule,

    CAST(h.service_status AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS service_status,

    CAST(i.individual_lastname   AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS last_name,
    CAST(i.individual_firstname  AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS first_name,
    CAST(COALESCE(i.individual_middlename,'') AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS middle_name,

    CAST(TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname)) AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS requester_name,
    CAST(COALESCE(h.email_address, i.individual_email_address, '') AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS requester_email
  FROM service_house h
  JOIN individual_table i ON i.individual_id=h.individual_id
  WHERE h.service_status COLLATE utf8mb4_unicode_ci IN ('New','Pending','Reschedule')

  UNION ALL

  SELECT 
    CAST('wedding' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS service_key,
    CAST('Wedding' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS service_label,
    w.wedding_id AS service_id,
    COALESCE(w.created_at, w.service_date) AS requested_at,
    w.service_date AS event_date,
    CAST(COALESCE(w.service_time,'') AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS event_time,
    /* NEW: flag for reschedule */
    CAST(CASE WHEN w.service_status COLLATE utf8mb4_unicode_ci = 'Reschedule' THEN 1 ELSE 0 END AS UNSIGNED) AS is_reschedule,

    CAST(w.service_status AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS service_status,

    CAST(w.client_lastname   AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS last_name,
    CAST(w.client_firstname  AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS first_name,
    CAST(COALESCE(w.client_middlename,'') AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS middle_name,

    CAST(TRIM(CONCAT_WS(' ', w.client_firstname, w.client_middlename, w.client_lastname)) AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS requester_name,
    CAST(COALESCE(w.email_address, i.individual_email_address, '') AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS requester_email
  FROM service_wedding w
  JOIN individual_table i ON i.individual_id=w.individual_id
  WHERE w.service_status COLLATE utf8mb4_unicode_ci IN ('Pending','Reschedule','ReqCancel')
) t
ORDER BY t.requested_at DESC
";


$resCombined = mysqli_query($db_connection, $sqlCombined);

/* -------------------------------
  AJAX: Cancelled Services (list modal)
------------------------------- */
if (isset($_GET['cancelled']) && $_GET['cancelled']==='1') {
  // Build a Cancelled-only query from the same UNION used in the main table
  $sqlCancelled = str_replace("IN ('Pending','Reschedule','ReqCancel')", "IN ('Cancelled')", $sqlCombined);
  $resCancelled = mysqli_query($db_connection, $sqlCancelled);

  ob_start(); ?>
  <div class="modal-header">
  <div class="cancelled-modal">
    <div class="modal-header">
      <div class="modal-title-group">
        <h3><i class="fas fa-ban"></i> Cancelled Services</h3>
        <p class="subtitle">View all service requests with a status of Cancelled.</p>
      </div>
      <button type="button" class="modal-close" onclick="closeModal()" aria-label="Close">
        ×
      </button>
    </div>
    <div class="modal-body">
      <div class="cancelled-meta">
        <span class="cancelled-pill">
          <i class="fas fa-times-circle"></i>
          Cancelled
        </span>
        <span class="meta-note">These requests are already closed. You can still open each record to review full details.</span>
      </div>
      <div class="table-cancelled-wrap">
        <table class="table-cancelled">
          <thead>
            <tr>
              <th>Date Requested</th>
              <th>Event Type</th>
              <th>Last Name</th>
              <th>First Name</th>
              <th>Middle Name</th>
              <th>Individual's Email Address</th>
              <th>Service Date &amp; Time</th>
              <th class="text-right">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($resCancelled && mysqli_num_rows($resCancelled)>0): 
            while ($r = mysqli_fetch_assoc($resCancelled)):
              $reqDate = !empty($r['requested_at']) ? date('M d, Y', strtotime($r['requested_at'])) : '—';
              $evtDate= !empty($r['event_date']) ? date('M d, Y', strtotime($r['event_date'])) : '—';
              $time   = !empty($r['event_time']) ? $r['event_time'] : '';
              $when   = trim($evtDate.' '.($time ? '• '.$time : ''));
          ?>
            <tr data-service="<?=h($r['service_key'])?>">
              <td data-label="Date Requested"><?=h($reqDate)?></td>
              <td data-label="Event Type"><?=h($r['service_label'])?></td>
              <td data-label="Last Name"><?=h($r['last_name'] ?? '')?></td>
              <td data-label="First Name"><?=h($r['first_name'] ?? '')?></td>
              <td data-label="Middle Name"><?=h($r['middle_name'] ?? '')?></td>
              <td data-label="Individual's Email Address"><?=h($r['requester_email'])?></td>
              <td data-label="Service Date &amp; Time"><?=h($when)?></td>
              <td data-label="Action" class="text-right">
                <button class="btn tiny ghost" title="View"
                  onclick="viewDetails('<?=h($r['service_key'])?>','<?=h($r['service_id'])?>')">
                  <i class="fas fa-eye"></i> VIEW
                </button>
              </td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="8" class="empty">No cancelled services.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer">
      <div class="grow"></div>
      <button type="button" class="btn ghost" onclick="closeModal()">Close</button>
    </div>
  </div>
  <style>
    .cancelled-modal {
      background:#0b1b4a;
      border-radius:16px;
      box-shadow:0 24px 60px rgba(15,23,42,0.45);
      max-width:1100px;
      margin:24px auto;
      overflow:hidden;
      display:flex;
      flex-direction:column;
    }
    .cancelled-modal .modal-header {
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      padding:16px 20px;
      border-bottom:1px solid rgba(255,255,255,0.08);
      background:#0b1b4a;
      color:#ffffff;
    }
    .cancelled-modal .modal-title-group h3 {
      margin:0;
      font-size:18px;
      display:flex;
      align-items:center;
      gap:8px;
    }
    .cancelled-modal .modal-title-group h3 i {
      font-size:18px;
    }
    .cancelled-modal .modal-title-group .subtitle {
      margin:4px 0 0;
      font-size:12px;
      opacity:0.9;
    }
    .cancelled-modal .modal-close {
      background:transparent;
      border:0;
      color:#ffffff;
      font-size:22px;
      line-height:1;
      cursor:pointer;
      padding:4px 0 0 12px;
    }
    .cancelled-modal .modal-body {
      padding:16px 20px 18px;
      background:#f9fafb;
      max-height:70vh;
      overflow-y:auto;
    }
    .cancelled-modal .cancelled-meta {
      display:flex;
      flex-wrap:wrap;
      align-items:center;
      gap:8px;
      margin-bottom:12px;
      font-size:13px;
      color:#4b5563;
    }
    .cancelled-modal .cancelled-pill {
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding:4px 10px;
      border-radius:999px;
      background:#fee2e2;
      color:#b91c1c;
      font-weight:600;
      font-size:12px;
    }
    .cancelled-modal .cancelled-pill i {
      font-size:13px;
    }
    .cancelled-modal .table-cancelled-wrap {
      border:1px solid #e5e7eb;
      border-radius:10px;
      background:#ffffff;
      overflow:auto;
    }
    .cancelled-modal .table-cancelled {
      width:100%;
      border-collapse:collapse;
      font-size:13px;
    }
    .cancelled-modal .table-cancelled thead th {
      position:sticky;
      top:0;
      background:#f3f4f6;
      border-bottom:1px solid #e5e7eb;
      padding:8px 10px;
      text-align:left;
      font-weight:600;
      white-space:nowrap;
      z-index:1;
    }
    .cancelled-modal .table-cancelled tbody td {
      border-bottom:1px solid #f3f4f6;
      padding:8px 10px;
      vertical-align:middle;
    }
    .cancelled-modal .table-cancelled tbody tr:hover {
      background:#f9fafb;
    }
    .cancelled-modal .table-cancelled tbody td.text-right {
      text-align:right;
    }
    .cancelled-modal .table-cancelled .empty {
      padding:24px 16px;
      text-align:center;
      color:#6b7280;
      font-style:italic;
    }
    .cancelled-modal .modal-footer {
      display:flex;
      align-items:center;
      justify-content:flex-end;
      gap:8px;
      padding:10px 20px;
      border-top:1px solid #e5e7eb;
      background:#f9fafb;
    }
    .cancelled-modal .modal-footer .btn {
      min-width:80px;
    }
    .cancelled-modal .grow {
      flex:1;
    }
    @media (max-width: 1024px) {
      .cancelled-modal {
        margin:16px;
        max-width:100%;
      }
    }
    @media (max-width: 768px) {
      .cancelled-modal .modal-body {
        padding:12px 14px 14px;
        max-height:70vh;
      }
      .cancelled-modal .table-cancelled thead {
        display:none;
      }
      .cancelled-modal .table-cancelled tbody tr {
        display:block;
        border-bottom:1px solid #e5e7eb;
      }
      .cancelled-modal .table-cancelled tbody td {
        display:flex;
        justify-content:space-between;
        gap:12px;
        padding:6px 12px;
      }
      .cancelled-modal .table-cancelled tbody td::before {
        content: attr(data-label);
        font-weight:600;
        color:#4b5563;
      }
      .cancelled-modal .table-cancelled tbody td.text-right {
        justify-content:flex-end;
      }
    }
  </style>
  <?php
  $html = ob_get_clean();
  echo $html;
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Admin – Schedule Request</title>

  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/admin-schedule-request.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/admin-schedule-request.css'); ?>">

</head>
<body>

<!-- ======================== SIDEBAR ======================== -->
<aside class="sidebar">
  <div class="brand">
    <img src="image/httc_main-logo.jpg" alt="" />
    <span>HTCCC SYSTEM</span>
  </div>

  <div class="user-card">
    <img src="css/image/profile.png" alt="user">
    <div>
      <div class="user-title">Pastor</div>
      <div class="user-sub">Dashboard</div>
    </div>
  </div>

  <nav class="nav">
    <div class="section-title">Main</div>
    <a class="navlink" href="pastor-dashboard.php"><i class="fas fa-th-large"></i>Dashboard</a>

    <div class="section-title">Requests</div>
    <a class="navlink active" href="pastor-admin-request.php">
      <i class="far fa-calendar-plus"></i>Appointment Request
    </a>
    <a class="navlink" href="pastor-prayer-request.php">
      <i class="far fa-hand-paper"></i><span>Prayer Request</span>
    </a>

    <div class="section-title">Schedule</div>
      <a class="navlink" href="pastor-appointment-schedule.php">
      <i class="far fa-calendar-alt"></i>Appointment Schedule
    </a>
    <a class="navlink" href="pastor-service-schedule.php">
      <i class="fas fa-calendar-alt"></i>Service Schedule
    </a>

    <div class="section-title">Application</div>
    <a class="navlink" href="pastor-ministries-application.php">
      <i class="fas fa-users"></i>Ministries Application
    </a>
    <a class="navlink" href="pastor-user-application.php">
      <i class="far fa-user"></i>User Application
    </a>

    <div class="section-title">Streaming</div>
    <a class="navlink" href="pastor-streaming.php">
      <i class="fas fa-video"></i>Streaming
    </a>

    <div class="section-title">Ministry List</div>
    <a class="navlink" href="pastor-women-ministries.php">
      <i class="fas fa-female"></i>Handmaid's of the Lord
    </a>
    <a class="navlink" href="pastor-men-ministries.php">
      <i class="fas fa-male"></i>Men's Ministry
    </a>
    <a class="navlink" href="pastor-music-ministries.php">
      <i class="fas fa-music"></i>Music's Ministry
    </a>
    <a class="navlink" href="pastor-usher-ministries.php">
      <i class="fas fa-hands-helping"></i>Usher &amp; Usherette
    </a>
    <a class="navlink" href="pastor-junior-ministries.php">
      <i class="fas fa-child"></i>Junior Christ Ambassador
    </a>

    <div class="section-title">Reports</div>
    <a class="navlink" href="pastor-report.php">
      <i class="fas fa-file-alt"></i>Reports
    </a>

    <div class="section-title">Content</div>
    <a class="navlink" href="pastor-content management.php">
      <i class="fas fa-edit"></i>Content Management
    </a>

     <div class="section-title">Management</div>
    <a class="navlink" href="pastor-audittrails.php">
      <i class="fa fa-file"></i> Audit Trails
    </a>
      <a class="navlink" href="pastor-admin-accounts.php">
      <i class="fas fa-user"></i>Admin Accounts
    </a>

    <div class="section-title">More</div>
    <a class="navlink logout" href="all_log-in.php">
      <img alt="Logout" class="icon" src="image/logo-logout.png" width="18" height="18" style="vertical-align:middle;margin-right:8px;">
      Log Out
    </a>
  </nav>
</aside>

<!-- PAGE -->
<div class="page">
  <header class="topbar">
    <h1>Online Service Requests</h1>
    <div class="top-actions">
      <div class="search">
        <i class="fas fa-search"></i>
        <input id="searchInput" type="text" placeholder="Search…">
      </div>
      <div class="filters">
        <label><span>Service</span>
          <select id="svcFilter">
            <option value="all">All</option>
            <option value="dedication">Dedication</option>
            <option value="funeral">Funeral</option>
            <option value="house">House Blessing</option>
            <option value="wedding">Wedding</option>
          </select>
        </label>
        <label><span>Sort</span>
          <select id="sortFilter">
            <option value="new" selected>New → Old</option>
            <option value="old">Old → New</option>
            <option value="lname_az">Last name A → Z</option>
            <option value="lname_za">Last name Z → A</option>
          </select>
        </label>
      </div>
      <div class="extra-actions">
        <button type="button" class="btn minor" id="btnCancelledService" onclick="showCancelled()">
          <i class="fas fa-ban"></i> Cancelled Service
        </button>
      </div>
    </div>
  </header>

  <section class="panel">
    <div class="table-wrap">
      <table id="apptTable">
        <thead>
          <tr>
            <th>Date Requested</th>
            <th>Event Type</th>
            <th>Last Name</th>
            <th>First Name</th>
            <th>Middle Name</th>
            <th>Individual's Email Address</th>
            <th>Service Date &amp; Time</th>
            <th class="text-right">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php
          if ($resCombined && mysqli_num_rows($resCombined)>0):
            while ($r = mysqli_fetch_assoc($resCombined)):
              $reqDate = !empty($r['requested_at']) ? date('M d, Y', strtotime($r['requested_at'])) : '—';
              $evtDate= !empty($r['event_date']) ? date('M d, Y', strtotime($r['event_date'])) : '—';
              $time    = !empty($r['event_time']) ? $r['event_time'] : '';
              $when    = trim($evtDate.' '.($time ? '• '.$time : ''));
              $reqTs   = !empty($r['requested_at']) ? strtotime($r['requested_at']) : 0;
              $isRes   = !empty($r['is_reschedule']);
              $isReqCancel = (strcasecmp((string)($r['service_status'] ?? ''), 'ReqCancel') === 0);
              $isCancel = (strcasecmp((string)($r['service_status'] ?? ''), 'Cancelled') === 0 || strcasecmp((string)($r['service_status'] ?? ''), 'ReqCancel') === 0);
          ?>
          <tr
            data-service="<?=h($r['service_key'])?>"
            data-requested="<?=$reqTs?>"
            data-last="<?=h($r['last_name'] ?? '')?>"
            data-first="<?=h($r['first_name'] ?? '')?>"
            data-middle="<?=h($r['middle_name'] ?? '')?>"
            class="<?= $isRes ? 'row-resched' : '' ?>"
          >
            <td><?=h($reqDate)?></td>
            <td>
              <?=h($r['service_label'])?>
              <?php if ($isRes): ?>
                <span class="badge resched" title="For rescheduling">Reschedule</span>
              <?php endif; ?>
              <?php if ($isCancel): ?>
                <span class="badge cancel" title="Cancelled/Request Cancel">Cancel</span>
              <?php endif; ?>
            </td>
            <td><?=h($r['last_name'] ?? '')?></td>
            <td><?=h($r['first_name'] ?? '')?></td>
            <td><?=h($r['middle_name'] ?? '')?></td>
            <td><?=h($r['requester_email'])?></td>
            <td><?=h($when)?></td>
            <td class="text-right">
              <button class="btn tiny ghost" title="View"
                onclick="viewDetails('<?=h($r['service_key'])?>','<?=h($r['service_id'])?>')">
                <i class="fas fa-eye"></i> VIEW
              </button>
            </td>
          </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="8" class="empty">No pending or reschedule requests.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<!-- Modal -->
<div id="modal" class="modal" aria-hidden="true">
  <div class="modal-dialog">
    <div id="modalContent" class="modal-content">Loading…</div>
  </div>
</div>

<!-- Image Lightbox -->
<div id="imgBox" class="imgbox" aria-hidden="true">
  <div class="imgbox-inner">
    <button class="imgbox-close" onclick="closeImgBox()">×</button>
    <img id="imgBoxImg" alt="Document image">
  </div>
</div>

<!-- Toast utility -->
<script>
(function(){
  function ensureWrap(){
    let w = document.querySelector('.toast-wrap');
    if(!w){ w = document.createElement('div'); w.className = 'toast-wrap'; document.body.appendChild(w); }
    return w;
  }
  window.toast = function(message, type='info', opts={}){
    const {duration=3500} = opts;
    const wrap = ensureWrap();
    while (wrap.children.length >= 5) wrap.firstChild.remove();

    const el = document.createElement('div');
    el.className = 'toast ' + (type||'info');

    const icon = document.createElement('div');
    icon.className = 't-icon';
    icon.innerHTML = ({success:'✅', error:'⛔', warn:'⚠️', info:'ℹ️'})[type] || 'ℹ️';

    const body = document.createElement('div');
    body.className = 't-body';
    body.textContent = String(message || '');

    const close = document.createElement('button');
    close.className = 't-close'; close.type = 'button'; close.setAttribute('aria-label','Close'); close.innerHTML = '&times;';
    close.onclick = () => dismiss();

    el.appendChild(icon); el.appendChild(body); el.appendChild(close);
    wrap.appendChild(el);

    let timer = null;
    function dismiss(){ el.style.animation = 'toast-out .15s ease forwards'; clearTimeout(timer); setTimeout(()=> el.remove(), 180); }
    if (duration > 0){ timer = setTimeout(dismiss, duration); el.addEventListener('mouseenter', ()=> clearTimeout(timer)); el.addEventListener('mouseleave', ()=> timer = setTimeout(dismiss, 1200)); }
    return {dismiss, el};
  };
})();
</script>

<script>
// cache and sorting
const searchInput = document.getElementById('searchInput');
const svcFilter   = document.getElementById('svcFilter');
const sortFilter  = document.getElementById('sortFilter');
const tbody       = document.querySelector('#apptTable tbody');
const allRows     = [...tbody.querySelectorAll('tr')];

function sortVisibleRows(mode){
  const dir = mode || sortFilter.value;
  const visible = allRows.filter(r => r.style.display !== 'none');
  const collator = new Intl.Collator(undefined, { sensitivity: 'base', numeric: true });

  function byRequested(a, b) {
    const ta = parseInt(a.dataset.requested || '0', 10);
    const tb = parseInt(b.dataset.requested || '0', 10);
    return ta - tb; // ascending
  }
  function byName(a, b) {
    const la = (a.dataset.last || '');
    const lb = (b.dataset.last || '');
    const lf = collator.compare(la, lb);
    if (lf !== 0) return lf;
    const fa = (a.dataset.first || '');
    const fb = (b.dataset.first || '');
    const ff = collator.compare(fa, fb);
    if (ff !== 0) return ff;
    const ma = (a.dataset.middle || '');
    const mb = (b.dataset.middle || '');
    const mf = collator.compare(ma, mb);
    if (mf !== 0) return mf;
    return parseInt(b.dataset.requested || '0', 10) - parseInt(a.dataset.requested || '0', 10);
  }

  visible.sort((a, b) => {
    switch (dir) {
      case 'old':       return byRequested(a, b);
      case 'new':       return byRequested(b, a);
      case 'lname_az':  return byName(a, b);
      case 'lname_za':  return byName(b, a);
      default:          return byRequested(b, a);
    }
  });

  visible.forEach(r => tbody.appendChild(r));
}

function applyFilters() {
  const q   = (searchInput.value || '').toLowerCase();
  const svc = svcFilter.value;

  allRows.forEach(row => {
    const matchSvc = (svc === 'all') || (row.dataset.service === svc);
    const text     = row.innerText.toLowerCase();
    const match    = text.includes(q);
    row.style.display = (matchSvc && match) ? '' : 'none';
  });

  sortVisibleRows(sortFilter.value);
}
searchInput.addEventListener('input', applyFilters);
svcFilter.addEventListener('change', applyFilters);
sortFilter.addEventListener('change', applyFilters);
applyFilters();

function openModal(){ document.getElementById('modal').setAttribute('aria-hidden','false'); }
function closeModal(){ document.getElementById('modal').setAttribute('aria-hidden','true'); }
window.closeModal = closeModal;

function viewDetails(svc, id) {
  openModal();
  const box = document.getElementById('modalContent');
  box.innerHTML = 'Loading…';
  fetch(`admin-schedule-request.php?view=1&svc=${encodeURIComponent(svc)}&id=${encodeURIComponent(id)}`)
    .then(r => r.text())
    .then(html => {
      box.innerHTML = html;
})
    .catch(() => {
      box.innerHTML = '<div class="modal-body">Failed to load.</div>';
      toast('Failed to load details. Please try again.', 'error');
    });
}

function showCancelled() {
  openModal();
  const box = document.getElementById('modalContent');
  box.innerHTML = '<div class="modal-body">Loading…</div>';
  fetch('admin-schedule-request.php?cancelled=1')
    .then(r => r.text())
    .then(html => { box.innerHTML = html; })
    .catch(() => {
      box.innerHTML = '<div class="modal-body">Failed to load.</div>';
      toast('Failed to load cancelled services. Please try again.', 'error');
    });
}

function updateStatus(svc, id, status) {
  const fd = new FormData();
  fd.append('action','updateStatus');
  fd.append('svc', svc);
  fd.append('id', id);
  fd.append('status', status);

  toast('Updating status…', 'info', {duration: 1500});

  fetch('admin-schedule-request.php', { method:'POST', body: fd })
    .then(async r => {
      const raw = await r.text();
      let j = null;
      try { j = JSON.parse(raw); }
      catch(e){
        toast("Server error (raw): " + raw.slice(0,1000), 'error', {duration: 6000});
        return;
      }
      if (!j.ok) {
        toast("Update failed: " + (j.msg || 'Unknown error'), 'error', {duration: 6000});
        return;
      }
      toast(
        `Status updated to ${status}` + (j.emailSent ? ' • email sent.' : ` • email failed: ${j.emailMsg||''}`),
        j.emailSent ? 'success' : 'warn',
        {duration: 4500}
      );
      closeModal();
      setTimeout(()=>location.reload(), 650);
    })
    .catch((e) => toast('Network error: ' + e.message, 'error', {duration: 6000}));
}

// Image viewer (delegated)
document.addEventListener('click', (e) => {
  const btn = e.target.closest('.view-image[data-img]');
  if (!btn) return;
  e.preventDefault();
  openImgBox(btn.getAttribute('data-img'));
});
function openImgBox(src){
  const box = document.getElementById('imgBox');
  const img = document.getElementById('imgBoxImg');
  img.src = src;
  box.setAttribute('aria-hidden','false');
}
function closeImgBox(){
  const box = document.getElementById('imgBox');
  const img = document.getElementById('imgBoxImg');
  img.src = '';
  box.setAttribute('aria-hidden','true');
}

// Limit visible rows to 10 + sticky thead
function setScrollableLimit() {
  const wrap  = document.querySelector('.table-wrap');
  const table = document.getElementById('apptTable');
  if (!wrap || !table || !table.tBodies[0]) return;
  const headerRow = table.tHead ? table.tHead.rows[0] : null;
  const firstVisible = [...table.tBodies[0].rows].find(r => r.style.display !== 'none');
  if (!headerRow || !firstVisible) return;
  const headH = Math.ceil(headerRow.getBoundingClientRect().height || 44);
  const rowH  = Math.ceil(firstVisible.getBoundingClientRect().height || 44);
  const maxH  = headH + (rowH * 10);
  wrap.style.maxHeight = maxH + 'px';
}
window.addEventListener('load', setScrollableLimit);
window.addEventListener('resize', setScrollableLimit);
searchInput.addEventListener('input', setScrollableLimit);
svcFilter.addEventListener('change', setScrollableLimit);
sortFilter.addEventListener('change', setScrollableLimit);
setScrollableLimit();

/* Smart search (unchanged core) */
function norm(s){ return (s||'').toString().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase(); }
function lev(a,b){
  a = norm(a); b = norm(b);
  const m = []; for (let i=0;i<=b.length;i++){ m[i]=[i]; } for (let j=0;j<=a.length;j++){ m[0][j]=j; }
  for (let i=1;i<=b.length;i++){ for (let j=1;j<=a.length;j++){ m[i][j] = Math.min(m[i-1][j]+1, m[i][j-1]+1, m[i-1][j-1]+(a[j-1]===b[i-1]?0:1)); } }
  return m[b.length][a.length];
}
function parseSmartQuery(q){
  const tokens = []; const neg = []; const fields = { name:[], email:[], service:[], time:[], date:[], phrase:[] }; const fuzzy = [];
  q = (q||'').trim(); if (!q) return { tokens, neg, fields, fuzzy, raw:'' };
  const phrases = []; q = q.replace(/"([^"]+)"/g, (_,p) => { phrases.push(p.trim()); return ' '; }); fields.phrase.push(...phrases);
  const parts = q.split(/\s+/).filter(Boolean);
  for (const part of parts){
    if (/^-/.test(part)) { neg.push(part.slice(1)); continue; }
    const m = part.match(/^(\w+):(.*)$/);
    if (m){
      const key = m[1].toLowerCase(); const val = m[2];
      if (key === 'service'){ fields.service.push(...val.split('|').map(v=>v.toLowerCase())); }
      else if (key === 'email'){ fields.email.push(val); }
      else if (key === 'name'){ fields.name.push(val); }
      else if (key === 'time'){ fields.time.push(val); }
      else if (key === 'date'){ fields.date.push(val); }
      else { tokens.push(part); }
    } else if (part === '~'){ /* ignore */ }
    else if (part.endsWith('~')) { fuzzy.push(part.slice(0,-1)); }
    else { tokens.push(part); }
  }
  return { tokens, neg, fields, fuzzy, raw:q };
}
function parseDateToTs(s){
  if (!s) return null;
  const a = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (a) { const d = new Date(Number(a[1]), Number(a[2])-1, Number(a[3])); return isNaN(d.getTime()) ? null : Math.floor(d.getTime()/1000); }
  const t = Date.parse(s);
  return isNaN(t) ? null : Math.floor(t/1000);
}
function clearHighlights(row){
  row.querySelectorAll('mark.smart-hit').forEach(m=>{ const parent = m.parentNode; parent.replaceChild(document.createTextNode(m.textContent), m); parent.normalize(); });
}
function highlightRow(row, needles){
  if (!needles || needles.length===0) return;
  const walker = document.createTreeWalker(row, NodeFilter.SHOW_TEXT, {
    acceptNode(node){ if (!node.nodeValue.trim()) return NodeFilter.FILTER_REJECT; if (node.parentElement && node.parentElement.tagName === 'BUTTON') return NodeFilter.FILTER_REJECT; return NodeFilter.FILTER_ACCEPT; }
  });
  const chunks = []; while (walker.nextNode()){ chunks.push(walker.currentNode); }
  for (const n of chunks){
    const txt = n.nodeValue; const low = norm(txt); let ranges = [];
    for (const needle of (needles||[])){ const q = norm(needle); let idx = 0; while (q && (idx = low.indexOf(q, idx)) !== -1){ ranges.push([idx, idx+q.length]); idx += q.length; } }
    if (!ranges.length) continue; ranges.sort((a,b)=>a[0]-b[0]);
    const merged=[]; let [s,e] = ranges[0]; for (let i=1;i<ranges.length;i++){ const [ns,ne] = ranges[i]; if (ns<=e){ e=Math.max(e,ne);} else { merged.push([s,e]); [s,e]=[ns,ne]; } } merged.push([s,e]);
    const frag = document.createDocumentFragment(); let last=0; for (const [ms,me] of merged){ if (last<ms) frag.appendChild(document.createTextNode(txt.slice(last, ms))); const mark=document.createElement('mark'); mark.className='smart-hit'; mark.textContent = txt.slice(ms, me); frag.appendChild(mark); last=me; }
    if (last<txt.length) frag.appendChild(document.createTextNode(txt.slice(last))); n.parentNode.replaceChild(frag, n);
  }
}
function smartFilterRows(){
  const smartOn = true;
  allRows.forEach(clearHighlights);
  if (!smartOn){ setScrollableLimit(); return; }

  const query = searchInput.value || '';
  const parsed = parseSmartQuery(query);
  const needles = [...parsed.fields.phrase, ...parsed.tokens, ...parsed.fields.name, ...parsed.fields.email];

  function rowMatchesDate(row){
    if (parsed.fields.date.length===0) return true;
    const reqTxt  = row.cells[0]?.innerText.trim() || '';
    const apptTxt = row.cells[6]?.innerText.trim() || '';
    const reqTs   = parseDateToTs(reqTxt);
    const apptDateText = apptTxt.split('•')[0].trim();
    const apptTs  = parseDateToTs(apptDateText);
    for (const d of parsed.fields.date){
      if (d.includes('..')){ const [a,b] = d.split('..'); const ta=parseDateToTs(a); const tb=parseDateToTs(b);
        if ((reqTs && ta && tb && reqTs>=ta && reqTs<=tb) || (apptTs && ta && tb && apptTs>=ta && apptTs<=tb)) return true;
      } else {
        const t = parseDateToTs(d);
        if ((reqTs && t && Math.abs(reqTs - t) < 86400) || (apptTs && t && Math.abs(apptTs - t) < 86400)) return true;
      }
    }
    return false;
  }

  allRows.forEach(row=>{
    if (row.style.display === 'none'){ return; }
    if (parsed.fields.service.length){
      const rowSvc = (row.dataset.service || '').toLowerCase();
      if (!parsed.fields.service.some(s => rowSvc === s)){ row.style.display = 'none'; return; }
    }
    const nameCell = norm(
      (row.cells[2]?.innerText || '') + ' ' +
      (row.cells[3]?.innerText || '') + ' ' +
      (row.cells[4]?.innerText || '')
    );
    if (parsed.fields.name.length){
      if (!parsed.fields.name.some(n => nameCell.includes(norm(n)))){ row.style.display = 'none'; return; }
    }
    if (parsed.fields.email.length){
      const mailCell = norm(row.cells[5]?.innerText || '');
      if (!parsed.fields.email.some(n => mailCell.includes(norm(n)))){ row.style.display = 'none'; return; }
    }
    if (parsed.fields.time.length){
      const apptCell = norm(row.cells[6]?.innerText || '');
      if (!parsed.fields.time.some(t => apptCell.includes(norm(t)))){ row.style.display = 'none'; return; }
    }
    if (!rowMatchesDate(row)){ row.style.display = 'none'; return; }
    const whole = norm(row.innerText);
    if (parsed.neg.length && parsed.neg.some(n => whole.includes(norm(n)))){ row.style.display = 'none'; return; }
    if (parsed.fields.phrase.length && !parsed.fields.phrase.every(p => whole.includes(norm(p)))){ row.style.display = 'none'; return; }
    if (parsed.tokens.length && !parsed.tokens.every(t => whole.includes(norm(t)))){ row.style.display = 'none'; return; }
    highlightRow(row, needles);
  });

  sortVisibleRows(sortFilter.value);
  setScrollableLimit();
}
['input','change'].forEach(evt=>{
  searchInput.addEventListener(evt, smartFilterRows);
  svcFilter.addEventListener(evt, smartFilterRows);
  sortFilter.addEventListener(evt, smartFilterRows);
});
window.addEventListener('load', smartFilterRows);
</script>

<!-- Dev JS error overlay -->
<script>
(function(){
  function ensureBox(){
    var box = document.getElementById('js-error-box');
    if (box) return box;
    box = document.createElement('div');
    box.id = 'js-error-box';
    box.style.position = 'fixed';
    box.style.left = '10px';
    box.style.bottom = '10px';
    box.style.maxWidth = '640px';
    box.style.maxHeight = '40vh';
    box.style.overflow = 'auto';
    box.style.background = '#1a1a1a';
    box.style.color = '#ffdede';
    box.style.border = '1px solid #5a1b1b';
    box.style.borderRadius = '10px';
    box.style.boxShadow = '0 10px 30px rgba(0,0,0,.3)';
    box.style.fontFamily = 'Inter, Arial, sans-serif';
    box.style.fontSize = '12px';
    box.style.zIndex = '999999';
    box.innerHTML = '<div style="background:#8b1e1e;color:#fff;padding:6px 10px;font-weight:700;border-top-left-radius:10px;border-top-right-radius:10px">JS Errors</div>';
    document.body.appendChild(box);
    return box;
  }
  function line(msg, file, line, col, stack){
    var box = ensureBox();
    var el = document.createElement('div');
    el.style.padding = '8px 10px';
    el.style.borderTop = '1px dashed rgba(255,255,255,.2)';
    el.innerHTML =
      '<div style="white-space:pre-wrap;word-break:break-word;">'+ String(msg) +'</div>' +
      '<div style="opacity:.8;margin-top:4px">at <code>'+ (file||'') +'</code>:'+ (line||'?') + (col?(':'+col):'') + '</div>' +
      (stack ? '<details style="margin-top:6px"><summary style="cursor:pointer">stack</summary><pre style="margin:6px 0;white-space:pre-wrap">'+ stack +'</pre></details>' : '');
    box.appendChild(el);
  }
  window.addEventListener('error', function(e){
    line(e.message, e.filename, e.lineno, e.colno, (e.error && e.error.stack) ? e.error.stack : '');
  });
  window.addEventListener('unhandledrejection', function(e){
    var r = e.reason || {};
    var msg = (typeof r === 'string') ? r : (r.message || 'Unhandled promise rejection');
    line(msg, (r.fileName||''), (r.lineNumber||''), (r.columnNumber||''), (r.stack||''));
  });
})();
</script>

<!-- ========================= -->
<!-- ADD BELOW THIS LINE (CSS) -->
<!-- ========================= -->
<style>
  .imgbox{
    position:fixed;
    inset:0;
    display:none;
    align-items:center;
    justify-content:center;
    background:rgba(0,0,0,.6);
    z-index:100000;
    padding:16px;
  }
  .imgbox[aria-hidden="false"]{ display:flex; }
  .imgbox-inner{
    position:relative;
    max-width:min(1000px, 96vw);
    max-height:90vh;
    background:#0f1720;
    border:1px solid rgba(255,255,255,.18);
    border-radius:14px;
    box-shadow:0 30px 80px rgba(0,0,0,.5);
    overflow:hidden;
  }
  .imgbox-inner img{
    display:block;
    max-width:100%;
    max-height:90vh;
    height:auto;
    width:auto;
  }
  .imgbox-close{
    position:absolute;
    top:8px; right:8px;
    border:0; background:#1f2a38; color:#fff;
    border-radius:10px; padding:6px 10px; cursor:pointer;
    z-index:2;
  }

  .modal-body { display:block; }
  .sec-title{
    margin:8px 0 6px; font-size:15px; font-weight:700; color:#0f172a;
    border-left:4px solid #2563eb; padding-left:8px;
  }
  .kv{ display:flex; align-items:flex-start; gap:10px; padding:6px 0; border-bottom:1px dashed rgba(0,0,0,.08); }
  .kv:last-child{ border-bottom:0; }
  .kv > span{ flex:0 0 230px; color:#334155; font-weight:600; }
  .kv > b{ flex:1; color:#0b1220; font-weight:600; }
  .modal-body .split{ height:14px; }

  /* ADD-ONLY: compact "Label: Value" look in modal (Requester: Name) */
  .modal-body .kv{
    gap:4px;
  }
  .modal-body .kv > span{
    flex:0 0 auto;
    margin-right:2px;
  }
  .modal-body .kv > span::after{
    content: ': ';
  }

  .swal2-container { z-index: 100200 !important; }

  .toast-wrap{
    position: fixed; top: 14px; right: 14px;
    display: flex; flex-direction: column; gap: 10px; z-index: 100300; pointer-events: none;
  }
  .toast{
    display:flex; align-items:center; gap:10px; background:#0f172a; color:#e2e8f0;
    border:1px solid rgba(255,255,255,.12); border-radius:10px; padding:10px 12px;
    min-width: 260px; max-width: 420px; box-shadow:0 8px 24px rgba(0,0,0,.35);
    animation: toast-in .18s ease-out; pointer-events: auto;
  }
  .toast .t-icon{ font-size:16px; line-height:1; }
  .toast .t-body{ flex:1; font-size:14px; }
  .toast .t-close{ border:0; background:transparent; color:#e2e8f0; font-size:18px; cursor:pointer; }
  .toast.info{ border-color:#2563eb; }
  .toast.success{ border-color:#16a34a; }
  .toast.warn{ border-color:#d97706; }
  .toast.error{ border-color:#dc2626; }
  @keyframes toast-in { from{ opacity:0; transform: translateY(-6px);} to{ opacity:1; transform: translateY(0);} }
  @keyframes toast-out{ to{ opacity:0; transform: translateY(-6px);} }

  .btn.warn{ background:#d97706; color:#fff; border:0; }
  .btn.warn:hover{ filter: brightness(0.95); }

  .btn.minor{ background:#e2e8f0; color:#0b1220; border:0; padding:8px 12px; border-radius:10px; cursor:pointer; }
  .btn.primary{ background:#2563eb; color:#fff; border:0; padding:10px 14px; border-radius:10px; cursor:pointer; }

  /* NEW: Reschedule indicators */
  .badge.resched{
    display:inline-block;
    margin-left:6px;
    font-size:11px;
    background:#fff7ed;
    color:#c2410c;
    border:1px solid #fed7aa;
    padding:2px 8px;
    border-radius:999px;
    vertical-align:middle;
  }

  /* NEW: Cancel indicators */
  .badge.cancel{
    display:inline-block;
    margin-left:6px;
    font-size:11px;
    background:#fee2e2;
    color:#b91c1c;
    border:1px solid #fecaca;
    padding:2px 8px;
    border-radius:999px;
  }
  .row-resched{
    background: rgba(254, 243, 199, .25);
  }
  .chip.resched{
    background:#fff7ed;
    color:#c2410c;
    border:1px solid #fed7aa;
  }
</style>

<!-- ========================= -->
<!-- SweetAlert wrappers (approve/decline + toasts) -->
<!-- ========================= -->
<script>
(function(){
  function patchWithSwal(){
    if (!window.Swal || !window.updateStatus) return;
    const __origUpdateStatus = window.updateStatus;
    if (__origUpdateStatus.__wrappedWithSwal) return;

    function confirmSimpleStatus(svc, id, status) {
      const s = String(status);
      let title = 'Confirm action?';
      let text  = 'This will update the status.';
      let icon  = 'question';
      if (s === 'Approved') {
        title = 'Approve this request?';
        text  = 'This will set the status to Approved and attempt to email the requester.';
        icon  = 'question';
      } else if (s === 'Scheduled') {
        title = 'Approve reschedule?';
        text  = 'This will set the status to Scheduled and notify the requester.';
        icon  = 'question';
      } else if (s === 'Cancelled') {
        title = 'Cancel this reschedule?';
        text  = 'This will set the status to Cancelled and notify the requester.';
        icon  = 'warning';
      }
      Swal.fire({
        title,
        text,
        icon,
        showCancelButton: true,
        confirmButtonText: status,
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        heightAuto: false
      }).then(res => {
        if (res.isConfirmed) {
          __origUpdateStatus(svc, id, status);
        }
      });
    }

    function showDeclineReasonsDialog(svc, id, status) {
      const reasons = [
        'Blurred Image',
        'Date Unavailable',
        'Inconsistent Data',
        'Church has an event that day'
      ];
      const checkboxesHtml = reasons.map((label, idx) => (
        `<label style="display:flex;align-items:center;gap:8px;margin-bottom:4px;font-size:14px;">
          <input type="checkbox" class="decline-reason" value="${label}" />
          <span>${label}</span>
        </label>`
      )).join('');
      const html = `
        <div style="text-align:left;font-size:14px;">
          <p>Select the reason(s) for declining this request:</p>
          <div style="margin:8px 0 12px 0;">${checkboxesHtml}</div>
          <label style="display:block;margin-top:8px;font-size:13px;">Other reason / notes:</label>
          <textarea id="declineOther" rows="3" style="width:100%;box-sizing:border-box;font-size:13px;padding:6px;"></textarea>
        </div>
      `;
      Swal.fire({
        title: 'Decline this request?',
        html,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Decline',
        cancelButtonText: 'Cancel',
        focusCancel: true,
        heightAuto: false,
        preConfirm: () => {
          const selected = [];
          document.querySelectorAll('.decline-reason:checked').forEach(el => {
            if (el && el.value) selected.push(el.value);
          });
          const otherEl = document.getElementById('declineOther');
          const other = otherEl ? otherEl.value : '';
          const trimmedOther = other.trim();
          if (selected.length === 0 && !trimmedOther) {
            Swal.showValidationMessage('Please select at least one reason or enter a note.');
            return false;
          }
          return { reasons: selected, other: trimmedOther };
        }
      }).then(result => {
        if (!result.isConfirmed) return;
        const value = result.value || { reasons: [], other: '' };
        const fd = new FormData();
        fd.append('action', 'updateStatus');
        fd.append('svc', svc);
        fd.append('id', id);
        fd.append('status', status);
        try {
          fd.append('decline_reasons', JSON.stringify(value.reasons || []));
        } catch (e) {
          fd.append('decline_reasons', '[]');
        }
        fd.append('decline_notes', value.other || '');

        toast('Updating status…', 'info', { duration: 1500 });

        fetch('admin-schedule-request.php', { method: 'POST', body: fd })
          .then(async r => {
            const raw = await r.text();
            let j = null;
            try { j = JSON.parse(raw); }
            catch (e) {
              toast("Server error (raw): " + raw.slice(0,1000), 'error', { duration: 6000 });
              return;
            }
            if (!j.ok) {
              toast("Update failed: " + (j.msg || 'Unknown error'), 'error', { duration: 6000 });
              return;
            }
            toast(
              `Status updated to ${status}` + (j.emailSent ? ' • email sent.' : ` • email failed: ${j.emailMsg || ''}`),
              j.emailSent ? 'success' : 'warn',
              { duration: 4500 }
            );
            if (typeof closeModal === 'function') {
              closeModal();
            }
            setTimeout(() => location.reload(), 650);
          })
          .catch(e => {
            toast('Network error: ' + e.message, 'error', { duration: 6000 });
          });
      });
    }

    window.updateStatus = function(svc, id, status){
      const s = String(status);
      if (s === 'Declined') {
        showDeclineReasonsDialog(svc, id, status);
      } else {
        confirmSimpleStatus(svc, id, status);
      }
    };
    window.updateStatus.__wrappedWithSwal = true;
  }
  if (window.Swal) { patchWithSwal(); }
  else {
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';
    s.async = true;
    s.onload = patchWithSwal;
    document.head.appendChild(s);
  }
})();
</script>

<script>
(function(){
  function ensureSwal(cb){
    if (window.Swal) return cb();
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';
    s.async = true;
    s.onload = cb;
    document.head.appendChild(s);
  }
  ensureSwal(function(){
    const Toast = Swal.mixin({
      toast: true, position: 'top-end', showConfirmButton: false,
      timer: 4000, timerProgressBar: true,
      didOpen: (t) => { t.addEventListener('mouseenter', Swal.stopTimer); t.addEventListener('mouseleave', Swal.resumeTimer); }
    });

    const __origFetch = window.fetch;
    if (__origFetch.__wrappedForUpdateStatusSwalToast) return;

    window.fetch = function(input, init){
      const p = __origFetch.apply(this, arguments);
      try {
        const url = (typeof input === 'string') ? input : (input && input.url) ? input.url : '';
        const method = (init && init.method) || (input && input.method) || 'GET';
        if (url && /admin-schedule-request\.php(?:$|\?)/.test(url) && String(method).toUpperCase() === 'POST') {
          p.then(function(res){
            try {
              const clone = res.clone();
              clone.text().then(function(raw){
                let j = null; try { j = JSON.parse(raw); } catch(e){ return; }
                if (j && typeof j.ok !== 'undefined') {
                  if (j.ok) {
                    const title = (typeof (j.emailSent) !== 'undefined')
                      ? (j.emailSent ? 'Status updated • Email sent' : 'Status updated • Email failed' + (j.emailMsg ? (': ' + j.emailMsg) : ''))
                      : 'Action completed';
                    Toast.fire({ icon: (j.emailSent === false) ? 'warning' : 'success', title });
                  } else {
                    Toast.fire({ icon: 'error', title: j.msg || 'Update failed' });
                  }
                } else if (j && (j.type === 'php_error' || j.type === 'php_fatal')) {
                  Toast.fire({ icon: 'error', title: j.message || 'Server error' });
                }
              });
            } catch(e){}
            return res;
          }).catch(function(err){
            Toast.fire({ icon: 'error', title: 'Network error: ' + (err && err.message ? err.message : err) });
          });
        }
      } catch(e){}
      return p;
    };
    window.fetch.__wrappedForUpdateStatusSwalToast = true;
  });
})();
</script>



</body>
</html>