<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db-connection.php';
mysqli_set_charset($db_connection, 'utf8mb4');

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

/* Build human-readable change notes (ONLY changed fields) for Ministries items */
if (!function_exists('build_changed_ministry_notes')) {
  function build_changed_ministry_notes(array $before, array $after): string {
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
    if (!$changes) return 'Updated ministry item — No values changed.';
    return 'Updated ministry item — ' . implode('; ', $changes);
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

    $form   = 'content-management_ministries.php';
    $source = 'content_management_table';

    if ($notes === '') {
      $notes = $action==='INSERT'  ? 'Added ministry item'
            : ($action==='UPDATE' ? 'Updated ministry item'
            : ($action==='DELETE'? 'Deleted ministry item'
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

/* --- Pre-capture BEFORE row so we can diff after edit (no edits to your handler) --- */
$GLOBALS['__min_before_edit'] = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['action'] ?? '') === 'edit')) {
  $id = (int)($_POST['contentID'] ?? 0);
  if ($id > 0 && ($st = mysqli_prepare($db_connection,
      "SELECT contentID, content_type, img_file_path, img_caption FROM content_management_table WHERE contentID=? LIMIT 1"))) {
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $GLOBALS['__min_before_edit'] = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
    mysqli_stmt_close($st);
  }
}

/* --- Shutdown audit (UPDATE via post-commit) --- */
register_shutdown_function(function() use ($db_connection) {
  $method = $_SERVER['REQUEST_METHOD'] ?? '';
  if ($method !== 'POST') return;
  $act = $_POST['action'] ?? '';
  if ($act !== 'edit') return;

  $id = (int)($_POST['contentID'] ?? 0);
  if ($id <= 0) return;

  // Fetch AFTER
  $after = null;
  if ($st = mysqli_prepare($db_connection,
        "SELECT contentID, content_type, img_file_path, img_caption FROM content_management_table WHERE contentID=? LIMIT 1")) {
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $after = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
    mysqli_stmt_close($st);
  }

  $before = $GLOBALS['__min_before_edit'] ?: [];
  if ($after) {
    $notes = build_changed_ministry_notes($before ?: [], $after ?: []);
    $audit = audit_content_action($db_connection, 'UPDATE', (string)$id, [
      'contentID'     => (string)$after['contentID'],
      'content_type'  => (string)($after['content_type'] ?? ''),
      'img_file_path' => (string)($after['img_file_path'] ?? ''),
      'img_caption'   => (string)($after['img_caption'] ?? ''),
    ], $notes);
    if (!$audit['ok']) error_log('[AUDIT][ministries-update]['.$audit['attempt'].'] '.$audit['msg']);
  }
});

/* ===== END ADDITIONS (helpers + audit) ===== */

/* ---------- EDIT CAPTION + IMAGE ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
  $id = (int)($_POST['contentID'] ?? 0);
  $cap = trim($_POST['img_caption'] ?? '');
  $newFilePath = '';

  if ($id > 0) {
    // Handle image upload (optional)
    if (!empty($_FILES['new_img']['name']) && $_FILES['new_img']['error'] === 0) {
      $uploadDir = 'uploads/';
      if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
      $fileName = time() . '_' . basename($_FILES['new_img']['name']);
      $targetFile = $uploadDir . $fileName;

      if (move_uploaded_file($_FILES['new_img']['tmp_name'], $targetFile)) {
        $newFilePath = $targetFile;
      }
    }

    // If new image uploaded, update both image and caption
    if ($newFilePath !== '') {
      $stmt = mysqli_prepare($db_connection, "UPDATE content_management_table SET img_caption=?, img_file_path=? WHERE contentID=?");
      mysqli_stmt_bind_param($stmt, "ssi", $cap, $newFilePath, $id);
    } else {
      // Only caption updated
      $stmt = mysqli_prepare($db_connection, "UPDATE content_management_table SET img_caption=? WHERE contentID=?");
      mysqli_stmt_bind_param($stmt, "si", $cap, $id);
    }

    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    header("Location: ?toast=" . ($ok ? "Changes saved successfully" : "Failed to update"));
    exit;
  }
}

/* ===== ADD MINISTRY FEATURE (ONLY ADDITIONS; NO MODIFICATIONS) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_ministry') {
  $minName = trim($_POST['ministry_name'] ?? '');
  $minDesc = trim($_POST['ministry_description'] ?? '');
  $joinDesc = trim($_POST['join_description'] ?? '');
  $minImagePath = '';

  // Upload image (required by UI; still safely handle if missing)
  if (!empty($_FILES['ministry_image']['name']) && ($_FILES['ministry_image']['error'] ?? 1) === 0) {
    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    $fileName = time() . '_' . basename($_FILES['ministry_image']['name']);
    $targetFile = $uploadDir . $fileName;

    if (move_uploaded_file($_FILES['ministry_image']['tmp_name'], $targetFile)) {
      $minImagePath = $targetFile;
    }
  }

  // Basic validation (keep simple; toast errors)
  if ($minName === '' || $minDesc === '' || $joinDesc === '' || $minImagePath === '') {
    header("Location: ?err=" . urlencode("Please complete all fields and upload an image."));
    exit;
  }

  // Insert to your ministry_table
  $okAdd = false;
  if ($stmtAdd = mysqli_prepare(
    $db_connection,
    "INSERT INTO ministry_table (join_description, ministry_image, ministry_name, ministry_description)
     VALUES (?, ?, ?, ?)"
  )) {
    mysqli_stmt_bind_param($stmtAdd, "ssss", $joinDesc, $minImagePath, $minName, $minDesc);
    $okAdd = mysqli_stmt_execute($stmtAdd);
    mysqli_stmt_close($stmtAdd);
  }

  header("Location: ?toast=" . ($okAdd ? "Ministry added successfully" : "Failed to add ministry"));
  exit;
}
/* ===== END ADD MINISTRY FEATURE ===== */

/* ---------- FETCH ITEMS ---------- */
$allowed = ["handmain","men","music","usher","junior"];
$items = [];
$in = implode(",", array_fill(0, count($allowed), "?"));
$sql = "SELECT contentID, content_type, img_file_path, imgAlt, img_caption
        FROM content_management_table
        WHERE content_type IN ($in)
        ORDER BY contentID DESC";
$stmt = mysqli_prepare($db_connection, $sql);
mysqli_stmt_bind_param($stmt, str_repeat("s", count($allowed)), ...$allowed);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) $items[] = $row;
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=1920, initial-scale=1" />
<title>Content Management - Ministries</title>
<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@600;700&display=swap');
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:'Inter',sans-serif;
  background-image:url("image/all-background.png");
  background-size:cover;
  background-attachment:fixed;
  color:#0B1446;
}
/* ===== HEADER ===== */
header{
  background-color:#0A0E3F;
  display:grid;
  grid-template-columns:60px 1fr 60px;
  align-items:center;
  justify-content:center;
  padding:10px 28px;
  width:100%;
  color:#fff;
  box-shadow:0 1px 3px rgba(0,0,0,.15);
  position:relative;
  z-index:10;
}
header .back-button img{width:30px;height:30px;cursor:pointer;display:block}
header .title-center{display:flex;align-items:center;justify-content:center;gap:10px}
header .title-center img{width:46px;height:46px;border-radius:50%}
header .title-center h1{font-size:26px;font-weight:900;margin:0;color:#fff}

/* ===== NAV ===== */
nav{
  background-color:#0A0E3F;
  display:flex;
  justify-content:center;
  flex-wrap:wrap;
  gap:22px;
  padding:16px 0;
  color:#fff;
}
.nav-pill{
  display:inline-flex;
  align-items:center;
  gap:8px;
  border:1px solid #304256;
  background:transparent;
  color:#dbeafe;
  padding:8px 18px;
  border-radius:999px;
  text-decoration:none;
  font-weight:800;
  font-size:15px;
  opacity:.85;
  transition:.2s;
}
.nav-pill:hover,
.nav-pill.active{
  opacity:1;
  background:#304256;
  color:#fff;
}
.nav-pill.active{
  box-shadow:0 0 4px rgba(255,255,255,.25);
}

/* ===== TABLE ===== */
.min-layout{max-width:1200px;margin:40px auto;padding:0 30px}
.min-table{
  width:100%;
  border-collapse:collapse;
  font-weight:600;
  color:#0B1446;
  background:#fff;
  border-radius:8px;
  overflow:hidden;
  box-shadow:0 8px 20px rgba(0,0,0,.1);
}
.min-table thead{background:#f8f9fa}
.min-table th,.min-table td{
  padding:14px 12px;text-align:center;border-bottom:1px solid #ddd;
}
.min-table th{font-size:15px;font-weight:800}
.min-table img{
  width:120px;height:120px;object-fit:cover;
  border-radius:6px;box-shadow:0 4px 10px rgba(0,0,0,.15);
  transition:transform .2s;
}
.min-table img:hover{transform:scale(1.05)}
.action-buttons{display:flex;justify-content:center;align-items:center}
.btn-edit{
  border:none;border-radius:8px;padding:8px 12px;
  font-weight:800;cursor:pointer;display:inline-flex;
  align-items:center;gap:8px;background:#0B3B8F;color:#fff;
}

/* ===== MODAL ===== */
.modal-backdrop{
  position:fixed;inset:0;background:rgba(0,0,0,.45);
  display:none;align-items:center;justify-content:center;z-index:9999;
}
.modal{
  background:#fff;border-radius:10px;max-width:560px;
  width:92%;padding:20px 22px;
  box-shadow:0 20px 50px rgba(0,0,0,.25);
}
.modal h2{margin:0 0 12px;color:#0B1446}
.modal label{font-weight:700;color:#0B1446;display:block;margin-top:10px}
.modal input[type="text"], .modal input[type="file"]{
  width:100%;padding:10px 12px;
  border:1px solid #cfd3d7;border-radius:8px;font-family:inherit;
}
.modal img.preview{
  width:120px;height:120px;object-fit:cover;
  border-radius:8px;margin-top:10px;
}
.modal .actions{
  display:flex;justify-content:flex-end;gap:10px;margin-top:14px;
}
.modal .btn{
  border:none;border-radius:8px;padding:10px 14px;
  font-weight:700;cursor:pointer;
}
.modal .btn.cancel{background:#e5e7eb;color:#111827}
.modal .btn.save{background:#0B3B4A;color:#fff}

/* ===== ADD MINISTRY BUTTON (ONLY ADDITIONS) ===== */
.min-toolbar{
  display:flex;
  justify-content:flex-end;
  margin:0 0 12px 0;
}
.btn-add{
  border:none;border-radius:8px;padding:10px 14px;
  font-weight:900;cursor:pointer;display:inline-flex;
  align-items:center;gap:8px;background:#0B3B4A;color:#fff;
}
.btn-add i{font-size:14px}
/* ===== END ADDITIONS ===== */
</style>
</head>
<body>

<header>
  <a href="secretary_dashboard.php" class="back-button">
    <img src="image/btn-back.png" alt="Back">
  </a>
  <div class="title-center">
    <img src="image/httc_main-logo.jpg" alt="Logo">
    <h1>CONTENT</h1>
  </div>
  <div style="width:30px;"></div>
</header>

<nav>
  <a href="content-management_home-page.php" class="nav-pill"><i class="fa-solid fa-house"></i> HOME PAGE</a>
  <a href="content-management_service.php" class="nav-pill"><i class="fa-solid fa-hands-praying"></i> SERVICE</a>
  <a href="content-management_events.php" class="nav-pill"><i class="fa-solid fa-calendar-days"></i> EVENTS</a>
  <a href="content-management_gallery.php" class="nav-pill"><i class="fa-solid fa-images"></i> GALLERY</a>
  <a href="content-management_ministries.php" class="nav-pill active"><i class="fa-solid fa-people-group"></i> MINISTRIES</a>
  <a href="content-management_join-us.php" class="nav-pill"><i class="fa-solid fa-user-plus"></i> JOIN US</a>
  <a href="content-management_find-us.php" class="nav-pill"><i class="fa-solid fa-location-dot"></i> FIND US</a>
</nav>

<main class="min-layout">

  <!-- ===== ADD MINISTRY BUTTON (ONLY ADDITIONS) ===== -->
  <div class="min-toolbar">
    <button type="button" class="btn-add" id="openAddMinistry">
      <i class="fa-solid fa-plus"></i> Add Ministry
    </button>
  </div>
  <!-- ===== END ADDITIONS ===== -->

  <table class="min-table">
    <thead>
      <tr>
        <th>IMAGE</th>
        <th>CAPTION</th>
        <th>TYPE</th>
        <th>ACTION</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($items): foreach ($items as $row): ?>
        <tr>
          <td>
            <?php
              $src = htmlspecialchars($row['img_file_path'] ?? '', ENT_QUOTES, 'UTF-8');
              $alt = htmlspecialchars($row['imgAlt'] ?? 'Ministry', ENT_QUOTES, 'UTF-8');
              echo $src ? "<img src='{$src}' alt='{$alt}'>" : '—';
            ?>
          </td>
          <td><?php echo htmlspecialchars($row['img_caption'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
          <td style="text-transform:capitalize;"><?php echo htmlspecialchars($row['content_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
          <td class="action-buttons">
            <button class="btn-edit edit-caption"
                    data-id="<?php echo (int)$row['contentID']; ?>"
                    data-caption="<?php echo htmlspecialchars($row['img_caption'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    data-image="<?php echo htmlspecialchars($row['img_file_path'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
              <i class="fas fa-edit"></i> Edit
            </button>
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="4">No ministries found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</main>

<!-- ===== EDIT MODAL ===== -->
<div class="modal-backdrop" id="editModal">
  <div class="modal">
    <h2>Edit Ministry</h2>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="contentID" id="edit_id">
      <label>Caption</label>
      <input type="text" name="img_caption" id="edit_caption" required>

      <label>Replace Image (optional)</label>
      <input type="file" name="new_img" id="new_img" accept="image/*">
      <img src="" alt="Preview" class="preview" id="img_preview">

      <div class="actions">
        <button type="button" class="btn cancel" id="cancelEdit">Cancel</button>
        <button type="submit" class="btn save">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== ADD MINISTRY MODAL (ONLY ADDITIONS) ===== -->
<div class="modal-backdrop" id="addModal">
  <div class="modal">
    <h2>Add Ministry</h2>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add_ministry">

      <label>Ministry Image</label>
      <input type="file" name="ministry_image" id="ministry_image" accept="image/*" required>
      <img src="" alt="Preview" class="preview" id="add_img_preview">

      <label>Ministry Name</label>
      <input type="text" name="ministry_name" id="ministry_name" required>

      <label>Ministry Description</label>
      <input type="text" name="ministry_description" id="ministry_description" required>

      <label>Join Description</label>
      <input type="text" name="join_description" id="join_description" required>

      <div class="actions">
        <button type="button" class="btn cancel" id="cancelAdd">Cancel</button>
        <button type="submit" class="btn save">Add Ministry</button>
      </div>
    </form>
  </div>
</div>
<!-- ===== END ADDITIONS ===== -->

<script>
// Edit modal logic
const editModal=document.getElementById('editModal');
const eid=document.getElementById('edit_id');
const ecap=document.getElementById('edit_caption');
const eimg=document.getElementById('img_preview');
document.querySelectorAll('.edit-caption').forEach(btn=>{
  btn.addEventListener('click',()=>{
    eid.value=btn.dataset.id;
    ecap.value=btn.dataset.caption||'';
    eimg.src=btn.dataset.image||'';
    editModal.style.display='flex';
  });
});
document.getElementById('cancelEdit').addEventListener('click',()=>editModal.style.display='none');
editModal.addEventListener('click',e=>{if(e.target===editModal)editModal.style.display='none';});

// Preview new image
document.getElementById('new_img').addEventListener('change',function(){
  if(this.files && this.files[0]){
    eimg.src=URL.createObjectURL(this.files[0]);
  }
});

// ===== ADD MINISTRY MODAL LOGIC (ONLY ADDITIONS) =====
const addModal=document.getElementById('addModal');
const openAdd=document.getElementById('openAddMinistry');
const addImgInput=document.getElementById('ministry_image');
const addImgPreview=document.getElementById('add_img_preview');

openAdd.addEventListener('click',()=>{
  // reset fields each open
  document.getElementById('ministry_name').value='';
  document.getElementById('ministry_description').value='';
  document.getElementById('join_description').value='';
  addImgInput.value='';
  addImgPreview.src='';
  addModal.style.display='flex';
});

document.getElementById('cancelAdd').addEventListener('click',()=>addModal.style.display='none');
addModal.addEventListener('click',e=>{if(e.target===addModal)addModal.style.display='none';});

addImgInput.addEventListener('change',function(){
  if(this.files && this.files[0]){
    addImgPreview.src=URL.createObjectURL(this.files[0]);
  }
});
// ===== END ADDITIONS =====

// Toast
(function(){
  const qs=new URLSearchParams(location.search);
  const msg=qs.get('toast')||qs.get('err');
  if(!msg)return;
  Swal.fire({
    toast:true,position:'top-end',
    icon:qs.get('err')?'error':'success',
    title:msg,showConfirmButton:false,timer:2000
  });
})();
</script>

</body>
</html>
