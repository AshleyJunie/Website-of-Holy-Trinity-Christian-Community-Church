<?php
include 'db-connection.php';

/* ---------- PHPMailer (SMTP via Gmail) ---------- */
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';
require __DIR__ . '/PHPMailer/src/Exception.php';

/* ====== AUDIT LOGGER (reads admin_table for username/email) ====== */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function _audit_uuidv4(){
  $d = random_bytes(16);
  $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
  $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}
function _audit_txn_id(){ static $id=null; return $id ?: ($id=_audit_uuidv4()); }

/** Fetch admin row from admin_table by admin_id (cached per request) */
function _audit_fetch_admin($admin_id){
  static $cache = [];
  if (!$admin_id) return null;
  if (isset($cache[$admin_id])) return $cache[$admin_id];

  global $db_connection;
  $sql = "SELECT admin_username, admin_emailaddress
          FROM admin_table
          WHERE admin_id = ?
          LIMIT 1";
  if (!$st = mysqli_prepare($db_connection, $sql)) {
    error_log('audit fetch_admin prepare: '.mysqli_error($db_connection));
    return null;
  }
  mysqli_stmt_bind_param($st,'i',$admin_id);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $row = $res ? mysqli_fetch_assoc($res) : null;
  mysqli_stmt_close($st);

  if ($row) $cache[$admin_id] = $row;
  return $row;
}

/**
 * Write to audit_trail (prepared). Call **after success**.
 * $action: 'INSERT','UPDATE','DELETE','SUBMIT','APPROVE','REJECT','LOGIN','LOGOUT','VIEW','EXPORT','IMPORT','ENABLE','SUSPEND','OTHER'
 */
function log_audit($action, $source_table=null, $record_pk=null, $form_name='admin-application.php', $details_before=null, $details_after=null, $notes=null){
  global $db_connection;

  $actor_id = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;

  // Prefer DB values from admin_table
  $actor_user = null;
  $actor_mail = null;
  if ($actor_id) {
    if ($adm = _audit_fetch_admin($actor_id)) {
      $actor_user = $adm['admin_username']     ?? null;
      $actor_mail = $adm['admin_emailaddress'] ?? null;
    }
  }
  // Fallback to any session values
  if (!$actor_user) $actor_user = $_SESSION['username'] ?? $_SESSION['admin_username'] ?? null;
  if (!$actor_mail) $actor_mail = $_SESSION['email']    ?? $_SESSION['admin_email']    ?? null;

  $txn = _audit_txn_id();
  $ip  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
  if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
  $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';

  $flags  = JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;
  $before = is_null($details_before) ? null : (is_string($details_before)? $details_before : json_encode($details_before,$flags));
  $after  = is_null($details_after)  ? null : (is_string($details_after)?  $details_after  : json_encode($details_after,$flags));
  $pk     = is_null($record_pk) ? null : (string)$record_pk;

  $sql = "INSERT INTO audit_trail
            (txn_id, actor_admin_id, actor_username, actor_email, action,
             source_table, record_pk, form_name, ip_address, user_agent, notes,
             details_before, details_after)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";
  if (!$st = mysqli_prepare($db_connection, $sql)) {
    error_log('audit prepare: '.mysqli_error($db_connection));
    return [false, 'prepare failed'];
  }
  mysqli_stmt_bind_param($st, 'sisssssssssss',
    $txn, $actor_id, $actor_user, $actor_mail, $action,
    $source_table, $pk, $form_name, $ip, $ua, $notes, $before, $after
  );
  $ok = mysqli_stmt_execute($st);
  $err = $ok ? null : mysqli_stmt_error($st);
  mysqli_stmt_close($st);
  if(!$ok) error_log('audit exec: '.$err);
  return [$ok,$err];
}
/* ====== END AUDIT LOGGER ====== */

/* =======================================================================
   Admin → Individual notifications helper (using your notifications schema)
   ======================================================================= */
