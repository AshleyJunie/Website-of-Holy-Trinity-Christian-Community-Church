<?php
include 'db-connection.php';
session_start();

/* ===== ADD BELOW THIS LINE — ADMIN BASICS, HELPERS, AUDIT (NO REMOVALS) ===== */

/* ✅ Ensure admin FULL NAME in session (Lastname, Firstname Middlename)
   - NO username/email needed for your requirement
*/
$__admin_id = (int)($_SESSION['admin_id'] ?? 0);

if ($__admin_id > 0 && empty($_SESSION['admin_name'])) {
  if ($__st = @mysqli_prepare(
    $db_connection,
    "SELECT admin_firstname, admin_middlename, admin_lastname
     FROM admin_table
     WHERE admin_id=? LIMIT 1"
  )) {
    mysqli_stmt_bind_param($__st, "i", $__admin_id);
    if (@mysqli_stmt_execute($__st)) {
      $res = mysqli_stmt_get_result($__st);
      if ($row = mysqli_fetch_assoc($res)) {
        $fn = trim((string)($row['admin_firstname'] ?? ''));
        $mn = trim((string)($row['admin_middlename'] ?? ''));
        $ln = trim((string)($row['admin_lastname'] ?? ''));

        $firstMid = trim($fn . ($mn !== '' ? ' ' . $mn : ''));
        if ($ln !== '' && $firstMid !== '') {
          $_SESSION['admin_name'] = trim($ln . ', ' . $firstMid);
        } elseif ($ln !== '') {
          $_SESSION['admin_name'] = $ln;
        } else {
          $_SESSION['admin_name'] = $firstMid;
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

/* Build human-readable change notes (ONLY changed fields) */
if (!function_exists('build_changed_fields_notes')) {
  function build_changed_fields_notes(array $before, array $after): string {
    $labels = [
      'ministry_position'      => 'Ministry Position',
      'ministry_lastname'      => 'Lastname',
      'ministry_firstname'     => 'Firstname',
      'ministry_middlename'    => 'Middlename',
      'ministry_extensionname' => 'Extension',
      'sex'                    => 'Sex',
      'birthday'               => 'Birthday',
      'ministry_email'         => 'Email',
      'date_join'              => 'Date Joined',
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
    if (!$changes) return 'Updated Usher/Usherette member — No values changed.';
    return 'Updated Usher/Usherette member — ' . implode('; ', $changes);
  }
}

/* ✅ Audit helper (FULL NAME only; NO email) with UUID txn_id */
if (!function_exists('audit_ministry_action')) {
  function audit_ministry_action(
    mysqli $db,
    string $action,
    string $recordPk,
    array  $detailsAfterArr = [],
    string $notes = ''
  ): array {

    $actorId = (int)($_SESSION['admin_id'] ?? 0);

    /* ✅ FULL NAME (preferred) */
    $actorName = (string)($_SESSION['admin_name'] ?? '');
    if ($actorName === '') $actorName = 'Administrator';

    /* ✅ NO EMAIL */
    $actorEmail = '';

    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $detailsAfterJson = $detailsAfterArr
      ? json_encode($detailsAfterArr, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
      : null;

    $form   = 'admin-ministry-usher.php';
    $source = 'ministries_table';

    if ($notes === '') {
      $notes = $action==='INSERT'   ? 'Added Usher/Usherette member'
            : ($action==='UPDATE'   ? 'Updated Usher/Usherette member'
            : ($action==='ARCHIVE'  ? 'Archived Usher/Usherette member'
            : ($action==='UNARCHIVE'? 'Unarchived Usher/Usherette member'
                                     : 'Usher/Usherette action')));
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
        $actorId, $actorName, $actorEmail, $action, $source, $recordPk, $form, $ip, $ua, $notes, $detailsAfterJson
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
          $actorId, $actorName, $actorEmail, $action, $source, $recordPk, $form, $ip, $ua, $notes, $detailsAfterJson
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
    $u  = $db->real_escape_string($actorName);
    $e  = $db->real_escape_string($actorEmail); // ''
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

/* Capture OLD row BEFORE update (so we can diff in shutdown) — no edits to your handler */
$GLOBALS['__usher_edit_old'] = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['__action'] ?? '') === 'edit_ministry_usher')) {
  $id = (int)($_POST['ministry_id'] ?? 0);
  if ($id > 0 && ($st0 = mysqli_prepare($db_connection, "
      SELECT ministry_position, ministry_lastname, ministry_firstname, ministry_middlename,
             ministry_extensionname, date_join, sex, birthday, ministry_email
      FROM ministries_table WHERE ministry_id=? LIMIT 1"))) {
    mysqli_stmt_bind_param($st0, 'i', $id);
    mysqli_stmt_execute($st0);
    $res0 = mysqli_stmt_get_result($st0);
    $GLOBALS['__usher_edit_old'] = $res0 ? (mysqli_fetch_assoc($res0) ?: []) : [];
    mysqli_stmt_close($st0);
  }
}

/* Register shutdown auditor */
register_shutdown_function(function() use ($db_connection) {
  // ADD audit (INSERT)
  if (isset($GLOBALS['add_success']) && $GLOBALS['add_success'] === true) {
    $newId = (string)mysqli_insert_id($db_connection);
    if ($newId !== '0') {
      $ministry_type = 'Usher & Usherette';
      $position   = (string)($_POST['ministry_position']      ?? '');
      $lastname   = (string)($_POST['ministry_lastname']      ?? '');
      $firstname  = (string)($_POST['ministry_firstname']     ?? '');
      $middlename = (string)($_POST['ministry_middlename']    ?? '');
      $extension  = (string)($_POST['ministry_extensionname'] ?? '');
      $date_join  = (string)($_POST['date_join']              ?? '');

      $audit_details = [
        'ministry_id' => $newId,
        'ministry_type' => $ministry_type,
        'ministry_position' => $position,
        'ministry_lastname' => $lastname,
        'ministry_firstname'=> $firstname,
        'ministry_middlename'=> $middlename,
        'ministry_extensionname'=> $extension,
        'date_join' => $date_join,
        'archive_status' => 'Active'
      ];
      $nameFmt = trim($lastname . ', ' . $firstname . ($middlename ? ' ' . $middlename : ''));
      $notes = "Added Usher/Usherette member — Name: {$nameFmt}; Ministry Position: {$position}; Date Joined: {$date_join}";
      $audit = audit_ministry_action($db_connection, 'INSERT', $newId, $audit_details, $notes);
      if (!$audit['ok']) error_log('[AUDIT][usher-insert]['.$audit['attempt'].'] '.$audit['msg']);
    }
  }

  // EDIT audit (UPDATE)
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['__action'] ?? '') === 'edit_ministry_usher')) {
    $id = (int)($_POST['ministry_id'] ?? 0);
    if ($id > 0) {
      $after = [];
      if ($st1 = mysqli_prepare($db_connection, "
            SELECT ministry_position, ministry_lastname, ministry_firstname, ministry_middlename,
                   ministry_extensionname, date_join, sex, birthday, ministry_email
            FROM ministries_table WHERE ministry_id=? LIMIT 1")) {
        mysqli_stmt_bind_param($st1, 'i', $id);
        mysqli_stmt_execute($st1);
        $res1 = mysqli_stmt_get_result($st1);
        $after = $res1 ? (mysqli_fetch_assoc($res1) ?: []) : [];
        mysqli_stmt_close($st1);
      }
      $before = $GLOBALS['__usher_edit_old'] ?: [];
      if (!empty($after)) {
        $notes = build_changed_fields_notes($before, $after);
        $audit_details = array_merge(['ministry_id'=>(string)$id], $after);
        $audit = audit_ministry_action($db_connection, 'UPDATE', (string)$id, $audit_details, $notes);
        if (!$audit['ok']) error_log('[AUDIT][usher-update]['.$audit['attempt'].'] '.$audit['msg']);
      }
    }
  }
});

/* ===== END ADDITIONS (helpers + audit) ===== */

/* ---------- ADD DATA HANDLER ---------- */
$add_success = false;
$error_msg   = '';
$old = [
  'ministry_position'      => '',
  'ministry_lastname'      => '',
  'ministry_firstname'     => '',
  'ministry_middlename'    => '',
  'ministry_extensionname' => '',
  'date_join'              => '',
];

/* Positions for Usher & Usherette dropdown */
$POSITION_OPTIONS = [
  "Head Usher",
  "Assistant Head Usher",
  "Usher",
  "Usherette",
  "Greeter",
  "Seating Coordinator",
  "Door Keeper",
  "Flow Coordinator",
  "Security Marshal",
  "Offering Collector",
  "Crowd Control",
  "Member"
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['__action'] ?? '') === 'add_ministry') {
  $ministry_type = 'Usher & Usherette';

  $position      = trim($_POST['ministry_position'] ?? '');
  $lastname      = trim($_POST['ministry_lastname'] ?? '');
  $firstname     = trim($_POST['ministry_firstname'] ?? '');
  $middlename    = trim($_POST['ministry_middlename'] ?? '');
  $extension     = trim($_POST['ministry_extensionname'] ?? '');
  $date_join     = trim($_POST['date_join'] ?? '');

  $old = [
    'ministry_position'      => $position,
    'ministry_lastname'      => $lastname,
    'ministry_firstname'     => $firstname,
    'ministry_middlename'    => $middlename,
    'ministry_extensionname' => $extension,
    'date_join'              => $date_join,
  ];

  if ($position === '' || $lastname === '' || $firstname === '' || $date_join === '') {
    $error_msg = 'Please complete all required fields.';
  } else {
    $d = date_create_from_format('Y-m-d', $date_join);
    if (!$d) {
      $error_msg = 'Invalid date format.';
    } else {
      $sql = "INSERT INTO ministries_table
              (ministry_type, ministry_position, ministry_lastname, ministry_firstname, ministry_middlename, ministry_extensionname, date_join, archive_status)
              VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')";
      if ($stmt = mysqli_prepare($db_connection, $sql)) {
        mysqli_stmt_bind_param($stmt, "sssssss", $ministry_type, $position, $lastname, $firstname, $middlename, $extension, $date_join);
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

/* =====================================================================
   EDIT FEATURE ENDPOINTS FOR USHER & USHERETTE (no removals)
===================================================================== */
function normalize_date_php($d){
  if(!$d) return '';
  if (preg_match('/^\d{4}-\d{2}-\d{2}/', $d)) return substr($d,0,10);
  $ts = strtotime($d);
  return $ts ? date('Y-m-d', $ts) : '';
}

if (isset($_GET['ajax']) && $_GET['ajax']==='usher_rows') {
  header('Content-Type: application/json; charset=utf-8');
  $rows = [];
  $q = "SELECT ministry_id, date_join, ministry_position, ministry_lastname, ministry_firstname, ministry_middlename,
               ministry_extensionname, sex, birthday, ministry_email
        FROM ministries_table
        WHERE ministry_type='Usher & Usherette' AND archive_status='Active'
        ORDER BY date_join DESC, ministry_lastname, ministry_firstname";
  if ($rs = mysqli_query($db_connection, $q)) {
    while($r = mysqli_fetch_assoc($rs)) { $rows[] = $r; }
  }
  echo json_encode(['ok'=>true,'rows'=>$rows]); exit;
}

if (isset($_GET['ajax']) && $_GET['ajax']==='usher_row' && isset($_GET['id'])) {
  header('Content-Type: application/json; charset=utf-8');
  $id = (int)$_GET['id'];
  $row = null;
  if ($id>0 && ($st=mysqli_prepare($db_connection, "SELECT ministry_id, date_join, ministry_position, ministry_lastname, ministry_firstname, ministry_middlename, ministry_extensionname, sex, birthday, ministry_email FROM ministries_table WHERE ministry_id=? LIMIT 1"))) {
    mysqli_stmt_bind_param($st,'i',$id);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);
  }
  echo json_encode(['ok'=>(bool)$row,'row'=>$row]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['__action'] ?? '') === 'edit_ministry_usher') {
  header('Content-Type: application/json; charset=utf-8');
  $id         = (int)($_POST['ministry_id'] ?? 0);
  $position   = trim($_POST['ministry_position'] ?? '');
  $lastname   = trim($_POST['ministry_lastname'] ?? '');
  $firstname  = trim($_POST['ministry_firstname'] ?? '');
  $middlename = trim($_POST['ministry_middlename'] ?? '');
  $extension  = trim($_POST['ministry_extensionname'] ?? '');
  $date_join  = normalize_date_php(trim($_POST['date_join'] ?? ''));
  $sex        = trim($_POST['sex'] ?? '');
  $birthday   = normalize_date_php(trim($_POST['birthday'] ?? ''));
  $email      = trim($_POST['ministry_email'] ?? '');

  if (!$id || $position==='' || $lastname==='' || $firstname==='' || $date_join==='') {
    echo json_encode(['ok'=>false,'msg'=>'Please complete required fields.']); exit;
  }

  $sql = "UPDATE ministries_table
          SET ministry_position=?, ministry_lastname=?, ministry_firstname=?, ministry_middlename=?,
              ministry_extensionname=?, date_join=?, sex=?, birthday=?, ministry_email=?
          WHERE ministry_id=? LIMIT 1";
  if ($st=mysqli_prepare($db_connection,$sql)){
    mysqli_stmt_bind_param($st,'sssssssssi',
      $position,$lastname,$firstname,$middlename,$extension,$date_join,$sex,$birthday,$email,$id
    );
    $ok = mysqli_stmt_execute($st);
    $err= $ok ? null : mysqli_error($db_connection);
    mysqli_stmt_close($st);
    echo json_encode(['ok'=>$ok?true:false,'msg'=>$ok?'Updated.':('DB error: '.$err)]); exit;
  } else {
    echo json_encode(['ok'=>false,'msg'=>'Prepare failed.']); exit;
  }
}

/* ===== ARCHIVE ENDPOINT WITH AUDIT (NO REMOVALS) ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && (($_POST['__action'] ?? '') === 'archive_ministry')) {
  header('Content-Type: application/json; charset=utf-8');

  $id = (int)($_POST['ministry_id'] ?? 0);
  if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Missing id']); exit; }

  $nameRow = ['ministry_lastname'=>'','ministry_firstname'=>''];
  if ($stN = mysqli_prepare($db_connection, "SELECT ministry_lastname, ministry_firstname FROM ministries_table WHERE ministry_id=? LIMIT 1")) {
    mysqli_stmt_bind_param($stN, 'i', $id);
    mysqli_stmt_execute($stN);
    $resN = mysqli_stmt_get_result($stN);
    if ($resN) $nameRow = mysqli_fetch_assoc($resN) ?: $nameRow;
    mysqli_stmt_close($stN);
  }

  $sql = "UPDATE ministries_table SET archive_status='Archived' WHERE ministry_id=? LIMIT 1";
  if ($st = mysqli_prepare($db_connection, $sql)) {
    mysqli_stmt_bind_param($st, 'i', $id);
    $ok = mysqli_stmt_execute($st);
    $err= $ok ? null : mysqli_error($db_connection);
    mysqli_stmt_close($st);

    if ($ok) {
      $lname = $nameRow['ministry_lastname'] ?? '';
      $fname = $nameRow['ministry_firstname'] ?? '';
      $notes = "Archived Usher/Usherette member — ID {$id}" .
               (($lname || $fname) ? " (Name: " . trim($lname . ', ' . $fname, ', ') . ")" : '');
      $audit = audit_ministry_action(
        $db_connection,
        'ARCHIVE',
        (string)$id,
        ['ministry_id'=>(string)$id, 'archive_status'=>'Archived'],
        $notes
      );
      if (!$audit['ok']) error_log('[AUDIT][usher-archive]['.$audit['attempt'].'] '.$audit['msg']);
    }

    echo json_encode(['ok'=> (bool)$ok, 'msg'=> $ok ? 'Archived.' : ('DB error: '.$err)]); exit;
  } else {
    echo json_encode(['ok'=>false,'msg'=>'Prepare failed.']); exit;
  }
}

/* ===== UNARCHIVE ENDPOINT WITH AUDIT ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && (($_POST['__action'] ?? '') === 'unarchive_ministry')) {
  header('Content-Type: application/json; charset=utf-8');

  $id = (int)($_POST['ministry_id'] ?? 0);
  if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Missing id']); exit; }

  $nameRow = ['ministry_lastname'=>'','ministry_firstname'=>''];
  if ($stN = mysqli_prepare($db_connection, "SELECT ministry_lastname, ministry_firstname FROM ministries_table WHERE ministry_id=? LIMIT 1")) {
    mysqli_stmt_bind_param($stN, 'i', $id);
    mysqli_stmt_execute($stN);
    $resN = mysqli_stmt_get_result($stN);
    if ($resN) $nameRow = mysqli_fetch_assoc($resN) ?: $nameRow;
    mysqli_stmt_close($stN);
  }

  $sql = "UPDATE ministries_table SET archive_status='Active' WHERE ministry_id=? LIMIT 1";
  if ($st = mysqli_prepare($db_connection, $sql)) {
    mysqli_stmt_bind_param($st, 'i', $id);
    $ok = mysqli_stmt_execute($st);
    $err= $ok ? null : mysqli_error($db_connection);
    mysqli_stmt_close($st);

    if ($ok) {
      $lname = $nameRow['ministry_lastname'] ?? '';
      $fname = $nameRow['ministry_firstname'] ?? '';
      $notes = "Unarchived Usher/Usherette member — ID {$id}" .
               (($lname || $fname) ? " (Name: " . trim($lname . ', ' . $fname, ', ') . ")" : '');
      $audit = audit_ministry_action(
        $db_connection,
        'UNARCHIVE',
        (string)$id,
        ['ministry_id'=>(string)$id, 'archive_status'=>'Active'],
        $notes
      );
      if (!$audit['ok']) error_log('[AUDIT][usher-unarchive]['.$audit['attempt'].'] '.$audit['msg']);
    }

    echo json_encode(['ok'=> (bool)$ok, 'msg'=> $ok ? 'Unarchived.' : ('DB error: '.$err)]); exit;
  } else {
    echo json_encode(['ok'=>false,'msg'=>'Prepare failed.']); exit;
  }
}
/* ===== END ADDITIONS ===== */

/* ===== DOMPDF DOWNLOAD (Approved list; Active fallback)
   Route: admin-ministry-usher.php?download=usher_pdf

   ✅ UPDATED:
   - Prepared by: FULL NAME of logged-in admin (from admin_table)
   - NO EMAIL shown
============================================================================= */
if (isset($_GET['download']) && $_GET['download'] === 'usher_pdf') {
  @mysqli_set_charset($db_connection, 'utf8mb4');
  @mysqli_query($db_connection, "SET collation_connection = 'utf8mb4_unicode_ci'");

  $rows = [];
  $sqlApproved = "
    SELECT date_join, ministry_position, ministry_lastname, ministry_firstname,
           ministry_middlename, ministry_extensionname
    FROM ministries_table
    WHERE ministry_type='Usher & Usherette' AND status='Approved'
    ORDER BY date_join DESC, ministry_lastname, ministry_firstname
  ";
  $res = @mysqli_query($db_connection, $sqlApproved);
  if ($res === false) {
    $sqlFallback = "
      SELECT date_join, ministry_position, ministry_lastname, ministry_firstname,
             ministry_middlename, ministry_extensionname
      FROM ministries_table
      WHERE ministry_type='Usher & Usherette' AND archive_status='Active'
      ORDER BY date_join DESC, ministry_lastname, ministry_firstname
    ";
    $res = mysqli_query($db_connection, $sqlFallback);
  }
  if ($res) { while($r = mysqli_fetch_assoc($res)) $rows[] = $r; }

  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $base   = $scheme . '://' . $_SERVER['HTTP_HOST'];
  $logo   = $base . '/HTCCC-SYSTEM/image/httc_main-logo.jpg';

  $pdfTitle  = "Usher & Usherette — Approved Members";
  $generated = date('M d, Y');

  /* ✅ Prepared By (FULL NAME) */
  $preparedBy = '';
  if ($__admin_id > 0) {
    if ($stPB = @mysqli_prepare($db_connection, "
      SELECT admin_firstname, admin_middlename, admin_lastname
      FROM admin_table
      WHERE admin_id=? LIMIT 1
    ")) {
      mysqli_stmt_bind_param($stPB, "i", $__admin_id);
      if (@mysqli_stmt_execute($stPB)) {
        $rsPB = mysqli_stmt_get_result($stPB);
        if ($rr = mysqli_fetch_assoc($rsPB)) {
          $fn  = trim((string)($rr['admin_firstname'] ?? ''));
          $mn  = trim((string)($rr['admin_middlename'] ?? ''));
          $ln  = trim((string)($rr['admin_lastname'] ?? ''));

          $firstMid = trim($fn . ($mn !== '' ? ' ' . $mn : ''));
          if ($ln !== '' && $firstMid !== '') {
            $preparedBy = trim($ln . ', ' . $firstMid);
          } elseif ($ln !== '') {
            $preparedBy = $ln;
          } else {
            $preparedBy = $firstMid;
          }
        }
      }
      mysqli_stmt_close($stPB);
    }
  }
  if ($preparedBy === '') $preparedBy = trim((string)($_SESSION['admin_name'] ?? ''));
  if ($preparedBy === '') $preparedBy = 'Administrator';

  ob_start(); ?>
  <!DOCTYPE html>
  <html>
  <head>
    <meta charset="utf-8">
    <title><?php echo h($pdfTitle); ?></title>
    <style>
      @page { margin: 18mm 14mm; }
      body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; color:#111827; font-size:12px; }
      .header { display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid #e5e7eb; padding-bottom:8px; margin-bottom:12px; }
      .brand { display:flex; gap:10px; align-items:center; }
      .brand img { width:48px; height:48px; border-radius:50%; }
      h1 { margin:0; font-size:18px; }
      .sub { color:#64748b; font-size:12px; line-height:1.35; }
      .meta-line { margin-top:2px; }
      table { width:100%; border-collapse:collapse; }
      thead th { background:#1f2a6b; color:#fff; padding:8px; text-align:left; font-weight:700; }
      tbody td { border-bottom:1px solid #e5e7eb; padding:8px; }
      .muted { color:#64748b; }
      .rightBox { text-align:right; }
    </style>
  </head>
  <body>
    <div class="header">
      <div class="brand">
        <img src="<?php echo h($logo); ?>" alt="Logo">
        <div>
          <h1><?php echo h($pdfTitle); ?></h1>
          <div class="sub">
            <div class="meta-line">Generated: <?php echo h($generated); ?></div>
            <div class="meta-line">Prepared by: <?php echo h($preparedBy); ?></div>
          </div>
        </div>
      </div>
      <div class="sub rightBox">HTCCC System</div>
    </div>

    <table>
      <thead>
        <tr>
          <th style="width:18%;">Date Joined</th>
          <th style="width:18%;">Position</th>
          <th style="width:16%;">Lastname</th>
          <th style="width:16%;">Firstname</th>
          <th style="width:16%;">Middlename</th>
          <th style="width:16%;">Extension</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="muted" style="text-align:center;">No approved members found.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?php echo h($r['date_join'] ? date('M d, Y', strtotime($r['date_join'])) : '—'); ?></td>
            <td><?php echo h($r['ministry_position'] ?? ''); ?></td>
            <td><?php echo h($r['ministry_lastname'] ?? ''); ?></td>
            <td><?php echo h($r['ministry_firstname'] ?? ''); ?></td>
            <td><?php echo h($r['ministry_middlename'] ?? ''); ?></td>
            <td><?php echo h($r['ministry_extensionname'] ?? ''); ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </body>
  </html>
  <?php
  $html = ob_get_clean();

  require __DIR__ . '/vendor/autoload.php';
  $options = new \Dompdf\Options();
  $options->set('isRemoteEnabled', true);
  $options->set('defaultFont', 'DejaVu Sans');

  $dompdf = new \Dompdf\Dompdf($options);
  $dompdf->loadHtml($html);
  $dompdf->setPaper('A4', 'portrait');
  $dompdf->render();

  $fname = 'Usher_Usherette_Approved_' . date('Y-m-d_His') . '.pdf';
  $dompdf->stream($fname, ['Attachment' => true]);
  exit;
}
/* ===== END DOMPDF DOWNLOAD ADDITION ===== */
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta content="width=device-width, initial-scale=1"/>
  <title>Admin – Usher & Usherette Ministry</title>

  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/admin-ministry-usher.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/admin-ministry-usher.css'); ?>">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: none; align-items: center; justify-content: center; z-index: 1000; }
    .modal { background: #fff; width: min(680px, 92vw); border-radius: 14px; padding: 20px; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
    .modal header { display:flex; align-items:center; justify-content:space-between; }
    .modal h3 { margin:0; font-size: 1.15rem; }
    .modal .close { background:transparent; border:0; font-size:1.2rem; cursor:pointer; }

    .grid-2 { display:grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .grid-3 { display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
    .field { display:flex; flex-direction:column; }
    .field label { font-size:.9rem; margin-bottom:6px; color:#19324e; }
    .field input, .field select { border:1px solid #e2e8f0; border-radius:10px; padding:10px 12px; outline:none; }
    .actions { display:flex; gap:10px; justify-content:flex-end; margin-top:14px; }
    .btn { border:0; border-radius:10px; padding:10px 14px; cursor:pointer; }
    .btn.primary { background:#6B5AE3; color:#fff; }
    .btn.ghost { background:#eef2ff; color:#1B1B4B; }
    button.inline-edit{background:#10b981;color:#fff;border:0;border-radius:8px;padding:6px 10px;margin-right:6px}
    mark.smart-hit{padding:0 2px;border-radius:3px;background:#fff3cd}
    @media (max-width:640px){ .grid-2,.grid-3{grid-template-columns:1fr;} }
  </style>

  <!-- ===== (OLD) SMART SORT UI CSS — keep but we’ll hide it ===== -->
  <style>
    .sort-pop { display:none !important; }
  </style>

  <!-- ===== SORT PANEL V2 CSS ===== -->
  <style>
    .sort2-pop {
      position: fixed; z-index: 1600; min-width: 320px; max-width: 96vw;
      background:#fff; border:1px solid #e5e7eb; border-radius:16px;
      box-shadow:0 18px 55px rgba(0,0,0,.18); padding:14px;
      display:none;
    }
    .sort2-pop.open{ display:block; }
    .sort2-pop h4{ margin:2px 0 10px; font-size:1rem; color:#0f172a; }
    .sort2-row{ display:flex; gap:10px; align-items:center; margin-bottom:10px; }
    .sort2-row select{
      flex:1 1 auto; border:1px solid #e2e8f0; border-radius:12px; padding:10px 12px;
      background:#fff; outline:none; font-size:.92rem;
    }
    .sort2-btn {
      flex:0 0 auto; padding:8px 12px; border-radius:12px; border:1px solid #e2e8f0;
      background:#f8fafc; cursor:pointer; font-size:.9rem;
    }
    .sort2-btn.active { background:#eef2ff; color:#1B1B4B; border-color:#e2e8f0; }
    .sort2-btn[disabled]{ opacity:.5; cursor:not-allowed; }
    .sort2-actions{ display:flex; justify-content:flex-end; gap:10px; margin-top:6px; }
    .sort2-actions .btn{ border:0; border-radius:12px; padding:9px 14px; }
    .sort2-actions .btn.ghost{ background:#eef2ff; color:#1B1B4B; }
    .sort2-actions .btn.primary{ background:#6B5AE3; color:#fff; }
  </style>
</head>
<body>

<aside class="sidebar">
  <div class="brand">
    <img src="image/httc_main-logo.jpg" alt="" />
    <span>HTCCC SYSTEM</span>
  </div>

  <div class="user-card">
    <img src="css/image/profile.png" alt="user">
    <div>
      <div class="user-title">Secretary</div>
      <div class="user-sub">Dashboard</div>
    </div>
  </div>

  <nav class="nav">
    <div class="section-title">Main</div>
    <a class="navlink" href="secretary_dashboard.php"><i class="fas fa-home"></i>Dashboard</a>

    <div class="section-title">Online Requests</div>
    <a class="navlink" href="admin-schedule-request.php">
      <i class="fas fa-calendar-plus"></i>Schedule Requests
    </a>
    <a class="navlink" href="admin-prayer-request.php">
      <i class="fas fa-praying-hands"></i><span>Prayer Requests</span>
    </a>

    <div class="section-title">Online Applications</div>
    <a class="navlink" href="">
      <i class="fas fa-water"></i>Baptismal Applications
    </a>
    <a class="navlink" href="admin-application.php">
      <i class="fas fa-user-cog"></i>Baptismal Account Verification
    </a>
    <a class="navlink" href="application_ministry.php">
      <i class="fas fa-users"></i>Ministry Applications
    </a>

    <div class="section-title">Schedule</div>
    <a class="navlink" href="appointment-schedule.php">
      <i class="fas fa-calendar-check"></i>Service Schedule
    </a>

    <div class="section-title">All Done Services</div>
    <a class="navlink" href="done-service-wedding.php">
      <i class="fas fa-ring"></i>Wedding Service
    </a>
    <a class="navlink" href="done-service-dedication.php">
      <i class="fas fa-baby"></i>Child Dedication
    </a>
    <a class="navlink" href="done-service-funeral.php">
      <i class="fas fa-cross"></i>Funeral Service
    </a>
    <a class="navlink" href="done-service-house.php">
      <i class="fas fa-home"></i>House Blessing
    </a>
    <a class="navlink" href="done-service-baptism.php">
      <i class="fas fa-tint"></i>Water Baptism
    </a>

    <div class="section-title">Streaming</div>
    <a class="navlink" href="admin-multimedia.php">
      <i class="fas fa-broadcast-tower"></i>Streaming
    </a>

    <div class="section-title">Ministry Management</div>
    <a class="navlink" href="admin-ministry-women.php">
      <i class="fas fa-female"></i>Handmaid's of the Lord
    </a>
    <a class="navlink" href="admin-ministry-men.php">
      <i class="fas fa-male"></i>Men's Ministry
    </a>
    <a class="navlink" href="admin-ministry-music.php">
      <i class="fas fa-music"></i>Music Ministry
    </a>
    <a class="navlink active" href="admin-ministry-usher.php">
      <i class="fas fa-hands-helping"></i>Usher &amp; Usherette
    </a>
    <a class="navlink" href="admin-ministry-junior.php">
      <i class="fas fa-child"></i>Junior Christ Ambassador
    </a>

    <div class="section-title">Reports</div>
    <a class="navlink" href="admin-reports.php">
      <i class="fas fa-file-alt"></i>Reports
    </a>

    <div class="section-title">Content</div>
    <a class="navlink" href="content-management_home-page.php">
      <i class="fas fa-edit"></i>Content Management
    </a>

    <div class="section-title">Certificates</div>
    <a class="navlink" href="certificate-table.php">
      <i class="fas fa-award"></i>Generate Certificate
    </a>

    <div class="section-title">Account</div>
    <a class="navlink" href="admin-account-settings.php">
      <i class="fas fa-user-shield"></i>Account Settings
    </a>

    <div class="section-title">More</div>
    <a class="navlink logout" href="all_log-in.php">
      <i class="fas fa-sign-out-alt"></i>Log Out
    </a>
  </nav>
</aside>

<!-- ============== MAIN PAGE CONTENT ============== -->
<div class="page">
  <header class="topbar">
    <h1>Usher & Usherette Ministry</h1>
  </header>

  <div class="container">
    <div class="top-bar">
      <input type="text" placeholder="🔍 Search" class="search-box" aria-label="Search">
      <div class="btn-group">
        <button class="sort-button" id="openAddModal" type="button">Add +</button>
        <button class="sort-button sky" type="button">Sort by:</button>

        <button class="sort-button" type="button" onclick="window.location='admin-ministry-usher.php?download=usher_pdf'">
          Download PDF
        </button>

        <button class="sort-button" id="openArchivedModal" type="button">
          Archived List
        </button>
      </div>
    </div>

    <table>
      <thead>
        <tr>
          <th>Date Joined</th>
          <th>Position</th>
          <th>Lastname</th>
          <th>Firstname</th>
          <th>Middlename</th>
          <th>Extension</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $query = "SELECT ministry_id, date_join, ministry_position, ministry_lastname, ministry_firstname, ministry_middlename, ministry_extensionname
                  FROM ministries_table
                  WHERE ministry_type = 'Usher & Usherette' AND archive_status='Active'
                  ORDER BY date_join DESC";
        $result = mysqli_query($db_connection, $query);

        if ($result && mysqli_num_rows($result) > 0) {
          while ($row = mysqli_fetch_assoc($result)) {
            $date_disp = $row['date_join'] ? date('F j, Y', strtotime($row['date_join'])) : '';
            echo '<tr>';
            echo '<td>' . htmlspecialchars($date_disp) . '</td>';
            echo '<td>' . htmlspecialchars($row['ministry_position']) . '</td>';
            echo '<td>' . htmlspecialchars($row['ministry_lastname']) . '</td>';
            echo '<td>' . htmlspecialchars($row['ministry_firstname']) . '</td>';
            echo '<td>' . htmlspecialchars($row['ministry_middlename']) . '</td>';
            echo '<td>' . htmlspecialchars($row['ministry_extensionname']) . '</td>';
            echo '<td>
                    <button type="button" class="inline-edit js-edit-row" data-mid="'.(int)$row['ministry_id'].'" title="Edit this record">
                      <i class="fas fa-pen"></i> Edit
                    </button>
                    <button class="decline" type="button" disabled title="Coming soon">Archive</button>
                  </td>';
            echo '</tr>';
          }
        } else {
          echo '<tr><td colspan="7">No data found.</td></tr>';
        }
        ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ============== ADD MODAL ============== -->
<div class="modal-backdrop" id="addModal" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="addModalTitle">
    <header>
      <h3 id="addModalTitle">Add Usher/Usherette Member</h3>
      <button class="close" id="closeAdd" type="button" aria-label="Close">&times;</button>
    </header>

    <form method="post" id="addForm" autocomplete="off">
      <input type="hidden" name="__action" value="add_ministry">

      <div class="grid-2">
        <div class="field">
          <label for="date_join">Date Joined</label>
          <input type="date" id="date_join" name="date_join" required value="<?php echo htmlspecialchars($old['date_join']); ?>">
        </div>

        <!-- POSITION DROPDOWN + OTHER -->
        <div class="field">
          <label for="ministry_position_select">Ministry Position</label>

          <input type="hidden" id="ministry_position" name="ministry_position" value="<?php echo htmlspecialchars($old['ministry_position']); ?>">

          <?php
            $old_pos   = $old['ministry_position'];
            $is_known  = in_array($old_pos, $POSITION_OPTIONS, true);
            $selectVal = $is_known ? $old_pos : '__other__';
            $otherVal  = $is_known ? '' : $old_pos;
          ?>
          <select id="ministry_position_select">
            <option value="" disabled <?php echo $selectVal==='' ? 'selected' : '' ?>>-- Select position --</option>
            <?php foreach($POSITION_OPTIONS as $opt): ?>
              <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $selectVal===$opt ? 'selected' : '' ?>>
                <?php echo htmlspecialchars($opt); ?>
              </option>
            <?php endforeach; ?>
            <option value="__other__" <?php echo $selectVal==='__other__' ? 'selected' : '' ?>>Other…</option>
          </select>

          <input type="text"
                 id="ministry_position_other"
                 placeholder="Type the position"
                 style="margin-top:8px; display: <?php echo $selectVal==='__other__' ? 'block' : 'none'; ?>;"
                 value="<?php echo htmlspecialchars($otherVal); ?>">
        </div>
      </div>

      <div class="grid-3" style="margin-top:12px;">
        <div class="field">
          <label for="ministry_lastname">Lastname</label>
          <input type="text" id="ministry_lastname" name="ministry_lastname" required value="<?php echo htmlspecialchars($old['ministry_lastname']); ?>">
        </div>
        <div class="field">
          <label for="ministry_firstname">Firstname</label>
          <input type="text" id="ministry_firstname" name="ministry_firstname" required value="<?php echo htmlspecialchars($old['ministry_firstname']); ?>">
        </div>
        <div class="field">
          <label for="ministry_middlename">Middlename</label>
          <input type="text" id="ministry_middlename" name="ministry_middlename" value="<?php echo htmlspecialchars($old['ministry_middlename']); ?>">
        </div>
      </div>

      <div class="grid-2" style="margin-top:12px;">
        <div class="field">
          <label for="ministry_extensionname">Extension</label>
          <input type="text" id="ministry_extensionname" name="ministry_extensionname" value="<?php echo htmlspecialchars($old['ministry_extensionname']); ?>">
        </div>
        <div class="field">
          <label>&nbsp;</label><small>Saved under <b>Usher &amp; Usherette</b></small>
        </div>
      </div>

      <div class="actions">
        <button type="button" class="btn ghost" id="cancelAdd">Cancel</button>
        <button type="button" class="btn primary" id="confirmAdd">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- ============== EDIT MODAL (ADDED) ============== -->
<div class="modal-backdrop" id="editModal" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
    <header>
      <h3 id="editModalTitle">Edit Usher/Usherette Member</h3>
      <button class="close" id="closeEdit" type="button" aria-label="Close">&times;</button>
    </header>

    <form id="editForm" autocomplete="off">
      <input type="hidden" name="__action" value="edit_ministry_usher">
      <input type="hidden" name="ministry_id" id="edit_ministry_id">

      <div class="grid-2">
        <div class="field">
          <label for="edit_date_join">Date Joined</label>
          <input type="date" id="edit_date_join" name="date_join" required>
        </div>

        <div class="field">
          <label for="edit_ministry_position">Ministry Position</label>
          <input type="text" id="edit_ministry_position" name="ministry_position" required>
        </div>
      </div>

      <div class="grid-3" style="margin-top:12px;">
        <div class="field">
          <label for="edit_ministry_lastname">Lastname</label>
          <input type="text" id="edit_ministry_lastname" name="ministry_lastname" required>
        </div>
        <div class="field">
          <label for="edit_ministry_firstname">Firstname</label>
          <input type="text" id="edit_ministry_firstname" name="ministry_firstname" required>
        </div>
        <div class="field">
          <label for="edit_ministry_middlename">Middlename</label>
          <input type="text" id="edit_ministry_middlename" name="ministry_middlename">
        </div>
      </div>

      <div class="grid-3" style="margin-top:12px;">
        <div class="field">
          <label for="edit_ministry_extensionname">Extension</label>
          <input type="text" id="edit_ministry_extensionname" name="ministry_extensionname">
        </div>
        <div class="field">
          <label for="edit_sex">Sex</label>
          <select id="edit_sex" name="sex">
            <option value="">—</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
          </select>
        </div>
        <div class="field">
          <label for="edit_birthday">Birthday</label>
          <input type="date" id="edit_birthday" name="birthday">
        </div>
      </div>

      <div class="grid-2" style="margin-top:12px;">
        <div class="field">
          <label for="edit_ministry_email">Email</label>
          <input type="email" id="edit_ministry_email" name="ministry_email">
        </div>
        <div class="field">
          <label>&nbsp;</label><small>Only the selected row will be updated.</small>
        </div>
      </div>

      <div class="actions">
        <button type="button" class="btn ghost" id="cancelEdit">Cancel</button>
        <button type="submit" class="btn primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ============== ARCHIVED LIST MODAL (NEW) ============== -->
<div class="modal-backdrop" id="archivedModal" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="archivedModalTitle">
    <header>
      <h3 id="archivedModalTitle">Archived Usher &amp; Usherette Members</h3>
      <button class="close" id="closeArchived" type="button" aria-label="Close">&times;</button>
    </header>
    <div class="modal-body">
      <table style="width:100%; border-collapse:collapse;">
        <thead>
          <tr>
            <th>Date Joined</th>
            <th>Position</th>
            <th>Lastname</th>
            <th>Firstname</th>
            <th>Middlename</th>
            <th>Extension</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $archivedQuery = "SELECT ministry_id, date_join, ministry_position, ministry_lastname, ministry_firstname, ministry_middlename, ministry_extensionname
                            FROM ministries_table
                            WHERE ministry_type='Usher & Usherette' AND archive_status='Archived'
                            ORDER BY date_join DESC, ministry_lastname, ministry_firstname";
          $archRes = mysqli_query($db_connection, $archivedQuery);
          if ($archRes && mysqli_num_rows($archRes) > 0) {
            while ($ar = mysqli_fetch_assoc($archRes)) {
              $adate = $ar['date_join'] ? date('F j, Y', strtotime($ar['date_join'])) : '';
              $amid  = (int)$ar['ministry_id'];
              ?>
              <tr>
                <td><?= h($adate) ?></td>
                <td><?= h($ar['ministry_position']) ?></td>
                <td><?= h($ar['ministry_lastname']) ?></td>
                <td><?= h($ar['ministry_firstname']) ?></td>
                <td><?= h($ar['ministry_middlename']) ?></td>
                <td><?= h($ar['ministry_extensionname']) ?></td>
                <td>
                  <button type="button" class="inline-edit js-unarchive-row" data-mid="<?= $amid ?>">
                    <i class="fas fa-undo"></i> Unarchive
                  </button>
                </td>
              </tr>
              <?php
            }
          } else {
            echo "<tr><td colspan='7' style='text-align:center;'>No archived members found.</td></tr>";
          }
          ?>
        </tbody>
      </table>
    </div>
    <div class="actions">
      <button type="button" class="btn ghost" id="closeArchivedFooter">Close</button>
    </div>
  </div>
</div>

<script>
/* ---------- Modal Controls ---------- */
const modal    = document.getElementById('addModal');
const openBtn  = document.getElementById('openAddModal');
const closeBtn = document.getElementById('closeAdd');
const cancelBtn= document.getElementById('cancelAdd');
const form     = document.getElementById('addForm');

function showModal(){ modal.style.display='flex'; modal.setAttribute('aria-hidden','false'); }
function hideModal(){ modal.style.display='none'; modal.setAttribute('aria-hidden','true'); }

openBtn && (openBtn.onclick = showModal);
closeBtn && (closeBtn.onclick = hideModal);
cancelBtn && (cancelBtn.onclick = hideModal);
modal && (modal.onclick = (e)=>{ if(e.target===modal) hideModal(); });

/* ---------- Archived Modal Controls ---------- */
const archivedModal      = document.getElementById('archivedModal');
const openArchivedModal  = document.getElementById('openArchivedModal');
const closeArchived      = document.getElementById('closeArchived');
const closeArchivedFooter= document.getElementById('closeArchivedFooter');

function showArchived(){ archivedModal.style.display='flex'; archivedModal.setAttribute('aria-hidden','false'); }
function hideArchived(){ archivedModal.style.display='none'; archivedModal.setAttribute('aria-hidden','true'); }

openArchivedModal?.addEventListener('click', showArchived);
closeArchived?.addEventListener('click', hideArchived);
closeArchivedFooter?.addEventListener('click', hideArchived);
archivedModal?.addEventListener('click', e => { if (e.target===archivedModal) hideArchived(); });

/* ---------- Position dropdown + Other handling ---------- */
const selectPos   = document.getElementById('ministry_position_select');
const otherPos    = document.getElementById('ministry_position_other');
const finalPosInp = document.getElementById('ministry_position');

function syncPositionValue(){
  const val = selectPos.value;
  if (val === '__other__') {
    otherPos.style.display = 'block';
    finalPosInp.value = otherPos.value.trim();
  } else {
    otherPos.style.display = 'none';
    finalPosInp.value = val;
  }
}
selectPos?.addEventListener('change', syncPositionValue);
otherPos?.addEventListener('input', syncPositionValue);
syncPositionValue();

/* ---------- SweetAlert Confirm Before Submit ---------- */
document.getElementById('confirmAdd')?.addEventListener('click', function(){
  syncPositionValue();
  if (!finalPosInp.value) {
    Swal.fire({ title: "Missing position", text: "Please select or type a ministry position.", icon: "warning" });
    if (selectPos.value === '__other__') otherPos.focus(); else selectPos.focus();
    return;
  }

  Swal.fire({
    title: "Confirm Add?",
    text: "Are you sure you want to add this new member?",
    icon: "question",
    showCancelButton: true,
    confirmButtonColor: "#6B5AE3",
    cancelButtonColor: "#d33",
    confirmButtonText: "Yes, Add"
  }).then((result) => {
    if(result.isConfirmed){
      form.submit();
    }
  });
});

/* ---------- After server processing: success/error alerts ---------- */
<?php if ($add_success): ?>
Swal.fire({
  title: "Added Successfully!",
  text: "New member has been added to Usher & Usherette.",
  icon: "success",
  confirmButtonColor: "#6B5AE3"
}).then(()=>{ window.location = 'admin-ministry-usher.php'; });
<?php elseif ($error_msg): ?>
showModal();
Swal.fire({
  title: "Error",
  text: "<?php echo addslashes($error_msg); ?>",
  icon: "error",
  confirmButtonColor: "#6B5AE3"
});
<?php endif; ?>
</script>

<!-- ==========================================================
     ADDED: EDIT FEATURE (buttons + modal + ajax) for USHER/USHERETTE
========================================================== -->
<script>
(function(){
  const editBackdrop = document.getElementById('editModal');
  const closeEdit    = document.getElementById('closeEdit');
  const cancelEdit   = document.getElementById('cancelEdit');
  const editForm     = document.getElementById('editForm');

  function openEdit(){ editBackdrop.style.display='flex'; editBackdrop.setAttribute('aria-hidden','false'); }
  function closeEditFn(){ editBackdrop.style.display='none'; editBackdrop.setAttribute('aria-hidden','true'); }

  closeEdit && closeEdit.addEventListener('click', closeEditFn);
  cancelEdit && cancelEdit.addEventListener('click', closeEditFn);
  editBackdrop && editBackdrop.addEventListener('click', (e)=>{ if(e.target===editBackdrop) closeEditFn(); });

  function normalizeDateOnly(d){
    if (!d) return '';
    if (/^\d{4}-\d{2}-\d{2}/.test(d)) return d.substring(0,10);
    const ts = Date.parse(d);
    if (!isNaN(ts)) return new Date(ts).toISOString().substring(0,10);
    return '';
  }

  function findRecordMatch(dbrows, tLast, tFirst, tDate){
    const d = normalizeDateOnly(tDate);
    return dbrows.find(r=>{
      const dbd = normalizeDateOnly(r.date_join || '');
      return (String(r.ministry_lastname||'').trim()===tLast &&
              String(r.ministry_firstname||'').trim()===tFirst &&
              dbd===d);
    });
  }

  let USHER_DB_ROWS = null;
  async function ensureDbRows(){
    if (USHER_DB_ROWS) return USHER_DB_ROWS;
    const res = await fetch('admin-ministry-usher.php?ajax=usher_rows', { headers: { 'X-Requested-With':'XMLHttpRequest' }});
    const j = await res.json();
    USHER_DB_ROWS = (j && j.ok) ? j.rows : [];
    return USHER_DB_ROWS;
  }

  async function attachEditors(){
    const tbody = document.querySelector('.container table tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.rows);
    const dbrows = await ensureDbRows();

    rows.forEach(tr=>{
      const btn = tr.querySelector('.js-edit-row');
      if (!btn) return;

      btn.addEventListener('click', async ()=>{
        let id = btn.getAttribute('data-mid');
        if (!id) {
          const tDate  = (tr.cells[0]?.innerText || '').trim();
          const tLast  = (tr.cells[2]?.innerText || '').trim();
          const tFirst = (tr.cells[3]?.innerText || '').trim();
          const match = findRecordMatch(dbrows, tLast, tFirst, tDate);
          if (match) id = match.ministry_id;
        }

        if (!id) {
          Swal.fire('Record ID not found', 'Could not locate the record for editing.', 'warning');
          return;
        }

        try{
          const res = await fetch('admin-ministry-usher.php?ajax=usher_row&id=' + encodeURIComponent(id));
          const j = await res.json();
          if (!j || !j.ok || !j.row) throw new Error('Row not found');

          document.getElementById('edit_ministry_id').value            = j.row.ministry_id;
          document.getElementById('edit_date_join').value              = normalizeDateOnly(j.row.date_join || '');
          document.getElementById('edit_ministry_position').value      = j.row.ministry_position || '';
          document.getElementById('edit_ministry_lastname').value      = j.row.ministry_lastname || '';
          document.getElementById('edit_ministry_firstname').value     = j.row.ministry_firstname || '';
          document.getElementById('edit_ministry_middlename').value    = j.row.ministry_middlename || '';
          document.getElementById('edit_ministry_extensionname').value = j.row.ministry_extensionname || '';
          document.getElementById('edit_sex').value                    = j.row.sex || '';
          document.getElementById('edit_birthday').value               = normalizeDateOnly(j.row.birthday || '');
          document.getElementById('edit_ministry_email').value         = j.row.ministry_email || '';

          openEdit();
        }catch(e){
          Swal.fire('Error','Unable to load selected record.','error');
        }
      });
    });
  }

  editForm?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const fd = new FormData(editForm);
    Swal.fire({ title:'Saving...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });

    try{
      const res = await fetch('admin-ministry-usher.php', { method:'POST', body: fd });
      const j = await res.json();
      if (j && j.ok) {
        Swal.fire({icon:'success',title:'Updated',timer:1100,showConfirmButton:false})
          .then(()=>{ window.location.reload(); });
      } else {
        Swal.fire('Error', (j&&j.msg)||'Update failed', 'error');
      }
    }catch(err){
      Swal.fire('Network error','Please try again.','error');
    }
  });

  window.addEventListener('load', attachEditors);
})();
</script>

<!-- ==========================================================
     ADDED: SMART SEARCH (fuzzy, multi-token, initials)
========================================================== -->
<script>
(function(){
  const input = document.querySelector('.search-box');
  const table = document.querySelector('.container table');
  if (!input || !table) return;

  const tbody = table.tBodies[0];
  const rows  = Array.from(tbody?.rows || []);
  const DATA  = [];

  rows.forEach(tr=>{
    const orig = [];
    for(let i=0;i<Math.min(6,tr.cells.length);i++){ orig.push(tr.cells[i].innerHTML); }
    DATA.push({tr, orig});
  });

  const debounce = (fn, d=160)=>{ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), d); }; };
  const norm = (s)=> (s||'').toString().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/\s+/g,' ').trim();

  function levenshtein(a,b){
    a=norm(a); b=norm(b);
    const m=a.length,n=b.length; if(!m) return n; if(!n) return m;
    const dp=new Array(n+1); for(let j=0;j<=n;j++) dp[j]=j;
    for(let i=1;i<=m;i++){
      let prev=dp[0]; dp[0]=i;
      for(let j=1;j<=n;j++){
        const tmp=dp[j];
        dp[j]=a[i-1]===b[j-1]?prev:1+Math.min(prev,dp[j-1],dp[j]);
        prev=tmp;
      }
    }
    return dp[n];
  }

  function tokenize(q){
    const out=[]; q.replace(/"([^"]+)"|(\S+)/g,(_,p1,p2)=>out.push(p1||p2));
    return out.map(norm).filter(Boolean);
  }

  function rowText(tr){
    const parts=[];
    for(let i=0;i<Math.min(6,tr.cells.length);i++){ parts.push(tr.cells[i].innerText); }
    return norm(parts.join(' | '));
  }

  function initialsFrom(tr){
    const ln = tr.cells[2]?.innerText || '';
    const fn = tr.cells[3]?.innerText || '';
    return norm((ln+' '+fn)).split(' ').map(w=>w[0]||'').join('');
  }

  const INDEX = rows.map(tr=>({ tr, text: rowText(tr), init: initialsFrom(tr) }));

  let noRow;
  function ensureNoRow(){
    if (noRow) return noRow;
    noRow = document.createElement('tr');
    const td = document.createElement('td');
    td.colSpan = table.tHead.rows[0].cells.length;
    td.textContent = 'No matching records.';
    td.style.textAlign='center'; td.style.opacity='0.75';
    noRow.appendChild(td);
    return noRow;
  }

  function clearHighlights(i){
    const {tr, orig} = DATA[i];
    for(let c=0;c<orig.length;c++){ tr.cells[c].innerHTML = orig[c]; }
  }

  function applyHighlights(i, tokens){
    const tr = DATA[i].tr;
    for(let c=0;c<Math.min(6,tr.cells.length);c++){
      const plain = tr.cells[c].textContent;
      let html = plain;
      tokens.forEach(tok=>{
        if (!tok) return;
        const re = new RegExp('('+tok.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','ig');
        html = html.replace(re,'<mark class="smart-hit">$1</mark>');
      });
      tr.cells[c].innerHTML = html;
    }
  }

  function matches(entry, tokens){
    if (!tokens.length) return true;
    return tokens.every(tok=>{
      if (!tok) return true;
      if (entry.text.includes(tok)) return true;
      if (entry.init && entry.init.startsWith(tok)) return true;
      if (tok.length>=4){
        const words = entry.text.split(/[^a-z0-9]+/);
        for(const w of words){
          if (w && Math.abs(w.length-tok.length)<=1 && levenshtein(w,tok)<=1) return true;
        }
      }
      return false;
    });
  }

  const run = debounce(()=>{
    const tokens = tokenize(input.value);
    let visible = 0;

    const tbody = table.tBodies[0];
    const rows = Array.from(tbody.rows);

    rows.forEach((tr,i)=>{
      clearHighlights(i);
      const ok = matches(INDEX[i], tokens);
      tr.style.display = ok ? '' : 'none';
      if (ok && tokens.length) applyHighlights(i, tokens);
      if (ok) visible++;
    });

    if (visible===0){
      ensureNoRow();
      if (!noRow.isConnected) tbody.appendChild(noRow);
    } else if (noRow && noRow.isConnected){
      noRow.remove();
    }
  }, 120);

  input.addEventListener('input', run);
  input.addEventListener('keydown', (e)=>{ if (e.key==='Escape'){ input.value=''; run(); }});
})();
</script>

<script>
/* Limit Usher & Usherette table to 4 rows (scrollable) */
(function(){
  const WRAP_CLASS = 'js-table-scroll-wrap-usher';

  function getMainTable(){
    const container = document.querySelector('.page .container');
    if (!container) return null;
    return container.querySelector('table');
  }

  function ensureWrapper(table){
    const p = table.parentElement;
    if (p && p.classList && p.classList.contains(WRAP_CLASS)) return p;
    const wrap = document.createElement('div');
    wrap.className = WRAP_CLASS;
    wrap.style.overflowY = 'auto';
    wrap.style.width = '100%';
    p.insertBefore(wrap, table);
    wrap.appendChild(table);
    return wrap;
  }

  function firstVisibleRow(tbody){
    const rows = tbody ? Array.from(tbody.rows) : [];
    return rows.find(r => r && r.offsetParent !== null) || rows[0] || null;
  }

  function setRowLimit(n){
    const table = getMainTable();
    if (!table) return;

    const wrap = ensureWrapper(table);
    const theadRow = table.tHead && table.tHead.rows[0] ? table.tHead.rows[0] : null;
    const tbody = table.tBodies && table.tBodies[0] ? table.tBodies[0] : null;
    if (!theadRow || !tbody) return;

    const headH = Math.ceil(theadRow.getBoundingClientRect().height || 44);
    const rowEl = firstVisibleRow(tbody);
    if (!rowEl) return;

    const rowH = Math.ceil(rowEl.getBoundingClientRect().height || 44);
    wrap.style.maxHeight = (headH + (rowH * n) + 2) + 'px';
  }

  function apply(){ setRowLimit(9); }

  window.addEventListener('load', apply);
  window.addEventListener('resize', apply);
  window.addEventListener('load', ()=>{
    setTimeout(apply, 120);
    setTimeout(apply, 300);
  });
})();
</script>

<!-- ============================== SORT PANEL V2 ============================== -->
<script>
(function(){
  const sortBtn = document.querySelector('.btn-group .sort-button.sky');
  const table = document.querySelector('.container table');
  if (!sortBtn || !table) return;
  const tbody = table.tBodies[0];
  if (!tbody) return;

  sortBtn.addEventListener('click', (e)=>{ e.preventDefault(); e.stopImmediatePropagation(); toggle(); }, true);

  const panel = document.createElement('div');
  panel.className = 'sort2-pop';
  panel.innerHTML = `
    <h4>Sort options</h4>
    <div class="sort2-row">
      <select id="sp2-primary">
        <option value="date">Date Joined</option>
        <option value="position">Position</option>
        <option value="lastname">Lastname</option>
        <option value="firstname">Firstname</option>
        <option value="middlename">Middlename</option>
        <option value="extension">Extension</option>
      </select>
      <button type="button" id="sp2-dir1" class="sort2-btn active" aria-pressed="true">Desc</button>
    </div>
    <div class="sort2-row">
      <select id="sp2-secondary">
        <option value="">(none)</option>
        <option value="date">Date Joined</option>
        <option value="position">Position</option>
        <option value="lastname">Lastname</option>
        <option value="firstname">Firstname</option>
        <option value="middlename">Middlename</option>
        <option value="extension">Extension</option>
      </select>
      <button type="button" id="sp2-dir2" class="sort2-btn" disabled>Asc</button>
    </div>
    <div class="sort2-actions">
      <button type="button" class="btn ghost" id="sp2-clear">Clear</button>
      <button type="button" class="btn primary" id="sp2-apply">Apply</button>
    </div>
  `;
  document.body.appendChild(panel);

  function place(){
    const r = sortBtn.getBoundingClientRect();
    const top = window.scrollY + r.bottom + 8;
    const left = Math.min(window.scrollX + r.left, window.scrollX + window.innerWidth - panel.offsetWidth - 12);
    panel.style.top = top + 'px';
    panel.style.left = left + 'px';
  }
  function open(){ panel.classList.add('open'); place(); bindDoc(); }
  function close(){ panel.classList.remove('open'); unbindDoc(); }
  function toggle(){ panel.classList.contains('open') ? close() : open(); }
  function onDocDown(e){ if (e.target===sortBtn) return; if (!panel.contains(e.target)) close(); }
  function bindDoc(){ document.addEventListener('mousedown', onDocDown, true); window.addEventListener('resize', place); window.addEventListener('scroll', place, true); }
  function unbindDoc(){ document.removeEventListener('mousedown', onDocDown, true); window.removeEventListener('resize', place); window.removeEventListener('scroll', place, true); }

  const originalOrder = Array.from(tbody.rows);
  const COLS = { date:0, position:1, lastname:2, firstname:3, middlename:4, extension:5 };

  function cell(tr, idx){ return (tr.cells[idx]?.innerText || '').trim(); }
  function parseDate(s){
    if (!s) return NaN;
    const iso = /^\d{4}-\d{2}-\d{2}/.test(s) ? s.substring(0,10) : s;
    const t = Date.parse(iso);
    return isNaN(t) ? NaN : t;
  }
  function blankLast(a,b){
    const A = (a==='' || a===null), B = (b==='' || b===null);
    if (A && !B) return 1;
    if (!A && B) return -1;
    return 0;
  }
  function cmpText(a,b,dir){
    const bl = blankLast(a,b); if (bl) return bl;
    const res = a.localeCompare(b, undefined, {sensitivity:'base', numeric:true});
    return dir==='asc' ? res : -res;
  }
  function cmpDate(a,b,dir){
    const bl = blankLast(a===''?'':a, b===''?'':b); if (bl) return bl;
    const ta=parseDate(a), tb=parseDate(b);
    if (isNaN(ta) && isNaN(tb)) return 0;
    if (isNaN(ta)) return 1;
    if (isNaN(tb)) return -1;
    const res = ta - tb;
    return dir==='asc' ? res : -res;
  }
  function compareBy(col, dir, A, B){
    const idx = COLS[col];
    const a = cell(A.tr, idx), b = cell(B.tr, idx);
    if (col==='date') return cmpDate(a,b,dir);
    return cmpText(a,b,dir);
  }

  function sortNow(primaryCol, primaryDir, secondaryCol, secondaryDir){
    const rows = Array.from(tbody.rows).map((tr,i)=>({tr,i}));
    rows.sort((A,B)=>{
      let r = compareBy(primaryCol, primaryDir, A, B);
      if (r===0 && secondaryCol){
        r = compareBy(secondaryCol, secondaryDir, A, B);
      }
      if (r===0) r = A.i - B.i;
      return r;
    });
    const frag=document.createDocumentFragment();
    rows.forEach(x=>frag.appendChild(x.tr));
    tbody.appendChild(frag);
  }

  function resetOrder(){
    const frag=document.createDocumentFragment();
    originalOrder.forEach(tr=>frag.appendChild(tr));
    tbody.appendChild(frag);
  }

  const sel1 = panel.querySelector('#sp2-primary');
  const sel2 = panel.querySelector('#sp2-secondary');
  const dir1 = panel.querySelector('#sp2-dir1');
  const dir2 = panel.querySelector('#sp2-dir2');
  const btnApply = panel.querySelector('#sp2-apply');
  const btnClear = panel.querySelector('#sp2-clear');

  let dir1State = 'desc';
  let dir2State = 'asc';

  function updateDirChips(){
    dir1.textContent = dir1State==='asc' ? 'Asc' : 'Desc';
    dir2.textContent = dir2State==='asc' ? 'Asc' : 'Desc';
    dir1.classList.toggle('active', true);
    const hasSecondary = !!sel2.value;
    dir2.disabled = !hasSecondary;
    dir2.classList.toggle('active', hasSecondary);
  }

  dir1.addEventListener('click', ()=>{
    dir1State = (dir1State==='asc') ? 'desc' : 'asc';
    updateDirChips();
  });
  dir2.addEventListener('click', ()=>{
    if (dir2.disabled) return;
    dir2State = (dir2State==='asc') ? 'desc' : 'asc';
    updateDirChips();
  });
  sel2.addEventListener('change', updateDirChips);

  btnApply.addEventListener('click', ()=>{
    const pCol = sel1.value || 'date';
    const sCol = sel2.value || '';
    sortNow(pCol, dir1State, sCol, dir2State);
    close();
  });

  btnClear.addEventListener('click', ()=>{
    sel1.value = 'date'; dir1State = 'desc';
    sel2.value = ''; dir2State = 'asc';
    updateDirChips();
    resetOrder();
    close();
  });

  function onKey(e){ if (e.key==='Escape' && panel.classList.contains('open')) close(); }
  document.addEventListener('keydown', onKey);
  function onScroll(){ if (panel.classList.contains('open')) place(); }
  window.addEventListener('scroll', onScroll, true);
  window.addEventListener('resize', onScroll);

  updateDirChips();
})();
</script>

<!-- ===== ENABLE + WIRE ARCHIVE BUTTONS ===== -->
<script>
(function(){
  function enableArchiveButtons(){
    document.querySelectorAll('button.decline[disabled]').forEach(btn=>{
      btn.disabled = false;
      btn.title = 'Archive this record';
      btn.addEventListener('click', async ()=>{
        const tr = btn.closest('tr');
        const idBtn = tr?.querySelector('.js-edit-row');
        const id = idBtn?.getAttribute('data-mid') || null;
        if (!id) {
          Swal.fire('Missing ID','Cannot locate record id for archiving.','warning');
          return;
        }

        const confirm = await Swal.fire({
          title: 'Archive this member?',
          text: 'This will set the record to Archived.',
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: '#6B5AE3',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Yes, Archive'
        });
        if (!confirm.isConfirmed) return;

        try{
          const fd = new FormData();
          fd.append('__action', 'archive_ministry');
          fd.append('ministry_id', id);

          const res = await fetch('admin-ministry-usher.php', { method:'POST', body: fd });
          const j = await res.json();
          if (j && j.ok) {
            Swal.fire({ icon:'success', title:'Archived', timer:1200, showConfirmButton:false })
              .then(()=> window.location.reload());
          } else {
            Swal.fire('Error', (j && j.msg) || 'Archive failed', 'error');
          }
        }catch(e){
          Swal.fire('Network error','Please try again.','error');
        }
      }, { once:false });
    });
  }
  window.addEventListener('load', enableArchiveButtons);
})();
</script>

<!-- ===== UNARCHIVE BUTTONS INSIDE ARCHIVED MODAL ===== -->
<script>
(function(){
  function bindUnarchiveButtons(){
    document.querySelectorAll('.js-unarchive-row').forEach(btn=>{
      btn.addEventListener('click', async ()=>{
        const id = btn.dataset.mid;
        if (!id) {
          Swal.fire('Missing ID','Cannot locate record id for unarchiving.','warning');
          return;
        }

        const confirm = await Swal.fire({
          title: 'Unarchive this member?',
          text: 'This will move the record back to Active.',
          icon: 'question',
          showCancelButton: true,
          confirmButtonColor: '#2563eb',
          cancelButtonColor: '#aaa',
          confirmButtonText: 'Yes, Unarchive'
        });
        if (!confirm.isConfirmed) return;

        try{
          const fd = new FormData();
          fd.append('__action', 'unarchive_ministry');
          fd.append('ministry_id', id);

          const res = await fetch('admin-ministry-usher.php', { method:'POST', body: fd });
          const j = await res.json();
          if (j && j.ok) {
            Swal.fire({ icon:'success', title:'Unarchived', timer:1200, showConfirmButton:false })
              .then(()=> window.location.reload());
          } else {
            Swal.fire('Error', (j && j.msg) || 'Unarchive failed', 'error');
          }
        }catch(e){
          Swal.fire('Network error','Please try again.','error');
        }
      });
    });
  }
  window.addEventListener('load', bindUnarchiveButtons);
})();
</script>

</body>
</html>
