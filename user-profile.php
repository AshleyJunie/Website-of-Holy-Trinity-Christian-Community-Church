<?php 
/* /HTCCC-SYSTEM/user-profile.php
  Full-page “My Profile” with View, Edit, and Appointment History (now via modal).
  Safe to include directly and to receive AJAX POSTs for:
  - __action=update_profile
  - __action=load_appt_history
  - __action=get_appt_details   <-- fetch a single record's full details
  - __action=get_availability   <-- calendar day availability (church-wide / per-service)
  - __action=request_reschedule <-- calendar-based reschedule request (writes reschedule_date/reschedule_time + sets service_status='Reschedule')
  - __action=request_cancellation <-- (if added later)
  - __action=upload_replacement <-- user re-uploads/replace a specific file field
*/

if (session_status() === PHP_SESSION_NONE) { session_start();

/**
 * notifyAdmins()
 * Copied from baptism form:
 *  - Validates session identity (admin / individual / pastor)
 *  - Builds a normalized display name
 *  - Inserts into notifications
 *  - Inserts notification_recipients rows for all admins
 */
if (!function_exists('notifyAdmins')) {
  function notifyAdmins(PDO $pdo, $formType, $formRecordId, $formSummary) {
    // Validate session identity
    if (!isset($_SESSION) || session_status() !== PHP_SESSION_ACTIVE) {
      return false;
    }
    $allowedUserTypes = ['admin', 'individual', 'pastor'];
    $createdByType = isset($_SESSION['user_type']) ? trim((string)$_SESSION['user_type']) : '';
    $createdById   = isset($_SESSION['user_id'])   ? (int)$_SESSION['user_id'] : 0;
    if (!in_array($createdByType, $allowedUserTypes, true) || $createdById <= 0) {
      return false;
    }

    // Validate args
    $formType = trim((string)$formType);
    if ($formType === '') return false;
    if (!is_numeric($formRecordId)) return false;
    $formRecordId = (int)$formRecordId;
    if ($formRecordId <= 0) return false;
    $formSummary = trim((string)$formSummary);
    if ($formSummary === '') return false;
    if (mb_strlen($formSummary) > 2000) {
      $formSummary = mb_substr($formSummary, 0, 2000);
    }

    // Helper to compose a human name from a row
    $composeFullName = function(array $row, array $hints = []) : string {
      $lower = [];
      foreach ($row as $k => $v) {
        $lower[strtolower($k)] = $v;
      }
      $pick = function(array $cands) use ($lower) {
        foreach ($cands as $c) {
          $lc = strtolower($c);
          if (array_key_exists($lc, $lower) && trim((string)$lower[$lc]) !== '') {
            return trim((string)$lower[$lc]);
          }
        }
        return '';
      };
      $last   = $pick(array_merge($hints['last']   ?? [], ['individual_lastname','lastname','last_name','surname','family_name','admin_lastname','pastor_lastname']));
      $first  = $pick(array_merge($hints['first']  ?? [], ['individual_firstname','firstname','first_name','given_name','admin_firstname','pastor_firstname']));
      $middle = $pick(array_merge($hints['middle'] ?? [], ['individual_middlename','middlename','middle_name']));
      $suf    = $pick(array_merge($hints['suffix'] ?? [], ['individual_extension','extension','suffix']));

      $title = function($s){
        return preg_replace_callback(
          '/\b(\p{L})(\p{L}*)/u',
          fn($m)=>mb_strtoupper($m[1]).mb_strtolower($m[2]),
          (string)$s
        );
      };
      $last=$title($last); $first=$title($first); $middle=$title($middle); $suf=trim((string)$suf);

      $given = trim($first . ($middle!=='' ? ' '.$middle : ''));
      $suffixStr = $suf !== '' ? ' ' . $suf : '';
      if ($last !== '' && $given !== '') return "{$last}, {$given}{$suffixStr}";
      if ($last !== '') return $last . $suffixStr;
      if ($given !== '') return $given . $suffixStr;
      return '';
    };

    // Fetch submitter's display name
    $fetchSubmitterName = function(string $type, int $id) use ($pdo, $composeFullName) : string {
      try {
        if ($type === 'individual') {
          $stmt = $pdo->prepare("SELECT * FROM individual_table WHERE individual_id = :id LIMIT 1");
          $stmt->execute([':id' => $id]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          if ($row) {
            $name = $composeFullName($row, [
              'last'   => ['individual_lastname'],
              'first'  => ['individual_firstname'],
              'middle' => ['individual_middlename'],
              'suffix' => ['individual_extension'],
            ]);
            if ($name !== '') return $name;
          }
        } elseif ($type === 'admin') {
          $stmt = $pdo->prepare("SELECT * FROM admin_table WHERE admin_id = :id LIMIT 1");
          $stmt->execute([':id' => $id]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          if ($row) {
            $name = $composeFullName($row);
            if ($name !== '') return $name;
          }
        } elseif ($type === 'pastor') {
          $stmt = $pdo->prepare("SELECT * FROM pastor_account WHERE Pastor_ID = :id LIMIT 1");
          $stmt->execute([':id' => $id]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          if ($row) {
            $name = $composeFullName($row);
            if ($name !== '') return $name;
          }
        }
      } catch (Throwable $e) { /* fall back */ }
      return ucfirst($type) . " #{$id}";
    };

    $submitterName = $fetchSubmitterName($createdByType, $createdById);
    $title = "New " . ucfirst($formType) . " submission";
    $body  = "Submitter: {$submitterName}\n"
           . "Type: {$formType}\n"
           . "Record ID: {$formRecordId}\n"
           . "Summary: {$formSummary}";

    try {
      $startedTxn = false;
      if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTxn = true;
      }

      // Insert notification
      $stmtInsNotif = $pdo->prepare("
        INSERT INTO notifications (title, body, created_by_type, created_by_id)
        VALUES (:title, :body, :created_by_type, :created_by_id)
      ");
      $stmtInsNotif->execute([
        ':title'           => $title,
        ':body'            => $body,
        ':created_by_type' => $createdByType,
        ':created_by_id'   => $createdById,
      ]);
      $notificationId = (int)$pdo->lastInsertId();
      if ($notificationId <= 0) {
        if ($startedTxn) $pdo->rollBack();
        return false;
      }

      // Fetch admins
      $stmtAdmins = $pdo->prepare("SELECT admin_id FROM admin_table");
      $stmtAdmins->execute();
      $adminIds = $stmtAdmins->fetchAll(PDO::FETCH_COLUMN, 0);

      // Insert recipients
      if ($adminIds && is_array($adminIds)) {
        $stmtInsRec = $pdo->prepare("
          INSERT INTO notification_recipients (notification_id, user_type, user_id, status)
          VALUES (:notification_id, 'admin', :user_id, 'unread')
        ");
        foreach ($adminIds as $aid) {
          $aid = (int)$aid;
          if ($aid <= 0) continue;
          $stmtInsRec->execute([
            ':notification_id' => $notificationId,
            ':user_id'         => $aid,
          ]);
        }
      }

      if ($startedTxn) $pdo->commit();
      return true;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $e2) {}
      }
      return false;
    }
  }
}


 }
require_once $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/db-connection.php';

/* ---------- JSON/AJAX preflight hardening ---------- */
(function () {
  if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['__action']) &&
    in_array($_POST['__action'], [
      'load_appt_history', 'get_appt_details', 'get_availability',
      'request_reschedule', 'request_cancellation', 'update_profile',
      'upload_replacement'
    ], true)
  ) {
    if (function_exists('ini_set')) { @ini_set('display_errors', '0'); }
    if (function_exists('ob_get_level')) { while (@ob_get_level() > 0) { @ob_end_clean(); } }
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
  }
})();

/* ---------- Helpers used by multiple actions ---------- */
$__tables = [
  ['service_baptism','Water Baptism'],
  ['service_dedication','Dedication'],
  ['service_wedding','Wedding'],
  ['service_house','House Blessing'],
  ['service_funeral','Funeral Service'],
  ['service_prayer','Prayer Request'],
];

/* explicit primary keys for the service tables */
$__primaryKeys = [
  'service_baptism'    => 'baptism_id',
  'service_dedication' => 'dedicationId',
  'service_funeral'    => 'funeral_id',
  'service_house'      => 'house_id',
  'service_wedding'    => 'wedding_id',
  // 'service_prayer'  => 'prayer_id',
];

$pick = function(array $row, array $cands, $default=null) {
  foreach ($cands as $c) { if (array_key_exists($c,$row) && $row[$c]!==null && $row[$c]!=='') return $row[$c]; }
  return $default;
};

$table_exists = function(mysqli $db, string $table): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
  if ($st = mysqli_prepare($db, $sql)) {
    mysqli_stmt_bind_param($st, "s", $table);
    mysqli_stmt_execute($st);
    mysqli_stmt_store_result($st);
    $ok = mysqli_stmt_num_rows($st) > 0;
    mysqli_stmt_close($st);
    return $ok;
  }
  return false;
};

$column_exists = function(mysqli $db, string $table, string $col): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
  if ($st = mysqli_prepare($db, $sql)) {
    mysqli_stmt_bind_param($st, "ss", $table, $col);
    mysqli_stmt_execute($st);
    mysqli_stmt_store_result($st);
    $ok = mysqli_stmt_num_rows($st) > 0;
    mysqli_stmt_close($st);
    return $ok;
  }
  return false;
};

/* Returns busy time "spans" on a given Y-m-d date across all service tables. 
   Only rows with service_status = 'Scheduled' are considered busy. */
function __busy_spans_for_date(mysqli $db, array $__tables, string $dateYmd): array {
  $spans = [];
  foreach ($__tables as $pair) {
    $tbl  = $pair[0];
    $sqlCheck = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
    if ($stc = mysqli_prepare($db, $sqlCheck)) {
      mysqli_stmt_bind_param($stc, "s", $tbl);
      mysqli_stmt_execute($stc);
      mysqli_stmt_store_result($stc);
      $exists = mysqli_stmt_num_rows($stc) > 0;
      mysqli_stmt_close($stc);
      if (!$exists) continue;
    } else continue;

    $sql = "SELECT service_time, service_status 
            FROM `{$tbl}` 
            WHERE DATE(service_date) = ?";
    if ($st = mysqli_prepare($db, $sql)) {
      mysqli_stmt_bind_param($st, "s", $dateYmd);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      while ($row = mysqli_fetch_assoc($res)) {
        $status = isset($row['service_status']) ? strtolower((string)$row['service_status']) : '';
        // Only 'scheduled' blocks time slots now
        if ($status !== 'scheduled') continue;

        $rawTime = $row['service_time'] ?? '';
        if ($rawTime === '' || $rawTime === null) continue;
        $t = @strtotime($rawTime); if (!$t) continue;
        $from = date('H:i', $t);
        $to   = date('H:i', strtotime('+30 minutes', $t));
        $spans[] = ['from'=>$from, 'to'=>$to];
      }
      mysqli_stmt_close($st);
    }
  }
  return $spans;
}

function __slot_list(string $open='08:00', string $close='17:00', int $mins=30): array {
  $out=[]; 
  $t = strtotime($open); 
  $end = strtotime($close);
  while ($t < $end) { $from = date('H:i', $t); $to   = date('H:i', strtotime("+{$mins} minutes",$t)); $out[]=['from'=>$from,'to'=>$to]; $t = strtotime("+{$mins} minutes",$t); }
  return $out;
}

function __slot_available(array $slot, array $busy): bool {
  $sfS=strtotime($slot['from']); $stS=strtotime($slot['to']);
  foreach ($busy as $b) { $bfS=strtotime($b['from']); $btS=strtotime($b['to']); if (($sfS < $btS) && ($stS > $bfS)) return false; }
  return true;
}

/* ---------- AJAX: update_profile ---------- */
if (
  $_SERVER['REQUEST_METHOD'] === 'POST' &&
  isset($_POST['__action']) &&
  $_POST['__action'] === 'update_profile'
) {
  if (empty($db_connection) || !isset($_SESSION['individual_id'])) {
    echo json_encode(['ok' => false, 'msg' => 'Not authorized.']); exit;
  }
  $iid = (int) $_SESSION['individual_id'];

  $fields = [
    'individual_lastname','individual_firstname','individual_middlename',
    'individual_username','individual_phone_number','individual_email_address',
    'individual_street','individual_city','individual_zip_code',
  ];
  $setParts = []; $values = []; $types = '';
  foreach ($fields as $f) {
    if (array_key_exists($f, $_POST)) {
      $setParts[] = "$f = ?"; $values[] = trim((string)$_POST[$f]); $types .= 's';
    }
  }
  if (!empty($_POST['individual_password'])) {
    $setParts[] = "individual_password = ?"; $values[] = (string)$_POST['individual_password']; $types .= 's';
  }
  if (!$setParts) { echo json_encode(['ok'=>false,'msg'=>'Nothing to update.']); exit; }

  $sql = "UPDATE individual_table SET ".implode(', ',$setParts)." WHERE individual_id = ?";
  $types .= 'i'; $values[] = $iid;

  try {
    if ($stmt = mysqli_prepare($db_connection, $sql)) {
      mysqli_stmt_bind_param($stmt, $types, ...$values);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_close($stmt);
      if ($stmt2 = mysqli_prepare($db_connection, "SELECT * FROM individual_table WHERE individual_id = ? LIMIT 1")) {
        mysqli_stmt_bind_param($stmt2, "i", $iid);
        mysqli_stmt_execute($stmt2);
        $res2 = mysqli_stmt_get_result($stmt2);
        $fresh = mysqli_fetch_assoc($res2) ?: null;
        mysqli_stmt_close($stmt2);
        echo json_encode(['ok'=>true,'data'=>$fresh]); exit;
      }
      echo json_encode(['ok'=>true,'data'=>null]); exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Database error.']); exit;
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'msg'=>'Database error.']); exit;
  }
}

