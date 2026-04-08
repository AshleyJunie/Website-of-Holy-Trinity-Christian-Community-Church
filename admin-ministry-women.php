<?php
/* =========================================================================
   HTCCC SYSTEM – Admin: Women’s Ministry
   - Add / Edit / Archive members
   - Audit trail with readable notes listing ONLY the fields that changed
   - Uses UUID() for audit_trail.txn_id
   - Relies on $_SESSION['admin_id'], ['admin_user'], ['admin_email']
   - DB connection via $db_connection (MySQLi)

   ✅ UPDATE (Per your request):
   - PDF "Prepared by" now shows the FULL NAME of the logged-in admin
   - PDF no longer shows email
   ====================================================================== */

include 'db-connection.php';
session_start();

/* -------------------------------------------
   Ensure admin basics in session (username/email)
------------------------------------------- */
$__admin_id = (int)($_SESSION['admin_id'] ?? 0);
if (empty($_SESSION['admin_user']) || empty($_SESSION['admin_email'])) {
  if ($__admin_id > 0 && ($stmt = @mysqli_prepare(
    $db_connection,
    "SELECT admin_username, admin_emailaddress FROM admin_table WHERE admin_id=? LIMIT 1"
  ))) {
    mysqli_stmt_bind_param($stmt, "i", $__admin_id);
    if (@mysqli_stmt_execute($stmt)) {
      $res = mysqli_stmt_get_result($stmt);
      if ($row = mysqli_fetch_assoc($res)) {
        if (!empty($row['admin_username']) && empty($_SESSION['admin_user'])) {
          $_SESSION['admin_user'] = (string)$row['admin_username'];
        }
        if (!empty($row['admin_emailaddress']) && empty($_SESSION['admin_email'])) {
          $_SESSION['admin_email'] = (string)$row['admin_emailaddress'];
        }
      }
    }
    mysqli_stmt_close($stmt);
  }
}

/* -------------------------------------------
   Helpers
------------------------------------------- */
function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/**
 * Build human-readable notes containing ONLY the fields that actually changed.
 * Example:
 *   "Updated Women Ministry member — Changed Ministry Position: Member → Treasurer; Changed Email: old@mail.com → new@mail.com"
 */
function build_changed_fields_notes(array $before, array $after): string {
  // Map column keys to nice labels for readability
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
  if (!$changes) return 'Updated Women Ministry member — No values changed.';
  return 'Updated Women Ministry member — ' . implode('; ', $changes);
}

/* -------------------------------------------
   AUDIT helper (3-level fallback)
   actions: INSERT | UPDATE | ARCHIVE | UNARCHIVE
   - $notes should be readable text; $detailsAfterArr for JSON payload
------------------------------------------- */
if (!function_exists('audit_ministry_action')) {
  function audit_ministry_action(
    mysqli $db,
    string $action,
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

    $form   = 'admin-ministry-women.php';
    $source = 'ministries_table';

    // Reasonable defaults if caller didn't provide notes
    if ($notes === '') {
      $notes = $action==='INSERT'   ? 'Added Women Ministry member'
            : ($action==='UPDATE'   ? 'Updated Women Ministry member'
            : ($action==='ARCHIVE'  ? 'Archived Women Ministry member'
            : ($action==='UNARCHIVE'? 'Unarchived Women Ministry member'
                                     : 'Women Ministry action')));
    }

    // Attempt #1: prepared with JSON
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
      // Attempt #2: retry once
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

    // Attempt #3: minimal raw insert (no JSON)
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

/* -------------------------------------------
   Add handler (server-rendered form submit)
------------------------------------------- */
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
$POSITION_OPTIONS = [
  "Coordinator","Assistant Coordinator","Secretary","Treasurer",
  "Worship Leader","Prayer Warrior","Event Coordinator",
  "Outreach Coordinator","Member"
];

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['__action']) && $_POST['__action'] === 'add_ministry') {

  $ministry_type = 'Women Ministry';
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
  } else if (!date_create_from_format('Y-m-d', $date_join)) {
    $error_msg = 'Invalid date format.';
  } else {
    $sql = "INSERT INTO ministries_table
            (ministry_type, ministry_position, ministry_lastname, ministry_firstname,
             ministry_middlename, ministry_extensionname, date_join, archive_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')";
    if ($stmt = mysqli_prepare($db_connection, $sql)) {
      mysqli_stmt_bind_param($stmt, "sssssss",
        $ministry_type, $position, $lastname, $firstname, $middlename, $extension, $date_join);
      if (mysqli_stmt_execute($stmt)) {
        $add_success = true;
        // Audit: INSERT
        $new_id = (string)mysqli_insert_id($db_connection);
        $audit_details = [
          'ministry_id' => $new_id,
          'ministry_type' => $ministry_type,
          'ministry_position' => $position,
          'ministry_lastname' => $lastname,
          'ministry_firstname' => $firstname,
          'ministry_middlename' => $middlename,
          'ministry_extensionname' => $extension,
          'date_join' => $date_join,
          'archive_status' => 'Active'
        ];
        // More descriptive “Added …” notes:
        $nameFmt = trim($lastname . ', ' . $firstname . ($middlename ? ' ' . $middlename : ''));
        $notes = "Added Women Ministry member — Name: {$nameFmt}; Ministry Position: {$position}; Date Joined: {$date_join}";
        $audit = audit_ministry_action($db_connection, 'INSERT', $new_id, $audit_details, $notes);
        if (!$audit['ok']) error_log('[AUDIT][women-insert]['.$audit['attempt'].'] '.$audit['msg']);
      } else {
        $error_msg = 'Database error while adding.';
      }
      mysqli_stmt_close($stmt);
    } else {
      $error_msg = 'Failed to prepare statement.';
    }
  }
}