function notifyIndividualFromAdmin_mysqli(
  int $individualId,
  string $actionKey,
  string $individualName = ''
): bool {

  global $db_connection;

  if ($individualId <= 0) {
    return false;
  }

  if (session_status() === PHP_SESSION_NONE) {
    @session_start();
  }

  // ---- 1) Identify Admin creator ----
  $createdByType = 'admin';
  $createdById   = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : 0;

  // Fallback to generic user_id if needed
  if ($createdById <= 0 && isset($_SESSION['user_id'])) {
    $createdById = (int)$_SESSION['user_id'];
  }

  if ($createdById <= 0) {
    // Cannot identify admin -> do not create notification
    error_log('[notifyIndividualFromAdmin_mysqli] Missing admin_id in session.');
    return false;
  }

  // ---- 2) Resolve Admin display label (for body) ----
  $adminLabel = null;

  $admRow = _audit_fetch_admin($createdById);
  if ($admRow) {
    if (!empty($admRow['admin_username'])) {
      $adminLabel = $admRow['admin_username'];
    } elseif (!empty($admRow['admin_emailaddress'])) {
      $adminLabel = $admRow['admin_emailaddress'];
    }
  }

  if (!$adminLabel && !empty($_SESSION['admin_name'])) {
    $adminLabel = (string)$_SESSION['admin_name'];
  }
  if (!$adminLabel && !empty($_SESSION['admin_username'])) {
    $adminLabel = (string)$_SESSION['admin_username'];
  }
  if (!$adminLabel) {
    $adminLabel = 'Admin #' . $createdById;
  }

  // ---- 3) Normalize Individual label ----
  $individualId   = (int)$individualId;
  $individualName = trim((string)$individualName);
  if ($individualName === '') {
    $individualName = 'Member #' . $individualId;
  }

  // ---- 4) Map actionKey -> ActionLabel & Explanation ----
  $actionKey    = strtolower(trim($actionKey));
  $serviceLabel = 'Baptism Verification';
  $actionLabel  = 'Updated';

  if ($actionKey === 'approve' || $actionKey === 'approved' || strpos($actionKey,'approve') !== false) {
    $actionLabel = 'Approved';
  } elseif ($actionKey === 'reupload' || strpos($actionKey,'reupload') !== false) {
    $actionLabel = 'Reupload Required';
  } elseif ($actionKey === 'decline' || $actionKey === 'rejected' || strpos($actionKey,'reject') !== false || strpos($actionKey,'decline') !== false) {
    $actionLabel = 'Rejected';
  }

  $title = $serviceLabel . ' – ' . $actionLabel;

  if ($actionLabel === 'Approved') {
    $explanation = 'Your baptism verification documents have been reviewed and approved by the administrator. '
                 . 'You are receiving this notification because your baptism verification status is now VERIFIED in the HTCCC system.';
  } elseif ($actionLabel === 'Reupload Required') {
    $explanation = 'Your baptism verification documents have been reviewed by the administrator and a clearer or updated copy is required. '
                 . 'You are receiving this notification because your baptism verification status has been set to REUPLOAD and additional action is needed from you.';
  } elseif ($actionLabel === 'Rejected') {
    $explanation = 'Your baptism verification request has been reviewed by the administrator but was not approved. '
                 . 'You are receiving this notification because your baptism verification status has been marked as REJECTED in the HTCCC system.';
  } else {
    $explanation = 'Your baptism verification record has been updated by the administrator. '
                 . 'You are receiving this notification because there has been a change to your baptism verification status.';
  }

  // ---- 5) Build body (plain text) ----
  $lines = [];
  $lines[] = 'Admin: ' . $adminLabel;
  $lines[] = 'Action: ' . $actionLabel;
  $lines[] = 'Service: ' . $serviceLabel;
  $lines[] = 'Applied To: ' . $individualName . ' (ID: ' . $individualId . ')';
  $lines[] = 'Explanation: ' . $explanation;

  $body = implode("\n", $lines);

  if (function_exists('mb_strlen')) {
    if (mb_strlen($body) > 2000) {
      $body = mb_substr($body, 0, 2000);
    }
  } else {
    if (strlen($body) > 2000) {
      $body = substr($body, 0, 2000);
    }
  }

  // ---- 6) INSERT into notifications (prepared) ----
  $sqlNotif = "INSERT INTO notifications (title, body, created_by_type, created_by_id)
               VALUES (?,?,?,?)";
  $st = mysqli_prepare($db_connection, $sqlNotif);
  if (!$st) {
    error_log('[notifyIndividualFromAdmin_mysqli] Prepare notifications failed: '.mysqli_error($db_connection));
    return false;
  }

  mysqli_stmt_bind_param($st, 'sssi', $title, $body, $createdByType, $createdById);
  $okNotif = mysqli_stmt_execute($st);
  if (!$okNotif) {
    error_log('[notifyIndividualFromAdmin_mysqli] Exec notifications failed: '.mysqli_stmt_error($st));
    mysqli_stmt_close($st);
    return false;
  }
  $notificationId = (int)mysqli_insert_id($db_connection);
  mysqli_stmt_close($st);

  if ($notificationId <= 0) {
    error_log('[notifyIndividualFromAdmin_mysqli] Invalid notification_id after insert.');
    return false;
  }

  // ---- 7) INSERT into notification_recipients (prepared) ----
  $sqlRec = "INSERT INTO notification_recipients (notification_id, user_type, user_id, status)
             VALUES (?, 'individual', ?, 'unread')";
  $st2 = mysqli_prepare($db_connection, $sqlRec);
  if (!$st2) {
    error_log('[notifyIndividualFromAdmin_mysqli] Prepare recipients failed: '.mysqli_error($db_connection));
    return false;
  }
  mysqli_stmt_bind_param($st2, 'ii', $notificationId, $individualId);
  $okRec = mysqli_stmt_execute($st2);
  if (!$okRec) {
    error_log('[notifyIndividualFromAdmin_mysqli] Exec recipients failed: '.mysqli_stmt_error($st2));
    mysqli_stmt_close($st2);
    return false;
  }
  mysqli_stmt_close($st2);

  return true;
}

/**
 * Send email about account / baptism verification status.
 * Supported $status: 'approved','declined','verified','reupload'
 */
