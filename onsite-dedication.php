<?php
// ============================
// form-dedication.php (one-file)
// ============================

if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db-connection.php'; // must define $db_connection (mysqli)

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

/* ============================================================
   SERVER-SIDE helpers (limit + upload validation)
   ============================================================ */

/* Ensure status/service_status columns exist */
if (!function_exists('htccc_ensure_status_columns_mysqli')) {
  function htccc_ensure_status_columns_mysqli(mysqli $db, string $tableName): void {
    $tableSafe = $db->real_escape_string($tableName);
    $have = [];
    $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$tableSafe}'
              AND COLUMN_NAME IN ('status','service_status')";
    if ($res = $db->query($sql)) {
      while ($row = $res->fetch_assoc()) {
        $c = $row['COLUMN_NAME'] ?? '';
        if ($c) $have[$c] = true;
      }
      $res->free();
    }
    $needed = ['status','service_status'];
    foreach ($needed as $col) {
      if (!isset($have[$col])) {
        $colSafe = $col === 'status' ? 'status' : 'service_status';
        $alter = "ALTER TABLE `{$tableSafe}` ADD COLUMN `{$colSafe}` VARCHAR(50) NULL DEFAULT NULL";
        try { $db->query($alter); } catch (Throwable $e) {}
      }
    }
  }
}

// ---- Strict revalidation for saved images ----
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

/* ============================================================
   SERVICE DATE capacity (per Sunday, only Scheduled)
   ============================================================ */
if (!function_exists('htccc_count_for_service_date_scheduled')) {
  function htccc_count_for_service_date_scheduled(mysqli $db, string $dateYmd): int {
    $sql = "SELECT COUNT(*) AS c
            FROM service_dedication
            WHERE service_status = 'Scheduled'
              AND COALESCE(service_date, appointment_date) = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) return 0;
    $stmt->bind_param("s", $dateYmd);
    $cnt = 0;
    if ($stmt->execute()) {
      $r = $stmt->get_result();
      if ($r) { $row = $r->fetch_assoc(); $cnt = (int)($row['c'] ?? 0); $r->free(); }
    }
    $stmt->close();
    return $cnt;
  }
}

