<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db-connection.php';
mysqli_set_charset($db_connection, 'utf8mb4');

$TABLE = 'content_management_table';
$toast = null;

/* ===== ADD BELOW THIS LINE — ADMIN BASICS, HELPERS, AUDIT (NO REMOVALS) ===== */

/* Ensure admin basics in session (username/email) */
$__admin_id = (int)($_SESSION['admin_id'] ?? 0);
if (empty($_SESSION['admin_user']) || empty($_SESSION['admin_email'])) {
  if ($__admin_id > 0 && ($__st = @mysqli_prepare(
    $db_connection,
    "SELECT admin_username, admin_emailaddress FROM admin_table WHERE admin_id=? LIMIT 1"
  ))) {
    mysqli_stmt_bind_param($__st, "i", $__admin_id);
    if (@mysqli_stmt_execute($__st)) {
      $res = mysqli_stmt_get_result($__st);
      if ($row = mysqli_fetch_assoc($res)) {
        if (!empty($row['admin_username']) && empty($_SESSION['admin_user'])) {
          $_SESSION['admin_user'] = (string)$row['admin_username'];
        }
        if (!empty($row['admin_emailaddress']) && empty($_SESSION['admin_email'])) {
          $_SESSION['admin_email'] = (string)$row['admin_emailaddress'];
        }
      }
    }
    mysqli_stmt_close($__st);
  }
}

/* HTML escape helper (safe if already defined elsewhere) */
if (!function_exists('h')) {
  function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
}

/* Build human-readable change notes (ONLY changed fields) for Join Us item */
if (!function_exists('build_changed_joinus_notes')) {
  function build_changed_joinus_notes(array $before, array $after): string {
    $labels = [
      'img_caption'   => 'Caption',
      'img_file_path' => 'Image Path',
      'content_type'  => 'Content Type',
    ];
    $changes = [];
    foreach ($labels as $key => $label) {
      $old = isset($before[$key]) ? (string)$before[$key] : '';
      $new = isset($after[$key])  ? (string)$after[$key]  : '';
      if ($old !== $new) {
        $oldTxt = ($old === '') ? '—' : $old;
        $newTxt = ($new === '') ? '—' : $new;
        $changes[] = "Changed {$label}: {$oldTxt} → {$newTxt}";
      }
    }
    if (!$changes) return 'Updated JoinUs item — No values changed.';
    return 'Updated JoinUs item — ' . implode('; ', $changes);
  }
}

