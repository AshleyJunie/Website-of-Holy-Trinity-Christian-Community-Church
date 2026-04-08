<?php
// ============================
// form-house.php
// with DB insert into service_house
// using external db-connection.php
// ============================

if (session_status() === PHP_SESSION_NONE) session_start();

// Use your existing db-connection.php (with $db_connection)
require_once __DIR__ . '/db-connection.php';

// Basic safety check
if (!isset($db_connection) || !$db_connection) {
  die("Failed to connect to database.");
}

/**
 * notifyAdmins() (standard HTCCC pattern)
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
   Get logged-in individual's ID
----------------------------*/
$individual_id = null;
if (isset($_SESSION['individual_id'])) {
  $individual_id = (int) $_SESSION['individual_id'];
} elseif (isset($_SESSION['user_id'])) {
  $individual_id = (int) $_SESSION['user_id'];
}

if (!$individual_id) {
  $notLoggedError = "You must be logged in to submit a house blessing appointment.";
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
   Submission handler
----------------------------*/
$submitError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    // Check login first
    if (!$individual_id) {
      throw new RuntimeException("You must be logged in to submit this form.");
    }

    // Ensure notifier identity for notifications (standard pattern)
    if (empty($_SESSION['user_type'])) $_SESSION['user_type'] = 'individual';
    if (empty($_SESSION['user_id']))   $_SESSION['user_id']   = (int)$individual_id;

    // Simple required fields
    $appointment_date    = ($_POST['appointment_date'] ?? '') !== '' ? trim($_POST['appointment_date']) : _pick('date');
    $appointment_time    = ($_POST['appointment_time'] ?? '') !== '' ? trim($_POST['appointment_time']) : _pick('time');
    $appointment_service = ($_POST['appointment_service'] ?? '') !== '' ? trim($_POST['appointment_service']) : (_pick('service') ?: 'HOUSE BLESSING');

    if ($appointment_date === '' || $appointment_time === '') {
      throw new RuntimeException("Missing appointment date/time. Please reselect your slot from the appointment page.");
    }

    // Persist back (keeps state after redirect)
    _persist_if_present('date',    $appointment_date);
    _persist_if_present('time',    $appointment_time);
    _persist_if_present('service', $appointment_service);

    
    // === FILE UPLOAD (image or PDF, shared helper pattern) ===

    // Allowed file types for this form
    $uploadRules = [
      'upload_image' => ['jpg','jpeg','png','pdf'],
    ];

    $uploadMimeMap = [
      'jpg'  => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'png'  => 'image/png',
      'pdf'  => 'application/pdf',
    ];

    // Reusable upload helper (image/PDF, web-viewable path)
    $save_upload = function(string $field, string $baseDir, array $allowedExts) use ($uploadMimeMap) : string {
      if (!isset($_FILES[$field]) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        throw new RuntimeException("Missing required file: {$field}");
      }

      $file = $_FILES[$field];

      if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException("Invalid upload parameters for {$field}.");
      }

      if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
          throw new RuntimeException("File {$field} is required.");
        }
        throw new RuntimeException("Upload error for {$field} (code {$file['error']}).");
      }

      // Max 8MB
      $maxSize = 8 * 1024 * 1024;
      if (!empty($file['size']) && $file['size'] > $maxSize) {
        throw new RuntimeException("File {$field} is too large. Maximum size is 8MB.");
      }

      $tmpName = $file['tmp_name'];
      $origNameRaw = isset($file['name']) ? trim((string)$file['name']) : '';

      // Extension check
      $ext = strtolower(pathinfo($origNameRaw, PATHINFO_EXTENSION));
      if (!in_array($ext, $allowedExts, true)) {
        throw new RuntimeException(
          "Invalid file type for {$field}. Allowed: " . implode(', ', $allowedExts)
        );
      }

      // MIME type check using finfo
      $mime = null;
      if (is_uploaded_file($tmpName) && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
          $mime = finfo_file($finfo, $tmpName) ?: null;
          finfo_close($finfo);
        }
      }

      if ($ext === 'pdf') {
        $pdfMimes = ['application/pdf', 'application/x-pdf'];
        if ($mime !== null && !in_array($mime, $pdfMimes, true)) {
          throw new RuntimeException("File content type mismatch for {$field} (expected PDF).");
        }
      } else {
        // Image: ensure it's image/* and passes getimagesize
        if ($mime !== null && strpos($mime, 'image/') !== 0) {
          throw new RuntimeException("{$field} must be an image or PDF file.");
        }
        if (@getimagesize($tmpName) === false) {
          throw new RuntimeException("Invalid image file for {$field}.");
        }
      }

      // Ensure upload directory exists
      if (!is_dir($baseDir)) {
        if (!mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
          throw new RuntimeException("Failed to create upload directory: {$baseDir}");
        }
        // Optional simple hardening
        @file_put_contents(
          rtrim($baseDir, '/\\') . '/.htaccess',
          "Options -Indexes\n<FilesMatch \"\\.(php|phar|phtml)$\">Deny from all</FilesMatch>\n"
        );
      }

      // Use original filename but avoid collisions
      if ($origNameRaw === '') {
        $origNameRaw = 'uploaded_file';
      }
      $onlyName = basename($origNameRaw);
      $safeName = $onlyName;
      if ($safeName === '' || $safeName === '.' || $safeName === '..') {
        $safeName = 'uploaded_file';
      }

      $finalName = $safeName;
      $dotPos   = strrpos($safeName, '.');
      if ($dotPos !== false) {
        $namePart = substr($safeName, 0, $dotPos);
        $extPart  = substr($safeName, $dotPos); // includes dot
      } else {
        $namePart = $safeName;
        $extPart  = '';
      }

      $counter = 1;
      while (file_exists(rtrim($baseDir, '/\\') . '/' . $finalName)) {
        $finalName = $namePart . '_' . $counter . $extPart;
        $counter++;
      }

      $targetPath = rtrim($baseDir, '/\\') . '/' . $finalName;
      if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException("Failed to save uploaded file for {$field}.");
      }

      // Convert absolute path to web-relative path for viewing in admin side
      $docRoot    = rtrim(str_replace('\\','/', $_SERVER['DOCUMENT_ROOT']), '/');
      $targetNorm = str_replace('\\','/', $targetPath);
      if (strpos($targetNorm, $docRoot) === 0) {
        return ltrim(substr($targetNorm, strlen($docRoot)), '/');
      }

      // Fallback: absolute path
      return $targetNorm;
    };

    // Handle required Valid ID upload (image or PDF) — copy of funeral pattern
    $validIdPath = null;
    if (!isset($_FILES['upload_image']) || !is_array($_FILES['upload_image'])) {
      throw new RuntimeException('Valid ID upload is required.');
    }
    $idFile = $_FILES['upload_image'];

    if ($idFile['error'] === UPLOAD_ERR_NO_FILE) {
      throw new RuntimeException('Valid ID upload is required.');
    }
    if ($idFile['error'] !== UPLOAD_ERR_OK) {
      throw new RuntimeException('Error uploading Valid ID. Please try again.');
    }

    // Basic size check (max 8MB)
    $maxSize = 8 * 1024 * 1024;
    if (!empty($idFile['size']) && $idFile['size'] > $maxSize) {
      throw new RuntimeException('Valid ID file is too large. Maximum size is 8MB.');
    }

    // MIME type check
    $tmpName = $idFile['tmp_name'];
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
      throw new RuntimeException('Valid ID must be an image or PDF file.');
    }

    // Use the original uploaded filename, stored under uploads/valid_id/
    $origNameRaw = isset($idFile['name']) ? trim((string)$idFile['name']) : '';
    if ($origNameRaw === '') {
      $origNameRaw = 'uploaded_file';
    }

    $onlyName = basename($origNameRaw);
    $safeName = $onlyName;
    if ($safeName === '' || $safeName === '.' || $safeName === '..') {
      $safeName = 'uploaded_file';
    }

    $uploadDir = __DIR__ . '/uploads/valid_id';
    if (!is_dir($uploadDir)) {
      if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Failed to create upload directory for Valid ID.');
      }
    }

    // Avoid overwriting existing files with same name
    $finalName = $safeName;
    $dotPos = strrpos($safeName, '.');
    if ($dotPos !== false) {
      $namePart = substr($safeName, 0, $dotPos);
      $extPart  = substr($safeName, $dotPos); // includes dot
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
      throw new RuntimeException('Failed to save uploaded Valid ID.');
    }

    // Store a web-relative path in the database (same logic as funeral form)
    $docRoot    = rtrim(str_replace('\\','/', $_SERVER['DOCUMENT_ROOT']), '/');
    $targetNorm = str_replace('\\','/', $targetPath);
    if (strpos($targetNorm, $docRoot) === 0) {
      $valid_id = ltrim(substr($targetNorm, strlen($docRoot)), '/');
    } else {
      $valid_id = $targetNorm;
    }