/* ---------------------------
   Submission handler (before any HTML)
----------------------------*/
$submitError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // helper
  function req($key, $label = null) {
    $label = $label ?: $key;
    if (!isset($_POST[$key]) || trim($_POST[$key]) === '') {
      throw new RuntimeException("Missing required field: {$label}");
    }
    return trim($_POST[$key]);
  }

  // file saver (images only)
  function save_uploaded_image($file, $subdir = 'uploads/dedication', $maxBytes = 8_388_608) {
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) return [null, 'Upload failed.'];
    if ($file['size'] > $maxBytes) return [null, 'File too large (max 8 MB).'];
    $allowedExt  = ['jpg','jpeg','png'];
    $allowedMime = ['image/jpeg','image/png'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) return [null, 'Invalid file type. Allowed: JPG, JPEG, PNG.'];
    $mime = '';
    if (function_exists('finfo_open')) {
      $finfo = new finfo(FILEINFO_MIME_TYPE);
      $mime  = $finfo->file($file['tmp_name']) ?: '';
      if (!in_array($mime, $allowedMime, true)) return [null, 'Invalid file content. Only image files (JPG/PNG) are allowed.'];
    }
    $baseDir = rtrim(__DIR__ . '/' . trim($subdir, '/'), '/');
    if (!is_dir($baseDir)) @mkdir($baseDir, 0775, true);
    $safeName = bin2hex(random_bytes(6)) . '_' . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $file['name']);
    $destAbs  = $baseDir . '/' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $destAbs)) return [null, 'Failed to save uploaded file.'];
    $rel = trim($subdir, '/') . '/' . $safeName;
    return [$rel, null];
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

    // Child name parts
    $child_lastname   = req('child_lastname',  'Child last name');
    $child_firstname  = req('child_firstname', 'Child first name');
    $child_middlename = trim($_POST['child_middlename'] ?? '');
    $child_ext        = trim($_POST['child_ext'] ?? '');

    // Child birthdate + age guard
    $child_birthdate  = req('child_birthdate', 'Child’s birthdate'); // YYYY-MM-DD
    if ($child_birthdate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $child_birthdate)) {
      throw new RuntimeException("Birthdate must be in YYYY-MM-DD format.");
    }

    try {
      $today  = new DateTime('today');
      $maxDob = (clone $today)->modify('-6 months'); // youngest (6 months)
      $minDob = (clone $today)->modify('-3 years');  // oldest (3 years)

      $dobObj = DateTime::createFromFormat('Y-m-d', $child_birthdate);
      $isValidFormat = $dobObj && $dobObj->format('Y-m-d') === $child_birthdate;

      if (!$isValidFormat) throw new RuntimeException('Child’s birthdate is invalid.');

      if ($dobObj > $today)       throw new RuntimeException('Child’s birthdate cannot be in the future.');
      if ($dobObj > $maxDob)      throw new RuntimeException('Child must be at least 6 months old.');
      if ($dobObj < $minDob)      throw new RuntimeException('Child must be 3 years old or younger.');
    } catch (Throwable $ageEx) {
      throw $ageEx;
    }

    // Guardian parts (all optional, manual entry)
    $g_ln = trim($_POST['guardian_lastname']   ?? '');
    $g_fn = trim($_POST['guardian_firstname']  ?? '');
    $g_mn = trim($_POST['guardian_middlename'] ?? '');
    $g_ex = trim($_POST['guardian_ext']        ?? '');

    // Service date (Sundays only + capacity)
    $service_date = req('service_date', 'Service Date');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $service_date)) throw new RuntimeException('Invalid service date format.');
    $todayYmd = (new DateTime('today'))->format('Y-m-d');
    if ($service_date < $todayYmd) throw new RuntimeException('Service date cannot be in the past.');
    $dow = (int) (new DateTime($service_date))->format('w');
    if ($dow !== 0) throw new RuntimeException('Dedication can only be scheduled on Sundays.');

    // Capacity check: count only Scheduled
    $existingCount = htccc_count_for_service_date_scheduled($db_connection, $service_date);
    $MAX_PER_SUNDAY = 4;
    if ($existingCount >= $MAX_PER_SUNDAY) throw new RuntimeException('Selected Sunday is already fully booked (4/4). Please choose another Sunday.');

    // Files (images only)
    $bcert = $_FILES['baptismal_cert'] ?? null;
    if (!$bcert || $bcert['error'] !== UPLOAD_ERR_OK) throw new RuntimeException("Child’s birth certificate image is required.");
    [$bcertPath, $err1] = save_uploaded_image($bcert);
    if ($err1) throw new RuntimeException("Birth certificate: {$err1}");
    try { _strict_revalidate_upload_imgonly($bcertPath, $__IMG_MIME_MAP); }
    catch (Throwable $revalEx) { if ($bcertPath && file_exists(__DIR__ . '/' . $bcertPath)) @unlink(__DIR__ . '/' . $bcertPath); throw $revalEx; }

    // Make sure service_status column exists
    htccc_ensure_status_columns_mysqli($db_connection, 'service_dedication');

    /* ------------------------------------------------------------
       Single INSERT: service_status is always "Scheduled"
       ------------------------------------------------------------ */
    $sql = "INSERT INTO service_dedication
            (child_birthdate,
             child_lastname, child_firstname, child_middlename, child_ext,
             guardian_lastname, guardian_firstname, guardian_middlename, guardian_ext,
             baptismal_cert_path, appointment_date, appointment_time, service_date, service_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Scheduled')";

    if (!$stmt = $db_connection->prepare($sql)) {
      if ($bcertPath && file_exists(__DIR__ . '/' . $bcertPath)) @unlink(__DIR__ . '/' . $bcertPath);
      throw new RuntimeException('Database error: failed to prepare statement.');
    }

    $stmt->bind_param(
      "sssssssssssss",
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
      $appointment_time,
      $service_date
    );

    if (!$stmt->execute()) {
      if ($bcertPath && file_exists(__DIR__ . '/' . $bcertPath)) @unlink(__DIR__ . '/' . $bcertPath);
      $stmt->close();
      throw new RuntimeException('Database error: failed to execute insert.');
    }
    $stmt->close();

    // Redirect with success flag to trigger SweetAlert
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
  }
}