/* -------------------------------------------
   AJAX: list rows (for mapping/debug)
------------------------------------------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'wm_rows') {
  header('Content-Type: application/json; charset=utf-8');
  $rows = [];
  $q = "
    SELECT ministry_id, ministry_position, ministry_lastname, ministry_firstname, ministry_middlename,
           ministry_extensionname, date_join, sex, birthday, ministry_email
    FROM ministries_table
    WHERE ministry_type='Women Ministry' AND archive_status='Active'
    ORDER BY date_join DESC, ministry_lastname, ministry_firstname
  ";
  if ($rs = mysqli_query($db_connection, $q)) {
    while ($r = mysqli_fetch_assoc($rs)) $rows[] = $r;
  }
  echo json_encode(['ok'=>true,'rows'=>$rows]);
  exit;
}

/* -------------------------------------------
   AJAX: one row by id (EDIT loader) — with debug
------------------------------------------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'wm_row' && isset($_GET['id'])) {
  header('Content-Type: application/json; charset=utf-8');
  $id = (int)$_GET['id'];
  error_log("[WM][wm_row] called id={$id}");
  $row = null;
  if ($id > 0 && ($st = mysqli_prepare($db_connection, "
      SELECT ministry_id, ministry_position, ministry_lastname, ministry_firstname, ministry_middlename,
             ministry_extensionname, date_join, sex, birthday, ministry_email
      FROM ministries_table
      WHERE ministry_id=? LIMIT 1
  "))) {
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);
  }
  if (!$row) error_log("[WM][wm_row] no row for id={$id}");
  echo json_encode(['ok'=> (bool)$row, 'row'=>$row]);
  exit;
}

/* -------------------------------------------
   AJAX: update (EDIT save) — with SPECIFIC changed fields in notes
------------------------------------------- */
if ($_SERVER['REQUEST_METHOD']==='POST'
    && isset($_POST['__action']) && $_POST['__action']==='edit_ministry') {

  header('Content-Type: application/json; charset=utf-8');

  $id         = isset($_POST['ministry_id']) ? (int)$_POST['ministry_id'] : 0;
  $position   = trim($_POST['ministry_position'] ?? '');
  $lastname   = trim($_POST['ministry_lastname'] ?? '');
  $firstname  = trim($_POST['ministry_firstname'] ?? '');
  $middlename = trim($_POST['ministry_middlename'] ?? '');
  $extension  = trim($_POST['ministry_extensionname'] ?? '');
  $date_join  = trim($_POST['date_join'] ?? '');
  $sex        = trim($_POST['sex'] ?? '');
  $birthday   = trim($_POST['birthday'] ?? '');
  $email      = trim($_POST['ministry_email'] ?? '');

  if (!$id || $position==='' || $lastname==='' || $firstname==='' || $date_join==='') {
    echo json_encode(['ok'=>false,'msg'=>'Please complete required fields.']); exit;
  }

  // Fetch OLD row BEFORE update to compute diff
  $oldRow = [];
  if ($st0 = mysqli_prepare($db_connection, "
        SELECT ministry_position, ministry_lastname, ministry_firstname, ministry_middlename,
               ministry_extensionname, date_join, sex, birthday, ministry_email
        FROM ministries_table WHERE ministry_id=? LIMIT 1")) {
    mysqli_stmt_bind_param($st0, 'i', $id);
    mysqli_stmt_execute($st0);
    $res0 = mysqli_stmt_get_result($st0);
    $oldRow = $res0 ? (mysqli_fetch_assoc($res0) ?: []) : [];
    mysqli_stmt_close($st0);
  }

  // Do the update
  $sql = "UPDATE ministries_table
          SET ministry_position=?, ministry_lastname=?, ministry_firstname=?, ministry_middlename=?,
              ministry_extensionname=?, date_join=?, sex=?, birthday=?, ministry_email=?
          WHERE ministry_id=? LIMIT 1";
  if ($st = mysqli_prepare($db_connection, $sql)) {
    mysqli_stmt_bind_param(
      $st, 'sssssssssi',
      $position, $lastname, $firstname, $middlename, $extension, $date_join, $sex, $birthday, $email, $id
    );
    $ok = mysqli_stmt_execute($st);
    $err= $ok ? null : mysqli_error($db_connection);
    mysqli_stmt_close($st);

    if ($ok) {
      // Build AFTER map + compute readable change-notes (ONLY changed fields)
      $newRow = [
        'ministry_position'      => $position,
        'ministry_lastname'      => $lastname,
        'ministry_firstname'     => $firstname,
        'ministry_middlename'    => $middlename,
        'ministry_extensionname' => $extension,
        'date_join'              => $date_join,
        'sex'                    => $sex,
        'birthday'               => $birthday,
        'ministry_email'         => $email
      ];
      $notes = build_changed_fields_notes($oldRow, $newRow);

      $audit_details = array_merge(['ministry_id'=>(string)$id], $newRow);
      $audit = audit_ministry_action($db_connection, 'UPDATE', (string)$id, $audit_details, $notes);
      if (!$audit['ok']) error_log('[AUDIT][women-update]['.$audit['attempt'].'] '.$audit['msg']);
    }
    echo json_encode(['ok'=> (bool)$ok, 'msg'=> $ok ? 'Updated.' : ('DB error: '.$err)]);
  } else {
    echo json_encode(['ok'=>false,'msg'=>'Prepare failed.']);
  }
  exit;
}

/* -------------------------------------------
   AJAX: archive (ARCHIVE button)
------------------------------------------- */
if ($_SERVER['REQUEST_METHOD']==='POST'
    && isset($_POST['__action']) && $_POST['__action']==='archive_ministry') {

  header('Content-Type: application/json; charset=utf-8');

  $id = (int)($_POST['ministry_id'] ?? 0);
  if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Missing id']); exit; }

  // Optional: fetch some name info for nicer archive notes
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
      $notes = "Archived Women Ministry member — ID {$id}" .
               (($lname || $fname) ? " (Name: " . trim($lname . ', ' . $fname, ', ') . ")" : '');
      $audit = audit_ministry_action(
        $db_connection,
        'ARCHIVE',
        (string)$id,
        ['ministry_id'=>(string)$id, 'archive_status'=>'Archived'],
        $notes
      );
      if (!$audit['ok']) error_log('[AUDIT][women-archive]['.$audit['attempt'].'] '.$audit['msg']);
    }

    echo json_encode(['ok'=> (bool)$ok, 'msg'=> $ok ? 'Archived.' : ('DB error: '.$err)]);
  } else {
    echo json_encode(['ok'=>false,'msg'=>'Prepare failed.']);
  }
  exit;
}

