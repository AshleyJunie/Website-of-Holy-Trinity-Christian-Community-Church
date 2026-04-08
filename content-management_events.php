<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db-connection.php';
mysqli_set_charset($db_connection, 'utf8mb4');

// ADD BELOW THIS LINE — safe recovery for bind_param count mismatch on ADD
set_exception_handler(function(Throwable $e) use ($db_connection) {
  if ($e instanceof ArgumentCountError && strpos($e->getMessage(), 'type definition string must match the number of bind variables') !== false) {
    // Attempt graceful recovery ONLY for the known ADD flow
    $act = $_POST['action'] ?? '';
    if ($act === 'add') {
      $title    = trim($_POST['title'] ?? '');
      $category = trim($_POST['category'] ?? '');
      $details  = trim($_POST['details'] ?? '');
      $filePath = $GLOBALS['__cm_last_upload_path'] ?? ''; // set when move_uploaded_file succeeds

      if ($filePath !== '') {
        if ($stmt = mysqli_prepare($db_connection, "INSERT INTO events_table (title, category, imgSrc, details, status) VALUES (?,?,?,?,'Active')")) {
          // Correct 4 placeholders -> 4 vars
          mysqli_stmt_bind_param($stmt, "ssss", $title, $category, $filePath, $details);
          $ok = @mysqli_stmt_execute($stmt);
          mysqli_stmt_close($stmt);
          header("Location: ?toast=" . ($ok ? "Event+added+successfully" : "Failed+to+add+event"));
          exit;
        }
      }
      // If we get here, fallback to error toast
      header("Location: ?err=" . rawurlencode("Failed to add event (auto-recover)."));
      exit;
    }
  }
  // Not our known case: rethrow
  throw $e;
});