/* ---------------------------
   Selections for rendering
----------------------------*/
$selDate    = _pick('date');
$selTime    = _pick('time');
$selService = _pick('service');
if ($selService === '') $selService = 'DEDICATION';
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

    /* DPA Modal */
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

    /* Floating calendar */
    .sd-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.4);display:none;align-items:center;justify-content:center;z-index:10000;padding:10px}
    .sd-panel{width:min(680px,95vw);background:#0e1330;color:#fff;border:2px solid var(--border);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.45);padding:14px}
    .sd-head{display:flex;align-items:center;justify-content:space-between;background:var(--ink);border-radius:12px;padding:10px 12px;margin-bottom:10px}
    .sd-head .title{font-weight:800}
    .sd-navbtn{background:#2a2f57;border:none;color:#fff;border-radius:10px;padding:8px 12px;font-weight:800}
    .sd-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:8px}
    .sd-cell{background:#11183c;border:1px solid var(--border);border-radius:12px;padding:10px 8px;min-height:54px;display:flex;flex-direction:column;justify-content:space-between}
    .sd-cell button{all:unset;cursor:pointer;display:block}
    .sd-cell .num{font-weight:800}
    .sd-cell .pill{align-self:flex-start;font-size:.72rem;padding:2px 6px;border-radius:999px;background:#17345c;color:#dceaff;border:1px solid #315d93}
    .sd-cell.disabled{opacity:.35;filter:grayscale(40%)}
    .sd-foot{display:flex;justify-content:flex-end;gap:10px;margin-top:10px}
    .sd-foot .sd-navbtn.primary{background:var(--accent);color:#000}
    .sd-week{display:grid;grid-template-columns:repeat(7,1fr);gap:8px;margin-bottom:8px}
    .sd-week div{font-size:.82rem;text-align:center;opacity:.85}
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
            <li>Child’s Birth Certificate (image)</li>
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

              <!-- Hidden posts for child parts -->
              <input type="hidden" name="child_lastname"   id="hid_child_lastname">
              <input type="hidden" name="child_firstname"  id="hid_child_firstname">
              <input type="hidden" name="child_middlename" id="hid_child_middlename">
              <input type="hidden" name="child_ext"        id="hid_child_ext">

              <label for="child_birthdate" class="mt-2">Child’s Birthdate <span class="text-danger">*</span></label>
              <input type="date" id="child_birthdate" name="child_birthdate" class="form-control" required
                     value="<?= htmlspecialchars($_POST['child_birthdate'] ?? '', ENT_QUOTES) ?>" />

              <label for="baptismal_cert" class="mt-2">Upload Child’s Birth Certificate (Image) <span class="text-danger">*</span></label>
              <input type="file" id="baptismal_cert" name="baptismal_cert" class="form-control"
                     accept=".jpg,.jpeg,.png,image/jpeg,image/png" required />
              <div class="form-text text-light">Allowed: JPG, JPEG, PNG (max 8 MB)</div>
            </div>
          </div>

          <!-- Right -->
          <div class="col-12 col-lg-6">
            <div class="form-card">
              <h3>Guardian</h3>

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

              <label for="service_date" class="mt-3">Service Date (Sundays only) <span class="text-danger">*</span></label>
              <div class="d-flex gap-2">
                <input type="text" id="service_date" name="service_date" class="form-control" placeholder="Select Sunday" readonly required />
                <button type="button" id="openServiceCalendar" class="btn btn-primary-ht px-3">Pick</button>
              </div>
              <div class="form-text text-light" style="opacity:.8">
                Only Sundays are available. Each Sunday has a maximum of <b>4</b> dedication slots.
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
          date: <?= json_encode($selDate ?: '—') ?>,
          time: <?= json_encode($selTime ?: '—') ?>,
          service: <?= json_encode($selService ?: 'DEDICATION') ?>
        };
        Swal.fire({
          icon: 'success',
          title: 'Service Scheduled',
          html: `
            <p>The <b>${details.service}</b> service has been <b>scheduled successfully</b>.</p>
            <div style="margin:12px 0; font-size:0.9rem; text-align:left;">
              <b>Details</b><br>
              Date: ${details.date}<br>
              Time: ${details.time}<br>
              Service: ${details.service}
            </div>
            <p>Do you want to book another service?</p>
          `,
          confirmButtonText: 'Yes, book again',
          denyButtonText: 'No, go to dashboard',
          showDenyButton: true,
          allowOutsideClick: false,
          confirmButtonColor: '#7FC1FF'
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
            window.location.href = 'onsite_appointment.php';
          } else {
            window.location.href = 'secretary_dashboard.php';
          }
        });
      })();
    </script>
  <?php endif; ?>

  <!-- DPA Consent Modal -->
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

            <div class="col-12">
              <div class="border rounded p-3">
                <h6 class="fw-bold mb-2">Service Schedule</h6>
                <div class="row">
                  <div class="col-md-4"><small class="text-light opacity-75">Service Date (Sunday)</small><div id="rev_service_date" class="fw-bold"></div></div>
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
      /* ===== DPA GATE ===== */
      const backdrop = document.getElementById('dpaBackdrop');
      const btnAgree = document.getElementById('dpaAgree');
      const btnCancel = document.getElementById('dpaCancel');
      const btnCloseX = document.getElementById('dpaCloseX');
      const linkViewDpa = document.getElementById('viewDpaLink');
      const consentInlineDpa = document.getElementById('consent_inline');

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

      /* ===== File validation ===== */
      const MAX_SIZE = 8 * 1024 * 1024;
      const ALLOWED = ['jpg','jpeg','png'];
      function checkFileInput(input) {
        const f = input?.files?.[0];
        if (!f) return true;
        const ext = (f.name.split('.').pop() || '').toLowerCase();
        if (!ALLOWED.includes(ext)) {
          input.value = '';
          Swal.fire({icon:'error', title:'Invalid file type', text:'Allowed: JPG, JPEG, PNG (max 8 MB).', confirmButtonColor:'#7FC1FF'});
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
        service_date: document.getElementById('service_date'),
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
        setText('rev_service_date', fields.service_date?.value || '');
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

      /* Compose CHILD name + hidden parts */
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

      /* Submit pre-flight guard */
      const submitBtnGuard = form?.querySelector('.actions button[type="submit"]');
      const consentInline = document.getElementById('consent_inline');
      if (submitBtnGuard) {
        submitBtnGuard.addEventListener('click', function(e){
          forceComposeChild();

          form.classList.add('was-validated');
          if (typeof form.reportValidity === 'function') form.reportValidity();

          if (!consentInline || !consentInline.checked) {
            e.stopImmediatePropagation(); e.preventDefault();
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
            Swal.fire({icon:'error', title:'Missing Birth Certificate', text:'Please upload the child’s birth certificate (JPG/PNG, max 8 MB).', confirmButtonColor:'#7FC1FF'});
            return;
          }
          const svc = document.getElementById('service_date');
          if (!svc || !svc.value) {
            e.stopImmediatePropagation(); e.preventDefault();
            Swal.fire({icon:'error', title:'Pick a Sunday', text:'Please select a Service Date (Sundays only).', confirmButtonColor:'#7FC1FF'});
            return;
          }
        }, true);
      }

      function ensureOk(){ return form.checkValidity() && consentInline?.checked && (fields.bcert?.files?.[0]) && fields.service_date?.value; }

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
    })();
  </script>

  <!-- Occupied counts bootstrap -->
  <?php
    try {
      $sqlOcc = "SELECT COALESCE(service_date, appointment_date) AS d, COUNT(*) AS c
                 FROM service_dedication
                 WHERE service_status = 'Scheduled'
                   AND COALESCE(service_date, appointment_date) IS NOT NULL
                 GROUP BY COALESCE(service_date, appointment_date)";
      $occMap = [];
      if ($resOcc = $db_connection->query($sqlOcc)) {
        while ($row = $resOcc->fetch_assoc()) {
          if (!empty($row['d'])) $occMap[$row['d']] = (int)$row['c'];
        }
        $resOcc->free();
      }
    } catch (Throwable $ex) { $occMap = []; }
  ?>
  <script>
    window.__DEDICATION_COUNTS__ = <?php echo json_encode($occMap ?? [], JSON_UNESCAPED_SLASHES); ?>;
    window.__DEDICATION_MAX_PER_SUNDAY__ = 4;
  </script>

  <!-- Floating Calendar -->
  <div class="sd-backdrop" id="sdBackdrop" role="dialog" aria-modal="true" aria-labelledby="sdTitle">
    <div class="sd-panel">
      <div class="sd-head">
        <button class="sd-navbtn" id="sdPrev" aria-label="Previous month">«</button>
        <div class="title" id="sdTitle">Choose Date</div>
        <button class="sd-navbtn" id="sdNext" aria-label="Next month">»</button>
      </div>
      <div class="sd-week">
        <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
      </div>
      <div class="sd-grid" id="sdGrid" aria-live="polite"></div>
      <div class="sd-foot">
        <button class="sd-navbtn" id="sdClose">Close</button>
        <button class="sd-navbtn primary" id="sdApply">Apply</button>
      </div>
    </div>
  </div>

  <script>
    (function(){
      const input = document.getElementById('service_date');
      const openBtn = document.getElementById('openServiceCalendar');
      const backdrop = document.getElementById('sdBackdrop');
      const grid = document.getElementById('sdGrid');
      const title = document.getElementById('sdTitle');
      const prev = document.getElementById('sdPrev');
      const next = document.getElementById('sdNext');
      const closeBtn = document.getElementById('sdClose');
      const applyBtn = document.getElementById('sdApply');

      if(!input || !backdrop || !grid) return;

      const COUNTS = window.__DEDICATION_COUNTS__ || {};
      const MAX = Number(window.__DEDICATION_MAX_PER_SUNDAY__ || 4);

      let cursor = new Date();
      let pendingPick = null;

      function ymd(d){ const m=d.getMonth()+1, day=d.getDate(); return d.getFullYear()+'-'+String(m).padStart(2,'0')+'-'+String(day).padStart(2,'0'); }
      function startOfMonth(d){ return new Date(d.getFullYear(), d.getMonth(), 1); }
      function endOfMonth(d){ return new Date(d.getFullYear(), d.getMonth()+1, 0); }
      function isSunday(d){ return d.getDay() === 0; }

      function openCal(){ backdrop.style.display='flex'; pendingPick = input.value || null; render(); }
      function closeCal(){ backdrop.style.display='none'; }
      function apply(){ if(pendingPick){ input.value = pendingPick; } closeCal(); }

      function render(){
        const s = startOfMonth(cursor);
        const e = endOfMonth(cursor);
        title.textContent = s.toLocaleString(undefined, {month:'long', year:'numeric'});
        grid.innerHTML = '';
        const firstDow = s.getDay();
        for(let i=0;i<firstDow;i++){ const cell=document.createElement('div'); cell.className='sd-cell disabled'; grid.appendChild(cell); }
        const today = new Date(); today.setHours(0,0,0,0);
        for(let day=1; day<=e.getDate(); day++){
          const d = new Date(cursor.getFullYear(), cursor.getMonth(), day);
          const dateStr = ymd(d);
          const cell = document.createElement('div');
          const past = d < today;
          const sunday = isSunday(d);
          const used = Number(COUNTS[dateStr] || 0);
          const full = used >= MAX;
          const disabled = past || !sunday || full;

          cell.className = 'sd-cell' + (disabled ? ' disabled' : '');
          const num = document.createElement('div'); num.className='num'; num.textContent=String(day);
          const badge = document.createElement('div'); badge.className = 'pill';
          if (!sunday) { badge.textContent = 'not available'; badge.style.opacity = .7; }
          else if (full) { badge.textContent = 'full (4/4)'; }
          else { const left = Math.max(0, MAX - used); badge.textContent = `available ${left}/${MAX}`; badge.style.opacity = .85; }
          const btn = document.createElement('button'); btn.setAttribute('type','button');
          btn.onclick = function(){ if(disabled) return; pendingPick = dateStr; Array.from(grid.querySelectorAll('.sd-cell')).forEach(c => c.style.outline=''); cell.style.outline='2px solid var(--accent)'; };
          btn.appendChild(num);
          cell.appendChild(btn); cell.appendChild(badge); grid.appendChild(cell);
        }
      }

      [input, openBtn].forEach(el => el && el.addEventListener('click', openCal));
      prev.addEventListener('click', () => { cursor = new Date(cursor.getFullYear(), cursor.getMonth()-1, 1); render(); });
      next.addEventListener('click', () => { cursor = new Date(cursor.getFullYear(), cursor.getMonth()+1, 1); render(); });
      closeBtn.addEventListener('click', closeCal);
      applyBtn.addEventListener('click', apply);
      backdrop.addEventListener('click', (e)=>{ if(e.target===backdrop) closeCal(); });
      document.addEventListener('keydown', (e)=>{ if(backdrop.style.display==='flex' && e.key==='Escape') closeCal(); });
    })();
  </script>

  <?php if (!empty($submitError)): ?>
    <script>
      Swal.fire({ icon:'error', title:'Submission Error', text: <?= json_encode($submitError) ?>, confirmButtonColor:'#7FC1FF' });
    </script>
  <?php endif; ?>

  <!-- DPA modal init fix -->
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

  <!-- Child Birthdate SweetAlert-only validator (6m–3y) -->
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
</body>
</html>
