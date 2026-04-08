<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db-connection.php';
mysqli_set_charset($db_connection, 'utf8mb4');

$TABLE = 'content_management_table';
$TOAST = null;
$ERR   = null;

/* ================================
   Link Fixer: get only the src
   ================================ */
function extract_iframe_src($input) {
  $s = trim((string)$input);
  if ($s === '') return '';

  // If they pasted an entire <iframe ... src="...">, pull just the src
  if (preg_match('/src="([^"]+)"/i', $s, $m)) {
    $s = trim($m[1]);
  }

  // Accept only a Google Maps EMbed URL (security + correctness)
  if (preg_match('#^https?://(www\.)?google\.com/maps/embed\?pb=#i', $s)) {
    return $s;
  }

  // Also allow the newer "maps/embed/v1" style if you use an API key (optional)
  if (preg_match('#^https?://(www\.)?google\.com/maps/embed/v1/[^?]+\?.+#i', $s)) {
    return $s;
  }

  return '';
}

/* ===== ADD BELOW THIS LINE — ADMIN BASICS, HELPERS, AUDIT (NO REMOVALS) ===== */

/* Ensure admin basics in session (username/email), like other CM pages */
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

/* Build human-readable notes for FindUs changes (only changed fields) */
if (!function_exists('build_changed_findus_notes')) {
  function build_changed_findus_notes(array $before, array $after): string {
    // For FindUs we care about img_caption (stores the embed src), but include content_type for completeness
    $labels = [
      'img_caption'  => 'Map Embed URL',
      'content_type' => 'Content Type',
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
    if (!$changes) return 'Updated FindUs map — No values changed.';
    return 'Updated FindUs map — ' . implode('; ', $changes);
  }
}

/* Audit helper (3-level fallback) with UUID txn_id */
if (!function_exists('audit_content_action')) {
  function audit_content_action(
    mysqli $db,
    string $action,              // INSERT | UPDATE | DELETE
    string $recordPk,
    array  $detailsAfterArr = [],// for DELETE, can contain last known state
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

    $form   = 'content-management_find-us.php';
    $source = 'content_management_table';

    if ($notes === '') {
      $notes = $action==='INSERT'  ? 'Added FindUs map'
            : ($action==='UPDATE' ? 'Updated FindUs map'
            : ($action==='DELETE'? 'Deleted FindUs map'
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

/* --- Pre-capture BEFORE state (so we can diff after save) --- */
$GLOBALS['__findus_before'] = null;
$GLOBALS['__findus_had_row'] = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_location'])) {
  if ($st = mysqli_prepare($db_connection, "SELECT contentID, content_type, img_caption FROM {$TABLE} WHERE content_type='FindUs' LIMIT 1")) {
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
    $GLOBALS['__findus_before'] = $row ?: null;
    $GLOBALS['__findus_had_row'] = (bool)$row;
    mysqli_stmt_close($st);
  }
}

/* --- Shutdown audit (INSERT/UPDATE via post-commit) --- */
register_shutdown_function(function() use ($db_connection, $TABLE) {
  $method = $_SERVER['REQUEST_METHOD'] ?? '';
  if ($method !== 'POST') return;
  if (!isset($_POST['save_location'])) return;

  // Fetch AFTER
  $after = null;
  if ($st = mysqli_prepare($db_connection, "SELECT contentID, content_type, img_caption FROM {$TABLE} WHERE content_type='FindUs' LIMIT 1")) {
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $after = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
    mysqli_stmt_close($st);
  }

  $before = $GLOBALS['__findus_before'] ?: null;
  $hadRow = (bool)$GLOBALS['__findus_had_row'];

  if ($after && !$hadRow) {
    // INSERT case
    $id = (string)$after['contentID'];
    $notes = 'Added FindUs map' . ($after['img_caption'] !== '' ? ' — Map Embed URL set' : '');
    $audit = audit_content_action($db_connection, 'INSERT', $id, [
      'contentID'     => (string)$after['contentID'],
      'content_type'  => (string)($after['content_type'] ?? ''),
      'img_caption'   => (string)($after['img_caption'] ?? ''),
    ], $notes);
    if (!$audit['ok']) error_log('[AUDIT][findus-insert]['.$audit['attempt'].'] '.$audit['msg']);
  } elseif ($after && $hadRow) {
    // UPDATE case
    $id = (string)$after['contentID'];
    $diffNotes = build_changed_findus_notes($before ?: [], $after ?: []);
    $audit = audit_content_action($db_connection, 'UPDATE', $id, [
      'contentID'     => (string)$after['contentID'],
      'content_type'  => (string)($after['content_type'] ?? ''),
      'img_caption'   => (string)($after['img_caption'] ?? ''),
    ], $diffNotes);
    if (!$audit['ok']) error_log('[AUDIT][findus-update]['.$audit['attempt'].'] '.$audit['msg']);
  }
});

/* ===== END ADDITIONS (helpers + audit) ===== */

/* ================================
   Handle Save
   ================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_location'])) {
  $raw   = $_POST['embed_url'] ?? '';
  $clean = extract_iframe_src($raw);

  if ($clean !== '') {
    // check if FindUs row exists
    $q = mysqli_query($db_connection, "SELECT contentID FROM {$TABLE} WHERE content_type='FindUs' LIMIT 1");
    if ($row = mysqli_fetch_assoc($q)) {
      $stmt = mysqli_prepare($db_connection, "UPDATE {$TABLE} SET img_caption=? WHERE content_type='FindUs' LIMIT 1");
      mysqli_stmt_bind_param($stmt, "s", $clean);
      $ok = mysqli_stmt_execute($stmt);
      mysqli_stmt_close($stmt);
    } else {
      $stmt = mysqli_prepare($db_connection, "INSERT INTO {$TABLE}(content_type,img_caption) VALUES('FindUs',?)");
      mysqli_stmt_bind_param($stmt, "s", $clean);
      $ok = mysqli_stmt_execute($stmt);
      mysqli_stmt_close($stmt);
    }
    $TOAST = $ok ? "Map location updated" : "Failed to save";
  } else {
    $ERR = "Invalid Google Maps embed. Paste the full iframe or a valid embed src.";
  }
}

/* ================================
   Fetch current record
   ================================ */
$currentMap = '';
$r = mysqli_query($db_connection, "SELECT contentID, img_caption FROM {$TABLE} WHERE content_type='FindUs' LIMIT 1");
if ($r && $row = mysqli_fetch_assoc($r)) {
  $currentMap = trim((string)$row['img_caption']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta content="width=device-width, initial-scale=1" name="viewport" />
<title>Content Management • Find Us</title>
<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Your page stylesheet (optional); keep it if you have one -->
<link rel="stylesheet" href="/HTCCC-SYSTEM/css/content-management_find-us.css?v=<?php echo time(); ?>">

<style>
/* ====== Minimal, consistent header + nav pills (matches other CM pages) ====== */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@600;700;800&display=swap');
:root{
  --navy:#0A0E3F; --pill:#304256; --soft:#dbeafe; --ink:#0B1446;
}
*{box-sizing:border-box}
body{margin:0;font-family:'Inter',sans-serif;background:#F7F9FC;color:var(--ink)}
.cm-header{
  background:var(--navy);color:#fff;display:grid;
  grid-template-columns:60px 1fr 60px;align-items:center;
  padding:10px 20px;position:sticky;top:0;z-index:9;
  box-shadow:0 1px 3px rgba(0,0,0,.15);
}
.cm-header img.cm-logo{width:46px;height:46px;border-radius:50%}
.cm-center{display:flex;align-items:center;justify-content:center;gap:10px}
.cm-title{margin:0;font-weight:900;font-size:26px}
.cm-back img{width:30px;height:30px;cursor:pointer;display:block}
nav{
  background:var(--navy);display:flex;justify-content:center;gap:14px;flex-wrap:wrap;
  padding:14px 10px;position:sticky;z-index:8;
}
.nav-pill{
  display:inline-flex;align-items:center;gap:8px;border:1px solid var(--pill);
  background:transparent;color:var(--soft);padding:8px 18px;border-radius:999px;
  text-decoration:none;font-weight:800;font-size:15px;opacity:.85;transition:.2s;
}
.nav-pill:hover,.nav-pill.active{opacity:1;background:var(--pill);color:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.05) inset}
.container{max-width:1000px;margin:24px auto;padding:0 18px}

/* ====== Map preview card ====== */
.card{
  background:#fff;border-radius:12px;box-shadow:0 6px 16px rgba(0,0,0,.08);
  padding:16px;margin:16px auto;max-width:900px
}
.card h2{margin:0 0 12px 0;text-align:center}
.map-wrap{display:flex;justify-content:center}
.map-wrap iframe{
  width:100%;max-width:900px;height:480px;border:0;border-radius:12px;
  box-shadow:0 6px 16px rgba(0,0,0,.12)
}

/* ====== Form ====== */
.form{
  background:#fff;border-radius:12px;box-shadow:0 6px 16px rgba(0,0,0,.08);
  padding:18px;margin:18px auto;max-width:900px
}
label{font-weight:800;color:var(--navy);display:block;margin:6px 0 6px}
.input{
  width:100%;padding:12px;border:1px solid #cfd3d7;border-radius:10px;font-family:inherit
}
.hint{color:#64748b;font-size:13px;margin-top:6px}
.btn{
  background:#0B3B4A;color:#fff;border:none;border-radius:10px;padding:10px 16px;
  font-weight:800;cursor:pointer
}
.btn:disabled{opacity:.6;cursor:not-allowed}

/* Responsive tweaks */
@media (max-width:768px){
  .cm-title{font-size:22px}
  .map-wrap iframe{height:360px}
}
</style>
</head>
<body>

<!-- Header -->
<header class="cm-header">
  <a href="secretary_dashboard.php" class="cm-back">
    <img src="image/btn-back.png" alt="Back">
  </a>
  <div class="cm-center">
    <img src="image/httc_main-logo.jpg" class="cm-logo" alt="Logo">
    <h1 class="cm-title">CONTENT MANAGEMENT</h1>
  </div>
  <div></div>
</header>

<!-- Pills Nav -->
<nav>
  <a class="nav-pill" href="content-management_home-page.php"><i class="fa-solid fa-house"></i> Home Page</a>
  <a class="nav-pill" href="content-management_service.php"><i class="fa-solid fa-hands-praying"></i> Service</a>
  <a class="nav-pill" href="content-management_events.php"><i class="fa-solid fa-calendar-days"></i> Events</a>
  <a class="nav-pill" href="content-management_gallery.php"><i class="fa-solid fa-images"></i> Gallery</a>
  <a class="nav-pill" href="content-management_ministries.php"><i class="fa-solid fa-people-group"></i> Ministries</a>
  <a class="nav-pill" href="content-management_join-us.php"><i class="fa-solid fa-user-plus"></i> Join Us</a>
  <a class="nav-pill active" href="#"><i class="fa-solid fa-location-dot"></i> Find Us</a>
</nav>

<div class="container">
  <!-- Live Map -->
  <section class="card">
    <h2>Location</h2>
    <div class="map-wrap">
      <?php if ($currentMap !== ''): ?>
        <iframe
          src="<?php echo htmlspecialchars($currentMap, ENT_QUOTES, 'UTF-8'); ?>"
          allowfullscreen="" loading="lazy"
          referrerpolicy="no-referrer-when-downgrade">
        </iframe>
      <?php else: ?>
        <p style="color:#6b7280;text-align:center;margin:12px 0 6px">
          No map location set yet. Paste a Google Maps <em>embed</em> link below and save.
        </p>
      <?php endif; ?>
    </div>
  </section>

  <!-- Form -->
  <form class="form" method="POST" id="mapForm">
    <label for="embed_url">Google Maps Embed (paste full iframe OR src link)</label>
    <input
      type="text"
      id="embed_url"
      name="embed_url"
      class="input"
      placeholder='Example: &lt;iframe src="https://www.google.com/maps/embed?pb=..."&gt;'
      value="<?php echo htmlspecialchars($currentMap, ENT_QUOTES, 'UTF-8'); ?>"
      required
    >
    <div class="hint">
      We’ll automatically **clean** what you paste and store only the safe <code>src</code> URL.
    </div>
    <div style="margin-top:12px;display:flex;gap:10px;justify-content:flex-end">
      <button class="btn" type="submit" name="save_location" id="saveBtn">Save Changes</button>
    </div>
  </form>
</div>

<script>
/* ================================
   Client-side Link Fixer (helper)
   - Accepts full iframe or a src URL
   - Ensures only a valid Google Maps embed src is submitted
================================ */
function cleanMapsInput(raw){
  if(!raw) return '';

  raw = raw.trim();

  // If full iframe is pasted, pull src
  const m = raw.match(/src="([^"]+)"/i);
  if (m && m[1]) raw = m[1].trim();

  // Accept embed patterns only
  const ok1 = /^https?:\/\/(www\.)?google\.com\/maps\/embed\?pb=/i.test(raw);
  const ok2 = /^https?:\/\/(www\.)?google\.com\/maps\/embed\/v1\//i.test(raw); // API-key style

  return (ok1 || ok2) ? raw : '';
}

// Auto-clean as they blur/paste
const input = document.getElementById('embed_url');
input.addEventListener('blur', () => {
  const cleaned = cleanMapsInput(input.value);
  if (cleaned) input.value = cleaned;
});

// Final validation on submit
document.getElementById('mapForm').addEventListener('submit', (e) => {
  const cleaned = cleanMapsInput(input.value);
  if (!cleaned) {
    e.preventDefault();
    Swal.fire({
      icon:'error',
      title:'Invalid embed',
      text:'Paste a valid Google Maps embed (iframe or src).'
    });
    return false;
  }
  input.value = cleaned; // submit only the cleaned src
});

/* Toasts from server */
<?php if ($TOAST): ?>
Swal.fire({toast:true, position:'top-end', icon:'success', title:'<?php echo addslashes($TOAST); ?>', showConfirmButton:false, timer:2000});
<?php endif; ?>
<?php if ($ERR): ?>
Swal.fire({icon:'error', title:'Error', text:'<?php echo addslashes($ERR); ?>'});
<?php endif; ?>
</script>
</body>
</html>
