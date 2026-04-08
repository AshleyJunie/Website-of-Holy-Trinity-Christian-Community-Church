<?php
// ============================
// form-baptism.php (one-file)
// ============================

session_start();

/** Helpers **/
function compute_age_from_string(?string $dateStr): ?int {
  if (!$dateStr) return null;
  $ts = strtotime($dateStr);
  if ($ts === false) return null;
  $birth = (new DateTime())->setTimestamp($ts);
  $today = new DateTime('today');
  $age = $birth->diff($today)->y;
  return is_numeric($age) ? (int)$age : null;
}
function norm(?string $s): ?string {
  if ($s === null) return null;
  $s = trim($s);
  return $s === '' ? null : $s;
}

/**
 * notifyAdmins()
 * Uses the same logic as the wedding form:
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

/** Require login for viewing AND processing **/
$individualId = $_SESSION['individual_id'] ?? (isset($_GET['individual_id']) ? (int)$_GET['individual_id'] : null);
if (!$individualId) {
  $returnTo = $_SERVER['REQUEST_URI'] ?? basename(__FILE__);
  header('Location: all_log-in.php?return_to=' . rawurlencode($returnTo));
  exit;
}

/** Connect DB (one place so both POST + render can use it) **/
$pdo = null;
try {
  $pdo = new PDO(
    "mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4",
    "root",
    "",
    [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]
  );
} catch (Throwable $e) {
  http_response_code(500);
  die("Database connection error.");
}

/** Load the logged-in individual for autofill + minor check **/
$individual = null;
$isMinor = false;
try {
  $stmt = $pdo->prepare("
    SELECT individual_lastname, individual_firstname, individual_middlename, individual_extension, individual_birthday
    FROM individual_table
    WHERE individual_id = :id
    LIMIT 1
  ");
  $stmt->execute([':id' => $individualId]);
  $individual = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
  $age = compute_age_from_string($individual['individual_birthday'] ?? null);
  $isMinor = ($age !== null && $age < 18);
} catch (Throwable $e) {
  // If individual can't be loaded, treat as not minor and leave fields blank
  $individual = [];
  $isMinor = false;
}

/** Security-hardened upload validator (reused) **/
if (!function_exists('_strict_revalidate_upload')) {
  function _strict_revalidate_upload(string $savedPath, array $allowedExts, array $mimeMap, string $fieldLabel = 'file'): void {
    $pathNorm = str_replace('\\', '/', $savedPath);
    $abs = $pathNorm;
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    if ($docRoot !== '' && strpos($pathNorm, $docRoot) !== 0) {
      $absCandidate = $docRoot . '/' . ltrim($pathNorm, '/');
      if (is_file($absCandidate)) $abs = $absCandidate;
    }
    if (!is_file($abs)) $abs = $pathNorm;

    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION) ?: '');
    if (!in_array($ext, $allowedExts, true)) { @unlink($abs); throw new RuntimeException("Invalid file type for {$fieldLabel}."); }

    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? @finfo_file($finfo, $abs) : '';
    if ($finfo) @finfo_close($finfo);
    $expected = $mimeMap[$ext] ?? '';
    if ($expected && $mime && strtolower($mime) !== strtolower($expected)) { @unlink($abs); throw new RuntimeException("File content type mismatch for {$fieldLabel}."); }

    if (in_array($ext, ['jpg','jpeg','png'], true)) {
      if (@getimagesize($abs) === false) { @unlink($abs); throw new RuntimeException("Invalid image file for {$fieldLabel}."); }
    }
  }
}
$__MIME_MAP = [ 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'png'=>'image/png', 'pdf'=>'application/pdf' ];

/** Count-active helper (unchanged) **/
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

