<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db-connection.php';
mysqli_set_charset($db_connection, 'utf8mb4');

$TABLE = 'content_management_table';
$toast = null;

/* ===== ADD BELOW THIS LINE — ADMIN BASICS, HELPERS, AUDIT (NO REMOVALS) ===== */

/* Ensure admin basics in session (username/email) like your reference pages */
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

/* Build human-readable change notes (ONLY changed fields) for Slider items */
if (!function_exists('build_changed_slider_notes')) {
  function build_changed_slider_notes(array $before, array $after): string {
    $labels = [
      'img_caption'    => 'Caption',
      'status'         => 'Status',
      'img_file_name'  => 'File Name',
      'img_file_path'  => 'File Path',
      // include type if you ever change it
      'content_type'   => 'Content Type',
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
    if (!$changes) return 'Updated slider item — No values changed.';
    return 'Updated slider item — ' . implode('; ', $changes);
  }
}

/* Audit helper (3-level fallback) with UUID txn_id */
if (!function_exists('audit_content_action')) {
  function audit_content_action(
    mysqli $db,
    string $action,              // INSERT | UPDATE | DELETE
    string $recordPk,
    array  $detailsAfterArr = [],// for DELETE, this will contain the last known state (before deletion)
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

    $form   = 'content-management_home-page.php';
    $source = 'content_management_table';

    if ($notes === '') {
      $notes = $action==='INSERT'  ? 'Added slider item'
            : ($action==='UPDATE' ? 'Updated slider item'
            : ($action==='DELETE'? 'Deleted slider item'
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

/* --- Pre-capture BEFORE rows so we can diff or include on DELETE (no handler edits) --- */
$GLOBALS['__cm_before_edit']   = null;
$GLOBALS['__cm_before_delete'] = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $act = $_POST['action'] ?? '';

  // capture BEFORE EDIT
  if ($act === 'edit') {
    $id = (int)($_POST['edit_id'] ?? 0);
    if ($id > 0 && ($st = mysqli_prepare($db_connection, "SELECT contentID, img_file_name, img_file_path, img_caption, content_type, status FROM {$TABLE} WHERE contentID=? LIMIT 1"))) {
      mysqli_stmt_bind_param($st, 'i', $id);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $GLOBALS['__cm_before_edit'] = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
      mysqli_stmt_close($st);
    }
  }

  // capture BEFORE DELETE
  if ($act === 'delete') {
    $id = (int)($_POST['delete_id'] ?? 0);
    if ($id > 0 && ($st = mysqli_prepare($db_connection, "SELECT contentID, img_file_name, img_file_path, img_caption, content_type, status FROM {$TABLE} WHERE contentID=? LIMIT 1"))) {
      mysqli_stmt_bind_param($st, 'i', $id);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $GLOBALS['__cm_before_delete'] = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
      mysqli_stmt_close($st);
    }
  }
}

/* --- Shutdown audit (INSERT/UPDATE via post-commit; DELETE verified by disappearance) --- */
register_shutdown_function(function() use ($db_connection, $TABLE) {
  $method = $_SERVER['REQUEST_METHOD'] ?? '';
  if ($method !== 'POST') return;
  $act = $_POST['action'] ?? '';

  // INSERT (ADD) - slider only
  if ($act === 'add') {
    $newId = (int)mysqli_insert_id($db_connection);
    if ($newId > 0) {
      // fetch the newly added row for details
      $row = null;
      if ($st = mysqli_prepare($db_connection, "SELECT contentID, img_file_name, img_file_path, img_caption, content_type, status FROM {$TABLE} WHERE contentID=? LIMIT 1")) {
        mysqli_stmt_bind_param($st, 'i', $newId);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $row = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
        mysqli_stmt_close($st);
      }
      if ($row) {
        $cap = (string)($row['img_caption'] ?? '');
        $notes = "Added slider item — ID {$newId}".($cap!=='' ? " | Caption: {$cap}" : '');
        $audit = audit_content_action($db_connection, 'INSERT', (string)$newId, [
          'contentID'     => (string)$row['contentID'],
          'img_file_name' => (string)($row['img_file_name'] ?? ''),
          'img_file_path' => (string)($row['img_file_path'] ?? ''),
          'img_caption'   => (string)($row['img_caption'] ?? ''),
          'content_type'  => (string)($row['content_type'] ?? ''),
          'status'        => (string)($row['status'] ?? ''),
        ], $notes);
        if (!$audit['ok']) error_log('[AUDIT][slider-insert]['.$audit['attempt'].'] '.$audit['msg']);
      }
    }
  }

  // UPDATE (EDIT caption) - slider only
  if ($act === 'edit') {
    $id = (int)($_POST['edit_id'] ?? 0);
    if ($id > 0) {
      $after = null;
      if ($st = mysqli_prepare($db_connection, "SELECT contentID, img_file_name, img_file_path, img_caption, content_type, status FROM {$TABLE} WHERE contentID=? LIMIT 1")) {
        mysqli_stmt_bind_param($st, 'i', $id);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $after = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
        mysqli_stmt_close($st);
      }
      $before = $GLOBALS['__cm_before_edit'] ?: [];
      if ($after) {
        $notes = build_changed_slider_notes($before ?: [], $after ?: []);
        $audit = audit_content_action($db_connection, 'UPDATE', (string)$id, [
          'contentID'     => (string)$after['contentID'],
          'img_file_name' => (string)($after['img_file_name'] ?? ''),
          'img_file_path' => (string)($after['img_file_path'] ?? ''),
          'img_caption'   => (string)($after['img_caption'] ?? ''),
          'content_type'  => (string)($after['content_type'] ?? ''),
          'status'        => (string)($after['status'] ?? ''),
        ], $notes);
        if (!$audit['ok']) error_log('[AUDIT][slider-update]['.$audit['attempt'].'] '.$audit['msg']);
      }
    }
  }

  // DELETE (verify that it disappeared; use BEFORE snapshot for details) - slider only
  if ($act === 'delete') {
    $id = (int)($_POST['delete_id'] ?? 0);
    $before = $GLOBALS['__cm_before_delete'] ?: null;
    if ($id > 0 && $before) {
      // check if still exists
      $exists = false;
      if ($st = mysqli_prepare($db_connection, "SELECT 1 FROM {$TABLE} WHERE contentID=? LIMIT 1")) {
        mysqli_stmt_bind_param($st, 'i', $id);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $exists = (bool)($res && mysqli_fetch_row($res));
        mysqli_stmt_close($st);
      }
      if (!$exists) {
        // consider deletion successful, log DELETE with last-known data
        $cap = (string)($before['img_caption'] ?? '');
        $notes = "Deleted slider item — ID {$id}".($cap!=='' ? " | Caption: {$cap}" : '');
        $audit = audit_content_action($db_connection, 'DELETE', (string)$id, [
          'contentID'     => (string)$before['contentID'],
          'img_file_name' => (string)($before['img_file_name'] ?? ''),
          'img_file_path' => (string)($before['img_file_path'] ?? ''),
          'img_caption'   => (string)($before['img_caption'] ?? ''),
          'content_type'  => (string)($before['content_type'] ?? ''),
          'status'        => (string)($before['status'] ?? ''),
        ], $notes);
        if (!$audit['ok']) error_log('[AUDIT][slider-delete]['.$audit['attempt'].'] '.$audit['msg']);
      }
    }
  }
});

/* ===== END ADDITIONS (helpers + audit) ===== */

/* ---------- ACTION HANDLER ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  /* ADD (SLIDER) */
  if ($action === 'add') {
    $cap = trim((string)($_POST['img_caption'] ?? ''));
    $targetDir = __DIR__ . DIRECTORY_SEPARATOR . "uploads";
    if (!is_dir($targetDir)) @mkdir($targetDir, 0777, true);

    if (!isset($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
      $toast = ['type'=>'error','msg'=>'No image attached.'];
    } else {
      $fileName = basename($_FILES['image']['name']);
      $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
      $allowed = ['jpg','jpeg','png','gif'];
      if (!in_array($ext, $allowed, true)) {
        $toast = ['type'=>'error','msg'=>'Only JPG, JPEG, PNG, GIF allowed.'];
      } elseif (($_FILES['image']['size'] ?? 0) > 5 * 1024 * 1024) {
        $toast = ['type'=>'error','msg'=>'File too large (max 5MB).'];
      } else {
        $safe = preg_replace('/[^A-Za-z0-9_\-]+/', '_', pathinfo($fileName, PATHINFO_FILENAME));
        $unique = $safe.'_'.time().'_'.mt_rand(1000,9999).'.'.$ext;
        $abs = $targetDir.DIRECTORY_SEPARATOR.$unique;
        $rel = "uploads/".$unique;
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $abs)) {
          $toast = ['type'=>'error','msg'=>'Failed to move uploaded file.'];
        } else {
          $sql = "INSERT INTO {$TABLE}(img_file_name,img_file_path,img_caption,content_type,img_upload_at)
                  VALUES (?,?,?,?,NOW())";
          $ctype = 'slider';
          if ($stmt = mysqli_prepare($db_connection,$sql)) {
            mysqli_stmt_bind_param($stmt,"ssss",$unique,$rel,$cap,$ctype);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $toast = $ok ? ['type'=>'success','msg'=>'New slider item added.']
                         : ['type'=>'error','msg'=>'Insert failed.'];
          }
        }
      }
    }
  }

  /* EDIT CAPTION (SLIDER) */
  if ($action === 'edit') {
    $id = (int)($_POST['edit_id'] ?? 0);
    $cap = trim((string)($_POST['new_caption'] ?? ''));
    if ($id>0) {
      $sql="UPDATE {$TABLE} SET img_caption=? WHERE contentID=? AND LOWER(content_type)='slider' LIMIT 1";
      if ($stmt=mysqli_prepare($db_connection,$sql)) {
        mysqli_stmt_bind_param($stmt,"si",$cap,$id);
        $ok=mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $toast = $ok?['type'=>'success','msg'=>'Caption updated.']:['type'=>'error','msg'=>'Update failed.'];
      }
    }
  }

  /* DELETE (SLIDER) */
  if ($action === 'delete') {
    $id=(int)($_POST['delete_id']??0);
    if ($id>0) {
      $r=mysqli_query($db_connection,"SELECT img_file_path FROM {$TABLE} WHERE contentID={$id} LIMIT 1");
      $row=$r?mysqli_fetch_assoc($r):null;
      if($row && is_file(__DIR__.'/'.$row['img_file_path'])) @unlink(__DIR__.'/'.$row['img_file_path']);
      $stmt=mysqli_prepare($db_connection,"DELETE FROM {$TABLE} WHERE contentID=? AND LOWER(content_type)='slider' LIMIT 1");
      mysqli_stmt_bind_param($stmt,"i",$id);
      mysqli_stmt_execute($stmt);
      $aff=mysqli_stmt_affected_rows($stmt);
      mysqli_stmt_close($stmt);
      $toast = ($aff===1)?['type'=>'success','msg'=>'Item deleted.']:['type'=>'error','msg'=>'Delete failed.'];
    }
  }

  /* ===== ABOUT US ACTIONS ===== */

  /* ADD ABOUT-US IMAGE (max 5) */
  if ($action === 'about_add_image') {
    // count existing images
    $cnt = 0;
    $res = mysqli_query($db_connection, "SELECT COUNT(*) AS c FROM {$TABLE} WHERE content_type='AboutUsImage'");
    if ($res && ($row = mysqli_fetch_assoc($res))) {
      $cnt = (int)$row['c'];
    }
    if ($cnt >= 5) {
      $toast = ['type'=>'error','msg'=>'Maximum of 5 About Us photos reached. Remove one before uploading.'];
    } else {
      $targetDir = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "about-us";
      if (!is_dir($targetDir)) @mkdir($targetDir, 0777, true);

      if (!isset($_FILES['about_image']) || !is_uploaded_file($_FILES['about_image']['tmp_name'])) {
        $toast = ['type'=>'error','msg'=>'No About Us image attached.'];
      } else {
        $fileName = basename($_FILES['about_image']['name']);
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif'];
        if (!in_array($ext, $allowed, true)) {
          $toast = ['type'=>'error','msg'=>'Only JPG, JPEG, PNG, GIF allowed for About Us photos.'];
        } elseif (($_FILES['about_image']['size'] ?? 0) > 5 * 1024 * 1024) {
          $toast = ['type'=>'error','msg'=>'About Us photo too large (max 5MB).'];
        } else {
          $safe = preg_replace('/[^A-Za-z0-9_\-]+/', '_', pathinfo($fileName, PATHINFO_FILENAME));
          $unique = $safe.'_'.time().'_'.mt_rand(1000,9999).'.'.$ext;
          $abs = $targetDir.DIRECTORY_SEPARATOR.$unique;
          $rel = "uploads/about-us/".$unique;
          if (!move_uploaded_file($_FILES['about_image']['tmp_name'], $abs)) {
            $toast = ['type'=>'error','msg'=>'Failed to move About Us photo.'];
          } else {
            $cap = trim((string)($_POST['about_caption_add'] ?? ''));
            $sql = "INSERT INTO {$TABLE}(img_file_name,img_file_path,img_caption,content_type,img_upload_at)
                    VALUES (?,?,?,?,NOW())";
            $ctype = 'AboutUsImage';
            if ($stmt = mysqli_prepare($db_connection,$sql)) {
              mysqli_stmt_bind_param($stmt,"ssss",$unique,$rel,$cap,$ctype);
              $ok = mysqli_stmt_execute($stmt);
              mysqli_stmt_close($stmt);
              $toast = $ok ? ['type'=>'success','msg'=>'About Us photo added.']
                           : ['type'=>'error','msg'=>'Insert failed for About Us photo.'];
            }
          }
        }
      }
    }
  }

  /* EDIT ABOUT-US IMAGE CAPTION */
  if ($action === 'about_edit_image') {
    $id = (int)($_POST['about_image_id'] ?? 0);
    $cap = trim((string)($_POST['about_caption'] ?? ''));
    if ($id>0) {
      $sql="UPDATE {$TABLE} SET img_caption=? WHERE contentID=? AND content_type='AboutUsImage' LIMIT 1";
      if ($stmt=mysqli_prepare($db_connection,$sql)) {
        mysqli_stmt_bind_param($stmt,"si",$cap,$id);
        $ok=mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $toast = $ok?['type'=>'success','msg'=>'About Us photo caption updated.']:['type'=>'error','msg'=>'Update failed for About Us caption.'];
      }
    }
  }

  /* DELETE ABOUT-US IMAGE */
  if ($action === 'about_delete_image') {
    $id = (int)($_POST['about_image_id'] ?? 0);
    if ($id>0) {
      $r = mysqli_query($db_connection,"SELECT img_file_path FROM {$TABLE} WHERE contentID={$id} AND content_type='AboutUsImage' LIMIT 1");
      $row = $r?mysqli_fetch_assoc($r):null;
      if($row && !empty($row['img_file_path']) && is_file(__DIR__.'/'.$row['img_file_path'])) {
        @unlink(__DIR__.'/'.$row['img_file_path']);
      }
      $stmt=mysqli_prepare($db_connection,"DELETE FROM {$TABLE} WHERE contentID=? AND content_type='AboutUsImage' LIMIT 1");
      mysqli_stmt_bind_param($stmt,"i",$id);
      mysqli_stmt_execute($stmt);
      $aff=mysqli_stmt_affected_rows($stmt);
      mysqli_stmt_close($stmt);
      $toast = ($aff===1)?['type'=>'success','msg'=>'About Us photo deleted.']:['type'=>'error','msg'=>'Delete failed for About Us photo.'];
    }
  }

  /* UPDATE ABOUT-US HISTORY TEXT */
  if ($action === 'about_update_history') {
    $txt = trim((string)($_POST['about_history_text'] ?? ''));
    // check if existing row
    $hid = 0;
    $res = mysqli_query($db_connection, "SELECT contentID FROM {$TABLE} WHERE content_type='AboutUsHistory' ORDER BY contentID DESC LIMIT 1");
    if ($res && ($row = mysqli_fetch_assoc($res))) {
      $hid = (int)$row['contentID'];
    }
    if ($hid > 0) {
      $stmt = mysqli_prepare($db_connection, "UPDATE {$TABLE} SET img_caption=?, img_upload_at=NOW() WHERE contentID=? LIMIT 1");
      mysqli_stmt_bind_param($stmt, "si", $txt, $hid);
      $ok = mysqli_stmt_execute($stmt);
      mysqli_stmt_close($stmt);
      $toast = $ok?['type'=>'success','msg'=>'About Us history updated.']:['type'=>'error','msg'=>'Failed to update About Us history.'];
    } else {
      $ctype = 'AboutUsHistory';
      $stmt = mysqli_prepare($db_connection, "INSERT INTO {$TABLE}(img_caption, content_type, img_upload_at) VALUES (?,?,NOW())");
      mysqli_stmt_bind_param($stmt, "ss", $txt, $ctype);
      $ok = mysqli_stmt_execute($stmt);
      mysqli_stmt_close($stmt);
      $toast = $ok?['type'=>'success','msg'=>'About Us history saved.']:['type'=>'error','msg'=>'Failed to save About Us history.'];
    }
  }
}

/* ---------- FETCH SLIDER ---------- */
$slider=[];
$res=mysqli_query($db_connection,"SELECT contentID,img_file_path,img_caption,status FROM {$TABLE}
  WHERE LOWER(content_type)='slider' ORDER BY img_upload_at DESC,contentID DESC");
while($res && $r=mysqli_fetch_assoc($res)) $slider[]=$r;

/* ---------- FETCH ABOUT-US (IMAGES + HISTORY) ---------- */
$aboutImages = [];
$resA = mysqli_query($db_connection, "SELECT contentID,img_file_path,img_caption FROM {$TABLE} WHERE content_type='AboutUsImage' ORDER BY contentID ASC");
while($resA && $row = mysqli_fetch_assoc($resA)) {
  $aboutImages[] = $row;
}
$aboutCount = count($aboutImages);

$aboutHistoryRow = null;
$resH = mysqli_query($db_connection, "SELECT contentID,img_caption FROM {$TABLE} WHERE content_type='AboutUsHistory' ORDER BY contentID DESC LIMIT 1");
if ($resH && ($rowH = mysqli_fetch_assoc($resH))) {
  $aboutHistoryRow = $rowH;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Content Management • Slider</title>
<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="/HTCCC-SYSTEM/css/content-management_home-page.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/content-management_home-page.css'); ?>">

</head>
<body>
<header class="cm-header">
  <a href="secretary_dashboard.php" class="cm-back">
    <img src="image/btn-back.png" alt="Back" style="width:30px;height:30px;cursor:pointer">
  </a>
  <div class="cm-center">
    <img src="image/httc_main-logo.jpg" alt="Logo" class="cm-logo">
    <h1 class="cm-title">CONTENT MANAGEMENT</h1>
  </div>
  <div class="cm-right"></div>
</header>

<nav>
  <div class="nav-wrap">
    <a class="nav-pill active" href="#"><i class="fa-solid fa-house"></i>&nbsp;Home Page</a>
    <a class="nav-pill" href="content-management_service.php"><i class="fa-solid fa-hands-praying"></i>&nbsp;Service</a>
    <a class="nav-pill" href="#"><i class="fa-solid fa-calendar-days"></i>&nbsp;Events</a>
    <a class="nav-pill" href="#"><i class="fa-solid fa-images"></i>&nbsp;Gallery</a>
    <a class="nav-pill" href="#"><i class="fa-solid fa-people-group"></i>&nbsp;Ministries</a>
    <a class="nav-pill" href="#"><i class="fa-solid fa-user-plus"></i>&nbsp;Join Us</a>
    <a class="nav-pill" href="#"><i class="fa-solid fa-location-dot"></i>&nbsp;Find Us</a>
  </div>
</nav>

<div class="container">
<form method="post" enctype="multipart/form-data" id="oneForm">
  <input type="hidden" name="action" id="global-action">
  <input type="hidden" name="edit_id" id="global-edit-id">
  <input type="hidden" name="delete_id" id="global-delete-id">
  <textarea name="new_caption" id="global-new-caption" style="display:none"></textarea>

  <button type="button" class="btn" id="openAdd"><i class="fas fa-plus"></i>ADD</button>

  <div class="spacer"></div>

  <?php if(empty($slider)):?>
    <div class="card" style="justify-content:center;">No slider items yet.</div>
  <?php else: foreach($slider as $r):?>
    <?php $id=(int)$r['contentID'];$img=$r['img_file_path'];$cap=$r['img_caption'];$st=strtoupper($r['status']??'ACTIVE');?>
    <section class="card" style="margin-bottom:26px;">
      <img class="img" src="<?php echo htmlspecialchars($img,ENT_QUOTES);?>" alt="">
      <div class="right">
        <div style="display:flex;justify-content:flex-end;"><span class="badge"><?php echo $st;?></span></div>
        <p id="cap-view-<?php echo $id;?>" style="margin:18px 0;font-weight:600;color:#1f2937;line-height:1.45;"><?php echo htmlspecialchars($cap,ENT_QUOTES);?></p>
        <div id="cap-edit-<?php echo $id;?>" style="display:none;margin:18px 0;">
          <textarea id="cap-textarea-<?php echo $id;?>" class="textarea" style="width:100%;"><?php echo htmlspecialchars($cap,ENT_QUOTES);?></textarea>
          <div style="display:flex;justify-content:center;gap:10px;margin-top:10px;">
            <button type="button" class="btn secondary" onclick="toggleEdit(<?php echo $id;?>,false)">Cancel</button>
            <button type="button" class="btn" onclick="saveEdit(<?php echo $id;?>)">Save</button>
          </div>
        </div>
        <div class="actions">
          <button type="button" class="btn-edit" onclick="toggleEdit(<?php echo $id;?>,true)">EDIT CAPTION</button>
          <button type="button" class="btn-arch" onclick="doDelete(<?php echo $id;?>)">DELETE</button>
        </div>
      </div>
    </section>
  <?php endforeach; endif;?>
  
  <!-- Modal -->
  <div class="modal-backdrop" id="modalBackdrop"></div>
  <div class="modal" id="modalAdd">
    <div class="modal-card">
      <div class="modal-header">
        <h3 class="modal-title"><i class="fa-solid fa-plus"></i>&nbsp;Add Slider Item</h3>
        <button type="button" class="close-x" id="closeAdd">&times;</button>
      </div>
      <div class="modal-body">
        <div class="formgrid">
          <label>Image</label>
          <input class="input" type="file" name="image" id="add-image" accept=".jpg,.jpeg,.png,.gif" required>
          <label>Caption</label>
          <textarea class="textarea" name="img_caption" id="add-caption"></textarea>
        </div>
        <small style="color:#64748b;display:block;margin-top:10px;">Allowed: JPG/JPEG/PNG/GIF ≤5 MB</small>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn secondary" id="cancelAdd">Cancel</button>
        <button type="button" class="btn" style="background:#065f46;" id="saveAdd">Save</button>
      </div>
    </div>
  </div>
</form>
</div>

<!-- ===== ABOUT US MANAGEMENT (MAX 5 PHOTOS + HISTORY) ===== -->
<div class="container">
  <h2 style="margin-top:20px;margin-bottom:6px;">Home Page • About Us Section</h2>
  <p style="margin:0 0 16px;color:#64748b;font-size:0.92rem;">
    Manage up to <strong>5 photos</strong> and the <strong>history text</strong> shown in the About Us section.
  </p>

  <form method="post" enctype="multipart/form-data" id="aboutForm">
    <input type="hidden" name="action" id="about-action">
    <input type="hidden" name="about_image_id" id="about-image-id">
    <input type="hidden" name="about_caption" id="about-caption-hidden">
    <textarea name="about_history_text" id="about-history-hidden" style="display:none"></textarea>

    <section class="card" style="margin-bottom:24px;">
      <h3 style="margin:0 0 12px;font-size:1.05rem;display:flex;align-items:center;gap:8px;">
        <i class="fa-solid fa-images"></i> About Us Photos
      </h3>
      <p style="margin:0 0 12px;color:#6b7280;font-size:0.9rem;">
        These photos rotate in the About Us carousel on the main page. Maximum of 5 images.
      </p>

      <div style="display:flex;flex-wrap:wrap;gap:16px;">
        <?php if ($aboutImages): ?>
          <?php foreach ($aboutImages as $imgRow): ?>
            <?php $aid = (int)$imgRow['contentID']; ?>
            <div class="card" style="width:260px;max-width:100%;padding:10px;">
              <div style="width:100%;aspect-ratio:4/3;overflow:hidden;border-radius:8px;margin-bottom:10px;background:#e5e7eb;">
                <img src="<?php echo h($imgRow['img_file_path']); ?>" alt="About Us photo" style="width:100%;height:100%;object-fit:cover;">
              </div>
              <textarea id="about-cap-<?php echo $aid; ?>" class="textarea" style="width:100%;min-height:60px;font-size:0.85rem;"><?php echo h($imgRow['img_caption']); ?></textarea>
              <div style="display:flex;justify-content:space-between;gap:8px;margin-top:8px;">
                <button type="button" class="btn" style="flex:1;font-size:0.8rem;padding:6px 8px;" onclick="aboutSaveCaption(<?php echo $aid; ?>)">Save Caption</button>
                <button type="button" class="btn-arch" style="flex:1;font-size:0.8rem;padding:6px 8px;" onclick="aboutDeleteImage(<?php echo $aid; ?>)">Remove</button>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p style="margin:0;color:#6b7280;font-size:0.9rem;">No About Us photos yet. Use the upload panel to add your first one.</p>
        <?php endif; ?>

        <!-- Upload new photo (only if less than 5) -->
        <div class="card" style="width:260px;max-width:100%;padding:10px;border-style:dashed;border-color:#cbd5f5;">
          <h4 style="margin:0 0 8px;font-size:0.95rem;">Upload New Photo</h4>
          <?php if ($aboutCount >= 5): ?>
            <p style="margin:0 0 4px;color:#ef4444;font-size:0.85rem;">
              You already have 5 photos. Remove one to upload a new image.
            </p>
          <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:6px;">
              <input class="input" type="file" name="about_image" id="about-add-image" accept=".jpg,.jpeg,.png,.gif">
              <textarea class="textarea" name="about_caption_add" id="about-add-caption" placeholder="Optional caption..." style="min-height:60px;font-size:0.85rem;"></textarea>
              <button type="button" class="btn" style="margin-top:4px;font-size:0.85rem;padding:6px 8px;" onclick="aboutAddImage()">
                <i class="fa-solid fa-upload"></i> Upload Photo
              </button>
              <small style="color:#64748b;font-size:0.75rem;">Allowed: JPG/JPEG/PNG/GIF ≤ 5 MB</small>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="card" style="margin-bottom:24px;">
      <h3 style="margin:0 0 12px;font-size:1.05rem;display:flex;align-items:center;gap:8px;">
        <i class="fa-solid fa-book-open"></i> About Us History Text
      </h3>
      <p style="margin:0 0 10px;color:#6b7280;font-size:0.9rem;">
        This short history appears beside the About Us carousel on the main page.
      </p>
      <textarea id="about-history-visible" class="textarea" style="width:100%;min-height:120px;font-size:0.9rem;"><?php echo h($aboutHistoryRow['img_caption'] ?? ''); ?></textarea>
      <div style="display:flex;justify-content:flex-end;margin-top:10px;">
        <button type="button" class="btn" style="font-size:0.85rem;padding:6px 12px;" onclick="aboutSaveHistory()">
          <i class="fa-solid fa-floppy-disk"></i> Save History
        </button>
      </div>
    </section>
  </form>
</div>
<!-- ===== END ABOUT US MANAGEMENT ===== -->

<script>
const modal=document.getElementById('modalAdd'),backdrop=document.getElementById('modalBackdrop'),
openAdd=document.getElementById('openAdd'),closeAdd=document.getElementById('closeAdd'),
cancelAdd=document.getElementById('cancelAdd'),saveAdd=document.getElementById('saveAdd'),
form=document.getElementById('oneForm'),act=document.getElementById('global-action');

function showModal(){modal.style.display='flex';backdrop.style.display='block'}
function hideModal(){modal.style.display='none';backdrop.style.display='none'}
openAdd?.addEventListener('click',showModal);
[closeAdd,cancelAdd,backdrop].forEach(el=>el?.addEventListener('click',hideModal));
saveAdd?.addEventListener('click',()=>{act.value='add';form.submit()});

function toggleEdit(id,on){
  const v=document.getElementById('cap-view-'+id),e=document.getElementById('cap-edit-'+id);
  if(v&&e){v.style.display=on?'none':'block';e.style.display=on?'block':'none';}
}
function saveEdit(id){
  act.value='edit';
  document.getElementById('global-edit-id').value=id;
  document.getElementById('global-new-caption').value=document.getElementById('cap-textarea-'+id).value;
  form.submit();
}
function doDelete(id){
  Swal.fire({
    title:'Are you sure?',
    text:'This slider item will be deleted permanently.',
    icon:'warning',
    showCancelButton:true,
    confirmButtonColor:'#991b1b',
    cancelButtonColor:'#6b7280',
    confirmButtonText:'Delete'
  }).then((r)=>{
    if(r.isConfirmed){
      act.value='delete';
      document.getElementById('global-delete-id').value=id;
      form.submit();
    }
  });
}

/* ABOUT US JS */
const aboutForm = document.getElementById('aboutForm');

function aboutAddImage(){
  if(!aboutForm) return;
  document.getElementById('about-action').value='about_add_image';
  aboutForm.submit();
}
function aboutSaveCaption(id){
  if(!aboutForm) return;
  const capEl = document.getElementById('about-cap-'+id);
  if(!capEl) return;
  document.getElementById('about-action').value='about_edit_image';
  document.getElementById('about-image-id').value=id;
  document.getElementById('about-caption-hidden').value=capEl.value;
  aboutForm.submit();
}
function aboutDeleteImage(id){
  if(!aboutForm) return;
  Swal.fire({
    title:'Remove this photo?',
    text:'This About Us photo will be deleted permanently.',
    icon:'warning',
    showCancelButton:true,
    confirmButtonColor:'#991b1b',
    cancelButtonColor:'#6b7280',
    confirmButtonText:'Remove'
  }).then((r)=>{
    if(r.isConfirmed){
      document.getElementById('about-action').value='about_delete_image';
      document.getElementById('about-image-id').value=id;
      aboutForm.submit();
    }
  });
}
function aboutSaveHistory(){
  if(!aboutForm) return;
  const txt = document.getElementById('about-history-visible')?.value || '';
  document.getElementById('about-action').value='about_update_history';
  document.getElementById('about-history-hidden').value = txt;
  aboutForm.submit();
}

/* Toast */
<?php if($toast): ?>
Swal.fire({
  toast:true,
  position:'top-end',
  icon:'<?php echo $toast['type'];?>',
  title:'<?php echo addslashes($toast['msg']);?>',
  showConfirmButton:false,
  timer:2000,
  timerProgressBar:true
});
<?php endif; ?>
</script>
</body>
</html>