<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db-connection.php';
mysqli_set_charset($db_connection, 'utf8mb4');

$TABLE = 'content_management_table';

/* ✅ NEW: table for “Add a service” */
$SERVICE_TABLE = 'service_list';

/* =====================================================================
   ADMIN BASICS + HELPERS + AUDIT
===================================================================== */

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

/* HTML escape helper */
if (!function_exists('h')) {
  function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
}

/* Human-readable change notes (ONLY changed fields) */
if (!function_exists('build_changed_service_notes')) {
  function build_changed_service_notes(array $before, array $after): string {
    $labels = [
      'content_type'    => 'Type',
      'img_caption'     => 'Caption',
      'img_file_path'   => 'Image Path',
      'status'          => 'Status',
      'dedication_age'  => 'Dedication Age',
    ];
    $changes = [];
    foreach ($labels as $k=>$label) {
      $old = isset($before[$k]) ? (string)$before[$k] : '';
      $new = isset($after[$k])  ? (string)$after[$k]  : '';
      if ($old !== $new) {
        $changes[] = "Changed {$label}: ".($old===''?'—':$old)." → ".($new===''?'—':$new);
      }
    }
    return $changes ? ('Updated service item — '.implode('; ', $changes))
                    : 'Updated service item — No values changed.';
  }
}