// ------------ DB INSERT INTO service_house ------------

    $owner_lastname   = trim($_POST['owner_lastname']   ?? '');
    $owner_firstname  = trim($_POST['owner_firstname']  ?? '');
    $owner_middlename = trim($_POST['owner_middlename'] ?? '');
    $owner_ext        = trim($_POST['owner_ext']        ?? '');

    $contact_number   = trim($_POST['contact_info']     ?? '');
    $home_address     = trim($_POST['home_address']     ?? '');

    // New mapping: store into service_date, service_time, service_status
    // instead of appointment_date, appointment_time, status
    $appointment_type = 'onsite';
    $service_status   = 'Pending'; // or whatever default you use (e.g. 'For Approval')

    $sql = "
      INSERT INTO service_house
        (individual_id,
         owner_lastname,
         owner_firstname,
         owner_middlename,
         owner_ext,
         contact_number,
         home_address,
         service_date,
         service_time,
         service_status,
         appointment_type,
         valid_id)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ";

    $stmt = $db_connection->prepare($sql);
    if (!$stmt) {
      throw new RuntimeException('Database prepare failed: ' . $db_connection->error);
    }

    $stmt->bind_param(
      'isssssssssss',
      $individual_id,
      $owner_lastname,
      $owner_firstname,
      $owner_middlename,
      $owner_ext,
      $contact_number,
      $home_address,
      $appointment_date,   // stored into service_date
      $appointment_time,   // stored into service_time
      $service_status,     // stored into service_status
      $appointment_type,
      $valid_id
    );

    if (!$stmt->execute()) {
      throw new RuntimeException('Database insert failed: ' . $stmt->error);
    }

    $houseId = (int)$db_connection->insert_id;
    $stmt->close();

    // Build a summary for the notification
    $ownerNameParts = [];
    if ($owner_lastname !== '')   $ownerNameParts[] = $owner_lastname;
    if ($owner_firstname !== '')  $ownerNameParts[] = $owner_firstname;
    if ($owner_middlename !== '') $ownerNameParts[] = $owner_middlename;
    if ($owner_ext !== '')        $ownerNameParts[] = $owner_ext;
    $ownerFullName = trim(implode(' ', $ownerNameParts));

    $summaryParts = [];
    if ($ownerFullName !== '') {
      $summaryParts[] = "Homeowner: {$ownerFullName}";
    }
    if ($home_address !== '') {
      $summaryParts[] = "Address: {$home_address}";
    }
    if ($appointment_date && $appointment_time) {
      $summaryParts[] = "Appointment: {$appointment_date} {$appointment_time}";
    }
    $formSummary = implode(' | ', $summaryParts);

    if ($houseId > 0 && $formSummary !== '') {
      try {
        notifyAdmins($db_connection, 'house blessing', $houseId, $formSummary);
      } catch (Throwable $__e) {
        // ignore notif errors
      }
    }

    // Optional JSONL manifest
    $manifestPath = $uploadDir . '/submissions.jsonl';
    $payload = [
      'ts'   => date('c'),
      'ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
      'individual_id' => $individual_id,
      'owner' => [
        'last'   => $owner_lastname,
        'first'  => $owner_firstname,
        'middle' => $owner_middlename,
        'ext'    => $owner_ext,
      ],
      'contact_number'        => $contact_number,
      'home_address'          => $home_address,
      'service_date'          => $appointment_date,
      'service_time'          => $appointment_time,
      'appointment_service'   => $appointment_service,
      'valid_id'              => $valid_id,
      'original_name'         => $safeName
    ];
    @file_put_contents($manifestPath, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

    header('Location: ' . basename(__FILE__)
      . '?success=1'
      . '&file='         . rawurlencode($valid_id)
      . '&service_date=' . rawurlencode($appointment_date)
      . '&service_time=' . rawurlencode($appointment_time)
      . '&service='      . rawurlencode($appointment_service)
    );
    exit;

  } catch (Throwable $e) {
    http_response_code(400);
    $submitError = $e->getMessage();
  }
}

