<?php
// ============================
// form-funeral.php (one-file)
// ============================

session_start();

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

/* ---------------------------
   Selection helper + session persistence (same as baptism)
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
   Submission handler (before any HTML)
----------------------------*/
$submitError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Require login
  $sessionId = $_SESSION['individual_id'] ?? null;
  if (!$sessionId) {
    $returnTo = basename(__FILE__);
    header('Location: all_log-in.php?return_to=' . rawurlencode($returnTo));
    exit;
  }

  // DB connect
  try {
    $pdo = new PDO(
      "mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4",
      "root",
      "",
      [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]
    );
  } catch (Throwable $e) {
    http_response_code(500);
    $submitError = "Database connection error.";
  }

  /* ================================
     helper to pull name parts from individual_table
     (PDO version, STRICTLY from individual_table)
     NOW ALSO RETURNS phone & email
     ================================ */
  if (!function_exists('htccc_get_individual_nameparts_pdo')) {
    function htccc_get_individual_nameparts_pdo(PDO $pdo, int $individualId): array {
      // Added phone & email here
      $out = ['ln'=>'','fn'=>'','mn'=>'','ex'=>'','phone'=>'','email'=>''];
      try {
        $stmt = $pdo->prepare("
          SELECT
            individual_lastname,
            individual_firstname,
            individual_middlename,
            individual_extension,
            individual_phone_number,
            individual_email_address
          FROM individual_table
          WHERE individual_id = ?
          LIMIT 1
        ");
        $stmt->execute([$individualId]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
          $out['ln']    = trim((string)($row['individual_lastname']   ?? ''));
          $out['fn']    = trim((string)($row['individual_firstname']  ?? ''));
          $out['mn']    = trim((string)($row['individual_middlename'] ?? ''));
          $out['ex']    = trim((string)($row['individual_extension']  ?? ''));
          // New: read phone and email from individual_table
          $out['phone'] = trim((string)($row['individual_phone_number']   ?? ''));
          $out['email'] = trim((string)($row['individual_email_address']  ?? ''));
        }
      } catch (Throwable $e) { /* silent */ }
      return $out;
    }
  }
  /* ===== END helper (individual_table only) ===== */

  if (!$submitError) {
    // Ensure notifier identity is available for this individual workflow (same as baptism)
    if (empty($_SESSION['user_type'])) $_SESSION['user_type'] = 'individual';
    if (empty($_SESSION['user_id']))   $_SESSION['user_id']   = (int)$sessionId;

    function req($key) {
      if (!isset($_POST[$key]) || $_POST[$key] === '') {
        throw new RuntimeException("Missing required field: {$key}");
      }
      return trim($_POST[$key]);
    }

    // ==== helper to count ACTIVE appointments across ALL service_* tables (same as baptism) ====
    if (!function_exists('htccc_count_active_across_services')) {
      function htccc_count_active_across_services(PDO $pdo, int $individualId, array $closedStatuses): int {
        $tables = [];
        $stmtTbl = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'service\\_%'");
        foreach ($stmtTbl as $r) {
          $t = $r['TABLE_NAME'] ?? '';
          if (!$t) continue;
          $chk = $pdo->prepare("
            SELECT COUNT(*) AS c
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :tname
              AND COLUMN_NAME IN ('individual_id','status')
          ");
          $chk->execute([':tname' => $t]);
          if ((int)$chk->fetchColumn() >= 2) $tables[] = $t;
        }
        if (!$tables) return 0;

        $ph = implode(',', array_fill(0, count($closedStatuses), '?'));
        $total = 0;
        foreach ($tables as $t) {
          $sql = "SELECT COUNT(*) AS c FROM `{$t}` WHERE individual_id = ? AND (status IS NULL OR status NOT IN ($ph))";
          $params = array_merge([$individualId], $closedStatuses);
          $q = $pdo->prepare($sql);
          $q->execute($params);
          $total += (int)$q->fetchColumn();
        }
        return $total;
      }
    }

    try {
      // Appointment metadata — POST with session fallbacks
      $appointment_date    = ($_POST['appointment_date'] ?? '') !== '' ? trim($_POST['appointment_date']) : _pick('date');
      $appointment_time    = ($_POST['appointment_time'] ?? '') !== '' ? trim($_POST['appointment_time']) : _pick('time');
      $appointment_service = ($_POST['appointment_service'] ?? '') !== '' ? trim($_POST['appointment_service']) : (_pick('service') ?: 'FUNERAL SERVICE');

      if ($appointment_date === '' || $appointment_time === '') {
        throw new RuntimeException("Missing appointment date/time. Please reselect your slot from the appointment page.");
      }

      // Persist back (keeps state after redirect)
      _persist_if_present('date',    $appointment_date);
      _persist_if_present('time',    $appointment_time);
      _persist_if_present('service', $appointment_service);

      // ==== split name fields; last/first required; middle/ext optional ====
      $lname = isset($_POST['deceased_lastname']) ? trim($_POST['deceased_lastname']) : '';
      $fname = isset($_POST['deceased_firstname']) ? trim($_POST['deceased_firstname']) : '';
      $mname = isset($_POST['deceased_middlename']) ? trim($_POST['deceased_middlename']) : '';
      $ext   = isset($_POST['deceased_ext']) ? trim($_POST['deceased_ext']) : '';

      if ($lname === '') throw new RuntimeException("Missing required field: deceased_lastname");
      if ($fname === '') throw new RuntimeException("Missing required field: deceased_firstname");

      $deceased_birthdate = req('deceased_birthdate'); // YYYY-MM-DD
      $home_address       = req('home_address');
      $remarks            = isset($_POST['remarks']) ? trim($_POST['remarks']) : null;

      // Handle required death certificate upload (image or PDF)
      $deathCertificatePath = null;
      if (!isset($_FILES['death_certificate']) || !is_array($_FILES['death_certificate'])) {
        throw new RuntimeException('Death certificate upload is required.');
      }
      $dcFile = $_FILES['death_certificate'];

      if ($dcFile['error'] === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Death certificate upload is required.');
      }
      if ($dcFile['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Error uploading death certificate. Please try again.');
      }

      // Basic size check (max 8MB)
      $maxSize = 8 * 1024 * 1024;
      if (!empty($dcFile['size']) && $dcFile['size'] > $maxSize) {
        throw new RuntimeException('Death certificate file is too large. Maximum size is 8MB.');
      }

      // MIME type check
      $tmpName = $dcFile['tmp_name'];
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
        throw new RuntimeException('Death certificate must be an image or PDF file.');
      }

      // ============================================
      // USE THE ORIGINAL UPLOADED FILENAME
      // Store it under uploads/death_certificates/
      // (no extra sanitizing that changes the name,
      //  just basename() + collision suffix)
      // ============================================
      $origNameRaw = isset($dcFile['name']) ? trim((string)$dcFile['name']) : '';
      if ($origNameRaw === '') {
        $origNameRaw = 'uploaded_file';
      }

      // strip any path the browser might send (just in case)
      $onlyName = basename($origNameRaw);

      // Keep the original name (no preg_replace sanitizing)
      $safeName = $onlyName;
      if ($safeName === '' || $safeName === '.' || $safeName === '..') {
        $safeName = 'uploaded_file';
      }

      $uploadDir = __DIR__ . '/uploads/death_certificates';
      if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
          throw new RuntimeException('Failed to create upload directory.');
        }
      }

      // Avoid overwriting existing files with same name:
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

      $targetPath = $uploadDir . '/' . $finalName;
      if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('Failed to save uploaded death certificate.');
      }

      // ✅ Store a WEB-RELATIVE path in the database (like wedding form)
      $docRoot    = rtrim(str_replace('\\','/', $_SERVER['DOCUMENT_ROOT']), '/');
      $targetNorm = str_replace('\\','/', $targetPath);

      if (strpos($targetNorm, $docRoot) === 0) {
        // remove document root so DB gets e.g. "HTCCC-SYSTEM/uploads/death_certificates/..."
        $deathCertificatePath = ltrim(substr($targetNorm, strlen($docRoot)), '/');
      } else {
        // fallback
        $deathCertificatePath = $targetNorm;
      }

      // 5 ACTIVE APPOINTMENTS CAP
      $CLOSED = ['Approved','Declined','Cancelled','Archived','Done'];
      $activeCount = htccc_count_active_across_services($pdo, (int)$sessionId, $CLOSED);
      if ($activeCount >= 5) {
        throw new RuntimeException(
          "APPT_LIMIT_EXCEEDED: You have exceeded the appointment request. Please wait until your appointments are processed (Approved, Declined, Cancelled, Archived, or Done) so you can appoint again."
        );
      }

      // Contact fields now come from individual_table (phone & email)
      $contact_person = ''; // still optional; can be filled separately if you want
      $contact_number = '';
      $email_address  = '';

      /* ============================================
         pull the *logged-in* individual's name
         strictly from individual_table and mirror
         into service_funeral with the same field names
         ALSO grab phone & email for contact_number/email_address
         ============================================ */
      $i_ln = $i_fn = $i_mn = $i_ex = '';
      try {
        $ind = htccc_get_individual_nameparts_pdo($pdo, (int)$sessionId);
        $i_ln = $ind['ln'] ?? '';
        $i_fn = $ind['fn'] ?? '';
        $i_mn = $ind['mn'] ?? '';
        $i_ex = $ind['ex'] ?? '';

        // New: set contact_number and email_address from individual_table
        if (!empty($ind['phone'])) {
          $contact_number = $ind['phone'];
        }
        if (!empty($ind['email'])) {
          $email_address = $ind['email'];
        }
      } catch (Throwable $___e) { /* silent fallback to empty */ }

      // Build a human-friendly summary for the notification (similar style to baptism)
      $dc_ln = trim($lname ?? '');
      $dc_fn = trim($fname ?? '');
      $dc_mn = trim($mname ?? '');
      $dc_ex = trim($ext ?? '');

      $deceasedName = $dc_ln;
      if ($dc_fn !== '') {
        $deceasedName .= ($deceasedName !== '' ? ', ' : '') . $dc_fn;
      }
      if ($dc_mn !== '') {
        $deceasedName .= ' ' . $dc_mn;
      }
      if ($dc_ex !== '') {
        $deceasedName .= ' ' . $dc_ex;
      }
      $deceasedName = trim($deceasedName);

      $summaryParts = [];
      if ($deceasedName !== '') {
        $summaryParts[] = "Deceased: {$deceasedName}";
      }
      if (!empty($deceased_birthdate)) {
        $summaryParts[] = "Birthdate: {$deceased_birthdate}";
      }
      if (!empty($home_address)) {
        $summaryParts[] = "Address: {$home_address}";
      }
      if (!empty($remarks)) {
        $summaryParts[] = "Remarks: {$remarks}";
      }
      if (!empty($deathCertificatePath)) {
        $summaryParts[] = "Death certificate: uploaded";
      }
      $formSummary = implode(' | ', $summaryParts);

      // ====== INSERT (split name columns only) + individual's name parts + death_certificate ======
      // NOTE: changed appointment_date/appointment_time -> service_date/service_time
      $sql = "INSERT INTO service_funeral (
                individual_id,
                service_date, service_time, appointment_service,
                deceased_lastname, deceased_firstname, deceased_middlename, deceased_ext,
                deceased_birthdate, home_address,
                contact_person, contact_number, email_address,
                death_certificate, remarks, status,
                individual_lastname, individual_firstname, individual_middlename, individual_ext
              ) VALUES (
                :individual_id,
                :appointment_date, :appointment_time, :appointment_service,
                :deceased_lastname, :deceased_firstname, :deceased_middlename, :deceased_ext,
                :deceased_birthdate, :home_address,
                :contact_person, :contact_number, :email_address,
                :death_certificate, :remarks, 'Pending',
                :i_ln, :i_fn, :i_mn, :i_ex
              )";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        ':individual_id'        => $sessionId,
        ':appointment_date'     => $appointment_date,
        ':appointment_time'     => $appointment_time,
        ':appointment_service'  => $appointment_service,
        ':deceased_lastname'    => $lname,
        ':deceased_firstname'   => $fname,
        ':deceased_middlename'  => $mname ?: null,
        ':deceased_ext'         => $ext ?: null,
        ':deceased_birthdate'   => $deceased_birthdate,
        ':home_address'         => $home_address,
        ':contact_person'       => $contact_person,
        ':contact_number'       => $contact_number,
        ':email_address'        => $email_address,
        ':death_certificate'    => $deathCertificatePath,
        ':remarks'              => $remarks,
        ':i_ln'                 => $i_ln !== '' ? $i_ln : null,
        ':i_fn'                 => $i_fn !== '' ? $i_fn : null,
        ':i_mn'                 => $i_mn !== '' ? $i_mn : null,
        ':i_ex'                 => $i_ex !== '' ? $i_ex : null,
      ]);

      // Get the new record ID and notify admins (formType = 'funeral')
      $funeralId = (int)$pdo->lastInsertId();
      if ($funeralId > 0) {
        try {
          notifyAdmins($pdo, 'funeral', $funeralId, $formSummary);
        } catch (Throwable $___e) {
          // Swallow notification errors to avoid blocking user submission
        }
      }

      // Optional: store attempts (parity with baptism)
      try {
        $_SESSION['funeral_attempts_today'] = htccc_count_active_across_services($pdo, (int)$sessionId, $CLOSED);
      } catch (Throwable $___e) {}

      // Redirect to self for SweetAlert
      header('Location: ' . basename(__FILE__) .
        '?success=1'
        . '&date=' . rawurlencode($appointment_date)
        . '&time=' . rawurlencode($appointment_time)
        . '&service=' . rawurlencode($appointment_service)
      );
      exit;

    } catch (Throwable $e) {
      http_response_code(400);
      $submitError = $e->getMessage();
    }
  }
}