/* Audit helper (actions: UPDATE, ARCHIVE, etc.) */
if (!function_exists('audit_content_action')) {
  function audit_content_action(
    mysqli $db,
    string $action,              // UPDATE | ARCHIVE | INSERT | DELETE
    string $recordPk,
    array  $detailsAfterArr = [],
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

    $form   = 'content-management_service.php';
    $source = 'content_management_table';

    if ($notes === '') {
      $notes = $action==='UPDATE'  ? 'Updated service item'
            : ($action==='ARCHIVE' ? 'Archived service item'
            : ($action==='INSERT'  ? 'Added service item'
            : ($action==='DELETE'  ? 'Deleted service item'
                                   : 'Content action')));
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

/* Pre-capture BEFORE rows for auditing */
$GLOBALS['__service_before_edit']    = null;
$GLOBALS['__service_before_archive'] = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (isset($_POST['edit_modal_id'])) {
    $id = (int)$_POST['edit_modal_id'];
    if ($id>0 && ($st = mysqli_prepare(
      $db_connection,
      "SELECT contentID,img_file_path,img_caption,content_type,status,dedication_age
       FROM {$TABLE} WHERE contentID=? LIMIT 1"
    ))) {
      mysqli_stmt_bind_param($st,'i',$id);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $GLOBALS['__service_before_edit'] = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
      mysqli_stmt_close($st);
    }
  }
  if (isset($_POST['archive_id'])) {
    $id = (int)$_POST['archive_id'];
    if ($id>0 && ($st = mysqli_prepare(
      $db_connection,
      "SELECT contentID,img_file_path,img_caption,content_type,status,dedication_age
       FROM {$TABLE} WHERE contentID=? LIMIT 1"
    ))) {
      mysqli_stmt_bind_param($st,'i',$id);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $GLOBALS['__service_before_archive'] = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
      mysqli_stmt_close($st);
    }
  }
}

/* Shutdown hook to perform audits AFTER handlers run */
register_shutdown_function(function() use ($db_connection, $TABLE) {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;

  // EDIT -> UPDATE audit
  if (isset($_POST['edit_modal_id'])) {
    $id = (int)$_POST['edit_modal_id'];
    if ($id>0) {
      $after = null;
      if ($st = mysqli_prepare(
        $db_connection,
        "SELECT contentID,img_file_path,img_caption,content_type,status,dedication_age
         FROM {$TABLE} WHERE contentID=? LIMIT 1"
      )) {
        mysqli_stmt_bind_param($st,'i',$id);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $after = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
        mysqli_stmt_close($st);
      }
      $before = $GLOBALS['__service_before_edit'] ?: [];
      if ($after) {
        $notes = build_changed_service_notes($before ?: [], $after ?: []);
        $audit = audit_content_action($db_connection, 'UPDATE', (string)$id, [
          'contentID'        => (string)$after['contentID'],
          'content_type'     => (string)($after['content_type'] ?? ''),
          'img_caption'      => (string)($after['img_caption'] ?? ''),
          'img_file_path'    => (string)($after['img_file_path'] ?? ''),
          'status'           => (string)($after['status'] ?? ''),
          'dedication_age'   => (string)($after['dedication_age'] ?? ''),
        ], $notes);
        if (!$audit['ok']) error_log('[AUDIT][service-update]['.$audit['attempt'].'] '.$audit['msg']);
      }
    }
  }

  // ARCHIVE -> ARCHIVE audit
  if (isset($_POST['archive_id'])) {
    $id = (int)$_POST['archive_id'];
    if ($id>0) {
      $row = null;
      if ($st = mysqli_prepare($db_connection, "SELECT contentID,img_caption,status FROM {$TABLE} WHERE contentID=? LIMIT 1")) {
        mysqli_stmt_bind_param($st,'i',$id);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $row = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
        mysqli_stmt_close($st);
      }
      if ($row && strtolower((string)($row['status'] ?? '')) === 'inactive') {
        $cap = (string)($row['img_caption'] ?? '');
        $notes = "Archived service item — ID {$id}".($cap!=='' ? " | Caption: {$cap}" : '');
        $audit = audit_content_action($db_connection, 'ARCHIVE', (string)$id, [
          'contentID' => (string)$row['contentID'],
          'status'    => 'Inactive',
        ], $notes);
        if (!$audit['ok']) error_log('[AUDIT][service-archive]['.$audit['attempt'].'] '.$audit['msg']);
      }
    }
  }
});

/* ---------- Helper Functions (page-specific) ---------- */
function norm($s){ return strtolower(trim((string)$s)); }

function resolveImgSmart(?string $dbPath, string $fallbackRel = 'image/placeholder.png'): string {
    $dbPath = trim((string)$dbPath);
    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    if ($scriptDir === '/') $scriptDir = '';

    if ($dbPath !== '' && preg_match('#^https?://#i', $dbPath)) return $dbPath;

    $candidates = [];
    if ($dbPath !== '') {
        $candidates[] = $dbPath;
        $candidates[] = '/'.ltrim($dbPath, '/');
        $candidates[] = ($scriptDir?:'').'/'.ltrim($dbPath, '/');
    }
    $candidates[] = $fallbackRel;
    $candidates[] = ($scriptDir?:'').'/'.ltrim($fallbackRel, '/');

    foreach ($candidates as $web) {
        if (!preg_match('#^https?://#i', $web)) {
            $abs = rtrim($_SERVER['DOCUMENT_ROOT'], '/').'/'.ltrim($web, '/');
            if (is_file($abs)) return $web;
        } else return $web;
    }
    return $candidates[0];
}

/* =====================================================================
   ✅ NEW: ADD SERVICE (stores to service_list)
   - Upload image -> service_image (stores file path)
   - Text field -> service_description
   - (also includes service_name because your table has it)
===================================================================== */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['add_service_submit'])) {

    $serviceName = trim((string)($_POST['service_name'] ?? ''));
    $serviceDesc = trim((string)($_POST['service_description'] ?? ''));

    $newServiceImagePath = null;

    // image upload
    if (!empty($_FILES['service_image']) && ($_FILES['service_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $allowedExt = ['png','jpg','jpeg'];
        $folder = "uploads_service_list/"; // ✅ separate folder
        if (!is_dir($folder)) mkdir($folder, 0777, true);

        $origName = (string)($_FILES['service_image']['name'] ?? '');
        $tmpName  = (string)($_FILES['service_image']['tmp_name'] ?? '');

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExt, true) && $tmpName !== '') {
            $safeName = preg_replace('/[^A-Za-z0-9_\.-]/','_', $origName);
            $fname  = time().'_'.mt_rand(1000,9999).'_'.$safeName;
            $target = $folder.$fname;

            if (move_uploaded_file($tmpName, $target)) {
                $newServiceImagePath = $target; // store path into LONGTEXT
            }
        }
    }

    // Insert to service_list
    $okAdd = false;

    // If your DB columns are NOT NULL, keep serviceName required
    if ($serviceName !== '' && $serviceDesc !== '') {
        $sqlIns = "INSERT INTO {$SERVICE_TABLE} (service_image, service_name, service_description) VALUES (?,?,?)";
        if ($st = mysqli_prepare($db_connection, $sqlIns)) {
            // if no image uploaded, store NULL
            $imgVal = $newServiceImagePath; // string|null
            mysqli_stmt_bind_param($st, "sss", $imgVal, $serviceName, $serviceDesc);
            $okAdd = mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        }
    }

    header("Location: ".$_SERVER['PHP_SELF']."?addsvc=".($okAdd?'1':'0'));
    exit;
}

