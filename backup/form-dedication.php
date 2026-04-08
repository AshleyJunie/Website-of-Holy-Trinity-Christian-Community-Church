<?php
// ============================
// form-dedication.php (one-file)
// ============================

if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db-connection.php'; // must define $db_connection (mysqli)

/**
 * notifyAdmins() for dedication form (mysqli version)
 *  - Validates session identity (admin / individual / pastor)
 *  - Builds a normalized display name
 *  - Inserts into notifications
 *  - Inserts notification_recipients rows for all admins
 */
if (!function_exists('notifyAdmins')) {
  function notifyAdmins(mysqli $db, $formType, $formRecordId, $formSummary) {
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
                if ($name !== '') {
                  $stmt->close();
                  return $name;
                }
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
                if ($name !== '') {
                  $stmt->close();
                  return $name;
                }
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
                if ($name !== '') {
                  $stmt->close();
                  return $name;
                }
              }
              if ($res) $res->free();
            }
            $stmt->close();
          }
        }
      } catch (Throwable $e) {
        // fall back
      }
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

      // Insert notification
      $stmtInsNotif = $db->prepare("
        INSERT INTO notifications (title, body, created_by_type, created_by_id)
        VALUES (?, ?, ?, ?)
      ");
      if (!$stmtInsNotif) {
        $db->rollback();
        return false;
      }
      $stmtInsNotif->bind_param("sssi", $title, $body, $createdByType, $createdById);
      if (!$stmtInsNotif->execute()) {
        $stmtInsNotif->close();
        $db->rollback();
        return false;
      }
      $stmtInsNotif->close();

      $notificationId = (int)$db->insert_id;
      if ($notificationId <= 0) {
        $db->rollback();
        return false;
      }

      // Fetch admins
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

      // Insert recipients
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

// Capture and persist incoming values (from appoint-page via GET or POST)
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

/* ❗========================================
   ADD BELOW THIS LINE — helper + AJAX for
   guardian name parts from individual_table
   ======================================== */
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
/* ❗== END helper + AJAX ===================================== */

/* ============================================================
   SERVER-SIDE helpers (limit + upload validation)
   ============================================================ */

// ---- [A] Cross-service ACTIVE-appointment limit (max 5) ----
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

// ---- [B] Strict revalidation helper (currently unused but kept) ----
if (!function_exists('_strict_revalidate_upload_imgonly')) {
  function _strict_revalidate_upload_imgonly(string $webRelOrAbsPath, array $mimeMap): void {
    $pathNorm = str_replace('\\', '/', $webRelOrAbsPath);
    $abs = $pathNorm;
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    if ($docRoot !== '' && strpos($pathNorm, $docRoot) !== 0) {
      $absCandidate = $docRoot . '/' . ltrim($pathNorm, '/');
      if (is_file($absCandidate)) $abs = $absCandidate;
    }
    if (!is_file($abs)) $abs = $pathNorm;
    if (!is_file($abs)) return;

    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION) ?: '');
    if (!in_array($ext, ['jpg','jpeg','png'], true)) { @unlink($abs); throw new RuntimeException('Invalid image type.'); }

    $mime = '';
    if (function_exists('finfo_open')) {
      $finfo = @finfo_open(FILEINFO_MIME_TYPE);
      $mime  = $finfo ? (@finfo_file($finfo, $abs) ?: '') : '';
      if ($finfo) @finfo_close($finfo);
    }
    $expected = $mimeMap[$ext] ?? '';
    if ($expected && $mime && strtolower($mime) !== strtolower($expected)) { @unlink($abs); throw new RuntimeException('Image content type mismatch.'); }

    if (@getimagesize($abs) === false) { @unlink($abs); throw new RuntimeException('Invalid image file.'); }
  }
}
$__IMG_MIME_MAP = [ 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'png'=>'image/png' ];

