<?php
/* ===================== DEBUG START (REMOVE WHEN FIXED) ===================== */
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function dbg_log($msg) {
  $line = '['.date('Y-m-d H:i:s').'] '.$msg."\n";
  @file_put_contents(__DIR__.'/audit_debug.log', $line, FILE_APPEND);
}

function respond_json($ok, $msg, $extra = []) {
  if (!headers_sent()) header('Content-Type: application/json');
  $payload = array_merge(['ok' => $ok, 'msg' => $msg], $extra);
  echo json_encode($payload);
  exit;
}
/* ====================== DEBUG END (REMOVE WHEN FIXED) ====================== */

// ============ DB ============
include 'db-connection.php';

if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) { @session_start(); }

/* ========= ACTOR RESOLVER (admin) ========= */
/**
 * Returns:
 *  - id
 *  - username (for logging/audit trails)
 *  - email (optional)
 *  - full_name (for "Prepared by" display)
 *
 * NOTE: "Prepared by" will use full_name ONLY (no username/email).
 */
function resolveAdminActor(mysqli $db): array {
  $actorId = null;
  foreach (['admin_id','admin_user_id','id'] as $k) {
    if (!empty($_SESSION[$k])) { $actorId = (int)$_SESSION[$k]; break; }
  }

  // Session fallbacks
  $sessionUser  = $_SESSION['admin_username']  ?? $_SESSION['username'] ?? null;
  $sessionEmail = $_SESSION['admin_email']     ?? $_SESSION['email']    ?? null;

  // If you already store full name in session, we honor it:
  $sessionFull  = $_SESSION['admin_fullname']
               ?? $_SESSION['admin_full_name']
               ?? $_SESSION['fullname']
               ?? $_SESSION['full_name']
               ?? null;

  $username  = $sessionUser ?: null;
  $email     = $sessionEmail ?: null;
  $fullName  = $sessionFull ?: null;

  if ($actorId) {
    $tables = ['admin_table','admin'];

    foreach ($tables as $t) {
      try {
        $chk = @$db->query("SHOW TABLES LIKE '{$t}'");
        if (!$chk || !$chk->num_rows) continue;

        // Detect available columns (so we don't break if one table differs)
        $cols = [];
        $rsCols = $db->query("SHOW COLUMNS FROM {$t}");
        while ($row = $rsCols->fetch_assoc()) {
          $cols[strtolower($row['Field'])] = true;
        }

        $selectParts = [];
        // For display full name
        if (!empty($cols['admin_firstname']))   $selectParts[] = "admin_firstname";
        if (!empty($cols['admin_middlename']))  $selectParts[] = "admin_middlename";
        if (!empty($cols['admin_lastname']))    $selectParts[] = "admin_lastname";
        if (!empty($cols['admin_extname']))     $selectParts[] = "admin_extname"; // optional if you have it
        if (!empty($cols['admin_suffix']))      $selectParts[] = "admin_suffix";  // optional alt name

        // For fallback logging identity
        if (!empty($cols['admin_username']))        $selectParts[] = "admin_username";
        if (!empty($cols['admin_emailaddress']))    $selectParts[] = "admin_emailaddress";
        if (!empty($cols['admin_email']))           $selectParts[] = "admin_email"; // optional alt column

        if (empty($selectParts)) continue;

        $sql = "SELECT ".implode(", ", array_unique($selectParts))." FROM {$t} WHERE admin_id=? LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $actorId);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
          // Fill username/email if missing
          if (!$username) {
            if (!empty($row['admin_username'])) $username = $row['admin_username'];
          }
          if (!$email) {
            if (!empty($row['admin_emailaddress'])) $email = $row['admin_emailaddress'];
            else if (!empty($row['admin_email']))   $email = $row['admin_email'];
          }

          // Build full name if missing (First Middle Last + optional suffix/ext)
          if (!$fullName) {
            $first  = trim((string)($row['admin_firstname']  ?? ''));
            $middle = trim((string)($row['admin_middlename'] ?? ''));
            $last   = trim((string)($row['admin_lastname']   ?? ''));
            $ext    = trim((string)($row['admin_extname']    ?? ($row['admin_suffix'] ?? '')));

            $nameCore = trim($first.' '.($middle !== '' ? $middle.' ' : '').$last);
            $fullName = trim($nameCore.' '.($ext !== '' ? $ext : ''));
          }

          // If we got something, stop scanning tables
          if ($fullName || $username || $email) break;
        }
      } catch (Throwable $e) {
        dbg_log('resolveAdminActor_table_exception('.$t.'): '.$e->getMessage());
        continue;
      }
    }
  }

  // Final fallbacks
  $finalFull = trim((string)$fullName);
  if ($finalFull === '') {
    // If name isn't available, fall back to username so you still see something
    $finalFull = $username ?: 'system';
  }

  return [
    'id'        => $actorId ?: null,
    'username'  => $username ?: 'system',
    'email'     => $email ?: '',
    'full_name' => $finalFull
  ];
}

/* ========= UUID helper ========= */
if (!function_exists('uuidv4')) {
  function uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
  }
}

/* ========= NAME HELPERS (no DB schema change) ========= */
function parseFullName(string $full): array {
  $full = trim(preg_replace('/\s+/', ' ', $full));
  if ($full === '') return ['last'=>'','first'=>'','middle'=>'','ext'=>''];

  $suffixes = ['jr','jr.','sr','sr.','iii','ii','iv','v'];
  $last=''; $first=''; $middle=''; $ext='';

  if (strpos($full, ',') !== false) {
    [$lastPart, $rest] = array_map('trim', explode(',', $full, 2));
    $last = $lastPart;
    $parts = preg_split('/\s+/', $rest);
  } else {
    $parts = preg_split('/\s+/', $full);
    if (count($parts) > 1) {
      $lastTok = strtolower(trim(end($parts)));
      if (in_array($lastTok, $suffixes, true)) {
        $ext = array_pop($parts);
      }
    }
    $last = count($parts) ? array_pop($parts) : '';
  }

  if (!empty($parts)) {
    $first = array_shift($parts);
    $middle = implode(' ', $parts);
  }

  return ['last'=>$last,'first'=>$first,'middle'=>$middle,'ext'=>$ext];
}
function composeFullName(string $last, string $first, string $middle='', string $ext=''): string {
  $firstMid = trim($first.' '.trim($middle));
  $core = $last !== '' ? "{$last}, {$firstMid}" : $firstMid;
  return trim($core.' '.trim($ext));
}

/* ================== NOTIFICATION HELPERS (ADMIN -> INDIVIDUAL) ================== */
/**
 * Try to resolve the individual_id for a given prayer request.
 * Strategy:
 *  1) If prayer_table has an individual_id column, use it.
 *  2) Else, try to match by name against individual_table (Last + First, with optional middle/ext).
 */
function findIndividualIdForPrayer(mysqli $db, int $prayerId): ?int {
  try {
    // Check if column exists
    $col = $db->query("SHOW COLUMNS FROM prayer_table LIKE 'individual_id'");
    if ($col && $col->num_rows) {
      $stmt = $db->prepare("SELECT individual_id FROM prayer_table WHERE prayer_id=? LIMIT 1");
      $stmt->bind_param("i", $prayerId);
      $stmt->execute();
      $stmt->bind_result($iid);
      if ($stmt->fetch()) { $stmt->close(); return $iid ? (int)$iid : null; }
      $stmt->close();
    }

    // Fallback by name
    $memName = '';
    $stmt2 = $db->prepare("SELECT prayer_mem_name FROM prayer_table WHERE prayer_id=? LIMIT 1");
    $stmt2->bind_param("i", $prayerId);
    $stmt2->execute();
    $stmt2->bind_result($memName);
    $stmt2->fetch();
    $stmt2->close();

    $memName = trim((string)$memName);
    if ($memName === '') return null;

    $p = parseFullName($memName);

    // Try strict match Last + First (+ Middle if present)
    $sql = "SELECT individual_id FROM individual_table
            WHERE TRIM(LOWER(individual_lastname)) = TRIM(LOWER(?))
              AND TRIM(LOWER(individual_firstname)) = TRIM(LOWER(?))
            ORDER BY individual_id DESC LIMIT 1";
    if ($stmt3 = $db->prepare($sql)) {
      $stmt3->bind_param("ss", $p['last'], $p['first']);
      $stmt3->execute();
      $stmt3->bind_result($iid2);
      if ($stmt3->fetch()) { $stmt3->close(); return (int)$iid2; }
      $stmt3->close();
    }

  } catch (Throwable $e) {
    dbg_log('findIndividualIdForPrayer_exception: '.$e->getMessage());
  }
  return null;
}

/**
 * Create a notification to a specific individual about an admin action.
 * Tables used:
 *  - notifications (title, body, created_by_type, created_by_id)
 *  - notification_recipients (notification_id, user_type, user_id, status)
 */