function sendDecisionEmail($toEmail, $toName, $status, $reason = null) {
  $mail = new PHPMailer(true);
  try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS
    $mail->Port       = 587;
    $mail->Username   = 'holytrinitychristiancommunityc@gmail.com';
    $mail->Password   = 'jngx vtqb urun yjur';

    $mail->setFrom('holytrinitychristiancommunityc@gmail.com', 'HTCCC Notification');
    $mail->addAddress($toEmail, $toName ?: $toEmail);
    $mail->isHTML(true);

    $safeName   = htmlspecialchars($toName ?: 'Member');
    $reasonSafe = $reason ? nl2br(htmlspecialchars($reason)) : 'No reason provided.';

    if ($status === 'approved') {
      // Original account APPROVED
      $mail->Subject = 'HTCCC Account Approved';
      $body = "
        <div style='font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6'>
          <p>Hi <b>{$safeName}</b>,</p>
          <p>Your account verification at <b>Holy Trinity Christian Community Church (HTCCC)</b> has been <b style='color:#16a34a'>APPROVED</b>.</p>
          <p>You may now <b>sign in</b> and <b>book an appointment</b> for our services.</p>
          <p>Thank you and God bless!</p>
          <hr>
          <p style='color:#6b7280'>This is an automated message. Please do not reply.</p>
        </div>";

    } elseif ($status === 'declined') {
      // Original account DECLINED
      $mail->Subject = 'HTCCC Account Verification Update';
      $body = "
        <div style='font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6'>
          <p>Hi <b>{$safeName}</b>,</p>
          <p>Your account verification at <b>Holy Trinity Christian Community Church (HTCCC)</b> has been <b style='color:#dc2626'>DECLINED</b>.</p>
          <p><b>Reason:</b><br>{$reasonSafe}</p>
          <p>You may update your details and re-apply. If you need assistance, kindly contact us.</p>
          <hr>
          <p style='color:#6b7280'>This is an automated message. Please do not reply.</p>
        </div>";

    } elseif ($status === 'verified') {
      // Baptism verification VERIFIED
      $mail->Subject = 'HTCCC Baptism Verification Approved';
      $body = "
        <div style='font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6'>
          <p>Hi <b>{$safeName}</b>,</p>
          <p>Your <b>baptism verification</b> at <b>Holy Trinity Christian Community Church (HTCCC)</b> is now <b style='color:#16a34a'>VERIFIED</b>.</p>
          <p>This means your baptismal documents have been reviewed and accepted. You may now continue with any related HTCCC services that require baptism verification.</p>
          <p>Thank you and God bless!</p>
          <hr>
          <p style='color:#6b7280'>This is an automated message. Please do not reply.</p>
        </div>";

    } elseif ($status === 'reupload') {
      // Baptism verification REUPLOAD requested
      $mail->Subject = 'HTCCC Baptism Verification – Reupload Required';
      $body = "
        <div style='font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6'>
          <p>Hi <b>{$safeName}</b>,</p>
          <p>We have reviewed your <b>baptism verification</b> documents at <b>Holy Trinity Christian Community Church (HTCCC)</b>.</p>
          <p>Your verification status is now <b style='color:#b45309'>REUPLOAD</b>. This means we need you to upload your baptismal certificate again or provide a clearer copy.</p>
          <p>If you have questions or need assistance, please contact the HTCCC office.</p>
          <hr>
          <p style='color:#6b7280'>This is an automated message. Please do not reply.</p>
        </div>";

    } else {
      // Generic fallback
      $mail->Subject = 'HTCCC Account Status Update';
      $body = "
        <div style='font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6'>
          <p>Hi <b>{$safeName}</b>,</p>
          <p>This is an update about the status of your account / verification at <b>Holy Trinity Christian Community Church (HTCCC)</b>.</p>
          <p>Current status: <b>".htmlspecialchars((string)$status)."</b></p>
          <p>If you have any questions, please contact the HTCCC office.</p>
          <hr>
          <p style='color:#6b7280'>This is an automated message. Please do not reply.</p>
        </div>";
    }

    $mail->Body    = $body;
    $mail->AltBody = strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $body));
    $mail->send();
    return [true, null];
  } catch (Exception $e) {
    return [false, $mail->ErrorInfo ?: $e->getMessage()];
  }
}

/* ---------- small helpers ---------- */
function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function is_image_path($v){
  if(!$v) return false;
  $ext = strtolower(pathinfo($v, PATHINFO_EXTENSION));
  return in_array($ext, ['jpg','jpeg','png','gif','webp','bmp']);
}
function project_base_path(){
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  $base   = rtrim(str_replace('\\','/', dirname($script)), '/');
  return $base === '' ? '/' : $base;
}
function to_url($v){
  if(!$v) return '';
  if (preg_match('~^https?://~i',$v)) return $v;
  $v = ltrim($v, '/');
  return project_base_path() . '/' . $v;
}
function status_badge($s){
  // Now used for baptism_verification (Pending, Verified, Reupload),
  // but still gracefully handles account statuses.
  $s = (string)($s ?? 'Pending');
  $normalized = strtolower($s);

  $cls = 'status-pending';
  if ($normalized === 'verified') {
    $cls = 'status-verified';
  } elseif ($normalized === 'reupload') {
    $cls = 'status-reupload';
  } elseif ($normalized === 'active') {
    $cls = 'status-active';
  } elseif ($normalized === 'suspended') {
    $cls = 'status-suspended';
  } elseif (in_array($normalized, ['unverified','pending'], true)) {
    $cls = 'status-pending';
  }

  return '<span class="status-badge '.$cls.'">'.h($s).'</span>';
}

