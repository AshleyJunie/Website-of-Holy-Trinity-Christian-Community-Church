<!doctype html>
<?php
include 'db-connection.php';
session_start();

/* Keep AJAX JSON clean — buffer anything accidental (warnings, stray output) */
ob_start();

/* ============================================================
   PHPMailer (manual, no Composer) — for Restrict notifications
============================================================ */
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* -------- JSON helper: send ONLY JSON & exit cleanly -------- */
function json_exit(array $data){
  while (ob_get_level() > 0) { ob_end_clean(); }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data);
  exit;
}

/* Send Restriction Email (copies your PHPMailer approach, silenced) */
function sendRestrictionEmail($toEmail, $toName, $reason) {
    $mail = new PHPMailer(true);
    try {
        // ======= SMTP CONFIG =======
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'holytrinitychristiancommunityc@gmail.com'; // your SMTP email
        $mail->Password   = 'jngx vtqb urun yjur';                      // app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;             // or ENCRYPTION_SMTPS
        $mail->Port       = 587;                                        // 465 if SMTPS
        // Keep things quiet & snappy
        $mail->SMTPDebug   = 0;
        $mail->Debugoutput = function(){};
        $mail->Timeout     = 12;
        $mail->CharSet     = 'UTF-8';
        // ============================

        // Use address aligned with SMTP account
        $mail->setFrom('holytrinitychristiancommunityc@gmail.com', 'HTCCC Admin Notice');
        $mail->addAddress($toEmail, $toName ?: $toEmail);

        // Compose email
        $mail->isHTML(true);
        $mail->Subject = 'Your HTCCC admin account has been restricted';
        $escapedName   = htmlspecialchars($toName ?: 'Admin', ENT_QUOTES, 'UTF-8');
        $escapedReason = nl2br(htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'));

        $mail->Body = '
          <div style="font-family:Arial,Helvetica,sans-serif;line-height:1.6;color:#111">
            <h2 style="margin:0 0 10px">Account Restriction Notice</h2>
            <p>Hi '.$escapedName.',</p>
            <p>Your <strong>HTCCC admin account</strong> has been set to <strong>Restrict</strong>.</p>
            <p><strong>Reason:</strong><br>'.$escapedReason.'</p>
            <p>If you believe this was made in error, please reply to this message or contact the system administrator.</p>
            <hr style="border:none;border-top:1px solid #eee;margin:16px 0">
            <p style="font-size:12px;color:#555">This is an automated notification from HTCCC System.</p>
          </div>
        ';
        $mail->AltBody = "Account Restriction Notice\n\n"
                       . "Hello {$toName},\n\n"
                       . "Your HTCCC admin account has been set to Restrict.\n"
                       . "Reason: {$reason}\n\n"
                       . "If this is a mistake, please contact the system administrator.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return "Mailer Error: ".$mail->ErrorInfo;
    }
}

/* ============================================================
   WHOLE EXISTING PAGE — admin_table (CLEANED: NO PASSWORDS)
============================================================ */

/* ---------- ADD DATA HANDLER (ADMIN TABLE) ---------- */
$add_success = false;
$error_msg   = '';
$old = [
  'admin_username'      => '',
  'admin_lastname'      => '',
  'admin_firstname'     => '',
  'admin_middlename'    => '',
  'admin_contactnumber' => '',
  'admin_emailaddress'  => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__action']) && $_POST['__action'] === 'add_admin') {
  $username   = trim($_POST['admin_username'] ?? '');
  $lastname   = trim($_POST['admin_lastname'] ?? '');
  $firstname  = trim($_POST['admin_firstname'] ?? '');
  $middlename = trim($_POST['admin_middlename'] ?? '');
  $contact    = trim($_POST['admin_contactnumber'] ?? '');
  $email      = trim($_POST['admin_emailaddress'] ?? '');

  $old = [
    'admin_username'      => $username,
    'admin_lastname'      => $lastname,
    'admin_firstname'     => $firstname,
    'admin_middlename'    => $middlename,
    'admin_contactnumber' => $contact,
    'admin_emailaddress'  => $email,
  ];

  // Basic required fields
  if ($username === '' || $lastname === '' || $firstname === '' || $contact === '' || $email === '') {
    $error_msg = 'Please complete all required fields.';
  } else {
    // Optional: ensure username uniqueness
    $exists = false;
    if ($st = mysqli_prepare($db_connection, "SELECT 1 FROM admin_table WHERE admin_username=? LIMIT 1")) {
      mysqli_stmt_bind_param($st, 's', $username);
      mysqli_stmt_execute($st);
      mysqli_stmt_store_result($st);
      $exists = (mysqli_stmt_num_rows($st) > 0);
      mysqli_stmt_close($st);
    }
    if ($exists) {
      $error_msg = 'Username is already taken.';
    } else {
      // Insert WITHOUT any password column.
      $sql = "INSERT INTO admin_table
              (admin_username, admin_lastname, admin_firstname, admin_middlename, admin_contactnumber, admin_emailaddress)
              VALUES (?, ?, ?, ?, ?, ?)";
      if ($stmt = mysqli_prepare($db_connection, $sql)) {
        mysqli_stmt_bind_param($stmt, "ssssss", $username, $lastname, $firstname, $middlename, $contact, $email);
        if (mysqli_stmt_execute($stmt)) {
          $add_success = true;
        } else {
          $error_msg = 'Database error while adding.';
        }
        mysqli_stmt_close($stmt);
      } else {
        $error_msg = 'Failed to prepare statement.';
      }
    }
  }
}

/* ============================================================
   AJAX ENDPOINTS (ADMIN TABLE)
============================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'admin_rows') {
  $rows = [];
  $q = "SELECT admin_id, admin_username, admin_lastname, admin_firstname, admin_middlename,
               admin_contactnumber, admin_emailaddress
        FROM admin_table
        ORDER BY admin_id DESC";
  if ($rs = mysqli_query($db_connection, $q)) {
    while ($r = mysqli_fetch_assoc($rs)) $rows[] = $r;
  }
  json_exit(['ok'=>true,'rows'=>$rows]);
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'admin_row' && isset($_GET['id'])) {
  $id  = (int)$_GET['id'];
  $row = null;
  if ($id > 0 && ($st = mysqli_prepare($db_connection, "
      SELECT admin_id, admin_username, admin_lastname, admin_firstname, admin_middlename,
             admin_contactnumber, admin_emailaddress
      FROM admin_table
      WHERE admin_id=? LIMIT 1
  "))) {
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);
  }
  json_exit(['ok'=> (bool)$row, 'row'=>$row]);
}

/* ============================================================
   HELPER
============================================================ */
function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

?>
<?php
/* ============================================================
   ==============  ADD BELOW THIS LINE — PHP  =================
   Password Reset Feature:
   - ajax=reset_password&id=  -> sets admin_password='1234' and password_request='No'
   Restrict Feature:
   - ajax=restrict_admin (POST: id, reason) -> sets admin_status='Restrict' and emails reason via PHPMailer
   ============================================================ */

/* Reset password */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'reset_password' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  if ($id > 0) {
    $new_pass = '1234'; // per request, raw string
    if ($st = mysqli_prepare($db_connection, "UPDATE admin_table SET admin_password=?, password_request='No' WHERE admin_id=? LIMIT 1")) {
      mysqli_stmt_bind_param($st, 'si', $new_pass, $id);
      $ok = mysqli_stmt_execute($st);
      mysqli_stmt_close($st);
      json_exit(['ok'=>$ok, 'msg'=>$ok ? "Password reset to '1234'." : 'Database update failed.']);
    }
  }
  json_exit(['ok'=>false, 'msg'=>'Invalid admin ID.']);
}