function notifyIndividualAboutAdminAction(mysqli $db, int $individualId, array $adminActor, string $action, int $prayerId, string $extraSummary=''): bool {
  if ($individualId <= 0) return false;
  $createdByType = 'admin';
  $createdById   = (int)($adminActor['id'] ?? 0);

  $title = "Your prayer request was ".($action === 'Done' ? 'marked Done' : ($action === 'Declined' ? 'Declined' : 'updated'));
  $lines = [];
  $lines[] = "Action: {$action}";
  $lines[] = "Record ID: {$prayerId}";
  if (!empty($adminActor['username'])) $lines[] = "By: ".$adminActor['username'];
  if ($extraSummary !== '') $lines[] = "Note: ".$extraSummary;
  $body  = implode("\n", $lines);

  try {
    $db->begin_transaction();

    // Insert into notifications
    $stmtN = $db->prepare("INSERT INTO notifications (title, body, created_by_type, created_by_id) VALUES (?, ?, ?, ?)");
    $stmtN->bind_param("sssi", $title, $body, $createdByType, $createdById);
    $stmtN->execute();
    $notificationId = (int)$db->insert_id;
    $stmtN->close();

    if ($notificationId <= 0) { $db->rollback(); return false; }

    // Insert recipient for this individual
    $stmtR = $db->prepare("INSERT INTO notification_recipients (notification_id, user_type, user_id, status) VALUES (?, 'individual', ?, 'unread')");
    $stmtR->bind_param("ii", $notificationId, $individualId);
    $stmtR->execute();
    $stmtR->close();

    $db->commit();
    return true;
  } catch (Throwable $e) {
    dbg_log('notifyIndividual_exception: '.$e->getMessage());
    try { $db->rollback(); } catch (Throwable $e2) {}
    return false;
  }
}

/* ---------- COMMON: FILTERS + SORT (share between table + PDF) ---------- */
$allowedStatusMap  = ['pending'=>'Pending','done'=>'Done','declined'=>'Declined','all'=>'All'];
$allowedPrivacyMap = ['public'=>'Public','private'=>'Private','all'=>'All'];

$rawStatus      = $_GET['status']  ?? 'Pending';
$normStatusKey  = strtolower(trim($rawStatus));
$currentStatus  = $allowedStatusMap[$normStatusKey] ?? 'Pending';

$rawPrivacy     = $_GET['privacy'] ?? 'All';
$normPrivacyKey = strtolower(trim($rawPrivacy));
$currentPrivacy = $allowedPrivacyMap[$normPrivacyKey] ?? 'All';

/* Whitelist sort fields */
$sortMap = [
  'date'    => 'prayer_date',
  'prayer'  => 'prayer_request',
  'privacy' => 'prayer_option',
  'status'  => 'prayer_status',
];
$rawSort = $_GET['sort'] ?? 'date';
$rawDir  = $_GET['dir']  ?? 'desc';

$sortKey  = strtolower($rawSort);
$orderCol = $sortMap[$sortKey] ?? $sortMap['date'];

$dirKey   = strtolower($rawDir);
$orderDir = ($dirKey === 'asc') ? 'ASC' : 'DESC';

$orderSql = " ORDER BY {$orderCol} {$orderDir}, prayer_id DESC ";

/* Helper to build WHERE and prepared params based on current filters */
function build_where_and_params($currentStatus, $currentPrivacy) {
  $where = []; $params = []; $types = '';
  if ($currentStatus !== 'All')  { $where[] = 'prayer_status = ?';  $types.='s'; $params[]=$currentStatus; }
  if ($currentPrivacy !== 'All') { $where[] = 'prayer_option = ?';  $types.='s'; $params[]=$currentPrivacy; }
  return [$where, $types, $params];
}