/* ---------------------------
   Selections for rendering
----------------------------*/
$selDate    = _pick('date');
$selTime    = _pick('time');
$selService = _pick('service');
if ($selService === '') $selService = 'HOUSE BLESSING';

$val_owner_lastname   = htmlspecialchars($_POST['owner_lastname']   ?? '', ENT_QUOTES, 'UTF-8');
$val_owner_firstname  = htmlspecialchars($_POST['owner_firstname']  ?? '', ENT_QUOTES, 'UTF-8');
$val_owner_middlename = htmlspecialchars($_POST['owner_middlename'] ?? '', ENT_QUOTES, 'UTF-8');
$val_owner_ext        = htmlspecialchars($_POST['owner_ext']        ?? '', ENT_QUOTES, 'UTF-8');
$val_contact_info     = htmlspecialchars($_POST['contact_info']     ?? '', ENT_QUOTES, 'UTF-8');
$val_home_address     = htmlspecialchars($_POST['home_address']     ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta content="width=device-width, initial-scale=1" name="viewport" />
  <title>HTCCC - House Blessing Appointment Form</title>
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
    :root {
      --ink:#1a1f4a;
      --panel:#3a3f71;
      --panelAlpha: rgba(58,63,113,0.9);
      --border:#4b5563;
      --bg:#0A0E3F;
      --accent:#7FC1FF;
      --field:#F3F2F5;
      --text:#fff;
    }
    * { box-sizing: border-box; }
    body {
      font-family: Arial, sans-serif;
      background-image: url("image/all-background.png");
      background-position: center;
      background-size: cover;
      background-attachment: fixed;
      background-repeat: no-repeat;
      margin: 0;
      color: var(--text);
    }

    nav {
      background-color: var(--bg);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .75rem;
      padding: 10px clamp(12px, 3vw, 20px);
      color: #fff;
    }
    nav img { width: clamp(26px, 5vw, 30px); height: clamp(26px, 5vw, 30px); border-radius: 50%; }
    nav h1 { margin: 0; font-size: clamp(16px, 3.4vw, 20px); font-weight: 800; line-height: 1.1; }
    nav a { color:#fff; text-decoration:none; display:flex; align-items:center; gap:8px; }

    main { max-width: 1120px; margin: clamp(12px, 2vw, 24px) auto 0; padding: 0 clamp(12px, 2.5vw, 16px); }

    .top-sections { margin-bottom: clamp(12px, 2.2vw, 24px); }
    section.border-box {
      border: 2px solid var(--border);
      border-radius: 0.75rem;
      padding: clamp(12px, 2.2vw, 16px);
      background: var(--panelAlpha);
      height: 100%;
    }
    section.border-box h2 {
      font-weight: 700; font-size: clamp(1rem, 2.5vw, 1.15rem); margin-bottom: 0.75rem; text-align: center; color:#fff;
    }
    section.border-box ul {
      list-style-type: disc; padding-left: 1.25rem; font-weight: 600; font-size: clamp(.9rem, 2.4vw, .95rem); line-height: 1.5; margin: 0; color:#fff;
    }

    .summary {
      color:#fff; font-weight:700; background: var(--ink);
      border-radius:12px; padding: clamp(10px, 2.5vw, 12px) clamp(12px, 2.5vw, 16px);
      text-align:center; margin-bottom: clamp(12px, 2.2vw, 16px);
      word-wrap: break-word; overflow-wrap: anywhere;
      font-size: clamp(.95rem, 2.5vw, 1rem);
    }

    .form-grid {
      background-color: var(--panel);
      border-radius: 0.75rem;
      padding: clamp(12px, 2.5vw, 20px);
      border: 2px solid var(--border);
    }
    .form-card {
      background: rgba(0,0,0,0.08);
      border: 2px solid var(--border);
      border-radius: 12px;
      padding: clamp(12px, 2.5vw, 16px);
      height: 100%;
    }
    .form-card h3 { margin:0 0 10px; font-size: clamp(1rem, 2.6vw, 1.05rem); font-weight:800; }

    label { font-weight: 600; font-size: clamp(.9rem, 2.4vw, .92rem); display:block; color:#f9fafb; margin-bottom: 6px; }
    input[type="text"], input[type="file"] {
      width: 100%; border-radius: 12px; padding: clamp(12px, 2.5vw, 14px);
      font-weight: 600; font-size: clamp(.98rem, 2.5vw, 1rem); color: #1f2937; border: none; outline: none; background: var(--field);
      min-height: 44px;
    }

    .actions { display:flex; justify-content:center; padding-top: clamp(8px, 2vw, 12px); }
    .btn-primary-ht { background-color: var(--accent); color: #000; border-radius: 12px; border: none; font-weight: 800; min-height: 44px; padding: 12px 18px; }
    .btn-primary-ht:hover { filter: brightness(0.95); }

    /* DPA modal */
    .dpa-backdrop {
      position: fixed; inset: 0; background: rgba(0,0,0,0.65);
      display: none; align-items: center; justify-content: center; z-index: 9999; padding: 16px;
    }
    .dpa-modal {
      max-width: 720px; width: 100%; background: #111631; color: #fff;
      border: 2px solid var(--border); border-radius: 14px; box-shadow: 0 20px 60px rgba(0,0,0,0.45); overflow: hidden;
    }
    .dpa-header { background: var(--ink); padding: 14px 18px; font-weight: 800; font-size: 1.05rem; display:flex; align-items:center; justify-content:space-between; }
    .dpa-body { padding: 16px 18px; line-height: 1.55; font-size: 0.96rem; }
    .dpa-footer { padding: 14px 18px; display: flex; gap: 10px; justify-content: flex-end; background: rgba(255,255,255,0.04); }
    .dpa-btn { border: none; border-radius: 10px; padding: 10px 14px; cursor: pointer; font-weight: 800; min-height: 44px; }
    .dpa-btn.primary { background: var(--accent); color: #000; }
    .dpa-btn.secondary { background: #2a2f57; color: #fff; }
    .dpa-close-x { background: transparent; border: none; color: #fff; font-size: 20px; line-height: 1; cursor: pointer; }

    .consent-row { margin-top: 10px; margin-bottom: 4px; color: #e5e7eb; font-size: clamp(.9rem, 2.4vw, .95rem); }
    .consent-row .form-check-input { margin-top: .25rem; min-width: 1.15rem; min-height: 1.15rem; }

    @media (max-width: 575.98px) {
      nav { padding: 10px 14px; }
      main { padding: 0 12px; }
      .form-card { padding: 14px; }
      .summary { padding: 10px 12px; }
      body { background-attachment: scroll; }
    }
  
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
      <h1 class="m-0 text-center text-uppercase">HOUSE BLESSING APPOINTMENT FORM</h1>
    </div>
    <div style="width:30px;"></div>
  </nav>

  <main class="container py-3 py-md-4">
    <?php if (!empty($notLoggedError)): ?>
      <div class="alert alert-warning mt-3" role="alert">
        <?= htmlspecialchars($notLoggedError, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>

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
            <li>Valid ID of Homeowner</li>
          </ul>
        </section>
      </div>
      <div class="col-12 col-lg-6">
        <section class="border-box h-100">
          <h2>Church Rules</h2>
          <ul class="mb-0">
            <li>Be on time for your scheduled meeting.</li>
            <li>Dress modestly and respectfully when entering the church.</li>
            <li>If you requested use of electricity or sound system, additional fees may apply.</li>
            <li>Gas fee for the Pastor (if needed).</li>
            <li>Offerings are optional but encouraged.</li>
          </ul>
        </section>
      </div>
    </div>

    <div class="summary px-3 py-2">
      <?php
        $safeDate = htmlspecialchars($selDate ?: '—', ENT_QUOTES, 'UTF-8');
        $safeTime = htmlspecialchars($selTime ?: '—', ENT_QUOTES, 'UTF-8');
        $safeSvc  = htmlspecialchars($selService ?: 'HOUSE BLESSING', ENT_QUOTES, 'UTF-8');
        echo "Selected Date: <b>{$safeDate}</b> &nbsp; | &nbsp; Time: <b>{$safeTime}</b> &nbsp; | &nbsp; Service: <b>{$safeSvc}</b>";
      ?>
    </div>

    <form
      id="houseForm"
      action="<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>"
      method="post"
      class="needs-validation"
      novalidate
      enctype="multipart/form-data"
    >
      <!-- Keep MAX_FILE_SIZE aligned with 8MB -->
      <input type="hidden" name="MAX_FILE_SIZE" value="<?= 8 * 1024 * 1024 ?>">

      <div class="form-grid">
        <div class="row g-3 g-md-4">
          <div class="col-12">
            <div class="form-card">
              <h3>Homeowner & Address</h3>

              <label class="mt-0">Owner’s Name <span class="text-danger">*</span></label>
              <div class="row g-2">
                <div class="col-12 col-md-6">
                  <input type="text" id="owner_lastname" name="owner_lastname" class="form-control" placeholder="Last name" required value="<?= $val_owner_lastname ?>" />
                </div>
                <div class="col-12 col-md-6">
                  <input type="text" id="owner_firstname" name="owner_firstname" class="form-control" placeholder="First name" required value="<?= $val_owner_firstname ?>" />
                </div>
                <div class="col-12 col-md-6 mt-2">
                  <input type="text" id="owner_middlename" name="owner_middlename" class="form-control" placeholder="Middle name (optional)" value="<?= $val_owner_middlename ?>" />
                </div>
                <div class="col-12 col-md-6 mt-2">
                  <input type="text" id="owner_ext" name="owner_ext" class="form-control" placeholder="Extension (Jr., Sr., III, etc.)" value="<?= $val_owner_ext ?>" />
                </div>
              </div>

              <label for="contact_info" class="mt-2">Contact Information <span class="text-danger">*</span></label>
              <input type="text" id="contact_info" name="contact_info" class="form-control" placeholder="Phone or Email" required value="<?= $val_contact_info ?>" />

              <label for="home_address" class="mt-2">Home Address <span class="text-danger">*</span></label>
              <input type="text" id="home_address" name="home_address" class="form-control" required value="<?= $val_home_address ?>" />

              <label for="upload_image" class="mt-3">Upload Valid ID (Image/PDF) <span class="text-danger">*</span></label>
              <input
                type="file"
                id="upload_image"
                name="upload_image"
                class="form-control"
                accept="image/*,.pdf"
                required
              />
              <div class="form-text text-light" style="opacity:.8">
                Upload a clear photo or scanned copy of the valid ID. Accepted formats: image or PDF (max 8MB). The stored path will be saved in <code>valid_id</code>.
              </div>
            </div>
          </div>
        </div>

        <!-- Hidden appointment context -->
        <input type="hidden" name="appointment_date" value="<?= htmlspecialchars($selDate, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="appointment_time" value="<?= htmlspecialchars($selTime, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="appointment_service" value="<?= htmlspecialchars($selService ?: 'HOUSE BLESSING', ENT_QUOTES, 'UTF-8') ?>">

        <!-- Inline DPA consent -->
        <div class="row mt-2">
          <div class="col-12">
            <div class="consent-row">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="consent_inline" required>
                <label class="form-check-label" for="consent_inline">
                  I agree to the collection and processing of my personal data for house blessing appointment purposes in accordance with the Data Privacy Act of 2012 (RA 10173) and
                  <a href="#" id="viewDpaLink" class="text-decoration-underline">HTCCC’s Privacy Notice</a>.
                </label>
              </div>
            </div>
          </div>
        </div>

        <div class="actions mt-1">
          <button type="submit" class="btn btn-primary-ht px-4 py-2" <?= !$individual_id ? 'disabled' : '' ?>>SUBMIT</button>
        </div>
      </div>
    </form>
  </main>

  <!-- Success SweetAlert -->
  <?php if (isset($_GET['success']) && $_GET['success'] === '1'): ?>
    <script>
      (function(){
        const details = {
          date: <?= json_encode($_GET['service_date'] ?? ($selDate ?: '—')) ?>,
          time: <?= json_encode($_GET['service_time'] ?? ($selTime ?: '—')) ?>,
          service: <?= json_encode($_GET['service'] ?? ($selService ?: 'HOUSE BLESSING')) ?>
        };

        showAppointmentSubmitted({
          serviceLabel: 'house blessing',
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
              url.searchParams.delete('file');
              url.searchParams.delete('service_date');
              url.searchParams.delete('service_time');
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

  <!-- DPA consent (show once before using the form) -->
  <div class="dpa-backdrop" id="dpaBackdrop" role="dialog" aria-modal="true" aria-labelledby="dpaTitle" aria-describedby="dpaDesc">
    <div class="dpa-modal">
      <div class="dpa-header">
        <div id="dpaTitle">Data Privacy Act Consent (RA 10173)</div>
        <button class="dpa-close-x" id="dpaCloseX" aria-label="Close">×</button>
      </div>
      <div class="dpa-body" id="dpaDesc">
        <p>Holy Trinity Christian Community Church (HTCCC) is committed to protecting your personal data in compliance with the <strong>Data Privacy Act of 2012 (RA 10173)</strong> and its Implementing Rules and Regulations.</p>
        <p><strong>What we collect:</strong> Information you provide in this form (e.g., names, contact details, home address, identification documents).</p>
        <p><strong>Purpose:</strong> To process and manage your house blessing appointment, verify eligibility, coordinate schedules, and comply with church and legal requirements.</p>
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
                  <div class="col-md-4">
                    <small class="text-light opacity-75">Date</small>
                    <div id="rev_date" class="fw-bold"></div>
                  </div>
                  <div class="col-md-4">
                    <small class="text-light opacity-75">Time</small>
                    <div id="rev_time" class="fw-bold"></div>
                  </div>
                  <div class="col-md-4">
                    <small class="text-light opacity-75">Service</small>
                    <div id="rev_service" class="fw-bold"></div>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-12">
              <div class="border rounded p-3">
                <h6 class="fw-bold mb-2">Valid ID</h6>
                <div class="row">
                  <div class="col-md-6">
                    <small class="text-light opacity-75">File Name</small>
                    <div id="rev_valid_id" class="fw-bold"></div>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <div class="border rounded p-3 h-100">
                <h6 class="fw-bold mb-2">Homeowner</h6>
                <div>
                  <small class="text-light opacity-75">Full Name</small>
                  <div id="rev_owner_name" class="fw-bold"></div>
                </div>
                <div class="mt-2">
                  <small class="text-light opacity-75">Contact Information</small>
                  <div id="rev_contact_info" class="fw-bold"></div>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <div class="border rounded p-3 h-100">
                <h6 class="fw-bold mb-2">Address</h6>
                <div>
                  <small class="text-light opacity-75">Home Address</small>
                  <div id="rev_home_address" class="fw-bold" style="white-space:pre-wrap;"></div>
                </div>
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

  <!-- DPA gating + inline link -->
  <script>
    (function() {
      const backdrop = document.getElementById('dpaBackdrop');
      const btnAgree = document.getElementById('dpaAgree');
      const btnCancel = document.getElementById('dpaCancel');
      const btnCloseX = document.getElementById('dpaCloseX');

      const form = document.getElementById('houseForm');
      const consentInline = document.getElementById('consent_inline');
      const linkViewDpa = document.getElementById('viewDpaLink');

      const ACCEPT_KEY = 'htcccDpaAcceptedHouse';

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

      const formEl = document.getElementById('houseForm');
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

  <!-- Review & Confirm -->
  <script>
    (function(){
      const form = document.getElementById('houseForm');
      if (!form) return;

      const fields = {
        owner_lastname:   document.getElementById('owner_lastname'),
        owner_firstname:  document.getElementById('owner_firstname'),
        owner_middlename: document.getElementById('owner_middlename'),
        owner_ext:        document.getElementById('owner_ext'),
        contact_info:     document.getElementById('contact_info'),
        home_address:     document.getElementById('home_address'),
        upload_image:     document.getElementById('upload_image'),
        appointment_date: form.querySelector('input[name="appointment_date"]'),
        appointment_time: form.querySelector('input[name="appointment_time"]'),
        appointment_service: form.querySelector('input[name="appointment_service"]'),
        consent_inline:   document.getElementById('consent_inline')
      };

      function composeOwnerName() {
        const cap = s => (s||'').toString().replace(/\s+/g,' ').trim().replace(/\b([A-Za-z])([A-Za-z]*)/g,(_,a,b)=>a.toUpperCase()+b.toLowerCase());
        const ln = cap(fields.owner_lastname?.value);
        const fn = cap(fields.owner_firstname?.value);
        const mn = cap(fields.owner_middlename?.value);
        const ex = (fields.owner_ext?.value || '').trim();
        const parts = [];
        if (ln) parts.push(ln + (fn || mn ? ',' : ''));
        if (fn) parts.push(fn);
        if (mn) parts.push(mn);
        let full = parts.join(' ');
        if (ex) full = full + ' ' + ex;
        return full.trim();
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

        setText('rev_owner_name', composeOwnerName());
        setText('rev_contact_info', fields.contact_info?.value || '');
        setText('rev_home_address', fields.home_address?.value || '');

        let idName = 'No file selected';
        if (fields.upload_image && fields.upload_image.files && fields.upload_image.files[0]) {
          idName = fields.upload_image.files[0].name || idName;
        }
        setText('rev_valid_id', idName);
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
</body>
</html>