/* Restrict admin with reason + PHPMailer email */
if ((isset($_GET['ajax']) && $_GET['ajax'] === 'restrict_admin') && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $id     = (int)($_POST['id'] ?? 0);
  $reason = trim($_POST['reason'] ?? '');

  if (!$id)         json_exit(['ok'=>false,'msg'=>'Invalid admin ID.']);
  if ($reason === '') json_exit(['ok'=>false,'msg'=>'Please provide a reason.']);

  // Fetch admin info (for email)
  $email = null; $uname = null;
  if ($st = mysqli_prepare($db_connection, "SELECT admin_emailaddress, admin_username FROM admin_table WHERE admin_id=? LIMIT 1")) {
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    if ($res && ($row = mysqli_fetch_assoc($res))) {
      $email = $row['admin_emailaddress'] ?? null;
      $uname = $row['admin_username'] ?? ('Admin #'.$id);
    }
    mysqli_stmt_close($st);
  }

  // Update status first
  $ok = false; $err = null;
  if ($st = mysqli_prepare($db_connection, "UPDATE admin_table SET admin_status='Restrict' WHERE admin_id=? LIMIT 1")) {
    mysqli_stmt_bind_param($st, 'i', $id);
    $ok = mysqli_stmt_execute($st);
    $err = $ok ? null : mysqli_error($db_connection);
    mysqli_stmt_close($st);
  }

  // Attempt to email (best-effort)
  $mailInfo = null;
  if ($ok && $email) {
    $mailRes = sendRestrictionEmail($email, $uname, $reason);
    if ($mailRes !== true) { $mailInfo = $mailRes; }
  }

  $msg = $ok ? 'Admin restricted' : ('DB error: '.$err);
  if ($ok) {
    $msg .= $mailInfo ? (' — but email failed: '.$mailInfo) : ' and notified via email.';
  }
  json_exit(['ok'=>$ok, 'msg'=> $msg]);
}