/* ========== PRAYER REQUESTS PDF (portrait A4, filters+sort, clean header) ========== */
if (isset($_GET['download']) && $_GET['download'] === 'prayers_pdf') {
  try {
    // Use SAME filters & sorting as table
    [$where, $types, $params] = build_where_and_params($currentStatus, $currentPrivacy);
    $sql = "SELECT prayer_date, prayer_request, prayer_option, prayer_mem_name, prayer_status
            FROM prayer_table";
    if (!empty($where)) $sql .= " WHERE ".implode(' AND ', $where);
    $sql .= $orderSql;

    $stmt = $db_connection->prepare($sql);
    if (!$stmt) throw new Exception('DB prepare failed: '.$db_connection->error);
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    // ===================== Prepared by (FULL NAME ONLY) =====================
    $actor = resolveAdminActor($db_connection);
    $preparedByText = trim((string)($actor['full_name'] ?? 'system'));
    if ($preparedByText === '') $preparedByText = 'system';
    $preparedByTextEsc = htmlspecialchars($preparedByText, ENT_QUOTES, 'UTF-8');
    // ========================================================================

    $host   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
    $logo   = $host . '/HTCCC-SYSTEM/image/httc_main-logo.jpg';
    $title  = 'Prayer Requests';
    $nowStr = date('F j, Y g:i A');

    $html = '<!doctype html><html><head><meta charset="utf-8"><style>
      *{box-sizing:border-box} body{font-family: DejaVu Sans, Arial, Helvetica, sans-serif; color:#0b1220; margin:0; padding:24px}
      .header{display:flex; align-items:center; gap:14px; margin-bottom:6px}
      .logo{width:52px; height:52px; object-fit:cover; border-radius:8px}
      h1{font-size:18px; margin:0} .meta{font-size:12px; color:#4b5563}
      .bar{height:4px; background:#00216e; border-radius:4px; margin:10px 0 16px}
      table{width:100%; border-collapse:collapse; font-size:12px}
      thead th{background:#00216e; color:#fff; text-align:left; padding:10px; font-weight:700}
      tbody td{padding:8px 10px; border-bottom:1px solid #e6edf5; vertical-align:top}
      .small{color:#374151; font-size:11px}
      .tag{display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px}
      .tag.Pending{background:#fde68a} .tag.Done{background:#bbf7d0} .tag.Declined{background:#fecaca}
      </style></head><body>
      <div class="header"><img class="logo" src="'.$logo.'" alt="HTCCC"><div>
        <h1>'.$title.'</h1>
        <div class="meta">Generated: '.$nowStr.'</div>
        <div class="meta">Prepared by: '.$preparedByTextEsc.'</div>
      </div></div><div class="bar"></div>
      <table><thead><tr>
        <th style="width:17%">Date</th>
        <th style="width:28%">Prayer</th>
        <th style="width:12%">Privacy</th>
        <th style="width:13%">Last</th>
        <th style="width:13%">First</th>
        <th style="width:10%">Middle</th>
        <th style="width:7%">Ext</th>
        <th style="width:10%">Status</th>
      </tr></thead><tbody>';

    if (!empty($rows)) {
      foreach ($rows as $r) {
        $d   = $r['prayer_date'] ? date('F j, Y', strtotime($r['prayer_date'])) : '';
        $req = htmlspecialchars($r['prayer_request'] ?? '', ENT_QUOTES, 'UTF-8');
        $opt = htmlspecialchars($r['prayer_option'] ?? '', ENT_QUOTES, 'UTF-8');
        $st  = htmlspecialchars($r['prayer_status'] ?? '', ENT_QUOTES, 'UTF-8');
        $mem = htmlspecialchars($r['prayer_mem_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $p = parseFullName($mem);

        $html .= '<tr>
          <td>'.$d.'</td>
          <td>'.$req.'</td>
          <td>'.$opt.'</td>
          <td>'.htmlspecialchars($p['last'],ENT_QUOTES,'UTF-8').'</td>
          <td>'.htmlspecialchars($p['first'],ENT_QUOTES,'UTF-8').'</td>
          <td>'.htmlspecialchars($p['middle'],ENT_QUOTES,'UTF-8').'</td>
          <td>'.htmlspecialchars($p['ext'],ENT_QUOTES,'UTF-8').'</td>
          <td><span class="tag '.$st.'">'.$st.'</span></td>
        </tr>';
      }
    } else {
      $html .= '<tr><td colspan="8" class="small">No records found.</td></tr>';
    }

    $html .= '</tbody></table></body></html>';

    require_once __DIR__ . '/vendor/autoload.php';
    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait'); // Portrait A4
    $dompdf->render();

    $filename = 'Prayer_Requests_'.($currentStatus==='All'?'All':$currentStatus).'_'.date('Y-m-d_His').'.pdf';
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
  } catch (Throwable $e) {
    dbg_log('prayers_pdf_exception: '.$e->getMessage());
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo "Failed to generate PDF.\n\n".$e->getMessage();
    exit;
  }
}

/* ---------- AJAX: update status (Done / Declined) & BULK DONE ---------- */
try {

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='bulk_done') {
  $rawIds = $_POST['ids'] ?? [];
  if (!is_array($rawIds)) {
    $rawIds = explode(',', (string)$rawIds);
  }

  $idMap = [];
  foreach ($rawIds as $rid) {
    $rid = (int)$rid;
    if ($rid > 0) $idMap[$rid] = true;
  }
  $ids = array_keys($idMap);

  if (empty($ids)) {
    respond_json(false, 'No valid IDs selected', ['where'=>'bulk_validate']);
  }

  $actor = resolveAdminActor($db_connection);
  $actorId    = $actor['id'];
  $actorUser  = $actor['username'];
  $actorEmail = $actor['email'];

  $ip        = $_SERVER['REMOTE_ADDR']     ?? '';
  $ua        = $_SERVER['HTTP_USER_AGENT'] ?? '';
  $form      = 'admin-prayer-request';
  $sourceTbl = 'prayer_table';

  // Check once if audit_log table exists
  $hasAuditLog = false;
  try {
    $checkAL = @$db_connection->query("SHOW TABLES LIKE 'audit_log'");
    if ($checkAL && $checkAL->num_rows) {
      $hasAuditLog = true;
    }
  } catch (\Throwable $e) {
    $hasAuditLog = false;
  }

  $updated = 0;
  $errors  = [];

  foreach ($ids as $id) {
    $oldStatus = null;

    // Get old status
    try {
      $stmtOld = $db_connection->prepare("SELECT prayer_status FROM prayer_table WHERE prayer_id=? LIMIT 1");
      if ($stmtOld) {
        $stmtOld->bind_param("i", $id);
        $stmtOld->execute();
        $stmtOld->bind_result($oldStatus);
        $stmtOld->fetch();
        $stmtOld->close();
      }
    } catch (Throwable $e) {
      dbg_log('bulk_select_old_exception('.$id.'): '.$e->getMessage());
      $errors[] = "ID {$id}: failed to read old status";
      continue;
    }

    // Update to Done
    try {
      $status = 'Done';
      $stmt = $db_connection->prepare("UPDATE prayer_table SET prayer_status=? WHERE prayer_id=? LIMIT 1");
      if (!$stmt) {
        dbg_log('bulk_update_prepare_failed('.$id.'): '.$db_connection->error);
        $errors[] = "ID {$id}: prepare failed";
        continue;
      }
      $stmt->bind_param("si", $status, $id);
      $ok = $stmt->execute();
      $stmt->close();
      if (!$ok) {
        $errors[] = "ID {$id}: update failed";
        continue;
      }
      $updated++;
    } catch (Throwable $e) {
      dbg_log('bulk_update_exception('.$id.'): '.$e->getMessage());
      $errors[] = "ID {$id}: exception during update";
      continue;
    }

    // Audit trail
    try {
      $txn       = uuidv4();
      $recordPk  = (string)$id;
      $notes     = 'Bulk status change: Done';
      $before    = json_encode(['status'=>$oldStatus], JSON_UNESCAPED_UNICODE);
      $after     = json_encode(['status'=>'Done'], JSON_UNESCAPED_UNICODE);

      $sqlTrail = "INSERT INTO audit_trail
        (txn_id, actor_admin_id, actor_username, actor_email, action,
         source_table, record_pk, form_name, ip_address, user_agent,
         notes, details_before, details_after)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";
      $log = $db_connection->prepare($sqlTrail);
      if ($log) {
        $action = 'Done';
        $log->bind_param(
          "sisssssssssss",
          $txn, $actorId, $actorUser, $actorEmail, $action,
          $sourceTbl, $recordPk, $form, $ip, $ua, $notes, $before, $after
        );
        $log->execute(); $log->close();
      } else {
        dbg_log('bulk_audit_trail_prepare_failed('.$id.'): '.$db_connection->error);
      }
    } catch (Throwable $e) {
      dbg_log('bulk_audit_trail_exception('.$id.'): '.$e->getMessage());
    }

    // Optional simple audit_log
    if ($hasAuditLog) {
      try {
        $sqlAL = "INSERT INTO audit_log
          (entity, entity_id, action, old_value, new_value, actor_username, ip_address, user_agent, created_at)
          VALUES ('prayer_table', ?, ?, ?, ?, ?, ?, ?, NOW())";
        if ($lg = $db_connection->prepare($sqlAL)) {
          $newStatus = 'Done';
          $lg->bind_param("issssss", $id, $newStatus, $oldStatus, $newStatus, $actorUser, $ip, $ua);
          $lg->execute(); $lg->close();
        }
      } catch (\Throwable $e2) {
        dbg_log('bulk_audit_log_exception('.$id.'): '.$e2->getMessage());
      }
    }

    // Notification to individual
    try {
      $individualId = findIndividualIdForPrayer($db_connection, $id);

      if ($individualId) {
        $summary = '';
        try {
          $stmtS = $db_connection->prepare("SELECT prayer_request, prayer_date FROM prayer_table WHERE prayer_id=? LIMIT 1");
          $stmtS->bind_param("i", $id);
          $stmtS->execute();
          $stmtS->bind_result($prTitle, $prDate);
          if ($stmtS->fetch()) {
            $summary = trim(($prTitle ? "“{$prTitle}”" : '') . ($prDate ? " on ".date('F j, Y', strtotime($prDate)) : ''));
          }
          $stmtS->close();
        } catch (Throwable $e) { /* ignore summary */ }

        $note = $summary !== '' ? "Prayer ".$summary : '';
        notifyIndividualAboutAdminAction($db_connection, $individualId, $actor, 'Done', $id, $note);
      } else {
        dbg_log("bulk_notification_skip: could not resolve individual_id for prayer_id={$id}");
      }
    } catch (Throwable $e) {
      dbg_log('bulk_notify_individual_exception('.$id.'): '.$e->getMessage());
    }
  }

  respond_json(true, 'Bulk update completed', [
    'where'   => 'bulk_done',
    'updated' => $updated,
    'total'   => count($ids),
    'errors'  => $errors
  ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='update_status') {
  $id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  $status = $_POST['status'] ?? '';

  $allowed = ['Done','Declined'];
  if (!$id || !in_array($status, $allowed, true)) {
    respond_json(false, 'Invalid id/status', ['where'=>'validate']);
  }

  $oldStatus = null;
  try {
    $stmtOld = $db_connection->prepare("SELECT prayer_status FROM prayer_table WHERE prayer_id=? LIMIT 1");
    if (!$stmtOld) respond_json(false, 'DB prepare failed (select old)', ['sql_error'=>$db_connection->error, 'where'=>'select_old_prepare']);
    $stmtOld->bind_param("i", $id);
    $stmtOld->execute();
    $stmtOld->bind_result($oldStatus);
    $stmtOld->fetch();
    $stmtOld->close();
  } catch (Throwable $e) {
    error_log($e);
    dbg_log('select_old_exception: '.$e->getMessage());
    respond_json(false, 'Exception selecting old status', ['exception'=>$e->getMessage(), 'where'=>'select_old_execute']);
  }

  try {
    $stmt = $db_connection->prepare("UPDATE prayer_table SET prayer_status=? WHERE prayer_id=? LIMIT 1");
    if (!$stmt) respond_json(false, 'DB prepare failed (update)', ['sql_error'=>$db_connection->error, 'where'=>'update_prepare']);
    $stmt->bind_param("si", $status, $id);
    $ok = $stmt->execute();
    $stmt->close();
  } catch (Throwable $e) {
    error_log($e);
    dbg_log('update_exception: '.$e->getMessage());
    respond_json(false, 'Exception during update', ['exception'=>$e->getMessage(), 'where'=>'update_execute']);
  }

  $actor = resolveAdminActor($db_connection);
  $actorId    = $actor['id'];
  $actorUser  = $actor['username'];
  $actorEmail = $actor['email'];

  try {
    $ip        = $_SERVER['REMOTE_ADDR']     ?? '';
    $ua        = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $txn       = uuidv4();
    $form      = 'admin-prayer-request';
    $sourceTbl = 'prayer_table';
    $recordPk  = (string)$id;
    $notes     = sprintf('Status change via button: %s', $status);
    $before    = json_encode(['status'=>$oldStatus], JSON_UNESCAPED_UNICODE);
    $after     = json_encode(['status'=>$status],    JSON_UNESCAPED_UNICODE);

    $sqlTrail = "INSERT INTO audit_trail
      (txn_id, actor_admin_id, actor_username, actor_email, action,
       source_table, record_pk, form_name, ip_address, user_agent,
       notes, details_before, details_after)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $log = $db_connection->prepare($sqlTrail);
    if ($log) {
      $log->bind_param(
        "sisssssssssss",
        $txn, $actorId, $actorUser, $actorEmail, $status,
        $sourceTbl, $recordPk, $form, $ip, $ua, $notes, $before, $after
      );
      $log->execute(); $log->close();
    } else {
      dbg_log('audit_trail_prepare_failed: '.$db_connection->error);
    }
  } catch (Throwable $e) {
    error_log($e);
    dbg_log('audit_trail_exception: '.$e->getMessage());
  }

  try {
    $hasAuditLog = @$db_connection->query("SHOW TABLES LIKE 'audit_log'");
    if ($hasAuditLog && $hasAuditLog->num_rows) {
      $sqlAL = "INSERT INTO audit_log
        (entity, entity_id, action, old_value, new_value, actor_username, ip_address, user_agent, created_at)
        VALUES ('prayer_table', ?, ?, ?, ?, ?, ?, ?, NOW())";
      if ($lg = $db_connection->prepare($sqlAL)) {
        $lg->bind_param("issssss", $id, $status, $oldStatus, $status, $actorUser, $ip, $ua);
        $lg->execute(); $lg->close();
      }
    }
  } catch (\Throwable $e2) { /* ignore */ }

  /* ================== NEW: SEND NOTIFICATION TO SPECIFIC INDIVIDUAL ================== */
  try {
    // Try to resolve which individual owns this prayer request
    $individualId = findIndividualIdForPrayer($db_connection, $id);

    if ($individualId) {
      // Build a short contextual summary from the row (optional)
      $summary = '';
      try {
        $stmtS = $db_connection->prepare("SELECT prayer_request, prayer_date FROM prayer_table WHERE prayer_id=? LIMIT 1");
        $stmtS->bind_param("i", $id);
        $stmtS->execute();
        $stmtS->bind_result($prTitle, $prDate);
        if ($stmtS->fetch()) {
          $summary = trim(($prTitle ? "“{$prTitle}”" : '') . ($prDate ? " on ".date('F j, Y', strtotime($prDate)) : ''));
        }
        $stmtS->close();
      } catch (Throwable $e) { /* ignore summary */ }

      $note = $summary !== '' ? "Prayer ".$summary : '';
      notifyIndividualAboutAdminAction($db_connection, $individualId, $actor, $status, $id, $note);
    } else {
      dbg_log("notification_skip: could not resolve individual_id for prayer_id={$id}");
    }
  } catch (Throwable $e) {
    dbg_log('notify_individual_wrapper_exception: '.$e->getMessage());
  }
  /* ================== END NEW NOTIFICATION LOGIC ================== */

  respond_json($ok, $ok ? 'Updated' : 'Update failed', [
    'where'=>'done',
    'oldStatus'=>$oldStatus,
    'newStatus'=>$status,
    'actor'=>['id'=>$actorId, 'username'=>$actorUser, 'email'=>$actorEmail]
  ]);
}

/* ---------- PRG: handle Add form submit ---------- */
$errorMessage = null;
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_prayer"])) {
  $prayer_date        = trim($_POST["prayer_date"]        ?? '');
  $prayer_type        = trim($_POST["prayer_type"]        ?? '');
  $prayer_request     = trim($_POST["prayer_request"]     ?? '');
  $prayer_option      = trim($_POST["prayer_option"]      ?? '');
  $prayer_description = trim($_POST["prayer_description"] ?? '');
  $last               = trim($_POST["mem_last"]   ?? '');
  $first              = trim($_POST["mem_first"]  ?? '');
  $middle             = trim($_POST["mem_middle"] ?? '');
  $ext                = trim($_POST["mem_ext"]    ?? '');
  $prayer_mem_name    = composeFullName($last, $first, $middle, $ext);
  if ($prayer_mem_name === '') {
    $prayer_mem_name = trim($_POST["prayer_mem_name"] ?? '');
  }
  $prayer_status      = "Pending";

  if ($prayer_date && $prayer_type && $prayer_request && $prayer_option && $prayer_mem_name) {
    $stmt = $db_connection->prepare("
      INSERT INTO prayer_table
        (prayer_date, prayer_type, prayer_request, prayer_option, prayer_description, prayer_mem_name, prayer_status)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    if ($stmt) {
      $stmt->bind_param("sssssss",
        $prayer_date, $prayer_type, $prayer_request, $prayer_option,
        $prayer_description, $prayer_mem_name, $prayer_status
      );
      if ($stmt->execute()) { header("Location: ".$_SERVER['PHP_SELF']."?added=1"); exit; }
      else { $errorMessage = "Database error: ".$stmt->error; }
      $stmt->close();
    } else {
      $errorMessage = "Database prepare failed: ".$db_connection->error;
    }
  } else {
    $errorMessage = "Please fill in all required fields.";
  }
}

/* ---------- Modal “Done list” renderer ---------- */
if (isset($_GET['view']) && $_GET['view'] === 'done_list') {
  $rs = mysqli_query($db_connection, "SELECT * FROM prayer_table WHERE prayer_status='Done' ORDER BY prayer_date DESC");
  ?>
  <div class="modal-header">
    <h3>Done List</h3>
    <button type="button" class="modal-close" onclick="closeListModal()">×</button>
  </div>
  <div class="modal-body" style="max-height:60vh;overflow:auto">
    <table class="mini-table" style="width:100%;border-collapse:collapse">
      <thead>
        <tr style="background:#00216e;color:#fff">
          <th style="padding:8px;text-align:left">Date</th>
          <th style="padding:8px;text-align:left">Prayer</th>
          <th style="padding:8px;text-align:left">Privacy</th>
          <th style="padding:8px;text-align:left">Last</th>
          <th style="padding:8px;text-align:left">First</th>
          <th style="padding:8px;text-align:left">Middle</th>
          <th style="padding:8px;text-align:left">Ext</th>
          <th style="padding:8px;text-align:left">Status</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($rs && mysqli_num_rows($rs)>0): while($row=mysqli_fetch_assoc($rs)): $np=parseFullName($row['prayer_mem_name']??''); ?>
        <tr style="border-bottom:1px solid #e6edf5">
          <td style="padding:8px"><?php echo htmlspecialchars(date('F j, Y', strtotime($row['prayer_date']))); ?></td>
          <td style="padding:8px"><?php echo htmlspecialchars($row['prayer_request']); ?></td>
          <td style="padding:8px"><?php echo htmlspecialchars($row['prayer_option']); ?></td>
          <td style="padding:8px"><?php echo htmlspecialchars($np['last']);   ?></td>
          <td style="padding:8px"><?php echo htmlspecialchars($np['first']);  ?></td>
          <td style="padding:8px"><?php echo htmlspecialchars($np['middle']); ?></td>
          <td style="padding:8px"><?php echo htmlspecialchars($np['ext']);    ?></td>
          <td style="padding:8px"><?php echo htmlspecialchars($row['prayer_status']); ?></td>
        </tr>
      <?php endwhile; else: ?>
        <tr><td colspan="8" style="padding:10px;color:#6b7280">No records.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="modal-footer">
    <div class="grow"></div>
    <button class="btn" onclick="closeListModal()">Close</button>
  </div>
  <?php
  exit;
}

} catch (Throwable $outer) {
  error_log($outer);
  dbg_log('outer_exception: '.$outer->getMessage());
  if (isset($_POST['action']) && $_POST['action']==='update_status') {
    respond_json(false, 'Fatal error', ['exception'=>$outer->getMessage(), 'where'=>'outer']);
  }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta content="width=device-width, initial-scale=1" name="viewport"/>
  <title>Admin – Prayer Request</title>

  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/admin-prayer-request.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/admin-prayer-request.css'); ?>">

  <style>
    :root{ --brand:#00216e; --ink:#0b1220; --muted:#6b7280; --border:#e5e7eb; --bg:#f7f9fc; --radius:14px; --ring:#7aa2ff; }
    .modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;z-index:9999}
    .modal[aria-hidden="false"]{display:flex}
    .modal::before{content:"";position:absolute;inset:0;background:rgba(2,6,23,.55);backdrop-filter:blur(2px);}
    .modal-dialog{position:relative;background:#fff;width:min(920px,96vw);border-radius:var(--radius);overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.4),0 8px 20px rgba(0,0,0,.25);transform:translateY(12px);animation:pop .18s ease-out both;}
    @keyframes pop{from{opacity:.6;transform:translateY(18px) scale(.98)} to{opacity:1;transform:translateY(0) scale(1)}}
    .modal-header{background:linear-gradient(0deg, rgba(255,255,255,.06), rgba(255,255,255,.06)), var(--brand);color:#fff;padding:14px 18px;display:flex;align-items:center;gap:10px}
    .modal-header h3{margin:0;font-size:18px}
    .modal-close{margin-left:auto;background:transparent;border:0;color:#fff;font-size:22px;cursor:pointer;border-radius:8px;padding:2px 8px}
    .modal-close:hover{background:rgba(255,255,255,.12)}
    .modal-body{padding:18px;background:#fff}
    .modal-footer{padding:12px 16px;background:var(--bg);display:flex;gap:10px;align-items:center;border-top:1px solid var(--border)}
    .modal-footer .grow{flex:1}
    .btn{background:#edf1f7;border:1px solid var(--border);border-radius:10px;padding:10px 14px;cursor:pointer;font-weight:600}
    .btn.primary{background:var(--brand);color:#fff;border-color:transparent}
    .btn.success{background:#0f8a4b;color:#fff;border-color:transparent}
    .btn.danger{background:#c03636;color:#fff;border-color:transparent}
    .btn.tiny{padding:6px 10px;font-size:12px}
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media (max-width:640px){ .form-grid{grid-template-columns:1fr} }
    .field{display:flex;flex-direction:column;gap:6px}
    .field.full{grid-column:1 / -1}
    .label{font-size:12px;font-weight:700;color:#1f2937;letter-spacing:.2px;display:flex;align-items:center;gap:6px}
    .req{color:#e11d48;font-weight:800}
    .help{font-size:12px;color:var(--muted)}
    .control{border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-size:14px;outline:0;transition:box-shadow .12s ease,border-color .12s ease;background:#fff;}
    select.control{appearance:none;background-image:linear-gradient(45deg,transparent 50%,#9aa0a6 50%),linear-gradient(135deg,#9aa0a6 50%,transparent 50%),linear-gradient(to right,#ddd,#ddd);background-position:calc(100% - 18px) 16px, calc(100% - 12px) 16px, calc(100% - 2.2rem) 50%;background-size:6px 6px,6px 6px,1px 60%;background-repeat:no-repeat;}
    textarea.control{min-height:96px;resize:vertical}
    .tag{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px}
    .tag.pending{background:#fde68a}.tag.done{background:#bbf7d0}.tag.declined{background:#fecaca}
    .smart-help{position:relative;display:inline-block;margin-left:8px;cursor:pointer;font-size:12px;color:#334155}
    .smart-help .bubble{position:absolute;right:0;top:130%;background:#0f1720;color:#e5efff;border:1px solid rgba(255,255,255,.15);padding:10px 12px;border-radius:10px;width:min(420px,92vw);display:none;z-index:50;box-shadow:0 10px 30px rgba(0,0,0,.25)}
    .smart-help:hover .bubble{display:block}
    mark.smart-hit{background:#ffe08a;color:#111;padding:0 .12em;border-radius:4px}
    .dl-form{display:flex;align-items:center;gap:6px}
    .dl-form select{border:1px solid var(--border);border-radius:10px;padding:8px 10px;background:#fff}
  </style>
</head>
<body>

<!-- ======================== SIDEBAR ======================== -->
  <aside class="sidebar">
    <div class="brand">
      <img src="image/httc_main-logo.jpg" alt="" />
      <span>HTCCC SYSTEM</span>
    </div>
    <div class="user-card">
      <img src="css/image/profile.png" alt="user">
      <div>
        <div class="user-title">Pastor</div>
        <div class="user-sub">Dashboard</div>
      </div>
    </div>
    <nav class="nav">
      <div class="section-title">Main</div>
      <a class="navlink" href="pastor-dashboard.php"><i class="fas fa-home"></i>Dashboard</a>
      <div class="section-title">Online Requests</div>
      <a class="navlink" href="pastor-schedule-request.php"><i class="fas fa-calendar-plus"></i>Schedule Requests</a>
      <a class="navlink active" href="pastor-prayer-request.php"><i class="fas fa-praying-hands"></i><span>Prayer Requests</span></a>
      <div class="section-title">Online Applications</div>
      <a class="navlink" href="baptismal_application.php"><i class="fas fa-water"></i>Baptismal Applications</a>
      <a class="navlink" href="admin-application.php"><i class="fas fa-user-cog"></i>Baptsimal Account Verification</a>
      <a class="navlink" href="application_ministry.php"><i class="fas fa-users"></i>Ministry Applications</a>
      <div class="section-title">Schedule</div>
      <a class="navlink" href="appointment-schedule.php"><i class="fas fa-calendar-check"></i>Service Schedule</a>
      <div class="section-title">All Done Services</div>
      <a class="navlink" href="done-service-wedding.php"><i class="fas fa-ring"></i>Wedding Service</a>
      <a class="navlink" href="done-service-dedication.php"><i class="fas fa-baby"></i>Child Dedication</a>
      <a class="navlink" href="done-service-funeral.php"><i class="fas fa-cross"></i>Funeral Service</a>
      <a class="navlink" href="done-service-house.php"><i class="fas fa-home"></i>House Blessing</a>
      <a class="navlink" href="done-service-baptism.php"><i class="fas fa-tint"></i>Water Baptism</a>
      <div class="section-title">Streaming</div>
      <a class="navlink" href="admin-multimedia.php"><i class="fas fa-broadcast-tower"></i>Streaming</a>
       <div class="section-title">Individual Management</div>
      <a class="navlink" href="admin-individual_list.php"><i class="fas fa-user"></i>Individual List</a>
      <div class="section-title">Ministry Management</div>
      <a class="navlink" href="admin-ministry-women.php"><i class="fas fa-female"></i>Handmaid's of the Lord</a>
      <a class="navlink" href="admin-ministry-men.php"><i class="fas fa-male"></i>Men's Ministry</a>
      <a class="navlink" href="admin-ministry-music.php"><i class="fas fa-music"></i>Mzusic Ministry</a>
      <a class="navlink" href="admin-ministry-usher.php"><i class="fas fa-hands-helping"></i>Usher &amp; Usherette</a>
      <div class="section-title">Reports</div>
      <a class="navlink" href="admin-reports.php"><i class="fas fa-file-alt"></i>Reports</a>
      <div class="section-title">Content</div>
      <a class="navlink" href="content-management_home-page.php"><i class="fas fa-edit"></i>Content Management</a>
      <div class="section-title">Certificates</div>
      <a class="navlink" href="certificate-table.php"><i class="fas fa-award"></i>Generate Certificate</a>
      <div class="section-title">Account</div>
      <a class="navlink" href="admin-account-settings.php"><i class="fas fa-user-shield"></i>Account Settings</a>
      <div class="section-title">Management</div>
      <a class="navlink" href="pastor-audittrails.php"><i class="fa fa-file"></i> Audit Trails</a>
      <a class="navlink" href="pastor-admin-accounts.php"><i class="fas fa-user"></i>Admin Accounts</a>
      <div class="section-title">More</div>
      <a class="navlink logout" href="all_log-in.php"><i class="fas fa-sign-out-alt"></i>Log Out</a>
    </nav>
  </aside>

<div class="page">
  <header class="topbar">
    <h1>Prayer Request</h1>
    <div class="top-actions">
      <div class="search" style="display:flex;align-items:center;gap:6px">
        <i class="fas fa-search"></i>
        <input id="searchBox" type="text" placeholder="Search requests…">
        <span class="smart-help">?
          <span class="bubble">
            <div style="font-weight:700;margin-bottom:6px">Smart search (always on)</div>
            <ul style="margin:0;padding-left:16px;line-height:1.5">
              <li><code>"exact phrase"</code> match phrase</li>
              <li><code>-word</code> exclude</li>
              <li><code>member:juan</code> filter by member name</li>
              <li><code>privacy:public|private</code> OR match</li>
              <li><code>status:pending|done|declined</code></li>
              <li><code>date:2025-10-01..2025-10-31</code> range (Prayer Date)</li>
              <li><code>healing~</code> fuzzy (~=allow small typos)</li>
            </ul>
          </span>
        </span>
      </div>

      <form class="dl-form" id="dlForm" method="get" action="admin-prayer-request.php" title="Download PDF">
        <select name="status" id="statusFilter" aria-label="Choose status to filter/download">
          <?php foreach (['Pending','Done','Declined','All'] as $opt) { $sel = ($currentStatus === $opt) ? 'selected' : ''; echo "<option value=\"{$opt}\" {$sel}>$opt</option>"; } ?>
        </select>

        <!-- Privacy filter -->
        <select name="privacy" id="privacyFilter" aria-label="Choose privacy to filter">
          <?php foreach (['All','Public','Private'] as $opt) { $sel = ($currentPrivacy === $opt) ? 'selected' : ''; echo "<option value=\"{$opt}\" {$sel}>$opt</option>"; } ?>
        </select>

        <button class="btn" type="button" id="btnDownload"><i class="fas fa-file-download"></i> Download PDF</button>
      </form>

      <button class="btn" type="button" onclick="openDoneList()">Done list</button>
      <button class="btn success" type="button" id="btnMarkDoneAll">Mark selected as Done</button>
      <button class="btn primary" type="button" onclick="openAddModal()">Add +</button>
    </div>
  </header>

  <div class="container">
    <section class="panel">
      <div class="panel-head">
        <h2>Online Prayer Requests</h2>
        <div class="muted"></div>
      </div>
      <div class="panel-body">
        <?php
          // Build list query with Status + Privacy + ORDER
          [$where, $types, $params] = build_where_and_params($currentStatus, $currentPrivacy);

          if (empty($where)) {
            $sqlList = "SELECT * FROM prayer_table ".$orderSql;
            $result  = mysqli_query($db_connection, $sqlList);
          } else {
            $sqlList = "SELECT * FROM prayer_table WHERE ".implode(' AND ', $where).$orderSql;
            $stmtList = $db_connection->prepare($sqlList);
            if ($types !== '') { $stmtList->bind_param($types, ...$params); }
            $stmtList->execute();
            $result = $stmtList->get_result();
          }
        ?>
        <div class="table-wrap">
          <table id="prayerTable">
            <thead>
              <tr>
                <th style="width:32px;text-align:center">
                  <input type="checkbox" id="checkAll">
                </th>
                <th data-sort="date">Prayer Date</th>
                <th data-sort="prayer">Prayer</th>
                <th data-sort="privacy">Privacy</th>
                <th>Last</th>
                <th>First</th>
                <th>Middle</th>
                <th>Ext</th>
                <th class="text-right">Action</th>
              </tr>
            </thead>
            <tbody>
            <?php
            if ($result && mysqli_num_rows($result) > 0) {
              while ($row = mysqli_fetch_assoc($result)) {
                $id  = (int)$row['prayer_id'];
                $st  = (string)$row['prayer_status'];
                $np = parseFullName($row['prayer_mem_name'] ?? '');
                $memberFull = composeFullName($np['last'], $np['first'], $np['middle'], $np['ext']); // normalized

                echo "<tr data-id='{$id}' data-member=\"".htmlspecialchars($memberFull,ENT_QUOTES,'UTF-8')."\" data-status=\"".htmlspecialchars(strtolower($st),ENT_QUOTES,'UTF-8')."\">
                        <td style='text-align:center'>
                          <input type='checkbox' class='row-check' data-id='{$id}'>
                        </td>
                        <td>".htmlspecialchars(date('F j, Y', strtotime($row['prayer_date'])))."</td>
                        <td>".htmlspecialchars($row['prayer_request'])."</td>
                        <td>".htmlspecialchars($row['prayer_option'])."</td>
                        <td>".htmlspecialchars($np['last'])."</td>
                        <td>".htmlspecialchars($np['first'])."</td>
                        <td>".htmlspecialchars($np['middle'])."</td>
                        <td>".htmlspecialchars($np['ext'])."</td>
                        <td class='text-right'>
                          <button class='btn success tiny btn-done' data-id='{$id}'>Done</button>
                          <button class='btn danger tiny btn-decline' data-id='{$id}'>Decline</button>
                        </td>
                      </tr>";
              }
            } else {
              echo '<tr><td colspan="9">No data found.</td></tr>';
            }
            if (isset($stmtList) && $stmtList instanceof mysqli_stmt) { $stmtList->close(); }
            ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</div>

<!-- ===== ADD MODAL (with name breakdown) ===== -->
<div id="addModal" class="modal" aria-hidden="true" aria-labelledby="addTitle" role="dialog">
  <div class="modal-dialog" role="document">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h3 id="addTitle">Add Prayer Request</h3>
        <button type="button" class="modal-close" aria-label="Close modal" onclick="closeAddModal()">×</button>
      </div>

      <div class="modal-body">
        <div class="form-grid">
          <div class="field">
            <label class="label">Date <span class="req">*</span></label>
            <input class="control" type="date" name="prayer_date" required>
          </div>

          <div class="field">
            <label class="label">Prayer Type <span class="req">*</span></label>
            <input class="control" type="text" name="prayer_type" placeholder="e.g., Healing, Thanksgiving" required>
          </div>

          <div class="field full">
            <label class="label">Prayer Title <span class="req">*</span></label>
            <input class="control" type="text" name="prayer_request" placeholder="Short title of the request" required>
          </div>

          <div class="field">
            <label class="label">Privacy Option <span class="req">*</span></label>
            <select class="control" name="prayer_option" required>
              <option value="Public">Public</option>
              <option value="Private">Private</option>
            </select>
            <div class="help">Private requests are visible to admins only.</div>
          </div>

          <!-- Name breakdown -->
          <div class="field">
            <label class="label">Last Name <span class="req">*</span></label>
            <input class="control" type="text" name="mem_last" id="mem_last" required>
          </div>
          <div class="field">
            <label class="label">First Name <span class="req">*</span></label>
            <input class="control" type="text" name="mem_first" id="mem_first" required>
          </div>
          <div class="field">
            <label class="label">Middle Name</label>
            <input class="control" type="text" name="mem_middle" id="mem_middle">
          </div>
          <div class="field">
            <label class="label">Ext/Suffix</label>
            <input class="control" type="text" name="mem_ext" id="mem_ext" placeholder="Jr., Sr., III">
          </div>

          <div class="field full">
            <label class="label">Description <span class="help">(optional)</span></label>
            <textarea class="control" name="prayer_description" rows="3" placeholder="Add details to guide the prayer team…"></textarea>
          </div>
        </div>

        <!-- Hidden composed full name for DB -->
        <input type="hidden" name="prayer_mem_name" id="prayer_mem_name">
        <div class="help" id="fullPreview" style="margin-top:6px;color:#374151"></div>
      </div>

      <div class="modal-footer">
        <div class="grow"></div>
        <button type="button" class="btn" onclick="closeAddModal()">Cancel</button>
        <button type="submit" name="add_prayer" class="btn primary">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== DONE LIST MODAL ===== -->
<div id="listModal" class="modal" aria-hidden="true">
  <div class="modal-dialog">
    <div id="listModalContent"></div>
  </div>
</div>

<script>
function openAddModal(){
  const modal=document.getElementById("addModal");
  modal.setAttribute("aria-hidden","false");
  setTimeout(()=>modal.querySelector('input[name="prayer_date"]')?.focus(), 30);
}
function closeAddModal(){ document.getElementById("addModal").setAttribute("aria-hidden","true"); }
document.getElementById('addModal')?.addEventListener('click', (e)=>{ if(e.target.id==='addModal') closeAddModal(); });
document.addEventListener('keydown', (e)=>{ if(e.key==='Escape'){ closeAddModal(); closeListModal(); } });

function openDoneList(){
  const box = document.getElementById('listModal');
  const content = document.getElementById('listModalContent');
  content.innerHTML = '<div class="modal-body">Loading…</div>';
  box.setAttribute('aria-hidden','false');
  fetch('admin-prayer-request.php?view=done_list')
    .then(r => r.text())
    .then(html => content.innerHTML = html)
    .catch(()=> content.innerHTML = '<div class="modal-body">Failed to load.</div>');
}
function closeListModal(){ document.getElementById("listModal").setAttribute("aria-hidden","true"); }

// Compose full name for hidden field + preview
function composeFull(last, first, middle, ext){
  last = (last||'').trim(); first=(first||'').trim(); middle=(middle||'').trim(); ext=(ext||'').trim();
  const firstMid = (first + ' ' + middle).trim();
  const core = last ? `${last}, ${firstMid}` : firstMid;
  return (core + (ext ? ' ' + ext : '')).trim();
}
const lastEl = document.getElementById('mem_last');
const firstEl = document.getElementById('mem_first');
const midEl   = document.getElementById('mem_middle');
const extEl   = document.getElementById('mem_ext');
const fullEl  = document.getElementById('prayer_mem_name');
const prevEl  = document.getElementById('fullPreview');

function syncFull(){
  const full = composeFull(lastEl?.value, firstEl?.value, midEl?.value, extEl?.value);
  if (fullEl) fullEl.value = full;
  if (prevEl) prevEl.textContent = full ? `Full name to be saved: ${full}` : '';
}
[lastEl, firstEl, midEl, extEl].forEach(el=> el && el.addEventListener('input', syncFull));
syncFull();

// Prevent double submit on Add
const formAdd = document.querySelector('#addModal form');
if (formAdd) {
  formAdd.addEventListener('submit', function(){
    syncFull();
    const btn = formAdd.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
  });
}

/* ================= SMART SEARCH (member: reads data-member) ================= */
function ss_norm(s){ return (s||'').toString().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase(); }
function ss_lev(a,b){
  a=ss_norm(a); b=ss_norm(b);
  const m=[]; for(let i=0;i<=b.length;i++){ m[i]=[i]; } for(let j=0;j<=a.length;j++){ m[0][j]=j; }
  for(let i=1;i<=b.length;i++){ for(let j=1;j<=a.length;j++){
    m[i][j]=Math.min(m[i-1][j]+1, m[i][j-1]+1, m[i-1][j-1]+(a[j-1]===b[i-1]?0:1));
  }} return m[b.length][a.length];
}
function ss_parseDateToTs(s){
  if(!s) return null;
  const m=s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if(m){ const d=new Date(+m[1],+m[2]-1,+m[3]); const t=d.getTime(); return isNaN(t)?null:Math.floor(t/1000); }
  const t=Date.parse(s); return isNaN(t)?null:Math.floor(t/1000);
}
function ss_clearHighlights(row){
  row.querySelectorAll('mark.smart-hit').forEach(el=>{
    const p=el.parentNode; p.replaceChild(document.createTextNode(el.textContent), el); p.normalize();
  });
}
function ss_highlightRow(row, needles){
  if(!needles||!needles.length) return;
  const walker=document.createTreeWalker(row, NodeFilter.SHOW_TEXT, {
    acceptNode(node){
      if(!node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
      if(node.parentElement && node.parentElement.tagName==='BUTTON') return NodeFilter.FILTER_REJECT;
      return NodeFilter.FILTER_ACCEPT;
    }
  });
  const nodes=[]; while(walker.nextNode()) nodes.push(walker.currentNode);
  for(const n of nodes){
    const txt=n.nodeValue, low=ss_norm(txt);
    let ranges=[];
    for(const needle of needles){
      const q=ss_norm(needle); if(!q) continue;
      let idx=0; while((idx=low.indexOf(q,idx))!==-1){ ranges.push([idx,idx+q.length]); idx+=q.length; }
    }
    if(!ranges.length) continue;
    ranges.sort((a,b)=>a[0]-b[0]);
    const merged=[]; let [s,e]=ranges[0];
    for(let i=1;i<ranges.length;i++){ const [ns,ne]=ranges[i]; if(ns<=e){ e=Math.max(e,ne); } else { merged.push([s,e]); [s,e]=[ns,ne]; } }
    merged.push([s,e]);
    const frag=document.createDocumentFragment(); let last=0;
    for(const [ms,me] of merged){
      if(last<ms) frag.appendChild(document.createTextNode(txt.slice(last,ms)));
      const mark=document.createElement('mark'); mark.className='smart-hit'; mark.textContent=txt.slice(ms,me);
      frag.appendChild(mark); last=me;
    }
    if(last<txt.length) frag.appendChild(document.createTextNode(txt.slice(last)));
    n.parentNode.replaceChild(frag,n);
  }
}
function ss_parseQuery(q){
  const tokens=[], neg=[], fields={member:[], privacy:[], status:[], date:[], phrase:[]}, fuzzy=[];
  q=q.trim(); if(!q) return {tokens,neg,fields,fuzzy};
  const ph=[]; q=q.replace(/"([^"]+)"/g,(_,p)=>{ ph.push(p.trim()); return ' '; }); fields.phrase.push(...ph);
  const parts=q.split(/\s+/).filter(Boolean);
  for(const part of parts){
    if(/^-/.test(part)){ neg.push(part.slice(1)); continue; }
    const m=part.match(/^(\w+):(.*)$/);
    if(m){
      const k=m[1].toLowerCase(), v=m[2];
      if(k==='member') fields.member.push(v);
      else if(k==='privacy') fields.privacy.push(...v.split('|').map(s=>s.toLowerCase()));
      else if(k==='status') fields.status.push(...v.split('|').map(s=>s.toLowerCase()));
      else if(k==='date') fields.date.push(v);
      else tokens.push(part);
    } else if(part.endsWith('~')){ fuzzy.push(part.slice(0,-1)); }
    else if(part==='~'){ /* ignore */ }
    else { tokens.push(part); }
  }
  return {tokens,neg,fields,fuzzy};
}
function ss_rowDateTs(row){
  const cell=row.cells[1]?.innerText.trim()||''; // date now in col 1 (col 0 is checkbox)
  return ss_parseDateToTs(cell);
}
(function smartSearchInit(){
  const searchBox = document.getElementById('searchBox');
  const tbody = document.querySelector('#prayerTable tbody');
  const rows  = tbody ? [...tbody.querySelectorAll('tr')] : [];
  function ss_smartFilter(){
    if(!searchBox || !rows.length) return;

    rows.forEach(ss_clearHighlights);

    const q = searchBox.value || '';
    const parsed = ss_parseQuery(q);
    const needles = [...parsed.fields.phrase, ...parsed.tokens, ...parsed.fields.member];

    rows.forEach(row=>{
      row.style.display = '';
      const txt = ss_norm(row.innerText);

      if(parsed.fields.member.length){
        const memberCell = ss_norm(row.getAttribute('data-member') || '');
        const ok = parsed.fields.member.some(n => memberCell.includes(ss_norm(n)));
        if(!ok){ row.style.display='none'; return; }
      }

      if(parsed.fields.privacy.length){
        const priv = ss_norm(row.cells[3]?.innerText || ''); // privacy now col 3
        const ok = parsed.fields.privacy.some(p => priv===p || priv.includes(p));
        if(!ok){ row.style.display='none'; return; }
      }

      if(parsed.fields.status.length){
        const statusTxt = ss_norm(row.getAttribute('data-status') || '');
        const ok = parsed.fields.status.some(s => statusTxt===s || statusTxt.includes(s));
        if(!ok){ row.style.display='none'; return; }
      }

      if(parsed.fields.date.length){
        const rowTs = ss_rowDateTs(row);
        let dateOK = false;
        for(const d of parsed.fields.date){
          if(d.includes('..')){
            const [a,b]=d.split('..');
            const ta = ss_parseDateToTs(a), tb = ss_parseDateToTs(b);
            if(rowTs && ta && tb && rowTs>=ta && rowTs<=tb){ dateOK=true; break; }
          } else {
            const t = ss_parseDateToTs(d);
            if(rowTs && t && Math.abs(rowTs - t) < 86400){ dateOK=true; break; }
          }
        }
        if(!dateOK){ row.style.display='none'; return; }
      }

      if(parsed.neg.length){
        const bad = parsed.neg.some(n => txt.includes(ss_norm(n)));
        if(bad){ row.style.display='none'; return; }
      }

      if(parsed.fields.phrase.length){
        const ok = parsed.fields.phrase.every(p => txt.includes(ss_norm(p)));
        if(!ok){ row.style.display='none'; return; }
      }

      if(parsed.tokens.length){
        const ok = parsed.tokens.every(t => txt.includes(ss_norm(t)));
        if(!ok){ row.style.display='none'; return; }
      }

      if(parsed.fuzzy.length){
        const flat = Array.from(row.cells).map(td=>td.innerText).join(' ').slice(0,600);
        const ok = parsed.fuzzy.some(f => {
          if(ss_norm(flat).includes(ss_norm(f))) return true;
          if(f.length>=5) return ss_lev(flat, f) <= 2;
          return false;
        });
        if(!ok){ row.style.display='none'; return; }
      }

      ss_highlightRow(row, needles);
    });
  }
  if (searchBox && rows.length){
    ['input','change','keyup'].forEach(ev=> searchBox.addEventListener(ev, ss_smartFilter));
    window.addEventListener('load', ss_smartFilter);
  }
})();

/* ========= SMART SORT (clickable headers, sync URL for server & PDF) ========= */
(function(){
  const table = document.getElementById('prayerTable');
  if (!table) return;
  const thead = table.tHead;
  const tbody = table.tBodies[0];
  if (!thead || !tbody) return;

  const getUrlSort = () => {
    const url = new URL(window.location.href);
    return {
      sort: (url.searchParams.get('sort') || 'date').toLowerCase(),
      dir:  (url.searchParams.get('dir')  || 'desc').toLowerCase()
    };
  };
  const setUrlSort = (sort, dir, replace=false) => {
    const url = new URL(window.location.href);
    url.searchParams.set('sort', sort);
    url.searchParams.set('dir', dir);
    // Keep current status/privacy so server/PDF match table
    const statusSel  = document.getElementById('statusFilter');
    const privacySel = document.getElementById('privacyFilter');
    if (statusSel)  url.searchParams.set('status',  statusSel.value);
    if (privacySel) url.searchParams.set('privacy', privacySel.value);
    (replace ? history.replaceState : history.pushState).call(history, {}, '', url.toString());
  };

  const cmpText = (a,b) => a.localeCompare(b, undefined, {sensitivity:'base'});
  const parseDate = (txt) => {
    const t = Date.parse(txt); // expects "F j, Y"
    return isNaN(t) ? 0 : t;
  };

  const extract = (row, key) => {
    switch(key){
      case 'date':    return parseDate(row.cells[1]?.innerText.trim() || '');
      case 'prayer':  return (row.cells[2]?.innerText || '').trim().toLowerCase();
      case 'privacy': return (row.cells[3]?.innerText || '').trim().toLowerCase();
      case 'status':  return (row.getAttribute('data-status') || '').trim().toLowerCase();
      default:        return (row.innerText || '').trim().toLowerCase();
    }
  };

  const sortRows = (key, dir) => {
    const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.style.display !== 'none');
    const isDate = (key === 'date');
    rows.sort((ra, rb) => {
      const A = extract(ra, key);
      const B = extract(rb, key);
      let cmp = 0;
      if (isDate) {
        cmp = (A === B ? 0 : (A < B ? -1 : 1));
      } else {
        cmp = cmpText(String(A), String(B));
      }
      return dir === 'asc' ? cmp : -cmp;
    });
    const frag = document.createDocumentFragment();
    rows.forEach(r => frag.appendChild(r));
    tbody.appendChild(frag);
  };

  const clearIndicators = () => {
    thead.querySelectorAll('[data-sort]').forEach(th => {
      th.removeAttribute('aria-sort');
      th.textContent = th.textContent.replace(/\s*[▲▼]\s*$/,'');
    });
  };
  const setIndicator = (th, dir) => {
    th.setAttribute('aria-sort', dir);
    th.textContent = th.textContent.replace(/\s*[▲▼]\s*$/, '') + (dir === 'asc' ? ' ▲' : ' ▼');
  };

  // Initialize indicators from URL (does not resort DOM—server already ordered)
  const {sort: initSort, dir: initDir} = getUrlSort();
  const initTh = thead.querySelector(`[data-sort="${initSort}"]`);
  if (initTh) {
    clearIndicators();
    setIndicator(initTh, initDir);
  }

  // Click-to-sort
  thead.addEventListener('click', (e) => {
    const th = e.target.closest('[data-sort]');
    if (!th) return;
    const key = th.getAttribute('data-sort');
    const current = getUrlSort();
    const nextDir = (current.sort === key) ? (current.dir === 'asc' ? 'desc' : 'asc') : 'asc';

    sortRows(key, nextDir);
    clearIndicators();
    setIndicator(th, nextDir);
    setUrlSort(key, nextDir);
  });
})();

// Status + Privacy filters + PDF (carry sort & filters)
const statusSel   = document.getElementById('statusFilter');
const privacySel  = document.getElementById('privacyFilter');
const btnDownload = document.getElementById('btnDownload');
const dlForm      = document.getElementById('dlForm');

function reloadWithFilters() {
  const url = new URL(window.location.href);
  if (statusSel)  url.searchParams.set('status',  statusSel.value);
  if (privacySel) url.searchParams.set('privacy', privacySel.value);
  url.searchParams.delete('download');
  window.location.href = url.toString();
}

if (statusSel)  { statusSel.addEventListener('change', reloadWithFilters); }
if (privacySel) { privacySel.addEventListener('change', reloadWithFilters); }

if (btnDownload && dlForm) {
  btnDownload.addEventListener('click', () => {
    const url = new URL(window.location.href);
    const sort = url.searchParams.get('sort') || 'date';
    const dir  = url.searchParams.get('dir')  || 'desc';

    const hidDl   = document.createElement('input'); hidDl.type='hidden'; hidDl.name='download'; hidDl.value='prayers_pdf';
    const hidSort = document.createElement('input'); hidSort.type='hidden'; hidSort.name='sort'; hidSort.value=sort;
    const hidDir  = document.createElement('input'); hidDir.type='hidden';  hidDir.name='dir';  hidDir.value=dir;

    dlForm.appendChild(hidDl);
    dlForm.appendChild(hidSort);
    dlForm.appendChild(hidDir);
    dlForm.submit();
  });
}

/* ========= SELECT ALL + BULK MARK DONE ========= */
const checkAll = document.getElementById('checkAll');
function getRowChecks(){
  return Array.from(document.querySelectorAll('.row-check'));
}
if (checkAll) {
  checkAll.addEventListener('change', () => {
    const rows = getRowChecks();
    rows.forEach(cb => { cb.checked = checkAll.checked; });
  });
}

function syncHeaderCheckbox(){
  const rows = getRowChecks();
  if (!rows.length || !checkAll) return;
  const checked = rows.filter(cb => cb.checked).length;
  if (checked === 0){
    checkAll.checked = false;
    checkAll.indeterminate = false;
  } else if (checked === rows.length){
    checkAll.checked = true;
    checkAll.indeterminate = false;
  } else {
    checkAll.checked = false;
    checkAll.indeterminate = true;
  }
}
getRowChecks().forEach(cb => cb.addEventListener('change', syncHeaderCheckbox));

const btnMarkDoneAll = document.getElementById('btnMarkDoneAll');
if (btnMarkDoneAll) {
  btnMarkDoneAll.addEventListener('click', () => {
    const ids = getRowChecks().filter(cb => cb.checked).map(cb => cb.dataset.id);
    if (!ids.length) {
      Swal.fire({
        icon: 'info',
        title: 'No rows selected',
        text: 'Please select at least one prayer request to mark as Done.'
      });
      return;
    }

    Swal.fire({
      icon: 'question',
      title: 'Mark selected as Done?',
      text: `You are about to mark ${ids.length} request(s) as Done.`,
      showCancelButton: true,
      confirmButtonText: 'Yes, mark as Done',
      cancelButtonText: 'Cancel'
    }).then(res => {
      if (!res.isConfirmed) return;

      const params = new URLSearchParams();
      params.append('action', 'bulk_done');
      ids.forEach(id => params.append('ids[]', id));

      fetch('admin-prayer-request.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: params.toString()
      })
        .then(r => r.json())
        .then(data => {
          if (data.ok) {
            Swal.fire({
              icon: 'success',
              title: 'Updated!',
              text: `Marked ${data.updated} of ${data.total} request(s) as Done.`
            }).then(()=> window.location.reload());
          } else {
            Swal.fire({
              icon: 'error',
              title: 'Bulk update failed',
              text: data.msg || 'Something went wrong.'
            });
          }
        })
        .catch(() => {
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Request failed. Please try again.'
          });
        });
    });
  });
}

/* ========= SINGLE ROW STATUS BUTTONS: Done / Declined ========= */
function sendStatusUpdate(id, status) {
  const params = new URLSearchParams();
  params.append('action', 'update_status');
  params.append('id', id);
  params.append('status', status);

  return fetch('admin-prayer-request.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: params.toString()
  }).then(r => r.json());
}

document.querySelectorAll('.btn-done').forEach(btn => {
  btn.addEventListener('click', () => {
    const id = btn.dataset.id;
    Swal.fire({
      icon: 'question',
      title: 'Mark as Done?',
      text: 'This will set the status of this prayer request to Done.',
      showCancelButton: true,
      confirmButtonText: 'Yes, mark as Done',
      cancelButtonText: 'Cancel'
    }).then(res => {
      if (!res.isConfirmed) return;

      sendStatusUpdate(id, 'Done')
        .then(data => {
          if (data.ok) {
            const row = document.querySelector(`tr[data-id="${id}"]`);
            if (row) {
              row.dataset.status = 'done';
            }
            Swal.fire({
              icon: 'success',
              title: 'Updated!',
              text: 'Prayer request has been marked as Done.'
            }).then(()=> window.location.reload());
          } else {
            Swal.fire({
              icon: 'error',
              title: 'Update failed',
              text: data.msg || 'Something went wrong.'
            });
          }
        })
        .catch(() => {
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Request failed. Please try again.'
          });
        });
    });
  });
});

document.querySelectorAll('.btn-decline').forEach(btn => {
  btn.addEventListener('click', () => {
    const id = btn.dataset.id;
    Swal.fire({
      icon: 'question',
      title: 'Decline this request?',
      text: 'This will set the status of this prayer request to Declined.',
      showCancelButton: true,
      confirmButtonText: 'Yes, Decline',
      cancelButtonText: 'Cancel'
    }).then(res => {
      if (!res.isConfirmed) return;

      sendStatusUpdate(id, 'Declined')
        .then(data => {
          if (data.ok) {
            const row = document.querySelector(`tr[data-id="${id}"]`);
            if (row) {
              row.dataset.status = 'declined';
            }
            Swal.fire({
              icon: 'success',
              title: 'Updated!',
              text: 'Prayer request has been marked as Declined.'
            }).then(()=> window.location.reload());
          } else {
            Swal.fire({
              icon: 'error',
              title: 'Update failed',
              text: data.msg || 'Something went wrong.'
            });
          }
        })
        .catch(() => {
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Request failed. Please try again.'
          });
        });
    });
  });
});

/* SweetAlert messages */
<?php if (!empty($_GET['added'])): ?>
Swal.fire({ icon: 'success', title: 'Prayer Request Added!', text: 'New prayer request has been successfully saved.', confirmButtonColor: '#3085d6' });
<?php endif; ?>
<?php if (!empty($errorMessage)): ?>
Swal.fire({ icon: 'error', title: 'Error!', text: <?php echo json_encode($errorMessage); ?> });
<?php endif; ?>
</script>
</body>
</html>
