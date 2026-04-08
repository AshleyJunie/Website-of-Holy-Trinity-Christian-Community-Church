<?php
/* ========================================================================
   FILE: admin-upload-schedule-image.php
   PURPOSE: Upload a schedule image and insert into content_management_table
            following the same logic and conventions as your Slider uploader.
   DB: uses db-connection.php (mysqli)
   NOTES:
   - Saves files under /uploads/schedule/
   - Inserts: img_file_name, img_file_path, img_caption, content_type, status, img_upload_at
   - Accepts JPG, JPEG, PNG, GIF, WEBP (≤ 5 MB)
   ======================================================================== */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db-connection.php';
mysqli_set_charset($db_connection, 'utf8mb4');

$TABLE = 'content_management_table';
$STATUS_DEFAULT = 'Active';         // adjust if your table expects a different default
$toast = null;

/* ===== ADMIN BASICS, HELPERS, AUDIT (copied from your logic; no removals) ===== */

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

if (!function_exists('h')) {
  function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('audit_content_action')) {
  function audit_content_action(
    mysqli $db,
    string $action,              // INSERT | UPDATE | DELETE
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

    $form   = 'admin-upload-schedule-image.php';
    $source = 'content_management_table';

    if ($notes === '') {
      $notes = $action==='INSERT'  ? 'Added schedule item'
            : ($action==='UPDATE' ? 'Updated schedule item'
            : ($action==='DELETE'? 'Deleted schedule item'
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

    // minimal raw
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

/* ===== IMAGE HELPERS (robust extension detection) ===== */

const ALLOWED_MIME_TO_EXT = [
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/gif'  => 'gif',
  'image/webp' => 'webp',
];

function detect_image_ext(string $tmpPath, string $originalName = ''): string {
  // 1) exif_imagetype
  if (function_exists('exif_imagetype')) {
    $t = @exif_imagetype($tmpPath);
    if ($t !== false) {
      $map = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
      ];
      if (isset($map[$t])) return $map[$t];
    }
  }
  // 2) finfo
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime  = @$finfo->file($tmpPath);
  if ($mime && isset(ALLOWED_MIME_TO_EXT[$mime])) return ALLOWED_MIME_TO_EXT[$mime];

  // 3) original filename extension (whitelisted)
  if ($originalName !== '') {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $whitelist = ['jpg','jpeg','png','gif','webp'];
    if (in_array($ext, $whitelist, true)) return $ext === 'jpeg' ? 'jpg' : $ext;
  }

  // 4) last resort (guarantee an extension so file path never ends bare)
  return 'jpg';
}

/* ---------- ACTION HANDLER ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'upload_schedule') {
    $cap = trim((string)($_POST['img_caption'] ?? ''));
    $picked = trim((string)($_POST['preset_type'] ?? ''));
    $custom = trim((string)($_POST['custom_type'] ?? ''));
    $ctype  = ($picked === 'custom' && $custom !== '') ? $custom : $picked;

    if ($ctype === '') {
      $toast = ['type'=>'error','msg'=>'Please choose a schedule type or enter a custom content_type.'];
    } else {
      $targetDir = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "schedule";
      if (!is_dir($targetDir)) @mkdir($targetDir, 0777, true);

      if (!isset($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        $toast = ['type'=>'error','msg'=>'No image attached.'];
      } else {
        $origName = basename($_FILES['image']['name'] ?? '');
        $size = (int)($_FILES['image']['size'] ?? 0);
        if ($size <= 0) {
          $toast = ['type'=>'error','msg'=>'Empty or invalid file.'];
        } elseif ($size > 5 * 1024 * 1024) {
          $toast = ['type'=>'error','msg'=>'File too large (max 5MB).'];
        } else {
          // determine reliable extension from actual bytes
          $ext = detect_image_ext($_FILES['image']['tmp_name'], $origName);

          // build safe unique filename with guaranteed extension
          $base = preg_replace('/[^A-Za-z0-9_\-]+/', '_', pathinfo($origName, PATHINFO_FILENAME) ?: 'img');
          $unique = $base . '_' . date('Ymd_His') . '_' . mt_rand(1000,9999) . '.' . $ext;

          // ADD BELOW THIS LINE — ENSURE FILENAME HAS EXTENSION
          $___extCheck = strtolower((string)pathinfo($unique, PATHINFO_EXTENSION));
          if ($___extCheck === '' || strlen($___extCheck) < 2) {
            $unique .= '.jpg';
          }
          // ADD ABOVE THIS LINE — ENSURE FILENAME HAS EXTENSION

          $abs = $targetDir . DIRECTORY_SEPARATOR . $unique;
          $rel = "uploads/schedule/" . $unique;

          if (!@move_uploaded_file($_FILES['image']['tmp_name'], $abs)) {
            $toast = ['type'=>'error','msg'=>'Failed to move uploaded file. Check folder permissions.'];
          } else {
            // insert row
            $sql = "INSERT INTO {$TABLE}
                    (img_file_name, img_file_path, img_caption, content_type, status, img_upload_at)
                    VALUES (?,?,?,?,?,NOW())";
            if ($stmt = mysqli_prepare($db_connection, $sql)) {
              mysqli_stmt_bind_param($stmt, "sssss", $unique, $rel, $cap, $ctype, $STATUS_DEFAULT);
              $ok = mysqli_stmt_execute($stmt);
              $newId = $ok ? (int)mysqli_insert_id($db_connection) : 0;
              mysqli_stmt_close($stmt);

              if ($ok && $newId > 0) {
                // audit
                $audit = audit_content_action($db_connection, 'INSERT', (string)$newId, [
                  'contentID'     => (string)$newId,
                  'img_file_name' => (string)$unique,
                  'img_file_path' => (string)$rel,
                  'img_caption'   => (string)$cap,
                  'content_type'  => (string)$ctype,
                  'status'        => (string)$STATUS_DEFAULT,
                ], "Added schedule item — ID {$newId}" . ($cap!=='' ? " | Caption: {$cap}" : ''));
                if (!$audit['ok']) error_log('[AUDIT][schedule-insert]['.$audit['attempt'].'] '.$audit['msg']);

                $toast = ['type'=>'success','msg'=>'Schedule image uploaded.'];
              } else {
                // db failed, roll back file
                @unlink($abs);
                $toast = ['type'=>'error','msg'=>'Insert failed.'];
              }
            } else {
              @unlink($abs);
              $toast = ['type'=>'error','msg'=>'Prepare failed.'];
            }
          }
        }
      }
    }
  }
}

/* ---------- UI ---------- */
$PRESETS = [
  'schedule_handmaid'      => 'Handmaids (schedule_handmaid)',
  'schedule_men'           => 'Men’s (schedule_men)',
  'schedule_music'         => 'Music (schedule_music)',
  'schedule_sunday_school' => 'Sunday School (schedule_sunday_school)',
  'schedule_default'       => 'Generic Schedule Default (schedule_default)',
  'custom'                 => '— Enter a CUSTOM content_type —',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Content Management • Upload Schedule</title>
<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
  :root { --brand:#001B3A; --accent:#0d6efd; --bg:#f5f7fb; --card:#ffffff; --muted:#6b7280; }
  * { box-sizing: border-box; }
  body { margin:0; padding:32px; background:var(--bg); color:#111827; font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
  .wrap { max-width: 880px; margin: 0 auto; }
  .card { background:var(--card); border-radius:14px; box-shadow:0 12px 22px rgba(0,0,0,.07); padding:24px 22px; }
  h1 { margin:0 0 14px; font-size:22px; color:var(--brand); }
  p.lead { margin:0 0 22px; color:var(--muted); }
  form .row { display:grid; grid-template-columns: 1fr 2fr; gap:12px 16px; align-items:center; margin-bottom:12px; }
  label { font-weight:600; color:#111827; }
  input[type="text"], input[type="file"], select, textarea { width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:10px; background:#fff; }
  textarea { min-height:90px; resize:vertical; }
  .hint { font-size:12px; color:var(--muted); margin-top:4px; }
  .btn { appearance:none; border:0; padding:10px 16px; border-radius:12px; cursor:pointer; font-weight:700; background:var(--accent); color:#fff; box-shadow:0 8px 16px rgba(13,110,253,.25); }
  .actions { display:flex; gap:10px; margin-top:18px; }
  .preview { margin-top:14px; display:flex; align-items:center; gap:12px; color:var(--muted); }
  .preview img { max-height:90px; border-radius:8px; border:1px solid #e5e7eb; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Upload a Schedule Photo</h1>
      <p class="lead">Choose the <b>content_type</b> (e.g., <code>schedule_men</code>, <code>schedule_handmaid</code>) and upload an image. This follows the same rules as the Slider uploader.</p>

      <form action="" method="post" enctype="multipart/form-data" id="uploadForm" novalidate>
        <div class="row">
          <label for="preset_type">Schedule type</label>
          <select id="preset_type" name="preset_type" required>
            <?php foreach ($PRESETS as $val => $label): ?>
              <option value="<?= h($val) ?>" <?= isset($_POST['preset_type']) && $_POST['preset_type']===$val ? 'selected':'' ?>>
                <?= h($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="row" id="customTypeRow" style="display:none;">
          <label for="custom_type">Custom content_type</label>
          <input type="text" id="custom_type" name="custom_type" placeholder="e.g., schedule_youth" value="<?= h($_POST['custom_type'] ?? '') ?>">
        </div>

        <div class="row">
          <label for="image">Image (JPG, JPEG, PNG, GIF, WEBP)</label>
          <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.gif,.webp" required>
          <div class="hint">Max 5MB. Stored in <code>uploads/schedule/</code></div>
        </div>

        <div class="row">
          <label for="img_caption">Caption (optional)</label>
          <textarea id="img_caption" name="img_caption" placeholder="Short caption..."><?= h($_POST['img_caption'] ?? '') ?></textarea>
        </div>

        <div class="actions">
          <input type="hidden" name="action" value="upload_schedule">
          <button class="btn" type="submit">Upload</button>
        </div>
      </form>

      <div class="preview" id="prevWrap" style="display:none;">
        <img alt="Preview" id="prevImg"><span>Preview</span>
      </div>
    </div>
  </div>

<script>
  // Toggle custom type visibility
  const presetSel = document.getElementById('preset_type');
  const customRow = document.getElementById('customTypeRow');
  function syncCustomVisibility(){
    customRow.style.display = (presetSel.value === 'custom') ? 'grid' : 'none';
  }
  presetSel.addEventListener('change', syncCustomVisibility);
  syncCustomVisibility();

  // Preview
  const fileInput = document.getElementById('image');
  fileInput?.addEventListener('change', function(){
    const f = this.files?.[0];
    const wrap = document.getElementById('prevWrap');
    const img  = document.getElementById('prevImg');
    if (!f) { wrap.style.display='none'; img.src=''; return; }
    wrap.style.display='flex';
    img.src = URL.createObjectURL(f);
  });

  /* Toast */
  <?php if($toast): ?>
  Swal.fire({
    toast:true,
    position:'top-end',
    icon:'<?php echo $toast['type'];?>',
    title:'<?php echo addslashes($toast['msg']);?>',
    showConfirmButton:false,
    timer:2200,
    timerProgressBar:true
  });
  <?php endif; ?>
</script>
</body>
</html>