/* =====================================================================
   ADMIN BASICS + HELPERS + AUDIT (same logic as your reference)
   - backfill admin_user/admin_email in session
   - h(): HTML escape
   - build_changed_events_notes(): human-readable diffs
   - audit_events_action(): 3-level fallback (JSON → retry → MINIMAL)
   - pre-capture BEFORE rows for ADD (none), EDIT, ARCHIVE
   - register_shutdown_function(): audit INSERT, UPDATE, ARCHIVE after handlers
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
if (!function_exists('build_changed_events_notes')) {
  function build_changed_events_notes(array $before, array $after): string {
    $labels = [
      'title'    => 'Title',
      'category' => 'Category',
      'imgSrc'   => 'Image Path',
      'imgAlt'   => 'Alt Text',
      'details'  => 'Details',
      'status'   => 'Status',
    ];
    $changes = [];
    foreach ($labels as $k=>$label) {
      $old = isset($before[$k]) ? (string)$before[$k] : '';
      $new = isset($after[$k])  ? (string)$after[$k]  : '';
      if ($old !== $new) {
        $changes[] = "Changed {$label}: ".($old===''?'—':$old)." → ".($new===''?'—':$new);
      }
    }
    return $changes ? ('Updated event — '.implode('; ', $changes))
                    : 'Updated event — No values changed.';
  }
}

/* Audit helper */
if (!function_exists('audit_events_action')) {
  function audit_events_action(
    mysqli $db,
    string $action,              // INSERT | UPDATE | ARCHIVE | DELETE
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

    $form   = 'content-management_events.php';
    $source = 'events_table';

    if ($notes === '') {
      $notes = $action==='INSERT'  ? 'Added event'
            : ($action==='UPDATE' ? 'Updated event'
            : ($action==='ARCHIVE'? 'Archived event'
                                   : 'Event action'));
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
$GLOBALS['__event_before_edit']    = null;
$GLOBALS['__event_before_archive'] = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $act = $_POST['action'] ?? '';
  if ($act === 'edit') {
    $id = (int)($_POST['eventId'] ?? 0);
    if ($id>0 && ($st = mysqli_prepare($db_connection, "SELECT eventId,title,category,imgSrc,imgAlt,details,COALESCE(status,'Active') AS status FROM events_table WHERE eventId=? LIMIT 1"))) {
      mysqli_stmt_bind_param($st,'i',$id);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $GLOBALS['__event_before_edit'] = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
      mysqli_stmt_close($st);
    }
  }
  if ($act === 'archive') {
    $id = (int)($_POST['eventId'] ?? 0);
    if ($id>0 && ($st = mysqli_prepare($db_connection, "SELECT eventId,title,category,imgSrc,imgAlt,details,COALESCE(status,'Active') AS status FROM events_table WHERE eventId=? LIMIT 1"))) {
      mysqli_stmt_bind_param($st,'i',$id);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $GLOBALS['__event_before_archive'] = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
      mysqli_stmt_close($st);
    }
  }
}

/* ===== ADDED CODE START =====
   Helper: reliably find the new event ID even if mysqli_insert_id() returns 0.
===== */
if (!function_exists('cm_find_new_event_id')) {
  function cm_find_new_event_id(mysqli $db): int {
    $id = (int)mysqli_insert_id($db);
    if ($id > 0) return $id;

    // Fallback: search using posted values (title/imgSrc/imgAlt/details/status='Active')
    $title   = trim($_POST['title']   ?? '');
    $imgAlt  = trim($_POST['imgAlt']  ?? '');
    $details = trim($_POST['details'] ?? '');
    $movedPath = $GLOBALS['__cm_last_upload_path'] ?? '';

    if ($title !== '' && $movedPath !== '') {
      $sql = "SELECT eventId FROM events_table
              WHERE title=? AND imgSrc=? AND imgAlt=? AND details=? AND COALESCE(status,'Active')='Active'
              ORDER BY eventId DESC LIMIT 1";
      if ($st = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($st, 'ssss', $title, $movedPath, $imgAlt, $details);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        if ($row = mysqli_fetch_assoc($res)) {
          $id = (int)$row['eventId'];
        }
        mysqli_stmt_close($st);
      }
    }
    return $id > 0 ? $id : 0;
  }
}
/* ===== ADDED CODE END ===== */

/* ===== ADDED CODE START =====
   Pre-capture a combined datetime from posted date & time (or default "now").
   This ensures we always have a value even if browser didn't send them.
===== */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add') {
  $d = trim($_POST['event_date_date'] ?? '');
  $t = trim($_POST['event_date_time'] ?? '');
  if ($d === '' || $t === '') {
    $GLOBALS['__cm_event_dt'] = date('Y-m-d H:i:s');
  } else {
    $ts = strtotime($d.' '.$t);
    $GLOBALS['__cm_event_dt'] = $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
  }
}
/* ===== ADDED CODE END ===== */

/* ===== ADD BELOW THIS LINE — disable_date wiring (pre-capture) =====
   Capture intent for disabling appointments and compute disable_date value.
   Treat current "Yes" option (value 'upcoming') as TRUE; anything else as FALSE.
===== */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add') {
  $disableRaw = strtolower(trim($_POST['Disable'] ?? ''));
  $shouldDisable = in_array($disableRaw, ['upcoming','yes','1','true'], true);

  // Use pre-captured event datetime if available, otherwise reconstruct, otherwise now.
  $dt = $GLOBALS['__cm_event_dt'] ?? (function(){
    $d = trim($_POST['event_date_date'] ?? '');
    $t = trim($_POST['event_date_time'] ?? '');
    $ts = $d && $t ? strtotime($d.' '.$t) : false;
    return $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
  })();

  // Store decision globally for shutdown updater.
  $GLOBALS['__cm_disable_should_set'] = $shouldDisable ? 1 : 0;
  $GLOBALS['__cm_disable_date'] = $shouldDisable ? $dt : null;
}
/* ===== ADDED CODE END ===== */