/* Audit helper (3-level fallback) with UUID txn_id */
if (!function_exists('audit_content_action')) {
  function audit_content_action(
    mysqli $db,
    string $action,              // INSERT | UPDATE | DELETE
    string $recordPk,
    array  $detailsAfterArr = [],// for DELETE, this can contain last known state
    string $notes = ''
  ): array {

    $actorId    = (int)($_SESSION['admin_id']   ?? 0);
    $actorUser  = (string)($_SESSION['admin_user']  ?? '');
    $actorEmail = (string)($_SESSION['admin_email'] ?? '');

    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $detailsAfterJson = $detailsAfterArr
      ? json_encode($detailsAfterArr, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
      : null;

    $form   = 'content-management_join-us.php';
    $source = 'content_management_table';

    if ($notes === '') {
      $notes = $action==='INSERT'  ? 'Added JoinUs item'
            : ($action==='UPDATE' ? 'Updated JoinUs item'
            : ($action==='DELETE'? 'Deleted JoinUs item'
                                  : 'Content management action'));
    }

    $sql = "INSERT INTO audit_trail
            (txn_id, actor_admin_id, actor_username, actor_email,
             action, source_table, record_pk, form_name,
             ip_address, user_agent, notes, details_before, details_after)
            VALUES
            (UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)";
    if ($stmt = mysqli_prepare($db, $sql)) {
      mysqli_stmt_bind_param(
        $stmt, "issssssssss",
        $actorId, $actorUser, $actorEmail, $action, $source, $recordPk, $form, $ip, $ua, $notes, $detailsAfterJson
      );
      $ok = mysqli_stmt_execute($stmt);
      $err = $ok ? '' : mysqli_error($db);
      mysqli_stmt_close($stmt);
      if ($ok) return ['ok'=>true,'attempt'=>'JSON','msg'=>''];

      // retry once
      $stmt = mysqli_prepare($db, $sql);
      if ($stmt) {
        mysqli_stmt_bind_param(
          $stmt, "issssssssss",
          $actorId, $actorUser, $actorEmail, $action, $source, $recordPk, $form, $ip, $ua, $notes, $detailsAfterJson
        );
        $ok2 = mysqli_stmt_execute($stmt);
        $err2= $ok2 ? '' : mysqli_error($db);
        mysqli_stmt_close($stmt);
        if ($ok2) return ['ok'=>true,'attempt'=>'JSON-RETRY','msg'=>$err];
        $last = $err2 ?: $err;
      } else {
        $last = mysqli_error($db) ?: $err;
      }
    } else {
      $last = mysqli_error($db) ?: 'prepare_failed';
    }

    // minimal raw insert (no JSON)
    $u  = $db->real_escape_string($actorUser);
    $e  = $db->real_escape_string($actorEmail);
    $pk = $db->real_escape_string($recordPk);
    $ipEsc = $db->real_escape_string($ip);
    $uaEsc = $db->real_escape_string($ua);
    $notesEsc = $db->real_escape_string($notes);
    $sql3 = "
      INSERT INTO audit_trail
      (txn_id, actor_admin_id, actor_username, actor_email,
       action, source_table, record_pk, form_name,
       ip_address, user_agent, notes, details_before, details_after)
      VALUES
      (UUID(), {$actorId}, '{$u}', '{$e}', '{$action}', '{$source}',
       '{$pk}', '{$form}', '{$ipEsc}', '{$uaEsc}', '{$notesEsc}', NULL, NULL)";
    $ok3 = mysqli_query($db, $sql3);
    if ($ok3) return ['ok'=>true,'attempt'=>'MINIMAL','msg'=>$last ?: ''];

    return ['ok'=>false,'attempt'=>'FAILED','msg'=>mysqli_error($db) ?: $last ?: 'Unknown audit error'];
  }
}

/* --- Pre-capture BEFORE row so we can diff after actions --- */
$GLOBALS['__joinus_before'] = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $act = $_POST['action'] ?? '';
  $id  = (int)($_POST['contentID'] ?? 0);
  if (in_array($act, ['edit_caption','edit_image','remove'], true) && $id > 0) {
    if ($st = mysqli_prepare($db_connection, "SELECT contentID, content_type, img_file_path, img_caption FROM {$TABLE} WHERE contentID=? AND content_type='JoinUs' LIMIT 1")) {
      mysqli_stmt_bind_param($st, 'i', $id);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $GLOBALS['__joinus_before'] = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
      mysqli_stmt_close($st);
    }
  }
}

/* --- Shutdown audit (UPDATE via post-commit) --- */
register_shutdown_function(function() use ($db_connection, $TABLE) {
  $method = $_SERVER['REQUEST_METHOD'] ?? '';
  if ($method !== 'POST') return;

  $act = $_POST['action'] ?? '';
  if (!in_array($act, ['edit_caption','edit_image','remove'], true)) return;

  $id = (int)($_POST['contentID'] ?? 0);
  if ($id <= 0) return;

  // Fetch AFTER row
  $after = null;
  if ($st = mysqli_prepare($db_connection, "SELECT contentID, content_type, img_file_path, img_caption FROM {$TABLE} WHERE contentID=? AND content_type='JoinUs' LIMIT 1")) {
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $after = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
    mysqli_stmt_close($st);
  }

  $before = $GLOBALS['__joinus_before'] ?: [];
  if ($after) {
    // Notes vary slightly by action, but we still diff what actually changed.
    $prefix = $act === 'edit_caption' ? 'Updated caption — '
            : ($act === 'edit_image' ? 'Updated image — '
            : 'Cleared image/caption — ');
    $diffNotes = build_changed_joinus_notes($before ?: [], $after ?: []);
    $notes = $prefix . preg_replace('/^Updated JoinUs item —\s*/', '', $diffNotes);
    $audit = audit_content_action($db_connection, 'UPDATE', (string)$id, [
      'contentID'     => (string)$after['contentID'],
      'content_type'  => (string)($after['content_type'] ?? ''),
      'img_file_path' => (string)($after['img_file_path'] ?? ''),
      'img_caption'   => (string)($after['img_caption'] ?? ''),
    ], $notes);
    if (!$audit['ok']) error_log('[AUDIT][joinus-update]['.$audit['attempt'].'] '.$audit['msg']);
  }
});

/* ===== END ADDITIONS (helpers + audit) ===== */