/* -------------------------------------------
   AJAX: UNARCHIVE (Unarchive button in Archived modal)
------------------------------------------- */
if ($_SERVER['REQUEST_METHOD']==='POST'
    && isset($_POST['__action']) && $_POST['__action']==='unarchive_ministry') {

  header('Content-Type: application/json; charset=utf-8');

  $id = (int)($_POST['ministry_id'] ?? 0);
  if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Missing id']); exit; }

  // Optional: fetch some name info for nicer unarchive notes
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
      $notes = "Unarchived Women Ministry member — ID {$id}" .
               (($lname || $fname) ? " (Name: " . trim($lname . ', ' . $fname, ', ') . ")" : '');
      $audit = audit_ministry_action(
        $db_connection,
        'UNARCHIVE',
        (string)$id,
        ['ministry_id'=>(string)$id, 'archive_status'=>'Active'],
        $notes
      );
      if (!$audit['ok']) error_log('[AUDIT][women-unarchive]['.$audit['attempt'].'] '.$audit['msg']);
    }

    echo json_encode(['ok'=> (bool)$ok, 'msg'=> $ok ? 'Unarchived.' : ('DB error: '.$err)]);
  } else {
    echo json_encode(['ok'=>false,'msg'=>'Prepare failed.']);
  }
  exit;
}

/* =============================================================================
   === DOMPDF DOWNLOAD (Approved-only with styled layout) ===
   Route: admin-ministry-women.php?download=wm_pdf

   ✅ UPDATED:
   - Prepared by: FULL NAME of logged-in admin (from admin_table)
   - NO EMAIL shown
   - ✅ REMOVED: admin_extensionname usage
   ========================================================================== */