/* Shutdown hook to perform audits AFTER handlers run */
register_shutdown_function(function() use ($db_connection) {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
  $act = $_POST['action'] ?? '';

  // ADD -> INSERT audit (use last insert id if available)
  if ($act === 'add') {
    $newId = (int)mysqli_insert_id($db_connection);
    if ($newId <= 0) { $newId = cm_find_new_event_id($db_connection); }
    if ($newId > 0) {
      $row = null;
      if ($st = mysqli_prepare($db_connection, "SELECT eventId,title,category,imgSrc,imgAlt,details,COALESCE(status,'Active') AS status FROM events_table WHERE eventId=? LIMIT 1")) {
        mysqli_stmt_bind_param($st,'i',$newId);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $row = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
        mysqli_stmt_close($st);
      }
      if ($row) {
        $notes = "Added event — ID {$newId}; Title: ".((string)$row['title'] ?? '');
        $audit = audit_events_action($db_connection, 'INSERT', (string)$newId, [
          'eventId'  => (string)$row['eventId'],
          'title'    => (string)($row['title'] ?? ''),
          'category' => (string)($row['category'] ?? ''),
          'imgSrc'   => (string)($row['imgSrc'] ?? ''),
          'imgAlt'   => (string)($row['imgAlt'] ?? ''),
          'details'  => (string)($row['details'] ?? ''),
          'status'   => (string)($row['status'] ?? ''),
        ], $notes);
        if (!$audit['ok']) error_log('[AUDIT][event-insert]['.$audit['attempt'].'] '.$audit['msg']);
      }
    }
  }

  // EDIT -> UPDATE audit
  if ($act === 'edit') {
    $id = (int)($_POST['eventId'] ?? 0);
    if ($id>0) {
      $after = null;
      if ($st = mysqli_prepare($db_connection, "SELECT eventId,title,category,imgSrc,imgAlt,details,COALESCE(status,'Active') AS status FROM events_table WHERE eventId=? LIMIT 1")) {
        mysqli_stmt_bind_param($st,'i',$id);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $after = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
        mysqli_stmt_close($st);
      }
      $before = $GLOBALS['__event_before_edit'] ?: [];
      if ($after) {
        $notes = build_changed_events_notes($before ?: [], $after ?: []);
        $audit = audit_events_action($db_connection, 'UPDATE', (string)$id, [
          'eventId'  => (string)$after['eventId'],
          'title'    => (string)($after['title'] ?? ''),
          'category' => (string)($after['category'] ?? ''),
          'imgSrc'   => (string)($after['imgSrc'] ?? ''),
          'imgAlt'   => (string)($after['imgAlt'] ?? ''),
          'details'  => (string)($after['details'] ?? ''),
          'status'   => (string)($after['status'] ?? ''),
        ], $notes);
        if (!$audit['ok']) error_log('[AUDIT][event-update]['.$audit['attempt'].'] '.$audit['msg']);
      }
    }
  }

  // ARCHIVE -> ARCHIVE audit
  if ($act === 'archive') {
    $id = (int)($_POST['eventId'] ?? 0);
    if ($id>0) {
      $row = null;
      if ($st = mysqli_prepare($db_connection, "SELECT eventId,title,COALESCE(status,'Active') AS status FROM events_table WHERE eventId=? LIMIT 1")) {
        mysqli_stmt_bind_param($st,'i',$id);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $row = $res ? (mysqli_fetch_assoc($res) ?: null) : null;
        mysqli_stmt_close($st);
      }
      if ($row && strtolower((string)$row['status']) === 'inactive') {
        $notes = "Archived event — ID {$id}".(!empty($row['title']) ? " (Title: ".$row['title'].")" : '');
        $audit = audit_events_action($db_connection, 'ARCHIVE', (string)$id, [
          'eventId' => (string)$row['eventId'],
          'status'  => 'Inactive',
        ], $notes);
        if (!$audit['ok']) error_log('[AUDIT][event-archive]['.$audit['attempt'].'] '.$audit['msg']);
      }
    }
  }
});

/* ===== ADDED CODE START =====
   Shutdown hook v1 (original): set event_date from posted values (kept).
===== */
register_shutdown_function(function() use ($db_connection) {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
  if (($_POST['action'] ?? '') !== 'add') return;

  $date = trim($_POST['event_date_date'] ?? '');
  $time = trim($_POST['event_date_time'] ?? '');
  if ($date === '' || $time === '') return;

  $ts = strtotime($date.' '.$time);
  if ($ts === false) return;
  $dt = date('Y-m-d H:i:s', $ts);

  $newId = cm_find_new_event_id($db_connection);
  if ($newId > 0 && ($st = mysqli_prepare($db_connection, "UPDATE events_table SET event_date=? WHERE eventId=?"))) {
    mysqli_stmt_bind_param($st, 'si', $dt, $newId);
    @mysqli_stmt_execute($st);
    if ($err = mysqli_error($db_connection)) error_log('[EVENTS][UPDATE event_date V1] '.$err);
    mysqli_stmt_close($st);
  }
});
/* ===== ADDED CODE END ===== */

