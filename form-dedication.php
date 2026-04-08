<?php
// ============================
// form-dedication.php (one-file)
// ============================

if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db-connection.php'; // must define $db_connection (mysqli)

/**
 * notifyAdmins() for dedication form (mysqli version)
 */
if (!function_exists('notifyAdmins')) {
  function notifyAdmins(mysqli $db, $formType, $formRecordId, $formSummary) {
    if (!isset($_SESSION) || session_status() !== PHP_SESSION_ACTIVE) return false;

    $allowedUserTypes = ['admin', 'individual', 'pastor'];
    $createdByType = isset($_SESSION['user_type']) ? trim((string)$_SESSION['user_type']) : '';
    $createdById   = isset($_SESSION['user_id'])   ? (int)$_SESSION['user_id'] : 0;
    if (!in_array($createdByType, $allowedUserTypes, true) || $createdById <= 0) return false;

    $formType = trim((string)$formType);
    if ($formType === '') return false;
    if (!is_numeric($formRecordId)) return false;
    $formRecordId = (int)$formRecordId;
    if ($formRecordId <= 0) return false;

    $formSummary = trim((string)$formSummary);
    if ($formSummary === '') return false;
    if (mb_strlen($formSummary) > 2000) $formSummary = mb_substr($formSummary, 0, 2000);

    $composeFullName = function(array $row, array $hints = []) : string {
      $lower = [];
      foreach ($row as $k => $v) $lower[strtolower($k)] = $v;

      $pick = function(array $cands) use ($lower) {
        foreach ($cands as $c) {
          $lc = strtolower($c);
          if (array_key_exists($lc, $lower) && trim((string)$lower[$lc]) !== '') return trim((string)$lower[$lc]);
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

    $fetchSubmitterName = function(string $type, int $id) use ($db, $composeFullName) : string {
      try {
        if ($type === 'individual') {
          $stmt = $db->prepare("SELECT * FROM individual_table WHERE individual_id = ? LIMIT 1");
          if ($stmt) {
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
              $res = $stmt->get_result();
              if ($res && ($row = $res->fetch_assoc())) {
                $name = $composeFullName($row, [
                  'last'   => ['individual_lastname'],
                  'first'  => ['individual_firstname'],
                  'middle' => ['individual_middlename'],
                  'suffix' => ['individual_extension'],
                ]);
                $res->free();
                if ($name !== '') { $stmt->close(); return $name; }
              }
              if ($res) $res->free();
            }
            $stmt->close();
          }
        } elseif ($type === 'admin') {
          $stmt = $db->prepare("SELECT * FROM admin_table WHERE admin_id = ? LIMIT 1");
          if ($stmt) {
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
              $res = $stmt->get_result();
              if ($res && ($row = $res->fetch_assoc())) {
                $name = $composeFullName($row);
                $res->free();
                if ($name !== '') { $stmt->close(); return $name; }
              }
              if ($res) $res->free();
            }
            $stmt->close();
          }
        } elseif ($type === 'pastor') {
          $stmt = $db->prepare("SELECT * FROM pastor_account WHERE Pastor_ID = ? LIMIT 1");
          if ($stmt) {
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
              $res = $stmt->get_result();
              if ($res && ($row = $res->fetch_assoc())) {
                $name = $composeFullName($row);
                $res->free();
                if ($name !== '') { $stmt->close(); return $name; }
              }
              if ($res) $res->free();
            }
            $stmt->close();
          }
        }
      } catch (Throwable $e) {}
      return ucfirst($type) . " #{$id}";
    };

    $submitterName = $fetchSubmitterName($createdByType, $createdById);

    $title = "New " . ucfirst($formType) . " submission";
    $body  = "Submitter: {$submitterName}\n"
           . "Type: {$formType}\n"
           . "Record ID: {$formRecordId}\n"
           . "Summary: {$formSummary}";

    try {
      $db->begin_transaction();

      $stmtInsNotif = $db->prepare("
        INSERT INTO notifications (title, body, created_by_type, created_by_id)
        VALUES (?, ?, ?, ?)
      ");
      if (!$stmtInsNotif) { $db->rollback(); return false; }

      $stmtInsNotif->bind_param("sssi", $title, $body, $createdByType, $createdById);
      if (!$stmtInsNotif->execute()) { $stmtInsNotif->close(); $db->rollback(); return false; }
      $stmtInsNotif->close();

      $notificationId = (int)$db->insert_id;
      if ($notificationId <= 0) { $db->rollback(); return false; }

      $adminIds = [];
      $stmtAdmins = $db->prepare("SELECT admin_id FROM admin_table");
      if ($stmtAdmins && $stmtAdmins->execute()) {
        $res = $stmtAdmins->get_result();
        if ($res) {
          while ($row = $res->fetch_assoc()) {
            $aid = (int)($row['admin_id'] ?? 0);
            if ($aid > 0) $adminIds[] = $aid;
          }
          $res->free();
        }
        $stmtAdmins->close();
      }

      if ($adminIds) {
        $stmtInsRec = $db->prepare("
          INSERT INTO notification_recipients (notification_id, user_type, user_id, status)
          VALUES (?, 'admin', ?, 'unread')
        ");
        if ($stmtInsRec) {
          foreach ($adminIds as $aid) {
            $stmtInsRec->bind_param("ii", $notificationId, $aid);
            $stmtInsRec->execute();
          }
          $stmtInsRec->close();
        }
      }

      $db->commit();
      return true;
    } catch (Throwable $e) {
      try { $db->rollback(); } catch (Throwable $e2) {}
      return false;
    }
  }
}

/* ---------------------------
   Selection helpers + session persistence
----------------------------*/
function _pick($name) {
  if (isset($_GET[$name]) && $_GET[$name] !== '') return trim($_GET[$name]);
  if (isset($_POST[$name]) && $_POST[$name] !== '') return trim($_POST[$name]);
  $sessKey = 'appoint_' . $name;
  if (isset($_SESSION[$sessKey]) && $_SESSION[$sessKey] !== '') return $_SESSION[$sessKey];
  return '';
}
function _persist_if_present($name, $value) {
  if ($value === '') return;
  $_SESSION['appoint_' . $name] = $value;
}

/* ============================================================
   ✅ Fetch dedication_age from contentId=72
   Rules:
   - dedication_age = NULL  => fallback 3
   - dedication_age = 0     => NO MAX AGE LIMIT (disable "Too Old")
   - dedication_age > 0     => that value as max years
   ============================================================ */
if (!function_exists('htccc_get_dedication_age_setting')) {
  function htccc_get_dedication_age_setting(mysqli $db, int $contentId = 72): array {
    // returns: ['raw' => int|null, 'effective' => int, 'unbounded' => bool]
    $fallback = 3;
    $raw = null;

    try {
      $cid = (int)$contentId;
      if ($cid <= 0) $cid = 72;

      $sql = "SELECT dedication_age
              FROM content_management_table
              WHERE contentId = ?
              LIMIT 1";
      if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param("i", $cid);
        if ($stmt->execute()) {
          $res = $stmt->get_result();
          if ($res && ($row = $res->fetch_assoc())) {
            // keep raw (NULL allowed)
            $rawDb = $row['dedication_age'] ?? null;

            if ($rawDb === null || $rawDb === '') {
              $raw = null;
            } else {
              $raw = (int)$rawDb; // can be 0
            }
          }
          if ($res) $res->free();
        }
        $stmt->close();
      }
    } catch (Throwable $e) {}

    // Interpret:
    // - raw === null -> fallback bounded (3)
    // - raw === 0    -> unbounded (no max age)
    // - raw > 0      -> bounded by raw (sanity cap)
    if ($raw === null) {
      return ['raw'=>null, 'effective'=>$fallback, 'unbounded'=>false];
    }
    if ($raw === 0) {
      return ['raw'=>0, 'effective'=>0, 'unbounded'=>true];
    }
    // raw < 0 treated as fallback
    if ($raw < 0) {
      return ['raw'=>$raw, 'effective'=>$fallback, 'unbounded'=>false];
    }
    // sanity cap
    if ($raw > 50) $raw = 50;

    return ['raw'=>$raw, 'effective'=>$raw, 'unbounded'=>false];
  }
}

/* ---- compute dedication setting once ---- */
$__DEDICATION_SETTING = ['raw'=>null, 'effective'=>3, 'unbounded'=>false];
try {
  if (isset($db_connection) && $db_connection instanceof mysqli) {
    $__DEDICATION_SETTING = htccc_get_dedication_age_setting($db_connection, 72);
  }
} catch (Throwable $e) {}

/* for JS */
$__DEDICATION_RAW_AGE = ($__DEDICATION_SETTING['raw'] === null ? null : (int)$__DEDICATION_SETTING['raw']);
$__DEDICATION_EFFECTIVE_AGE = (int)$__DEDICATION_SETTING['effective'];
$__DEDICATION_UNBOUNDED = (bool)$__DEDICATION_SETTING['unbounded'];

/* Capture and persist incoming values */
$incomingDate    = isset($_GET['date'])    ? trim($_GET['date'])    : (isset($_POST['date'])    ? trim($_POST['date'])    : '');
$incomingTime    = isset($_GET['time'])    ? trim($_GET['time'])    : (isset($_POST['time'])    ? trim($_POST['time'])    : '');
$incomingService = isset($_GET['service']) ? trim($_GET['service']) : (isset($_POST['service']) ? trim($_POST['service']) : '');
if ($incomingDate !== '')    _persist_if_present('date',    $incomingDate);
if ($incomingTime !== '')    _persist_if_present('time',    $incomingTime);
if ($incomingService !== '') _persist_if_present('service', $incomingService);

/* ---------------------------
   Guard: require login
----------------------------*/
$individualId = $_SESSION['individual_id'] ?? (isset($_GET['individual_id']) ? (int)$_GET['individual_id'] : null);
if (!$individualId) {
  $returnTo = $_SERVER['REQUEST_URI'];
  header('Location: all_log-in.php?return_to=' . rawurlencode($returnTo));
  exit;
}

/* ---------------------------
   guardian helper + AJAX
----------------------------*/
if (!function_exists('htccc_get_individual_nameparts')) {
  function htccc_get_individual_nameparts(mysqli $db, int $individualId): array {
    $out = ['ln'=>'','fn'=>'','mn'=>'','ex'=>''];
    if ($stmt = $db->prepare("SELECT individual_lastname, individual_firstname, individual_middlename, individual_extension FROM individual_table WHERE individual_id = ? LIMIT 1")) {
      $stmt->bind_param("i", $individualId);
      if ($stmt->execute()) {
        $r = $stmt->get_result();
        if ($r && ($row = $r->fetch_assoc())) {
          $out['ln'] = trim((string)($row['individual_lastname']   ?? ''));
          $out['fn'] = trim((string)($row['individual_firstname']  ?? ''));
          $out['mn'] = trim((string)($row['individual_middlename'] ?? ''));
          $out['ex'] = trim((string)($row['individual_extension']  ?? ''));
        }
        if ($r) $r->free();
      }
      $stmt->close();
    }
    return $out;
  }
}

/* ✅ NEW: Prefill guardian fields from the logged-in user (individual_table)
   - This is what makes the form auto-fill on page load.
   - If there was a failed submit, we keep the POSTed values instead (sticky). */
$__GUARDIAN_PREFILL = ['ln'=>'','fn'=>'','mn'=>'','ex'=>''];
try {
  if (isset($db_connection) && $db_connection instanceof mysqli) {
    $__GUARDIAN_PREFILL = htccc_get_individual_nameparts($db_connection, (int)$individualId);
  }
} catch (Throwable $e) {}

$__GUARDIAN_LN = isset($_POST['guardian_lastname'])   && trim($_POST['guardian_lastname'])   !== '' ? trim($_POST['guardian_lastname'])   : $__GUARDIAN_PREFILL['ln'];
$__GUARDIAN_FN = isset($_POST['guardian_firstname'])  && trim($_POST['guardian_firstname'])  !== '' ? trim($_POST['guardian_firstname'])  : $__GUARDIAN_PREFILL['fn'];
$__GUARDIAN_MN = isset($_POST['guardian_middlename']) && trim($_POST['guardian_middlename']) !== '' ? trim($_POST['guardian_middlename']) : $__GUARDIAN_PREFILL['mn'];
$__GUARDIAN_EX = isset($_POST['guardian_ext'])        && trim($_POST['guardian_ext'])        !== '' ? trim($_POST['guardian_ext'])        : $__GUARDIAN_PREFILL['ex'];

if (isset($_GET['__ajax']) && $_GET['__ajax'] === 'guardian') {
  header('Content-Type: application/json; charset=utf-8');
  $out = ['lastname'=>'','firstname'=>'','middlename'=>'','ext'=>'','fullname'=>''];
  try {
    $ind = htccc_get_individual_nameparts($db_connection, (int)$individualId);
    $ln = $ind['ln']; $fn = $ind['fn']; $mn = $ind['mn']; $ex = $ind['ex'];
    $after = trim($fn . ' ' . $mn);
    $full = trim(($ln && $after) ? ($ln . ', ' . $after . ($ex ? (' ' . $ex) : ''))
                                 : (($ln ?: $after) . ($ex ? (' ' . $ex) : '')));
    $out = ['lastname'=>$ln,'firstname'=>$fn,'middlename'=>$mn,'ext'=>$ex,'fullname'=>$full];
  } catch(Throwable $e){}
  echo json_encode($out, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ============================================================
   ✅ child_suggest / child_get:
   - if dedication_age = 0 => NO age filter
   - else age filter by years
   ============================================================ */
if (isset($_GET['__ajax']) && $_GET['__ajax'] === 'child_suggest') {
  header('Content-Type: application/json; charset=utf-8');
  $q = trim((string)($_GET['q'] ?? ''));
  $out = [];

  try {
    if ($q !== '' && isset($db_connection) && $db_connection instanceof mysqli) {
      $like = $q . '%';

      $unbounded = (bool)$__DEDICATION_UNBOUNDED;
      $ageYears = (int)$__DEDICATION_EFFECTIVE_AGE; // only meaningful if bounded

      if ($unbounded) {
        $sql = "SELECT individual_id, individual_lastname, individual_firstname, individual_middlename, individual_extension, individual_birthday
                FROM individual_table
                WHERE individual_lastname LIKE ?
                ORDER BY individual_lastname, individual_firstname
                LIMIT 10";
        if ($stmt = $db_connection->prepare($sql)) {
          $stmt->bind_param("s", $like);
          if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
              $ln = trim((string)($row['individual_lastname'] ?? ''));
              $fn = trim((string)($row['individual_firstname'] ?? ''));
              $mn = trim((string)($row['individual_middlename'] ?? ''));
              $ex = trim((string)($row['individual_extension'] ?? ''));
              $bd = trim((string)($row['individual_birthday'] ?? ''));
              $after = trim($fn . ' ' . $mn);
              $name = trim(($ln && $after) ? ($ln . ', ' . $after) : ($ln ?: $after));
              if ($ex) $name .= ' ' . $ex;
              $label = $name . ($bd ? (" — " . $bd) : "");
              $out[] = ['id' => (int)$row['individual_id'], 'label' => $label];
            }
            $res->free();
          }
          $stmt->close();
        }
      } else {
        if ($ageYears <= 0) $ageYears = 3;
        if ($ageYears > 50) $ageYears = 50;

        $sql = "SELECT individual_id, individual_lastname, individual_firstname, individual_middlename, individual_extension, individual_birthday
                FROM individual_table
                WHERE individual_lastname LIKE ?
                  AND (
                    COALESCE(
                      STR_TO_DATE(individual_birthday, '%Y-%m-%d'),
                      STR_TO_DATE(individual_birthday, '%m/%d/%Y')
                    ) BETWEEN DATE_SUB(CURDATE(), INTERVAL {$ageYears} YEAR) AND CURDATE()
                  )
                ORDER BY individual_lastname, individual_firstname
                LIMIT 10";
        if ($stmt = $db_connection->prepare($sql)) {
          $stmt->bind_param("s", $like);
          if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
              $ln = trim((string)($row['individual_lastname'] ?? ''));
              $fn = trim((string)($row['individual_firstname'] ?? ''));
              $mn = trim((string)($row['individual_middlename'] ?? ''));
              $ex = trim((string)($row['individual_extension'] ?? ''));
              $bd = trim((string)($row['individual_birthday'] ?? ''));
              $after = trim($fn . ' ' . $mn);
              $name = trim(($ln && $after) ? ($ln . ', ' . $after) : ($ln ?: $after));
              if ($ex) $name .= ' ' . $ex;
              $label = $name . ($bd ? (" — " . $bd) : "");
              $out[] = ['id' => (int)$row['individual_id'], 'label' => $label];
            }
            $res->free();
          }
          $stmt->close();
        }
      }
    }
  } catch (Throwable $e) {}

  echo json_encode($out, JSON_UNESCAPED_UNICODE);
  exit;
}