if (isset($_GET['download']) && $_GET['download'] === 'wm_pdf') {
  // Charset/collation (avoid collation conflicts)
  @mysqli_set_charset($db_connection, 'utf8mb4');
  @mysqli_query($db_connection, "SET collation_connection = 'utf8mb4_unicode_ci'");

  // Try to fetch APPROVED; if 'status' column is missing, fallback to Active
  $rows = [];
  $sqlApproved = "
    SELECT date_join, ministry_position, ministry_lastname, ministry_firstname,
           ministry_middlename, ministry_extensionname
    FROM ministries_table
    WHERE ministry_type='Women Ministry' AND status='Approved'
    ORDER BY date_join DESC, ministry_lastname, ministry_firstname
  ";
  $res = @mysqli_query($db_connection, $sqlApproved);
  if ($res === false) {
    // fallback: no `status` column – use archive_status='Active'
    $sqlFallback = "
      SELECT date_join, ministry_position, ministry_lastname, ministry_firstname,
             ministry_middlename, ministry_extensionname
      FROM ministries_table
      WHERE ministry_type='Women Ministry' AND archive_status='Active'
      ORDER BY date_join DESC, ministry_lastname, ministry_firstname
    ";
    $res = mysqli_query($db_connection, $sqlFallback);
  }
  if ($res) {
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
  }

  // Build absolute logo URL
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $base   = $scheme . '://' . $_SERVER['HTTP_HOST'];
  $logo   = $base . '/HTCCC-SYSTEM/image/httc_main-logo.jpg';

  // Human title & date
  $pdfTitle  = "Women’s Ministry — Approved Members";
  $generated = date('M d, Y');

  /* ============================
     ✅ Prepared By (FULL NAME)
     ✅ REMOVED: admin_extensionname
     ============================ */
  $preparedBy = '';
  if ($__admin_id > 0) {
    if ($stPB = @mysqli_prepare($db_connection, "
      SELECT 
        admin_firstname,
        admin_middlename,
        admin_lastname
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

  // Final fallback (still no email)
  if ($preparedBy === '') {
    $preparedBy = trim((string)($_SESSION['admin_name'] ?? ''));
  }
  if ($preparedBy === '') {
    $preparedBy = 'Administrator';
  }

  // === HTML styled like your shared report ===
  ob_start();
  ?>
  <!DOCTYPE html>
  <html>
  <head>
    <meta charset="utf-8">
    <title><?php echo htmlspecialchars($pdfTitle); ?></title>
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
        <img src="<?php echo htmlspecialchars($logo); ?>" alt="Logo">
        <div>
          <h1><?php echo htmlspecialchars($pdfTitle); ?></h1>
          <div class="sub">
            <div class="meta-line">Generated: <?php echo htmlspecialchars($generated); ?></div>
            <div class="meta-line">Prepared by: <?php echo htmlspecialchars($preparedBy); ?></div>
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
          <tr><td colspan="6" style="text-align:center;" class="muted">No approved members found.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?php echo htmlspecialchars($r['date_join'] ? date('M d, Y', strtotime($r['date_join'])) : '—'); ?></td>
            <td><?php echo htmlspecialchars($r['ministry_position'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($r['ministry_lastname'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($r['ministry_firstname'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($r['ministry_middlename'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($r['ministry_extensionname'] ?? ''); ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </body>
  </html>
  <?php
  $html = ob_get_clean();

  // Render with Dompdf
  require __DIR__ . '/vendor/autoload.php';

  $options = new \Dompdf\Options();
  $options->set('isRemoteEnabled', true);
  $options->set('defaultFont', 'DejaVu Sans');

  $dompdf = new \Dompdf\Dompdf($options);
  $dompdf->loadHtml($html);
  $dompdf->setPaper('A4', 'portrait');
  $dompdf->render();

  $fname = 'Womens_Ministry_Approved_' . date('Y-m-d_His') . '.pdf';
  $dompdf->stream($fname, ['Attachment' => true]);
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1"/>
<title>Admin – Women’s Ministry</title>

<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link rel="stylesheet" href="/HTCCC-SYSTEM/css/admin-ministry-women.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/admin-ministry-women.css'); ?>">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
/* Minimal inline styles for modals & buttons that integrate with your CSS */
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: none; align-items: center; justify-content: center; z-index: 1000; }
.modal { background: #fff; width: min(780px, 96vw); border-radius: 14px; padding: 20px; box-shadow: 0 20px 50px rgba(0,0,0,.25); max-height: 90vh; overflow: hidden; display:flex; flex-direction:column; }
.modal header { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
.modal h3 { margin:0; font-size: 1.15rem; }
.modal .close { background:transparent; border:0; font-size:1.2rem; cursor:pointer; }
.modal .modal-body { flex:1 1 auto; overflow:auto; margin-top:4px; }
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
button.decline{background:#b91c1c;color:#fff;border:0;border-radius:8px;padding:6px 10px}
button.unarchive-btn{background:#2563eb;color:#fff;border:0;border-radius:8px;padding:6px 10px}
@media (max-width:640px){ .grid-2,.grid-3{grid-template-columns:1fr;} }

/* ====================== SMART SORT UI ====================== */
.sort-panel { position: fixed; right: 18px; top: 110px; width: min(360px, 92vw); background:#fff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 20px 40px rgba(0,0,0,.18); padding:14px; display:none; z-index:1100; }
.sort-panel.open { display:block; }
.sort-panel h4 { margin:0 0 8px 0; font-size:1rem; color:#19324e; }
.sort-row { display:grid; grid-template-columns: 1fr auto; gap:8px; align-items:center; margin-bottom:10px; }
.sort-row select { width:100%; border:1px solid #e2e8f0; border-radius:10px; padding:8px 10px; }
.sort-dir { border:1px solid #e2e8f0; border-radius:10px; padding:8px 10px; background:#f8fafc; cursor:pointer; }
.sort-actions{ display:flex; gap:8px; justify-content:flex-end; margin-top:6px; }
.sort-actions .apply{ background:#6B5AE3; color:#fff; border:0; border-radius:10px; padding:8px 12px; cursor:pointer; }
.sort-actions .clear{ background:#eef2ff; color:#1B1B4B; border:0; border-radius:10px; padding:8px 12px; cursor:pointer; }
th.sortable { cursor:pointer; position:relative; }
th.sortable::after{ content:''; position:absolute; right:8px; top:50%; transform:translateY(-50%); border:5px solid transparent; opacity:.4; }
th.sortable[data-sort="asc"]::after { border-bottom-color:#111827; margin-top:-3px; }
th.sortable[data-sort="desc"]::after{ border-top-color:#111827; margin-top:3px; }
/* ==================== END SMART SORT UI ==================== */
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
      <a class="navlink active" href="admin-ministry-women.php">
        <i class="fas fa-female"></i>Handmaid's of the Lord
      </a>
      <a class="navlink" href="admin-ministry-men.php">
        <i class="fas fa-male"></i>Men's Ministry
      </a>
      <a class="navlink" href="admin-ministry-music.php">
        <i class="fas fa-music"></i>Music Ministry
      </a>
      <a class="navlink" href="admin-ministry-usher.php">
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

<div class="page">
  <header class="topbar"><h1>Handmaid's of the Lord</h1></header>

  <div class="container">
    <div class="top-bar">
      <input type="text" placeholder="🔍 Search" class="search-box" aria-label="Search">
      <div class="btn-group">
        <button class="sort-button" id="openAddModal" type="button">Add +</button>
        <button class="sort-button sky" type="button">Sort by:</button>
        <!-- PDF Download button -->
        <button class="sort-button" type="button" onclick="window.location='admin-ministry-women.php?download=wm_pdf'">
          Download PDF
        </button>
        <!-- NEW: Archived List button (opens modal) -->
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
        // Active records
        $query = "SELECT ministry_id, date_join, ministry_position, ministry_lastname, ministry_firstname, ministry_middlename, ministry_extensionname
                  FROM ministries_table
                  WHERE ministry_type='Women Ministry' AND archive_status='Active'
                  ORDER BY date_join DESC, ministry_lastname, ministry_firstname";
        $res = mysqli_query($db_connection, $query);
        if ($res && mysqli_num_rows($res) > 0) {
          while ($r = mysqli_fetch_assoc($res)) {
            $date_disp = $r['date_join'] ? date('F j, Y', strtotime($r['date_join'])) : '';
            $mid = (int)$r['ministry_id'];
            ?>
            <tr>
              <td><?= h($date_disp) ?></td>
              <td><?= h($r['ministry_position']) ?></td>
              <td><?= h($r['ministry_lastname']) ?></td>
              <td><?= h($r['ministry_firstname']) ?></td>
              <td><?= h($r['ministry_middlename']) ?></td>
              <td><?= h($r['ministry_extensionname']) ?></td>
              <td>
                <button type="button" class="inline-edit js-edit-row" data-mid="<?= $mid ?>"><i class="fas fa-pen"></i> Edit</button>
                <button type="button" class="decline js-archive-row" data-mid="<?= $mid ?>">Archive</button>
              </td>
            </tr>
            <?php
          }
        } else {
          echo "<tr><td colspan='7'>No data found.</td></tr>";
        }
        ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ADD MODAL -->
<div class="modal-backdrop" id="addModal" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="addModalTitle">
    <header>
      <h3 id="addModalTitle">Add Women’s Ministry Member</h3>
      <button class="close" id="closeAdd" type="button" aria-label="Close">&times;</button>
    </header>

    <form method="post" id="addForm" autocomplete="off">
      <input type="hidden" name="__action" value="add_ministry">

      <div class="grid-2">
        <div class="field">
          <label for="date_join">Date Joined</label>
          <input type="date" id="date_join" name="date_join" required value="<?php echo h($old['date_join']); ?>">
        </div>

        <div class="field">
          <label for="ministry_position_select">Ministry Position</label>
          <input type="hidden" id="ministry_position" name="ministry_position" value="<?php echo h($old['ministry_position']); ?>">
          <?php
            $old_pos   = $old['ministry_position'];
            $is_known  = in_array($old_pos, $POSITION_OPTIONS, true);
            $selectVal = $is_known ? $old_pos : '__other__';
            $otherVal  = $is_known ? '' : $old_pos;
          ?>
          <select id="ministry_position_select">
            <option value="" disabled <?php echo $selectVal==='' ? 'selected' : '' ?>>-- Select position --</option>
            <?php foreach($POSITION_OPTIONS as $opt): ?>
              <option value="<?php echo h($opt); ?>" <?php echo $selectVal===$opt ? 'selected' : '' ?>>
                <?php echo h($opt); ?>
              </option>
            <?php endforeach; ?>
            <option value="__other__" <?php echo $selectVal==='__other__' ? 'selected' : '' ?>>Other…</option>
          </select>
          <input type="text" id="ministry_position_other" placeholder="Type the position" style="margin-top:8px; display: <?php echo $selectVal==='__other__' ? 'block' : 'none'; ?>;" value="<?php echo h($otherVal); ?>">
        </div>
      </div>

      <div class="grid-3" style="margin-top:12px;">
        <div class="field">
          <label for="ministry_lastname">Lastname</label>
          <input type="text" id="ministry_lastname" name="ministry_lastname" required value="<?php echo h($old['ministry_lastname']); ?>">
        </div>
        <div class="field">
          <label for="ministry_firstname">Firstname</label>
          <input type="text" id="ministry_firstname" name="ministry_firstname" required value="<?php echo h($old['ministry_firstname']); ?>">
        </div>
        <div class="field">
          <label for="ministry_middlename">Middlename</label>
          <input type="text" id="ministry_middlename" name="ministry_middlename" value="<?php echo h($old['ministry_middlename']); ?>">
        </div>
      </div>

      <div class="grid-2" style="margin-top:12px;">
        <div class="field">
          <label for="ministry_extensionname">Extension</label>
          <input type="text" id="ministry_extensionname" name="ministry_extensionname" value="<?php echo h($old['ministry_extensionname']); ?>">
        </div>
        <div class="field">
          <label>&nbsp;</label><small>Saved under <b>Women Ministry</b></small>
        </div>
      </div>

      <div class="actions">
        <button type="button" class="btn ghost" id="cancelAdd">Cancel</button>
        <button type="button" class="btn primary" id="confirmAdd">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-backdrop" id="editModal" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
    <header>
      <h3 id="editModalTitle">Edit Women’s Ministry Member</h3>
      <button class="close" id="closeEdit" type="button" aria-label="Close">&times;</button>
    </header>

    <form id="editForm" autocomplete="off">
      <input type="hidden" name="__action" value="edit_ministry">
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

<!-- ARCHIVED LIST MODAL -->
<div class="modal-backdrop" id="archivedModal" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="archivedModalTitle">
    <header>
      <h3 id="archivedModalTitle">Archived Women’s Ministry Members</h3>
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
                            WHERE ministry_type='Women Ministry' AND archive_status='Archived'
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
                  <button type="button" class="unarchive-btn js-unarchive-row" data-mid="<?= $amid ?>">
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
/* ----------------------------- Add modal controls ----------------------------- */
const addModal   = document.getElementById('addModal');
const openAdd    = document.getElementById('openAddModal');
const closeAdd   = document.getElementById('closeAdd');
const cancelAdd  = document.getElementById('cancelAdd');
const addForm    = document.getElementById('addForm');

function showAdd(){ addModal.style.display='flex'; addModal.setAttribute('aria-hidden','false'); }
function hideAdd(){ addModal.style.display='none'; addModal.setAttribute('aria-hidden','true'); }
openAdd?.addEventListener('click', showAdd);
closeAdd?.addEventListener('click', hideAdd);
cancelAdd?.addEventListener('click', hideAdd);
addModal?.addEventListener('click', e => { if (e.target===addModal) hideAdd(); });

/* ---------------- Archived modal controls ---------------- */
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

/* ---------------- Position select (Add) + “Other…” handling ------------------- */
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

/* -------------------------- Confirm Add (SweetAlert) -------------------------- */
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
  }).then(r => { if (r.isConfirmed) addForm.submit(); });
});

/* -------------------------- After Add: success/error -------------------------- */
<?php if ($add_success): ?>
Swal.fire({ title:"Added Successfully!", text:"New member has been added to Women’s Ministry.", icon:"success", confirmButtonColor:"#6B5AE3" })
  .then(()=>{ window.location = 'admin-ministry-women.php'; });
<?php elseif ($error_msg): ?>
showAdd();
Swal.fire({ title:"Error", text:"<?php echo addslashes($error_msg); ?>", icon:"error", confirmButtonColor:"#6B5AE3" });
<?php endif; ?>

/* ----------------------- Limit table height (scrollable) ---------------------- */
(function(){
  const WRAP_CLASS = 'js-table-scroll-wrap';
  function getTable(){ return document.querySelector('.page .container table'); }
  function ensureWrap(t){
    if (t.parentElement?.classList?.contains(WRAP_CLASS)) return t.parentElement;
    const w = document.createElement('div');
    w.className = WRAP_CLASS; w.style.overflowY='auto'; w.style.width='100%';
    t.parentElement.insertBefore(w, t); w.appendChild(t); return w;
  }
  function firstVisibleRow(tb){
    const rows = tb ? Array.from(tb.rows) : [];
    return rows.find(r => r && r.offsetParent !== null) || rows[0] || null;
  }
  function setLimit(n){
    const table = getTable(); if (!table) return;
    const wrap  = ensureWrap(table);
    const head  = table.tHead?.rows[0]; const body = table.tBodies[0];
    if (!head || !body) return;
    const headH = Math.ceil(head.getBoundingClientRect().height || 44);
    const rowEl = firstVisibleRow(body); if (!rowEl) return;
    const rowH  = Math.ceil(rowEl.getBoundingClientRect().height || 44);
    wrap.style.maxHeight = (headH + rowH*n + 2) + 'px';
  }
  function apply(){ setLimit(9); }
  window.addEventListener('load', apply);
  window.addEventListener('resize', apply);
  window.addEventListener('load', ()=>{ setTimeout(apply,120); setTimeout(apply,300); });
})();

/* ------------------------------ Search (client) ------------------------------- */
(function(){
  const sb = document.querySelector('.search-box');
  const tbody = document.querySelector('.container table tbody');
  if (!sb || !tbody) return;
  const rows = Array.from(tbody.rows);
  const norm = s => (s||'').toString().toLowerCase();
  sb.addEventListener('input', () => {
    const parts = norm(sb.value).split(/\s+/).filter(Boolean);
    rows.forEach(tr => {
      const text = norm(tr.innerText);
      tr.style.display = parts.every(p => text.includes(p)) ? '' : 'none';
    });
  });
})();

/* ----------------------- Edit + Archive + Unarchive wiring (AJAX) ------------------------ */
(function(){
  const editModal = document.getElementById('editModal');
  const closeEdit = document.getElementById('closeEdit');
  const cancelEdit= document.getElementById('cancelEdit');
  const editForm  = document.getElementById('editForm');

  function openEdit(){ editModal.style.display='flex'; editModal.setAttribute('aria-hidden','false'); }
  function hideEdit(){ editModal.style.display='none'; editModal.setAttribute('aria-hidden','true'); }
  closeEdit?.addEventListener('click', hideEdit);
  cancelEdit?.addEventListener('click', hideEdit);
  editModal?.addEventListener('click', e => { if (e.target===editModal) hideEdit(); });

  const normalizeDateOnly = d => {
    if (!d) return '';
    if (/^\d{4}-\d{2}-\d{2}/.test(d)) return d.substring(0,10);
    const t = Date.parse(d); if (isNaN(t)) return ''; return new Date(t).toISOString().substring(0,10);
  };

  async function fetchJSON(url) {
    const res = await fetch(url);
    const text = await res.text();
    console.log('AJAX', url, '→', text); // debug raw response for JSON issues
    let j; try { j = JSON.parse(text); } catch(e) { throw new Error('Bad JSON: ' + text); }
    return j;
  }

  // Bind EDIT buttons
  document.querySelectorAll('.js-edit-row').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.dataset.mid;
      if (!id) { Swal.fire('Error','Record ID missing in button.','warning'); return; }
      try{
        const j = await fetchJSON('admin-ministry-women.php?ajax=wm_row&id=' + encodeURIComponent(id));
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
      } catch (err) {
        console.error(err);
        Swal.fire('Error', 'Unable to load selected record.', 'error');
      }
    });
  });

  // Submit EDIT
  editForm?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const fd = new FormData(editForm);
    Swal.fire({ title:'Saving...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
    try{
      const res = await fetch('admin-ministry-women.php', { method:'POST', body: fd });
      const txt = await res.text();
      console.log('EDIT save →', txt);
      const j = JSON.parse(txt);
      if (j && j.ok) {
        Swal.fire({ icon:'success', title:'Updated', timer:1100, showConfirmButton:false })
          .then(()=> window.location.reload());
      } else {
        Swal.fire('Error', (j&&j.msg)||'Update failed', 'error');
      }
    }catch(err){
      console.error(err);
      Swal.fire('Network error', 'Please try again.', 'error');
    }
  });

  // Bind ARCHIVE buttons (main table)
  document.querySelectorAll('.js-archive-row').forEach(btn => {
    btn.addEventListener('click', async ()=>{
      const id = btn.dataset.mid;
      if (!id) { Swal.fire('Missing ID', 'Cannot locate record id for archiving.', 'warning'); return; }

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

        const res = await fetch('admin-ministry-women.php', { method:'POST', body: fd });
        const txt = await res.text();
        console.log('ARCHIVE →', txt);
        const j = JSON.parse(txt);

        if (j && j.ok) {
          Swal.fire({ icon:'success', title:'Archived', timer:1200, showConfirmButton:false })
            .then(()=> window.location.reload());
        } else {
          Swal.fire('Error', (j&&j.msg)||'Archive failed', 'error');
        }
      }catch(e){
        console.error(e);
        Swal.fire('Network error', 'Please try again.', 'error');
      }
    });
  });

  // Bind UNARCHIVE buttons (archived modal table)
  document.querySelectorAll('.js-unarchive-row').forEach(btn => {
    btn.addEventListener('click', async ()=>{
      const id = btn.dataset.mid;
      if (!id) { Swal.fire('Missing ID', 'Cannot locate record id for unarchiving.', 'warning'); return; }

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

        const res = await fetch('admin-ministry-women.php', { method:'POST', body: fd });
        const txt = await res.text();
        console.log('UNARCHIVE →', txt);
        const j = JSON.parse(txt);

        if (j && j.ok) {
          Swal.fire({ icon:'success', title:'Unarchived', timer:1200, showConfirmButton:false })
            .then(()=> window.location.reload());
        } else {
          Swal.fire('Error', (j&&j.msg)||'Unarchive failed', 'error');
        }
      }catch(e){
        console.error(e);
        Swal.fire('Network error', 'Please try again.', 'error');
      }
    });
  });
})();

/* =================== SMART SORT (client) for MAIN TABLE ONLY =================== */
(function(){
  const table = document.querySelector('.page .container table');
  if (!table) return;
  const thead = table.tHead?.rows[0];
  const tbody = table.tBodies[0];
  if (!thead || !tbody) return;

  // Map column titles to indices (robust to order changes)
  const headerCells = Array.from(thead.cells);
  const colIndexByTitle = {};
  headerCells.forEach((th, idx) => {
    const key = th.textContent.trim().toLowerCase();
    colIndexByTitle[key] = idx;
    // Make data columns clickable (skip Action)
    if (key !== 'action') th.classList.add('sortable');
  });

  // Helpers
  const collator = new Intl.Collator(undefined, { numeric:true, sensitivity:'base' });
  const norm = (v) => (v ?? '').toString().trim();
  const isBlank = (s) => !s || s === '—';
  const parseMaybeDate = (s) => {
    if (!s) return NaN;
    // Accept "YYYY-MM-DD" or formatted "Month D, YYYY"
    const t1 = Date.parse(s);
    if (!Number.isNaN(t1)) return t1;
    const m = s.match(/^([A-Za-z]+)\s+(\d{1,2}),\s*(\d{4})$/);
    if (m) return Date.parse(`${m[1]} ${m[2]} ${m[3]}`);
    return NaN;
  };

  function cellText(tr, idx){
    const td = tr.cells[idx];
    if (!td) return '';
    if (td.querySelector('button')) return '';
    return td.textContent.trim();
  }

  // Build comparator from rules list: [{idx, type:'text'|'date', dir: 1|-1}, ...]
  function makeComparator(rules){
    return (a, b) => {
      for (const r of rules) {
        const av = cellText(a, r.idx);
        const bv = cellText(b, r.idx);

        const aBlank = isBlank(av), bBlank = isBlank(bv);
        if (aBlank && !bBlank) return 1;
        if (!aBlank && bBlank) return -1;

        let cmp = 0;
        if (r.type === 'date') {
          const at = parseMaybeDate(av), bt = parseMaybeDate(bv);
          const aNaN = Number.isNaN(at), bNaN = Number.isNaN(bt);
          if (aNaN && !bNaN) cmp = 1;
          else if (!aNaN && bNaN) cmp = -1;
          else if (!aNaN && !bNaN) cmp = at === bt ? 0 : (at < bt ? -1 : 1);
        } else {
          cmp = collator.compare(norm(av), norm(bv));
        }
        if (cmp !== 0) return r.dir * cmp;
      }
      return 0;
    };
  }

  function applySort(rules){
    const rows = Array.from(tbody.rows);
    rows.sort(makeComparator(rules));
    const frag = document.createDocumentFragment();
    rows.forEach(r => frag.appendChild(r));
    tbody.appendChild(frag);
  }

  let headerState = {};
  function clearHeaderArrows(exceptIdx){
    headerCells.forEach((th, i)=>{
      if (i !== exceptIdx) {
        th.removeAttribute('data-sort');
        headerState[i] = undefined;
      }
    });
  }
  headerCells.forEach((th, idx)=>{
    if (th.textContent.trim().toLowerCase() === 'action') return;
    th.addEventListener('click', ()=>{
      const curr = headerState[idx];
      const next = curr === 'asc' ? 'desc' : (curr === 'desc' ? undefined : 'asc');
      clearHeaderArrows(idx);
      if (!next){
        th.removeAttribute('data-sort');
        headerState[idx] = undefined;
        return;
      }
      th.dataset.sort = next;
      headerState[idx] = next;

      const title = th.textContent.trim().toLowerCase();
      const type = title.includes('date') ? 'date' : 'text';
      const dir = next === 'asc' ? 1 : -1;

      const rules = [{ idx, type, dir }];
      const ln = colIndexByTitle['lastname'];
      const fn = colIndexByTitle['firstname'];
      if (title === 'lastname' && typeof fn === 'number') rules.push({ idx: fn, type:'text', dir: 1 });
      if ((title === 'firstname' || title === 'middlename' || title === 'extension') && typeof ln === 'number')
        rules.push({ idx: ln, type:'text', dir: 1 });

      applySort(rules);
    });
  });

  const sortBtn = document.querySelector('.top-bar .btn-group .sort-button.sky');
  if (sortBtn){
    const panel = document.createElement('div');
    panel.className = 'sort-panel';
    panel.setAttribute('aria-hidden','true');
    const opts = headerCells
      .filter(th => th.textContent.trim().toLowerCase() !== 'action')
      .map(th => th.textContent.trim());
    const optionsHtml = opts.map(o=>`<option value="${o}">${o}</option>`).join('');
    panel.innerHTML = `
      <h4>Sort options</h4>
      <div class="sort-row">
        <select id="sortPrimary">${optionsHtml}</select>
        <button type="button" class="sort-dir" id="dirPrimary" data-dir="asc" aria-label="Primary direction">Asc</button>
      </div>
      <div class="sort-row">
        <select id="sortSecondary">
          <option value="">(none)</option>
          ${optionsHtml}
        </select>
        <button type="button" class="sort-dir" id="dirSecondary" data-dir="asc" aria-label="Secondary direction" disabled>Asc</button>
      </div>
      <div class="sort-actions">
        <button type="button" class="clear" id="clearSort">Clear</button>
        <button type="button" class="apply" id="applySort">Apply</button>
      </div>
    `;
    document.body.appendChild(panel);

    function togglePanel(){
      const open = panel.classList.toggle('open');
      panel.setAttribute('aria-hidden', open ? 'false':'true');
    }
    sortBtn.addEventListener('click', togglePanel);

    function toggleDir(btn){
      const curr = btn.getAttribute('data-dir') === 'asc' ? 'desc' : 'asc';
      btn.setAttribute('data-dir', curr);
      btn.textContent = curr === 'asc' ? 'Asc' : 'Desc';
    }
    const dirP = panel.querySelector('#dirPrimary');
    const dirS = panel.querySelector('#dirSecondary');
    dirP.addEventListener('click', ()=>toggleDir(dirP));
    dirS.addEventListener('click', ()=>toggleDir(dirS));

    const selS = panel.querySelector('#sortSecondary');
    selS.addEventListener('change', ()=>{
      const enable = !!selS.value;
      dirS.disabled = !enable;
      if (!enable) { dirS.setAttribute('data-dir','asc'); dirS.textContent='Asc'; }
    });

    panel.querySelector('#applySort').addEventListener('click', ()=>{
      const primary = panel.querySelector('#sortPrimary').value;
      const secondary = selS.value || null;
      const pIdx = colIndexByTitle[primary.toLowerCase()];
      const sIdx = secondary ? colIndexByTitle[secondary.toLowerCase()] : null;
      const pDir = panel.querySelector('#dirPrimary').getAttribute('data-dir') === 'asc' ? 1 : -1;
      const sDir = dirS.getAttribute('data-dir') === 'asc' ? 1 : -1;

      const pType = primary.toLowerCase().includes('date') ? 'date' : 'text';
      const rules = [{ idx: pIdx, type: pType, dir: pDir }];

      if (secondary && typeof sIdx === 'number' && sIdx !== pIdx){
        const sType = secondary.toLowerCase().includes('date') ? 'date' : 'text';
        rules.push({ idx: sIdx, type: sType, dir: sDir });
      } else {
        const ln = colIndexByTitle['lastname'];
        const fn = colIndexByTitle['firstname'];
        if (primary.toLowerCase() === 'lastname' && typeof fn === 'number')
          rules.push({ idx: fn, type:'text', dir: 1 });
        if ((primary.toLowerCase() === 'firstname' || primary.toLowerCase() === 'middlename' || primary.toLowerCase() === 'extension') && typeof ln === 'number')
          rules.push({ idx: ln, type:'text', dir: 1 });
      }

      headerCells.forEach((th,i)=>{
        if (i === pIdx) th.dataset.sort = (pDir === 1 ? 'asc':'desc');
        else if (secondary && typeof sIdx === 'number' && i === sIdx) th.dataset.sort = (sDir === 1 ? 'asc':'desc');
        else th.removeAttribute('data-sort');
      });

      applySort(rules);
      togglePanel();
    });

    panel.querySelector('#clearSort').addEventListener('click', ()=>{
      headerCells.forEach(th=>th.removeAttribute('data-sort'));
      togglePanel();
    });

    document.addEventListener('click', (e)=>{
      if (!panel.classList.contains('open')) return;
      const within = panel.contains(e.target) || sortBtn.contains(e.target);
      if (!within) togglePanel();
    });
  }
})();
</script>

</body>
</html>