/* ===== ADDED CODE START =====
   Shutdown hook v2 (strong fallback): always set event_date using pre-captured
   value __cm_event_dt if column is still NULL/zero. Ensures success.
===== */
register_shutdown_function(function() use ($db_connection) {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
  if (($_POST['action'] ?? '') !== 'add') return;

  $dt = $GLOBALS['__cm_event_dt'] ?? null;
  if (!$dt) return;

  $newId = cm_find_new_event_id($db_connection);
  if ($newId > 0 && ($st = mysqli_prepare($db_connection, "UPDATE events_table SET event_date=? WHERE eventId=? AND (event_date IS NULL OR event_date='0000-00-00 00:00:00')"))) {
    mysqli_stmt_bind_param($st, 'si', $dt, $newId);
    @mysqli_stmt_execute($st);
    if ($err = mysqli_error($db_connection)) error_log('[EVENTS][UPDATE event_date V2] '.$err);
    mysqli_stmt_close($st);
  }
});
/* ===== ADDED CODE END ===== */

/* ===== ADDED CODE START =====
   Shutdown hook: store the posted event_link (already working previously).
===== */
register_shutdown_function(function() use ($db_connection) {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
  if (($_POST['action'] ?? '') !== 'add') return;

  $link = trim($_POST['event_link'] ?? '');
  if ($link === '') return;

  $newId = cm_find_new_event_id($db_connection);
  if ($newId > 0 && ($st = mysqli_prepare($db_connection, "UPDATE events_table SET event_link=? WHERE eventId=?"))) {
    mysqli_stmt_bind_param($st, 'si', $link, $newId);
    @mysqli_stmt_execute($st);
    if ($err = mysqli_error($db_connection)) error_log('[EVENTS][UPDATE event_link] '.$err);
    mysqli_stmt_close($st);
  }
});
/* ===== ADDED CODE END ===== */

/* ===== ADD BELOW THIS LINE — disable_date wiring (shutdown updater) =====
   On ADD, set events_table.disable_date to the event datetime if user chose Yes,
   otherwise explicitly set it to NULL.
===== */
register_shutdown_function(function() use ($db_connection) {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
  if (($_POST['action'] ?? '') !== 'add') return;

  $newId = cm_find_new_event_id($db_connection);
  if ($newId <= 0) return;

  $shouldSet = (int)($GLOBALS['__cm_disable_should_set'] ?? 0);
  $dt        = $GLOBALS['__cm_disable_date'] ?? null;

  if ($shouldSet && $dt) {
    if ($st = mysqli_prepare($db_connection, "UPDATE events_table SET disable_date=? WHERE eventId=?")) {
      mysqli_stmt_bind_param($st, 'si', $dt, $newId);
      @mysqli_stmt_execute($st);
      if ($err = mysqli_error($db_connection)) error_log('[EVENTS][UPDATE disable_date=DT] '.$err);
      mysqli_stmt_close($st);
    }
  } else {
    if ($st = mysqli_prepare($db_connection, "UPDATE events_table SET disable_date=NULL WHERE eventId=?")) {
      mysqli_stmt_bind_param($st, 'i', $newId);
      @mysqli_stmt_execute($st);
      if ($err = mysqli_error($db_connection)) error_log('[EVENTS][UPDATE disable_date=NULL] '.$err);
      mysqli_stmt_close($st);
    }
  }
});
/* ===== ADDED CODE END ===== */