/* ---------------------------
   Submission handler (before any HTML)
----------------------------*/
$submitError = null;
$bcertAbsPath = null; // absolute path to uploaded birth certificate (for cleanup on error)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  /* ========= Enforce active-appointment limit (server-side) ========= */
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
  /* ===== END limit ===== */

  // Ensure notifier identity is available for this individual workflow (for notifications)
  if (empty($_SESSION['user_type'])) $_SESSION['user_type'] = 'individual';
  if (empty($_SESSION['user_id']))   $_SESSION['user_id']   = (int)$individualId;

  // helper
  function req($key, $label = null) {
    $label = $label ?: $key;
    if (!isset($_POST[$key]) || trim($_POST[$key]) === '') {
      throw new RuntimeException("Missing required field: {$label}");
    }
    return trim($_POST[$key]);
  }

  try {
    // Appointment metadata — POST with session fallbacks
    $appointment_date    = ($_POST['appointment_date'] ?? '') !== '' ? trim($_POST['appointment_date']) : _pick('date');
    $appointment_time    = ($_POST['appointment_time'] ?? '') !== '' ? trim($_POST['appointment_time']) : _pick('time');
    $appointment_service = ($_POST['appointment_service'] ?? '') !== '' ? trim($_POST['appointment_service']) : (_pick('service') ?: 'DEDICATION');

    if ($appointment_date === '' || $appointment_time === '') {
      throw new RuntimeException("Missing appointment date/time. Please reselect your slot from the appointment page.");
    }

    _persist_if_present('date',    $appointment_date);
    _persist_if_present('time',    $appointment_time);
    _persist_if_present('service', $appointment_service);

    // ===== Required child fields: use parts, NOT child_full_name =====
    $child_lastname   = req('child_lastname',  'Child last name');
    $child_firstname  = req('child_firstname', 'Child first name');
    $child_middlename = trim($_POST['child_middlename'] ?? '');
    $child_ext        = trim($_POST['child_ext'] ?? '');

    $child_birthdate  = req('child_birthdate', 'Child’s birthdate'); // YYYY-MM-DD
    if ($child_birthdate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $child_birthdate)) {
      throw new RuntimeException("Birthdate must be in YYYY-MM-DD format.");
    }

    /* ================================================
       Child birthdate age guard (6 months–3 years)
       ================================================ */
    try {
      $today = new DateTime('today');                  // Current date (no time)
      $maxDob = (clone $today)->modify('-6 months');   // Youngest allowed (6 months old)
      $minDob = (clone $today)->modify('-3 years');    // Oldest allowed (3 years old)

      $dobObj = DateTime::createFromFormat('Y-m-d', $child_birthdate);
      $isValidFormat = $dobObj && $dobObj->format('Y-m-d') === $child_birthdate;

      if (!$isValidFormat) {
        throw new RuntimeException('Child’s birthdate is invalid.');
      }

      // Ensure DOB is not in the future and within [minDob, maxDob]
      if ($dobObj > $today) {
        throw new RuntimeException('Child’s birthdate cannot be in the future.');
      }
      if ($dobObj > $maxDob) {
        throw new RuntimeException('Child must be at least 6 months old.');
      }
      if ($dobObj < $minDob) {
        throw new RuntimeException('Child must be 3 years old or younger.');
      }
    } catch (Throwable $ageEx) {
      throw $ageEx;
    }
    /* ===== END Child birthdate age guard ===== */

    // Guardian parts — all optional; if empty, try to backfill from individual_table
    $g_ln = trim($_POST['guardian_lastname']   ?? '');
    $g_fn = trim($_POST['guardian_firstname']  ?? '');
    $g_mn = trim($_POST['guardian_middlename'] ?? '');
    $g_ex = trim($_POST['guardian_ext']        ?? '');
    if ($g_ln === '' && $g_fn === '' && $g_mn === '' && $g_ex === '') {
      $ind = htccc_get_individual_nameparts($db_connection, (int)$individualId);
      $g_ln = $ind['ln']; $g_fn = $ind['fn']; $g_mn = $ind['mn']; $g_ex = $ind['ex'];
    }

    // Build a human-friendly summary for the notification (child name + dates)
    $c_ln = trim($child_lastname ?? '');
    $c_fn = trim($child_firstname ?? '');
    $c_mn = trim($child_middlename ?? '');
    $c_ex = trim($child_ext ?? '');

    $childName = $c_ln;
    if ($c_fn !== '') {
      $childName .= ($childName !== '' ? ', ' : '') . $c_fn;
    }
    if ($c_mn !== '') {
      $childName .= ' ' . $c_mn;
    }
    if ($c_ex !== '') {
      $childName .= ' ' . $c_ex;
    }
    $childName = trim($childName);

    $summaryParts = [];
    if ($childName !== '') {
      $summaryParts[] = "Child: {$childName}";
    }
    if ($child_birthdate) {
      $summaryParts[] = "Birthdate: {$child_birthdate}";
    }
    if ($appointment_date && $appointment_time) {
      $summaryParts[] = "Appointment: {$appointment_date} {$appointment_time}";
    }
    $formSummary = implode(' | ', $summaryParts);

    // ===== Handle required birth certificate upload (image or PDF) — same pattern as house form =====
    $bcertPath = null;
    if (!isset($_FILES['baptismal_cert']) || !is_array($_FILES['baptismal_cert'])) {
      throw new RuntimeException("Child’s birth certificate file is required.");
    }
    $bcertFile = $_FILES['baptismal_cert'];

    if ($bcertFile['error'] === UPLOAD_ERR_NO_FILE) {
      throw new RuntimeException("Child’s birth certificate file is required.");
    }
    if ($bcertFile['error'] !== UPLOAD_ERR_OK) {
      throw new RuntimeException('Error uploading birth certificate. Please try again.');
    }

    // Basic size check (max 8MB)
    $maxSize = 8 * 1024 * 1024;
    if (!empty($bcertFile['size']) && $bcertFile['size'] > $maxSize) {
      throw new RuntimeException('Birth certificate file is too large. Maximum size is 8MB.');
    }

    // MIME type check
    $tmpName = $bcertFile['tmp_name'];
    $mime = null;
    if (is_uploaded_file($tmpName)) {
      if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
          $mime = finfo_file($finfo, $tmpName);
          finfo_close($finfo);
        }
      }
    }
    $allowedMime = [
      'image/jpeg',
      'image/png',
      'image/gif',
      'image/webp',
      'application/pdf'
    ];
    if ($mime !== null && !in_array($mime, $allowedMime, true)) {
      throw new RuntimeException('Birth certificate must be an image or PDF file.');
    }

    // Use original uploaded filename (with collision suffix) under uploads/dedication/
    $origNameRaw = isset($bcertFile['name']) ? trim((string)$bcertFile['name']) : '';
    if ($origNameRaw === '') {
      $origNameRaw = 'uploaded_file';
    }

    // strip any path the browser might send
    $onlyName = basename($origNameRaw);

    $safeName = $onlyName;
    if ($safeName === '' || $safeName === '.' || $safeName === '..') {
      $safeName = 'uploaded_file';
    }

    $uploadDir = __DIR__ . '/uploads/dedication';
    if (!is_dir($uploadDir)) {
      if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Failed to create upload directory.');
      }
    }

    // Avoid overwriting existing files with same name
    $finalName = $safeName;
    $dotPos = strrpos($safeName, '.');
    if ($dotPos !== false) {
      $namePart = substr($safeName, 0, $dotPos);
      $extPart  = substr($safeName, $dotPos); // includes the dot
    } else {
      $namePart = $safeName;
      $extPart  = '';
    }
    $counter = 1;
    while (file_exists($uploadDir . '/' . $finalName)) {
      $finalName = $namePart . '_' . $counter . $extPart;
      $counter++;
    }

    $targetPath   = $uploadDir . '/' . $finalName;
    $bcertAbsPath = $targetPath; // remember absolute path for possible cleanup
    if (!move_uploaded_file($tmpName, $targetPath)) {
      throw new RuntimeException('Failed to save uploaded birth certificate.');
    }

    // Store a WEB-RELATIVE path in the database (like house / funeral form)
    $docRoot    = rtrim(str_replace('\\','/', $_SERVER['DOCUMENT_ROOT']), '/');
    $targetNorm = str_replace('\\','/', $targetPath);

    if ($docRoot !== '' && strpos($targetNorm, $docRoot) === 0) {
      // e.g. "HTCCC-SYSTEM/uploads/dedication/..."
      $bcertPath = ltrim(substr($targetNorm, strlen($docRoot)), '/');
    } else {
      // fallback
      $bcertPath = $targetNorm;
    }

    /* ❗===========================================================
       INSERT: store only parts (no child_full_name / guardian_fullname)
       and save date/time into service_date / service_time
       =========================================================== */
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
      try {
        notifyAdmins($db_connection, 'dedication', $dedicationId, $formSummary);
      } catch (Throwable $__e) {
        // Swallow notification errors to avoid blocking user submission
      }
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
    if ($bcertAbsPath && file_exists($bcertAbsPath)) {
      @unlink($bcertAbsPath);
    }
  }
}