/** Submission **/
$submitError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    // Require login again (defense in depth)
    $sessionId = $_SESSION['individual_id'] ?? null;
    if (!$sessionId || (int)$sessionId !== (int)$individualId) {
      $returnTo = basename(__FILE__);
      header('Location: all_log-in.php?return_to=' . rawurlencode($returnTo));
      exit;
    }

    // Ensure notifier identity is available for this individual workflow
    if (empty($_SESSION['user_type'])) $_SESSION['user_type'] = 'individual';
    if (empty($_SESSION['user_id']))   $_SESSION['user_id']   = (int)$sessionId;

    // Required field helper
    $req = function(string $key): string {
      if (!isset($_POST[$key]) || trim($_POST[$key]) === '') {
        throw new RuntimeException("Missing required field: {$key}");
      }
      return trim($_POST[$key]);
    };

    // Rate/limit
    $CLOSED = ['Approved','Declined','Cancelled','Archived','Done'];
    $activeCount = htccc_count_active_across_services($pdo, (int)$sessionId, $CLOSED);
    if ($activeCount >= 5) {
      throw new RuntimeException(
        "APPT_LIMIT_EXCEEDED: You have exceeded the appointment request. Please wait until your appointments are processed (Approved, Declined, Cancelled, Archived, or Done) so you can appoint again."
      );
    }

    // Baptizand (breakdown)
    $baptized_lastname   = $req('baptized_lastname');
    $baptized_firstname  = $req('baptized_firstname');
    $baptized_middlename = $_POST['baptized_middlename'] ?? null;
    $baptized_ext        = $_POST['baptized_ext'] ?? null;
    $baptized_birthdate  = $req('baptized_birthdate'); // YYYY-MM-DD

    // Minor check is based on individual_table (server authoritative)
    $ageNow = compute_age_from_string($individual['individual_birthday'] ?? null);
    $isMinorNow = ($ageNow !== null && $ageNow < 18);

    // Guardian (conditional required)
    $guardian_lastname   = null;
    $guardian_firstname  = null;
    $guardian_middlename = null;
    $guardian_ext        = null;
    $guardian_contactnum = null;

    if ($isMinorNow) {
      $guardian_lastname   = $req('guardian_lastname');
      $guardian_firstname  = $req('guardian_firstname');
      $guardian_middlename = $_POST['guardian_middlename'] ?? null;
      $guardian_ext        = $_POST['guardian_ext'] ?? null;
      $guardian_contactnum = $req('guardian_contactnum');
    }

    // ============================================
    // Handle required birth certificate upload
    // (image or PDF) – funeral-style logic
    // ============================================
    $baptismal_cert_path = null;

    if (!isset($_FILES['baptismal_cert']) || !is_array($_FILES['baptismal_cert'])) {
      throw new RuntimeException('Birth certificate upload is required.');
    }
    $bcFile = $_FILES['baptismal_cert'];

    if ($bcFile['error'] === UPLOAD_ERR_NO_FILE) {
      throw new RuntimeException('Birth certificate upload is required.');
    }
    if ($bcFile['error'] !== UPLOAD_ERR_OK) {
      throw new RuntimeException('Error uploading birth certificate. Please try again.');
    }

    // Basic size check (max 8MB)
    $maxSize = 8 * 1024 * 1024;
    if (!empty($bcFile['size']) && $bcFile['size'] > $maxSize) {
      throw new RuntimeException('Birth certificate file is too large. Maximum size is 8MB.');
    }

    // MIME type check
    $tmpName = $bcFile['tmp_name'];
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

    // Use original filename (with collision suffix) under uploads/baptism/
    $origNameRaw = isset($bcFile['name']) ? trim((string)$bcFile['name']) : '';
    if ($origNameRaw === '') {
      $origNameRaw = 'uploaded_file';
    }
    $onlyName = basename($origNameRaw);

    $safeName = $onlyName;
    if ($safeName === '' || $safeName === '.' || $safeName === '..') {
      $safeName = 'uploaded_file';
    }

    $uploadDir = __DIR__ . '/uploads/baptism';
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
      throw new RuntimeException('Failed to save uploaded birth certificate.');
    }

    // Store a WEB-RELATIVE path in the database
    $docRoot    = rtrim(str_replace('\\','/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $targetNorm = str_replace('\\','/', $targetPath);
    if ($docRoot && strpos($targetNorm, $docRoot) === 0) {
      $baptismal_cert_path = ltrim(substr($targetNorm, strlen($docRoot)), '/');
    } else {
      $baptismal_cert_path = $targetNorm;
    }

    // Optional fields preserved
    $special_request = norm($_POST['special_request'] ?? null);
    $contact_number  = ''; // intentionally blank to satisfy legacy NOT NULL if present
    $email_address   = '';

    // Build a human-friendly summary for the notification (fullname style: Last, First Middle Ext)
    $b_ln = trim($baptized_lastname ?? '');
    $b_fn = trim($baptized_firstname ?? '');
    $b_mn = trim($baptized_middlename ?? '');
    $b_ex = trim($baptized_ext ?? '');

    $baptizandName = $b_ln;
    if ($b_fn !== '') {
      $baptizandName .= ($baptizandName !== '' ? ', ' : '') . $b_fn;
    }
    if ($b_mn !== '') {
      $baptizandName .= ' ' . $b_mn;
    }
    if ($b_ex !== '') {
      $baptizandName .= ' ' . $b_ex;
    }
    $baptizandName = trim($baptizandName);

    $summaryParts = [];
    if ($baptizandName !== '') {
      $summaryParts[] = "Baptizand: {$baptizandName}";
    }
    if ($baptized_birthdate) {
      $summaryParts[] = "Birthdate: {$baptized_birthdate}";
    }
    if (!empty($special_request)) {
      $summaryParts[] = "Special request: {$special_request}";
    }
    $formSummary = implode(' | ', $summaryParts);

    // INSERT with new columns
    $sql = "INSERT INTO service_baptism (
              individual_id,
              baptized_lastname, baptized_firstname, baptized_middlename, baptized_ext,
              baptized_birthdate,
              guardian_lastname, guardian_firstname, guardian_middlename, guardian_ext, guardian_contactnum,
              contact_number, email_address, special_request,
              baptismal_cert_path, status
            ) VALUES (
              :individual_id,
              :b_last, :b_first, :b_mid, :b_ext,
              :b_birth,
              :g_last, :g_first, :g_mid, :g_ext, :g_contact,
              :contact_number, :email_address, :special_request,
              :cert_path, 'Pending'
            )";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':individual_id'   => $sessionId,
      ':b_last'          => $baptized_lastname,
      ':b_first'         => $baptized_firstname,
      ':b_mid'           => $baptized_middlename,
      ':b_ext'           => $baptized_ext,
      ':b_birth'         => $baptized_birthdate,
      ':g_last'          => $guardian_lastname,
      ':g_first'         => $guardian_firstname,
      ':g_mid'           => $guardian_middlename,
      ':g_ext'           => $guardian_ext,
      ':g_contact'       => $guardian_contactnum,
      ':contact_number'  => $contact_number,
      ':email_address'   => $email_address,
      ':special_request' => $special_request,
      ':cert_path'       => $baptismal_cert_path,
    ]);

    // Get the new record ID and notify admins
    $baptismId = (int)$pdo->lastInsertId();
    if ($baptismId > 0) {
      try {
        // formType set to 'baptism' for this form
        notifyAdmins($pdo, 'baptism', $baptismId, $formSummary);
      } catch (Throwable $___e) {
        // Swallow notification errors to avoid blocking user submission
      }
    }

    try {
      $_SESSION['baptism_attempts_today'] = htccc_count_active_across_services($pdo, (int)$sessionId, $CLOSED);
    } catch (Throwable $___e) {}

    header('Location: ' . basename(__FILE__) . '?success=1');
    exit;

  } catch (Throwable $e) {
    http_response_code(400);
    $submitError = $e->getMessage();
  }
}