/* ===== ADDED CODE START — FINAL FIX (ENUM 'Yes'/'No') =====
   Because the DB column `disable_date` is ENUM('Yes','No'), set the exact
   enum value from the <select name="Disable"> after all previous updaters.
   This **overrides** earlier datetime/NULL writes and ensures correct storage.
===== */
register_shutdown_function(function() use ($db_connection) {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
  if (($_POST['action'] ?? '') !== 'add') return;

  $newId = cm_find_new_event_id($db_connection);
  if ($newId <= 0) return;

  $raw = strtolower(trim($_POST['Disable'] ?? ''));
  // Treat typical truthy inputs as 'Yes'
  $flag = in_array($raw, ['yes','upcoming','1','true'], true) ? 'Yes' : 'No';

  if ($st = mysqli_prepare($db_connection, "UPDATE events_table SET disable_date=? WHERE eventId=?")) {
    mysqli_stmt_bind_param($st, 'si', $flag, $newId);
    @mysqli_stmt_execute($st);
    if ($err = mysqli_error($db_connection)) error_log('[EVENTS][UPDATE disable_date=ENUM] '.$err);
    mysqli_stmt_close($st);
  }
});
/* ===== ADDED CODE END — FINAL FIX ===== */

/* =====================================================================
   ORIGINAL HANDLERS (kept): ADD / EDIT / ARCHIVE + FETCH
===================================================================== */

/* ========== ADD EVENT ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
  $title    = trim($_POST['title'] ?? '');
  $category = trim($_POST['category'] ?? '');
  $details  = trim($_POST['details'] ?? '');
  $filePath = '';

  if (isset($_FILES['imgSrc']) && $_FILES['imgSrc']['error'] === 0) {
    $folder = "uploads/";
    if (!is_dir($folder)) @mkdir($folder, 0777, true);
    $fileName = time() . '_' . basename($_FILES['imgSrc']['name']);
    $fileName = preg_replace('/[^A-Za-z0-9_.-]/', '_', $fileName);
    $target = $folder . $fileName;
    if (move_uploaded_file($_FILES['imgSrc']['tmp_name'], $target)) {
      $filePath = $target;
      /* remember moved path for fallback ID match */
      $GLOBALS['__cm_last_upload_path'] = $filePath;
    }
  }

  if ($filePath) {
    $stmt = mysqli_prepare($db_connection, "INSERT INTO events_table (title, category, imgSrc, details, status) VALUES (?,?,?,?,'Active')");
    mysqli_stmt_bind_param($stmt, "sssss", $title, $category, $filePath, $details);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    header("Location: ?toast=" . ($ok ? "Event+added+successfully" : "Failed+to+add+event"));
    exit;
  } else {
    header("Location: ?err=Failed+to+upload+image");
    exit;
  }
}