if (isset($_GET['__ajax']) && $_GET['__ajax'] === 'child_get') {
  header('Content-Type: application/json; charset=utf-8');
  $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
  $out = ['id'=>0,'lastname'=>'','firstname'=>'','middlename'=>'','ext'=>'','birthdate'=>''];

  try {
    if ($id > 0 && isset($db_connection) && $db_connection instanceof mysqli) {

      $unbounded = (bool)$__DEDICATION_UNBOUNDED;
      $ageYears = (int)$__DEDICATION_EFFECTIVE_AGE;

      if ($unbounded) {
        $sql = "SELECT individual_id, individual_lastname, individual_firstname, individual_middlename, individual_extension, individual_birthday
                FROM individual_table
                WHERE individual_id = ?
                LIMIT 1";
        if ($stmt = $db_connection->prepare($sql)) {
          $stmt->bind_param("i", $id);
          if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
              $bdRaw = trim((string)($row['individual_birthday'] ?? ''));
              $bdYmd = '';
              if ($bdRaw !== '') {
                $ts = strtotime($bdRaw);
                if ($ts !== false) $bdYmd = date('Y-m-d', $ts);
              }
              $out = [
                'id'        => (int)($row['individual_id'] ?? 0),
                'lastname'  => trim((string)($row['individual_lastname'] ?? '')),
                'firstname' => trim((string)($row['individual_firstname'] ?? '')),
                'middlename'=> trim((string)($row['individual_middlename'] ?? '')),
                'ext'       => trim((string)($row['individual_extension'] ?? '')),
                'birthdate' => ($bdYmd !== '' ? $bdYmd : $bdRaw),
              ];
            }
            $res->free();
          }
          $stmt->close();
        }
      } else {
        if ($ageYears <= 0) $ageYears = 3;
        if ($ageYears > 50) $ageYears = 50;

        $sql = "SELECT individual_id, individual_lastname, individual_firstname, individual_middlename, individual_extension, individual_birthday
                FROM individual_table
                WHERE individual_id = ?
                  AND (
                    COALESCE(
                      STR_TO_DATE(individual_birthday, '%Y-%m-%d'),
                      STR_TO_DATE(individual_birthday, '%m/%d/%Y')
                    ) BETWEEN DATE_SUB(CURDATE(), INTERVAL {$ageYears} YEAR) AND CURDATE()
                  )
                LIMIT 1";
        if ($stmt = $db_connection->prepare($sql)) {
          $stmt->bind_param("i", $id);
          if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
              $bdRaw = trim((string)($row['individual_birthday'] ?? ''));
              $bdYmd = '';
              if ($bdRaw !== '') {
                $ts = strtotime($bdRaw);
                if ($ts !== false) $bdYmd = date('Y-m-d', $ts);
              }
              $out = [
                'id'        => (int)($row['individual_id'] ?? 0),
                'lastname'  => trim((string)($row['individual_lastname'] ?? '')),
                'firstname' => trim((string)($row['individual_firstname'] ?? '')),
                'middlename'=> trim((string)($row['individual_middlename'] ?? '')),
                'ext'       => trim((string)($row['individual_extension'] ?? '')),
                'birthdate' => ($bdYmd !== '' ? $bdYmd : $bdRaw),
              ];
            }
            $res->free();
          }
          $stmt->close();
        }
      }
    }
  } catch (Throwable $e) {}

  echo json_encode($out, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ============================================================
   SERVER-SIDE helpers (limit)
   ============================================================ */
if (!function_exists('htccc_count_active_across_services_mysqli')) {
  function htccc_count_active_across_services_mysqli(mysqli $db, int $individualId, array $closedStatuses): int {
    $tables = [];
    $sqlFind = "SELECT TABLE_NAME FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'service\\_%'";
    if ($res = $db->query($sqlFind)) {
      while ($row = $res->fetch_assoc()) {
        $t = $row['TABLE_NAME'] ?? '';
        if (!$t) continue;
        $safeT = $db->real_escape_string($t);
        $sqlCols = "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = '{$safeT}'
                      AND COLUMN_NAME IN ('individual_id','status')";
        $r2 = $db->query($sqlCols);
        $hasBoth = 0;
        if ($r2) { $tmp = $r2->fetch_assoc(); $hasBoth = (int)($tmp['c'] ?? 0); $r2->free(); }
        if ($hasBoth >= 2) $tables[] = $t;
      }
      $res->free();
    }
    if (!$tables) return 0;

    $ph = implode(',', array_fill(0, count($closedStatuses), '?'));
    $total = 0;

    foreach ($tables as $t) {
      $sql = "SELECT COUNT(*) AS c FROM `{$t}` WHERE individual_id = ? AND (status IS NULL OR status NOT IN ($ph))";
      $stmt = $db->prepare($sql);
      if (!$stmt) continue;
      $types = 'i' . str_repeat('s', count($closedStatuses));
      $params = array_merge([$individualId], $closedStatuses);
      $stmt->bind_param($types, ...$params);
      if ($stmt->execute()) {
        $r = $stmt->get_result();
        if ($r) {
          $row = $r->fetch_assoc();
          $total += (int)($row['c'] ?? 0);
          $r->free();
        }
      }
      $stmt->close();
    }
    return $total;
  }
}

/* ---------------------------
   Submission handler
----------------------------*/
$submitError = null;
$bcertAbsPath = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  try {
    $CLOSED = ['Approved','Declined','Cancelled','Archived','Done'];
    $activeCount = htccc_count_active_across_services_mysqli($db_connection, (int)$individualId, $CLOSED);
    if ($activeCount >= 5) {
      header('Location: ' . basename(__FILE__) . '?quota=1'
        . '&date='    . rawurlencode(_pick('date'))
        . '&time='    . rawurlencode(_pick('time'))
        . '&service=' . rawurlencode(_pick('service') ?: 'DEDICATION')
      );
      exit;
    }
  } catch (Throwable $___limitEx) {}

  if (empty($_SESSION['user_type'])) $_SESSION['user_type'] = 'individual';
  if (empty($_SESSION['user_id']))   $_SESSION['user_id']   = (int)$individualId;

  function req($key, $label = null) {
    $label = $label ?: $key;
    if (!isset($_POST[$key]) || trim($_POST[$key]) === '') {
      throw new RuntimeException("Missing required field: {$label}");
    }
    return trim($_POST[$key]);
  }

  try {
    $appointment_date    = ($_POST['appointment_date'] ?? '') !== '' ? trim($_POST['appointment_date']) : _pick('date');
    $appointment_time    = ($_POST['appointment_time'] ?? '') !== '' ? trim($_POST['appointment_time']) : _pick('time');
    $appointment_service = ($_POST['appointment_service'] ?? '') !== '' ? trim($_POST['appointment_service']) : (_pick('service') ?: 'DEDICATION');

    if ($appointment_date === '' || $appointment_time === '') {
      throw new RuntimeException("Missing appointment date/time. Please reselect your slot from the appointment page.");
    }

    _persist_if_present('date',    $appointment_date);
    _persist_if_present('time',    $appointment_time);
    _persist_if_present('service', $appointment_service);

    $child_lastname   = req('child_lastname',  'Child last name');
    $child_firstname  = req('child_firstname', 'Child first name');
    $child_middlename = trim($_POST['child_middlename'] ?? '');
    $child_ext        = trim($_POST['child_ext'] ?? '');

    $child_birthdate  = req('child_birthdate', 'Child’s birthdate');
    if ($child_birthdate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $child_birthdate)) {
      throw new RuntimeException("Birthdate must be in YYYY-MM-DD format.");
    }

    /* ================================================
       ✅ SERVER DOB VALIDATION:
       - youngest: at least 6 months old
       - oldest: ONLY if dedication_age > 0
         * if dedication_age = 0 => NO MAX AGE LIMIT
       ================================================ */
    $today = new DateTime('today');
    $maxDob = (clone $today)->modify('-6 months');

    $dobObj = DateTime::createFromFormat('Y-m-d', $child_birthdate);
    $isValidFormat = $dobObj && $dobObj->format('Y-m-d') === $child_birthdate;
    if (!$isValidFormat) throw new RuntimeException('Child’s birthdate is invalid.');

    if ($dobObj > $today) throw new RuntimeException('Child’s birthdate cannot be in the future.');
    if ($dobObj > $maxDob) throw new RuntimeException('Child must be at least 6 months old.');

    // max-age check ONLY when bounded
    if (!$__DEDICATION_UNBOUNDED) {
      $dedicationAgeYears = (int)$__DEDICATION_EFFECTIVE_AGE; // fallback already handled if raw NULL
      if ($dedicationAgeYears <= 0) $dedicationAgeYears = 3;
      $minDob = (clone $today)->modify('-' . $dedicationAgeYears . ' years');

      if ($dobObj < $minDob) {
        throw new RuntimeException('Child must be ' . (int)$dedicationAgeYears . ' years old or younger.');
      }
    }
    /* ===== END SERVER DOB VALIDATION ===== */

    // Guardian parts optional; fallback from individual_table
    $g_ln = trim($_POST['guardian_lastname']   ?? '');
    $g_fn = trim($_POST['guardian_firstname']  ?? '');
    $g_mn = trim($_POST['guardian_middlename'] ?? '');
    $g_ex = trim($_POST['guardian_ext']        ?? '');
    if ($g_ln === '' && $g_fn === '' && $g_mn === '' && $g_ex === '') {
      $ind = htccc_get_individual_nameparts($db_connection, (int)$individualId);
      $g_ln = $ind['ln']; $g_fn = $ind['fn']; $g_mn = $ind['mn']; $g_ex = $ind['ex'];
    }

    // Build notification summary
    $childName = trim($child_lastname);
    if (trim($child_firstname) !== '') $childName .= ($childName !== '' ? ', ' : '') . trim($child_firstname);
    if (trim($child_middlename) !== '') $childName .= ' ' . trim($child_middlename);
    if (trim($child_ext) !== '') $childName .= ' ' . trim($child_ext);
    $childName = trim($childName);

    $summaryParts = [];
    if ($childName !== '') $summaryParts[] = "Child: {$childName}";
    if ($child_birthdate)  $summaryParts[] = "Birthdate: {$child_birthdate}";
    if ($appointment_date && $appointment_time) $summaryParts[] = "Appointment: {$appointment_date} {$appointment_time}";
    $formSummary = implode(' | ', $summaryParts);

    // Required upload
    $bcertPath = null;
    if (!isset($_FILES['baptismal_cert']) || !is_array($_FILES['baptismal_cert'])) {
      throw new RuntimeException("Child’s birth certificate file is required.");
    }
    $bcertFile = $_FILES['baptismal_cert'];

    if ($bcertFile['error'] === UPLOAD_ERR_NO_FILE) throw new RuntimeException("Child’s birth certificate file is required.");
    if ($bcertFile['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Error uploading birth certificate. Please try again.');

    $maxSize = 8 * 1024 * 1024;
    if (!empty($bcertFile['size']) && $bcertFile['size'] > $maxSize) {
      throw new RuntimeException('Birth certificate file is too large. Maximum size is 8MB.');
    }

    $tmpName = $bcertFile['tmp_name'];
    $mime = null;
    if (is_uploaded_file($tmpName) && function_exists('finfo_open')) {
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      if ($finfo) {
        $mime = finfo_file($finfo, $tmpName);
        finfo_close($finfo);
      }
    }

    $allowedMime = ['image/jpeg','image/png','image/gif','image/webp','application/pdf'];
    if ($mime !== null && !in_array($mime, $allowedMime, true)) {
      throw new RuntimeException('Birth certificate must be an image or PDF file.');
    }

    $origNameRaw = isset($bcertFile['name']) ? trim((string)$bcertFile['name']) : '';
    if ($origNameRaw === '') $origNameRaw = 'uploaded_file';
    $onlyName = basename($origNameRaw);
    $safeName = ($onlyName === '' || $onlyName === '.' || $onlyName === '..') ? 'uploaded_file' : $onlyName;

    $uploadDir = __DIR__ . '/uploads/dedication';
    if (!is_dir($uploadDir)) {
      if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Failed to create upload directory.');
      }
    }

    $finalName = $safeName;
    $dotPos = strrpos($safeName, '.');
    if ($dotPos !== false) { $namePart = substr($safeName, 0, $dotPos); $extPart = substr($safeName, $dotPos); }
    else { $namePart = $safeName; $extPart = ''; }

    $counter = 1;
    while (file_exists($uploadDir . '/' . $finalName)) {
      $finalName = $namePart . '_' . $counter . $extPart;
      $counter++;
    }

    $targetPath   = $uploadDir . '/' . $finalName;
    $bcertAbsPath = $targetPath;
    if (!move_uploaded_file($tmpName, $targetPath)) {
      throw new RuntimeException('Failed to save uploaded birth certificate.');
    }

    $docRoot    = rtrim(str_replace('\\','/', $_SERVER['DOCUMENT_ROOT']), '/');
    $targetNorm = str_replace('\\','/', $targetPath);
    if ($docRoot !== '' && strpos($targetNorm, $docRoot) === 0) $bcertPath = ltrim(substr($targetNorm, strlen($docRoot)), '/');
    else $bcertPath = $targetNorm;

    $sql = "INSERT INTO service_dedication
            (individual_id, child_birthdate,
             child_lastname, child_firstname, child_middlename, child_ext,
             guardian_lastname, guardian_firstname, guardian_middlename, guardian_ext,
             baptismal_cert_path, service_date, service_time)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    if (!$stmt = $db_connection->prepare($sql)) {
      if ($bcertAbsPath && file_exists($bcertAbsPath)) @unlink($bcertAbsPath);
      throw new RuntimeException('Database error: failed to prepare statement.');
    }

    $stmt->bind_param(
      "issssssssssss",
      $individualId,
      $child_birthdate,
      $child_lastname,
      $child_firstname,
      $child_middlename,
      $child_ext,
      $g_ln,
      $g_fn,
      $g_mn,
      $g_ex,
      $bcertPath,
      $appointment_date,
      $appointment_time
    );

    if (!$stmt->execute()) {
      $stmt->close();
      if ($bcertAbsPath && file_exists($bcertAbsPath)) @unlink($bcertAbsPath);
      throw new RuntimeException('Database error: failed to execute insert.');
    }

    $dedicationId = (int)$db_connection->insert_id;
    $stmt->close();

    if ($dedicationId > 0) {
      try { notifyAdmins($db_connection, 'dedication', $dedicationId, $formSummary); } catch (Throwable $__e) {}
    }

    header('Location: ' . basename(__FILE__)
      . '?success=1'
      . '&date='    . rawurlencode($appointment_date)
      . '&time='    . rawurlencode($appointment_time)
      . '&service=' . rawurlencode($appointment_service)
    );
    exit;

  } catch (Throwable $e) {
    http_response_code(400);
    $submitError = $e->getMessage();
    if ($bcertAbsPath && file_exists($bcertAbsPath)) @unlink($bcertAbsPath);
  }
}