/* ============================================================
   ADD BELOW THIS LINE — NEW ENDPOINTS FOR STATUS/UNRESTRICT
   ============================================================ */

/* NEW (Mail): Send Unrestrict Email (mirrors your SMTP setup) */
function sendUnrestrictEmail($toEmail, $toName) {
    $mail = new PHPMailer(true);
    try {
        // ======= SMTP CONFIG (same as restriction) =======
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'holytrinitychristiancommunityc@gmail.com';
        $mail->Password   = 'jngx vtqb urun yjur';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->SMTPDebug   = 0;
        $mail->Debugoutput = function(){};
        $mail->Timeout     = 12;
        $mail->CharSet     = 'UTF-8';
        // ================================================

        $mail->setFrom('holytrinitychristiancommunityc@gmail.com', 'HTCCC Admin Notice');
        $mail->addAddress($toEmail, $toName ?: $toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Your HTCCC admin account is now Active';
        $escapedName   = htmlspecialchars($toName ?: 'Admin', ENT_QUOTES, 'UTF-8');
        $mail->Body = '
          <div style="font-family:Arial,Helvetica,sans-serif;line-height:1.6;color:#111">
            <h2 style="margin:0 0 10px">Account Unrestricted</h2>
            <p>Hi '.$escapedName.',</p>
            <p>Your <strong>HTCCC admin account</strong> has been <strong>re-activated (Active)</strong>.</p>
            <p>You can sign in normally. If you encounter any issues, please reply to this email or contact the system administrator.</p>
            <hr style="border:none;border-top:1px solid #eee;margin:16px 0">
            <p style="font-size:12px;color:#555">This is an automated notification from HTCCC System.</p>
          </div>
        ';
        $mail->AltBody = "Account Unrestricted\n\n"
                       . "Hello {$toName},\n\n"
                       . "Your HTCCC admin account has been re-activated (Active).\n"
                       . "You can sign in normally. If you encounter issues, contact the system administrator.\n";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return "Mailer Error: ".$mail->ErrorInfo;
    }
}

/* NEW: Fetch statuses for all admins to adjust buttons client-side */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'admin_statuses') {
  $rows = [];
  $q = "SELECT admin_id, COALESCE(admin_status,'Active') AS admin_status FROM admin_table ORDER BY admin_id DESC";
  if ($rs = mysqli_query($db_connection, $q)) {
    while ($r = mysqli_fetch_assoc($rs)) { $rows[] = $r; }
  }
  json_exit(['ok'=>true, 'rows'=>$rows]);
}

/* NEW: Unrestrict admin (set status to Active) — with EMAIL */
if ((isset($_GET['ajax']) && $_GET['ajax'] === 'unrestrict_admin') && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = (int)($_POST['id'] ?? 0);
  if (!$id) json_exit(['ok'=>false,'msg'=>'Invalid admin ID.']);

  // Fetch admin info (email + name) first
  $email = null; $uname = null;
  if ($st = mysqli_prepare($db_connection, "SELECT admin_emailaddress, admin_username FROM admin_table WHERE admin_id=? LIMIT 1")) {
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    if ($res && ($row = mysqli_fetch_assoc($res))) {
      $email = $row['admin_emailaddress'] ?? null;
      $uname = $row['admin_username'] ?? ('Admin #'.$id);
    }
    mysqli_stmt_close($st);
  }

  // Update to Active
  $ok = false; $err = null;
  if ($st = mysqli_prepare($db_connection, "UPDATE admin_table SET admin_status='Active' WHERE admin_id=? LIMIT 1")) {
    mysqli_stmt_bind_param($st, 'i', $id);
    $ok = mysqli_stmt_execute($st);
    $err = $ok ? null : mysqli_error($db_connection);
    mysqli_stmt_close($st);
  }

  // Best-effort email
  $mailInfo = null;
  if ($ok && $email) {
    $mailRes = sendUnrestrictEmail($email, $uname);
    if ($mailRes !== true) { $mailInfo = $mailRes; }
  }

  $msg = $ok ? 'Admin status set to Active' : ('DB error: '.$err);
  if ($ok) {
    $msg .= $mailInfo ? (' — but email failed: '.$mailInfo) : ' and notified via email.';
  }
  json_exit(['ok'=>$ok,'msg'=>$msg]);
}
/* ========================= END ADDITIONS (PHP) ======================== */
?>