/* =========================
   ACTIONS (same file)
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $id     = (int)($_POST['contentID'] ?? 0);

  /* EDIT CAPTION */
  if ($action === 'edit_caption' && $id > 0) {
    $cap = trim((string)($_POST['img_caption'] ?? ''));
    if ($cap === '') {
      $toast = ['type'=>'error','msg'=>'Caption cannot be empty.'];
    } else {
      if ($stmt = mysqli_prepare($db_connection, "UPDATE {$TABLE} SET img_caption=? WHERE contentID=? AND content_type='JoinUs' LIMIT 1")) {
        mysqli_stmt_bind_param($stmt, "si", $cap, $id);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $toast = $ok ? ['type'=>'success','msg'=>'Caption updated.'] : ['type'=>'error','msg'=>'Failed to update caption.'];
      }
    }
  }

  /* EDIT IMAGE (PNG/JPG/JPEG, ≤5MB) */
  if ($action === 'edit_image' && $id > 0) {
    if (!isset($_FILES['img_file_path']) || !is_uploaded_file($_FILES['img_file_path']['tmp_name'])) {
      $toast = ['type'=>'error','msg'=>'Please choose an image file.'];
    } else {
      $ext = strtolower(pathinfo($_FILES['img_file_path']['name'], PATHINFO_EXTENSION));
      $allowed = ['png','jpg','jpeg'];
      $sizeOK  = (($_FILES['img_file_path']['size'] ?? 0) <= 5 * 1024 * 1024);
      if (!in_array($ext, $allowed, true)) {
        $toast = ['type'=>'error','msg'=>'Only PNG/JPG/JPEG allowed.'];
      } elseif (!$sizeOK) {
        $toast = ['type'=>'error','msg'=>'File too large (max 5MB).'];
      } else {
        $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'joinus';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $safe   = preg_replace('/[^A-Za-z0-9_\-]+/','_', pathinfo($_FILES['img_file_path']['name'], PATHINFO_FILENAME));
        $fname  = $safe.'_'.time().'_'.mt_rand(1000,9999).'.'.$ext;
        $abs    = $dir . DIRECTORY_SEPARATOR . $fname;
        $relWeb = 'uploads/joinus/'.$fname;

        if (!move_uploaded_file($_FILES['img_file_path']['tmp_name'], $abs)) {
          $toast = ['type'=>'error','msg'=>'Failed to upload image.'];
        } else {
          if ($stmt = mysqli_prepare($db_connection, "UPDATE {$TABLE} SET img_file_path=? WHERE contentID=? AND content_type='JoinUs' LIMIT 1")) {
            mysqli_stmt_bind_param($stmt, "si", $relWeb, $id);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $toast = $ok ? ['type'=>'success','msg'=>'Image updated.'] : ['type'=>'error','msg'=>'Failed to update image path.'];
          }
        }
      }
    }
  }

  /* REMOVE (clear image + caption; also delete file if present) */
  if ($action === 'remove' && $id > 0) {
    $r = mysqli_query($db_connection, "SELECT img_file_path FROM {$TABLE} WHERE contentID={$id} AND content_type='JoinUs' LIMIT 1");
    $old = $r ? mysqli_fetch_assoc($r) : null;
    if ($old && !empty($old['img_file_path'])) {
      $absOld = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $old['img_file_path']);
      if (is_file($absOld)) @unlink($absOld);
    }
    if ($stmt = mysqli_prepare($db_connection, "UPDATE {$TABLE} SET img_file_path=NULL, img_caption=NULL WHERE contentID=? AND content_type='JoinUs' LIMIT 1")) {
      mysqli_stmt_bind_param($stmt, "i", $id);
      $ok = mysqli_stmt_execute($stmt);
      mysqli_stmt_close($stmt);
      $toast = $ok ? ['type'=>'success','msg'=>'Image removed.'] : ['type'=>'error','msg'=>'Failed to remove image.'];
    }
  }
}

/* =========================
   FETCH (latest JoinUs row)
   ========================= */
$row = null;
$q = "SELECT contentID, img_file_path, imgAlt, img_caption
      FROM {$TABLE}
      WHERE content_type='JoinUs'
      ORDER BY contentID DESC
      LIMIT 1";