/* ---------- Archive ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_id'])) {
    $id = (int)$_POST['archive_id'];
    if ($id > 0) {
        $stmt = mysqli_prepare($db_connection, "UPDATE {$TABLE} SET status='Inactive' WHERE contentID=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $ok = mysqli_stmt_affected_rows($stmt) > 0;
        mysqli_stmt_close($stmt);
        header("Location: ".$_SERVER['PHP_SELF']."?arch=".($ok?'1':'0'));
        exit;
    }
}

/* =====================================================================
   Dedicated helper that updates dedication_age (dedication only)
===================================================================== */
function update_dedication_age(mysqli $db, string $table, int $contentId, ?int $age): bool {
    if ($contentId <= 0) return false;

    if ($age === null || $age < 0) $age = null;

    // Only update if this record is Dedication / Child Dedication
    $ct = '';
    if ($st = mysqli_prepare($db, "SELECT content_type FROM {$table} WHERE contentID=? LIMIT 1")) {
        mysqli_stmt_bind_param($st, "i", $contentId);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        if ($row = mysqli_fetch_assoc($res)) $ct = trim((string)($row['content_type'] ?? ''));
        mysqli_stmt_close($st);
    }

    $t = strtolower($ct);
    $isDedication = ($t === 'dedication' || $t === 'child dedication' || strpos($t, 'dedication') !== false);
    if (!$isDedication) return true; // not dedication => do nothing, but don't fail the whole save

    if ($age === null) {
        $sql = "UPDATE {$table} SET dedication_age=NULL WHERE contentID=? LIMIT 1";
        if ($st2 = mysqli_prepare($db, $sql)) {
            mysqli_stmt_bind_param($st2, "i", $contentId);
            mysqli_stmt_execute($st2);
            $ok = mysqli_stmt_affected_rows($st2) >= 0;
            mysqli_stmt_close($st2);
            return $ok;
        }
        return false;
    }

    $sql = "UPDATE {$table} SET dedication_age=? WHERE contentID=? LIMIT 1";
    if ($st2 = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($st2, "ii", $age, $contentId);
        mysqli_stmt_execute($st2);
        $ok = mysqli_stmt_affected_rows($st2) >= 0;
        mysqli_stmt_close($st2);
        return $ok;
    }
    return false;
}