/** attempts for success UI **/
$__attemptsToday = isset($_SESSION['baptism_attempts_today']) ? (int)$_SESSION['baptism_attempts_today'] : null;

/** quota modal flag **/
$__quotaExceeded = false;
if (!empty($submitError) && str_starts_with($submitError, 'APPT_LIMIT_EXCEEDED:')) {
  $__quotaExceeded = true;
  $submitError = trim(substr($submitError, strlen('APPT_LIMIT_EXCEEDED:')));
}

// Prefill values from individual_table
$pref_last = htmlspecialchars($individual['individual_lastname']   ?? '', ENT_QUOTES, 'UTF-8');
$pref_first= htmlspecialchars($individual['individual_firstname']  ?? '', ENT_QUOTES, 'UTF-8');
$pref_mid  = htmlspecialchars($individual['individual_middlename'] ?? '', ENT_QUOTES, 'UTF-8');
$pref_ext  = htmlspecialchars($individual['individual_extension']  ?? '', ENT_QUOTES, 'UTF-8');
$pref_birth= htmlspecialchars($individual['individual_birthday']   ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta content="width=device-width, initial-scale=1" name="viewport" />
  <title>HTCCC - Baptism Appointment Form</title>
  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    :root { --ink:#1a1f4a; --panel:#3a3f71; --panelAlpha: rgba(58,63,113,0.9); --border:#4b5563; --bg:#0A0E3F; --accent:#7FC1FF; --field:#F3F2F5; --text:#fff; }
    * { box-sizing: border-box; }
    body {
      font-family: Arial, sans-serif;
      background-image: url("image/all-background.png");
      background-position: center; background-size: cover; background-attachment: fixed; background-repeat: no-repeat;
      margin: 0; color: var(--text);
    }
    nav { background-color: var(--bg); display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:10px 20px; color:#fff; }
    nav img { width:30px; height:30px; border-radius:50%; }
    nav h1 { margin:0; font-size: clamp(16px, 2.5vw, 20px); font-weight:800; }
    nav a { color:#fff; text-decoration:none; display:flex; align-items:center; gap:8px; }

    main { max-width:1120px; margin:1.5rem auto 0; padding:0 1rem; }

    .top-sections { margin-bottom:1.5rem; }
    section.border-box { border:2px solid var(--border); border-radius:.75rem; padding:1rem; background:var(--panelAlpha); height:100%; }
    section.border-box h2 { font-weight:700; font-size:clamp(1rem,2.5vw,1.15rem); margin-bottom:.75rem; text-align:center; color:#fff; }

    .form-grid { background-color:var(--panel); border-radius:.75rem; padding:1.25rem; border:2px solid var(--border); }
    .form-card { background:rgba(0,0,0,0.08); border:2px solid var(--border); border-radius:12px; padding:16px; height:100%; }
    .form-card h3 { margin:0 0 10px; font-size:clamp(1rem,2.6vw,1.05rem); font-weight:800; }

    label { font-weight:600; font-size:clamp(.9rem,2.4vw,.92rem); display:block; color:#f9fafb; margin-bottom:6px; }
    input[type="text"], input[type="date"], textarea, select, .file-input {
      width:100%; border-radius:10px; padding:12px 12px; font-weight:600; font-size:1rem; color:#1f2937; border:none; outline:none; background:var(--field); min-height:44px;
    }
    .as-bs.form-control { background:var(--field); border:1px solid #ced4da; border-radius:.6rem; font-weight:600; }
    .as-bs.form-control:focus { border-color:#86b7fe; box-shadow:0 0 0 .25rem rgba(13,110,253,.25); }

    .actions { display:flex; justify-content:center; padding:10px 0 0 0; }
    .btn-primary-ht { background-color:var(--accent); color:#000; border-radius:12px; border:none; font-weight:800; min-height:44px; }
    .btn-primary-ht:hover { filter:brightness(0.95); }

    #baptismal_cert { text-overflow: ellipsis; overflow: hidden; }

    /* DPA GATE */
    .dpa-backdrop { position:fixed; inset:0; background:rgba(0,0,0,0.65); display:none; align-items:center; justify-content:center; z-index:9999; padding:16px; }
    .dpa-modal { max-width:720px; width:100%; background:#111631; color:#fff; border:2px solid var(--border); border-radius:14px; box-shadow:0 20px 60px rgba(0,0,0,0.45); overflow:hidden; }
    .dpa-header { background:var(--ink); padding:14px 18px; font-weight:800; font-size:1.05rem; display:flex; align-items:center; justify-content:space-between; }
    .dpa-body { padding:16px 18px; line-height:1.55; font-size:.96rem; }
    .dpa-footer { padding:14px 18px; display:flex; gap:10px; justify-content:flex-end; background:rgba(255,255,255,0.04); }
    .dpa-btn { border:none; border-radius:10px; padding:10px 14px; cursor:pointer; font-weight:800; }
    .dpa-btn.primary { background:var(--accent); color:#000; }
    .dpa-btn.secondary { background:#2a2f57; color:#fff; }
    .dpa-close-x { background:transparent; border:none; color:#fff; font-size:20px; line-height:1; cursor:pointer; }
    
    /* Center the two top cards and the inner grid rows */
    .top-sections.row { justify-content: center; }
    .form-grid > .row { justify-content: center; }

    /* Guardian section toggle */
    .guardian-hidden { display:none; }
  </style>
</head>
<body>
  <nav class="w-100">
    <a href="appoint-page.php" class="d-inline-flex align-items-center gap-2">
      <img src="image/btn-back.png" alt="Back"><span class="fw-bold"></span>
    </a>
    <div class="d-flex align-items-center gap-2">
      <img src="image/httc_main-logo.jpg" alt="Baptism logo" style="width:50px; height:50px; border-radius:50%;">
      <h1 class="m-0 text-center text-uppercase">BAPTISM APPLICATION FORM</h1>
    </div>
    <div style="width:30px;"></div>
  </nav>

  <main class="container py-3 py-md-4">
    <?php if (!empty($submitError) && !$__quotaExceeded): ?>
      <div class="alert alert-danger mt-3" role="alert">
        <strong>Submission Error:</strong>
        <?= htmlspecialchars($submitError, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>

    <div class="top-sections row g-3 g-md-4">
      <div class="col-12 col-md-6 col-lg-5">
        <section class="border-box h-100">
          <h2>What to Bring?</h2>
          <ul class="mb-0">
            <li>Baptizand’s birth certificate</li>
            <li>Extra set of clothes</li>
            <li>Towel</li>
            <li>Personal toiletries</li>
          </ul>
        </section>
      </div>
      <div class="col-12 col-md-6 col-lg-5">
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
    </div>

    <form id="baptismForm" action="<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
      <div class="form-grid">
        <div class="row g-3 g-md-4">
          <!-- Baptizand -->
          <div class="col-12 col-lg-5">
            <div class="form-card">
              <h3>Baptizand Information</h3>

              <div class="row g-2">
                <div class="col-12 col-md-6">
                  <label for="baptized_lastname">Last Name <span style="color:#ffb3b3">*</span></label>
                  <input type="text" id="baptized_lastname" name="baptized_lastname" class="form-control as-bs" value="<?= $pref_last ?>" required />
                </div>
                <div class="col-12 col-md-6">
                  <label for="baptized_firstname">First Name <span style="color:#ffb3b3">*</span></label>
                  <input type="text" id="baptized_firstname" name="baptized_firstname" class="form-control as-bs" value="<?= $pref_first ?>" required />
                </div>
                <div class="col-12 col-md-6">
                  <label for="baptized_middlename">Middle Name</label>
                  <input type="text" id="baptized_middlename" name="baptized_middlename" class="form-control as-bs" value="<?= $pref_mid ?>" />
                </div>
                <div class="col-12 col-md-6">
                  <label for="baptized_ext">Extension (Jr., III, etc.)</label>
                  <input type="text" id="baptized_ext" name="baptized_ext" class="form-control as-bs" value="<?= $pref_ext ?>" />
                </div>
              </div>

              <label for="baptized_birthdate" class="mt-2">Birthdate <span style="color:#ffb3b3">*</span></label>
              <input type="date" id="baptized_birthdate" name="baptized_birthdate" class="form-control as-bs" value="<?= htmlspecialchars($pref_birth, ENT_QUOTES, 'UTF-8') ?>" required />

              <label for="baptismal_cert" class="mt-2">Upload Birth Certificate <span style="color:#ffb3b3">*</span></label>
              <input
                class="file-input as-bs form-control"
                type="file"
                id="baptismal_cert"
                name="baptismal_cert"
                accept="image/*,.pdf"
                required
              />
              <div class="form-text text-light">
                Upload a clear photo or scanned copy of the birth certificate. Accepted formats: image or PDF (max 8MB).
              </div>
              <div class="form-text text-light mt-2">Note: The fields above are pre-filled from your account’s profile (individual_table). You may edit them if necessary.</div>
            </div>
          </div>

          <!-- Guardian (conditional) -->
          <div class="col-12 col-lg-5">
            <div class="form-card <?= $isMinor ? '' : 'guardian-hidden' ?>" id="guardianCard">
              <h3>Guardian Details (Required for Minors)</h3>
              <div class="row g-2">
                <div class="col-12 col-md-6">
                  <label for="guardian_lastname">Guardian Last Name<?= $isMinor ? ' <span style="color:#ffb3b3">*</span>' : '' ?></label>
                  <input type="text" id="guardian_lastname" name="guardian_lastname" class="form-control as-bs" <?= $isMinor ? 'required' : '' ?> />
                </div>
                <div class="col-12 col-md-6">
                  <label for="guardian_firstname">Guardian First Name<?= $isMinor ? ' <span style="color:#ffb3b3">*</span>' : '' ?></label>
                  <input type="text" id="guardian_firstname" name="guardian_firstname" class="form-control as-bs" <?= $isMinor ? 'required' : '' ?> />
                </div>
                <div class="col-12 col-md-6">
                  <label for="guardian_middlename">Guardian Middle Name</label>
                  <input type="text" id="guardian_middlename" name="guardian_middlename" class="form-control as-bs" />
                </div>
                <div class="col-12 col-md-6">
                  <label for="guardian_ext">Guardian Extension</label>
                  <input type="text" id="guardian_ext" name="guardian_ext" class="form-control as-bs" />
                </div>
                <div class="col-12">
                  <label for="guardian_contactnum">Guardian Contact Number<?= $isMinor ? ' <span style="color:#ffb3b3">*</span>' : '' ?></label>
                  <input type="text" id="guardian_contactnum" name="guardian_contactnum" class="form-control as-bs" <?= $isMinor ? 'required' : '' ?> />
                </div>
              </div>

              <h3 class="mt-3">Additional Details</h3>
              <label for="special_request">Special Request or Message (optional)</label>
              <textarea id="special_request" name="special_request" rows="4" placeholder="Share any details or requests" class="form-control as-bs"></textarea>
            </div>

            <?php if (!$isMinor): ?>
            <div class="form-card" id="extrasWhenNotMinor">
              <h3>Additional Details</h3>
              <label for="special_request">Special Request or Message (optional)</label>
              <textarea id="special_request" name="special_request" rows="4" placeholder="Share any details or requests" class="form-control as-bs"></textarea>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <input type="hidden" name="individual_id" value="<?php echo (int)$individualId; ?>">

        <div class="row mt-3">
          <div class="col-12">
            <div class="consent-row">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="consent_inline" required>
                <label class="form-check-label" for="consent_inline">
                  I agree to the collection and processing of my personal data for baptism appointment purposes in accordance with the Data Privacy Act of 2012 (RA 10173) and
                  <a href="#" id="viewDpaLink" class="text-decoration-underline">HTCCC’s Privacy Notice</a>.
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

  <!-- Success Alert (UPDATED) -->
  <?php if (isset($_GET['success']) && $_GET['success'] === '1'): ?>
    <script>
      (function(){
        Swal.fire({
          icon: 'success',
          title: 'Appointment Submitted',
          html: `
            <p>Please wait for the Secretary Announcement for the process of your Baptism Application.</p>
          `,
          confirmButtonText: 'Go to main page',
          confirmButtonColor: '#d33',
          allowOutsideClick: true
        }).then((res) => {
          try {
            if (window.history && window.history.replaceState) {
              const url = new URL(window.location.href);
              url.searchParams.delete('success');
              window.history.replaceState({}, document.title, url.toString());
            }
          } catch(e){}
          if (res.isConfirmed) {
            window.location.href = 'main-page.php';
          }
        });
      })();
    </script>
  <?php endif; ?>

  <script>
    // Server-driven minor toggle (for extra safety if you want to re-check client-side)
    const IS_MINOR = <?= $isMinor ? 'true' : 'false' ?>;

    // Client-side validation for birth certificate (image or PDF)
    (function(){
      const bcInput = document.getElementById('baptismal_cert');
      const allowedExts = ['jpg','jpeg','png','gif','webp','pdf'];
      const allowedMimes = new Set(['image/jpeg','image/png','image/gif','image/webp','application/pdf']);
      if (bcInput) {
        bcInput.addEventListener('change', function(e) {
          const file = e.target.files && e.target.files[0];
          if (!file) return;
          const ext = (file.name.split('.').pop() || '').toLowerCase();
          const type = (file.type || '').toLowerCase();
          const extOk = allowedExts.includes(ext);
          const mimeOk = allowedMimes.has(type) || type === '';
          if (!extOk || !mimeOk) {
            e.target.value = '';
            Swal.fire({
              icon: 'error',
              title: 'Invalid file type',
              text: 'Please upload an image (JPG, JPEG, PNG, GIF, WEBP) or a PDF file.',
              confirmButtonColor: '#7FC1FF'
            });
          }
        });
      }

      // If not minor, ensure guardian fields are not required (already done server-side via PHP attrs)
      document.addEventListener('DOMContentLoaded', function(){
        if (!IS_MINOR) {
          const guard = document.getElementById('guardianCard');
          if (guard) guard.classList.add('guardian-hidden');
          ['guardian_lastname','guardian_firstname','guardian_contactnum'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.removeAttribute('required');
          });
        }
      });
    })();
  </script>

  <!-- DPA consent (show once before using the form) -->
  <div class="dpa-backdrop" id="dpaBackdrop" role="dialog" aria-modal="true" aria-labelledby="dpaTitle" aria-describedby="dpaDesc">
    <div class="dpa-modal">
      <div class="dpa-header">
        <div id="dpaTitle">Data Privacy Act Consent (RA 10173)</div>
        <button class="dpa-close-x" id="dpaCloseX" aria-label="Close">×</button>
      </div>
      <div class="dpa-body" id="dpaDesc">
        <p>Holy Trinity Christian Community Church (HTCCC) is committed to protecting your personal data in compliance with the <strong>Data Privacy Act of 2012 (RA 10173)</strong> and its Implementing Rules and Regulations.</p>
        <p><strong>What we collect:</strong> Information you provide in this form (e.g., names, birthdates, contact details, IDs and certificates).</p>
        <p><strong>Purpose:</strong> To process and manage your baptism appointment, verify eligibility, coordinate schedules, and comply with church and legal requirements.</p>
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

  <script>
    (function() {
      const backdrop = document.getElementById('dpaBackdrop');
      const btnAgree = document.getElementById('dpaAgree');
      const btnCancel = document.getElementById('dpaCancel');
      const btnCloseX = document.getElementById('dpaCloseX');

      const form = document.getElementById('baptismForm');
      const consentInline = document.getElementById('consent_inline');
      const linkViewDpa = document.getElementById('viewDpaLink');

      const ACCEPT_KEY = 'htcccDpaAccepted';

      function lockBody(lock) { document.body.style.overflow = lock ? 'hidden' : ''; }
      function openDPA() { backdrop.style.display = 'flex'; lockBody(true); setTimeout(() => { btnAgree && btnAgree.focus(); }, 50); }
      function closeDPA() { backdrop.style.display = 'none'; lockBody(false); }

      function accepted() { try { return sessionStorage.getItem(ACCEPT_KEY) === '1'; } catch(e){ return false; } }
      function markAccepted() { try { sessionStorage.setItem(ACCEPT_KEY, '1'); } catch(e){} if (consentInline) consentInline.checked = true; }

      document.addEventListener('DOMContentLoaded', function() {
        const url = new URL(window.location.href);
        const inSuccess = url.searchParams.get('success') === '1';
        if (!accepted() && !inSuccess) { openDPA(); }
        else if (accepted()) { if (consentInline) consentInline.checked = true; }
      });

      btnAgree && btnAgree.addEventListener('click', function(){ markAccepted(); closeDPA(); });
      function cancelFlow() { window.location.href = 'appoint-page.php'; }
      btnCancel && btnCancel.addEventListener('click', cancelFlow);
      btnCloseX && btnCloseX.addEventListener('click', cancelFlow);

      form && form.addEventListener('submit', function(e) {
        if (!consentInline || !consentInline.checked) {
          e.preventDefault();
          openDPA();
          alert('Please agree to the Data Privacy Act consent to proceed.');
          return;
        }
        if (!form.checkValidity()) { e.preventDefault(); e.stopPropagation(); }
        form.classList.add('was-validated');
      }, false);

      linkViewDpa && linkViewDpa.addEventListener('click', function(e){ e.preventDefault(); openDPA(); });
    })();
  </script>

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

  <script>
    (function(){
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
</body>
</html>