/* ---------- AJAX: fetch details for modal ---------- */
if (isset($_GET['view']) && ctype_digit($_GET['view'])) {
  header('Content-Type: text/html; charset=utf-8');
  $id = (int)$_GET['view'];
  $sql = "SELECT * FROM individual_table WHERE individual_id = ?";
  $st  = mysqli_prepare($db_connection, $sql);
  mysqli_stmt_bind_param($st,'i',$id);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $row = $res ? mysqli_fetch_assoc($res) : null;

  if (!$row) { echo '<div class="modal-body">Record not found.</div>'; exit; }

  ob_start(); ?>
  <div class="modal-header">
    <h3>Baptism Verification Details</h3>
    <button class="modal-close" onclick="closeModal()">×</button>
  </div>
  <div class="modal-body">
    <div class="kv"><span>Lastname</span><b><?=h($row['individual_lastname'])?></b></div>
    <div class="kv"><span>Firstname</span><b><?=h($row['individual_firstname'])?></b></div>
    <div class="kv"><span>Middlename</span><b><?=h($row['individual_middlename'])?></b></div>
    <div class="kv"><span>Extension</span><b><?=h($row['individual_extension'])?></b></div>
    <div class="kv"><span>Email Address</span><b><?=h($row['individual_email_address'])?></b></div>
    <div class="kv"><span>Contact Number</span><b><?=h($row['individual_phone_number'])?></b></div>

    <?php
      $bapPath = $row['img_baptismal_cert'] ?? '';
    ?>

    <!-- Baptismal Certificate -->
    <div class="kv">
      <span>Baptismal Certificate</span>
      <b>
        <?php
          if ($bapPath) {
            $u = h(to_url($bapPath));
            echo '<button class="btn tiny" onclick="openImg(\''.$u.'\')"><i class="fas fa-image"></i> View Baptismal Cert</button>';
          } else {
            echo '—';
          }
        ?>
      </b>
    </div>
  </div>
  <div class="modal-footer">
    <div class="grow"></div>

    <!-- Approve: baptism_verification -> Verified -->
    <button class="btn success" onclick="approveBaptism(<?= (int)$row['individual_id'] ?>)">
      <i class="fas fa-check"></i> Approve
    </button>

    <!-- Reupload: baptism_verification -> Reupload -->
    <button class="btn warning" onclick="reuploadBaptism(<?= (int)$row['individual_id'] ?>)">
      <i class="fas fa-upload"></i> Reupload
    </button>

    <button class="btn ghost" onclick="closeModal()">Close</button>
  </div>
  <?php
  echo ob_get_clean();
  exit;
}

