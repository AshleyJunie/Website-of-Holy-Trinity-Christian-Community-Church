<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db-connection.php';
mysqli_set_charset($db_connection, 'utf8mb4');

/* =====================================================================
   ADMIN BASICS + HELPERS + AUDIT  (same pattern as your reference)
===================================================================== */

/* Backfill admin basics in session (username/email) */
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

/* HTML escape */
if (!function_exists('h')) {
  function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
}

/* Changed fields -> human readable notes */
if (!function_exists('build_changed_gallery_notes')) {
  function build_changed_gallery_notes(array $before, array $after): string {
    $labels = [
      'title'      => 'Title',
      'month'      => 'Month',
      'album_type' => 'Album Type',
      'imgSrc'     => 'Image Path',
      'imgAlt'     => 'Alt Text',
      'details'    => 'Details',
      'status'     => 'Status',
    ];
    $changes = [];
    foreach ($labels as $k=>$label) {
      $old = isset($before[$k]) ? (string)$before[$k] : '';
      $new = isset($after[$k])  ? (string)$after[$k]  : '';
      if ($old !== $new) {
        $changes[] = "Changed {$label}: ".($old===''?'—':$old)." → ".($new===''?'—':$new);
      }
    }
    return $changes ? ('Updated gallery item — '.implode('; ', $changes))
                    : 'Updated gallery item — No values changed.';
  }
}

