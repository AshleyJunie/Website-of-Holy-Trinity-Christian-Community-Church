<?php
include 'db-connection.php';

/* ---------- PHPMailer (SMTP via Gmail) ---------- */
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';
require __DIR__ . '/PHPMailer/src/Exception.php';

/** Send approval/decline email */
function sendDecisionEmail($toEmail, $toName, $status, $reason = null) {
  $mail = new PHPMailer(true);
  try {
    // SMTP
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS
    $mail->Port       = 587;
    $mail->Username   = 'holytrinitychristiancommunityc@gmail.com';
    $mail->Password   = 'jngx vtqb urun yjur'; // Gmail App Password

    // From/To
    $mail->setFrom('holytrinitychristiancommunityc@gmail.com', 'HTCCC Verification');
    $mail->addAddress($toEmail, $toName ?: $toEmail);

    // Content
    $mail->isHTML(true);

    if ($status === 'approved') {
      $mail->Subject = 'HTCCC Account Approved';
      $body = "
        <div style='font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6'>
          <p>Hi <b>".htmlspecialchars($toName ?: 'Member')."</b>,</p>
          <p>Your account verification at <b>Holy Trinity Christian Community Church (HTCCC)</b> has been <b style='color:#16a34a'>APPROVED</b>.</p>
          <p>You may now <b>sign in</b> and <b>book an appointment</b> for our services.</p>
          <p>Thank you and God bless!</p>
          <hr>
          <p style='color:#6b7280'>This is an automated message. Please do not reply.</p>
        </div>";
    } else {
      $mail->Subject = 'HTCCC Account Verification Update';
      $reasonSafe = $reason ? nl2br(htmlspecialchars($reason)) : 'No reason provided.';
      $body = "
        <div style='font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6'>
          <p>Hi <b>".htmlspecialchars($toName ?: 'Member')."</b>,</p>
          <p>Your account verification at <b>Holy Trinity Christian Community Church (HTCCC)</b> has been <b style='color:#dc2626'>DECLINED</b>.</p>
          <p><b>Reason:</b><br>$reasonSafe</p>
          <p>You may update your details and re-apply. If you need assistance, kindly contact us.</p>
          <hr>
          <p style='color:#6b7280'>This is an automated message. Please do not reply.</p>
        </div>";
    }

    $mail->Body = $body;
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

/* Return URL path rooted at the app folder (e.g., /HTCCC-SYSTEM) */
function project_base_path(){
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  $base   = rtrim(str_replace('\\','/', dirname($script)), '/');
  return $base === '' ? '/' : $base;
}

/* Turn a stored DB relative path into a URL under the app */
function to_url($v){
  if(!$v) return '';
  if (preg_match('~^https?://~i',$v)) return $v; // absolute stays absolute
  $v = ltrim($v, '/');
  return project_base_path() . '/' . $v; // -> /HTCCC-SYSTEM/uploads/...
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
    <h3>Applicant Details</h3>
    <button class="modal-close" onclick="closeModal()">×</button>
  </div>
  <div class="modal-body">
    <div class="kv"><span>Lastname</span><b><?=h($row['individual_lastname'])?></b></div>
    <div class="kv"><span>Firstname</span><b><?=h($row['individual_firstname'])?></b></div>
    <div class="kv"><span>Middlename</span><b><?=h($row['individual_middlename'])?></b></div>
    <div class="kv"><span>Gender</span><b><?=h($row['individual_gender'])?></b></div>
    <div class="kv"><span>Birthday</span><b><?=h($row['individual_birthday'])?></b></div>
    <div class="kv"><span>Phone</span><b><?=h($row['individual_phone_number'])?></b></div>
    <div class="kv"><span>Email</span><b><?=h($row['individual_email_address'])?></b></div>
    <div class="kv"><span>Address</span>
      <b><?=h(trim(($row['individual_street']??'').' '.$row['individual_city'].' '.($row['individual_zip_code']??'')))?></b>
    </div>
    <div class="kv"><span>Baptised</span><b><?=h($row['individual_baptised'])?></b></div>

    <?php
      $selfiePath = $row['img_file_path']      ?? '';
      $validPath  = $row['img_valid_id']       ?? '';
      $bapPath    = $row['img_baptismal_cert'] ?? '';
    ?>

    <!-- Selfie with ID -->
    <div class="kv">
      <span>Selfie with ID</span>
      <b>
        <?php
          if ($selfiePath) {
            $u = h(to_url($selfiePath));
            echo is_image_path($selfiePath)
              ? '<button class="btn tiny" onclick="openImg(\''.$u.'\')"><i class="fas fa-image"></i> View image</button>'
              : '<a class="btn tiny ghost" href="'.$u.'" target="_blank" rel="noopener"><i class="fas fa-file"></i> Open file</a>';
          } else {
            echo '—';
          }
        ?>
      </b>
    </div>

    <!-- Valid Government ID -->
    <div class="kv">
      <span>Valid Government ID</span>
      <b>
        <?php
          if ($validPath) {
            $u = h(to_url($validPath));
            echo is_image_path($validPath)
              ? '<button class="btn tiny" onclick="openImg(\''.$u.'\')"><i class="fas fa-image"></i> View image</button>'
              : '<a class="btn tiny ghost" href="'.$u.'" target="_blank" rel="noopener"><i class="fas fa-file"></i> Open file</a>';
          } else {
            echo '—';
          }
        ?>
      </b>
    </div>

    <!-- Baptismal Certificate: always open image using DB path -->
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
    <button class="btn success" onclick="approveMember(<?= (int)$row['individual_id'] ?>)"><i class="fas fa-check"></i> Approve</button>
    <button class="btn danger"  onclick="declineMember(<?= (int)$row['individual_id'] ?>)"><i class="fas fa-times"></i> Decline</button>
    <button class="btn ghost"   onclick="closeModal()">Close</button>
  </div>
  <?php
  echo ob_get_clean();
  exit;
}

/* ---------- AJAX: approve / decline ---------- */
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

  if ($_POST['action'] === 'approve') {
    $sql = "UPDATE individual_table SET individual_baptised='Yes' WHERE individual_id=? LIMIT 1";
    $st  = mysqli_prepare($db_connection, $sql);
    mysqli_stmt_bind_param($st,'i',$id);
    $ok  = mysqli_stmt_execute($st);
    mysqli_stmt_close($st);

    $email_ok = null; $email_err = null;
    if ($ok && $info['email']) {
      [$email_ok, $email_err] = sendDecisionEmail($info['email'], $info['name'], 'approved');
    }
    echo json_encode([
      'ok'   => $ok ? true : false,
      'msg'  => $ok ? 'Updated to Yes' : 'DB error',
      'email_ok'  => $email_ok,
      'email_err' => $email_err
    ]);
    exit;

  } elseif ($_POST['action'] === 'decline') {
    $reason = trim($_POST['reason'] ?? '');
    $email_ok = null; $email_err = null;
    if ($info['email']) {
      [$email_ok, $email_err] = sendDecisionEmail($info['email'], $info['name'], 'declined', $reason);
    }
    echo json_encode([
      'ok'   => true,
      'msg'  => 'Declined (no DB changes).',
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
    /* Minimal modal + lightbox (matches your theme) */
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
    .btn.success{background:#16a34a;color:#fff}
    .btn.danger{background:#dc2626;color:#fff}
    .btn.tiny{padding:6px 10px;font-size:12px}

    .imgbox{position:fixed;inset:0;background:rgba(0,0,0,.6);display:none;align-items:center;justify-content:center;z-index:10000;padding:1rem}
    .imgbox[aria-hidden="false"]{display:flex}
    .imgbox-inner{position:relative;max-width:96vw;max-height:92vh;background:#000;border-radius:12px;overflow:hidden}
    .imgbox img{display:block;max-width:96vw;max-height:92vh}
    .imgbox-close{position:absolute;top:8px;right:8px;background:rgba(255,255,255,.2);color:#fff;border:0;border-radius:8px;padding:6px 10px;cursor:pointer}
  </style>

  <!-- ADDED: Smart Search small CSS (non-breaking) -->
  <style>
    .smart-help{position:relative;display:inline-block;margin-left:8px;font-size:12px;color:#334155;cursor:pointer}
    .smart-help .bubble{
      position:absolute;right:0;top:130%;
      background:#0f1720;color:#e5efff;border:1px solid rgba(255,255,255,.15);
      padding:10px 12px;border-radius:10px;width:min(440px,92vw);display:none;z-index:50;
      box-shadow:0 10px 30px rgba(0,0,0,.25)
    }
    .smart-help:hover .bubble{display:block}
    mark.smart-hit{background:#ffe08a;color:#111;padding:0 .12em;border-radius:4px}
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
      <div class="user-title">Pastor</div>
      <div class="user-sub">Dashboard</div>
    </div>
  </div>

  <nav class="nav">
    <div class="section-title">Main</div>
    <a class="navlink" href="secretary_dashboard.php"><i class="fas fa-th-large"></i>Dashboard</a>

    <div class="section-title">Requests</div>
    <a class="navlink" href="pastor-admin-request.php">
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
    <a class="navlink active" href="pastor-user-application.php">
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
    <a class="navlink" href="content-management_home-page.php">
      <i class="fas fa-edit"></i> Audit Trails
    </a>

    <div class="section-title">More</div>
    <a class="navlink logout" href="all_log-in.php">
      <img alt="Logout" class="icon" src="image/logo-logout.png" width="18" height="18" style="vertical-align:middle;margin-right:8px;">
      Log Out
    </a>
  </nav>
</aside>

<!-- ============== MAIN PAGE CONTENT ============== -->
<div class="page">
  <header class="topbar">
    <h1>User Application List</h1>
  </header>

  <div class="container">
    <div class="top-bar">
      <input type="text" id="searchBox" placeholder="🔍 Search" class="search-box">
      <button class="sort-button">Sort by:</button>
    </div>

    <table id="appTable">
      <thead>
        <tr>
          <th>Lastname</th>
          <th>Firstname</th>
          <th>Contact Number</th>
          <th>Address</th>
          <th style="text-align:center;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $q = "SELECT individual_id, individual_lastname, individual_firstname, individual_phone_number,
                     individual_street, individual_city, individual_zip_code
              FROM individual_table
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
            echo '<td style="text-align:center">
                    <button class="view" onclick="viewMember('.(int)$row['individual_id'].')"><i class="fas fa-eye"></i> View</button>
                  </td>';
            echo '</tr>';
          }
        } else {
          echo '<tr><td colspan="5">No records found.</td></tr>';
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

/* approve with SweetAlert confirm */
function approveMember(id){
  Swal.fire({
    icon: 'question',
    title: 'Approve this account?',
    text: 'They will receive an email and can now book services.',
    showCancelButton: true,
    confirmButtonText: 'Yes, approve',
    cancelButtonText: 'Cancel'
  }).then(res => {
    if (!res.isConfirmed) { Swal.close(); return; }

    // compact loading state that auto-disappears later
    Swal.fire({
      title: 'Approving...',
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
      // close detail modal now so we don't see stale content
      try { closeModal(); } catch(e) {}

      if (j.ok) {
        // success toast → auto-close → reload
        Swal.fire({
          icon: 'success',
          title: 'Approved',
          text: 'Baptised set to Yes.',
          timer: 1100,
          showConfirmButton: false,
          willClose: () => { window.location.reload(); }   // <— force restart
        });
      } else {
        Swal.fire({
          icon: 'error',
          title: 'Error',
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

// DECLINE (with reason, auto-close + reload)
function declineMember(id){
  Swal.fire({
    icon: 'warning',
    title: 'Decline this account?',
    input: 'textarea',
    inputLabel: 'Reason (optional)',
    inputPlaceholder: 'e.g., ID not readable, please re-upload a clearer copy…',
    showCancelButton: true,
    confirmButtonText: 'Yes, decline',
    cancelButtonText: 'Cancel'
  }).then(res => {
    if (!res.isConfirmed) { Swal.close(); return; }

    const reason = res.value || '';

    Swal.fire({
      title: 'Sending update...',
      allowOutsideClick: false,
      allowEscapeKey: false,
      didOpen: () => { Swal.showLoading(); }
    });

    fetch('admin-application.php', {
      method:'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({action:'decline', id:String(id), reason})
    })
    .then(r => r.json())
    .then(j => {
      try { closeModal(); } catch(e) {}

      Swal.fire({
        icon: 'success',
        title: 'Declined',
        text: 'Notification sent.',
        timer: 1100,
        showConfirmButton: false,
        willClose: () => { window.location.reload(); }    // <— force restart
      });
    })
    .catch(() => {
      Swal.fire({ icon:'error', title:'Network error', timer: 1200, showConfirmButton: false });
    });
  });
}

/* =====================================================
   ADDED (functions only): Limit table to 4 visible rows
   without altering your existing design or markup.
   - Dynamically wraps #appTable in a scrollable container.
   - Computes height = THEAD + (ROW * 4) + small buffer.
===================================================== */
(function(){
  const TABLE_SELECTOR = '#appTable';
  const WRAP_CLASS = 'js-table-scroll-wrap';

  function ensureWrapper(table) {
    if (table.parentElement && table.parentElement.classList.contains(WRAP_CLASS)) {
      return table.parentElement;
    }
    const wrap = document.createElement('div');
    wrap.className = WRAP_CLASS;
    // functional styles only (no design change)
    wrap.style.overflowY = 'auto';
    wrap.style.width = '100%';
    // insert wrapper before table and move table inside
    table.parentElement.insertBefore(wrap, table);
    wrap.appendChild(table);
    return wrap;
  }

  function firstVisibleRow(tbody) {
    const rows = tbody ? Array.from(tbody.rows) : [];
    return rows.find(r => r && r.offsetParent !== null);
  }

  function setRowLimit(n) {
    const table = document.querySelector(TABLE_SELECTOR);
    if (!table) return;

    const wrap = ensureWrapper(table);
    const theadRow = table.tHead && table.tHead.rows[0] ? table.tHead.rows[0] : null;
    const tbody = table.tBodies && table.tBodies[0] ? table.tBodies[0] : null;
    if (!theadRow || !tbody) return;

    const headH = Math.ceil(theadRow.getBoundingClientRect().height || 44);
    const rowEl = firstVisibleRow(tbody) || tbody.rows[0];
    if (!rowEl) return;
    const rowH = Math.ceil(rowEl.getBoundingClientRect().height || 44);

    const total = headH + (rowH * n) + 2; // buffer for borders
    wrap.style.maxHeight = total + 'px';
  }

  function applyLimit() { setRowLimit(8); }

  window.addEventListener('load', applyLimit);
  window.addEventListener('resize', applyLimit);
  window.addEventListener('load', () => {
    setTimeout(applyLimit, 120);
    setTimeout(applyLimit, 300);
  });
})();

/* ==========================================================
   ADDED: SMART SEARCH (Always ON, no checkbox)
   - Enhances search for User Applications without altering existing logic.
   - Works on table rows currently rendered.
   - Query features (examples):
       "exact phrase"       -> phrase match
       -word                -> exclude token
       last:cruz            -> Lastname contains
       first:juan           -> Firstname contains
       phone:0917           -> Contact # contains
       city:makati          -> Address contains token (city)
       street:ayala         -> Address contains token (street)
       zip:1200             -> Address contains token (zip)
       address:taguig       -> Address contains token
       rodriguez~           -> fuzzy token (len>=5, Levenshtein<=2 fallback)
   - Highlights matches with <mark class="smart-hit">
========================================================== */

// Inject a small help bubble next to the search box (no HTML edits)
(function injectSmartHelp(){
  const input = document.getElementById('searchBox');
  if (!input) return;
  const tip = document.createElement('span');
  tip.className = 'smart-help';
  tip.innerHTML = `?
    <span class="bubble">
      <div style="font-weight:700;margin-bottom:6px">Smart search (always on)</div>
      <ul style="margin:0;padding-left:16px;line-height:1.5">
        <li><code>"exact phrase"</code>, <code>-exclude</code></li>
        <li><code>last:cruz</code>, <code>first:juan</code>, <code>phone:0917</code></li>
        <li><code>city:makati</code>, <code>street:ayala</code>, <code>zip:1200</code>, <code>address:taguig</code></li>
        <li><code>rodriguez~</code> (fuzzy)</li>
      </ul>
    </span>`;
  input.insertAdjacentElement('afterend', tip);
})();

// Utilities
function ss_norm(s){ return (s||'').toString().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase(); }
function ss_lev(a,b){
  a=ss_norm(a); b=ss_norm(b);
  const m=[]; for(let i=0;i<=b.length;i++){ m[i]=[i]; } for(let j=0;j<=a.length;j++){ m[0][j]=j; }
  for(let i=1;i<=b.length;i++){ for(let j=1;j<=a.length;j++){
    m[i][j]=Math.min(m[i-1][j]+1, m[i][j-1]+1, m[i-1][j-1]+(a[j-1]===b[i-1]?0:1));
  }} return m[b.length][a.length];
}
function ss_clearHighlights(row){
  row.querySelectorAll('mark.smart-hit').forEach(el=>{
    const p=el.parentNode; p.replaceChild(document.createTextNode(el.textContent), el); p.normalize();
  });
}
function ss_highlightRow(row, needles){
  if(!needles||!needles.length) return;
  const walker=document.createTreeWalker(row, NodeFilter.SHOW_TEXT, {
    acceptNode(node){
      if(!node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
      if(node.parentElement && (node.parentElement.tagName==='BUTTON' || node.parentElement.closest('button'))) return NodeFilter.FILTER_REJECT;
      return NodeFilter.FILTER_ACCEPT;
    }
  });
  const nodes=[]; while(walker.nextNode()) nodes.push(walker.currentNode);
  for(const n of nodes){
    const txt=n.nodeValue, low=ss_norm(txt);
    let ranges=[];
    for(const needle of needles){
      const q=ss_norm(needle); if(!q) continue;
      let idx=0; while((idx=low.indexOf(q,idx))!==-1){ ranges.push([idx,idx+q.length]); idx+=q.length; }
    }
    if(!ranges.length) continue;
    ranges.sort((a,b)=>a[0]-b[0]);
    const merged=[]; let [s,e]=ranges[0];
    for(let i=1;i<ranges.length;i++){ const [ns,ne]=ranges[i]; if(ns<=e){ e=Math.max(e,ne); } else { merged.push([s,e]); [s,e]=[ns,ne]; } }
    merged.push([s,e]);
    const frag=document.createDocumentFragment(); let last=0;
    for(const [ms,me] of merged){
      if(last<ms) frag.appendChild(document.createTextNode(txt.slice(last,ms)));
      const mark=document.createElement('mark'); mark.className='smart-hit'; mark.textContent=txt.slice(ms,me);
      frag.appendChild(mark); last=me;
    }
    if(last<txt.length) frag.appendChild(document.createTextNode(txt.slice(last)));
    n.parentNode.replaceChild(frag,n);
  }
}
function ss_parseQuery(q){
  const tokens=[], neg=[], fields={phrase:[], last:[], first:[], phone:[], city:[], street:[], zip:[], address:[]}, fuzzy=[];
  q=q.trim(); if(!q) return {tokens,neg,fields,fuzzy};
  // phrases
  const ph=[]; q=q.replace(/"([^"]+)"/g,(_,p)=>{ ph.push(p.trim()); return ' '; }); fields.phrase.push(...ph);
  const parts=q.split(/\s+/).filter(Boolean);
  for(const part of parts){
    if(/^-/.test(part)){ neg.push(part.slice(1)); continue; }
    const m=part.match(/^(\w+):(.*)$/);
    if(m){
      const k=m[1].toLowerCase(), v=m[2];
      if(fields.hasOwnProperty(k)) fields[k].push(v);
      else tokens.push(part);
    } else if(part.endsWith('~')){ fuzzy.push(part.slice(0,-1)); }
    else if(part==='~'){ /* ignore */ }
    else { tokens.push(part); }
  }
  return {tokens,neg,fields,fuzzy};
}

(function smartSearchAlwaysOn(){
  const input = document.getElementById('searchBox');
  const tbody = document.querySelector('#appTable tbody');
  if(!input || !tbody) return;

  const rows = Array.from(tbody.querySelectorAll('tr'));

  input.addEventListener('input', ()=> {
    const raw = input.value;
    const basic = ss_norm(raw);

    // keep original quick filter behavior (default contains)
    rows.forEach(r=>{
      const text = ss_norm(r.innerText);
      r.style.display = text.includes(basic) ? '' : 'none';
    });

    // clear previous highlights
    rows.forEach(ss_clearHighlights);

    // Smart refinement (always on)
    const parsed = ss_parseQuery(raw);

    // Needles for highlighting
    const needles = [
      ...parsed.fields.phrase,
      ...parsed.tokens,
      ...parsed.fields.last,
      ...parsed.fields.first,
      ...parsed.fields.phone,
      ...parsed.fields.address,
      ...parsed.fields.city,
      ...parsed.fields.street,
      ...parsed.fields.zip
    ];

    rows.forEach(row=>{
      if(row.style.display==='none') return;

      // column mapping
      const cLast = row.cells[0]?.innerText || '';
      const cFirst= row.cells[1]?.innerText || '';
      const cPhone= row.cells[2]?.innerText || '';
      const cAddr = row.cells[3]?.innerText || '';

      const whole = ss_norm(`${cLast} ${cFirst} ${cPhone} ${cAddr}`);

      // Field filters
      if(parsed.fields.last.length){
        const ok = parsed.fields.last.some(v => ss_norm(cLast).includes(ss_norm(v)));
        if(!ok){ row.style.display='none'; return; }
      }
      if(parsed.fields.first.length){
        const ok = parsed.fields.first.some(v => ss_norm(cFirst).includes(ss_norm(v)));
        if(!ok){ row.style.display='none'; return; }
      }
      if(parsed.fields.phone.length){
        const ok = parsed.fields.phone.some(v => ss_norm(cPhone).includes(ss_norm(v)));
        if(!ok){ row.style.display='none'; return; }
      }
      if(parsed.fields.address.length){
        const ok = parsed.fields.address.some(v => ss_norm(cAddr).includes(ss_norm(v)));
        if(!ok){ row.style.display='none'; return; }
      }
      if(parsed.fields.city.length){
        const ok = parsed.fields.city.some(v => ss_norm(cAddr).includes(ss_norm(v)));
        if(!ok){ row.style.display='none'; return; }
      }
      if(parsed.fields.street.length){
        const ok = parsed.fields.street.some(v => ss_norm(cAddr).includes(ss_norm(v)));
        if(!ok){ row.style.display='none'; return; }
      }
      if(parsed.fields.zip.length){
        const ok = parsed.fields.zip.some(v => ss_norm(cAddr).includes(ss_norm(v)));
        if(!ok){ row.style.display='none'; return; }
      }

      // negatives
      if(parsed.neg.length){
        const bad = parsed.neg.some(n => whole.includes(ss_norm(n)));
        if(bad){ row.style.display='none'; return; }
      }

      // phrases must all match
      if(parsed.fields.phrase.length){
        const ok = parsed.fields.phrase.every(p => whole.includes(ss_norm(p)));
        if(!ok){ row.style.display='none'; return; }
      }

      // plain tokens: AND
      if(parsed.tokens.length){
        const ok = parsed.tokens.every(t => whole.includes(ss_norm(t)));
        if(!ok){ row.style.display='none'; return; }
      }

      // fuzzy tokens: ANY with distance<=2 for len>=5
      if(parsed.fuzzy.length){
        const ok = parsed.fuzzy.some(f => {
          if(whole.includes(ss_norm(f))) return true;
          if(f.length>=5) return ss_lev(`${cLast} ${cFirst} ${cAddr}`, f) <= 2;
          return false;
        });
        if(!ok){ row.style.display='none'; return; }
      }

      // highlight positives
      ss_highlightRow(row, needles);
    });
  });

  // trigger once (default ON)
  window.addEventListener('load', ()=> input.dispatchEvent(new Event('input')));
})();
</script>
</body>
</html>