if ($res = mysqli_query($db_connection, $q)) {
  $row = mysqli_fetch_assoc($res);
  mysqli_free_result($res);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Content Management • Join Us</title>
<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="/HTCCC-SYSTEM/css/content-management_join-us.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/content-management_join-us.css'); ?>">

<style>
/* Small local tweaks for the preview and modal */
.photo{width:400px;height:150px;object-fit:cover;border-radius:8px;box-shadow:0 6px 12px rgba(0,0,0,.2)}
.text-box{margin-top:20px;background:#fff;padding:16px;border-radius:10px;box-shadow:0 6px 16px rgba(0,0,0,.1);text-align:center;margin-left: 290px;}
.text-box p{font-size:16px;color:#0B1446;font-weight:600}
.actions{display:flex;justify-content:center;gap:12px;margin:24px auto}
.actions .btn{font-weight:800;border:none;padding:10px 14px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:8px}
.btn.edit-image{background:#0B3B4A;color:#fff}
.btn.remove{background:#8B0000;color:#fff}
.btn.edit-caption{background:#6B5AE3;color:#fff}

.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:9999}
.modal{background:#fff;border-radius:10px;max-width:520px;width:92%;padding:20px;box-shadow:0 20px 50px rgba(0,0,0,.25)}
.modal h2{margin:0 0 12px;color:#0B1446}
.modal .actions{display:flex;justify-content:flex-end;gap:10px;margin-top:14px}
.modal .btn{border:none;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer}
.modal .btn.cancel{background:#e5e7eb;color:#111827}
.modal .btn.save{background:#0B3B4A;color:#fff}

/* Ensure header + nav classes look like other pages you showed */
.cm-header{background:#0A0E3F;color:#fff;display:grid;grid-template-columns:60px 1fr 60px;align-items:center;justify-content:center;padding:10px 28px;width:100%;box-shadow:0 1px 3px rgba(0,0,0,.15);position:relative;z-index:10}
.cm-center{display:flex;align-items:center;justify-content:center;gap:10px}
.cm-logo{width:46px;height:46px;border-radius:50%}
.cm-title{font-size:26px;font-weight:900;margin:0}
nav{background:#0A0E3F;color:#fff;display:flex;justify-content:center;flex-wrap:wrap;gap:22px;padding:16px 0}
.nav-pill{display:inline-flex;align-items:center;gap:8px;border:1px solid #304256;background:transparent;color:#dbeafe;padding:8px 18px;border-radius:999px;text-decoration:none;font-weight:800;font-size:15px;opacity:.85;transition:.2s}
.nav-pill:hover,.nav-pill.active{opacity:1;background:#304256;color:#fff}
.nav-pill i{font-size:16px}
.container-narrow{max-width:900px;margin:36px auto;padding:0 24px;text-align:center}
</style>
</head>
<body>

<header class="cm-header">
  <a href="secretary_dashboard.php" class="cm-back">
    <img src="image/btn-back.png" alt="Back" style="width:30px;height:30px;cursor:pointer">
  </a>
  <div class="cm-center">
    <img src="image/httc_main-logo.jpg" class="cm-logo" alt="Logo">
    <h1 class="cm-title">CONTENT MANAGEMENT</h1>
  </div>
  <div class="cm-right"></div>
</header>

<nav>
  <div class="nav-wrap">
    <a class="nav-pill" href="content-management_home-page.php"><i class="fa-solid fa-house"></i> Home Page</a>
    <a class="nav-pill" href="content-management_service.php"><i class="fa-solid fa-hands-praying"></i> Service</a>
    <a class="nav-pill" href="content-management_events.php"><i class="fa-solid fa-calendar-days"></i> Events</a>
    <a class="nav-pill" href="content-management_gallery.php"><i class="fa-solid fa-images"></i> Gallery</a>
    <a class="nav-pill" href="content-management_ministries.php"><i class="fa-solid fa-people-group"></i> Ministries</a>
    <a class="nav-pill active" href="#"><i class="fa-solid fa-user-plus"></i> Join Us</a>
    <a class="nav-pill" href="content-management_find-us.php"><i class="fa-solid fa-location-dot"></i> Find Us</a>
  </div>
</nav>

<!-- One form for all actions (like your Slider page) -->
<form method="post" enctype="multipart/form-data" id="oneForm">
  <input type="hidden" name="action" id="actionField">
  <input type="hidden" name="contentID" id="idField" value="<?php echo (int)($row['contentID'] ?? 0); ?>">

  <div class="container-narrow">
    <div class="image-container" style="margin:0 auto 10px;max-width:500px;box-shadow:0 10px 20px rgba(0,0,0,.2),0 6px 6px rgba(0,0,0,.25)">
      <?php if (!empty($row['img_file_path'])): ?>
        <img src="<?php echo htmlspecialchars($row['img_file_path'],ENT_QUOTES); ?>" alt="<?php echo htmlspecialchars($row['imgAlt'] ?? 'Join Us',ENT_QUOTES); ?>" class="photo">
      <?php else: ?>
        <div style="padding:36px 0;color:#6b7280;font-weight:700;background:#fff;border-radius:8px;">No image available</div>
      <?php endif; ?>
      <svg viewBox="0 0 400 48" fill="none" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M0 12C40 0 80 12 120 12C160 12 200 0 240 12C280 24 320 12 360 12C400 12 400 48 0 48V12Z" fill="#FBBF59"/>
      </svg>
    </div>

    <div class="text-box">
      <p id="caption-view"><?php
        echo ($row && $row['img_caption']!=='')
          ? htmlspecialchars($row['img_caption'],ENT_QUOTES)
          : 'No caption available';
      ?></p>
      <button type="button" class="btn edit-caption" id="openCaption">Edit Caption</button>
    </div>

    <div class="actions">
      <button type="button" class="btn edit-image" id="openImage"><i class="fas fa-upload"></i> Edit Image</button>
      <button type="button" class="btn remove" id="removeBtn">Remove</button>
    </div>
  </div>

  <!-- ===== Caption Modal ===== -->
  <div class="modal-backdrop" id="capModal">
    <div class="modal">
      <h2>Edit Caption</h2>
      <input type="text" name="img_caption" id="capInput" value="<?php echo htmlspecialchars($row['img_caption'] ?? '',ENT_QUOTES); ?>" placeholder="Enter caption..." required>
      <div class="actions">
        <button type="button" class="btn cancel" data-close="#capModal">Cancel</button>
        <button type="button" class="btn save" id="saveCaption">Save</button>
      </div>
    </div>
  </div>

  <!-- ===== Image Modal ===== -->
  <div class="modal-backdrop" id="imgModal">
    <div class="modal">
      <h2>Edit Image</h2>
      <input type="file" name="img_file_path" id="imgInput" accept="image/png,image/jpeg" required>
      <small style="display:block;color:#6b7280;margin-top:8px">Allowed: PNG/JPG (max 5MB)</small>
      <div class="actions">
        <button type="button" class="btn cancel" data-close="#imgModal">Cancel</button>
        <button type="button" class="btn save" id="saveImage">Upload</button>
      </div>
    </div>
  </div>
</form>

<script>
const $ = (q)=>document.querySelector(q);
const capModal = $('#capModal');
const imgModal = $('#imgModal');
const actionF  = $('#actionField');
const form     = $('#oneForm');
const idVal    = $('#idField').value || '';

/* Open/close modals */
$('#openCaption')?.addEventListener('click', ()=> capModal.style.display='flex');
$('#openImage')?.addEventListener('click',  ()=> imgModal.style.display='flex');

document.querySelectorAll('[data-close]').forEach(btn=>{
  btn.addEventListener('click', e=>{
    const sel = e.currentTarget.getAttribute('data-close');
    const m = document.querySelector(sel);
    if(m) m.style.display='none';
  });
});
capModal.addEventListener('click',e=>{ if(e.target===capModal) capModal.style.display='none'; });
imgModal.addEventListener('click',e=>{ if(e.target===imgModal) imgModal.style.display='none'; });

/* Save caption */
$('#saveCaption')?.addEventListener('click', ()=>{
  actionF.value='edit_caption';
  form.submit();
});

/* Save image */
$('#saveImage')?.addEventListener('click', ()=>{
  actionF.value='edit_image';
  form.submit();
});

/* Remove */
$('#removeBtn')?.addEventListener('click', ()=>{
  if(!idVal){ return; }
  Swal.fire({
    title:'Remove Join Us image?',
    text:'This will clear the image and caption.',
    icon:'warning',
    showCancelButton:true,
    confirmButtonColor:'#8B0000',
    cancelButtonColor:'#6b7280',
    confirmButtonText:'Remove'
  }).then(res=>{
    if(res.isConfirmed){
      actionF.value='remove';
      form.submit();
    }
  });
});

/* Toast */
<?php if ($toast): ?>
Swal.fire({
  toast:true,
  position:'top-end',
  icon:'<?php echo $toast['type']; ?>',
  title:'<?php echo addslashes($toast['msg']); ?>',
  showConfirmButton:false,
  timer:2000,
  timerProgressBar:true
});
<?php endif; ?>
</script>
</body>
</html>