/* ---------------------------
   Guard for viewing + consolidated selections
----------------------------*/
$individualId = $_SESSION['individual_id'] ?? (isset($_GET['individual_id']) ? (int)$_GET['individual_id'] : null);
if (!$individualId) {
  $returnTo = $_SERVER['REQUEST_URI'];
  header('Location: all_log-in.php?return_to=' . rawurlencode($returnTo));
  exit;
}
$selDate    = _pick('date');
$selTime    = _pick('time');
$selService = _pick('service');
if ($selService === '') $selService = 'FUNERAL SERVICE';

// Quota marker handling (same pattern as baptism)
$__quotaExceeded = false;
if (!empty($submitError) && str_starts_with($submitError, 'APPT_LIMIT_EXCEEDED:')) {
  $__quotaExceeded = true;
  $submitError = trim(substr($submitError, strlen('APPT_LIMIT_EXCEEDED:')));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta content="width=device-width, initial-scale=1" name="viewport" />
  <title>HTCCC - Funeral Service Appointment Form</title>
  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <script>
    // Standard HTCCC "Appointment Submitted" dialog helper
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
    :root { --ink:#1a1f4a; --panel:#3a3f71; --panelAlpha: rgba(58,63,113,0.9); --border:#4b5563; --bg:#0A0E3F; --accent:#7FC1FF; --field:#F3F2F5; --text:#fff; }
    * { box-sizing: border-box; }
    body {
      font-family: Arial, sans-serif;
      background-image: url("image/all-background.png");
      background-position: center; background-size: cover; background-attachment: fixed; background-repeat: no-repeat;
      margin: 0; color: var(--text);
    }
    nav { background-color: var(--bg); display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:10px clamp(12px,3vw,20px); color:#fff; }
    nav img { width: clamp(26px, 5vw, 30px); height: clamp(26px, 5vw, 30px); border-radius:50%; }
    nav h1 { margin:0; font-size: clamp(16px, 3.4vw, 20px); font-weight:800; line-height:1.1; }
    nav a { color:#fff; text-decoration:none; display:flex; align-items:center; gap:8px; }

    main { max-width:1120px; margin: clamp(12px, 2vw, 24px) auto 0; padding: 0 clamp(12px, 2.5vw, 16px); }

    .top-sections { margin-bottom: clamp(12px, 2.2vw, 24px); }
    section.border-box { border:2px solid var(--border); border-radius:.75rem; padding: clamp(12px, 2.2vw, 16px); background:var(--panelAlpha); height:100%; }
    section.border-box h2 { font-weight:700; font-size:clamp(1rem,2.5vw,1.15rem); margin-bottom:.75rem; text-align:center; color:#fff; }

    .summary { color:#fff; font-weight:700; background:var(--ink); border-radius:12px; padding: clamp(10px, 2.5vw, 12px) clamp(12px, 2.5vw, 16px); text-align:center; margin-bottom: clamp(12px, 2.2vw, 16px); word-wrap:break-word; overflow-wrap:anywhere; font-size:clamp(.95rem,2.5vw,1rem); }

    .form-grid { background-color:var(--panel); border-radius:.75rem; padding: clamp(12px, 2.5vw, 20px); border:2px solid var(--border); }

    .form-card { background:rgba(0,0,0,0.08); border:2px solid var(--border); border-radius:12px; padding: clamp(12px, 2.5vw, 16px); height:100%; }
    .form-card h3 { margin:0 0 10px; font-size:clamp(1rem,2.6vw,1.05rem); font-weight:800; }

    label { font-weight:600; font-size:clamp(.9rem,2.4vw,.92rem); display:block; color:#f9fafb; margin-bottom:6px; }
    input[type="text"], input[type="date"], textarea, select {
      width:100%; border-radius:10px; padding:12px 12px; font-weight:600; font-size:1rem; color:#1f2937; border:none; outline:none; background:var(--field); min-height:44px;
    }
    .as-bs.form-control { background:var(--field); border:1px solid #ced4da; border-radius:.6rem; font-weight:600; }
    .as-bs.form-control:focus { border-color:#86b7fe; box-shadow:0 0 0 .25rem rgba(13,110,253,.25); }

    .actions { display:flex; justify-content:center; padding-top: clamp(8px, 2vw, 12px); }
    .btn-primary-ht { background-color:var(--accent); color:#000; border-radius:12px; border:none; font-weight:800; min-height:44px; padding:12px 18px; }
    .btn-primary-ht:hover { filter:brightness(0.95); }
    @media (max-width: 575.98px) { .btn-primary-ht { width: 100%; } }

    /* DPA modal */
    .dpa-backdrop { position:fixed; inset:0; background:rgba(0,0,0,0.65); display:none; align-items:center; justify-content:center; z-index:9999; padding:16px; }
    .dpa-modal { max-width:720px; width:100%; background:#111631; color:#fff; border:2px solid var(--border); border-radius:14px; box-shadow:0 20px 60px rgba(0,0,0,0.45); overflow:hidden; }
    .dpa-header { background:var(--ink); padding:14px 18px; font-weight:800; font-size:1.05rem; display:flex; align-items:center; justify-content:space-between; }
    .dpa-body { padding:16px 18px; line-height:1.55; font-size:.96rem; }
    .dpa-footer { padding:14px 18px; display:flex; gap:10px; justify-content:flex-end; background:rgba(255,255,255,0.04); }
    .dpa-btn { border:none; border-radius:10px; padding:10px 14px; cursor:pointer; font-weight:800; }
    .dpa-btn.primary { background:var(--accent); color:#000; }
    .dpa-btn.secondary { background:#2a2f57; color:#fff; }
    .dpa-close-x { background:transparent; border:none; color:#fff; font-size:20px; line-height:1; cursor:pointer; }

    /* Name breakdown */
    .name-breakdown-grid { display:grid; gap:.6rem; grid-template-columns: 1fr; }
    @media (min-width: 768px){ .name-breakdown-grid { grid-template-columns: 1fr 1fr; } }
    .name-breakdown-grid label small.req { color:#ffb3b3; font-weight:700; }
    .name-preview { font-size:.9rem; color:#bcd7ff; margin-top:.35rem; }

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
  <!-- Top Nav -->
  <nav class="w-100">
    <a href="appoint-page.php" class="d-inline-flex align-items-center gap-2 text-decoration-none text-white">
      <img src="image/btn-back.png" alt="Back"><span class="fw-bold">Back</span>
    </a>
    <div class="d-flex align-items-center gap-2">
      <img src="image/httc_main-logo.jpg" alt="HTCCC logo" style="width:50px; height:50px; border-radius:50%;">
      <h1 class="m-0 text-center text-uppercase">FUNERAL SERVICE APPOINTMENT FORM</h1>
    </div>
    <div style="width:30px;"></div>
  </nav>

  <main class="container-fluid container-md py-3 py-md-4">
    <?php if (!empty($submitError) && !$__quotaExceeded): ?>
      <div class="alert alert-danger mt-3" role="alert">
        <strong>Submission Error:</strong>
        <?= htmlspecialchars($submitError, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>

    <!-- Info box -->
    <div class="top-sections row g-3 g-md-4">
      <div class="col-12 col-md-6">
        <section class="border-box h-100">
          <h2>Church Rules</h2>
          <ul class="mb-0">
            <li>Please arrive on time for your schedule.</li>
            <li>Dress modestly and respectfully when entering the church.</li>
            <li>Electricity or sound system use may incur additional fees.</li>
            <li>Gas fee for the Pastor (if needed).</li>
            <li>Offerings are optional but encouraged.</li>
          </ul>
        </section>
      </div>
      <div class="col-12 col-md-6">
        <section class="border-box h-100">
          <h2>What to Prepare?</h2>
          <ul class="mb-0">
            <li>Deceased’s details and home address</li>
            <li>Clear image or PDF of the death certificate</li>
            <li>Any special requests for service</li>
          </ul>
        </section>
      </div>
    </div>

    <!-- Appointment summary -->
    <div class="summary px-3 py-2">
      <?php
        $safeDate = htmlspecialchars($selDate ?: '—', ENT_QUOTES, 'UTF-8');
        $safeTime = htmlspecialchars($selTime ?: '—', ENT_QUOTES, 'UTF-8');
        $safeSvc  = htmlspecialchars($selService ?: 'FUNERAL SERVICE', ENT_QUOTES, 'UTF-8');
        echo "Selected Date: <b>{$safeDate}</b> &nbsp; | &nbsp; Time: <b>{$safeTime}</b> &nbsp; | &nbsp; Service: <b>{$safeSvc}</b>";
      ?>
    </div>

    <!-- SINGLE form (self-post) -->
    <form
      id="funeralForm"
      action="<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>"
      method="post"
      class="needs-validation"
      enctype="multipart/form-data"
      novalidate
    >
      <div class="form-grid">
        <div class="row g-3 g-md-4">
          <!-- Deceased -->
          <div class="col-12 col-lg-6">
            <div class="form-card">
              <h3>Deceased Information</h3>

              <div class="name-breakdown-grid">
                <div>
                  <label for="dc_last">Last Name <small class="req">*</small></label>
                  <input type="text" id="dc_last" class="form-control as-bs" placeholder="e.g., Dela Cruz" autocomplete="family-name" required>
                </div>
                <div>
                  <label for="dc_first">First Name <small class="req">*</small></label>
                  <input type="text" id="dc_first" class="form-control as-bs" placeholder="e.g., Maria" autocomplete="given-name" required>
                </div>
                <div>
                  <label for="dc_middle">Middle Name <span class="text-light opacity-75">(optional)</span></label>
                  <input type="text" id="dc_middle" class="form-control as-bs" placeholder="e.g., Santos" autocomplete="additional-name">
                </div>
                <div>
                  <label for="dc_ext">Extension <span class="text-light opacity-75">(optional)</span></label>
                  <select id="dc_ext" class="form-control as-bs">
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

              <!-- Hidden fields to POST split name columns -->
              <input type="hidden" name="deceased_lastname" id="deceased_lastname">
              <input type="hidden" name="deceased_firstname" id="deceased_firstname">
              <input type="hidden" name="deceased_middlename" id="deceased_middlename">
              <input type="hidden" name="deceased_ext" id="deceased_ext">

              <div class="name-preview" aria-live="polite">
                Preview: <span id="namePreview">—</span>
              </div>

              <label for="deceased_birthdate" class="mt-2">Deceased’s Birthdate <span class="text-danger">*</span></label>
              <input type="date" id="deceased_birthdate" name="deceased_birthdate" class="form-control as-bs" required />

              <label for="home_address" class="mt-2">Home Address <span class="text-danger">*</span></label>
              <input type="text" id="home_address" name="home_address" class="form-control as-bs" required />
            </div>
          </div>

          <!-- Documents & Message -->
          <div class="col-12 col-lg-6">
            <div class="form-card">
              <h3>Documents &amp; Message</h3>

              <label for="death_certificate">
                Death Certificate (Image or PDF) <span class="text-danger">*</span>
              </label>
              <input
                type="file"
                id="death_certificate"
                name="death_certificate"
                class="form-control as-bs"
                accept="image/*,.pdf"
                required
              />
              <div class="form-text text-light" style="opacity:.8">
                Upload a clear photo or scanned copy of the death certificate. Accepted formats: image or PDF (max 8MB).
              </div>

              <label for="remarks" class="mt-3">Special Request or Message (optional)</label>
              <textarea id="remarks" name="remarks" placeholder="Share any details or requests" rows="6" class="form-control as-bs"></textarea>
            </div>
          </div>
        </div>

        <!-- Hidden context -->
        <input type="hidden" name="individual_id" value="<?php echo (int)$individualId; ?>">
        <input type="hidden" name="appointment_date" value="<?php echo htmlspecialchars($selDate, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="appointment_time" value="<?php echo htmlspecialchars($selTime, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="appointment_service" value="<?php echo htmlspecialchars($selService ?: 'FUNERAL SERVICE', ENT_QUOTES, 'UTF-8'); ?>">

        <!-- Inline consent with link that opens the DPA modal -->
        <div class="row mt-3">
          <div class="col-12">
            <div class="consent-row">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="consent_inline" required>
                <label class="form-check-label" for="consent_inline">
                  I agree to the collection and processing of my personal data for funeral service appointment purposes in accordance with the Data Privacy Act of 2012 (RA 10173) and
                  <a href="#" id="viewDpaLink" class="text-decoration-underline">HTCCC’s Privacy Notice</a>.
                </label>
              </div>
            </div>
          </div>
        </div>

        <!-- Submit (triggers review modal first) -->
        <div class="actions mt-1">
          <button type="submit" class="btn btn-primary-ht px-4 py-2">SUBMIT</button>
        </div>
      </div>
    </form>
  </main>

  <!-- Success Alert -->
  <?php if (isset($_GET['success']) && $_GET['success'] === '1'): ?>
    <script>
      (function(){
        const details = {
          date: <?= json_encode($_GET['date'] ?? ($selDate ?: '—')) ?>,
          time: <?= json_encode($_GET['time'] ?? ($selTime ?: '—')) ?>,
          service: <?= json_encode($_GET['service'] ?? ($selService ?: 'FUNERAL SERVICE')) ?>
        };

        showAppointmentSubmitted({
          serviceLabel: 'funeral service',
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
          } catch(e){}
          if (res.isConfirmed) {
            window.location.href = 'appoint-page.php';
          } else if (res.isDenied) {
            window.location.href = 'main-page.php';
          }
        });
      })();
    </script>
  <?php endif; ?>

  <!-- DPA consent (show once before using the form) -->
  <div class="dpa-backdrop" id="dpaBackdrop" role="dialog" aria-modal="true" aria-labelledby="dpaTitle" aria-describedby="dpaDesc">
    <div class="dpa-modal">
      <div class="dpa-header">
        <div id="dpaTitle">Data Privacy Act Consent (RA 10173)</div>
        <button class="dpa-close-x" id="dpaCloseX" aria-label="Close">×</button>
      </div>
      <div class="dpa-body" id="dpaDesc">
        <p>Holy Trinity Christian Community Church (HTCCC) is committed to protecting your personal data in compliance with the <strong>Data Privacy Act of 2012 (RA 10173)</strong> and its Implementing Rules and Regulations.</p>
        <p><strong>What we collect:</strong> Information you provide in this form (e.g., names, birthdates, contact details).</p>
        <p><strong>Purpose:</strong> To process and manage your funeral service appointment, verify eligibility, coordinate schedules, and comply with church and legal requirements.</p>
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

  <!-- Review & Confirm Modal -->
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

            <div class="col-12">
              <div class="border rounded p-3">
                <h6 class="fw-bold mb-2">Death Certificate</h6>
                <div class="row">
                  <div class="col-md-6">
                    <small class="text-light opacity-75">File</small>
                    <div id="rev_death_certificate" class="fw-bold"></div>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <div class="border rounded p-3 h-100">
                <h6 class="fw-bold mb-2">Deceased</h6>
                <div><small class="text-light opacity-75">Full Name</small><div id="rev_deceased_name" class="fw-bold"></div></div>
                <div class="mt-2"><small class="text-light opacity-75">Birthdate</small><div id="rev_deceased_birthdate" class="fw-bold"></div></div>
                <div class="mt-2"><small class="text-light opacity-75">Home Address</small><div id="rev_home_address" class="fw-bold" style="white-space:pre-wrap;"></div></div>
              </div>
            </div>

            <div class="col-md-6">
              <div class="border rounded p-3 h-100">
                <h6 class="fw-bold mb-2">Special Request</h6>
                <div><small class="text-light opacity-75">Message</small><div id="rev_remarks" class="fw-bold" style="white-space:pre-wrap;"></div></div>
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
  <!-- /QUOTA LIMIT MODAL -->

  <!-- Scripts -->
  <script>
    // DPA gating (show once) + inline link to re-open
    (function() {
      const backdrop = document.getElementById('dpaBackdrop');
      const btnAgree = document.getElementById('dpaAgree');
      const btnCancel = document.getElementById('dpaCancel');
      const btnCloseX = document.getElementById('dpaCloseX');

      const form = document.getElementById('funeralForm');
      const consentInline = document.getElementById('consent_inline');
      const linkViewDpa = document.getElementById('viewDpaLink');

      const ACCEPT_KEY = 'htcccDpaAcceptedFuneral';

      function lockBody(lock) { document.body.style.overflow = lock ? 'hidden' : ''; }
      function openDPA() { backdrop.style.display = 'flex'; lockBody(true); setTimeout(() => { btnAgree && btnAgree.focus(); }, 50); }
      function closeDPA() { backdrop.style.display = 'none'; lockBody(false); }

      function accepted() {
        try { return sessionStorage.getItem(ACCEPT_KEY) === '1'; } catch(e){ return false; }
      }
      function markAccepted() {
        try { sessionStorage.setItem(ACCEPT_KEY, '1'); } catch(e){}
        if (consentInline) consentInline.checked = true;
      }

      document.addEventListener('DOMContentLoaded', function() {
        const url = new URL(window.location.href);
        const inSuccess = url.searchParams.get('success') === '1';

        if (!accepted() && !inSuccess) {
          openDPA();
        } else if (accepted()) {
          if (consentInline) consentInline.checked = true;
        }
      });

      btnAgree && btnAgree.addEventListener('click', function() {
        markAccepted();
        closeDPA();
      });

      function cancelFlow() { window.location.href = 'appoint-page.php'; }
      btnCancel && btnCancel.addEventListener('click', cancelFlow);
      btnCloseX && btnCloseX.addEventListener('click', cancelFlow);

      const formEl = document.getElementById('funeralForm');
      formEl && formEl.addEventListener('submit', function(e) {
        if (!consentInline || !consentInline.checked) {
          e.preventDefault();
          openDPA();
          alert('Please agree to the Data Privacy Act consent to proceed.');
          return;
        }
        if (!formEl.checkValidity()) {
          e.preventDefault();
          e.stopPropagation();
        }
        formEl.classList.add('was-validated');
      }, false);

      linkViewDpa && linkViewDpa.addEventListener('click', function(e) {
        e.preventDefault();
        openDPA();
      });
    })();
  </script>

  <script>
    // Review & Confirm — build full name from parts for display only
    (function(){
      const form = document.getElementById('funeralForm');
      if (!form) return;

      const fields = {
        dc_last: document.getElementById('dc_last'),
        dc_first: document.getElementById('dc_first'),
        dc_middle: document.getElementById('dc_middle'),
        dc_ext: document.getElementById('dc_ext'),
        deceased_birthdate: document.getElementById('deceased_birthdate'),
        home_address: document.getElementById('home_address'),
        death_certificate: document.getElementById('death_certificate'),
        remarks: document.getElementById('remarks'),
        appointment_date: form.querySelector('input[name="appointment_date"]'),
        appointment_time: form.querySelector('input[name="appointment_time"]'),
        appointment_service: form.querySelector('input[name="appointment_service"]'),
        consent_inline: document.getElementById('consent_inline')
      };

      function composeFull(){
        const cap = s => (s||'').toString().replace(/\s+/g,' ').trim().replace(/\b([A-Za-z])([A-Za-z]*)/g,(_,a,b)=>a.toUpperCase()+b.toLowerCase());
        const ln = cap(fields.dc_last?.value);
        const fn = cap(fields.dc_first?.value);
        const mn = cap(fields.dc_middle?.value);
        const ex = (fields.dc_ext?.value || '').trim();
        const afterComma = [fn, mn].filter(Boolean).join(' ');
        return (ln && afterComma ? `${ln}, ${afterComma}` : (ln || afterComma)) + (ex ? ` ${ex}` : '');
      }

      function setText(id, val){
        const el = document.getElementById(id);
        if (!el) return;
        const s = (val ?? '').toString().trim();
        el.textContent = s !== '' ? s : '—';
      }

      function populateReview(){
        setText('rev_date', fields.appointment_date?.value || '');
        setText('rev_time', fields.appointment_time?.value || '');
        setText('rev_service', fields.appointment_service?.value || '');
        setText('rev_deceased_name', composeFull());
        setText('rev_deceased_birthdate', fields.deceased_birthdate?.value || '');
        setText('rev_home_address', fields.home_address?.value || '');
        setText('rev_remarks', fields.remarks?.value || '');

        let dcName = 'No file selected';
        if (fields.death_certificate && fields.death_certificate.files && fields.death_certificate.files[0]) {
          dcName = fields.death_certificate.files[0].name || dcName;
        }
        setText('rev_death_certificate', dcName);
      }

      const submitBtn = form.querySelector('.actions button[type="submit"]');
      const confirmBtn = document.getElementById('confirmSubmitBtn');
      const reviewModalEl = document.getElementById('reviewModal');
      let bsModal = null;

      function ensureModal(){
        if (!bsModal && reviewModalEl && window.bootstrap && bootstrap.Modal) {
          bsModal = new bootstrap.Modal(reviewModalEl, { backdrop: 'static', keyboard: false });
        }
        return bsModal;
      }

      if (submitBtn) {
        submitBtn.addEventListener('click', function(e){
          if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
            form.classList.add('was-validated');
            return;
          }
          if (!fields.consent_inline || !fields.consent_inline.checked) {
            return;
          }
          e.preventDefault();
          e.stopPropagation();
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
          marker.type = 'hidden';
          marker.name = 'review_confirmed';
          marker.value = '1';
          form.appendChild(marker);
          form.submit();
        });
      }
    })();
  </script>

  <script>
    // Name breakdown: compose preview + sync hidden POST fields
    (function(){
      const elLast = document.getElementById('dc_last');
      const elFirst = document.getElementById('dc_first');
      const elMiddle = document.getElementById('dc_middle');
      const elExt = document.getElementById('dc_ext');
      const elPrev = document.getElementById('namePreview');

      const hLast = document.getElementById('deceased_lastname');
      const hFirst = document.getElementById('deceased_firstname');
      const hMiddle = document.getElementById('deceased_middlename');
      const hExt = document.getElementById('deceased_ext');

      function val(x){ return (x && typeof x.value === 'string') ? x.value.trim() : ''; }
      function sanitizeWord(s){
        return s.replace(/\s+/g,' ').trim().replace(/\b([A-Za-z])([A-Za-z]*)/g, (_,a,b)=>a.toUpperCase()+b.toLowerCase());
      }

      function compose(){
        const ln = sanitizeWord(val(elLast));
        const fn = sanitizeWord(val(elFirst));
        const mn = sanitizeWord(val(elMiddle));
        const ex = val(elExt);

        const afterComma = [fn, mn].filter(Boolean).join(' ');
        const suffix = ex ? (' ' + ex) : '';
        let assembled = '';
        if (ln && afterComma) assembled = `${ln}, ${afterComma}${suffix}`;
        else if (ln)           assembled = `${ln}${suffix}`;
        else if (afterComma)   assembled = `${afterComma}${suffix}`;

        elPrev && (elPrev.textContent = assembled || '—');

        if (hLast)   hLast.value   = ln || '';
        if (hFirst)  hFirst.value  = fn || '';
        if (hMiddle) hMiddle.value = mn || '';
        if (hExt)    hExt.value    = ex || '';
      }

      ['input','change'].forEach(ev => {
        elLast  && elLast.addEventListener(ev, compose);
        elFirst && elFirst.addEventListener(ev, compose);
        elMiddle&& elMiddle.addEventListener(ev, compose);
        elExt   && elExt.addEventListener(ev, compose);
      });

      compose(); // initialize
    })();
  </script>

  <script>
    // Auto-open quota modal if server flagged the limit
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
  </script>
</body>
</html>