/* ========== EDIT EVENT ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
  $id = (int)($_POST['eventId'] ?? 0);
  $details = trim($_POST['details'] ?? '');
  if ($id > 0) {
    $stmt = mysqli_prepare($db_connection, "UPDATE events_table SET details=? WHERE eventId=?");
    mysqli_stmt_bind_param($stmt, "si", $details, $id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    header("Location: ?toast=" . ($ok ? "Changes+saved+successfully" : "Failed+to+save+changes"));
    exit;
  }
}

/* ========== ARCHIVE (SOFT DELETE) ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive') {
  $id = (int)($_POST['eventId'] ?? 0);
  if ($id > 0) {
    $stmt = mysqli_prepare($db_connection, "UPDATE events_table SET status='Inactive' WHERE eventId=?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    header("Location: ?toast=" . ($ok ? "Event+archived+successfully" : "Failed+to+archive+event"));
    exit;
  }
}

/* ========== FETCH EVENTS ========== */
$events = [];
$res = mysqli_query($db_connection, "SELECT eventId, title, category, imgSrc, imgAlt, details, COALESCE(status,'Active') AS status FROM events_table WHERE COALESCE(status,'Active')='Active' ORDER BY eventId DESC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $events[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Content Management - Events</title>
<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" />
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- FIXED CSS LINK -->
<link rel="stylesheet"
      href="/HTCCC-SYSTEM/css/content-management_events.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/content-management_events.css'); ?>">

<!-- minor styles kept -->
<style>
  .hidden-soft { display: none !important; }
  .dt-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .dt-grid label { grid-column: span 2; margin-top: 6px; }
  .dt-grid .field { display: flex; flex-direction: column; gap: 6px; }
  .upload-note { font-size: 12px; color:#6b7280; margin-top:6px; }
</style>
</head>
<body>

<header class="cm-header">
  <a href="secretary_dashboard.php" class="cm-back">
    <img src="image/btn-back.png" alt="Back">
  </a>
  <div class="cm-center">
    <img src="image/httc_main-logo.jpg" class="cm-logo" alt="HTCCC Logo">
    <h1 class="cm-title">CONTENT MANAGEMENT</h1>
  </div>
  <div class="cm-right"></div>
</header>

<nav>
  <a href="content-management_home-page.php" class="nav-pill"><i class="fas fa-home"></i> Home Page</a>
  <a href="content-management_service.php" class="nav-pill"><i class="fas fa-bell"></i> Service</a>
  <a href="content-management_events.php" class="nav-pill active"><i class="fas fa-calendar-alt"></i> Events</a>
  <a href="content-management_gallery.php" class="nav-pill"><i class="fas fa-image"></i> Gallery</a>
  <a href="content-management_ministries.php" class="nav-pill"><i class="fas fa-users"></i> Ministries</a>
  <a href="content-management_join-us.php" class="nav-pill"><i class="fas fa-user-plus"></i> Join Us</a>
  <a href="content-management_find-us.php" class="nav-pill"><i class="fas fa-map-marker-alt"></i> Find Us</a>
</nav>

<main>
  <div class="events-toolbar">
    <button class="add-button" id="openAddModal"><i class="fas fa-plus-square"></i> ADD</button>
  </div>

  <div class="events-table">
    <div class="et-head"><div>POSTER</div><div>TYPE OF EVENTS</div><div style="text-align:right">ACTION</div></div>
    <?php if ($events): foreach ($events as $row): ?>
    <div class="et-row">
      <div class="et-poster">
        <img src="<?php echo h($row['imgSrc'] ?: 'image/placeholder.png'); ?>"
             alt="<?php echo h($row['imgAlt'] ?: $row['title']); ?>">
      </div>
      <div class="et-title"><?php echo h($row['title']); ?></div>
      <div class="et-actions">
        <form method="POST" style="display:inline">
          <input type="hidden" name="action" value="archive">
          <input type="hidden" name="eventId" value="<?php echo (int)$row['eventId']; ?>">
          <button class="btn-delete" type="submit"><i class="fas fa-trash-alt"></i> ARCHIVE</button>
        </form>
        <button class="btn-edit edit-details"
                data-id="<?php echo (int)$row['eventId']; ?>"
                data-title="<?php echo h($row['title']); ?>"
                data-details="<?php echo h($row['details']); ?>">
          <i class="fas fa-edit"></i> EDIT
        </button>
      </div>
    </div>
    <?php endforeach; else: ?>
      <div class="et-row"><div style="grid-column:1/4;text-align:center;color:#6b7280;">No events found.</div></div>
    <?php endif; ?>
  </div>
</main>

<!-- ADD MODAL -->
<div class="modal-backdrop" id="addModal">
  <div class="modal">
    <h2>Add Event</h2>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add">
      <label>Title</label><input type="text" name="title" required>
      <label>Category</label>
      <select name="category" required>
        <option value="upcoming">Upcoming</option>
        <option value="previous">Previous</option>
      </select>
      <label>Image (Poster)</label><input type="file" name="imgSrc" accept="image/*" required>
      <small class="upload-note">Allowed: JPG, JPEG, PNG. Max size: 5&nbsp;MB.</small>

      <div class="dt-grid">
        <label>Event Schedule</label>
        <div class="field">
          <span>Date</span>
          <input type="date" name="event_date_date" id="event_date_date" required>
        </div>
        <div class="field">
          <span>Time</span>
          <input type="time" name="event_date_time" id="event_date_time" required>
        </div>
      </div>
      <br>
      <p>Disable the appointment Date in Calendar?</p>
      <select name="Disable" required>
        <option value="Yes">Yes</option>
        <option value="No">No</option>
      </select>
      <label>Event Link (optional)</label>
      <input type="url" name="event_link" placeholder="https://example.com/..." pattern="https?://.*">

      <label>Details</label><textarea name="details" rows="5" required></textarea>
      <div class="actions">
        <button type="button" class="btn cancel" id="cancelAdd">Cancel</button>
        <button type="submit" class="btn save">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-backdrop" id="editModal">
  <div class="modal">
    <h2>Edit Event Details</h2>
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="eventId" id="edit_eventId">
      <label>Title</label><input type="text" id="edit_title" readonly>
      <label>Details</label><textarea name="details" id="edit_details" rows="6" required></textarea>
      <div class="actions">
        <button type="button" class="btn cancel" id="cancelEdit">Cancel</button>
        <button type="submit" class="btn save">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
// open/close Add modal
const addModal=document.getElementById('addModal');
document.getElementById('openAddModal').onclick=()=>addModal.style.display='flex';
document.getElementById('cancelAdd').onclick=()=>addModal.style.display='none';
addModal.onclick=e=>{if(e.target===addModal)addModal.style.display='none'};

// open/close Edit modal
const editModal=document.getElementById('editModal');
document.getElementById('cancelEdit').onclick=()=>editModal.style.display='none';
editModal.onclick=e=>{if(e.target===editModal)addModal.style.display='none'};

// Edit button
document.querySelectorAll('.edit-details').forEach(btn=>{
  btn.onclick=()=>{
    document.getElementById('edit_eventId').value=btn.dataset.id;
    document.getElementById('edit_title').value=btn.dataset.title;
    document.getElementById('edit_details').value=btn.dataset.details;
    editModal.style.display='flex';
  }
});

/* TOAST */
(function(){
  const q=new URLSearchParams(location.search);
  const msg=q.get('toast')||q.get('err');
  if(!msg)return;
  Swal.fire({
    toast:true,position:'top-end',
    icon:q.get('err')?'error':'success',
    title:msg,showConfirmButton:false,timer:2000,timerProgressBar:true,
    background:'#fff',color:'#0B1446',
    didOpen:t=>{t.addEventListener('mouseenter',Swal.stopTimer);t.addEventListener('mouseleave',Swal.resumeTimer);}
  });
  q.delete('toast');q.delete('err');
  history.replaceState({},'',`${location.pathname}${q.toString()?'?'+q.toString():''}`);
})();

/* Force category + defaults + file validation */
(function(){
  const form = document.querySelector('#addModal form');
  if (!form) return;

  // Force Category = 'upcoming'
  const sel = form.querySelector('select[name="category"]');
  if (sel) {
    sel.value = 'upcoming';
    sel.required = false;
    sel.disabled = true;
    sel.classList.add('hidden-soft');
    const prev = sel.previousElementSibling;
    if (prev && prev.tagName === 'LABEL') prev.classList.add('hidden-soft');
    let hid = form.querySelector('input[type="hidden"][name="category"]');
    if (!hid) { hid = document.createElement('input'); hid.type='hidden'; hid.name='category'; form.appendChild(hid); }
    hid.value = 'upcoming';
  }

  // Defaults for date/time
  const dInput = form.querySelector('#event_date_date');
  const tInput = form.querySelector('#event_date_time');
  const now = new Date();
  const pad = n => String(n).padStart(2,'0');
  if (dInput && !dInput.value) dInput.value = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}`;
  if (tInput && !tInput.value) tInput.value = `${pad(now.getHours())}:${pad(now.getMinutes())}`;

  // File validation: types & size
  const file = form.querySelector('input[name="imgSrc"]');
  const MAX_BYTES = 5 * 1024 * 1024; // 5MB
  const ALLOWED_EXT = ['jpg','jpeg','png'];
  if (file) {
    file.setAttribute('accept','image/jpeg,image/png');
    const validateFile = () => {
      const f = file.files && file.files[0];
      if (!f) return true;
      const name = f.name || '';
      const ext = name.split('.').pop().toLowerCase();
      const okExt = ALLOWED_EXT.includes(ext);
      const okSize = f.size > 0 && f.size <= MAX_BYTES;
      if (!okExt || !okSize) {
        let why = !okExt ? 'Only JPG, JPEG, PNG are allowed.' : 'File too large (max 5 MB).';
        Swal.fire({icon:'error',title:'Invalid file',text:why});
        file.value='';
        return false;
      }
      return true;
    };
    file.addEventListener('change', validateFile);
    form.addEventListener('submit', (e)=>{ if(!validateFile()) e.preventDefault(); });
  }
})();
</script>
</body>
</html>
END FILE