/* ---------- AJAX: approve / reupload (baptism_verification) ---------- */
if (isset($_POST['action'])) {
  header('Content-Type: application/json; charset=utf-8');
  $id = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : 0;

  if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Invalid ID']); exit; }

  // Fetch recipient info once
  $info = ['email'=>null, 'name'=>null];
  if ($st = mysqli_prepare($db_connection, "SELECT individual_email_address, CONCAT(individual_firstname,' ',individual_lastname) AS fullname FROM individual_table WHERE individual_id=? LIMIT 1")) {
    mysqli_stmt_bind_param($st,'i',$id);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    if ($res && $u = mysqli_fetch_assoc($res)) {
      $info['email'] = $u['individual_email_address'] ?? null;
      $info['name']  = $u['fullname'] ?? null;
    }
    mysqli_stmt_close($st);
  }

  // Helper to get current baptism_verification for audit "before"
  $before = null;
  if ($st0 = mysqli_prepare($db_connection, "SELECT baptism_verification FROM individual_table WHERE individual_id=? LIMIT 1")) {
    mysqli_stmt_bind_param($st0,'i',$id);
    mysqli_stmt_execute($st0);
    $res0 = mysqli_stmt_get_result($st0);
    if ($res0) $before = mysqli_fetch_assoc($res0);
    mysqli_stmt_close($st0);
  }

  if ($_POST['action'] === 'approve') {
    // Set baptism_verification = 'Verified'
    $sql = "UPDATE individual_table SET baptism_verification='Verified' WHERE individual_id=? LIMIT 1";
    $st  = mysqli_prepare($db_connection, $sql);
    mysqli_stmt_bind_param($st,'i',$id);
    $ok  = mysqli_stmt_execute($st);
    mysqli_stmt_close($st);

    $email_ok = null; $email_err = null;
    if ($ok && $info['email']) {
      [$email_ok, $email_err] = sendDecisionEmail($info['email'], $info['name'], 'verified');
    }

    if ($ok) {
      $after = ['baptism_verification'=>'Verified'];
      $notes = 'Baptism verification approved (baptism_verification set to "Verified").';
      log_audit('APPROVE', 'individual_table', $id, 'admin-application.php', $before, $after, $notes);

      // Admin → Individual notification
      try {
        notifyIndividualFromAdmin_mysqli(
          $id,                 // individual_id
          'approve',           // actionKey
          (string)($info['name'] ?? '') // individualName
        );
      } catch (Throwable $e) {
        error_log('[notifyIndividualFromAdmin_mysqli][approve] '.$e->getMessage());
      }
    }

    echo json_encode([
      'ok'        => $ok ? true : false,
      'msg'       => $ok ? 'Baptism verification set to "Verified".' : 'DB error',
      'email_ok'  => $email_ok,
      'email_err' => $email_err
    ]);
    exit;

  } elseif ($_POST['action'] === 'reupload') {
    // Set baptism_verification = 'Reupload'
    $sql = "UPDATE individual_table SET baptism_verification='Reupload' WHERE individual_id=? LIMIT 1";
    $st  = mysqli_prepare($db_connection, $sql);
    mysqli_stmt_bind_param($st,'i',$id);
    $ok  = mysqli_stmt_execute($st);
    mysqli_stmt_close($st);

    $email_ok = null; $email_err = null;
    if ($ok && $info['email']) {
      [$email_ok, $email_err] = sendDecisionEmail($info['email'], $info['name'], 'reupload');
    }

    if ($ok) {
      $after = ['baptism_verification'=>'Reupload'];
      $notes = 'Baptism verification marked as "Reupload" (member asked to reupload certificate).';
      log_audit('REJECT', 'individual_table', $id, 'admin-application.php', $before, $after, $notes);

      // Admin → Individual notification
      try {
        notifyIndividualFromAdmin_mysqli(
          $id,                 // individual_id
          'reupload',          // actionKey
          (string)($info['name'] ?? '') // individualName
        );
      } catch (Throwable $e) {
        error_log('[notifyIndividualFromAdmin_mysqli][reupload] '.$e->getMessage());
      }
    }

    echo json_encode([
      'ok'        => $ok ? true : false,
      'msg'       => $ok ? 'Baptism verification set to "Reupload".' : 'DB error',
      'email_ok'  => $email_ok,
      'email_err' => $email_err
    ]);
    exit;
  }

  echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta content="width=device-width, initial-scale=1"/>
  <title>Admin – User Application</title>

  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/admin-application.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/admin-application.css'); ?>">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    /* Modal + lightbox (unchanged from your layout style) */
    .modal{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:9999;padding:16px}
    .modal[aria-hidden="false"]{display:flex}
    .modal-dialog{width:min(720px,96vw);max-height:92vh;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 20px 50px rgba(0,0,0,.35)}
    .modal-header,.modal-footer{padding:12px 16px;background:#f7f9fc;border-bottom:1px solid #e6edf5;display:flex;gap:10px;align-items:center}
    .modal-header{background:#00216e;color:#fff;border-bottom:none}
    .modal-header h3{margin:0;font-size:18px}
    .modal-close{margin-left:auto;background:transparent;border:0;color:#fff;font-size:20px;cursor:pointer}
    .modal-body{padding:14px 16px;max-height:64vh;overflow:auto}
    .kv{display:grid;grid-template-columns:200px 1fr;gap:10px;padding:8px 0;border-bottom:1px solid #e6edf5}
    .kv span{color:#6b7280}
    .modal-footer .grow{flex:1}
    .btn{background:#e2e8f0;border:0;padding:8px 12px;border-radius:10px;cursor:pointer}
    .btn.ghost{background:transparent;border:1px solid #e6edf5}
    .btn.warning{background:#f59e0b;color:#fff}
    .btn.tiny{padding:6px 10px;font-size:12px}

    /* success style for Approve button */
    .btn.success{background:#16a34a;color:#fff;}
    .btn.success:hover{background:#15803d;}

    .imgbox{position:fixed;inset:0;background:rgba(0,0,0,.6);display:none;align-items:center;justify-content:center;z-index:10000;padding:1rem}
    .imgbox[aria-hidden="false"]{display:flex}
    .imgbox-inner{position:relative;max-width:96vw;max-height:92vh;background:#000;border-radius:12px;overflow:hidden}
    .imgbox img{display:block;max-width:96vw;max-height:92vh}
    .imgbox-close{position:absolute;top:8px;right:8px;background:rgba(255,255,255,.2);color:#fff;border:0;border-radius:8px;padding:6px 10px;cursor:pointer}

    /* Status pill badges */
    td .status-badge,
    .kv b .status-badge {
      display: inline-block;
      padding: 2px 10px;
      border-radius: 999px;
      font-size: 12px;
      line-height: 18px;
      border: 1px solid #e5e7eb;
      background: #f9fafb;
      color: #374151;
      vertical-align: middle;
      white-space: nowrap;
    }
    /* Account-related (kept for compatibility) */
    .status-badge.status-active { background:#ecfdf5;border-color:#a7f3d0;color:#065f46; }
    .status-badge.status-suspended { background:#fff7ed;border-color:#fcd34d;color:#92400e; }
    .status-badge.status-unverified { background:#f1f5f9;border-color:#cbd5e1;color:#334155; }

    /* Baptism verification specific */
    .status-badge.status-verified { background:#ecfdf5;border-color:#6ee7b7;color:#065f46; }
    .status-badge.status-pending { background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8; }
    .status-badge.status-reupload { background:#fef3c7;border-color:#facc15;color:#92400e; }

    /* Sort dropdown */
    .sort-wrap{position:relative;display:inline-block}
    .sort-button{margin-left:8px;padding:8px 12px;border:1px solid #e5e7eb;background:#fff;border-radius:10px;cursor:pointer}
    .sort-menu{
      position:absolute;top:110%;right:0;
      min-width:320px;background:#fff;border:1px solid #e5e7eb;
      border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);
      padding:6px;display:none;z-index:50
    }
    .sort-menu[aria-hidden="false"]{display:block}
    .sort-menu button{
      width:100%;border:0;background:transparent;padding:10px 12px;
      border-radius:8px;cursor:pointer;display:grid;
      grid-template-columns:1fr auto;align-items:center;column-gap:12px;
      white-space:nowrap
    }
    .sort-menu button:hover{background:#f3f4f6}
    .sort-menu .hint{opacity:.65;font-size:12px;white-space:nowrap}
    .sort-menu hr{border:0;border-top:1px solid #eee;margin:6px 4px}
  </style>
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
        <div class="user-title">Secretary</div>
        <div class="user-sub">Dashboard</div>
      </div>
    </div>
    <nav class="nav">
      <div class="section-title">Main</div>
      <a class="navlink" href="secretary_dashboard.php"><i class="fas fa-home"></i>Dashboard</a>
      <div class="section-title">Online Requests</div>
      <a class="navlink" href="admin-schedule-request.php"><i class="fas fa-calendar-plus"></i>Schedule Requests</a>
      <a class="navlink" href="admin-prayer-request.php"><i class="fas fa-praying-hands"></i><span>Prayer Requests</span></a>
      <div class="section-title">Online Applications</div>
      <a class="navlink" href="baptismal_application.php"><i class="fas fa-water"></i>Baptismal Applications</a>
      <a class="navlink active" href="admin-application.php"><i class="fas fa-user-cog"></i>Baptismal Account Verification</a>
      <a class="navlink" href="application_ministry.php"><i class="fas fa-users"></i>Ministry Applications</a>
      <div class="section-title">Schedule</div>
      <a class="navlink" href="appointment-schedule.php"><i class="fas fa-calendar-check"></i>Service Schedule</a>
      <div class="section-title">All Done Services</div>
      <a class="navlink" href="done-service-wedding.php"><i class="fas fa-ring"></i>Wedding Service</a>
      <a class="navlink" href="done-service-dedication.php"><i class="fas fa-baby"></i>Child Dedication</a>
      <a class="navlink" href="done-service-funeral.php"><i class="fas fa-cross"></i>Funeral Service</a>
      <a class="navlink" href="done-service-house.php"><i class="fas fa-home"></i>House Blessing</a>
      <a class="navlink" href="done-service-baptism.php"><i class="fas fa-tint"></i>Water Baptism</a>
      <div class="section-title">Streaming</div>
      <a class="navlink" href="admin-multimedia.php"><i class="fas fa-broadcast-tower"></i>Streaming</a>
       <div class="section-title">Individual Management</div>
      <a class="navlink" href="admin-individual_list.php"><i class="fas fa-user"></i>Individual List</a>
      <div class="section-title">Ministry Management</div>
      <a class="navlink" href="admin-ministry-women.php"><i class="fas fa-female"></i>Handmaid of the Lord</a>
      <a class="navlink" href="admin-ministry-men.php"><i class="fas fa-male"></i>Men Ministry</a>
      <a class="navlink" href="admin-ministry-music.php"><i class="fas fa-music"></i>Music Ministry</a>
      <a class="navlink" href="admin-ministry-usher.php"><i class="fas fa-hands-helping"></i>Usher &amp; Usherette</a>
      <div class="section-title">Reports</div>
      <a class="navlink" href="admin-reports.php"><i class="fas fa-file-alt"></i>Reports</a>
      <div class="section-title">Content</div>
      <a class="navlink" href="content-management_home-page.php"><i class="fas fa-edit"></i>Content Management</a>
      <div class="section-title">Certificates</div>
      <a class="navlink" href="certificate-table.php"><i class="fas fa-award"></i>Generate Certificate</a>
      <div class="section-title">Account</div>
      <a class="navlink" href="admin-account-settings.php"><i class="fas fa-user-shield"></i>Account Settings</a>
      <div class="section-title">More</div>
      <a class="navlink logout" href="all_log-in.php"><i class="fas fa-sign-out-alt"></i>Log Out</a>
    </nav>
  </aside>

<!-- ============== MAIN PAGE CONTENT ============== -->
<div class="page">
  <header class="topbar">
    <h1>Baptismal Account Verification</h1>
  </header>

  <div class="container">
    <div class="top-bar">
      <input type="text" id="searchBox" placeholder="🔍 Search" class="search-box">
      <span class="sort-wrap">
        <button type="button" class="sort-button" id="sortBtn">Sort by ▾</button>
        <div class="sort-menu" id="sortMenu" aria-hidden="true">
          <button data-type="alpha" data-col="0">
            <span>Alphabetical – Lastname</span>
            <span class="hint">A → Z</span>
          </button>
          <button data-type="alpha" data-col="1">
            <span>Alphabetical – Firstname</span>
            <span class="hint">A → Z</span>
          </button>
          <hr>
          <button data-type="status">
            <span>By Status</span>
            <span class="hint">Verified → Reupload → Pending</span>
          </button>
        </div>
      </span>
    </div>

    <table id="appTable">
      <thead>
        <tr>
          <th>Lastname</th>
          <th>Firstname</th>
          <th>Contact Number</th>
          <th>Address</th>
          <th>Status</th>
          <th style="text-align:center;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php
        // Filter only records with baptism_verification = 'Pending'
        $q = "SELECT individual_id, individual_lastname, individual_firstname, individual_phone_number,
                     individual_street, individual_city, individual_zip_code, baptism_verification
              FROM individual_table
              WHERE baptism_verification = 'Pending'
              ORDER BY individual_lastname, individual_firstname";
        $r = mysqli_query($db_connection, $q);
        if ($r && mysqli_num_rows($r)>0) {
          while ($row = mysqli_fetch_assoc($r)) {
            $addr = trim(($row['individual_street']??'').' '.$row['individual_city'].' '.($row['individual_zip_code']??'')); 
            echo '<tr data-id="'.(int)$row['individual_id'].'">';
            echo '<td>'.h($row['individual_lastname']).'</td>';
            echo '<td>'.h($row['individual_firstname']).'</td>';
            echo '<td>'.h($row['individual_phone_number']).'</td>';
            echo '<td>'.h($addr).'</td>';
            // USE baptism_verification for Status column
            echo '<td>'.status_badge($row['baptism_verification'] ?? 'Pending').'</td>';
            echo '<td style="text-align:center">
                    <button class="view" onclick="viewMember('.(int)$row['individual_id'].')"><i class="fas fa-eye"></i> View</button>
                  </td>';
            echo '</tr>';
          }
        } else {
          echo '<tr><td colspan="6">No pending baptism verification records found.</td></tr>';
        }
        ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal -->
<div id="modal" class="modal" aria-hidden="true">
  <div class="modal-dialog">
    <div id="modalContent">Loading…</div>
  </div>
</div>

<!-- Image Lightbox -->
<div id="imgBox" class="imgbox" aria-hidden="true">
  <div class="imgbox-inner">
    <button class="imgbox-close" onclick="closeImg()">×</button>
    <img id="imgPreview" alt="Uploaded document">
  </div>
</div>

<script>
/* search */
const searchBox = document.getElementById('searchBox');
const tbody = document.querySelector('#appTable tbody');
const rows  = [...tbody.querySelectorAll('tr')];
if (searchBox) {
  searchBox.addEventListener('input', () => {
    const q = (searchBox.value || '').toLowerCase();
    rows.forEach(r => r.style.display = r.innerText.toLowerCase().includes(q) ? '' : 'none');
  });
}

/* modal helpers */
function openModal(){ document.getElementById('modal').setAttribute('aria-hidden','false'); }
function closeModal(){ document.getElementById('modal').setAttribute('aria-hidden','true'); document.getElementById('modalContent').innerHTML=''; }
window.closeModal = closeModal;

/* image lightbox */
function openImg(src){
  const b = document.getElementById('imgBox');
  const i = document.getElementById('imgPreview');
  i.src = src;
  b.setAttribute('aria-hidden','false');
}
function closeImg(){
  const b = document.getElementById('imgBox');
  const i = document.getElementById('imgPreview');
  i.src = '';
  b.setAttribute('aria-hidden','true');
}
window.openImg = openImg;

/* view member (loads details HTML from this same file) */
function viewMember(id){
  openModal();
  const box = document.getElementById('modalContent');
  box.innerHTML = 'Loading…';
  fetch('admin-application.php?view=' + encodeURIComponent(id))
    .then(r => r.text())
    .then(html => box.innerHTML = html)
    .catch(() => box.innerHTML = '<div class="modal-body">Failed to load.</div>');
}

/* APPROVE baptism_verification = Verified */
function approveBaptism(id){
  Swal.fire({
    icon: 'question',
    title: 'Approve baptism verification?',
    text: 'This will mark the baptism verification as "Verified" for this member.',
    showCancelButton: true,
    confirmButtonText: 'Yes, approve',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#16a34a'
  }).then(res => {
    if (!res.isConfirmed) { Swal.close(); return; }

    Swal.fire({
      title: 'Saving...',
      allowOutsideClick: false,
      allowEscapeKey: false,
      didOpen: () => { Swal.showLoading(); }
    });

    fetch('admin-application.php', {
      method:'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({action:'approve', id:String(id)})
    })
    .then(r => r.json())
    .then(j => {
      try { closeModal(); } catch(e) {}
      if (j.ok) {
        Swal.fire({
          icon: 'success',
          title: 'Approved',
          text: 'Baptism verification has been set to "Verified". Email notification sent (if possible).',
          timer: 1500,
          showConfirmButton: false,
          willClose: () => { window.location.reload(); }
        });
      } else {
        Swal.fire({
          icon:'error',
          title:'Error',
          text: j.msg || 'Database error',
          timer: 1500,
          showConfirmButton: false
        });
      }
    })
    .catch(() => {
      Swal.fire({ icon:'error', title:'Network error', timer: 1200, showConfirmButton: false });
    });
  });
}

/* REUPLOAD baptism_verification = Reupload */
function reuploadBaptism(id){
  Swal.fire({
    icon: 'question',
    title: 'Request reupload of baptism certificate?',
    text: 'This will mark the baptism verification as "Reupload" and notify the member.',
    showCancelButton: true,
    confirmButtonText: 'Yes, mark as Reupload',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#f59e0b'
  }).then(res => {
    if (!res.isConfirmed) { Swal.close(); return; }

    Swal.fire({
      title: 'Saving...',
      allowOutsideClick: false,
      allowEscapeKey: false,
      didOpen: () => { Swal.showLoading(); }
    });

    fetch('admin-application.php', {
      method:'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({action:'reupload', id:String(id)})
    })
    .then(r => r.json())
    .then(j => {
      try { closeModal(); } catch(e) {}
      if (j.ok) {
        Swal.fire({
          icon: 'success',
          title: 'Reupload requested',
          text: 'Baptism verification has been set to "Reupload". Email notification sent (if possible).',
          timer: 1500,
          showConfirmButton: false,
          willClose: () => { window.location.reload(); }
        });
      } else {
        Swal.fire({
          icon:'error',
          title:'Error',
          text: j.msg || 'Database error',
          timer: 1500,
          showConfirmButton: false
        });
      }
    })
    .catch(() => {
      Swal.fire({ icon:'error', title:'Network error', timer: 1200, showConfirmButton: false });
    });
  });
}

/* ===== SORT BY (fixed choices, refined design) ===== */
(function(){
  const sortBtn = document.getElementById('sortBtn');
  const sortMenu = document.getElementById('sortMenu');
  const tableBody = document.querySelector('#appTable tbody');

  function getCellText(tr, col){
    const td = tr.children[col];
    return td ? (td.textContent || '').trim() : '';
  }

  // Fixed order for baptism verification status
  const statusOrder = { 'Verified': 1, 'Reupload': 2, 'Pending': 3 };

  function sortAlpha(colIndex){
    const trs = Array.from(tableBody.querySelectorAll('tr'));
    trs.sort((a,b)=>{
      const A = getCellText(a, colIndex);
      const B = getCellText(b, colIndex);
      return A.localeCompare(B, undefined, { sensitivity: 'base' });
    });
    trs.forEach(tr => tableBody.appendChild(tr));
  }

  function sortByStatus(){
    const trs = Array.from(tableBody.querySelectorAll('tr'));
    trs.sort((a,b)=>{
      const A = getCellText(a, 4);
      const B = getCellText(b, 4);
      return (statusOrder[A] ?? 999) - (statusOrder[B] ?? 999);
    });
    trs.forEach(tr => tableBody.appendChild(tr));
  }

  function toggleMenu(show){
    sortMenu.setAttribute('aria-hidden', show ? 'false' : 'true');
  }

  sortBtn.addEventListener('click', (e)=>{
    e.stopPropagation();
    const open = sortMenu.getAttribute('aria-hidden') === 'false';
    toggleMenu(!open);
  });

  document.addEventListener('click', ()=> toggleMenu(false));

  sortMenu.addEventListener('click', (e)=>{
    const btn = e.target.closest('button');
    if (!btn) return;
    const type = btn.getAttribute('data-type');
    if (type === 'alpha'){
      sortAlpha(parseInt(btn.getAttribute('data-col'), 10));
    } else if (type === 'status'){
      sortByStatus();
    }
    toggleMenu(false);
  });
})();

/* Image/PDF viewer fix: open PDFs in new tab, images in lightbox */
(function(){
  const originalOpenImg = window.openImg || function(src){
    const b = document.getElementById('imgBox');
    const i = document.getElementById('imgPreview');
    if (b && i) {
      i.src = src;
      b.setAttribute('aria-hidden','false');
    } else {
      if (src) { window.open(src, '_blank', 'noopener'); }
    }
  };

  window.openImg = function(src){
    if (!src) return;
    try {
      const clean = String(src).split('#')[0].split('?')[0].toLowerCase();
      if (clean.endsWith('.pdf')) {
        window.open(src, '_blank', 'noopener');
        return;
      }
    } catch (e) {}
    originalOpenImg(src);
  };
})();
</script>

</body>
</html>