/* ---------------------------
   Selections for rendering
----------------------------*/
$selDate    = _pick('date');
$selTime    = _pick('time');
$selService = _pick('service');
if ($selService === '') $selService = 'DEDICATION';

// ==== detect quota flag for auto-open modal ====
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

  <!-- Standard HTCCC "Appointment Submitted" dialog helper (glass style) -->
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
        customClass: {
          popup: 'swal2-transparent-blur'
        }
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
      width:100%; border-radius:12px; padding:clamp(12px,2.5vw,14px); font-weight:600; font-size:clamp(.98rem,2.5vw,1rem); color:#1f2937; border:none; outline:none; background:var(--field); min-height:44px;
    }
    textarea{ resize:vertical; min-height:100px; }

    .actions{ display:flex; justify-content:center; padding-top:clamp(8px,2vw,12px) }
    .btn-primary-ht{ background-color:var(--accent); color:#000; border-radius:12px; border:none; font-weight:800; min-height:44px; padding:12px 18px }
    .btn-primary-ht:hover{ filter:brightness(.95) }
    @media (max-width:575.98px){ .btn-primary-ht{ width:100% } }

    /* DPA Modal (client pattern v2) */
    .dpa-backdrop{ position:fixed; inset:0; background:rgba(0,0,0,.65); display:none; align-items:center; justify-content:center; z-index:9999; padding:16px }
    .dpa-modal{ max-width:720px; width:100%; background:#111631; color:#fff; border:2px solid var(--border); border-radius:14px; box-shadow:0 20px 60px rgba(0,0,0,.45); overflow:hidden }
    .dpa-header{ background:var(--ink); padding:14px 18px; font-weight:800; font-size:1.05rem; display:flex; align-items:center; justify-content:space-between }
    .dpa-body{ padding:16px 18px; line-height:1.55; font-size:.96rem }
    .dpa-body p{ margin:0 0 12px }
    .dpa-footer{ padding:14px 18px; display:flex; gap:10px; justify-content:flex-end; background:rgba(255,255,255,0.04) }
    .dpa-btn{ border:none; border-radius:10px; padding:10px 14px; cursor:pointer; font-weight:800; min-height:44px }
    .dpa-btn.primary{ background:var(--accent); color:#000 }
    .dpa-btn.secondary{ background:#2a2f57; color:#fff }
    .dpa-close-x{ background:transparent; border:none; color:#fff; font-size:20px; line-height:1; cursor:pointer }

    .consent-row{ margin-top:10px; margin-bottom:4px; color:#e5e7eb; font-size:clamp(.9rem,2.4vw,.95rem) }
    .consent-row .form-check-input{ margin-top:.25rem; min-width:1.15rem; min-height:1.15rem }

    .name-breakdown-hint{ color:#dce4ff; font-size:.85rem; margin-top:.35rem }
    .name-breakdown-grid{ display:grid; gap:.6rem; grid-template-columns:1fr }
    @media (min-width:768px){ .name-breakdown-grid{ grid-template-columns:1fr 1fr } }
    .name-breakdown-grid label small.req{ color:#ffb3b3; font-weight:700 }
    .name-preview{ font-size:.9rem; color:#bcd7ff; margin-top:.35rem }

    @media (max-width:575.98px){ nav{ padding:10px 14px } main{ padding:0 12px } .form-card{ padding:14px } .summary{ padding:10px 12px } body{ background-attachment:scroll } }

    /* Standard glassmorphism popup styling for SweetAlert2 */
    .swal2-popup.swal2-transparent-blur {
      background: rgba(15, 23, 42, 0.30);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border-radius: 16px;
      border: 1px solid rgba(148, 163, 184, 0.4);
    }
    .swal2-title,
    .swal2-html-container {
      color: #e5e7eb;
    }
  </style>
</head>
<body>
  <nav class="w-100">
    <a href="appoint-page.php" class="d-inline-flex align-items-center gap-2 text-decoration-none text-white">
      <img src="image/btn-back.png" alt="Back"><span class="fw-bold">Back</span>
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
          <!-- Left -->
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
                    <option>Jr.</option>
                    <option>Sr.</option>
                    <option>I</option>
                    <option>II</option>
                    <option>III</option>
                    <option>IV</option>
                    <option>V</option>
                  </select>
                </div>
              </div>
              <div class="name-preview" aria-live="polite">Preview: <span id="namePreview">—</span></div>

              <!-- ❗ Hidden posts for child parts (these are submitted) -->
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

          <!-- Right -->
          <div class="col-12 col-lg-6">
            <div class="form-card">
              <h3>Guardian</h3>

              <!-- Visible guardian breakdown fields (submitted as parts) -->
              <div class="row g-2">
                <div class="col-12 col-md-6">
                  <label for="guardian_lastname">Guardian Last Name</label>
                  <input type="text" id="guardian_lastname" name="guardian_lastname" class="form-control" value="">
                </div>
                <div class="col-12 col-md-6">
                  <label for="guardian_firstname">Guardian First Name</label>
                  <input type="text" id="guardian_firstname" name="guardian_firstname" class="form-control" value="">
                </div>
                <div class="col-12 col-md-6">
                  <label for="guardian_middlename">Guardian Middle Name</label>
                  <input type="text" id="guardian_middlename" name="guardian_middlename" class="form-control" value="">
                </div>
                <div class="col-12 col-md-6">
                  <label for="guardian_ext">Guardian Extension</label>
                  <input type="text" id="guardian_ext" name="guardian_ext" class="form-control" value="">
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

  <?php if (isset($_GET['success']) && $_GET['success'] === '1'): ?>
    <script>
      (function(){
        const details = {
          date: <?= json_encode($_GET['date']    ?? ($selDate    ?: '—')) ?>,
          time: <?= json_encode($_GET['time']    ?? ($selTime    ?: '—')) ?>,
          service: <?= json_encode($_GET['service'] ?? ($selService ?: 'DEDICATION')) ?>
        };

        showAppointmentSubmitted({
          serviceLabel: 'dedication',
          date: details.date,
          time: details.time,
          serviceCode: details.service,
          onNewApptHref: 'appoint-page.php',
          onMainHref: 'main-page.php'
        }).then((res) => {
          try {
            if (window.history && window.history.replaceState) {
              const url = new URL(window.location.href);
              url.searchParams.delete('success');
              url.searchParams.delete('date');
              url.searchParams.delete('time');
              url.searchParams.delete('service');
              window.history.replaceState({}, document.title, url.toString());
            }
          } catch(e) {}
          if (res.isConfirmed) {
            window.location.href = 'appoint-page.php';
          } else if (res.isDenied) {
            window.location.href = 'main-page.php';
          }
        });
      })();
    </script>
  <?php endif; ?>

  <!-- DPA Consent Modal (client, standard v2) -->
  <div class="dpa-backdrop" id="dpaBackdrop" role="dialog" aria-modal="true" aria-labelledby="dpaTitle" aria-describedby="dpaDesc">
    <div class="dpa-modal">
      <div class="dpa-header">
        <div id="dpaTitle">Data Privacy Act Consent (RA 10173)</div>
        <button class="dpa-close-x" id="dpaCloseX" aria-label="Close">×</button>
      </div>
      <div class="dpa-body" id="dpaDesc">
        <p>Holy Trinity Christian Community Church (HTCCC) is committed to protecting your personal data in compliance with the <strong>Data Privacy Act of 2012 (RA 10173)</strong> and its Implementing Rules and Regulations.</p>
        <p><strong>What we collect:</strong> Information you provide in this form (e.g., names, birthdates, contact details, and uploaded images/IDs).</p>
        <p><strong>Purpose:</strong> To process and manage your dedication appointment, verify eligibility, coordinate schedules, and comply with church and legal requirements.</p>
        <p><strong>Storage & Retention:</strong> Your data will be securely stored and retained only as long as necessary for the declared purposes or as required by law.</p>
        <p><strong>Sharing:</strong> Data may be shared with authorized church personnel and service providers strictly for the purposes stated above. We will not sell your data.</p>
        <p><strong>Your rights:</strong> You have the right to access, correct, and delete your personal data, and to withdraw consent, subject to legal and contractual limitations. For concerns, contact our Data Protection Officer at <em>htccc.dpo@example.com</em>.</p>
        <p class="mb-0">By selecting <em>“I Agree &amp; Proceed”</em>, you acknowledge that you have read and understood this notice and you consent to the collection and processing of your data for the purposes stated.</p>
      </div>
      <div class="dpa-footer">
        <button class="dpa-btn secondary" id="dpaCancel">Cancel</button>
        <button class="dpa-btn primary" id="dpaAgree">I Agree &amp; Proceed</button>
      </div>
    </div>
  </div>

  <!-- QUOTA LIMIT MODAL -->
  <div class="modal fade" id="quotaLimitModal" tabindex="-1" aria-labelledby="quotaLimitLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content" style="border:2px solid var(--border); background:#0e1330; color:#fff;">
        <div class="modal-header" style="background:var(--ink);">
          <h5 class="modal-title fw-bold" id="quotaLimitLabel">Appointment Limit Reached</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2"><b>You have exceeded the appointment request.</b></p>
          <p class="mb-2">Please wait until your appointments are processed so you can appoint again.</p>
        </div>
        <div class="modal-footer" style="background:rgba(255,255,255,0.04);">
          <a href="main-page.php" class="btn btn-secondary">Go to main page</a>
          <button type="button" class="btn btn-primary" style="background:var(--accent); color:#000; font-weight:800;" data-bs-dismiss="modal">Okay</button>
        </div>
      </div>
    </div>
  </div>

  <!-- REVIEW & CONFIRM MODAL -->
  <div class="modal fade" id="reviewModal" tabindex="-1" aria-labelledby="reviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content" style="border:2px solid var(--border); background:#0e1330; color:#fff;">
        <div class="modal-header" style="background:var(--ink);">
          <h5 class="modal-title fw-bold" id="reviewModalLabel">Review Your Information</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <div class="border rounded p-3">
                <h6 class="fw-bold mb-2">Appointment</h6>
                <div class="row">
                  <div class="col-md-4"><small class="text-light opacity-75">Date</small><div id="rev_date" class="fw-bold"></div></div>
                  <div class="col-md-4"><small class="text-light opacity-75">Time</small><div id="rev_time" class="fw-bold"></div></div>
                  <div class="col-md-4"><small class="text-light opacity-75">Service</small><div id="rev_service" class="fw-bold"></div></div>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <div class="border rounded p-3 h-100">
                <h6 class="fw-bold mb-2">Child</h6>
                <div><small class="text-light opacity-75">Full Name</small><div id="rev_child_full_name" class="fw-bold"></div></div>
                <div class="mt-2"><small class="text-light opacity-75">Birthdate</small><div id="rev_child_birthdate" class="fw-bold"></div></div>
                <div class="mt-2"><small class="text-light opacity-75">Birth Certificate File</small><div id="rev_bcert" class="fw-bold"></div></div>
              </div>
            </div>

            <div class="col-md-6">
              <div class="border rounded p-3 h-100">
                <h6 class="fw-bold mb-2">Guardian</h6>
                <div><small class="text-light opacity-75">Full Name</small><div id="rev_guardian_fullname" class="fw-bold"></div></div>
              </div>
            </div>

            <div class="col-12">
              <div class="alert alert-info py-2 mb-0" role="alert" style="background:#17345c; color:#dceaff; border-color:#315d93;">
                Please make sure all details are correct. You can go back to edit if needed.
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer" style="background:rgba(255,255,255,0.04);">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Edit</button>
          <button type="button" id="confirmSubmitBtn" class="btn btn-primary" style="background:var(--accent); color:#000; font-weight:800;">Confirm &amp; Submit</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function() {
      /* ===== DPA GATE (client standard v2) ===== */
      const backdrop = document.getElementById('dpaBackdrop');
      const btnAgree = document.getElementById('dpaAgree');
      const btnCancel = document.getElementById('dpaCancel');
      const btnCloseX = document.getElementById('dpaCloseX');
      const linkViewDpa = document.getElementById('viewDpaLink');
      const dpaFormRef = document.getElementById('dedicationForm'); // renamed to avoid collision
      const consentInlineDpa = document.getElementById('consent_inline'); // renamed to avoid collision

      function lockBody(lock) { document.body.style.overflow = lock ? 'hidden' : ''; }
      function openDPA() { if(!backdrop) return; backdrop.style.display = 'flex'; lockBody(true); setTimeout(() => { btnAgree && btnAgree.focus(); }, 50); }
      function closeDPA() { if(!backdrop) return; backdrop.style.display = 'none'; lockBody(false); }

      const DPA_GATE_KEY = 'htccc_dpa_gate_dedication_v2';
      function hasGateConsent(){
        try { return localStorage.getItem(DPA_GATE_KEY)==='1' || sessionStorage.getItem(DPA_GATE_KEY)==='1'; } catch(e){ return false; }
      }
      function markGateConsent(){
        try { localStorage.setItem(DPA_GATE_KEY,'1'); sessionStorage.setItem(DPA_GATE_KEY,'1'); } catch(e){}
        if (consentInlineDpa) consentInlineDpa.checked = true;
      }

      document.addEventListener('DOMContentLoaded', function(){
        const urlHasSuccess = new URLSearchParams(location.search).get('success') === '1';
        if (!hasGateConsent() && !urlHasSuccess) { openDPA(); }
        else if (hasGateConsent()) { if (consentInlineDpa) consentInlineDpa.checked = true; }
      });

      btnAgree && btnAgree.addEventListener('click', function(){ markGateConsent(); closeDPA(); });
      function cancelFlow() { window.location.href = 'appoint-page.php'; }
      btnCancel && btnCancel.addEventListener('click', cancelFlow);
      btnCloseX && btnCloseX.addEventListener('click', cancelFlow);
      linkViewDpa && linkViewDpa.addEventListener('click', function(e){ e.preventDefault(); openDPA(); });

      /* ===== File validation (match server: image or PDF, max 8MB) ===== */
      const MAX_SIZE = 8 * 1024 * 1024;
      const ALLOWED = ['jpg','jpeg','png','pdf'];
      function checkFileInput(input) {
        const f = input?.files?.[0];
        if (!f) return true;
        const ext = (f.name.split('.').pop() || '').toLowerCase();
        if (!ALLOWED.includes(ext)) {
          input.value = '';
          Swal.fire({icon:'error', title:'Invalid file type', text:'Allowed: JPG, JPEG, PNG, PDF (max 8 MB).', confirmButtonColor:'#7FC1FF'});
          return false;
        }
        if (f.size > MAX_SIZE) {
          input.value = '';
          Swal.fire({icon:'error', title:'File too large', text:'Maximum size is 8 MB.', confirmButtonColor:'#7FC1FF'});
          return false;
        }
        return true;
      }
      const bcEl = document.getElementById('baptismal_cert');
      bcEl && bcEl.addEventListener('change', () => checkFileInput(bcEl));

      /* =====================
         REVIEW & SUBMIT FLOW
         ===================== */
      const form = document.getElementById('dedicationForm');
      const confirmBtn = document.getElementById('confirmSubmitBtn');
      const reviewModalEl = document.getElementById('reviewModal');
      let bsModal = null;

      const fields = {
        child_birthdate: document.getElementById('child_birthdate'),
        appointment_date: form?.querySelector('input[name="appointment_date"]'),
        appointment_time: form?.querySelector('input[name="appointment_time"]'),
        appointment_service: form?.querySelector('input[name="appointment_service"]'),
        bcert: document.getElementById('baptismal_cert'),
        consent_inline: document.getElementById('consent_inline')
      };

      function setText(id, val){
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = (val && String(val).trim() !== '') ? String(val).trim() : '—';
      }

      function getChildFull(){
        const ln = (document.getElementById('cn_last')?.value || '').trim();
        const fn = (document.getElementById('cn_first')?.value || '').trim();
        const mn = (document.getElementById('cn_middle')?.value || '').trim();
        const ex = (document.getElementById('cn_ext')?.value || '').trim();
        const after = [fn, mn].filter(Boolean).join(' '); const suffix = ex ? (' ' + ex) : '';
        if (ln && after) return `${ln}, ${after}${suffix}`;
        if (ln) return `${ln}${suffix}`;
        if (after) return `${after}${suffix}`;
        return '';
      }
      function getGuardianFull(){
        const ln = (document.getElementById('guardian_lastname')?.value || '').trim();
        const fn = (document.getElementById('guardian_firstname')?.value || '').trim();
        const mn = (document.getElementById('guardian_middlename')?.value || '').trim();
        const ex = (document.getElementById('guardian_ext')?.value || '').trim();
        const after = [fn, mn].filter(Boolean).join(' '); const suffix = ex ? (' ' + ex) : '';
        if (ln && after) return `${ln}, ${after}${suffix}`;
        if (ln) return `${ln}${suffix}`;
        if (after) return `${after}${suffix}`;
        return '';
      }

      function populateReview(){
        setText('rev_date', fields.appointment_date?.value || '');
        setText('rev_time', fields.appointment_time?.value || '');
        setText('rev_service', fields.appointment_service?.value || '');
        setText('rev_child_full_name', getChildFull());
        setText('rev_child_birthdate', fields.child_birthdate?.value || '');
        const f1 = fields.bcert?.files?.[0] ? fields.bcert.files[0].name : '';
        setText('rev_bcert', f1);
        setText('rev_guardian_fullname', getGuardianFull());
      }

      function ensureModal(){
        if (!bsModal && reviewModalEl && window.bootstrap && bootstrap.Modal) {
          bsModal = new bootstrap.Modal(reviewModalEl, { backdrop: 'static', keyboard: false });
        }
        return bsModal;
      }

      /* ❗ Compose CHILD name + hidden parts */
      const elLast = document.getElementById('cn_last');
      const elFirst = document.getElementById('cn_first');
      const elMiddle = document.getElementById('cn_middle');
      const elExt = document.getElementById('cn_ext');
      const elPrev = document.getElementById('namePreview');
      const h_ln = document.getElementById('hid_child_lastname');
      const h_fn = document.getElementById('hid_child_firstname');
      const h_mn = document.getElementById('hid_child_middlename');
      const h_ex = document.getElementById('hid_child_ext');

      function val(x){ return (x && typeof x.value === 'string') ? x.value.trim() : ''; }
      function titleCaseWords(s){
        return s.replace(/\s+/g,' ').trim()
                .replace(/\b([A-Za-z])([A-Za-z]*)/g, (_,a,b)=>a.toUpperCase()+b.toLowerCase());
      }
      function compose(){
        const ln = titleCaseWords(val(elLast));
        const fn = titleCaseWords(val(elFirst));
        const mn = titleCaseWords(val(elMiddle));
        const ex = val(elExt);

        const afterComma = [fn, mn].filter(Boolean).join(' ');
        const suffix = ex ? (' ' + ex) : '';
        let assembled = '';
        if (ln && afterComma) assembled = `${ln}, ${afterComma}${suffix}`;
        else if (ln)           assembled = `${ln}${suffix}`;
        else if (afterComma)   assembled = `${afterComma}${suffix}`;

        if (elPrev) elPrev.textContent = assembled || '—';

        // mirror to hidden fields
        if (h_ln) h_ln.value = ln;
        if (h_fn) h_fn.value = fn;
        if (h_mn) h_mn.value = mn;
        if (h_ex) h_ex.value = ex;
      }
      ['input','change'].forEach(ev => {
        elLast  && elLast.addEventListener(ev, compose);
        elFirst && elFirst.addEventListener(ev, compose);
        elMiddle&& elMiddle.addEventListener(ev, compose);
        elExt   && elExt.addEventListener(ev, compose);
      });
      function forceComposeChild(){ try { compose(); } catch(e){} }
      compose();

      /* ❗ Guardian autofill (parts only) */
      (function(){
        const gln = document.getElementById('guardian_lastname');
        const gfn = document.getElementById('guardian_firstname');
        const gmn = document.getElementById('guardian_middlename');
        const gex = document.getElementById('guardian_ext');

        function mirrorGuard(){ /* no-op, but placeholder for future logic */ }
        ['input','change'].forEach(ev=>{ [gln,gfn,gmn,gex].forEach(el=> el && el.addEventListener(ev, mirrorGuard)); });

        document.addEventListener('DOMContentLoaded', async function(){
          try {
            if ((!gln?.value) && (!gfn?.value) && (!gmn?.value) && (!gex?.value)) {
              const url = new URL(window.location.href);
              url.searchParams.set('__ajax','guardian');
              const res = await fetch(url.toString(), { credentials:'same-origin' });
              if (res.ok) {
                const data = await res.json();
                if (gln) gln.value = data.lastname   || '';
                if (gfn) gfn.value = data.firstname  || '';
                if (gmn) gmn.value = data.middlename || '';
                if (gex) gex.value = data.ext        || '';
              }
            }
          } catch(e){}
        });
      })();

      /* ❗ Submit pre-flight guard with user-friendly messages (capture) */
      const submitBtnGuard = form?.querySelector('.actions button[type="submit"]');
      const consentInline = document.getElementById('consent_inline');
      if (submitBtnGuard) {
        submitBtnGuard.addEventListener('click', function(e){
          forceComposeChild();

          form.classList.add('was-validated');
          if (typeof form.reportValidity === 'function') form.reportValidity();

          if (!consentInline || !consentInline.checked) {
            e.stopImmediatePropagation(); e.preventDefault();
            // Open DPA modal if consent missing (standard v2 behavior)
            (function openDPAFallback(){
              const el = document.getElementById('dpaBackdrop');
              if (el) { el.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
            })();
            Swal.fire({icon:'error', title:'Consent required', text:'Please agree to the Data Privacy Act consent to proceed.', confirmButtonColor:'#7FC1FF'});
            return;
          }
          const fileEl = document.getElementById('baptismal_cert');
          const hasFile = !!(fileEl && fileEl.files && fileEl.files[0]);
          if (!hasFile) {
            e.stopImmediatePropagation(); e.preventDefault();
            Swal.fire({icon:'error', title:'Missing Birth Certificate', text:'Please upload the child’s birth certificate (image or PDF, max 8 MB).', confirmButtonColor:'#7FC1FF'});
            return;
          }
        }, true);
      }

      function ensureOk(){ return form.checkValidity() && consentInline?.checked && (fields.bcert?.files?.[0]); }

      const submitBtn = form?.querySelector('.actions button[type="submit"]');
      if (submitBtn) {
        submitBtn.addEventListener('click', function(e){
          if (!ensureOk()) return;
          e.preventDefault();
          e.stopPropagation();
          forceComposeChild();
          populateReview();
          const modal = ensureModal();
          if (modal) modal.show();
        });
      }

      if (confirmBtn) {
        confirmBtn.addEventListener('click', function(){
          confirmBtn.disabled = true;
          confirmBtn.textContent = 'Submitting...';
          const marker = document.createElement('input');
          marker.type = 'hidden'; marker.name = 'review_confirmed'; marker.value = '1';
          form.appendChild(marker);
          form.submit();
        });
      }

      <?php if ($__quotaExceeded): ?>
      document.addEventListener('DOMContentLoaded', function(){
        try {
          const mEl = document.getElementById('quotaLimitModal');
          if (mEl && window.bootstrap && bootstrap.Modal) {
            const m = new bootstrap.Modal(mEl, {backdrop:'static', keyboard:false});
            m.show();
          }
        } catch(e){}
      });
      <?php endif; ?>
    })();
  </script>

  <?php if (!empty($submitError)): ?>
    <script>
      Swal.fire({ icon:'error', title:'Submission Error', text: <?= json_encode($submitError) ?>, confirmButtonColor:'#7FC1FF' });
    </script>
  <?php endif; ?>

  <!-- ==============================================
       DPA modal init fix (shim)
       Ensures the DPA gate binds AFTER the modal exists
       ============================================== -->
  <script>
    (function(){
      document.addEventListener('DOMContentLoaded', function(){
        var backdrop = document.getElementById('dpaBackdrop');
        if (!backdrop) return;

        var btnAgree = document.getElementById('dpaAgree');
        var btnCancel = document.getElementById('dpaCancel');
        var btnCloseX = document.getElementById('dpaCloseX');
        var linkViewDpa = document.getElementById('viewDpaLink');
        var consentInline = document.getElementById('consent_inline');

        var KEY = 'htccc_dpa_gate_dedication_v2';
        function hasGate(){ try { return localStorage.getItem(KEY)==='1' || sessionStorage.getItem(KEY)==='1'; } catch(e){ return false; } }
        function markGate(){ try { localStorage.setItem(KEY,'1'); sessionStorage.setItem(KEY,'1'); } catch(e){} if(consentInline) consentInline.checked = true; }

        function lockBody(lock){ document.body.style.overflow = lock ? 'hidden' : ''; }
        function open(){ backdrop.style.display='flex'; lockBody(true); setTimeout(function(){ btnAgree && btnAgree.focus(); }, 50); }
        function close(){ backdrop.style.display='none'; lockBody(false); }

        btnAgree && btnAgree.addEventListener('click', function(){ markGate(); close(); });
        function cancelFlow(){ window.location.href = 'appoint-page.php'; }
        btnCancel && btnCancel.addEventListener('click', cancelFlow);
        btnCloseX && btnCloseX.addEventListener('click', cancelFlow);
        linkViewDpa && linkViewDpa.addEventListener('click', function(e){ e.preventDefault(); open(); });

        var urlHasSuccess = new URLSearchParams(location.search).get('success') === '1';
        if (!hasGate() && !urlHasSuccess) { open(); }
        else if (hasGate()) { if (consentInline) consentInline.checked = true; }
      });
    })();
  </script>
  <!-- === END DPA modal init fix (shim) === -->

  <!-- ==============================================
       Child Birthdate SweetAlert-only validator (6m–3y)
       Keeps the calendar fully enabled; shows alerts on invalid picks
       ============================================== -->
  <script>
    (function(){
      document.addEventListener('DOMContentLoaded', function(){
        var dob = document.getElementById('child_birthdate');
        if (!dob) return;

        dob.addEventListener('change', function(){
          if (!this.value) return;

          var parts = this.value.split('-');
          if (parts.length !== 3) return;
          var d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
          var today = new Date(); today.setHours(0,0,0,0);

          if (isNaN(d.getTime())) {
            this.value = '';
            try {
              Swal.fire({icon:'error', title:'Invalid Birthdate', text:'Please enter a valid date in YYYY-MM-DD format.', confirmButtonColor:'#7FC1FF'});
            } catch(e) { alert('Please enter a valid date in YYYY-MM-DD format.'); }
            return;
          }

          // Compute bounds: 6 months ago and 3 years ago
          var sixMonthsAgo = new Date(today.getFullYear(), today.getMonth(), today.getDate());
          sixMonthsAgo.setMonth(sixMonthsAgo.getMonth() - 6);

          var threeYearsAgo = new Date(today.getFullYear() - 3, today.getMonth(), today.getDate());

          if (d > today) {
            this.value = '';
            try {
              Swal.fire({icon:'error', title:'Invalid Birthdate', text:'Future dates are not allowed.', confirmButtonColor:'#7FC1FF'});
            } catch(e) { alert('Future dates are not allowed.'); }
            return;
          }
          if (d > sixMonthsAgo) {
            this.value = '';
            try {
              Swal.fire({icon:'warning', title:'Too Young', text:'The child must be at least 6 months old.', confirmButtonColor:'#7FC1FF'});
            } catch(e) { alert('The child must be at least 6 months old.'); }
            return;
          }
          if (d < threeYearsAgo) {
            this.value = '';
            try {
              Swal.fire({icon:'warning', title:'Too Old', text:'The child must be 3 years old or younger.', confirmButtonColor:'#7FC1FF'});
            } catch(e) { alert('The child must be 3 years old or younger.'); }
            return;
          }
        });
      });
    })();
  </script>
  <!-- === END SweetAlert-only DOB validator === -->
</body>
</html>