/* ---------- AJAX: load_appt_history ---------- */
if (
  $_SERVER['REQUEST_METHOD'] === 'POST' &&
  isset($_POST['__action']) &&
  $_POST['__action'] === 'load_appt_history'
) {
  if (empty($db_connection) || !isset($_SESSION['individual_id'])) {
    echo json_encode(['ok'=>false,'msg'=>'Not authorized.']); exit;
  }
  $iid = (int) $_SESSION['individual_id'];

  $rows = [];
  foreach ($__tables as [$tbl,$label]) {
    if (!$table_exists($db_connection, $tbl)) continue;
    if ($stmt = mysqli_prepare($db_connection, "SELECT * FROM `$tbl` WHERE individual_id = ?")) {
      mysqli_stmt_bind_param($stmt, "i", $iid);
      mysqli_stmt_execute($stmt);
      $res = mysqli_stmt_get_result($stmt);
      while ($r = mysqli_fetch_assoc($res)) {
        if (isset($__primaryKeys[$tbl]) && isset($r[$__primaryKeys[$tbl]])) {
          $ref = $r[$__primaryKeys[$tbl]];
        } else {
          $ref = $pick($r, ['appointment_id','id','service_id', "{$tbl}_id"]);
        }
        if ($ref === null || $ref === '') continue;

        $status = isset($r['service_status']) && $r['service_status'] !== ''
          ? (string)$r['service_status']
          : 'Pending';
        $serviceDate = isset($r['service_date']) && $r['service_date'] !== '' ? $r['service_date'] : null;
        $serviceTime = isset($r['service_time']) && $r['service_time'] !== '' ? $r['service_time'] : null;

        $dateRaw = $serviceDate ?? $pick($r, [
          'appointment_date','scheduled_date','sched_date','service_date',
          'created_at','date_created','request_date','submitted_at','timestamp','createdAt','updated_at'
        ]);

        if ($serviceDate || $serviceTime) {
          $dtStr = trim(($serviceDate ?? '') . ' ' . ($serviceTime ?? ''));
          $ts = @strtotime($dtStr);
          if (!$ts) $ts = $dateRaw ? @strtotime($dateRaw) : 0;
        } else {
          $ts = $dateRaw ? @strtotime($dateRaw) : 0;
        }

        $rows[] = [
          'service'=>$label,
          'table'=>$tbl,
          'ref'=>$ref,
          'status'=>$status,
          'date'=>$dateRaw,
          'ts'=>$ts,
          // Per-record reschedule count so disabling only affects this specific service row
          'reschedule_count'=> isset($r['reschedule_count']) ? (int)$r['reschedule_count'] : 0
        ];
      }
      mysqli_stmt_close($stmt);
    }
  }
  usort($rows, fn($a,$b)=>($b['ts'] <=> $a['ts']));
  echo json_encode(['ok'=>true,'data'=>$rows]); exit;
}

/* ---------- AJAX: get_appt_details ---------- */
if (
  $_SERVER['REQUEST_METHOD'] === 'POST' &&
  isset($_POST['__action']) &&
  $_POST['__action'] === 'get_appt_details'
) {
  if (empty($db_connection) || !isset($_SESSION['individual_id'])) {
    echo json_encode(['ok'=>false,'msg'=>'Not authorized.']); exit;
  }
  $iid = (int) $_SESSION['individual_id'];
  $tbl = isset($_POST['table']) ? (string)$_POST['table'] : '';
  $ref = isset($_POST['ref']) ? (string)$_POST['ref'] : '';

  $allowedTables = array_map(fn($t) => $t[0], $__tables);
  if (!in_array($tbl, $allowedTables, true)) { echo json_encode(['ok'=>false,'msg'=>'Invalid table.']); exit; }
  if ($ref === '') { echo json_encode(['ok'=>false,'msg'=>'Missing reference.']); exit; }

  $idCols = [];
  if (isset($__primaryKeys[$tbl])) $idCols[] = $__primaryKeys[$tbl];
  $idCols = array_merge($idCols, ['appointment_id','id','service_id', $tbl.'_id']);
  $idCols = array_values(array_unique($idCols));

  $found = null;
  foreach ($idCols as $col) {
    $colCheck = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    if ($stc = mysqli_prepare($db_connection, $colCheck)) {
      mysqli_stmt_bind_param($stc, "ss", $tbl, $col);
      mysqli_stmt_execute($stc);
      mysqli_stmt_store_result($stc);
      $colExists = mysqli_stmt_num_rows($stc) > 0;
      mysqli_stmt_close($stc);
      if (!$colExists) continue;
    }

    $sql = "SELECT * FROM `$tbl` WHERE `$col` = ? AND `individual_id` = ? LIMIT 1";
    if ($st = mysqli_prepare($db_connection, $sql)) {
      mysqli_stmt_bind_param($st, "si", $ref, $iid);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $row = mysqli_fetch_assoc($res) ?: null;
      mysqli_stmt_close($st);
      if ($row) { $found = $row; break; }
    }
  }

  if ($found === null) { echo json_encode(['ok'=>false,'msg'=>'Record not found.']); exit; }

  $systemCols = [
    'individual_id','status','appointment_status','archive_status',
    'appointment_id','id','service_id', $tbl.'_id',
    'created_at','updated_at','request_date','submitted_at','timestamp','createdAt',
    'sched_date','scheduled_date',
    'service_status','service_date','service_time'
  ];
  $filtered = [];
  foreach ($found as $k=>$v) {
    if (in_array($k, $systemCols, true)) continue;
    if ($v === null || $v === '') continue;
    $filtered[$k] = $v;
  }
  echo json_encode(['ok'=>true,'data'=>$filtered]); exit;
}

/* ---------- AJAX: get_availability ---------- */
if (
  $_SERVER['REQUEST_METHOD'] === 'POST' &&
  isset($_POST['__action']) &&
  $_POST['__action'] === 'get_availability'
) {
  if (empty($db_connection)) { echo json_encode(['ok'=>false,'msg'=>'Database not available.']); exit; }

  $date = isset($_POST['date']) ? trim((string)$_POST['date']) : '';
  if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) {
    echo json_encode(['ok'=>false,'msg'=>'Invalid or missing date.']); exit;
  }
  $today = date('Y-m-d');
  if ($date < $today) {
    echo json_encode(['ok'=>false,'msg'=>'Past dates are not allowed.']); exit;
  }

  // Optional: service table for per-service weekday & capacity rules
  $serviceTable = isset($_POST['table']) ? trim((string)$_POST['table']) : '';
  $allowedTables = array_map(fn($t) => $t[0], $__tables);
  if ($serviceTable !== '' && !in_array($serviceTable, $allowedTables, true)) {
    // Unknown service table -> treat as generic (no special rules)
    $serviceTable = '';
  }

  // Generate full-day slot list up front
  $slots  = __slot_list('08:00','17:00',30);
  $dateTs = strtotime($date);
  $weekday = (int)date('w', $dateTs); // 0 = Sunday

  // per-service weekday rules
  $allowedWeekdays = [0,1,2,3,4,5,6]; // default: all days allowed
  if ($serviceTable === 'service_dedication') {
    $allowedWeekdays = [0]; // Sunday only
  } elseif (in_array($serviceTable, ['service_wedding','service_funeral','service_house'], true)) {
    $allowedWeekdays = [1,2,3,4,5,6]; // Monday–Saturday
  }

  if (!in_array($weekday, $allowedWeekdays, true)) {
    echo json_encode([
      'ok'   => true,
      'data' => [
        'date'       => $date,
        'available'  => [],
        'total'      => count($slots),
        'busy'       => count($slots),
        'day_status' => 'not_applicable',
        'note'       => 'This service is not available on this day of the week.'
      ]
    ]);
    exit;
  }

  // Helper to count scheduled rows in a table on this date
  $countScheduled = function(string $tblName) use ($db_connection, $date): int {
    // table exists?
    $tblExists = false;
    if ($stc = mysqli_prepare($db_connection,
      "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    )) {
      mysqli_stmt_bind_param($stc, "s", $tblName);
      mysqli_stmt_execute($stc);
      mysqli_stmt_store_result($stc);
      $tblExists = mysqli_stmt_num_rows($stc) > 0;
      mysqli_stmt_close($stc);
    }
    if (!$tblExists) return 0;

    // service_date + service_status columns exist?
    $colSql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? 
               AND COLUMN_NAME IN ('service_date','service_status')";
    $colsFound = 0;
    if ($stCols = mysqli_prepare($db_connection, $colSql)) {
      mysqli_stmt_bind_param($stCols, "s", $tblName);
      mysqli_stmt_execute($stCols);
      mysqli_stmt_store_result($stCols);
      $colsFound = mysqli_stmt_num_rows($stCols);
      mysqli_stmt_close($stCols);
    }
    if ($colsFound < 2) return 0;

    $cnt = 0;
    $sqlCnt = "SELECT COUNT(*) AS c FROM `{$tblName}` 
               WHERE LOWER(service_status) = 'scheduled' 
               AND DATE(service_date) = ?";
    if ($stCnt = mysqli_prepare($db_connection, $sqlCnt)) {
      mysqli_stmt_bind_param($stCnt, "s", $date);
      mysqli_stmt_execute($stCnt);
      $resCnt = mysqli_stmt_get_result($stCnt);
      if ($rowCnt = mysqli_fetch_assoc($resCnt)) {
        $cnt = (int)$rowCnt['c'];
      }
      mysqli_stmt_close($stCnt);
    }
    return $cnt;
  };

  /* CASE 1: No specific table -> global occupied if ANY service scheduled */
  if ($serviceTable === '') {
    $dayFullyBooked = false;
    foreach ($__tables as $pair) {
      $tblName = $pair[0];
      if ($countScheduled($tblName) > 0) {
        $dayFullyBooked = true;
        break;
      }
    }

    if ($dayFullyBooked) {
      echo json_encode([
        'ok'   => true,
        'data' => [
          'date'       => $date,
          'available'  => [],
          'total'      => count($slots),
          'busy'       => count($slots),
          'day_status' => 'occupied',
          'note'       => 'This date is already fully scheduled. Please select another date.'
        ]
      ]);
      exit;
    }

    // Not fully booked — use normal time-slot conflicts
    $busy   = __busy_spans_for_date($db_connection, $__tables, $date);
    $avail  = [];
    foreach ($slots as $s) { if (__slot_available($s, $busy)) $avail[] = $s; }

    $resp = [
      'date'       => $date,
      'available'  => $avail,
      'total'      => count($slots),
      'busy'       => count($slots) - count($avail),
      'day_status' => $avail ? 'open' : 'occupied'
    ];
    if (!$avail) {
      $resp['note'] = 'This date is fully booked. Please select another date.';
    }

    echo json_encode(['ok'=>true,'data'=>$resp]); 
    exit;
  }

  /* CASE 2: Dedication -> capacity 4/day */
  if ($serviceTable === 'service_dedication') {
    $capacity   = 4;
    $used       = $countScheduled('service_dedication');
    $remaining  = max(0, $capacity - $used);

    if ($remaining <= 0) {
      echo json_encode([
        'ok'   => true,
        'data' => [
          'date'       => $date,
          'available'  => [],
          'total'      => count($slots),
          'busy'       => count($slots),
          'day_status' => 'occupied',
          'capacity'   => $capacity,
          'used'       => $used,
          'remaining'  => 0,
          'note'       => 'Dedication is full (4/4) for this date.'
        ]
      ]);
      exit;
    }

    $busy   = __busy_spans_for_date($db_connection, $__tables, $date);
    $avail  = [];
    foreach ($slots as $s) { if (__slot_available($s, $busy)) $avail[] = $s; }

    $resp = [
      'date'       => $date,
      'available'  => $avail,
      'total'      => count($slots),
      'busy'       => count($slots) - count($avail),
      'day_status' => $avail ? 'open' : 'occupied',
      'capacity'   => $capacity,
      'used'       => $used,
      'remaining'  => $remaining,
      'note'       => $avail
        ? "{$remaining}/{$capacity} slots left for Dedication."
        : 'This date is fully booked by time conflicts.'
    ];
    echo json_encode(['ok'=>true,'data'=>$resp]);
    exit;
  }

  /* CASE 3: Wedding/House/Funeral trio share occupancy */
  if (in_array($serviceTable, ['service_wedding','service_house','service_funeral'], true)) {
    $trioTables = ['service_wedding','service_house','service_funeral'];
    $trioCount  = 0;
    foreach ($trioTables as $t) { $trioCount += $countScheduled($t); }

    if ($trioCount > 0) {
      echo json_encode([
        'ok'   => true,
        'data' => [
          'date'       => $date,
          'available'  => [],
          'total'      => count($slots),
          'busy'       => count($slots),
          'day_status' => 'occupied',
          'note'       => 'This date already has a scheduled Wedding/House Blessing/Funeral.'
        ]
      ]);
      exit;
    }

    // No trio booking yet -> use time-slot logic
    $busy   = __busy_spans_for_date($db_connection, $__tables, $date);
    $avail  = [];
    foreach ($slots as $s) { if (__slot_available($s, $busy)) $avail[] = $s; }

    $resp = [
      'date'       => $date,
      'available'  => $avail,
      'total'      => count($slots),
      'busy'       => count($slots) - count($avail),
      'day_status' => $avail ? 'open' : 'occupied'
    ];
    if (!$avail) { $resp['note'] = 'No available time slots for this date.'; }
    echo json_encode(['ok'=>true,'data'=>$resp]); 
    exit;
  }

  /* CASE 4: Others -> plain time-slot conflicts */
  $busy   = __busy_spans_for_date($db_connection, $__tables, $date);
  $avail  = [];
  foreach ($slots as $s) { if (__slot_available($s, $busy)) $avail[] = $s; }

  $resp = [
    'date'       => $date,
    'available'  => $avail,
    'total'      => count($slots),
    'busy'       => count($slots) - count($avail),
    'day_status' => $avail ? 'open' : 'occupied'
  ];
  if (!$avail) {
    $resp['note'] = 'This date is fully booked. Please select another date.';
  }

  echo json_encode(['ok'=>true,'data'=>$resp]); 
  exit;
}