/* ---------------------------
   Selections for rendering
----------------------------*/
$selDate    = _pick('date');
$selTime    = _pick('time');
$selService = _pick('service');
if ($selService === '') $selService = 'DEDICATION';
$__quotaExceeded = (isset($_GET['quota']) && $_GET['quota'] === '1');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta content="width=device-width, initial-scale=1" name="viewport" />
  <title>HTCCC - Dedication Appointment Form</title>
  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <script>
    function showAppointmentSubmitted(options) {
      const opts = Object.assign({
        serviceLabel: 'appointment',
        date: '—',
        time: '—',
        serviceCode: '—',
        onNewApptHref: 'appoint-page.php',
        onMainHref: 'main-page.php'
      }, options || {});

      return Swal.fire({
        icon: 'success',
        title: 'Appointment Submitted',
        html: `
          <p>Your ${opts.serviceLabel} appointment was submitted successfully.</p>
          <p>Please allow up to <b>1 business day</b> for approval.</p>
          <hr style="opacity:.2%;">
          <p style="font-size:.9rem;margin-top:10px;">
            <b>Details</b><br>
            Date: ${opts.date}<br>
            Time: ${opts.time}<br>
            Service: ${opts.serviceCode}
          </p>
        `,
        confirmButtonText: 'Create another appointment',
        confirmButtonColor: '#7FC1FF',
        showDenyButton: true,
        denyButtonText: 'Go to main page',
        showCancelButton: false,
        allowOutsideClick: true,
        allowEscapeKey: false,
        background: 'transparent',
        backdrop: 'rgba(15, 23, 42, 0.65)',
        customClass: { popup: 'swal2-transparent-blur' }
      });
    }
  </script>

  <style>
    :root {
      --ink:#1a1f4a; --panel:#3a3f71; --panelAlpha: rgba(58,63,113,0.9);
      --border:#4b5563; --bg:#0A0E3F; --accent:#7FC1FF; --field:#F3F2F5; --text:#fff;
    }
    * { box-sizing: border-box; }
    body {
      font-family: Arial, sans-serif;
      background-image: url("image/all-background.png");
      background-position: center;
      background-size: cover;
      background-attachment: fixed;
      background-repeat: no-repeat;
      margin: 0; color: var(--text);
    }
    nav{background-color:var(--bg);display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:10px clamp(12px,3vw,20px);color:#fff}
    nav img { width: clamp(26px, 5vw, 30px); height: clamp(26px, 5vw, 30px); border-radius: 50%; }
    nav h1 { margin:0;font-size:clamp(16px,3.4vw,20px);font-weight:800;line-height:1.1 }
    nav a { color:#fff; text-decoration:none; display:flex; align-items:center; gap:8px; }
    main { max-width: 1120px; margin: clamp(12px, 2vw, 24px) auto 0; padding: 0 clamp(12px, 2.5vw, 16px); }
    .top-sections{ margin-bottom: clamp(12px, 2.2vw, 24px); }
    section.border-box{ border:2px solid var(--border); border-radius:.75rem; padding:clamp(12px,2.2vw,16px); background:var(--panelAlpha); height:100% }
    section.border-box h2{ font-weight:700; font-size:clamp(1rem,2.5vw,1.15rem); margin-bottom:.75rem; text-align:center; color:#fff }
    section.border-box ul{ list-style:disc; padding-left:1.25rem; font-weight:600; font-size:clamp(.9rem,2.4vw,.95rem); line-height:1.5; margin:0; color:#fff }
    .summary{ color:#fff; font-weight:700; background:var(--ink); border-radius:12px; padding:clamp(10px,2.5vw,12px) clamp(12px,2.5vw,16px); text-align:center; margin-bottom:clamp(12px,2.2vw,16px); word-wrap:break-word; overflow-wrap:anywhere; font-size:clamp(.95rem,2.5vw,1rem) }
    .form-grid{ background-color:var(--panel); border-radius:.75rem; padding:clamp(12px,2.5vw,20px); border:2px solid var(--border) }
    .form-card{ background:rgba(0,0,0,.08); border:2px solid var(--border); border-radius:12px; padding:clamp(12px,2.5vw,16px); height:100% }
    .form-card h3{ margin:0 0 10px; font-size:clamp(1rem,2.6vw,1.05rem); font-weight:800 }
    label{ font-weight:600; font-size:clamp(.9rem,2.4vw,.92rem); display:block; color:#f9fafb; margin-bottom:6px }
    input[type="text"], input[type="tel"], input[type="date"], input[type="file"], select, textarea{
      width:100%; border-radius:12px; padding:clamp(12px,2.5vw,14px);
      font-weight:600; font-size:clamp(.98rem,2.5vw,1rem); color:#1f2937;
      border:none; outline:none; background:var(--field); min-height:44px;
    }
    textarea{ resize:vertical; min-height:100px; }
    .actions{ display:flex; justify-content:center; padding-top:clamp(8px,2vw,12px) }
    .btn-primary-ht{ background-color:var(--accent); color:#000; border-radius:12px; border:none; font-weight:800; min-height:44px; padding:12px 18px }
    .btn-primary-ht:hover{ filter:brightness(.95) }
    @media (max-width:575.98px){ .btn-primary-ht{ width:100% } }

    .consent-row{ margin-top:10px; margin-bottom:4px; color:#e5e7eb; font-size:clamp(.9rem,2.4vw,.95rem) }
    .consent-row .form-check-input{ margin-top:.25rem; min-width:1.15rem; min-height:1.15rem }

    .name-breakdown-hint{ color:#dce4ff; font-size:.85rem; margin-top:.35rem }
    .name-breakdown-grid{ display:grid; gap:.6rem; grid-template-columns:1fr }
    @media (min-width:768px){ .name-breakdown-grid{ grid-template-columns:1fr 1fr } }
    .name-breakdown-grid label small.req{ color:#ffb3b3; font-weight:700 }
    .name-preview{ font-size:.9rem; color:#bcd7ff; margin-top:.35rem }

    @media (max-width:575.98px){
      nav{ padding:10px 14px }
      main{ padding:0 12px }
      .form-card{ padding:14px }
      .summary{ padding:10px 12px }
      body{ background-attachment:scroll }
    }

    .swal2-popup.swal2-transparent-blur {
      background: rgba(15, 23, 42, 0.30);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border-radius: 16px;
      border: 1px solid rgba(148, 163, 184, 0.4);
    }
    .swal2-title, .swal2-html-container { color: #e5e7eb; }
  </style>
</head>
<body>
  <nav class="w-100">
    <a href="appoint-page.php" class="d-inline-flex align-items-center gap-2 text-decoration-none text-white">
      <img src="image/btn-back.png" alt="Back"><span class="fw-bold"></span>
    </a>
    <div class="d-flex align-items-center gap-2">
      <img src="image/httc_main-logo.jpg" alt="HTCCC logo" style="width:50px; height:50px; border-radius:50%;">
      <h1 class="m-0 text-center text-uppercase">DEDICATION APPOINTMENT FORM</h1>
    </div>
    <div style="width:30px;"></div>
  </nav>

  <main class="container py-3 py-md-4">
    <?php if (!empty($submitError)): ?>
      <div class="alert alert-danger mt-3" role="alert">
        <strong>Submission Error:</strong>
        <?= htmlspecialchars($submitError, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>

    <div class="top-sections row g-3 g-md-4">
      <div class="col-12 col-lg-6">
        <section class="border-box h-100">
          <h2>What to Bring?</h2>
          <ul class="mb-0">
            <li>Child’s Birth Certificate (image or PDF)</li>
            <li>List of Godparents</li>
          </ul>
        </section>
      </div>
      <div class="col-12 col-lg-6">
        <section class="border-box h-100">
          <h2>Church Rules</h2>
          <ul class="mb-0">
            <li>At least one parent should be a member of the church.</li>
            <li>Electricity Fee (if inside the Church).</li>
            <li>Offerings are optional but encouraged.</li>
          </ul>
        </section>
      </div>
    </div>

    <div class="summary px-3 py-2">
      <?php
        $safeDate = htmlspecialchars($selDate ?: '—', ENT_QUOTES, 'UTF-8');
        $safeTime = htmlspecialchars($selTime ?: '—', ENT_QUOTES, 'UTF-8');
        $safeSvc  = htmlspecialchars($selService ?: 'DEDICATION', ENT_QUOTES, 'UTF-8');
        echo "Selected Date: <b>{$safeDate}</b> &nbsp; | &nbsp; Time: <b>{$safeTime}</b> &nbsp; | &nbsp; Service: <b>{$safeSvc}</b>";
      ?>
    </div>

    <form id="dedicationForm" action="<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
      <div class="form-grid">
        <div class="row g-3 g-md-4">
          <div class="col-12 col-lg-6">
            <div class="form-card">
              <h3>Child Information</h3>

              <div class="name-breakdown-hint">
                Enter in this order: <b>Last Name, First Name, Middle Name (optional), Extension (optional)</b>. We’ll auto-build the Full Name for review only.
              </div>

              <div class="name-breakdown-grid" id="nameBreakdown">
                <div>
                  <label for="cn_last">Last Name <small class="req">*</small></label>
                  <input type="text" id="cn_last" class="form-control" placeholder="e.g., Dela Cruz" autocomplete="family-name" required>
                </div>
                <div>
                  <label for="cn_first">First Name <small class="req">*</small></label>
                  <input type="text" id="cn_first" class="form-control" placeholder="e.g., Juan" autocomplete="given-name" required>
                </div>
                <div>
                  <label for="cn_middle">Middle Name <span class="text-light opacity-75">(optional)</span></label>
                  <input type="text" id="cn_middle" class="form-control" placeholder="e.g., Santos" autocomplete="additional-name">
                </div>
                <div>
                  <label for="cn_ext">Extension <span class="text-light opacity-75">(optional)</span></label>
                  <select id="cn_ext" class="form-control">
                    <option value="">—</option>
                    <option>Jr.</option><option>Sr.</option><option>I</option><option>II</option>
                    <option>III</option><option>IV</option><option>V</option>
                  </select>
                </div>
              </div>

              <div class="name-preview" aria-live="polite">Preview: <span id="namePreview">—</span></div>

              <input type="hidden" name="child_lastname"   id="hid_child_lastname">
              <input type="hidden" name="child_firstname"  id="hid_child_firstname">
              <input type="hidden" name="child_middlename" id="hid_child_middlename">
              <input type="hidden" name="child_ext"        id="hid_child_ext">

              <label for="child_birthdate" class="mt-2">Child’s Birthdate <span class="text-danger">*</span></label>
              <input type="date" id="child_birthdate" name="child_birthdate" class="form-control" required
                     value="<?= htmlspecialchars($_POST['child_birthdate'] ?? '', ENT_QUOTES) ?>" />

              <label for="baptismal_cert" class="mt-2">Upload Child’s Birth Certificate (Image or PDF) <span class="text-danger">*</span></label>
              <input type="file" id="baptismal_cert" name="baptismal_cert" class="form-control"
                     accept="image/*,.pdf" required />
              <div class="form-text text-light">Allowed: image or PDF (max 8 MB)</div>
            </div>
          </div>

          <div class="col-12 col-lg-6">
            <div class="form-card">
              <h3>Guardian</h3>

              <!-- ✅ AUTO-FILL HERE: values come from the logged-in user (individual_table)
                   If the user typed something (POST) we keep it (sticky). -->
              <div class="row g-2">
                <div class="col-12 col-md-6">
                  <label for="guardian_lastname">Guardian Last Name</label>
                  <input type="text" id="guardian_lastname" name="guardian_lastname" class="form-control"
                         value="<?= htmlspecialchars($__GUARDIAN_LN ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-12 col-md-6">
                  <label for="guardian_firstname">Guardian First Name</label>
                  <input type="text" id="guardian_firstname" name="guardian_firstname" class="form-control"
                         value="<?= htmlspecialchars($__GUARDIAN_FN ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-12 col-md-6">
                  <label for="guardian_middlename">Guardian Middle Name</label>
                  <input type="text" id="guardian_middlename" name="guardian_middlename" class="form-control"
                         value="<?= htmlspecialchars($__GUARDIAN_MN ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-12 col-md-6">
                  <label for="guardian_ext">Guardian Extension</label>
                  <input type="text" id="guardian_ext" name="guardian_ext" class="form-control"
                         value="<?= htmlspecialchars($__GUARDIAN_EX ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
              </div>

            </div>
          </div>
        </div>

        <input type="hidden" name="appointment_date" value="<?= htmlspecialchars($selDate, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="appointment_time" value="<?= htmlspecialchars($selTime, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="appointment_service" value="<?= htmlspecialchars($selService ?: 'DEDICATION', ENT_QUOTES, 'UTF-8') ?>">

        <div class="row mt-2">
          <div class="col-12">
            <div class="consent-row">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="consent_inline" required>
                <label class="form-check-label" for="consent_inline">
                  I agree to the collection and processing of my personal data for dedication appointment purposes in accordance with the Data Privacy Act of 2012 (RA 10173) and
                  <a href="#" id="viewDpaLink" class="text-decoration-underline text-white">HTCCC’s Privacy Notice</a>.
                </label>
              </div>
            </div>
          </div>
        </div>

        <div class="actions mt-1">
          <button type="submit" class="btn btn-primary-ht px-4 py-2">SUBMIT</button>
        </div>
      </div>
    </form>
  </main>

  <!-- ✅ FIXED: Child Birthdate SweetAlert validator
       - youngest allowed: 6 months old
       - oldest allowed: only if dedication_age > 0
       - if dedication_age = 0 => NO "Too Old" check
  -->
  <script>
    (function(){
      document.addEventListener('DOMContentLoaded', function(){
        var dob = document.getElementById('child_birthdate');
        if (!dob) return;

        // Raw DB value can be: null, 0, >0
        var DEDICATION_RAW = <?= ($__DEDICATION_RAW_AGE === null ? 'null' : (int)$__DEDICATION_RAW_AGE) ?>;
        var DEDICATION_UNBOUNDED = <?= ($__DEDICATION_UNBOUNDED ? 'true' : 'false') ?>;
        // effective fallback used only when bounded + raw is null
        var DEDICATION_EFFECTIVE = <?= (int)$__DEDICATION_EFFECTIVE_AGE ?>;

        function clampYears(y){
          y = parseInt(y, 10);
          if (!isFinite(y) || y <= 0) return 3;
          if (y > 50) return 50;
          return y;
        }

        dob.addEventListener('change', function(){
          if (!this.value) return;

          var parts = this.value.split('-');
          if (parts.length !== 3) return;

          var d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
          var today = new Date(); today.setHours(0,0,0,0);

          if (isNaN(d.getTime())) {
            this.value = '';
            Swal.fire({icon:'error', title:'Invalid Birthdate', text:'Please enter a valid date in YYYY-MM-DD format.', confirmButtonColor:'#7FC1FF'});
            return;
          }

          var sixMonthsAgo = new Date(today.getFullYear(), today.getMonth(), today.getDate());
          sixMonthsAgo.setMonth(sixMonthsAgo.getMonth() - 6);

          if (d > today) {
            this.value = '';
            Swal.fire({icon:'error', title:'Invalid Birthdate', text:'Future dates are not allowed.', confirmButtonColor:'#7FC1FF'});
            return;
          }

          if (d > sixMonthsAgo) {
            this.value = '';
            Swal.fire({icon:'warning', title:'Too Young', text:'The child must be at least 6 months old.', confirmButtonColor:'#7FC1FF'});
            return;
          }

          // ✅ if dedication_age is 0 => unbounded => skip too old check
          if (!DEDICATION_UNBOUNDED) {
            var yrs = (DEDICATION_RAW === null) ? clampYears(DEDICATION_EFFECTIVE) : clampYears(DEDICATION_RAW);
            var yearsAgo = new Date(today.getFullYear() - yrs, today.getMonth(), today.getDate());

            if (d < yearsAgo) {
              this.value = '';
              Swal.fire({
                icon:'warning',
                title:'Too Old',
                text:`The child must be ${yrs} years old or younger (based on dedication_age setting).`,
                confirmButtonColor:'#7FC1FF'
              });
              return;
            }
          }
        });
      });
    })();
  </script>

  <?php if (!empty($submitError)): ?>
    <script>
      Swal.fire({ icon:'error', title:'Submission Error', text: <?= json_encode($submitError) ?>, confirmButtonColor:'#7FC1FF' });
    </script>
  <?php endif; ?>
</body>
</html>