/* Audit writer (JSON → retry → MINIMAL) */
if (!function_exists('audit_gallery_action')) {
  function audit_gallery_action(mysqli $db, string $action, string $recordPk, array $after = [], string $notes = ''): array {
    $actorId    = (int)($_SESSION['admin_id']   ?? 0);
    $actorUser  = (string)($_SESSION['admin_user']  ?? '');
    $actorEmail = (string)($_SESSION['admin_email'] ?? '');

    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $detailsAfterJson = $after ? json_encode($after, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : null;

    $form   = 'content-management_gallery.php';
    $source = 'gallery_table';

    if ($notes === '') {
      $notes = $action==='INSERT'  ? 'Added gallery item'
            : ($action==='UPDATE' ? 'Updated gallery item'
            : ($action==='DELETE' ? 'Deleted gallery item'
                                   : 'Gallery action'));
    }

    $sql = "INSERT INTO audit_trail
            (txn_id, actor_admin_id, actor_username, actor_email,
             action, source_table, record_pk, form_name,
             ip_address, user_agent, notes, details_before, details_after)
            VALUES
            (UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)";
    if ($st = mysqli_prepare($db, $sql)) {
      mysqli_stmt_bind_param($st, "issssssssss",
        $actorId, $actorUser, $actorEmail, $action, $source, $recordPk, $form, $ip, $ua, $notes, $detailsAfterJson
      );
      $ok = mysqli_stmt_execute($st);
      $err = $ok ? '' : mysqli_error($db);
      mysqli_stmt_close($st);
      if ($ok) return ['ok'=>true,'attempt'=>'JSON','msg'=>''];

      // retry once
      if ($st = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($st, "issssssssss",
          $actorId, $actorUser, $actorEmail, $action, $source, $recordPk, $form, $ip, $ua, $notes, $detailsAfterJson
        );
        $ok2 = mysqli_stmt_execute($st);
        $err2 = $ok2 ? '' : mysqli_error($db);
        mysqli_stmt_close($st);
        if ($ok2) return ['ok'=>true,'attempt'=>'JSON-RETRY','msg'=>$err];
        $last = $err2 ?: $err;
      } else { $last = mysqli_error($db) ?: $err; }
    } else {
      $last = mysqli_error($db) ?: 'prepare_failed';
    }

    // MINIMAL
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

/* =====================================================================
   ACTION HANDLERS (same-file: add / edit / delete) + Pre-capture
===================================================================== */

$toast = null;
$before_edit  = null;
$before_delete= null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $action = $_POST['action'] ?? '';

  // Pre-capture BEFORE for diffing/notes
  if (in_array($action, ['edit','delete'], true)) {
    $gid = (int)($_POST['galleryId'] ?? 0);
    if ($gid>0 && ($st = mysqli_prepare($db_connection, "SELECT * FROM gallery_table WHERE galleryId=? LIMIT 1"))) {
      mysqli_stmt_bind_param($st,'i',$gid);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $row = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
      mysqli_stmt_close($st);
      if ($action==='edit')   $before_edit   = $row;
      if ($action==='delete') $before_delete = $row;
    }
  }

  /* ----- ADD ----- */
  if ($action === 'add') {
    $title      = trim($_POST['title'] ?? '');
    $month      = trim($_POST['month'] ?? '');
    $album_type = trim($_POST['album_type'] ?? '');
    $imgAlt     = trim($_POST['imgAlt'] ?? '');
    $details    = trim($_POST['details'] ?? '');
    $imgSrcPath = '';

    if (isset($_FILES['imgSrc']) && $_FILES['imgSrc']['error'] === 0) {
      $allowed = ['jpg','jpeg','png','gif','webp'];
      $ext = strtolower(pathinfo($_FILES['imgSrc']['name'], PATHINFO_EXTENSION));
      if (in_array($ext,$allowed,true) && ($_FILES['imgSrc']['size'] ?? 0) <= 5*1024*1024) {
        $folder = __DIR__ . '/uploads_gallery';
        if (!is_dir($folder)) @mkdir($folder,0777,true);
        $safe = preg_replace('/[^A-Za-z0-9_\-\.]+/','_', pathinfo($_FILES['imgSrc']['name'], PATHINFO_FILENAME));
        $unique = $safe.'_'.time().'_'.mt_rand(1000,9999).'.'.$ext;
        $abs = $folder.'/'.$unique;
        if (move_uploaded_file($_FILES['imgSrc']['tmp_name'],$abs)) {
          $imgSrcPath = 'uploads_gallery/'.$unique;
        }
      }
    }

    if ($imgSrcPath !== '') {
      $sql = "INSERT INTO gallery_table (title, month, album_type, imgSrc, imgAlt, details, created_at)
              VALUES (?, ?, ?, ?, ?, ?, NOW())";
      if ($st = mysqli_prepare($db_connection,$sql)) {
        mysqli_stmt_bind_param($st,'ssssss',$title,$month,$album_type,$imgSrcPath,$imgAlt,$details);
        $ok = mysqli_stmt_execute($st);
        $newId = (int)mysqli_insert_id($db_connection);
        mysqli_stmt_close($st);
        if ($ok && $newId>0) {
          // Audit INSERT
          $audit = audit_gallery_action($db_connection,'INSERT',(string)$newId,[
            'galleryId'=>(string)$newId,
            'title'=>$title,'month'=>$month,'album_type'=>$album_type,
            'imgSrc'=>$imgSrcPath,'imgAlt'=>$imgAlt,'details'=>$details
          ], "Added gallery item — Title: {$title}");
          if (!$audit['ok']) error_log('[AUDIT][gallery-insert]['.$audit['attempt'].'] '.$audit['msg']);
          header("Location: ?toast=Gallery+item+added");
          exit;
        } else {
          header("Location: ?err=Failed+to+add+item");
          exit;
        }
      } else {
        header("Location: ?err=Prepare+failed");
        exit;
      }
    } else {
      header("Location: ?err=Invalid+image+(type/size)");
      exit;
    }
  }

  /* ----- EDIT (details only, like your UI) ----- */
  if ($action === 'edit') {
    $gid = (int)($_POST['galleryId'] ?? 0);
    $details = trim($_POST['details'] ?? '');
    if ($gid>0 && ($st = mysqli_prepare($db_connection,"UPDATE gallery_table SET details=? WHERE galleryId=? LIMIT 1"))) {
      mysqli_stmt_bind_param($st,'si',$details,$gid);
      $ok = mysqli_stmt_execute($st);
      mysqli_stmt_close($st);

      // Fetch AFTER for diff/audit
      $after = null;
      if ($st2 = mysqli_prepare($db_connection,"SELECT * FROM gallery_table WHERE galleryId=? LIMIT 1")) {
        mysqli_stmt_bind_param($st2,'i',$gid);
        mysqli_stmt_execute($st2);
        $res2 = mysqli_stmt_get_result($st2);
        $after = $res2 ? (mysqli_fetch_assoc($res2) ?: null) : null;
        mysqli_stmt_close($st2);
      }
      if ($after) {
        $notes = build_changed_gallery_notes($before_edit ?: [], $after);
        $audit = audit_gallery_action($db_connection,'UPDATE',(string)$gid,[
          'galleryId'=>(string)$gid,
          'title'=>(string)($after['title'] ?? ''),
          'month'=>(string)($after['month'] ?? ''),
          'album_type'=>(string)($after['album_type'] ?? ''),
          'imgSrc'=>(string)($after['imgSrc'] ?? ''),
          'imgAlt'=>(string)($after['imgAlt'] ?? ''),
          'details'=>(string)($after['details'] ?? ''),
        ], $notes);
        if (!$audit['ok']) error_log('[AUDIT][gallery-update]['.$audit['attempt'].'] '.$audit['msg']);
      }

      header("Location: ?toast=".($ok?'Changes+saved':'Failed+to+save+changes'));
      exit;
    } else {
      header("Location: ?err=Prepare+failed");
      exit;
    }
  }

  /* ----- DELETE (hard delete like your original form) ----- */
  if ($action === 'delete') {
    $gid = (int)($_POST['galleryId'] ?? 0);
    if ($gid > 0) {
      // Try to unlink file if present
      if (!empty($before_delete['imgSrc'])) {
        $abs = rtrim($_SERVER['DOCUMENT_ROOT'],'/').'/'.ltrim($before_delete['imgSrc'],'/');
        if (is_file($abs)) @unlink($abs);
      }
      if ($st = mysqli_prepare($db_connection,"DELETE FROM gallery_table WHERE galleryId=? LIMIT 1")) {
        mysqli_stmt_bind_param($st,'i',$gid);
        $ok = mysqli_stmt_execute($st);
        mysqli_stmt_close($st);

        $title = (string)($before_delete['title'] ?? '');
        $notes = "Deleted gallery item — ID {$gid}".($title!=='' ? " (Title: {$title})" : '');
        $audit = audit_gallery_action($db_connection,'DELETE',(string)$gid,[
          'galleryId'=>(string)$gid,
          'deleted'=>'1'
        ], $notes);
        if (!$audit['ok']) error_log('[AUDIT][gallery-delete]['.$audit['attempt'].'] '.$audit['msg']);

        header("Location: ?toast=".($ok?'Item+deleted':'Delete+failed'));
        exit;
      } else {
        header("Location: ?err=Prepare+failed");
        exit;
      }
    }
  }
}

/* =====================================================================
   FETCH LIST + ALBUM TYPE OPTIONS
===================================================================== */

/* Fetch gallery rows (newest first) */
$items = [];
$sql = "SELECT galleryId, title, month, imgSrc, imgAlt, details, created_at, album_type
        FROM gallery_table
        ORDER BY galleryId DESC";
$res = mysqli_query($db_connection, $sql);
if ($res) { while ($r = mysqli_fetch_assoc($res)) $items[] = $r; }

/* Unique album types for datalist */
$album_types = [];
$q2 = "SELECT DISTINCT album_type FROM gallery_table
       WHERE album_type IS NOT NULL AND album_type <> ''
       ORDER BY album_type ASC";
$r2 = mysqli_query($db_connection, $q2);
if ($r2) { while ($row = mysqli_fetch_assoc($r2)) $album_types[] = $row['album_type']; }

/* Safe CSS version */
$cssFs  = $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/content-management_gallery.css';
$cssVer = is_file($cssFs) ? filemtime($cssFs) : time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=1920, initial-scale=1" />
<title>Content Management - Gallery</title>
<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" />
<link rel="stylesheet" href="/HTCCC-SYSTEM/css/content-management_gallery.css?v=<?php echo $cssVer; ?>">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>

  /* Centered toolbar (Add + Sort by) */
  .gallery-toolbar-center{
    display:flex;
    justify-content:center;
    align-items:center;
    gap:.75rem;
    margin:.5rem 0 .75rem;
    flex-wrap:wrap;
  }
  .gallery-toolbar-center .add-button{
    margin:0 !important;
    border-radius:999px;
    padding:.55rem 1rem;
    height:auto;
  }
  .lf-pill{
    display:inline-flex;
    align-items:center;
    gap:.4rem;
    padding:.55rem 1rem;
    border:1px solid #e2e8f0;
    border-radius:999px;
    background:#eaf4ff;
    color:#0b1446;
    font-weight:700;
    cursor:pointer;
  }
  .lf-pill:hover{ background:#dbeafe; }

  #localGallerySorter{
    margin:.25rem auto 1rem;
    max-width:640px;
    width:100%;
  }

  .visually-remove{ display:none !important; }

/* ===== Header (centered title) ===== */
header .title-center{position:absolute;left:50%;transform:translateX(-50%);display:flex;align-items:center;gap:10px}
header .title-center img{width:60px;height:60px;border-radius:50%}
header .title-center h1{margin:0;font-size:25px}

/* ===== Dark pill-style NAV ===== */
nav{
  background:#0A0E3F;color:#fff;display:flex;justify-content:center;flex-wrap:wrap;
  gap:18px;padding:16px 0;width:100%;box-shadow:0 1px 3px rgba(0,0,0,.15)
}
.nav-pill{
  display:inline-flex;align-items:center;gap:8px;border:1px solid #304256;background:transparent;
  color:#dbeafe;padding:8px 16px;border-radius:999px;text-decoration:none;font-weight:800;opacity:.9;transition:.2s
}
.nav-pill i{font-size:16px}
.nav-pill:hover{opacity:1;background:#304256;color:#fff}
.nav-pill.active{background:#304256;color:#fff;opacity:1;box-shadow:0 0 4px rgba(255,255,255,.25)}

.gallery-layout{max-width:1300px;margin:40px auto;padding:0 30px}
.gallery-table{width:100%;border-collapse:collapse;font-family:'Inter',sans-serif;font-weight:600;color:#0B1446;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 8px 20px rgba(0,0,0,0.1)}
.gallery-table thead{background:#f8f9fa}
.gallery-table th,.gallery-table td{padding:14px 12px;text-align:center;border-bottom:1px solid #ddd}
.gallery-table th{font-size:15px;font-weight:800}
.gallery-table img{width:120px;height:120px;object-fit:cover;border-radius:6px;box-shadow:0 4px 10px rgba(0,0,0,.15);transition:transform .2s}
.gallery-table img:hover{transform:scale(1.05)}
.action-buttons{display:flex;justify-content:center;gap:18px;align-items:center}

/* Modals */
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:9999}
.modal{background:#fff;border-radius:10px;max-width:620px;width:92%;padding:20px 22px;box-shadow:0 20px 50px rgba(0,0,0,.25)}
.modal h2{margin:0 0 12px 0;color:#0B1446}
.modal label{font-weight:700;color:#0B1446}
.modal input[type="text"],.modal select,.modal textarea,.modal input[type="file"],.modal input[list]{width:100%;padding:10px 12px;border:1px solid #cfd3d7;border-radius:8px;font-family:inherit}
.modal .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.modal .actions{display:flex;justify-content:flex-end;gap:10px;margin-top:14px}
.modal .btn{border:none;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer}
.modal .btn.cancel{background:#e5e7eb;color:#111827}
.modal .btn.save{background:#0B3B4A;color:#fff}
tbody td{padding:14px 12px;}
</style>
</head>
<body>

<!-- Header -->
<header style="background-color:#0A0E3F;display:flex;align-items:center;justify-content:space-between;padding:10px 20px;color:#fff;">
  <a href="secretary_dashboard.php" class="back-button" style="display:inline-block;z-index:10;">
    <img src="image/btn-back.png" alt="Back" style="width:30px;height:30px;cursor:pointer;display:block;">
  </a>
  <div class="title-center">
    <img src="image/httc_main-logo.jpg" alt="Logo">
    <h1>CONTENT MANAGEMENT</h1>
  </div>
  <div style="width:30px;"></div>
</header>

<!-- Dark pill nav with icons -->
<nav>
  <div class="dropdown" style="position:relative">
    <a href="content-management_home-page.php" class="nav-pill"><i class="fas fa-home"></i> Home Page ▾</a>
    <div class="dropdown-content" style="display:none;position:absolute;background:#f1f1f1;min-width:220px;z-index:2;border-radius:8px;box-shadow:0 8px 16px rgba(0,0,0,.2);padding:6px;top:42px;left:0">
      <a href="content-management_home-page.php"       style="display:block;color:#2c3e50;padding:8px 10px;border-radius:6px;text-decoration:none">Carousel Image</a>
      <a href="content-management_home-page_text.php"  style="display:block;color:#2c3e50;padding:8px 10px;border-radius:6px;text-decoration:none">Doctrinal Content</a>
      <a href="content-management_apostol.php"         style="display:block;color:#2c3e50;padding:8px 10px;border-radius:6px;text-decoration:none">Apostol Creed</a>
    </div>
  </div>

  <a href="content-management_service.php"   class="nav-pill"><i class="fas fa-bell"></i> Service</a>
  <a href="content-management_events.php"    class="nav-pill"><i class="fas fa-calendar-alt"></i> Events</a>
  <a href="content-management_gallery.php"   class="nav-pill active"><i class="fas fa-image"></i> Gallery</a>
  <a href="content-management_ministries.php" class="nav-pill"><i class="fas fa-users"></i> Ministries</a>
  <a href="content-management_join-us.php"   class="nav-pill"><i class="fas fa-user-plus"></i> Join Us</a>
  <a href="content-management_find-us.php"   class="nav-pill"><i class="fas fa-map-marker-alt"></i> Find Us</a>
</nav>

<main class="gallery-layout">
  <div style="margin:10px 0 18px;">
  </div>


  <table class="gallery-table">
    <thead>
      <tr>
        <th>IMAGE</th>
        <th>TITLE</th>
        <th>MONTH</th>
        <th>ALBUM TYPE</th>
        <th>ACTION</th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($items)): foreach ($items as $row): ?>
        <tr>
          <td>
            <?php
              $src = h($row['imgSrc'] ?? '');
              $alt = h(($row['imgAlt'] ?: $row['title']));
              echo $src ? '<img src="'.$src.'" alt="'.$alt.'">' : '—';
            ?>
          </td>
          <td><?php echo h($row['title'] ?? ''); ?></td>
          <td><?php echo h($row['month'] ?? ''); ?></td>
          <td><?php echo h($row['album_type'] ?? ''); ?></td>
          <td class="action-buttons">
            <form method="POST" style="display:inline;">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="galleryId" value="<?php echo (int)$row['galleryId']; ?>">
              <button class="btn-delete" type="button" style="color:#A00B0B;font-weight:800;">
                <i class="fas fa-trash-alt"></i> DELETE
              </button>
            </form>
            <button class="btn-edit edit-details" style="color:#0B9B4A;font-weight:700;"
                    data-id="<?php echo (int)$row['galleryId']; ?>"
                    data-title="<?php echo h($row['title']); ?>"
                    data-details="<?php echo h($row['details']); ?>">
              <i class="fas fa-edit"></i> Edit Details
            </button>
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="5">No gallery items found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</main>

<!-- ===== ADD MODAL ===== -->
<div class="modal-backdrop" id="addModal">
  <div class="modal">
    <h2>Add Gallery Item</h2>
    <form id="addForm" method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add">
      <div class="grid2">
        <div>
          <label>Title</label>
          <input type="text" name="title" required>
        </div>
        <div>
          <label>Month</label>
          <input type="text" name="month" placeholder="e.g., June 2025" required>
        </div>
      </div>

      <div class="grid2" style="margin-top:12px;">
        <div>
          <label>Album Type</label>
          <input list="album_types" name="album_type" placeholder="Choose or type new..." required>
          <datalist id="album_types">
            <?php foreach ($album_types as $type): ?>
              <option value="<?php echo h($type); ?>"></option>
            <?php endforeach; ?>
          </datalist>
        </div>
        <div>
          <label>Image</label>
          <input type="file" name="imgSrc" accept="image/*" required>
        </div>
      </div>

      <label style="margin-top:12px;">Image Alt Text</label>
      <input type="text" name="imgAlt" required>

      <label style="margin-top:12px;">Details</label>
      <textarea name="details" rows="5" required></textarea>

      <div class="actions">
        <button type="button" class="btn cancel" id="cancelAdd">Cancel</button>
        <button type="submit" class="btn save">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== EDIT DETAILS MODAL ===== -->
<div class="modal-backdrop" id="editModal">
  <div class="modal">
    <h2>Edit Details</h2>
    <form id="editForm" method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="galleryId" id="edit_galleryId">
      <label>Title (readonly)</label>
      <input type="text" id="edit_title" readonly>
      <label>Details</label>
      <textarea name="details" id="edit_details" rows="6" required></textarea>
      <div class="actions">
        <button type="button" class="btn cancel" id="cancelEdit">Cancel</button>
        <button type="submit" class="btn save">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
// simple dropdown hover
const dd = document.querySelector('nav .dropdown');
const menu = dd?.querySelector('.dropdown-content');
dd?.addEventListener('mouseenter', ()=> menu.style.display='block');
dd?.addEventListener('mouseleave', ()=> menu.style.display='none');

// ADD modal
const addModal=document.getElementById('addModal');
document.getElementById('openAddModal')?.addEventListener('click',()=>addModal.style.display='flex');
document.getElementById('cancelAdd')?.addEventListener('click',()=>addModal.style.display='none');
addModal.addEventListener('click',e=>{if(e.target===addModal)addModal.style.display='none';});

// EDIT modal
const editModal=document.getElementById('editModal');
const egid=document.getElementById('edit_galleryId');
const etitle=document.getElementById('edit_title');
const edetails=document.getElementById('edit_details');
document.querySelectorAll('.edit-details').forEach(btn=>{
  btn.addEventListener('click',()=>{
    egid.value=btn.dataset.id;
    etitle.value=btn.dataset.title;
    edetails.value=btn.dataset.details;
    editModal.style.display='flex';
  });
});
document.getElementById('cancelEdit')?.addEventListener('click',()=>editModal.style.display='none');
editModal.addEventListener('click',e=>{if(e.target===editModal)editModal.style.display='none';});

// SweetAlert delete confirm
document.querySelectorAll('form [type="button"].btn-delete').forEach(btn=>{
  btn.addEventListener('click',e=>{
    const form=e.currentTarget.closest('form');
    Swal.fire({
      title:'Delete this gallery item?',
      text:'This action cannot be undone.',
      icon:'warning',
      showCancelButton:true,
      confirmButtonText:'Yes, delete',
      cancelButtonText:'Cancel'
    }).then(res=>{if(res.isConfirmed) form.submit();});
  });
});

// Toast (?toast / ?err)
(function(){
  const qs=new URLSearchParams(location.search);
  const msg=qs.get('toast')||qs.get('err');
  if(!msg)return;
  Swal.fire({
    toast:true,position:'top-end',
    icon:qs.get('err')?'error':'success',
    title:msg,showConfirmButton:false,timer:2000,timerProgressBar:true
  });
  qs.delete('toast'); qs.delete('err');
  history.replaceState({},'',`${location.pathname}${qs.toString()?('?'+qs.toString()):''}`);
})();
</script>

<!-- ===================== ADD BELOW THIS LINE (EXISTING ADDITIONS MAY BE ABOVE) ===================== -->
<!-- ===== Center-Aligned Toolbar (Add + Sort by) + Local Sort/Filter — ADD-ONLY ===== -->
<style>
  .local-filter-toggle{ display:none !important; } /* hide legacy 'sort' trigger */
  .local-filter-panel{ display:none; }             /* keep off-screen; we reuse its logic only */
  .gallery-toolbar, .gallery-toolbar-center, .lf-pill { display:none !important; }

  /* THEAD sorter style */
  .thead-sort-row th{
    background:#eef5ff;
    padding:10px 12px;
  }
  .th-panel{ display:flex; gap:12px; justify-content:center; align-items:flex-end; flex-wrap:wrap }
  .th-col{ display:flex; flex-direction:column; gap:6px; min-width:180px; text-align:left }
  .th-col label{ font-size:.85rem; color:#475569; font-weight:700 }
  .th-col select{ height:38px; border:1px solid #cbd5e1; border-radius:.5rem; padding:0 .5rem; background:#fff; color:#0f172a }
  .th-actions{ display:flex; gap:8px; align-items:center; }
  .th-btn{ height:38px; border-radius:.5rem; border:1px solid transparent; cursor:pointer; font-weight:800; padding:0 12px }
  .th-apply{ background:#1d4ed8; color:#fff }
  .th-clear{ background:#eef2ff; color:#1e3a8a; border-color:#c7d2fe }
</style>

<div id="localGallerySorter" class="local-filter-panel" aria-hidden="true">
  <div class="lf-row">
    <div class="lf-field"><label for="lg-album">Album type</label><select id="lg-album"><option value="__all__">All</option></select></div>
    <div class="lf-field"><label for="lg-month">Month</label><select id="lg-month"><option value="__all__">All</option></select></div>
    <div class="lf-field"><label for="lg-sort">Sort by</label>
      <select id="lg-sort">
        <option value="month_desc">Month — Newest → Oldest</option>
        <option value="month_asc">Month — Oldest → Newest</option>
        <option value="title_asc">Title — A → Z</option>
        <option value="title_desc">Title — Z → A</option>
      </select>
    </div>
    <div class="lf-actions" style="align-self:center;">
      <button type="button" class="lf-btn clear"  id="lg-clear">Clear</button>
      <button type="button" class="lf-btn apply" id="lg-apply">Apply</button>
    </div>
  </div>
  <div class="lf-meta"><span id="lg-count">0</span> row(s) shown</div>
</div>

<script>
// ===== Core filter/sort logic from earlier (kept) =====
(function(){
  const main  = document.querySelector('main.gallery-layout');
  const table = document.querySelector('.gallery-table');
  const panel = document.getElementById('localGallerySorter');
  if (!table || !panel) return;

  // ----- Client-side Filter/Sort -----
  const PHP_ALBUM_TYPES = <?php echo json_encode(array_values($album_types), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
  const tbody = table.querySelector('tbody');
  const rows  = Array.from(tbody.querySelectorAll('tr'));

  const albumSel = document.getElementById('lg-album');
  const monthSel = document.getElementById('lg-month');
  const sortSel  = document.getElementById('lg-sort');
  const btnApply = document.getElementById('lg-apply');
  const btnClear = document.getElementById('lg-clear');
  const countSpan= document.getElementById('lg-count');

  function uniqueFromCol(idx){
    const s = new Set();
    rows.forEach(r=>{
      const c = r.querySelectorAll('td')[idx];
      if (!c) return;
      const v = (c.textContent || '').trim();
      if (v) s.add(v);
    });
    return Array.from(s).sort((a,b)=>a.localeCompare(b));
  }

  // Populate selects
  const albumTypes = Array.from(new Set([...(PHP_ALBUM_TYPES||[]), ...uniqueFromCol(3)])).sort((a,b)=>a.localeCompare(b));
  albumTypes.forEach(v => { const o=document.createElement('option'); o.value=v; o.textContent=v; albumSel.appendChild(o); });
  uniqueFromCol(2).forEach(v => { const o=document.createElement('option'); o.value=v; o.textContent=v; monthSel.appendChild(o); });

  function monthKey(str){
    const s = String(str||'').trim();
    if (!s) return -Infinity;
    const m1 = s.match(/^([A-Za-z]+)\s+(\d{4})$/);
    if (m1){
      const map=["january","february","march","april","may","june","july","august","september","october","november","december"];
      const idx = map.indexOf(m1[1].toLowerCase());
      if (idx>=0) return parseInt(m1[2],10)*100+(idx+1);
    }
    const m2 = s.match(/^(\d{4})[-\/\.](\d{1,2})$/);
    if (m2){ const y=+m2[1], mo=+m2[2]; if (y && mo>=1 && mo<=12) return y*100+mo; }
    const d = new Date(s);
    return isNaN(d)?-Infinity:(d.getFullYear()*100 + (d.getMonth()+1));
  }

  const originalOrder = rows.slice();

  function applyNow(){
    const album = albumSel.value;
    const month = monthSel.value;
    const sort  = sortSel.value;

    rows.forEach(r=>{
      const tds = r.querySelectorAll('td');
      const rowMonth = (tds[2]?.textContent||'').trim();
      const rowAlbum = (tds[3]?.textContent||'').trim();
      let show = true;
      if (album && album!=='__all__' && rowAlbum!==album) show=false;
      if (month && month!=='__all__' && rowMonth!==month) show=false;
      r.style.display = show?'':'none';
      r.dataset.sortTitle = (tds[1]?.textContent||'').trim().toLowerCase();
      r.dataset.sortMonthKey = monthKey(rowMonth);
    });

    const visible = rows.filter(r=>r.style.display!== 'none');
    const hidden  = rows.filter(r=>r.style.display=== 'none');

    visible.sort((a,b)=>{
      if (sort==='title_asc')  return a.dataset.sortTitle.localeCompare(b.dataset.sortTitle);
      if (sort==='title_desc') return b.dataset.sortTitle.localeCompare(a.dataset.sortTitle)*-1;
      if (sort==='month_asc')  return (+a.dataset.sortMonthKey) - (+b.dataset.sortMonthKey);
      return (+b.dataset.sortMonthKey) - (+a.dataset.sortMonthKey);
    });

    const frag=document.createDocumentFragment();
    visible.forEach(r=>frag.appendChild(r));
    hidden.forEach(r=>frag.appendChild(r));
    tbody.appendChild(frag);

    countSpan.textContent = String(visible.length);
  }

  function clearAll(){
    albumSel.value='__all__'; monthSel.value='__all__'; sortSel.value='month_desc';
    rows.forEach(r=>{ r.style.display=''; });
    const frag=document.createDocumentFragment();
    originalOrder.forEach(r=>frag.appendChild(r));
    tbody.appendChild(frag);
    countSpan.textContent = String(rows.length);
  }

  // Wire buttons
  btnApply.addEventListener('click', applyNow);
  btnClear.addEventListener('click', clearAll);
  [albumSel, monthSel, sortSel].forEach(el => el.addEventListener('change', applyNow));

  // Initial clear -> fills counts, then we’ll smart-sort below
  clearAll();

  // ===== NEW: THEAD SORTER + SMART SORT + LIMIT 3 =====
  (function setupTheadSorter(){
    const thead = table.querySelector('thead');
    if(!thead) return;

    // Build row
    const tr = document.createElement('tr');
    tr.className = 'thead-sort-row';
    const th = document.createElement('th');
    th.colSpan = 5;
    tr.appendChild(th);

    const panel = document.createElement('div');
    panel.className = 'th-panel';
    th.appendChild(panel);

    function makeCol(labelText, id, sourceSelect){
      const col = document.createElement('div');
      col.className = 'th-col';
      const label = document.createElement('label');
      label.htmlFor = id;
      label.textContent = labelText;
      const sel = document.createElement('select');
      sel.id = id;
      Array.from(sourceSelect.options).forEach(opt => {
        const o = document.createElement('option');
        o.value = opt.value; o.textContent = opt.textContent;
        sel.appendChild(o);
      });
      col.appendChild(label); col.appendChild(sel);
      return {col, sel};
    }

    const {col: colAlbum, sel: selAlbum} = makeCol('Album type', 'th-album', albumSel);
    const {col: colMonth, sel: selMonth} = makeCol('Month', 'th-month', monthSel);
    const {col: colSort , sel: selSort } = makeCol('Sort by', 'th-sort',  sortSel);
    panel.appendChild(colAlbum); panel.appendChild(colMonth); panel.appendChild(colSort);

    const actions = document.createElement('div');
    actions.className = 'th-actions';
    const bApply = document.createElement('button'); bApply.type='button'; bApply.className='th-btn th-apply'; bApply.textContent='Apply';
    const bClear = document.createElement('button'); bClear.type='button'; bClear.className='th-btn th-clear'; bClear.textContent='Clear';
    actions.appendChild(bApply); actions.appendChild(bClear);
    panel.appendChild(actions);

    // Insert as first row of THEAD
    thead.insertBefore(tr, thead.firstChild);

    // Sync helpers via master selects
    function syncToMaster(){
      albumSel.value = selAlbum.value;
      monthSel.value = selMonth.value;
      sortSel.value  = selSort.value;
      // Trigger change to update counts
      ['change'].forEach(evt => {
        albumSel.dispatchEvent(new Event(evt,{bubbles:true}));
        monthSel.dispatchEvent(new Event(evt,{bubbles:true}));
        sortSel.dispatchEvent(new Event(evt,{bubbles:true}));
      });
      // Apply then enforce limit
      btnApply.click();
      setTimeout(enforceLimit3, 0);
    }
    function syncFromMaster(){
      selAlbum.value = albumSel.value;
      selMonth.value = monthSel.value;
      selSort.value  = sortSel.value;
    }
    bApply.addEventListener('click', syncToMaster);
    bClear.addEventListener('click', ()=>{ btnClear.click(); setTimeout(()=>{ syncFromMaster(); enforceLimit3(); },0); });
    [selAlbum, selMonth, selSort].forEach(el => el.addEventListener('change', syncToMaster));
    [albumSel, monthSel, sortSel].forEach(el => el.addEventListener('change', syncFromMaster));

    // Hide any old in-table sorter row if present
    const oldVt = document.querySelector('.vt-sort-row'); if(oldVt) oldVt.remove();

    // Smart sort selection: if at least 2 parsable months, use month_desc else title_asc
    const monthKeys = rows.map(r => {
      const m = (r.querySelectorAll('td')[2]?.textContent||'').trim();
      return (function mk(s){
        if(!s) return -Infinity;
        const m1 = s.match(/^([A-Za-z]+)\s+(\d{4})$/);
        if (m1){
          const map=["january","february","march","april","may","june","july","august","september","october","november","december"];
          const idx = map.indexOf(m1[1].toLowerCase());
          if (idx>=0) return parseInt(m1[2],10)*100+(idx+1);
        }
        const m2 = s.match(/^(\d{4})[-\/\.](\d{1,2})$/);
        if (m2){ const y=+m2[1], mo=+m2[2]; if (y && mo>=1 && mo<=12) return y*100+mo; }
        const d = new Date(s); return isNaN(d)?-Infinity:(d.getFullYear()*100 + (d.getMonth()+1));
      })(m);
    });
    const parsableMonths = monthKeys.filter(v=>v!==-Infinity).length;
    const smart = (parsableMonths>=2) ? 'month_desc' : 'title_asc';
    sortSel.value = smart;    // master
    selSort.value  = smart;   // thead
    // Auto-apply on load with limit 3
    btnApply.click();
    setTimeout(()=>{ enforceLimit3(); syncFromMaster(); },0);

    // Enforce 3 after any master apply/clear
    btnApply.addEventListener('click', ()=>setTimeout(enforceLimit3,0));
    btnClear.addEventListener('click', ()=>setTimeout(enforceLimit3,0));
    // Also if DOM reorders, keep limit
    new MutationObserver(()=>enforceLimit3()).observe(tbody,{childList:true,subtree:false});
  })();

  // Limit to 3 visible rows (kept globally)
  function enforceLimit3(){
    const all = Array.from(tbody.querySelectorAll('tr'));
    let shown = 0;
    all.forEach(r=>{
      const visible = r.style.display !== 'none';
      if (visible){
        shown++;
        r.style.display = (shown<=3)? '' : 'none';
        r.dataset.limited = (shown<=3)? '0' : '1';
      }
    });
    // Update count text to reflect limited view
    const countSpan= document.getElementById('lg-count');
    if (countSpan){
      const totalVisible = all.filter(r=>r.dataset.limited!=='1' && r.style.display!=='none').length;
      countSpan.textContent = String(totalVisible);
    }
  }
})();
</script>

<!-- ===================== END (SMART SORT IN THEAD + LIMIT 3) ===================== -->

<!-- ===================== ADD BELOW THIS LINE — HIDE APPLY BUTTON (ADD-ONLY) ===================== -->
<style>
  /* Hide the Apply buttons in both the header sorter and the (hidden) legacy panel) */
  .th-actions .th-apply,
  #lg-apply {
    display: none !important;
  }
</style>
<!-- ===================== END ADD ===================== -->

<!-- ===================== ADD BELOW THIS LINE — ADD BUTTON INSIDE TABLE SORTER (ADD-ONLY) ===================== -->
<style>
  /* Style for the in-table ADD button */
  .th-add {
    background:#0B9B4A;
    color:#fff;
  }
  .th-add i { margin-right:6px; }
  /* When placed as the very first item, give it a little right gap */
  .th-add.th-add-leading { margin-right: 8px; }
  @media (max-width: 768px){
    .th-add { width:auto; }
  }
</style>

<script>
/* Reposition the in-table ADD button:
   1) Remove any previously injected .th-add (the “old one”).
   2) Insert a new ADD button at the START of the sorter (as the first item in .th-panel). */
(function moveAddButtonToStart(){
  function openAddModal(){
    const trigger = document.getElementById('openAddModal');
    if (trigger) { trigger.click(); }
    else {
      const addModal = document.getElementById('addModal');
      if (addModal) addModal.style.display = 'flex';
    }
  }

  function run(){
    const theadRow = document.querySelector('.thead-sort-row');
    if (!theadRow) return false;

    // 1) Remove old in-table add buttons if any
    theadRow.querySelectorAll('.th-add').forEach(btn => btn.remove());

    // 2) Insert new button at the very START of the sorter panel
    const panel = theadRow.querySelector('.th-panel');
    if (!panel) return false;

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'th-btn th-add th-add-leading';
    addBtn.innerHTML = '<i class="fas fa-plus-square"></i>ADD';
    addBtn.setAttribute('aria-label','Add gallery item');
    addBtn.addEventListener('click', openAddModal);

    // Prepend as first child of the sorter panel
    panel.prepend(addBtn);

    return true;
  }

  // Try immediately, then retry briefly in case the sorter is built slightly later
  if (run()) return;
  let tries = 0;
  const maxTries = 20;
  const interval = setInterval(()=>{
    tries++;
    if (run() || tries >= maxTries) clearInterval(interval);
  }, 100);
})();
</script>
<!-- ===================== END ADD ===================== -->

<!-- ===================== ADD BELOW THIS LINE — SCROLLABLE INSTEAD OF LIMIT 3 (ADD-ONLY) ===================== -->
<style>
  /* Scroll wrapper that appears only when we wrap the table dynamically */
  .gallery-scrollwrap{
    max-height: 560px;           /* adjust if you want taller/shorter viewport */
    overflow-y: auto;
    border-radius: 8px;
  }
  /* Keep header visible while scrolling */
  .gallery-scrollwrap .gallery-table thead th{
    position: sticky;
    top: 0;
    z-index: 1;
    background: #f8f9fa;
  }
</style>

<script>
/* Make table scrollable when there are 4+ rows and neutralize the old "limit 3" hider.
   We DO NOT modify existing code; we just undo any hiding and keep it undone. */
(function enableScrollableInsteadOfLimit3(){
  const table = document.querySelector('.gallery-table');
  if (!table) return;
  const tbody = table.querySelector('tbody');
  if (!tbody) return;

  // 1) Unhide anything that was hidden by the previous limiter
  function unlimit(){
    tbody.querySelectorAll('tr').forEach(r=>{
      if (r.style && r.style.display === 'none') r.style.display = '';
      if (r.dataset && 'limited' in r.dataset) delete r.dataset.limited;
    });
  }
  unlimit();

  // 2) Keep unlimiting whenever rows or styles change (the previous code may try to hide again)
  const unhideObserver = new MutationObserver(unlimit);
  unhideObserver.observe(tbody, { attributes:true, attributeFilter:['style','data-limited'], childList:true, subtree:true });

  // 3) Also run a short-lived interval as a belt-and-suspenders in case of quick reorder bursts
  let ticks = 0;
  const killer = setInterval(()=>{
    unlimit();
    if (++ticks > 20) clearInterval(killer); // stop after ~6 seconds
  }, 300);

  // 4) Wrap table in a scroll container when 4 or more visible rows exist
  function ensureScrollWrap(){
    const visibleRows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.style.display !== 'none');
    const alreadyWrapped = table.parentElement && table.parentElement.classList.contains('gallery-scrollwrap');
    if (visibleRows.length >= 4 && !alreadyWrapped){
      const wrap = document.createElement('div');
      wrap.className = 'gallery-scrollwrap';
      table.parentNode.insertBefore(wrap, table);
      wrap.appendChild(table);
    }
  }
  ensureScrollWrap();

  // Re-check whenever rows are added/removed or filters change visibility
  const wrapObserver = new MutationObserver(ensureScrollWrap);
  wrapObserver.observe(tbody, { childList:true, subtree:false });

  // Also re-check on window resize (optional, harmless)
  window.addEventListener('resize', ensureScrollWrap);
})();
</script>
<!-- ===================== END ADD ===================== -->

</body>
</html>