/* ---------- AJAX: request_reschedule ---------- */
if (
  $_SERVER['REQUEST_METHOD'] === 'POST' &&
  isset($_POST['__action']) &&
  $_POST['__action'] === 'request_reschedule'
) {
  if (empty($db_connection) || !isset($_SESSION['individual_id'])) {
    echo json_encode(['ok'=>false,'msg'=>'Not authorized.']); exit;
  }
  $iid  = (int) $_SESSION['individual_id'];
  $tbl  = isset($_POST['table']) ? trim((string)$_POST['table']) : '';
  $ref  = isset($_POST['ref'])   ? trim((string)$_POST['ref'])   : '';
  $date = isset($_POST['date'])  ? trim((string)$_POST['date'])  : '';
  $from = isset($_POST['from'])  ? trim((string)$_POST['from'])  : '';
  $to   = isset($_POST['to'])    ? trim((string)$_POST['to'])    : '';
  $reason = isset($_POST['reason']) ? trim((string)$_POST['reason']) : '';

  $allowedTables = array_map(fn($t) => $t[0], $__tables);
  if ($tbl==='' || !in_array($tbl,$allowedTables,true)) { echo json_encode(['ok'=>false,'msg'=>'Invalid table.']); exit; }
  if ($ref==='')  { echo json_encode(['ok'=>false,'msg'=>'Missing reference.']); exit; }
  if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) { echo json_encode(['ok'=>false,'msg'=>'Invalid date.']); exit; }
  if (!$from || !preg_match('/^\d{2}:\d{2}$/',$from)) { echo json_encode(['ok'=>false,'msg'=>'Invalid start time.']); exit; }
  if (!$to || !preg_match('/^\d{2}:\d{2}$/',$to)) { echo json_encode(['ok'=>false,'msg'=>'Invalid end time.']); exit; }
  if ($date < date('Y-m-d')) { echo json_encode(['ok'=>false,'msg'=>'Selected date is in the past.']); exit; }

  // Resolve primary key column name for this table
  $idCols = [];
  if (isset($__primaryKeys[$tbl])) $idCols[] = $__primaryKeys[$tbl];
  $idCols = array_merge($idCols, ['appointment_id','id','service_id', $tbl.'_id']);
  $idCols = array_values(array_unique($idCols));

  $idColUsed = null;
  foreach ($idCols as $c) {
    $ck = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    if ($stc = mysqli_prepare($db_connection, $ck)) {
      mysqli_stmt_bind_param($stc, "ss", $tbl, $c);
      mysqli_stmt_execute($stc);
      mysqli_stmt_store_result($stc);
      $colExists = mysqli_stmt_num_rows($stc) > 0;
      mysqli_stmt_close($stc);
      if ($colExists) { $idColUsed = $c; break; }
    }
  }

  // Verify target columns exist (assumed present in all service tables)
  $colsNeeded = ['reschedule_date','reschedule_time','service_status','individual_id','reason','service_date','reschedule_count'];
  $colsOk = true;
  foreach ($colsNeeded as $c) {
    $ck = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    if ($stx = mysqli_prepare($db_connection, $ck)) {
      mysqli_stmt_bind_param($stx, "ss", $tbl, $c);
      mysqli_stmt_execute($stx);
      mysqli_stmt_store_result($stx);
      $exists = mysqli_stmt_num_rows($stx) > 0;
      mysqli_stmt_close($stx);
      if (!$exists) { $colsOk = false; }
    } else { $colsOk = false; }
  }

  // Fetch current service_date and reschedule_count for this record
  $currentServiceDate = null;
  $currentReschedCount = 0;
  if ($idColUsed && $colsOk) {
    $sqlChk = "SELECT `service_date`, `reschedule_count` FROM `$tbl` WHERE `$idColUsed` = ? AND `individual_id` = ? LIMIT 1";
    if ($stChk = mysqli_prepare($db_connection, $sqlChk)) {
      mysqli_stmt_bind_param($stChk, "si", $ref, $iid);
      mysqli_stmt_execute($stChk);
      $resChk = mysqli_stmt_get_result($stChk);
      if ($rowChk = mysqli_fetch_assoc($resChk)) {
        if (isset($rowChk['service_date'])) {
          // Normalize to YYYY-MM-DD (in case field is DATETIME)
          $currentServiceDate = substr((string)$rowChk['service_date'], 0, 10);
        }
        if (isset($rowChk['reschedule_count'])) {
          $currentReschedCount = (int)$rowChk['reschedule_count'];
        }
      }
      mysqli_stmt_close($stChk);
    }
  }

  // Business rule 1: only allow reschedule if service_date is MORE than 2 days away
  if ($currentServiceDate) {
    $today = date('Y-m-d');
    $limit = date('Y-m-d', strtotime('+2 days'));
    if ($currentServiceDate <= $limit) {
      echo json_encode(['ok'=>false,'msg'=>'Reschedule is not allowed within 2 days of the service date.']); exit;
    }
  }

  // Business rule 2: only allow a single reschedule per service record
  if ($currentReschedCount >= 1) {
    echo json_encode(['ok'=>false,'msg'=>'You have already requested a reschedule for this service.']); exit;
  }

  // Check availability for the NEW date/time
  $busy   = __busy_spans_for_date($db_connection, $__tables, $date);
  $slot   = ['from'=>$from,'to'=>$to];
  if (!__slot_available($slot, $busy)) {
    echo json_encode(['ok'=>false,'msg'=>'That slot was just taken. Please pick another.']); exit;
  }

  $updOk = false;
  $timeRange = $from; // store as "HH:MM" (24-hour) for now

  if ($idColUsed && $colsOk) {
    $sqlUpd = "UPDATE `$tbl`
               SET `reschedule_date` = ?,
                   `reschedule_time` = ?,
                   `service_status`  = 'Reschedule',
                   `reason`          = ?,
                   `reschedule_count` = COALESCE(`reschedule_count`,0) + 1
               WHERE `$idColUsed` = ? AND `individual_id` = ?
               LIMIT 1";
    if ($stUpd = mysqli_prepare($db_connection, $sqlUpd)) {
      mysqli_stmt_bind_param($stUpd, "ssssi", $date, $timeRange, $reason, $ref, $iid);
      $updOk = mysqli_stmt_execute($stUpd);
      // If no rows were affected, treat that as a failure (e.g. guard conditions)
      if ($updOk && mysqli_stmt_affected_rows($stUpd) < 1) {
        $updOk = false;
      }
      mysqli_stmt_close($stUpd);
    }
  }

  // Notify admins about this reschedule
  if ($updOk && function_exists('notifyAdmins')) {
    // Ensure generic session identity is available for notification helper
    if (empty($_SESSION['user_type'])) $_SESSION['user_type'] = 'individual';
    if (empty($_SESSION['user_id']))   $_SESSION['user_id']   = (int)$iid;

    try {
      $pdoNotif = new PDO(
        "mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4",
        "root",
        "",
        [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]
      );

      // Derive a human label for the service table
      $serviceLabel = $tbl;
      foreach ($__tables as $pair) {
        if ($pair[0] === $tbl) { $serviceLabel = $pair[1]; break; }
      }

      $formType = 'service rescheduled';
      $parts = [];
      $parts[] = 'Service: ' . $serviceLabel;
      $parts[] = 'Table: ' . $tbl;
      $parts[] = 'Record ID: ' . $ref;
      $parts[] = 'New date: ' . $date;
      $parts[] = 'New time: ' . $timeRange;
      if ($reason !== '') {
        $parts[] = 'Reason: ' . $reason;
      }
      $summary = implode(" | ", $parts);

      notifyAdmins($pdoNotif, $formType, $ref, $summary);
    } catch (Throwable $e) {
      // Swallow notification errors; main update already succeeded or failed
    }
  }

  if ($updOk) {
    $newReschedCount = $currentReschedCount + 1;
    echo json_encode(['ok'=>true,'msg'=>'Reschedule requested','data'=>[
      'reschedule_date'=>$date,
      'reschedule_time'=>$timeRange,
      'service_status'=>'Reschedule',
      'reschedule_count'=>$newReschedCount
    ]]); 
  } else {
    echo json_encode(['ok'=>false,'msg'=>'Unable to save your reschedule request. Please try again.']);
  }
  exit;
}
/* ---------- AJAX: request_cancellation ---------- */
if (
  $_SERVER['REQUEST_METHOD'] === 'POST' &&
  isset($_POST['__action']) &&
  $_POST['__action'] === 'request_cancellation'
) {
  if (empty($db_connection) || !isset($_SESSION['individual_id'])) {
    echo json_encode(['ok'=>false,'msg'=>'Not authorized.']); exit;
  }

  $iid    = (int) $_SESSION['individual_id'];
  $tbl    = isset($_POST['table'])  ? trim((string)$_POST['table'])  : '';
  $ref    = isset($_POST['ref'])    ? trim((string)$_POST['ref'])    : '';
  $reason = isset($_POST['reason']) ? trim((string)$_POST['reason']) : '';

  $allowedTables = array_map(fn($t) => $t[0], $__tables);
  if ($tbl === '' || !in_array($tbl, $allowedTables, true)) {
    echo json_encode(['ok'=>false,'msg'=>'Invalid table.']); exit;
  }
  if ($ref === '') {
    echo json_encode(['ok'=>false,'msg'=>'Missing reference.']); exit;
  }
  if ($reason === '') {
    echo json_encode(['ok'=>false,'msg'=>'Please provide a reason for cancellation.']); exit;
  }

  // Resolve primary key column name for this table
  $idCols = [];
  if (isset($__primaryKeys[$tbl])) $idCols[] = $__primaryKeys[$tbl];
  $idCols = array_merge($idCols, ['appointment_id','id','service_id', $tbl.'_id']);
  $idCols = array_values(array_unique($idCols));

  $idColUsed = null;
  foreach ($idCols as $c) {
    $ck = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    if ($stc = mysqli_prepare($db_connection, $ck)) {
      mysqli_stmt_bind_param($stc, "ss", $tbl, $c);
      mysqli_stmt_execute($stc);
      mysqli_stmt_store_result($stc);
      $colExists = mysqli_stmt_num_rows($stc) > 0;
      mysqli_stmt_close($stc);
      if ($colExists) { $idColUsed = $c; break; }
    }
  }

  if ($idColUsed === null) {
    echo json_encode(['ok'=>false,'msg'=>'Unable to determine primary key for this service.']); exit;
  }

  // Verify required columns exist
  $colsNeeded = ['reason','service_status','individual_id'];
  $colsOk = true;
  foreach ($colsNeeded as $c) {
    $ck = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    if ($stx = mysqli_prepare($db_connection, $ck)) {
      mysqli_stmt_bind_param($stx, "ss", $tbl, $c);
      mysqli_stmt_execute($stx);
      mysqli_stmt_store_result($stx);
      $exists = mysqli_stmt_num_rows($stx) > 0;
      mysqli_stmt_close($stx);
      if (!$exists) { $colsOk = false; }
    } else { $colsOk = false; }
  }

  if (!$colsOk) {
    echo json_encode(['ok'=>false,'msg'=>'Required columns were not found for this service.']); exit;
  }

  // Confirm record belongs to this individual and is in an allowed status
  $currentStatus = null;
  if ($idColUsed && $colsOk) {
    $sqlChk = "SELECT `service_status` FROM `$tbl` WHERE `$idColUsed` = ? AND `individual_id` = ? LIMIT 1";
    if ($stChk = mysqli_prepare($db_connection, $sqlChk)) {
      mysqli_stmt_bind_param($stChk, "si", $ref, $iid);
      mysqli_stmt_execute($stChk);
      $resChk = mysqli_stmt_get_result($stChk);
      if ($rowChk = mysqli_fetch_assoc($resChk)) {
        $currentStatus = isset($rowChk['service_status']) ? (string)$rowChk['service_status'] : null;
      }
      mysqli_stmt_close($stChk);
    }
  }

  if ($currentStatus === null) {
    echo json_encode(['ok'=>false,'msg'=>'Record not found.']); exit;
  }

  $normalizedStatus = strtolower($currentStatus);
  if ($normalizedStatus !== 'scheduled' && $normalizedStatus !== 'pending') {
    echo json_encode(['ok'=>false,'msg'=>'Cancellation is only allowed for Scheduled or Pending services.']); exit;
  }

  // Update record: store reason & mark status as ReqCancel
  $sqlUpd = "UPDATE `$tbl` 
             SET `reason` = ?, `service_status` = 'ReqCancel' 
             WHERE `$idColUsed` = ? AND `individual_id` = ? 
             LIMIT 1";
  $updOk = false;
  if ($stUpd = mysqli_prepare($db_connection, $sqlUpd)) {
    mysqli_stmt_bind_param($stUpd, "ssi", $reason, $ref, $iid);
    $updOk = mysqli_stmt_execute($stUpd);
    if ($updOk && mysqli_stmt_affected_rows($stUpd) < 1) {
      $updOk = false;
    }
    mysqli_stmt_close($stUpd);
  }

  // Notify admins about this cancellation request
  if ($updOk && function_exists('notifyAdmins')) {
    if (empty($_SESSION['user_type'])) $_SESSION['user_type'] = 'individual';
    if (empty($_SESSION['user_id']))   $_SESSION['user_id']   = (int)$iid;

    try {
      $pdoNotif = new PDO(
        "mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4",
        "root",
        "",
        [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]
      );

      // Derive a human label for the service table
      $serviceLabel = $tbl;
      foreach ($__tables as $pair) {
        if ($pair[0] === $tbl) { $serviceLabel = $pair[1]; break; }
      }

      $formType = 'service cancellation requested';
      $parts = [];
      $parts[] = 'Service: ' . $serviceLabel;
      $parts[] = 'Table: ' . $tbl;
      $parts[] = 'Record ID: ' . $ref;
      $parts[] = 'Status: ReqCancel';
      if ($reason !== '') {
        $parts[] = 'Reason: ' . $reason;
      }
      $summary = implode(" | ", $parts);

      notifyAdmins($pdoNotif, $formType, $ref, $summary);
    } catch (Throwable $e) {
      // Fail silently for notification errors
    }
  }

  if ($updOk) {
    echo json_encode(['ok'=>true,'msg'=>'Cancellation request submitted.']); 
  } else {
    echo json_encode(['ok'=>false,'msg'=>'Failed to submit cancellation request.']);
  }
  exit;
}