<html lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1"/>
<title>Admin – Accounts</title>

<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
/* -------------- Base -------------- */
:root{
  --bg:#f6f9fc;
  --muted:#6b7280;
  --ink:#0f172a;
  --primary:#0ea5e9;
  --panel:#ffffff;
  --line:#e6edf5;
  --nav:#00216e;        /* solid blue sidebar */
  --navmuted:#9aa4b2;
  --shadow:0 10px 30px rgba(16,24,40,.08);
}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;background:var(--bg);color:var(--ink);font:500 16px/1.5 ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, 'Helvetica Neue', Arial}

/* -------------- Layout -------------- */
.sidebar{position:fixed; inset:0 auto 0 0; width:280px; background:var(--nav); color:#fff; display:flex; flex-direction:column; padding:18px 16px; overflow-y:auto}
.page{ margin-left:280px; padding:18px 22px 40px; }
.topbar{display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; background-color:whitesmoke}
.topbar h1{ font-size:20px; margin:0; }
.top-actions{ display:flex; align-items:center; gap:14px; }

/* -------------- Sidebar -------------- */
.brand{ display:flex; align-items:center; gap:10px; font-weight:800; letter-spacing:.5px; }
.brand img{ width:26px; height:26px; border-radius:6px; }
.user-card{ display:flex; gap:12px; align-items:center; padding:12px 8px; background:rgba(255,255,255,.05); border-radius:12px; margin:14px 0; }
.user-card img{ width:40px; height:40px; border-radius:999px; }
.user-title{ font-weight:700 }
.user-sub{ font-size:12px; color:#cbd5e1 }
.nav{ display:flex; flex-direction:column; gap:6px; }
.section-title{ margin-top:12px; margin-bottom:6px; font-size:11px; letter-spacing:.08em; color:#ffffff; text-transform:uppercase }
.navlink{ display:flex; align-items:center; gap:10px; color:#e2e8f0; text-decoration:none; padding:10px 12px; border-radius:10px; }
.navlink i{ width:16px; text-align:center; }
.navlink:hover{ background:rgba(255,255,255,.06) }
.navlink.active{ background:#1f2937; color:#fff }
.navlink.logout{ color:#fca5a5 }

/* -------------- Cards/Table -------------- */
.card{ background:var(--panel); border:1px solid var(--line); border-radius:16px; padding:0; box-shadow:var(--shadow); }
.js-table-scroll-wrap{overflow-y:auto;width:100%}
.table{width:100%; border-collapse:collapse; background:var(--panel); border-top:1px solid var(--line)}
.table th{font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:#475569; background:#f8fafc}
.table th,.table td{padding:12px 14px; border-bottom:1px solid var(--line)}
.table tr:last-child td{border-bottom:0}
.text-right{text-align:right}
.badge-id{display:inline-block; background:#e8eefc; color:#0b2259; padding:2px 10px; border-radius:999px; font-size:12px; font-weight:800}
.inline-edit{background:#10b981;color:#fff;border:0;border-radius:10px;padding:8px 12px;cursor:pointer}
.decline{background:#e5e7eb;color:#111827;border:0;border-radius:10px;padding:8px 12px;cursor:pointer} /* pointer on Restrict */

/* -------------- Floating Modal (Add/Edit) -------------- */
.floating-modal-overlay{ position:fixed; inset:0; background:rgba(17,24,39,.55); display:none; align-items:center; justify-content:center; z-index:9999; backdrop-filter:saturate(120%) blur(2px) }
.floating-modal-overlay[aria-hidden="false"]{ display:flex; }
.floating-modal{ width:min(860px, 94vw); max-height:92vh; overflow:auto; background:#fff; border-radius:16px; box-shadow:0 20px 40px rgba(0,0,0,.2); animation:pop .18s ease-out; color:#111827; position:relative }
@keyframes pop { from{transform:translateY(8px) scale(.98); opacity:0} to{transform:none; opacity:1} }
.fm-header{ display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #eef2f7; position:sticky; top:0; background:#fff; border-radius:16px 16px 0 0; z-index:2 }
.fm-title{font-size:18px; font-weight:700; display:flex; gap:10px; align-items:center}
.fm-body{padding:18px 20px 6px}
.fm-footer{ display:flex; gap:10px; justify-content:flex-end; padding:14px 20px 18px; border-top:1px solid #eef2f7; position:sticky; bottom:0; background:#fff; border-radius:0 0 16px 16px }
.section-card{border:1px solid #eef2f7; border-radius:12px; padding:14px; margin-bottom:12px; background:#fcfcfd}
.section-title{font-weight:800; font-size:14px; margin-bottom:8px}
.muted{color:#64748b; font-size:12px}
.fm-field{display:flex; flex-direction:column; gap:8px; margin-bottom:10px}
.fm-label{font-size:12px; font-weight:700; letter-spacing:.02em; color:#6b7280; text-transform:uppercase}
.fm-textarea{ min-height:auto; padding:12px 14px; line-height:1.45; border:1px solid #e5e7eb; border-radius:12px; font-size:14px; outline:none; }

/* Buttons */
.btn{ border:0; border-radius:999px; padding:10px 16px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:8px }
.btn.primary{background:#0ea5e9; color:#fff}
.btn.secondary{background:#eef2f7; color:#374151}
.btn.ghost{background:transparent; color:#111827; border:1px dashed #cbd5e1}
.btn:disabled{opacity:.6; cursor:not-allowed}

/* Responsive */
@media (max-width:920px){
  .sidebar{ width:240px }
  .page{ margin-left:240px }
}
@media (max-width:720px){
  .sidebar{ position:static; width:auto }
  .page{ margin:0 }
}

/* Minor CSS helper */
.reset-spacer{ margin-right:auto; }
</style>
</head>
<body>

<!-- ============= SIDEBAR / NAVBAR ============= -->
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
      <a class="navlink active" href="pastor-dashboard.php"><i class="fas fa-home"></i>Dashboard</a>
      <div class="section-title">Online Requests</div>
      <a class="navlink" href="admin-schedule-request.php"><i class="fas fa-calendar-plus"></i>Schedule Requests</a>
      <a class="navlink" href="admin-prayer-request.php"><i class="fas fa-praying-hands"></i><span>Prayer Requests</span></a>
      <div class="section-title">Online Applications</div>
      <a class="navlink" href="baptismal_application.php"><i class="fas fa-water"></i>Baptismal Applications</a>
      <a class="navlink" href="admin-application.php"><i class="fas fa-user-cog"></i>Baptsimal Account Verification</a>
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
      <a class="navlink" href="admin-ministry-women.php"><i class="fas fa-female"></i>Handmaid's of the Lord</a>
      <a class="navlink" href="admin-ministry-men.php"><i class="fas fa-male"></i>Men's Ministry</a>
      <a class="navlink" href="admin-ministry-music.php"><i class="fas fa-music"></i>Mzusic Ministry</a>
      <a class="navlink" href="admin-ministry-usher.php"><i class="fas fa-hands-helping"></i>Usher &amp; Usherette</a>
      <div class="section-title">Reports</div>
      <a class="navlink" href="admin-reports.php"><i class="fas fa-file-alt"></i>Reports</a>
      <div class="section-title">Content</div>
      <a class="navlink" href="content-management_home-page.php"><i class="fas fa-edit"></i>Content Management</a>
      <div class="section-title">Certificates</div>
      <a class="navlink" href="certificate-table.php"><i class="fas fa-award"></i>Generate Certificate</a>
      <div class="section-title">Account</div>
      <a class="navlink" href="admin-account-settings.php"><i class="fas fa-user-shield"></i>Account Settings</a>
      <div class="section-title">Management</div>
      <a class="navlink" href="pastor-audittrails.php"><i class="fa fa-file"></i> Audit Trails</a>
      <a class="navlink active" href="pastor-admin-accounts.php"><i class="fas fa-user"></i>Admin Accounts</a>
      <div class="section-title">More</div>
      <a class="navlink logout" href="all_log-in.php"><i class="fas fa-sign-out-alt"></i>Log Out</a>
    </nav>
  </aside>

<!-- ============= MAIN CONTENT ============= -->
<div class="page">
  <header class="topbar">
    <h1>Admin Accounts</h1>
    <div class="top-actions">
      <button id="openAddModal" class="btn primary">Add +</button>
    </div>
  </header>

  <div class="card">
    <!-- Search -->
    <div style="display:flex; gap:10px; align-items:center; padding:12px 14px; border-bottom:1px solid var(--line); background:#fbfdff">
      <input type="text" placeholder="🔍 Search Admin..." class="search-box" aria-label="Search" style="flex:1; border:1px solid var(--line); border-radius:12px; padding:10px 12px; background:#fff">
    </div>

    <!-- Table -->
    <div class="js-table-scroll-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Lastname</th>
            <th>Firstname</th>
            <th>Middlename</th>
            <th>Contact #</th>
            <th>Email</th>
            <th class="text-right">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php
          // Include password_request so we can show Reset button without extra fetch
          $query = "SELECT admin_id, admin_username, admin_lastname, admin_firstname, admin_middlename, admin_contactnumber, admin_emailaddress, password_request
                    FROM admin_table
                    ORDER BY admin_id DESC";
          $res = mysqli_query($db_connection, $query);
          if ($res && mysqli_num_rows($res) > 0) {
            while ($r = mysqli_fetch_assoc($res)) {
              $id = (int)$r['admin_id'];
              $needReset = ($r['password_request'] ?? 'No') === 'Yes';
              echo "<tr>
                <td><span class='badge-id'>#{$id}</span></td>
                <td>".h($r['admin_username'])."</td>
                <td>".h($r['admin_lastname'])."</td>
                <td>".h($r['admin_firstname'])."</td>
                <td>".h($r['admin_middlename'])."</td>
                <td>".h($r['admin_contactnumber'])."</td>
                <td>".h($r['admin_emailaddress'])."</td>
                <td class='text-right'>";
              if ($needReset) {
                echo " <button type='button' class='inline-edit js-reset-pass' data-id='{$id}' style='background:#f59e0b' title='Reset password to 1234'><i class=\"fas fa-undo\"></i> Reset</button>";
              }
              echo "   <button class='decline js-restrict' type='button' data-id='{$id}' title='Set status to Restrict'><i class=\"fas fa-user-slash\"></i> Restrict</button>
                </td>
              </tr>";
            }
          } else {
            echo "<tr><td colspan='8' class='empty'>No admin records found.</td></tr>";
          }
          ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ============= ADD MODAL (NO PASSWORD FIELDS) ============= -->
<div class="floating-modal-overlay" id="addModal" aria-hidden="true">
  <div class="floating-modal" role="dialog" aria-modal="true" aria-labelledby="addModalTitle">
    <div class="fm-header">
      <div class="fm-title"><i class="fas fa-user-plus"></i> <span id="addModalTitle">Add Admin Account</span></div>
      <button class="btn ghost" id="closeAdd" type="button" aria-label="Close">Close</button>
    </div>

    <form method="post" id="addForm" autocomplete="off">
      <input type="hidden" name="__action" value="add_admin">
      <div class="fm-body">
        <div class="section-card">
          <div class="section-title">Account</div>
          <div class="grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
            <div class="fm-field">
              <label class="fm-label" for="admin_username">Username</label>
              <input type="text" id="admin_username" name="admin_username" required value="<?php echo h($old['admin_username']); ?>" class="fm-textarea">
            </div>
            <div class="fm-field">
              <label class="fm-label" for="admin_contactnumber">Contact #</label>
              <input type="text" id="admin_contactnumber" name="admin_contactnumber" required value="<?php echo h($old['admin_contactnumber']); ?>" class="fm-textarea">
            </div>
          </div>
        </div>

        <div class="section-card">
          <div class="section-title">Personal</div>
          <div class="grid-3" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
            <div class="fm-field">
              <label class="fm-label" for="admin_lastname">Lastname</label>
              <input type="text" id="admin_lastname" name="admin_lastname" required value="<?php echo h($old['admin_lastname']); ?>" class="fm-textarea">
            </div>
            <div class="fm-field">
              <label class="fm-label" for="admin_firstname">Firstname</label>
              <input type="text" id="admin_firstname" name="admin_firstname" required value="<?php echo h($old['admin_firstname']); ?>" class="fm-textarea">
            </div>
            <div class="fm-field">
              <label class="fm-label" for="admin_middlename">Middlename</label>
              <input type="text" id="admin_middlename" name="admin_middlename" value="<?php echo h($old['admin_middlename']); ?>" class="fm-textarea">
            </div>
          </div>
        </div>

        <div class="section-card">
          <div class="section-title">Contact</div>
          <div class="grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
            <div class="fm-field">
              <label class="fm-label" for="admin_emailaddress">Email</label>
              <input type="email" id="admin_emailaddress" name="admin_emailaddress" required value="<?php echo h($old['admin_emailaddress']); ?>" class="fm-textarea">
            </div>
            <div class="fm-field">
              <label class="fm-label">&nbsp;</label>
              <div class="muted">No password is stored or displayed on this page.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="fm-footer">
        <button type="button" class="btn secondary" id="cancelAdd">Cancel</button>
        <button type="button" class="btn primary" id="confirmAdd">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
/* ===== Add Modal Controls ===== */
const addModal  = document.getElementById('addModal');
const openAdd   = document.getElementById('openAddModal');
const closeAdd  = document.getElementById('closeAdd');
const cancelAdd = document.getElementById('cancelAdd');
const addForm   = document.getElementById('addForm');
function showAdd(){ addModal.setAttribute('aria-hidden','false'); }
function hideAdd(){ addModal.setAttribute('aria-hidden','true'); }
openAdd && (openAdd.onclick = showAdd);
closeAdd && (closeAdd.onclick = hideAdd);
cancelAdd && (cancelAdd.onclick = hideAdd);
addModal && (addModal.onclick = (e)=>{ if(e.target===addModal) hideAdd(); });

/* Confirm add with validation (no password checks) */
document.getElementById('confirmAdd')?.addEventListener('click', function(){
  const requiredIds = ['admin_username','admin_lastname','admin_firstname','admin_contactnumber','admin_emailaddress'];
  for (const id of requiredIds){
    const el = document.getElementById(id);
    if (!el || !el.value.trim()){
      Swal.fire({ title: "Missing field", text: "Please complete all required fields.", icon: "warning" });
      el?.focus();
      return;
    }
  }
  Swal.fire({
    title: "Confirm Add?",
    text: "Add this admin account?",
    icon: "question",
    showCancelButton: true,
    confirmButtonColor: "#0ea5e9",
    cancelButtonColor: "#d33",
    confirmButtonText: "Yes, Add"
  }).then((result) => { if(result.isConfirmed){ addForm.submit(); } });
});

/* After server processing alerts */
<?php if ($add_success): ?>
Swal.fire({ title: "Added Successfully!", text: "New admin has been added.", icon: "success", confirmButtonColor: "#0ea5e9" })
.then(()=>{ window.location = window.location.pathname; });
<?php elseif ($error_msg): ?>
showAdd();
Swal.fire({ title: "Error", text: "<?php echo addslashes($error_msg); ?>", icon: "error", confirmButtonColor: "#0ea5e9" });
<?php endif; ?>

/* Search */
document.querySelector('.search-box')?.addEventListener('input', function(){
  const q = this.value.toLowerCase();
  document.querySelectorAll('tbody tr').forEach(tr=>{
    tr.style.display = tr.innerText.toLowerCase().includes(q) ? '' : 'none';
  });
});

/* Scroll limit */
(function(){
  function apply(){
    const wrap = document.querySelector('.js-table-scroll-wrap');
    const table = document.querySelector('.table');
    if(!wrap||!table) return;
    const headH = table.tHead ? table.tHead.getBoundingClientRect().height : 44;
    const row = table.tBodies[0]?.rows[0];
    const rowH = row ? row.getBoundingClientRect().height : 44;
    wrap.style.maxHeight = (headH + (rowH * 9) + 2) + 'px';
  }
  window.addEventListener('load', ()=>{ apply(); setTimeout(apply,120); setTimeout(apply,300); });
  window.addEventListener('resize', apply);
})();

/* =============== Safe JSON helper for fetch =============== */
async function parseJsonOrThrow(resp){
  const text = await resp.text();
  try { return JSON.parse(text); }
  catch (e){
    console.error('Raw response (not JSON):', text);
    throw new Error('Bad JSON');
  }
}

/* =========================
   Actions — Reset + Restrict (reason + email)
   ========================= */
document.addEventListener('click', function(e){
  // RESET
  const resetBtn = e.target.closest('.js-reset-pass');
  if(resetBtn){
    const id = resetBtn.getAttribute('data-id');
    if(!id) return;
    Swal.fire({
      title: 'Reset Password?',
      text: "This will set the user\'s password to '1234' and mark request as handled.",
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#0ea5e9',
      cancelButtonColor: '#d33',
      confirmButtonText: 'Yes, reset'
    }).then(res=>{
      if(res.isConfirmed){
        fetch(window.location.pathname + '?ajax=reset_password&id=' + encodeURIComponent(id))
          .then(parseJsonOrThrow)
          .then(resp=>{
            if(resp && resp.ok){
              Swal.fire('Success', resp.msg, 'success').then(()=>window.location.reload());
            }else{
              Swal.fire('Error', (resp && resp.msg) || 'Reset failed.', 'error');
            }
          })
          .catch(()=> Swal.fire('Network error','Please try again.','error'));
      }
    });
    return;
  }

  // RESTRICT with reasons + “Other”
  const restrictBtn = e.target.closest('.js-restrict');
  if(restrictBtn){
    const id = restrictBtn.getAttribute('data-id');
    if(!id) return;

    const html = `
      <div style="text-align:left">
        <p class="muted" style="margin-bottom:8px">Select a reason for restricting this admin:</p>
        <div style="display:grid; gap:8px">
          <label><input type="radio" name="restrict_reason" value="Multiple failed login attempts"> Multiple failed login attempts</label>
          <label><input type="radio" name="restrict_reason" value="Security policy violation"> Security policy violation</label>
          <label><input type="radio" name="restrict_reason" value="Account under review"> Account under review</label>
          <label><input type="radio" name="restrict_reason" value="Suspected unauthorized access"> Suspected unauthorized access</label>
          <label><input type="radio" name="restrict_reason" value="Requested by owner/HR"> Requested by owner/HR</label>
          <label>
            <input type="radio" name="restrict_reason" value="__other"> Other:
            <input type="text" id="restrict_other" class="fm-textarea" placeholder="Type reason..." style="display:block; margin-top:6px">
          </label>
        </div>
      </div>`;

    Swal.fire({
      title: 'Restrict this admin?',
      html,
      focusConfirm: false,
      showCancelButton: true,
      confirmButtonColor: '#0ea5e9',
      cancelButtonColor: '#d33',
      confirmButtonText: 'Yes, restrict',
      preConfirm: () => {
        const selected = document.querySelector('input[name="restrict_reason"]:checked');
        let reasonVal = selected ? selected.value : '';
        if (reasonVal === '__other') {
          reasonVal = (document.getElementById('restrict_other')?.value || '').trim();
        }
        if (!reasonVal) {
          Swal.showValidationMessage('Please select or enter a reason.');
        }
        return reasonVal;
      }
    }).then(res=>{
      if(res.isConfirmed){
        const reason = res.value;
        const body = new URLSearchParams({ id, reason });
        fetch(window.location.pathname + '?ajax=restrict_admin', {
          method: 'POST',
          headers: {'Content-Type':'application/x-www-form-urlencoded'},
          body
        })
        .then(parseJsonOrThrow)
        .then(resp=>{
          if(resp && resp.ok){
            Swal.fire('Done', resp.msg, 'success').then(()=>window.location.reload());
          }else{
            Swal.fire('Error', (resp && resp.msg) || 'Action failed.', 'error');
          }
        })
        .catch(()=> Swal.fire('Network error','Please try again.','error'));
      }
    });
  }
});
</script>

<!-- =====================
     ADD BELOW THIS LINE — JS (Unrestrict UX)
     ===================== -->
<script>
// On load: fetch admin statuses and flip buttons to "Unrestrict" for restricted accounts
window.addEventListener('load', () => {
  fetch(window.location.pathname + '?ajax=admin_statuses')
    .then(parseJsonOrThrow)
    .then(resp => {
      if (!resp || !resp.ok || !Array.isArray(resp.rows)) return;
      resp.rows.forEach(({admin_id, admin_status}) => {
        if (String(admin_status).toLowerCase() === 'restrict') {
          const btn = document.querySelector(`.js-restrict[data-id="${admin_id}"]`);
          if (btn) {
            btn.classList.remove('js-restrict');
            btn.classList.add('js-unrestrict');
            btn.title = 'Set status to Active';
            btn.innerHTML = '<i class="fas fa-unlock"></i> Unrestrict';
          }
        }
      });
    })
    .catch(() => {/* silent */});
});

// Separate listener for Unrestrict (add-only; leaves original handler untouched)
document.addEventListener('click', function(e){
  const unrestrictBtn = e.target.closest('.js-unrestrict');
  if(!unrestrictBtn) return;
  const id = unrestrictBtn.getAttribute('data-id');
  if(!id) return;

  Swal.fire({
    title: 'Unrestrict this admin?',
    text: "This will set the admin's status to Active.",
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#0ea5e9',
    cancelButtonColor: '#d33',
    confirmButtonText: 'Yes, unrestrict'
  }).then(res=>{
    if(res.isConfirmed){
      const body = new URLSearchParams({ id });
      fetch(window.location.pathname + '?ajax=unrestrict_admin', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body
      })
      .then(parseJsonOrThrow)
      .then(resp=>{
        if(resp && resp.ok){
          Swal.fire('Done', resp.msg, 'success').then(()=>window.location.reload());
        }else{
          Swal.fire('Error', (resp && resp.msg) || 'Action failed.', 'error');
        }
      })
      .catch(()=> Swal.fire('Network error','Please try again.','error'));
    }
  });
});
</script>

</body>
</html>