/* ---------- Edit (Caption + Single Image Replace + Remove Image + Dedication Age) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_modal_id'])) {
    $id  = (int)$_POST['edit_modal_id'];
    $cap = trim($_POST['edit_modal_caption'] ?? '');
    $removeImage = !empty($_POST['remove_image']); // checkbox

    // dedication_age input from modal (only applies to dedication)
    $ageRaw = trim((string)($_POST['edit_modal_dedication_age'] ?? ''));
    $dedicationAge = ($ageRaw === '') ? null : (int)$ageRaw;

    $updated_ok = false;

    if ($id > 0) {
        $newPath = null;

        // ✅ SINGLE IMAGE UPLOAD (replace current image)
        if (!empty($_FILES['edit_modal_img']) && ($_FILES['edit_modal_img']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $allowedExt = ['png','jpg','jpeg'];
            $folder = "uploads_service/";
            if (!is_dir($folder)) {
                mkdir($folder, 0777, true);
            }

            $origName = (string)($_FILES['edit_modal_img']['name'] ?? '');
            $tmpName  = (string)($_FILES['edit_modal_img']['tmp_name'] ?? '');

            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExt, true) && $tmpName !== '') {
                $safeName = preg_replace('/[^A-Za-z0-9_\.-]/','_', $origName);
                $fname  = time().'_'.mt_rand(1000,9999).'_'.$safeName;
                $target = $folder.$fname;

                if (move_uploaded_file($tmpName, $target)) {
                    $newPath = $target;
                }
            }
        }

        // UPDATE row
        if ($newPath !== null) {
            // new image replaces old
            $stmt = mysqli_prepare($db_connection, "UPDATE {$TABLE} SET img_caption=?, img_file_path=? WHERE contentID=? LIMIT 1");
            mysqli_stmt_bind_param($stmt, "ssi", $cap, $newPath, $id);
        } else {
            if ($removeImage) {
                $stmt = mysqli_prepare($db_connection, "UPDATE {$TABLE} SET img_caption=?, img_file_path='0' WHERE contentID=? LIMIT 1");
                mysqli_stmt_bind_param($stmt, "si", $cap, $id);
            } else {
                $stmt = mysqli_prepare($db_connection, "UPDATE {$TABLE} SET img_caption=? WHERE contentID=? LIMIT 1");
                mysqli_stmt_bind_param($stmt, "si", $cap, $id);
            }
        }

        mysqli_stmt_execute($stmt);
        $updated_ok = mysqli_stmt_affected_rows($stmt) >= 0; // allow "no change"
        mysqli_stmt_close($stmt);

        // Update dedication_age (only if dedication)
        $ageOk = update_dedication_age($db_connection, $TABLE, $id, $dedicationAge);
        $updated_ok = $updated_ok && $ageOk;
    }

    header("Location: ".$_SERVER['PHP_SELF']."?edit=".($updated_ok?'1':'0'));
    exit;
}

/* ---------- Load Service Rows ---------- */
$rows = [];
$sql = "
SELECT contentID, img_file_path, img_caption, content_type, status, dedication_age
FROM {$TABLE}
WHERE LOWER(content_type) IN (
  'preach','sermon','homily','dedication','child dedication',
  'wedding','marriage','matrimony','house','house blessing','home blessing',
  'funeral','memorial','burial','baptism','water baptism','baptize',
  'prayer','intercessory'
)
ORDER BY contentID DESC";
if ($res = mysqli_query($db_connection, $sql)) {
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    mysqli_free_result($res);
}