/* ---------- AJAX: upload_replacement (REUPLOAD) ---------- */
if (
  $_SERVER['REQUEST_METHOD'] === 'POST' &&
  isset($_POST['__action']) &&
  $_POST['__action'] === 'upload_replacement'
) {
  if (empty($db_connection) || !isset($_SESSION['individual_id'])) {
    echo json_encode(['ok'=>false,'msg'=>'Not authorized.']); exit;
  }
  $iid  = (int) $_SESSION['individual_id'];
  $tbl  = isset($_POST['table']) ? trim((string)$_POST['table']) : '';
  $ref  = isset($_POST['ref'])   ? trim((string)$_POST['ref'])   : '';
  $col  = isset($_POST['column'])? trim((string)$_POST['column']): '';

  $allowedTables = array_map(fn($t) => $t[0], $__tables);
  if ($tbl==='' || !in_array($tbl,$allowedTables,true)) { echo json_encode(['ok'=>false,'msg'=>'Invalid table.']); exit; }
  if ($ref==='')  { echo json_encode(['ok'=>false,'msg'=>'Missing reference.']); exit; }
  if ($col==='')  { echo json_encode(['ok'=>false,'msg'=>'Missing target field.']); exit; }

  // Resolve primary key column for this table
  $idCols = [];
  if (isset($__primaryKeys[$tbl])) $idCols[] = $__primaryKeys[$tbl];
  $idCols = array_merge($idCols, ['appointment_id','id','service_id', $tbl.'_id']);
  $idCols = array_values(array_unique($idCols));

  $idColUsed = null;
  foreach ($idCols as $c) {
    $ck = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    if ($stc = mysqli_prepare($db_connection, $ck)) {
      mysqli_stmt_bind_param($stc, "ss", $tbl, $c);
      mysqli_stmt_execute($stc);
      mysqli_stmt_store_result($stc);
      $colExists = mysqli_stmt_num_rows($stc) > 0;
      mysqli_stmt_close($stc);
      if ($colExists) { $idColUsed = $c; break; }
    }
  }
  if (!$idColUsed) { echo json_encode(['ok'=>false,'msg'=>'Could not resolve record id.']); exit; }

  // Ensure target column exists
  $ck2 = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
  if ($stx = mysqli_prepare($db_connection, $ck2)) {
    mysqli_stmt_bind_param($stx, "ss", $tbl, $col);
    mysqli_stmt_execute($stx);
    mysqli_stmt_store_result($stx);
    $colExists = mysqli_stmt_num_rows($stx) > 0;
    mysqli_stmt_close($stx);
    if (!$colExists) { echo json_encode(['ok'=>false,'msg'=>'Selected field does not exist.']); exit; }
  }

  if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    echo json_encode(['ok'=>false,'msg'=>'No file uploaded.']); exit;
  }

  $file = $_FILES['file'];
  $maxBytes = 15 * 1024 * 1024; // 15 MB
  if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
    echo json_encode(['ok'=>false,'msg'=>'File too large (max 15MB).']); exit;
  }

  $allowedExt = ['pdf','png','jpg','jpeg','webp','heic','gif'];
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, $allowedExt, true)) {
    echo json_encode(['ok'=>false,'msg'=>'Invalid file type.']); exit;
  }

  $baseDir = $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/uploads/reuploads';
  if (!is_dir($baseDir)) @mkdir($baseDir, 0775, true);

  $safeName = preg_replace('/[^a-zA-Z0-9._-]/','_', $file['name']);
  $newName  = date('Ymd_His').'_'.$iid.'_'.$safeName;
  $absPath  = rtrim($baseDir,'/').'/'.$newName;
  $relPath  = '/HTCCC-SYSTEM/uploads/reuploads/'.$newName;

  if (!@move_uploaded_file($file['tmp_name'], $absPath)) {
    echo json_encode(['ok'=>false,'msg'=>'Failed to save file.']); exit;
  }

  // Update the table with the new path
  if ($tbl === 'service_baptism' && $col === 'img_baptismal_cert') {
    // For baptism certificate uploads, also move verification back to Pending
    $sql = "UPDATE `$tbl` SET `$col` = ?, `baptism_verification` = 'Pending' WHERE `$idColUsed` = ? AND `individual_id` = ? LIMIT 1";
  } else {
    $sql = "UPDATE `$tbl` SET `$col` = ? WHERE `$idColUsed` = ? AND `individual_id` = ? LIMIT 1";
  }

  if ($st = mysqli_prepare($db_connection, $sql)) {
    mysqli_stmt_bind_param($st, "sii", $relPath, $ref, $iid);
    $ok = mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
    echo json_encode(['ok'=>$ok, 'path'=>$relPath]); exit;
  }

echo json_encode(['ok'=>false,'msg'=>'Database error.']); exit;
}


/* ---------- AJAX: upload_baptism_profile (BAPTISM VERIFICATION FILE) ---------- */
if (
  $_SERVER['REQUEST_METHOD'] === 'POST' &&
  isset($_POST['__action']) &&
  $_POST['__action'] === 'upload_baptism_profile'
) {
  if (empty($db_connection) || !isset($_SESSION['individual_id'])) {
    echo json_encode(['ok'=>false,'msg'=>'Not authorized.']); 
    exit;
  }

  $iid = (int) $_SESSION['individual_id'];

  if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    echo json_encode(['ok'=>false,'msg'=>'No file uploaded.']); 
    exit;
  }

  $file = $_FILES['file'];
  $maxBytes = 15 * 1024 * 1024; // 15 MB
  if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
    echo json_encode(['ok'=>false,'msg'=>'File too large (max 15MB).']); 
    exit;
  }

  $allowedExt = ['pdf','png','jpg','jpeg','webp','heic','gif'];
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, $allowedExt, true)) {
    echo json_encode(['ok'=>false,'msg'=>'Invalid file type.']); 
    exit;
  }

  $baseDir = $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/uploads/baptism_profile';
  if (!is_dir($baseDir)) {
    @mkdir($baseDir, 0775, true);
  }

  $safeName = preg_replace('/[^a-zA-Z0-9._-]/','_', $file['name']);
  $newName  = date('Ymd_His').'_'.$iid.'_'.$safeName;
  $absPath  = rtrim($baseDir,'/').'/'.$newName;
  $relPath  = '/HTCCC-SYSTEM/uploads/baptism_profile/'.$newName;

  if (!@move_uploaded_file($file['tmp_name'], $absPath)) {
    echo json_encode(['ok'=>false,'msg'=>'Failed to save file.']); 
    exit;
  }

  // Update baptism_verification status on the individual record
  if ($st = mysqli_prepare($db_connection, "UPDATE individual_table SET baptism_verification = 'Pending' WHERE individual_id = ? LIMIT 1")) {
    mysqli_stmt_bind_param($st, "i", $iid);
    $ok = mysqli_stmt_execute($st);
    mysqli_stmt_close($st);

    if ($ok) {
      echo json_encode(['ok'=>true,'msg'=>'Baptismal certificate uploaded. Verification status set to Pending.','path'=>$relPath]); 
      exit;
    }
  }

  echo json_encode(['ok'=>false,'msg'=>'Database error while updating verification status.']); 
  exit;
}

/* ---------- Guard + data for initial render ---------- */
$loggedIn = isset($_SESSION['individual_id']);
$__profileData = null;
$__fullName = 'My Account';