/* ---------- Grouping ---------- */
$TYPE_GROUPS = [
  'baptism'=>['baptism','water baptism','baptize'],
  'dedication'=>['dedication','child dedication'],
  'preach'=>['preach','sermon','homily'],
  'wedding'=>['wedding','marriage','matrimony'],
  'house'=>['house','house blessing','home blessing'],
  'funeral'=>['funeral','memorial','burial'],
  'prayer'=>['prayer','intercessory'],
];
$LABELS = [
  'baptism'=>'Water Baptism','dedication'=>'Dedication',
  'preach'=>'Preaching of the Word','wedding'=>'Wedding',
  'house'=>'House Blessing','funeral'=>'Funeral','prayer'=>'Prayer'
];
$DISPLAY_ORDER = ['baptism','dedication','preach','wedding','house','funeral','prayer'];
$byType = [];
foreach ($rows as $r) {
    $t = norm($r['content_type'] ?? '');
    foreach ($TYPE_GROUPS as $canon=>$aliases)
        foreach ($aliases as $a)
            if ($t===norm($a)||strpos($t,norm($a))!==false){$byType[$canon][]=$r;break 2;}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Content Management - Service</title>
<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="/HTCCC-SYSTEM/css/content-management_service.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/content-management_service.css'); ?>">

<style>
/* ✅ small helper styles for the new Add Service button/modal */
.cm-actions-bar{
  display:flex;
  justify-content:flex-end;
  margin: 14px 0;
}
.add-service-btn{
  background:#0B3B8F;
  color:#fff;
  border:none;
  padding:10px 16px;
  border-radius:12px;
  cursor:pointer;
  font-weight:600;
}
.add-service-btn:hover{ opacity:.95; }
</style>
</head>
<body>
<header class="cm-header">
  <a href="secretary_dashboard.php"><img src="image/btn-back.png" alt="Back" style="width:30px;height:30px;cursor:pointer;margin-right: 1300px;"></a>
  <div class="cm-center">
    <img src="image/httc_main-logo.jpg" class="cm-logo" alt="Logo">
    <h1 class="cm-title">CONTENT MANAGEMENT</h1>
  </div>
</header>

<nav>
  <div class="nav-wrap">
    <a class="nav-pill" href="content-management_home-page.php"><i class="fa-solid fa-house"></i> Home Page</a>
    <a class="nav-pill active" href="#"><i class="fa-solid fa-hands-praying"></i> Service</a>
    <a class="nav-pill" href="content-management_events.php"><i class="fa-solid fa-calendar-days"></i> Events</a>
    <a class="nav-pill" href="content-management_gallery.php"><i class="fa-solid fa-images"></i> Gallery</a>
    <a class="nav-pill" href="content-management_ministries.php"><i class="fa-solid fa-people-group"></i> Ministries</a>
    <a class="nav-pill" href="content-management_join-us.php"><i class="fa-solid fa-user-plus"></i> Join Us</a>
    <a class="nav-pill" href="content-management_find-us.php"><i class="fa-solid fa-location-dot"></i> Find Us</a>
  </div>
</nav>

<main>
  <!-- ✅ NEW: Add Service button -->
  <div class="cm-actions-bar">
    <button type="button" class="add-service-btn" onclick="openAddServiceModal()">
      <i class="fa-solid fa-plus"></i> Add Service
    </button>
  </div>

  <div class="table-wrap">
    <table>
      <thead><tr><th>IMAGE</th><th>TYPE</th><th>CAPTION</th><th>ACTION</th></tr></thead>
      <tbody>
        <?php $any=false; foreach($DISPLAY_ORDER as $canon):
          if(empty($byType[$canon])) continue; $any=true;
          $r=$byType[$canon][0]; $id=$r['contentID'];
          $label=$LABELS[$canon]??ucfirst($canon);
          $img=resolveImgSmart($r['img_file_path']); $caption=$r['img_caption']??'';
          $status=strtolower($r['status']??'active');
          $dedAge = $r['dedication_age'] ?? '';
          $rawType = (string)($r['content_type'] ?? '');
          ?>
        <tr>
          <td><img class="thumb" src="<?php echo h($img);?>" alt=""></td>
          <td><?php echo h($label);?> <br><span class="badge <?php echo $status==='inactive'?'inactive':'active';?>"><?php echo strtoupper(h($status));?></span></td>
          <td><?php echo $caption!==''?h($caption):'<em>No caption</em>';?></td>
          <td>
            <button type="button" class="edit-btn"
              data-id="<?php echo (int)$id;?>"
              data-caption="<?php echo h($caption);?>"
              data-img="<?php echo h($img);?>"
              data-type="<?php echo h($rawType);?>"
              data-dedication-age="<?php echo h($dedAge);?>"
              onclick="openEditModal(this)">EDIT</button>
            <form method="post" onsubmit="return confirmArchive(this)" style="display:inline;">
              <input type="hidden" name="archive_id" value="<?php echo (int)$id;?>">
              <button type="submit" class="archive-btn" <?php echo $status==='inactive'?'disabled':'';?>>ARCHIVE</button>
            </form>
          </td>
        </tr>
        <?php endforeach; if(!$any):?><tr><td colspan="4" style="text-align:center;">No records.</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
</main>

<!-- ======= EDIT MODAL ======= -->
<div class="modal-backdrop" id="editModal">
  <div class="modal">
    <h2>Edit Service Item</h2>
    <!-- ✅ keep multipart because we allow SINGLE image replace -->
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="edit_modal_id" id="em_id">

      <label>Caption</label>
      <textarea name="edit_modal_caption" id="em_caption" rows="4"></textarea>

      <!-- ✅ DEDICATION AGE (only shown when type is dedication) -->
      <div id="dedicationAgeWrap" style="display:none;margin-top:10px;">
        <label>Dedication Age</label>
        <input
          type="number"
          name="edit_modal_dedication_age"
          id="em_dedication_age"
          min="0"
          step="1"
          style="width:100%;padding:10px;border-radius:10px;border:1px solid #ccc;"
          placeholder="Enter dedication age">
        <small style="display:block;margin-top:4px;font-size:12px;color:#555;">
          This field only updates for Dedication / Child Dedication.
        </small>
      </div>

      <!-- REMOVE CURRENT IMAGE -->
      <label style="margin-top:8px;display:flex;align-items:center;gap:6px;font-size:13px;">
        <input type="checkbox" name="remove_image" value="1">
        Remove current image for this service item
      </label>

      <!-- ✅ SINGLE IMAGE UPLOAD (replaces current image) -->
      <label style="margin-top:10px;">Upload New Image (replaces current)</label>
      <input type="file" name="edit_modal_img" id="em_file" accept="image/png,image/jpeg">

      <div style="text-align:center;margin-top:10px;">
        <img id="em_preview" src="" alt="Preview"
             style="width:140px;border-radius:10px;display:none;">
      </div>

      <div class="actions">
        <button type="button" class="btn cancel" onclick="closeEditModal()">Cancel</button>
        <button type="submit" class="btn save">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- ======= ✅ NEW: ADD SERVICE MODAL (stores to service_list) ======= -->
<div class="modal-backdrop" id="addServiceModal" style="display:none;">
  <div class="modal">
    <h2>Add a service</h2>

    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="add_service_submit" value="1">

      <!-- ✅ Upload image = Mark as Service Image -->
      <label>Service Image</label>
      <input type="file" name="service_image" id="as_image" accept="image/png,image/jpeg">

      <div style="text-align:center;margin-top:10px;">
        <img id="as_preview" src="" alt="Preview" style="width:140px;border-radius:10px;display:none;">
      </div>

      <!-- ✅ Name (because your table has service_name) -->
      <label style="margin-top:10px;">Service Name</label>
      <input
        type="text"
        name="service_name"
        id="as_name"
        style="width:100%;padding:10px;border-radius:10px;border:1px solid #ccc;"
        placeholder="Enter service name"
        required
      >

      <!-- ✅ Text field = Mark as Service Description -->
      <label style="margin-top:10px;">Service Description</label>
      <textarea
        name="service_description"
        id="as_desc"
        rows="4"
        style="width:100%;padding:10px;border-radius:10px;border:1px solid #ccc;"
        placeholder="Enter service description"
        required
      ></textarea>

      <div class="actions">
        <button type="button" class="btn cancel" onclick="closeAddServiceModal()">Cancel</button>
        <button type="submit" class="btn save">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
function isDedicationType(t){
  t = (t || '').toLowerCase().trim();
  return t === 'dedication' || t === 'child dedication' || t.indexOf('dedication') !== -1;
}

function openEditModal(btn){
  const m = document.getElementById('editModal');
  const preview = document.getElementById('em_preview');
  const caption = document.getElementById('em_caption');
  const idInput = document.getElementById('em_id');

  const ageWrap = document.getElementById('dedicationAgeWrap');
  const ageInput = document.getElementById('em_dedication_age');

  idInput.value = btn.dataset.id || '';
  caption.value = btn.dataset.caption || '';

  // reset remove-image checkbox
  const chk = document.querySelector('input[name="remove_image"]');
  if (chk) chk.checked = false;

  // reset file input so old selection doesn't persist
  const file = document.getElementById('em_file');
  if (file) file.value = '';

  // dedication age: show only if dedication
  const type = btn.dataset.type || '';
  if (isDedicationType(type)) {
    ageWrap.style.display = 'block';
    ageInput.value = btn.dataset.dedicationAge || '';
  } else {
    ageWrap.style.display = 'none';
    ageInput.value = '';
  }

  // show existing image as preview initially
  const imgPath = btn.dataset.img || '';
  if (imgPath && imgPath.indexOf('placeholder') === -1) {
    preview.src = imgPath;
    preview.style.display = 'inline-block';
  } else {
    preview.src = '';
    preview.style.display = 'none';
  }

  m.style.display = 'flex';
}

function closeEditModal(){
  document.getElementById('editModal').style.display='none';
}

/* preview newly selected file (edit) */
(function initPreview(){
  const input = document.getElementById('em_file');
  const preview = document.getElementById('em_preview');
  if (!input || !preview) return;

  input.addEventListener('change', function(e){
    const file = e.target.files && e.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function(ev){
      preview.src = ev.target.result;
      preview.style.display = 'inline-block';
    };
    reader.readAsDataURL(file);
  });
})();

/* ✅ NEW: Add service modal open/close */
function openAddServiceModal(){
  const m = document.getElementById('addServiceModal');
  const img = document.getElementById('as_image');
  const prev = document.getElementById('as_preview');
  const name = document.getElementById('as_name');
  const desc = document.getElementById('as_desc');

  if (img) img.value = '';
  if (prev) { prev.src=''; prev.style.display='none'; }
  if (name) name.value = '';
  if (desc) desc.value = '';

  m.style.display = 'flex';
}
function closeAddServiceModal(){
  document.getElementById('addServiceModal').style.display='none';
}

/* ✅ NEW: preview image in Add service modal */
(function initAddServicePreview(){
  const input = document.getElementById('as_image');
  const preview = document.getElementById('as_preview');
  if (!input || !preview) return;

  input.addEventListener('change', function(e){
    const file = e.target.files && e.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function(ev){
      preview.src = ev.target.result;
      preview.style.display = 'inline-block';
    };
    reader.readAsDataURL(file);
  });
})();

/* Keep modals always on top */
document.getElementById('editModal').style.zIndex='99999';
document.getElementById('addServiceModal').style.zIndex='99999';

/* Confirm archive */
function confirmArchive(form){
  if (typeof Swal === 'undefined') return true;
  Swal.fire({
    title:'Archive this item?',
    text:'Status will become Inactive.',
    icon:'warning',
    showCancelButton:true,
    confirmButtonColor:'#0B3B8F',
    cancelButtonColor:'#6b7280'
  }).then(function(r){
    if(r.isConfirmed) form.submit();
  });
  return false;
}

/* Toast alerts (no new URL() to avoid bugs) */
(function(){
  if (typeof Swal === 'undefined') return;

  var query = window.location.search ? window.location.search.substring(1) : '';
  var params = {};
  if (query) {
    var parts = query.split('&');
    for (var i = 0; i < parts.length; i++) {
      if (!parts[i]) continue;
      var pair = parts[i].split('=');
      var key = decodeURIComponent(pair[0] || '');
      var val = decodeURIComponent(pair[1] || '');
      params[key] = val;
    }
  }

  var a = params.arch;
  var e = params.edit;
  var s = params.addsvc; // ✅ NEW

  if (a) {
    Swal.fire({
      toast:true,
      icon:a==='1'?'success':'error',
      title:a==='1'?'Item archived.':'Archive failed.',
      position:'top-end',
      showConfirmButton:false,
      timer:2000
    });
  }

  if (e) {
    Swal.fire({
      toast:true,
      icon:e==='1'?'success':'error',
      title:e==='1'?'Changes saved.':'Save failed.',
      position:'top-end',
      showConfirmButton:false,
      timer:2000
    });
  }

  // ✅ NEW: Add service toast
  if (s) {
    Swal.fire({
      toast:true,
      icon:s==='1'?'success':'error',
      title:s==='1'?'Service added.':'Failed to add service.',
      position:'top-end',
      showConfirmButton:false,
      timer:2000
    });
  }
})();
</script>
</body>
</html>