if ($loggedIn && !empty($db_connection)) {
  $iid = (int) $_SESSION['individual_id'];

  if ($st = mysqli_prepare($db_connection, "SELECT * FROM individual_table WHERE individual_id = ? LIMIT 1")) {
    mysqli_stmt_bind_param($st, "i", $iid);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $__profileData = mysqli_fetch_assoc($res) ?: null;
    mysqli_stmt_close($st);
  }

  if ($st = mysqli_prepare($db_connection, "SELECT individual_firstname, individual_middlename, individual_lastname, individual_extensionname FROM individual_table WHERE individual_id = ? LIMIT 1")) {
    mysqli_stmt_bind_param($st, "i", $iid);
    mysqli_stmt_execute($st);
    mysqli_stmt_bind_result($st, $fn, $mn, $ln, $ex);
    if (mysqli_stmt_fetch($st)) {
      $parts = array_filter([$fn, $mn, $ln, $ex], fn($v)=>trim($v??'')!=='');
      $__fullName = implode(' ', $parts) ?: ($__profileData['individual_username'] ?? 'My Account');
    }
    mysqli_stmt_close($st);
  }


  // --- Ministry membership(s) for header UI ---
  $__ministryMemberships = [];
  if ($st = mysqli_prepare($db_connection, "SELECT ministry_type, ministry_position FROM ministries_table WHERE individual_id = ?")) {
    mysqli_stmt_bind_param($st, "i", $iid);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    if ($res) {
      while ($row = mysqli_fetch_assoc($res)) {
        // Defensive trim; only keep meaningful rows
        $t = trim($row['ministry_type'] ?? '');
        $p = trim($row['ministry_position'] ?? '');
        if ($t !== '' || $p !== '') {
          $__ministryMemberships[] = ['ministry_type' => $t, 'ministry_position' => $p];
        }
      }
    }
    mysqli_stmt_close($st);
  }

} else {
  header("Location: /HTCCC-SYSTEM/all_log-in.php");
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>My Profile</title>
  <link rel="icon" href="/HTCCC-SYSTEM/image/main-logo.png">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    :root { --brand:#001B3A; }

    body {
      margin:0;
      font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
      background:linear-gradient(135deg,#f8fafc,#e2e8f0);
      color:#0f172a;
    }
    .shell { max-width:1000px; margin:24px auto 64px; padding:0 16px; }

    .topbar {
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      margin-bottom:16px;
    }
    .title { font-size:24px; margin:0; font-weight:700; letter-spacing:.01em; }

    .actions { display:flex; gap:8px; align-items:center; }

    .btn {
      background:var(--brand);
      color:#fff;
      border:none;
      padding:10px 14px;
      border-radius:999px;
      cursor:pointer;
      font-size:13px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:6px;
      transition:background .15s ease, transform .1s ease, box-shadow .15s ease, border-color .15s ease;
      box-shadow:0 8px 20px rgba(15,23,42,.18);
      text-decoration:none;
    }
    .btn:hover { background:#002855; transform:translateY(-1px); box-shadow:0 10px 26px rgba(15,23,42,.22); }
    .btn:active { transform:translateY(0); box-shadow:0 4px 12px rgba(15,23,42,.18); }

    .btn.secondary {
      background:#e2e8f0;
      color:#0f172a;
      box-shadow:none;
    }
    .btn.secondary:hover { background:#cbd5e1; }

    .btn.ghost {
      background:#e2e8f0;
      color:#0f172a;
      border:1px solid rgba(148,163,184,.40);
      box-shadow:none;
    }
    .btn.ghost:hover {
      background:#cbd5e1;
      border-color:rgba(37,99,235,.4);
    }

    .btn.small { padding:6px 10px; border-radius:999px; font-size:12px; box-shadow:none; }

    .card {
      background:#ffffff;
      border-radius:16px;
      border:1px solid rgba(148,163,184,.28);
      box-shadow:0 18px 45px rgba(15,23,42,.08);
      overflow:hidden;
    }
    .card header {
      display:flex;
      align-items:center;
      justify-content:space-between;
      padding:16px 18px;
      border-bottom:1px solid #eef2f7;
    }
    .card .body { padding:16px 18px; }

    .grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }

    .field label {
      display:block;
      font-size:11px;
      text-transform:uppercase;
      letter-spacing:.08em;
      color:#64748b;
      margin:0 0 6px;
      font-weight:600;
    }
    .readonly,
    .field input[type="text"],
    .field input[type="email"],
    .field input[type="password"] {
      background:#ffffff;
      border:1px solid #cbd5e1;
      border-radius:12px;
      padding:11px 12px;
      min-height:42px;
      font-size:13px;
      display:flex;
      align-items:center;
      box-shadow:0 1px 2px rgba(148,163,184,.20) inset;
    }
    .readonly { background:#f8fafc; }

    .muted { color:#64748b; }

    .footer {
      display:flex;
      justify-content:flex-end;
      gap:10px;
      padding:16px 18px;
      border-top:1px solid #eef2f7;
    }

    .tab {
      border:none;
      background:#e2e8f0;
      border-radius:999px;
      padding:8px 14px;
      cursor:pointer;
      font-size:13px;
      color:#475569;
      display:inline-flex;
      align-items:center;
      gap:6px;
      transition:background .15s ease,color .15s ease, box-shadow .15s ease;
    }
    .tab.active {
      background:#0f172a;
      color:#e5eeff;
      box-shadow:0 10px 25px rgba(15,23,42,.35);
    }

    .table-wrap {
      border-radius:14px;
      overflow:auto;
      max-height:60vh;
      border:1px solid #e5e7eb;
      background:#ffffff;
    }
    table { width:100%; border-collapse:separate; border-spacing:0; font-size:14px; }
    thead th {
      position:sticky;
      top:0;
      background:#f9fafb;
      text-align:left;
      padding:10px 12px;
      border-bottom:1px solid #e5e7eb;
      font-size:12px;
      text-transform:uppercase;
      letter-spacing:.08em;
      color:#64748b;
      z-index:1;
    }
    tbody td {
      padding:10px 12px;
      border-bottom:1px solid #f1f5f9;
      vertical-align:middle;
      font-size:13px;
    }
    tbody tr:last-child td { border-bottom:none; }
    tbody tr:hover td {
      background:#f8fafc;
    }

    .chip {
      display:inline-flex;
      align-items:center;
      gap:4px;
      padding:2px 10px;
      border-radius:999px;
      font-size:11px;
      border:1px solid #e2e8f0;
      font-weight:600;
      text-transform:uppercase;
      letter-spacing:.07em;
    }
    .chip-ministry { background:#f1f5ff; border-color:#c7d2fe; color:#1e3a8a; }
    .chip.Pending { background:#fff7ed; border-color:#fed7aa; color:#9a3412; }
    .chip.Scheduled { background:#ecfdf5; border-color:#a7f3d0; color:#166534; }
    .chip.Done { background:#eef2ff; border-color:#c7d2fe; color:#3730a3; }
    .chip.Cancelled { background:#fef2f2; border-color:#fecaca; color:#b91c1c; }
    .chip.Reupload { background:#f1f5f9; border-color:#e2e8f0; color:#0f172a; }
    .chip.Reschedule { background:#e0f2fe; border-color:#bae6fd; color:#075985; }
    .chip.Archived { background:#f1f5f9; border-color:#e2e8f0; color:#475569; }

    .modal {
      display:none;
      position:fixed;
      inset:0;
      background:rgba(15,23,42,.72);
      z-index:1000;
      backdrop-filter:blur(6px);
    }
    .modal .panel {
      position:absolute;
      inset:auto 0 0 0;
      margin:auto;
      top:5%;
      width:min(1000px, 92%);
      background:#ffffff;
      border-radius:18px;
      box-shadow:0 28px 80px rgba(15,23,42,.55);
      overflow:hidden;
      max-height:90vh;
      display:flex;
      flex-direction:column;
    }
    .modal header {
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:8px;
      padding:14px 16px;
      border-bottom:1px solid #eef2f7;
      background:linear-gradient(135deg,#0f172a,#020617);
      color:#e5eeff;
    }
    .modal header h3 { margin:0; font-size:18px; font-weight:600; }
    .modal .body {
      padding:14px 16px;
      overflow:auto;
      background:#f8fafc;
    }
    .modal .actions-right {
      display:flex;
      gap:8px;
      align-items:center;
    }

    .cal-wrap { display:grid; gap:12px; }
    .cal-head { display:flex; justify-content:space-between; align-items:center; gap:8px; }
    .cal-nav { display:flex; gap:6px; align-items:center; }
    .cal-btn {
      border:1px solid #cbd5e1;
      background:#ffffff;
      padding:6px 10px;
      border-radius:999px;
      cursor:pointer;
      font-size:12px;
    }
    .cal-btn:hover { background:#eff6ff; }

    .cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:6px; }
    .cal-cell {
      border:1px solid #e5e7eb;
      padding:6px 0 8px;
      border-radius:10px;
      text-align:center;
      background:#ffffff;
      cursor:pointer;
      font-size:13px;
      transition:background .12s ease, transform .1s ease, border-color .12s ease;
      position:relative;
      display:flex;
      flex-direction:column;
      align-items:center;
      justify-content:center;
      min-height:46px;
    }
    .cal-cell:hover { background:#eff6ff; transform:translateY(-1px); }
    .cal-cell.disabled { color:#94a3b8; background:#f8fafc; cursor:not-allowed; transform:none; }

    .cal-cell.selected { outline:2px solid var(--brand); font-weight:700; }

    /* occupied style: red card + pill badge */
    .cal-cell.occupied {
      background:#fee2e2;
      border-color:#fecaca;
      color:#b91c1c;
      cursor:not-allowed;
      transform:none;
    }

    .cal-dow { color:#64748b; font-size:12px; text-align:center; }

    .cal-date-num {
      font-size:13px;
      font-weight:600;
    }

    .cal-badge {
      margin-top:4px;
      display:none;
      align-items:center;
      justify-content:center;
      padding:2px 10px;
      border-radius:999px;
      font-size:10px;
      text-transform:uppercase;
      letter-spacing:.06em;
      background:#ffe4e6;
      color:#b91c1c;
      border:1px solid #fecaca;
      font-weight:700;
    }
    .cal-cell.occupied .cal-badge {
      display:inline-flex;
    }

    .slots { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:8px; margin-top:4px; }
    .slot {
      border:1px solid #cbd5e1;
      border-radius:10px;
      padding:8px;
      text-align:center;
      cursor:pointer;
      background:#ffffff;
      font-size:13px;
      transition:background .12s ease,border-color .12s ease,transform .1s ease;
    }
    .slot:hover { background:#eff6ff; transform:translateY(-1px); }
    .slot.taken { background:#f1f5f9; color:#94a3b8; cursor:not-allowed; }
    .slot.selected { outline:2px solid #1d4ed8; border-color:#1d4ed8; }

    .empty { color:#64748b; font-size:13px; text-align:center; padding:8px; }

    /* --- NEW: time picker styles (12-hour) --- */
    .time-picker-row {
      display:flex;
      gap:8px;
      align-items:center;
      flex-wrap:wrap;
      margin-top:8px;
    }
    .time-select {
      border-radius:12px;
      border:1px solid #cbd5e1;
      padding:8px 10px;
      min-width:70px;
      font-size:13px;
      background:#020617;
      color:#e5eeff;
    }

    @media (max-width:768px) {
      .grid { grid-template-columns:1fr; }
      .slots { grid-template-columns:repeat(2,minmax(0,1fr)); }
      .modal .panel { width:96%; top:3%; }
    }
  </style>
</head>
<body>
  <?php /* optional header include */ ?>

  <div class="shell">
    <div class="topbar">
      <h1 class="title">My Profile</h1>
      <div class="actions">
        <button id="tabProfile"  class="tab active" type="button">Profile</button>
        <button id="tabHistory"  class="tab" type="button">History</button>
        <a class="btn secondary" href="/HTCCC-SYSTEM/main-page.php">Back</a>
      </div>
    </div>

    <!-- PROFILE VIEW -->
    <section id="profileView" class="card">
      <header>
        <div>
          <div style="font-weight:600;font-size:16px;"><?php echo htmlspecialchars($__fullName, ENT_QUOTES, 'UTF-8'); ?></div>
          <?php if (!empty($__ministryMemberships)): ?>
            <div style="margin-top:6px; display:flex; flex-wrap:wrap; gap:6px;">
              <?php foreach ($__ministryMemberships as $__m): ?>
                <?php
                  $__labelParts = array_filter([
                    trim($__m['ministry_type'] ?? ''),
                    trim($__m['ministry_position'] ?? ''),
                  ], fn($v)=>$v!=='');
                  $__label = implode(' • ', $__labelParts);
                ?>
                <?php if ($__label !== ''): ?>
                  <span class="chip chip-ministry"><?php echo htmlspecialchars($__label, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="muted" style="font-size:13px;">Individual</div>
        </div>
      </header>
      <div class="body">
        <?php if ($__profileData): ?>
          <?php
            $__addressView = trim(($__profileData['individual_street'] ?? '') .
                                  (empty($__profileData['individual_street']) ? '' : ', ') . ($__profileData['individual_city'] ?? '') .
                                  (empty($__profileData['individual_city']) ? '' : ', ') . ($__profileData['individual_zip_code'] ?? ''));
          ?>
          <div class="card" style="margin-bottom:14px;">
            <header><h3 style="margin:0;font-size:16px;">Basic Information</h3></header>
            <div class="body grid">
              <div class="field"><label>First Name</label><div class="readonly"><?php echo htmlspecialchars($__profileData['individual_firstname'] ?? '—'); ?></div></div>
              <div class="field"><label>Middle Name</label><div class="readonly"><?php echo htmlspecialchars($__profileData['individual_middlename'] ?? '—'); ?></div></div>
              <div class="field"><label>Last Name</label><div class="readonly"><?php echo htmlspecialchars($__profileData['individual_lastname'] ?? '—'); ?></div></div>
              <div class="field"><label>Suffix</label><div class="readonly"><?php echo htmlspecialchars($__profileData['individual_extensionname'] ?? '—'); ?></div></div>
              <div class="field"><label>Username</label><div class="readonly"><?php echo htmlspecialchars($__profileData['individual_username'] ?? '—'); ?></div></div>
              <div class="field"><label>Gender</label><div class="readonly"><?php echo htmlspecialchars($__profileData['individual_gender'] ?? '—'); ?></div></div>
              <div class="field"><label>Birthday</label><div class="readonly"><?php echo htmlspecialchars($__profileData['individual_birthday'] ?? '—'); ?></div></div>
            </div>
          </div>

          <div class="card" style="margin-bottom:14px;">
            <header><h3 style="margin:0;font-size:16px;">Contact</h3></header>
            <div class="body grid">
              <div class="field"><label>Phone</label><div class="readonly"><?php echo htmlspecialchars($__profileData['individual_phone_number'] ?? '—'); ?></div></div>
              <div class="field"><label>Email</label><div class="readonly"><?php echo htmlspecialchars($__profileData['individual_email_address'] ?? '—'); ?></div></div>
            </div>
          </div>

          <div class="card" style="margin-bottom:14px;">
            <header><h3 style="margin:0;font-size:16px;">Baptismal Account</h3></header>
            <div class="body">
              <div class="field">
                <label>Baptismal Account Status</label>
                <div class="readonly" id="baptismStatusText">
                  <?php
                    $bv = $__profileData['baptism_verification'] ?? 'NonVerified';
                    if ($bv === 'Verified') {
                      echo 'Verified';
                    } elseif ($bv === 'Pending') {
                      echo 'Pending';
                    } elseif ($bv === 'Reupload') {
                      echo 'Reupload';
                    } else {
                      echo 'Unverified';
                    }
                  ?>
                </div>
              </div>
              <?php
                $bvLower = strtolower((string)($bv ?? 'NonVerified'));
                if ($bvLower === 'nonverified' || $bvLower === 'pending' || $bvLower === 'reupload') :
              ?>
              <div class="field">
                <label style="visibility:hidden;">&nbsp;</label>
                <div>
                  <button type="button" class="btn small" id="btnUploadBaptismCert">
                    Upload Baptismal Certificate
                  </button>
                  <p class="muted" style="margin:6px 0 0;font-size:12px;">
                    Accepted files: PDF or image (max 15MB).
                  </p>
                </div>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="card">
            <header><h3 style="margin:0;font-size:16px;">Address</h3></header>
            <div class="body">
              <div class="field">
                <label>Full Address</label>
                <div class="readonly"><?php echo htmlspecialchars($__addressView ?: '—'); ?></div>
              </div>
            </div>
          </div>
          </div>
        <?php else: ?>
          <p class="muted">Profile not found.</p>
        <?php endif; ?>
      </div>
    </section>

    <!-- HISTORY MODAL -->
    <div id="historyModal" class="modal" aria-hidden="true" role="dialog" aria-label="Appointment history">
      <div class="panel">
        <header>
          <h3>Appointment History</h3>
          <div class="actions-right">
            <input id="apptSearch" type="text" placeholder="Search service or status…" class="readonly" style="background:#fff;min-width:160px;">
            <select id="apptStatusFilter" class="readonly" style="background:#fff;">
              <option value="">All statuses</option>
              <option>Pending</option>
              <option>Scheduled</option>
              <option>Done</option>
              <option>Cancelled</option>
              <option>Reupload</option>
              <option>Reschedule</option>
            </select>
            <select id="apptSort" class="readonly" style="background:#fff;">
              <option value="newest">Newest first</option>
              <option value="oldest">Oldest first</option>
              <option value="service">Service A–Z</option>
              <option value="status">Status A–Z</option>
            </select>
            <button id="refreshApptsBtn" class="btn small" type="button">Refresh</button>
            <button id="closeHistoryBtn" class="btn secondary small" type="button">Close</button>
          </div>
        </header>
        <div class="body">
          <div class="table-wrap">
            <table aria-label="Appointment history">
              <thead><tr><th>Date</th><th>Service</th><th>Status</th><th style="text-align:right;">Action</th></tr></thead>
              <tbody id="apptTbody"><tr><td colspan="4" class="muted">No data yet.</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

  </div>

<script>
  // Tabs & Modal controls
  const tabProfile = document.getElementById('tabProfile');
  const tabHistory = document.getElementById('tabHistory');
  const profileView = document.getElementById('profileView');

  const historyModal = document.getElementById('historyModal');
  const closeHistoryBtn = document.getElementById('closeHistoryBtn');

  function openHistoryModal(){
    historyModal.style.display='block';
    historyModal.setAttribute('aria-hidden','false');
    loadHistory();
  }
  function closeHistoryModal(){
    historyModal.style.display='none';
    historyModal.setAttribute('aria-hidden','true');
  }

  function activate(tab){
    tabProfile.classList.toggle('active', tab==='profile');
    tabHistory.classList.toggle('active', tab==='history');
    if (tab==='profile') { profileView.style.display = 'block'; }
    if (tab==='history') { openHistoryModal(); }
  }
  tabProfile.addEventListener('click', ()=> activate('profile'));
  tabHistory.addEventListener('click', ()=> activate('history'));
  closeHistoryBtn.addEventListener('click', ()=> { closeHistoryModal(); tabProfile.click(); });
  historyModal.addEventListener('click', (e)=>{ if(e.target === historyModal){ closeHistoryModal(); tabProfile.click(); } });

  // Appointment history
  const tbody = document.getElementById('apptTbody');
  const search = document.getElementById('apptSearch');
  const statusFilter = document.getElementById('apptStatusFilter');
  const sortSel = document.getElementById('apptSort');
  const btnRefresh = document.getElementById('refreshApptsBtn');
  let __rows = [];

  btnRefresh && btnRefresh.addEventListener('click', loadHistory);
  search && search.addEventListener('input', render);
  statusFilter && statusFilter.addEventListener('change', render);
  sortSel && sortSel.addEventListener('change', render);

  async function loadHistory(){
    const fd = new FormData(); fd.append('__action','load_appt_history');
    try {
      tbody.innerHTML = `<tr><td colspan="4" class="muted">Loading…</td></tr>`;
      const res = await fetch(location.href, {
        method:'POST',
        body:fd,
        headers:{'X-Requested-With':'fetch'},
        credentials:'same-origin',
        cache:'no-store'
      });
      const ct = res.headers.get('content-type') || '';
      const data = ct.includes('application/json') ? await res.json() : {ok:false,msg:'Unexpected response'};
      if (data.ok) {
        __rows = Array.isArray(data.data)? data.data : [];
        render();
      } else {
        tbody.innerHTML = `<tr><td colspan="4" class="muted">Failed to load: ${escapeHtml(data.msg||'Unknown error')}</td></tr>`;
      }
    } catch(e) {
      tbody.innerHTML = `<tr><td colspan="4" class="muted">Network error. Please try again.</td></tr>`;
      console.error(e);
    }
  }

  function render(){
    const q = (search && search.value || '').toLowerCase().trim();
    const f = (statusFilter && statusFilter.value || '').toLowerCase().trim();
    let list = __rows.slice();
    list = list.filter(r=>{
      const service=(r.service||'').toLowerCase();
      const status=(r.status||'').toLowerCase();
      const matchQ=!q || service.includes(q) || status.includes(q);
      const matchF=!f || status===f;
      return matchQ && matchF;
    });

    const sortBy = sortSel ? sortSel.value : 'newest';
    if (sortBy==='newest') list.sort((a,b)=>(b.ts||0)-(a.ts||0));
    else if (sortBy==='oldest') list.sort((a,b)=>(a.ts||0)-(b.ts||0));
    else if (sortBy==='service') list.sort((a,b)=> String(a.service||'').localeCompare(String(b.service||'')));
    else if (sortBy==='status') list.sort((a,b)=> String(a.status||'').localeCompare(String(b.status||'')));

    if (!list.length) {
      tbody.innerHTML = `<tr><td colspan="4" class="muted">No appointments found.</td></tr>`;
      return;
    }

    tbody.innerHTML = list.map(r=>{
      const d = r.date ? safeFormatDate(r.date) : '—';
      const st = escapeHtml(r.status || '—');
      const sv = escapeHtml(r.service || '—');

      const viewBtn = `
        <button
          class="btn ghost small viewBtn"
          type="button"
          style="box-shadow:none;float:right;"
          data-table="${escapeAttr(r.table||'')}"
          data-ref="${escapeAttr(String(r.ref||''))}"
        >
          View
        </button>`;

      return `<tr>
        <td>${d}</td>
        <td>${sv}</td>
        <td><span class="chip ${st}">${st}</span></td>
        <td style="text-align:right;">${viewBtn}</td>
      </tr>`;
    }).join('');
  }

  // Row actions – ONLY "View" in the table; all other actions are inside the view modal.
  tbody && tbody.addEventListener('click', async (e)=>{
    const view = e.target.closest('.viewBtn');
    if (!view) return;

    const tr = (e.target.closest('tr'));
    const tds = tr ? tr.querySelectorAll('td') : [];
    const dateText = tds[0] ? tds[0].textContent.trim() : '';
    const serviceText = tds[1] ? tds[1].textContent.trim() : '';
    const statusChip = tds[2] ? tds[2].querySelector('.chip') : null;
    const statusText = statusChip ? statusChip.textContent.trim() : (tds[2] ? tds[2].textContent.trim() : '');

    let row = (__rows || []).find(r =>
      (safeFormatDate(r.date || '') === dateText) &&
      (String(r.service || '') === serviceText) &&
      (String(r.status || '') === statusText)
    );
    if (!row) {
      await Swal.fire({icon:'error', title:'Not found', text:'Unable to match this record in your history.'});
      return;
    }

    // Load service-specific fields in advance
    let details = {};
    try {
      const fd = new FormData();
      fd.append('__action','get_appt_details');
      fd.append('table', row.table || '');
      fd.append('ref',   String(row.ref || ''));
      const res0 = await fetch(location.href, {
        method:'POST', body:fd, headers:{'X-Requested-With':'fetch'}, credentials:'same-origin', cache:'no-store'
      });
      const ct = res0.headers.get('content-type') || '';
      const data = ct.includes('application/json') ? await res0.json() : {ok:false,msg:'Unexpected response'};
      if (!data.ok) throw new Error(data.msg||'Load failed');
      details = data.data || {};
    } catch(err) {
      console.error(err);
      await Swal.fire({icon:'error', title:'Failed to load details', text:String(err||'Please try again.')});
      return;
    }
    row = Object.assign({}, row, {details});

    const html = buildReviewHtml(row);
    await Swal.fire({
      title: 'Review Your Information',
      html,
      width: 900,
      showConfirmButton: false,
      customClass:{
        popup:'swal2-modal-custom'
      },
      didOpen: () => attachDetailActions(row)
    });
  });

  function buildReviewHtml(row){
    const svcLabel = String(row.service || '');
    const svcTable = String(row.table || '');
    const status   = String(row.status || '');
    const d = row.details || {};

    const rawDate = row.date || d.service_date || d.appointment_date || d.scheduled_date || d.sched_date || '';
    const apptDate = rawDate ? safeFormatDateOnly(rawDate) : '—';
    const timeFrom = d.time_from || d.start_time || d.from_time || d.timeslot_from || '';
    const timeTo   = d.time_to   || d.end_time   || d.to_time   || d.timeslot_to   || '';
    const svcTime  = d.service_time || d.appointment_time || d.time || '';
    let timeStr = '—';
    if (svcTime) timeStr = svcTime;
    else if (timeFrom || timeTo) { timeStr = escapeHtml(timeFrom || '') + (timeTo ? (' - ' + escapeHtml(timeTo)) : ''); }

    const statusChip = status ? `<span class="chip ${escapeHtml(status)}" style="margin-left:8px;vertical-align:middle;">${escapeHtml(status)}</span>` : '';

    const appointment = `
      <div style="background:radial-gradient(circle at top,#1e293b,#020617); color:#e5eeff; border-radius:14px; padding:16px; border:1px solid rgba(148,163,184,.45);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px; gap:12px;">
          <div>
            <div style="font-weight:700; font-size:15px;">${escapeHtml(svcLabel)}${statusChip}</div>
            <div style="font-size:12px; opacity:.85; margin-top:2px;">Reference: ${escapeHtml(String(row.ref||'—'))}</div>
          </div>
        </div>
        <div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; font-size:13px;">
          <div><div style="opacity:.7; font-size:12px;">Date</div><div style="font-weight:700;">${escapeHtml(apptDate)}</div></div>
          <div><div style="opacity:.7; font-size:12px;">Time</div><div style="font-weight:700;">${timeStr}</div></div>
          <div><div style="opacity:.7; font-size:12px;">Status</div><div style="font-weight:700;">${escapeHtml(status || '—')}</div></div>
        </div>
      </div>
    `;

    const get = (obj, keys, fallback='—') => { 
      for (const k of keys){ 
        if (obj[k] !== undefined && obj[k] !== null && String(obj[k]).trim()!=='') return obj[k]; 
      } 
      return fallback; 
    };

    let bodySections = '';

    // SERVICE-SPECIFIC SECTIONS (names + key fields)

    if (svcTable === 'service_dedication') {
      const cfirst = get(d, ['child_firstname']);
      const cmid   = get(d, ['child_middlename']);
      const clast  = get(d, ['child_lastname']);
      const cext   = get(d, ['child_ext']);
      const gfirst = get(d, ['guardian_firstname']);
      const gmid   = get(d, ['guardian_middlename']);
      const glast  = get(d, ['guardian_lastname']);
      const gext   = get(d, ['guardian_ext']);
      const guardianContact = d.guardian_contact || '—';
      bodySections += `
        <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; margin-top:14px;">
          <div class="card"><header><h4 style="margin:0;font-size:14px;">Child Information</h4></header>
            <div class="body">
              <div class="field"><label>First</label><div class="readonly">${escapeHtml(cfirst)}</div></div>
              <div class="field"><label>Middle</label><div class="readonly">${escapeHtml(cmid)}</div></div>
              <div class="field"><label>Last</label><div class="readonly">${escapeHtml(clast)}</div></div>
              <div class="field"><label>Suffix</label><div class="readonly">${escapeHtml(cext)}</div></div>
            </div></div>
          <div class="card"><header><h4 style="margin:0;font-size:14px;">Guardian Information</h4></header>
            <div class="body">
              <div class="field"><label>First</label><div class="readonly">${escapeHtml(gfirst)}</div></div>
              <div class="field"><label>Middle</label><div class="readonly">${escapeHtml(gmid)}</div></div>
              <div class="field"><label>Last</label><div class="readonly">${escapeHtml(glast)}</div></div>
              <div class="field"><label>Suffix</label><div class="readonly">${escapeHtml(gext)}</div></div>
              <div class="field"><label>Contact Number</label><div class="readonly">${escapeHtml(guardianContact)}</div></div>
            </div></div>
        </div>`;
    } else if (svcTable === 'service_funeral') {
      const f = (n)=>get(d,[n]);
      const funeralDateRaw = d.service_date || d.funeral_date || '';
      bodySections += `
        <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; margin-top:14px;">
          <div class="card"><header><h4 style="margin:0;font-size:14px;">Deceased Information</h4></header>
            <div class="body">
              <div class="field"><label>First</label><div class="readonly">${escapeHtml(f('deceased_firstname'))}</div></div>
              <div class="field"><label>Middle</label><div class="readonly">${escapeHtml(f('deceased_middlename'))}</div></div>
              <div class="field"><label>Last</label><div class="readonly">${escapeHtml(f('deceased_lastname'))}</div></div>
              <div class="field"><label>Suffix</label><div class="readonly">${escapeHtml(f('deceased_ext'))}</div></div>
              <div class="field"><label>Birthdate</label><div class="readonly">${escapeHtml(d.deceased_birthdate ? safeFormatDateOnly(d.deceased_birthdate) : '—')}</div></div>
              <div class="field"><label>Home Address</label><div class="readonly">${escapeHtml(d.home_address || '—')}</div></div>
            </div></div>
          <div class="card"><header><h4 style="margin:0;font-size:14px;">Service Details</h4></header>
            <div class="body">
              <div class="field"><label>Funeral Date</label><div class="readonly">${escapeHtml(funeralDateRaw ? safeFormatDateOnly(funeralDateRaw) : '—')}</div></div>
              <div class="field"><label>Death Certificate</label><div class="readonly">${fileLinkHtml(d.death_certificate || '', 'View Death Certificate')}</div></div>
              <div class="field"><label>Remarks</label><div class="readonly">${escapeHtml(get(d, ['remarks','special_request','message','notes'], '—'))}</div></div>
            </div></div>
        </div>`;
    } else if (svcTable === 'service_house') {
      const f = (n)=>get(d,[n]);
      bodySections += `
        <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; margin-top:14px;">
          <div class="card"><header><h4 style="margin:0;font-size:14px;">Owner Information</h4></header>
            <div class="body">
              <div class="field"><label>First</label><div class="readonly">${escapeHtml(f('owner_firstname'))}</div></div>
              <div class="field"><label>Middle</label><div class="readonly">${escapeHtml(f('owner_middlename'))}</div></div>
              <div class="field"><label>Last</label><div class="readonly">${escapeHtml(f('owner_lastname'))}</div></div>
              <div class="field"><label>Suffix</label><div class="readonly">${escapeHtml(f('owner_ext'))}</div></div>
              <div class="field"><label>Home Address</label><div class="readonly">${escapeHtml(d.home_address || '—')}</div></div>
            </div></div>
          <div class="card"><header><h4 style="margin:0;font-size:14px;">Documents</h4></header>
            <div class="body">
              <div class="field"><label>Valid ID</label><div class="readonly">${fileLinkHtml(d.valid_id || '', 'View Valid ID')}</div></div>
            </div></div>
        </div>`;
    } else if (svcTable === 'service_wedding') {
      const gf = (n)=>get(d,[n]);
      const bf = (n)=>get(d,[n]);
      bodySections += `
        <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; margin-top:14px;">
          <div class="card"><header><h4 style="margin:0;font-size:14px;">Groom Information</h4></header>
            <div class="body">
              <div class="field"><label>First</label><div class="readonly">${escapeHtml(gf('groom_firstname'))}</div></div>
              <div class="field"><label>Middle</label><div class="readonly">${escapeHtml(gf('groom_middlename'))}</div></div>
              <div class="field"><label>Last</label><div class="readonly">${escapeHtml(gf('groom_lastname'))}</div></div>
              <div class="field"><label>Suffix</label><div class="readonly">${escapeHtml(gf('groom_extension'))}</div></div>
              <div class="field"><label>Birthdate</label><div class="readonly">${escapeHtml(d.groom_birthdate ? safeFormatDateOnly(d.groom_birthdate) : '—')}</div></div>
              <div class="field"><label>Valid ID</label><div class="readonly">${fileLinkHtml(d.groom_valid_id_path || '', 'View Groom ID')}</div></div>
              <div class="field"><label>Baptismal Certificate</label><div class="readonly">${fileLinkHtml(d.groom_birth_cert_path || '', 'View Groom Baptismal Cert')}</div></div>
              <div class="field"><label>Widowed Proof</label><div class="readonly">${fileLinkHtml(d.groom_widowed_file || '', 'View Groom Widowed Proof')}</div></div>
            </div></div>
          <div class="card"><header><h4 style="margin:0;font-size:14px;">Bride Information</h4></header>
            <div class="body">
              <div class="field"><label>First</label><div class="readonly">${escapeHtml(bf('bride_firstname'))}</div></div>
              <div class="field"><label>Middle</label><div class="readonly">${escapeHtml(bf('bride_middlename'))}</div></div>
              <div class="field"><label>Last</label><div class="readonly">${escapeHtml(bf('bride_lastname'))}</div></div>
              <div class="field"><label>Suffix</label><div class="readonly">${escapeHtml(bf('bride_extension'))}</div></div>
              <div class="field"><label>Birthdate</label><div class="readonly">${escapeHtml(d.bride_birthdate ? safeFormatDateOnly(d.bride_birthdate) : '—')}</div></div>
              <div class="field"><label>Valid ID</label><div class="readonly">${fileLinkHtml(d.bride_valid_id_path || '', 'View Bride ID')}</div></div>
              <div class="field"><label>CENOMAR</label><div class="readonly">${fileLinkHtml(d.bride_cenomar_path || '', 'View CENOMAR')}</div></div>
              <div class="field"><label>Baptismal Certificate</label><div class="readonly">${fileLinkHtml(d.bride_baptismal_cert_path || '', 'View Bride Baptismal Cert')}</div></div>
              <div class="field"><label>Widowed Proof</label><div class="readonly">${fileLinkHtml(d.bride_widowed_file || '', 'View Bride Widowed Proof')}</div></div>
            </div></div>
        </div>
        <div class="card" style="margin-top:14px;"><header><h4 style="margin:0;font-size:14px;">Partner Civil Status</h4></header>
          <div class="body"><div class="readonly">${escapeHtml(d.partner_civil_status || '—')}</div></div></div>`;
    } else if (svcTable === 'service_baptism') {
      const baptFirst  = get(d, ['baptized_firstname','baptizand_firstname','child_firstname']);
      const baptMiddle = get(d, ['baptized_middlename','baptizand_middlename','child_middlename'], '');
      const baptLast   = get(d, ['baptized_lastname','baptizand_lastname','child_lastname']);
      const baptExt    = get(d, ['baptized_ext','baptizand_ext','child_ext'], '');
      const birthCertVal = get(d, ['birth_certificate_file','birth_cert_file','birth_certificate','birth_cert'], '');

      const guardianFirst = d.guardian_firstname || '';
      const guardianMid   = d.guardian_middlename || '';
      const guardianLast  = d.guardian_lastname || '';
      const guardianExt   = d.guardian_ext || '';
      const hasGuardian = [guardianFirst, guardianMid, guardianLast, guardianExt]
        .some(v => v && String(v).trim() !== '');

      const guardianCard = hasGuardian ? `
          <div class="card"><header><h4 style="margin:0;font-size:14px;">Guardian (if minor)</h4></header>
            <div class="body">
              <div class="field"><label>First</label><div class="readonly">${escapeHtml(guardianFirst || '—')}</div></div>
              <div class="field"><label>Middle</label><div class="readonly">${escapeHtml(guardianMid || '—')}</div></div>
              <div class="field"><label>Last</label><div class="readonly">${escapeHtml(guardianLast || '—')}</div></div>
              <div class="field"><label>Suffix</label><div class="readonly">${escapeHtml(guardianExt || '—')}</div></div>
              <div class="field"><label>Special Request</label><div class="readonly">${escapeHtml(get(d, ['special_request','notes','remarks','message'], '—'))}</div></div>
            </div></div>
      ` : '';

      bodySections += `
        <div style="display:grid; grid-template-columns:repeat(${hasGuardian ? 2 : 1},minmax(0,1fr)); gap:16px; margin-top:14px;">
          <div class="card"><header><h4 style="margin:0;font-size:14px;">Baptizand</h4></header>
            <div class="body">
              <div class="field"><label>First</label><div class="readonly">${escapeHtml(baptFirst)}</div></div>
              <div class="field"><label>Middle</label><div class="readonly">${escapeHtml(baptMiddle)}</div></div>
              <div class="field"><label>Last</label><div class="readonly">${escapeHtml(baptLast)}</div></div>
              <div class="field"><label>Suffix</label><div class="readonly">${escapeHtml(baptExt)}</div></div>
              <div class="field"><label>Birth Certificate</label><div class="readonly">${fileLinkHtml(birthCertVal,'View Birth Certificate')}</div></div>
            </div></div>
          ${guardianCard}
        </div>`;
    } else if (svcTable === 'service_prayer') {
      const name = get(d,['first_name','firstname','given_name','full_name','name','requester_name']);
      const middle = get(d,['middle_name','middlename','mname'],'');

      const last = get(d,['last_name','lastname','surname','lname'],'');

      const ext = get(d,['extension','suffix','ext'],'');

      const email = get(d, ['email','email_address','requester_email'],'—');
      const topic = get(d, ['subject','prayer_subject','title'],'—');
      const message = get(d, ['prayer_request','message','remarks','notes'],'—');
      const first = (name && !get(d,['first_name','firstname','given_name'],'')) ? name : get(d,['first_name','firstname','given_name'], name);
      bodySections += `
        <div class="card" style="margin-top:14px;"><header><h4 style="margin:0;font-size:14px;">Requester</h4></header>
          <div class="body grid">
            <div class="field"><label>First</label><div class="readonly">${escapeHtml(first)}</div></div>
            <div class="field"><label>Middle</label><div class="readonly">${escapeHtml(middle||'')}</div></div>
            <div class="field"><label>Last</label><div class="readonly">${escapeHtml(last||'')}</div></div>
            <div class="field"><label>Suffix</label><div class="readonly">${escapeHtml(ext||'')}</div></div>
            <div class="field"><label>Email</label><div class="readonly">${escapeHtml(email)}</div></div>
            <div class="field"><label>Subject</label><div class="readonly">${escapeHtml(topic)}</div></div>
          </div>
        </div>
        <div class="card" style="margin-top:14px;"><header><h4 style="margin:0;font-size:14px;">Prayer Request</h4></header>
          <div class="body"><div class="readonly" style="min-height:80px;">${escapeHtml(message||'—')}</div></div></div>`;
    }

    const stLC = (row.status||'').toLowerCase();

    // Reschedule limitations
    const reschedCount = Number(d.reschedule_count || row.reschedule_count || 0);

    // Determine if the original service date is within 2 days from today
    let withinTwoDays = false;
    (function(){
      const raw = row.date || d.service_date || d.appointment_date || d.scheduled_date || d.sched_date || '';
      if (!raw) return;
      const candidate = new Date(raw);
      if (isNaN(candidate.getTime())) return;
      const today = new Date(); 
      today.setHours(0,0,0,0);
      candidate.setHours(0,0,0,0);
      const diffDays = (candidate.getTime() - today.getTime()) / 86400000;
      if (diffDays <= 2) withinTwoDays = true;
    })();

    
    const buttons = [];
    const baseAllowed = (stLC === 'scheduled' || stLC === 'pending');
    const disableResched = !baseAllowed || reschedCount >= 1 || withinTwoDays;

    // Baptism certificate reupload is allowed when verification is NonVerified or Reupload
    const baptismVerificationRaw = String(d.baptism_verification || '').trim();
    const baptismVerification = baptismVerificationRaw.toLowerCase();
    const allowBaptismReupload =
      (svcTable === 'service_baptism' &&
       (baptismVerification === 'nonverified' || baptismVerification === 'reupload'));

    if (baseAllowed) {
      const reason =
        reschedCount >= 1
          ? 'You have already requested a reschedule for this service.'
          : (withinTwoDays
              ? 'Reschedule is not allowed within 2 days of the service date.'
              : '');

      const disabledAttr = disableResched ? ' disabled aria-disabled="true"' : '';
      const extraStyle = disableResched ? 'opacity:.6;cursor:not-allowed;' : '';
      const titleAttr = reason ? ` title="${escapeAttr(reason)}"` : '';

      buttons.push(
        `<button id="btnResched" class="btn" style="background:#0ea5e9;border:none;border-radius:999px;padding:8px 14px;font-size:13px;font-weight:600;${extraStyle}"${disabledAttr}${titleAttr}>Request Reschedule</button>`
      );
      // Allow users to request cancellation for Scheduled or Pending services
      buttons.push(
        `<button id="btnCancel" class="btn secondary" style="background:#dc2626;color:#fff;border:none;border-radius:999px;padding:8px 14px;font-size:13px;font-weight:600;">Request Cancellation</button>`
      );
    }

    // Show reupload button when the overall service status is Reupload,
    // or specifically for baptism records with NonVerified/Reupload verification.
    if (stLC === 'reupload' || allowBaptismReupload) {
      buttons.push(
        `<button id="btnReupload" class="btn" style="background:#0ea5e9;color:#fff;border:none;border-radius:999px;padding:8px 14px;font-size:13px;font-weight:600;">Reupload Files</button>`
      );
    }

const footer = buttons.length
      ? `<div style="display:flex; flex-wrap:wrap; gap:10px; justify-content:flex-end; margin-top:16px;">${buttons.join('')}</div>`
      : '';

    return `<div style="display:grid; gap:14px; text-align:left; font-size:14px;">${appointment}${bodySections}${footer}</div>`;
  }

  async function attachDetailActions(row){
    const btnRes = document.getElementById('btnResched');
    const btnRe  = document.getElementById('btnReupload');
    const btnCan = document.getElementById('btnCancel');

    btnRes && btnRes.addEventListener('click', async ()=> { await openRescheduleFlow(row); });
    btnRe  && btnRe.addEventListener('click', async ()=> { await openReuploadFlow(row); });
    btnCan && btnCan.addEventListener('click', async ()=> { await openCancelFlow(row); });
  }

  // ----- Reschedule flow (inside view modal) ----- 
  async function openRescheduleFlow(row){
    let selectedDate = null, selectedSlot = null, viewYear, viewMonth;
    const today = new Date(); 
    viewYear = today.getFullYear(); 
    viewMonth = today.getMonth();
    const serviceTable = row.table || '';

    const calHtml = `
      <div class="cal-wrap">
        <div class="cal-head">
          <div style="font-weight:700;">Pick a new schedule</div>
          <div class="cal-nav">
            <button id="calPrev" class="cal-btn" type="button" aria-label="Previous month">&larr;</button>
            <div id="calTitle" style="min-width:160px; text-align:center; font-weight:600;"></div>
            <button id="calNext" class="cal-btn" type="button" aria-label="Next month">&rarr;</button>
          </div>
        </div>
        <div class="cal-grid" id="calDOW"></div>
        <div class="cal-grid" id="calGrid" aria-label="Calendar days"></div>

        <div id="timeWrap" style="margin-top:10px;">
          <div style="font-weight:600; margin-bottom:4px;">Pick Service Time</div>
          <div class="time-picker-row">
            <select id="timeHour" class="time-select"></select>
            <select id="timeMinute" class="time-select"></select>
            <select id="timeAmpm" class="time-select">
              <option value="AM">AM</option>
              <option value="PM">PM</option>
            </select>
          </div>
        </div>

        <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px;">
          <button id="rsSubmit" class="btn" disabled>Send Request</button>
          <button id="rsCancel" class="btn secondary">Close</button>
        </div>
      </div>
    `;

    await Swal.fire({
      title: 'Reschedule',
      html: calHtml,
      width: 900, 
      showConfirmButton: false,
      willOpen: () => {
        const DOW = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        document.getElementById('calDOW').innerHTML = DOW.map(d=>`<div class="cal-dow">${d}</div>`).join('');
      },
      didOpen: () => {
        const calTitle = document.getElementById('calTitle');
        const calGrid  = document.getElementById('calGrid');
        const prevBtn  = document.getElementById('calPrev');
        const nextBtn  = document.getElementById('calNext');

        const submitBtn = document.getElementById('rsSubmit');
        const cancelBtn = document.getElementById('rsCancel');

        const hourSel   = document.getElementById('timeHour');
        const minuteSel = document.getElementById('timeMinute');
        const ampmSel   = document.getElementById('timeAmpm');

        function ymd(d){ 
          const y=d.getFullYear(), m=d.getMonth()+1, day=d.getDate(); 
          return y+'-'+String(m).padStart(2,'0')+'-'+String(day).padStart(2,'0'); 
        }

        // JS mirror of per-service weekday rules
        function isWeekdayAllowed(dateObj){
          const dow = dateObj.getDay(); // 0=Sun..6=Sat
          if (serviceTable === 'service_dedication') {
            return dow === 0; // Sunday only
          }
          if (serviceTable === 'service_wedding' || serviceTable === 'service_funeral' || serviceTable === 'service_house') {
            return dow >= 1 && dow <= 6; // Mon–Sat
          }
          return true; // default: all days
        }

        // Populate hour/minute selects (12-hr; 30-min increments)
        function initTimeSelects(){
          if (hourSel) {
            hourSel.innerHTML = '';
            for (let h=1; h<=12; h++){
              const label = String(h).padStart(2,'0');
              const opt = document.createElement('option');
              opt.value = label;
              opt.textContent = label;
              if (label === '08') opt.selected = true; // default 08
              hourSel.appendChild(opt);
            }
          }
          if (minuteSel) {
            minuteSel.innerHTML = '';
            ['00','30'].forEach(min=>{
              const opt = document.createElement('option');
              opt.value = min;
              opt.textContent = min;
              minuteSel.appendChild(opt);
            });
          }
        }

        function updateSubmitState(){
          submitBtn.disabled = !(selectedDate && selectedSlot);
        }

        function recomputeSlot(){
          if (!hourSel || !minuteSel || !ampmSel) {
            selectedSlot = null;
            updateSubmitState();
            return;
          }
          const hStr = hourSel.value;
          const mStr = minuteSel.value;
          const ap   = ampmSel.value;
          if (!hStr || !mStr || !ap) {
            selectedSlot = null;
            updateSubmitState();
            return;
          }

          let h = parseInt(hStr,10);
          const m = parseInt(mStr,10);
          if (Number.isNaN(h) || Number.isNaN(m)) {
            selectedSlot = null;
            updateSubmitState();
            return;
          }

          // 12-hr -> 24-hr
          if (ap === 'AM') {
            if (h === 12) h = 0;
          } else { // PM
            if (h !== 12) h += 12;
          }
          const from = String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0');

          // plus 30 minutes for "to"
          const base = new Date();
          base.setHours(h, m, 0, 0);
          const end  = new Date(base.getTime() + 30*60000);
          const to   = String(end.getHours()).padStart(2,'0') + ':' + String(end.getMinutes()).padStart(2,'0');

          selectedSlot = { from, to };
          updateSubmitState();
        }

        function renderMonth(){
          const first = new Date(viewYear, viewMonth, 1);
          const last  = new Date(viewYear, viewMonth+1, 0);
          calTitle.textContent = first.toLocaleString(undefined, {month:'long', year:'numeric'});
          const startOffset = first.getDay();
          let html = '';
          for (let i=0;i<startOffset;i++) html += `<div></div>`;
          const todayMid = new Date(new Date().getFullYear(), new Date().getMonth(), new Date().getDate());
          for (let d=1; d<=last.getDate(); d++){
            const cur = new Date(viewYear, viewMonth, d);
            const dateStr = ymd(cur);
            const isPast = cur < todayMid;
            const allowed = isWeekdayAllowed(cur);
            const classes = ['cal-cell'];
            if (isPast || !allowed) classes.push('disabled');
            if (selectedDate === dateStr) classes.push('selected');
            html += `<div class="${classes.join(' ')}" data-date="${escapeAttr(dateStr)}">
                       <div class="cal-date-num">${d}</div>
                       <div class="cal-badge" style="display:none;">OCCUPIED</div>
                     </div>`;
          }
          calGrid.innerHTML = html;

          [...calGrid.querySelectorAll('.cal-cell')].forEach(cell=>{
            if (cell.classList.contains('disabled')) return;
            cell.addEventListener('click', async ()=>{
              [...calGrid.querySelectorAll('.cal-cell')].forEach(c=>c.classList.remove('selected'));
              cell.classList.add('selected');
              selectedDate = cell.getAttribute('data-date');
              recomputeSlot(); // enable submit only when both date and time set
            });
          });

          // Mark occupied days (and disallowed by service)
          markDayStatuses();
        }

        async function markDayStatuses(){
          const cells = calGrid.querySelectorAll('.cal-cell');
          const todayStr = ymd(new Date());
          for (const cell of cells) {
            const dateStr = cell.getAttribute('data-date');
            if (!dateStr) continue;
            if (cell.classList.contains('disabled')) continue;
            if (dateStr < todayStr) continue;
            await fetchDayStatus(cell, dateStr);
          }
        }

        async function fetchDayStatus(cell, dateStr){
          try {
            const fd = new FormData();
            fd.append('__action','get_availability');
            fd.append('date', dateStr);
            if (serviceTable) fd.append('table', serviceTable);
            const res = await fetch(location.href, { 
              method:'POST', 
              body:fd, 
              headers:{'X-Requested-With':'fetch'}, 
              credentials:'same-origin', 
              cache:'no-store' 
            });
            const ct = res.headers.get('content-type') || '';
            const data = ct.includes('application/json') ? await res.json() : {ok:false};
            if (!data.ok || !data.data) return;
            const st = data.data.day_status || '';
            if (st === 'occupied') {
              cell.classList.add('disabled','occupied');
              if (data.data.note) cell.title = data.data.note;
              const badge = cell.querySelector('.cal-badge');
              if (badge) badge.style.display = 'inline-flex';
            } else if (st === 'not_applicable') {
              cell.classList.add('disabled');
              if (data.data.note) cell.title = data.data.note;
            }
          } catch(e){
            console.error(e);
          }
        }

        prevBtn.addEventListener('click', ()=>{
          if (viewMonth === 0) { viewMonth = 11; viewYear--; } else { viewMonth--; }
          renderMonth();
        });
        nextBtn.addEventListener('click', ()=>{
          if (viewMonth === 11) { viewMonth = 0; viewYear++; } else { viewMonth++; }
          renderMonth();
        });

        cancelBtn.addEventListener('click', ()=> Swal.close());

        submitBtn.addEventListener('click', async ()=>{
          if (!selectedDate) {
            await Swal.fire({icon:'error', title:'Missing date', text:'Please select a date first.', confirmButtonColor:'#001B3A'});
            return;
          }
          if (!selectedSlot) {
            await Swal.fire({icon:'error', title:'Missing time', text:'Please choose a time.', confirmButtonColor:'#001B3A'});
            return;
          }
          const { value: reason } = await Swal.fire({
            title: 'Reason (optional)',
            input: 'textarea',
            inputPlaceholder: 'Add a note for the admin (optional)',
            showCancelButton: true,
            confirmButtonText: 'Send Request',
            confirmButtonColor:'#001B3A'
          });
          if (reason === undefined) return;

          const fd = new FormData();
          fd.append('__action','request_reschedule');
          fd.append('table', row.table||''); 
          fd.append('ref', row.ref||''); 
          fd.append('date', selectedDate);
          fd.append('from', selectedSlot.from); 
          fd.append('to', selectedSlot.to); 
          fd.append('reason', reason || '');
          try {
            const res = await fetch(location.href, { method:'POST', body:fd, headers:{'X-Requested-With':'fetch'}, credentials:'same-origin', cache:'no-store' });
            const data = await res.json();
            if (data.ok) {
              await Swal.fire({icon:'success', title:'Reschedule requested', text:'Your record was marked as Reschedule and the new date/time were saved.', confirmButtonColor:'#001B3A'});
              Swal.close();
            } else {
              await Swal.fire({icon:'error', title:'Failed', text:data.msg||'Please try again.', confirmButtonColor:'#001B3A'});
            }
          } catch(e){
            await Swal.fire({icon:'error', title:'Network error', text:'Please try again.', confirmButtonColor:'#001B3A'});
          }
        });

        // Wire up time selects
        initTimeSelects();
        if (hourSel)   hourSel.addEventListener('change', recomputeSlot);
        if (minuteSel) minuteSel.addEventListener('change', recomputeSlot);
        if (ampmSel)   ampmSel.addEventListener('change', recomputeSlot);

        recomputeSlot();   // set initial (requires date selection to enable submit)
        renderMonth();     // draw calendar
      }
    });
  }

  
  // ----- Cancellation flow (inside view modal) -----
  async function openCancelFlow(row){
    const d = row.details || {};
    const svcLabel = String(row.service || '');
    const status   = String(row.status || '');
    const table    = String(row.table || '');
    const ref      = String(row.ref || '');

    // Only allow from Scheduled or Pending states on the client side
    const stLC = status.toLowerCase();
    if (stLC !== 'scheduled' && stLC !== 'pending') {
      await Swal.fire({
        icon: 'info',
        title: 'Cancellation not allowed',
        text: 'You can only request cancellation for services that are Scheduled or Pending.',
        confirmButtonColor:'#001B3A'
      });
      return;
    }

    const { value: formVals } = await Swal.fire({
      title: 'Request Cancellation',
      html: `
        <div style="text-align:left;">
          <p style="margin-bottom:8px;">Please provide a reason for cancelling this service.</p>
          <label for="cancelReason" style="display:block; font-weight:600; margin-bottom:4px;">Reason</label>
          <textarea id="cancelReason" class="swal2-textarea" rows="3" style="width:100%;"></textarea>
        </div>
      `,
      focusConfirm: false,
      showCancelButton: true,
      confirmButtonText: 'Continue',
      preConfirm: () => {
        const el = document.getElementById('cancelReason');
        const val = el ? String(el.value || '').trim() : '';
        if (!val) {
          Swal.showValidationMessage('Please enter a reason for cancellation.');
          return false;
        }
        return { reason: val };
      }
    });

    if (!formVals) return;
    const reason = formVals.reason || '';

    const confirm = await Swal.fire({
      icon: 'warning',
      title: 'Are you sure?',
      text: 'This will send a cancellation request to the admins for review.',
      showCancelButton: true,
      confirmButtonText: 'Yes, send request',
      cancelButtonText: 'No, keep my booking',
      confirmButtonColor:'#001B3A'
    });
    if (!confirm.isConfirmed) return;

    const fd = new FormData();
    fd.append('__action','request_cancellation');
    fd.append('table', table);
    fd.append('ref', ref);
    fd.append('reason', reason);

    try {
      const res = await fetch(location.href, {
        method: 'POST',
        body: fd,
        headers: {'X-Requested-With':'fetch'},
        credentials: 'same-origin',
        cache: 'no-store'
      });
      const ct = res.headers.get('content-type') || '';
      const data = ct.includes('application/json') ? await res.json() : { ok:false, msg:'Unexpected response' };
      if (data.ok) {
        await Swal.fire({
          icon: 'success',
          title: 'Cancellation requested',
          text: 'Your cancellation request has been submitted to the admins.',
          confirmButtonColor:'#001B3A'
        });
        // Optionally reload the appointment history
        if (typeof loadHistory === 'function') {
          loadHistory();
        }
      } else {
        await Swal.fire({
          icon: 'error',
          title: 'Failed',
          text: data.msg || 'Please try again.',
          confirmButtonColor:'#001B3A'
        });
      }
    } catch (e) {
      console.error(e);
      await Swal.fire({
        icon: 'error',
        title: 'Network error',
        text: 'Please try again.',
        confirmButtonColor:'#001B3A'
      });
    }
  }

// ----- Reupload flow (inside view modal) ----- 
  async function openReuploadFlow(row){
    const d = row.details || {};
    const keys = Object.keys(d||{});
    const fileKeys = keys.filter(k=>{
      const v = String(k).toLowerCase();
      return v.includes('file') || v.includes('path') || v.includes('image') || v.includes('cert') || v.includes('certificate') || v.endsWith('_id');
    }).sort();

    if (!fileKeys.length) {
      await Swal.fire({icon:'info', title:'No file fields detected', text:'This record does not appear to have replaceable file fields.'});
      return;
    }

    const options = fileKeys.map(k=>`<option value="${escapeAttr(k)}">${escapeHtml(k)}</option>`).join('');
    const html = `
      <div style="display:grid; gap:10px; text-align:left;">
        <div class="field">
          <label>Select field to replace</label>
          <select id="reupField" class="readonly" style="background:#fff;">${options}</select>
        </div>
        <div class="field">
          <label>Choose file (PDF / Image, max 15MB)</label>
          <input id="reupFile" type="file" accept=".pdf,image/*" />
        </div>
      </div>
    `;

    const { isConfirmed } = await Swal.fire({ title:'Reupload Files', html, width:600, showCancelButton:true, confirmButtonText:'Upload' });
    if (!isConfirmed) return;

    const fieldEl = document.getElementById('reupField');
    const fileEl  = document.getElementById('reupFile');
    const column = fieldEl ? fieldEl.value : '';
    const file = fileEl && fileEl.files && fileEl.files[0];

    if (!column) {
      await Swal.fire({icon:'error', title:'Missing field', text:'Please select which field to replace.'});
      return;
    }
    if (!file) {
      await Swal.fire({icon:'error', title:'No file selected', text:'Please choose a file to upload.'});
      return;
    }

    const fd = new FormData();
    fd.append('__action','upload_replacement');
    fd.append('table', row.table||''); 
    fd.append('ref', row.ref||''); 
    fd.append('column', column);
    fd.append('file', file);

    try {
      const res = await fetch(location.href, { method:'POST', body:fd, credentials:'same-origin' });
      const data = await res.json();
      if (data.ok) {
        await Swal.fire({icon:'success', title:'Uploaded', text:'Your file has been uploaded for this field.'});
      } else {
        await Swal.fire({icon:'error', title:'Upload failed', text:data.msg || 'Please try again.'});
      }
    } catch(err) {
      await Swal.fire({icon:'error', title:'Network error', text:'Please try again.'});
    }
  }

  function safeFormatDate(v){
    const t = Date.parse(v);
    if (!isNaN(t)) {
      const d = new Date(t);
      return d.toLocaleString(undefined,{
        year:'numeric',month:'short',day:'2-digit',
        hour:'2-digit',minute:'2-digit'
      });
    }
    return escapeHtml(String(v));
  }
  function safeFormatDateOnly(v){
    const t = Date.parse(v);
    if (!isNaN(t)) {
      const d = new Date(t);
      return d.toLocaleDateString(undefined,{
        year:'numeric',month:'short',day:'2-digit'
      });
    }
    return escapeHtml(String(v));
  }
  function escapeHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
  function escapeAttr(s){ return escapeHtml(String(s)).replace(/"/g,'&quot;'); }

  function looksLikeFilePath(v){
    if (v === undefined || v === null) return false;
    const s = String(v).trim();
    if (!s) return false;
    if (s.startsWith('http://') || s.startsWith('https://') || s.startsWith('/')) return true;
    return /\.(pdf|png|jpe?g|webp|gif|heic)$/i.test(s);
  }

  // This returns a BUTTON-style link for any file path (pdf/image)
  function fileLinkHtml(v,label){
    if (v === undefined || v === null || String(v).trim()==='') return '—';
    const s = String(v).trim();
    const text = label || 'View File';
    if (!looksLikeFilePath(s)) return escapeHtml(s);

    let url = s;
    // If it's not absolute URL and not starting with '/', make it root-relative
    if (!/^https?:\/\//i.test(s) && !s.startsWith('/')) {
      url = '/'+s.replace(/^\/+/, '');
    }
    const urlEsc = escapeAttr(url);

    return `<a href="${urlEsc}" target="_blank" rel="noopener" class="btn ghost small" style="margin-top:4px;">${escapeHtml(text)}</a>`;
  }


  // Baptismal certificate upload from Baptismal Account card
  const btnUploadBaptismCert = document.getElementById('btnUploadBaptismCert');
  if (btnUploadBaptismCert) {
    btnUploadBaptismCert.addEventListener('click', async () => {
      const { isConfirmed, value: file } = await Swal.fire({
        title: 'Upload Baptismal Certificate',
        html: `
          <div style="text-align:left;">
            <p style="margin:0 0 8px;">
              Please upload a clear image or PDF of your baptismal certificate (max 15MB).
            </p>
            <input id="baptismCertFile" type="file" accept=".pdf,image/*" />
          </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Upload',
        width: 600,
        preConfirm: () => {
          const input = document.getElementById('baptismCertFile');
          const f = input && input.files && input.files[0];
          if (!f) {
            Swal.showValidationMessage('Please select a file to upload.');
            return false;
          }
          return f;
        }
      });

      if (!isConfirmed || !file) return;

      const fd = new FormData();
      fd.append('__action', 'upload_baptism_profile');
      fd.append('file', file);

      try {
        const res = await fetch(location.href, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        });
        const data = await res.json();
        if (data.ok) {
          await Swal.fire({
            icon: 'success',
            title: 'Uploaded',
            text: data.msg || 'Your baptismal certificate has been uploaded.'
          });
          const statusEl = document.getElementById('baptismStatusText');
          if (statusEl) {
            statusEl.textContent = 'Pending';
          }
        } else {
          await Swal.fire({
            icon: 'error',
            title: 'Upload failed',
            text: data.msg || 'Please try again.'
          });
        }
      } catch (err) {
        console.error(err);
        await Swal.fire({
          icon: 'error',
          title: 'Upload failed',
          text: 'An unexpected error occurred. Please try again.'
        });
      }
    });
  }

  // Start on Profile tab
  tabProfile.click();
</script>
</body>
</html>
