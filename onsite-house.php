<?php
// ============================
// form-house.php (one-file)
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

/* ---------------------------
   Submission handler (before any HTML)
----------------------------*/
$submitError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // helpers
  function req($key, $label = null) {
    if (!isset($_POST[$key]) || trim($_POST[$key]) === '') {
      $label = $label ?: $key;
      throw new RuntimeException("Missing required field: {$label}");
    }
    return trim($_POST[$key]);
  }

  try {
    // Appointment metadata — POST with session fallbacks
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

    // Name parts (no server-side "required" now, just trim)
    $owner_lastname   = isset($_POST['owner_lastname'])   ? trim($_POST['owner_lastname'])   : '';
    $owner_firstname  = isset($_POST['owner_firstname'])  ? trim($_POST['owner_firstname'])  : '';
    $owner_middlename = isset($_POST['owner_middlename']) ? trim($_POST['owner_middlename']) : '';
    $owner_ext        = isset($_POST['owner_ext'])        ? trim($_POST['owner_ext'])        : '';

    // Other required fields
    $contact_info    = req('contact_info', 'Contact Information'); // phone or email
    $home_address    = req('home_address', 'Home Address');
    $attendees_count = req('attendees_count', 'Number of People Attending');  // numeric >=1
    $special_request = isset($_POST['special_request']) ? trim($_POST['special_request']) : null;

    if (!ctype_digit($attendees_count) || (int)$attendees_count < 1) {
      throw new RuntimeException('Number of people attending must be a positive number.');
    }
    $attendees_count_int = (int)$attendees_count;

    // REQUIRED FIELD (service_date)
    $service_date = req('service_date', 'Service Date'); // expected YYYY-MM-DD (selected via floating calendar)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $service_date)) {
      throw new RuntimeException('Invalid service date format.');
    }
    $todayYmd = (new DateTime('today'))->format('Y-m-d');
    if ($service_date < $todayYmd) {
      throw new RuntimeException('Service date cannot be in the past.');
    }

    // individual_id is still in the table and NOT NULL, so insert a default (0)
    $individual_id = 0;

    // Insert including name parts, service_date and service_status = 'Scheduled'
    $sql_ext = "INSERT INTO service_house
                (individual_id,
                 owner_lastname, owner_firstname, owner_middlename, owner_ext,
                 contact_info, home_address, attendees_count, special_request,
                 appointment_date, appointment_time, service_date, service_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    if ($stmt_ext = $db_connection->prepare($sql_ext)) {
      $service_status = 'Scheduled';

      $stmt_ext->bind_param(
        "issssssisssss",
        $individual_id,
        $owner_lastname,
        $owner_firstname,
        $owner_middlename,
        $owner_ext,
        $contact_info,
        $home_address,
        $attendees_count_int,
        $special_request,
        $appointment_date,
        $appointment_time,
        $service_date,
        $service_status
      );
      if (!$stmt_ext->execute()) {
        throw new RuntimeException('Database error: failed to execute insert (service_date).');
      }
      $stmt_ext->close();

      header('Location: ' . basename(__FILE__)
        . '?success=1'
        . '&date='    . rawurlencode($appointment_date)
        . '&time='    . rawurlencode($appointment_time)
        . '&service=' . rawurlencode($appointment_service)
      );
      exit;
    } else {
      throw new RuntimeException('Database error: failed to prepare insert (service_date).');
    }

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

    /* Top Nav */
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

    /* Layout containers */
    main { max-width: 1120px; margin: clamp(12px, 2vw, 24px) auto 0; padding: 0 clamp(12px, 2.5vw, 16px); }

    /* Info boxes */
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

    /* Summary pill */
    .summary {
      color:#fff; font-weight:700; background: var(--ink);
      border-radius:12px; padding: clamp(10px, 2.5vw, 12px) clamp(12px, 2.5vw, 16px);
      text-align:center; margin-bottom: clamp(12px, 2.2vw, 16px);
      word-wrap: break-word; overflow-wrap: anywhere;
      font-size: clamp(.95rem, 2.5vw, 1rem);
    }

    /* Form wrapper */
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

    /* Inputs */
    label { font-weight: 600; font-size: clamp(.9rem, 2.4vw, .92rem); display:block; color:#f9fafb; margin-bottom: 6px; }
    input[type="text"], input[type="number"], textarea {
      width: 100%; border-radius: 12px; padding: clamp(12px, 2.5vw, 14px);
      font-weight: 600; font-size: clamp(.98rem, 2.5vw, 1rem); color: #1f2937; border: none; outline: none; background: var(--field);
      min-height: 44px;
    }
    textarea { resize: vertical; min-height: 100px; }

    /* Actions */
    .actions { display:flex; justify-content:center; padding-top: clamp(8px, 2vw, 12px); }
    .btn-primary-ht { background-color: var(--accent); color: #000; border-radius: 12px; border: none; font-weight: 800; min-height: 44px; padding: 12px 18px; }
    .btn-primary-ht:hover { filter: brightness(0.95); }
    @media (max-width: 575.98px) { .btn-primary-ht { width: 100%; } }

    /* DPA Modal */
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
    .dpa-body p { margin: 0 0 12px; }
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

    /* Floating Calendar Styles */
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

    /* Scrollability base */
    html, body { height: 100%; }
    main {
      overflow-y: auto;
      max-height: 100vh;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: thin;
      scrollbar-color: var(--accent) transparent;
    }
    .sd-backdrop[style*="display: flex"] ~ main,
    .dpa-backdrop[style*="display: flex"] ~ main {
      pointer-events: none;
    }
    body.modal-open { overflow: hidden !important; }

    /* Hide scrollbar but keep scrolling */
    body::-webkit-scrollbar,
    main::-webkit-scrollbar {
      width: 0;
      height: 0;
      background: transparent;
    }
    body, main { scrollbar-width: none; -ms-overflow-style: none; }
    body, main { overflow: auto; -webkit-overflow-scrolling: touch; }
    body.modal-open { overflow: hidden !important; }
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
      <h1 class="m-0 text-center text-uppercase">HOUSE BLESSING APPOINTMENT FORM</h1>
    </div>
    <div style="width:30px;"></div>
  </nav>

  <main class="container py-3 py-md-4">
    <!-- Optional server-side error -->
    <?php if (!empty($submitError)): ?>
      <div class="alert alert-danger mt-3" role="alert">
        <strong>Submission Error:</strong>
        <?= htmlspecialchars($submitError, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>

    <!-- Info boxes -->
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

    <!-- Appointment summary -->
    <div class="summary px-3 py-2">
      <?php
        $safeDate = htmlspecialchars($selDate ?: '—', ENT_QUOTES, 'UTF-8');
        $safeTime = htmlspecialchars($selTime ?: '—', ENT_QUOTES, 'UTF-8');
        $safeSvc  = htmlspecialchars($selService ?: 'HOUSE BLESSING', ENT_QUOTES, 'UTF-8');
        echo "Selected Date: <b>{$safeDate}</b> &nbsp; | &nbsp; Time: <b>{$safeTime}</b> &nbsp; | &nbsp; Service: <b>{$safeSvc}</b>";
      ?>
    </div>

    <!-- SINGLE form (self-post) -->
    <form id="houseForm" action="<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="post" class="needs-validation" novalidate>
      <div class="form-grid">
        <div class="row g-3 g-md-4">
          <!-- Left -->
          <div class="col-12 col-lg-6">
            <div class="form-card">
              <h3>Homeowner & Address</h3>

              <div class="row g-2">
                <div class="col-12 col-md-6">
                  <label for="owner_lastname">Last Name</label>
                  <input type="text" id="owner_lastname" name="owner_lastname" class="form-control"
                         value="<?= htmlspecialchars($_POST['owner_lastname'] ?? '', ENT_QUOTES) ?>" />
                </div>
                <div class="col-12 col-md-6">
                  <label for="owner_firstname">First Name</label>
                  <input type="text" id="owner_firstname" name="owner_firstname" class="form-control"
                         value="<?= htmlspecialchars($_POST['owner_firstname'] ?? '', ENT_QUOTES) ?>" />
                </div>
                <div class="col-12 col-md-6">
                  <label for="owner_middlename">Middle Name</label>
                  <input type="text" id="owner_middlename" name="owner_middlename" class="form-control"
                         value="<?= htmlspecialchars($_POST['owner_middlename'] ?? '', ENT_QUOTES) ?>" />
                </div>
                <div class="col-12 col-md-6">
                  <label for="owner_ext">Extension (Jr., Sr., III, etc.)</label>
                  <input type="text" id="owner_ext" name="owner_ext" class="form-control"
                         value="<?= htmlspecialchars($_POST['owner_ext'] ?? '', ENT_QUOTES) ?>" />
                </div>
              </div>

              <label for="contact_info" class="mt-2">Contact Information <span class="text-danger">*</span></label>
              <input type="text" id="contact_info" name="contact_info" class="form-control" placeholder="Phone or Email" required
                     value="<?= htmlspecialchars($_POST['contact_info'] ?? '', ENT_QUOTES) ?>" />

              <label for="home_address" class="mt-2">Home Address <span class="text-danger">*</span></label>
              <input type="text" id="home_address" name="home_address" class="form-control" required
                     value="<?= htmlspecialchars($_POST['home_address'] ?? '', ENT_QUOTES) ?>" />
            </div>
          </div>

          <!-- Right -->
          <div class="col-12 col-lg-6">
            <div class="form-card">
              <h3>Attendance & Notes</h3>

              <label for="service_date" class="mt-0">Service Date <span class="text-danger">*</span></label>
              <div class="d-flex gap-2">
                <input type="text" id="service_date" name="service_date" class="form-control" placeholder="Select service date" readonly required />
                <button type="button" id="openServiceCalendar" class="btn btn-primary-ht px-3">Pick</button>
              </div>
              <div class="form-text text-light" style="opacity:.8">Click the field or “Pick” to open the calendar.</div>

              <label for="attendees_count" class="mt-3">Number of People Attending <span class="text-danger">*</span></label>
              <input type="number" id="attendees_count" name="attendees_count" class="form-control" min="1" step="1" required
                     value="<?= isset($_POST['attendees_count']) ? (int)$_POST['attendees_count'] : '' ?>" />

              <label for="special_request" class="mt-2">Special Request (optional)</label>
              <textarea id="special_request" name="special_request" rows="4" class="form-control"
                        placeholder="Share any details or requests"><?= htmlspecialchars($_POST['special_request'] ?? '', ENT_QUOTES) ?></textarea>
            </div>
          </div>
        </div>

        <!-- Hidden context -->
        <input type="hidden" name="appointment_date" value="<?= htmlspecialchars($selDate, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="appointment_time" value="<?= htmlspecialchars($selTime, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="appointment_service" value="<?= htmlspecialchars($selService ?: 'HOUSE BLESSING', ENT_QUOTES, 'UTF-8') ?>">

        <!-- Inline consent -->
        <div class="row mt-2">
          <div class="col-12">
            <div class="consent-row">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="consent_inline" required>
                <label class="form-check-label" for="consent_inline">
                  I agree to the collection and processing of my personal data for house blessing appointment purposes in accordance with the Data Privacy Act of 2012 (RA 10173) and HTCCC’s Privacy Notice.
                </label>
              </div>
            </div>
          </div>
        </div>

        <!-- Submit -->
        <div class="actions mt-1">
          <button type="submit" class="btn btn-primary-ht px-4 py-2">
            SUBMIT
          </button>
        </div>
      </div>
    </form>
  </main>

  <!-- Success Alert -->
  <?php if (isset($_GET['success']) && $_GET['success'] === '1'): ?>
    <script>
      const details = {
        date: <?= json_encode($selDate ?: '—') ?>,
        time: <?= json_encode($selTime ?: '—') ?>,
        service: <?= json_encode($selService ?: 'HOUSE BLESSING') ?>
      };
      Swal.fire({
        icon: 'success',
        title: 'Service Scheduled',
        html: `
          <p>The ${details.service.toLowerCase()} service has been <b>scheduled successfully</b>.</p>
          <p style="font-size:.9rem;margin-top:10px;">
            <b>Details</b><br>
            Date: ${details.date}<br>
            Time: ${details.time}<br>
            Service: ${details.service}
          </p>
          <p style="margin-top:14px;">Do you want to book another service?</p>
        `,
        showCancelButton: true,
        confirmButtonText: 'Yes, book again',
        cancelButtonText: 'No, go to dashboard',
        confirmButtonColor: '#7FC1FF',
        cancelButtonColor: '#6c757d'
      }).then((result) => {
        if (result.isConfirmed) {
          window.location.href = 'onsite_appointment.php';
        } else {
          window.location.href = 'secretary_dashboard.php';
        }
      });
    </script>
  <?php endif; ?>

  <!-- DPA Consent Gate -->
  <div class="dpa-backdrop" id="dpaBackdrop" role="dialog" aria-modal="true" aria-labelledby="dpaTitle" aria-describedby="dpaDesc">
    <div class="dpa-modal">
      <div class="dpa-header">
        <div id="dpaTitle">Data Privacy Act Consent (RA 10173)</div>
        <button class="dpa-close-x" id="dpaCloseX" aria-label="Close">×</button>
      </div>
      <div class="dpa-body" id="dpaDesc">
        <p>Holy Trinity Christian Community Church (HTCCC) is committed to protecting your personal data in compliance with the <strong>Data Privacy Act of 2012 (RA 10173)</strong> and its Implementing Rules and Regulations.</p>
        <p><strong>What we collect:</strong> Information you provide in this form (e.g., names, contact details, address).</p>
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
                  <div class="col-md-4"><small class="text-light opacity-75">Service Date</small><div id="rev_service_date" class="fw-bold"></div></div>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <div class="border rounded p-3 h-100">
                <h6 class="fw-bold mb-2">Homeowner</h6>
                <div><small class="text-light opacity-75">Full Name</small><div id="rev_owner_full_name" class="fw-bold"></div></div>
                <div class="mt-2"><small class="text-light opacity-75">Contact</small><div id="rev_contact_info" class="fw-bold"></div></div>
                <div class="mt-2"><small class="text-light opacity-75">Address</small><div id="rev_home_address" class="fw-bold"></div></div>
              </div>
            </div>

            <div class="col-md-6">
              <div class="border rounded p-3 h-100">
                <h6 class="fw-bold mb-2">Attendance & Notes</h6>
                <div><small class="text-light opacity-75"># of People</small><div id="rev_attendees_count" class="fw-bold"></div></div>
                <div class="mt-2"><small class="text-light opacity-75">Special Request</small><div id="rev_special_request" class="fw-bold"></div></div>
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

  <!-- DPA gating + validation -->
  <script>
    (function() {
      const backdrop = document.getElementById('dpaBackdrop');
      const btnAgree = document.getElementById('dpaAgree');
      const btnCancel = document.getElementById('dpaCancel');
      const btnCloseX = document.getElementById('dpaCloseX');
      const form = document.getElementById('houseForm');
      const consentInline = document.getElementById('consent_inline');

      function lockBody(lock) { document.body.style.overflow = lock ? 'hidden' : ''; }
      function openDPA() { backdrop.style.display = 'flex'; lockBody(true); setTimeout(() => { btnAgree && btnAgree.focus(); }, 50); }
      function closeDPA() { backdrop.style.display = 'none'; lockBody(false); }

      document.addEventListener('DOMContentLoaded', openDPA);

      btnAgree && btnAgree.addEventListener('click', function() {
        if (consentInline) consentInline.checked = true;
        closeDPA();
      });

      function cancelFlow() { window.location.href = 'appoint-page.php'; }
      btnCancel && btnCancel.addEventListener('click', cancelFlow);
      btnCloseX && btnCloseX.addEventListener('click', cancelFlow);

      form.addEventListener('submit', function(e) {
        if (!consentInline || !consentInline.checked) {
          e.preventDefault();
          openDPA();
          alert('Please agree to the Data Privacy Act consent to proceed.');
          return;
        }
        if (!form.checkValidity()) {
          e.preventDefault();
          e.stopPropagation();
        }
        form.classList.add('was-validated');
      }, false);
    })();
  </script>

  <!-- DPA show-once behavior -->
  <style id="dpaOnceCSS">.dpa-backdrop{display:none !important}</style>
  <script>
    (function(){
      const DPA_GATE_KEY = 'htccc_dpa_gate_house_v1';

      function hasGateConsent(){
        try { return localStorage.getItem(DPA_GATE_KEY)==='1' || sessionStorage.getItem(DPA_GATE_KEY)==='1'; }
        catch(e){ return false; }
      }
      function markGateConsent(){
        try {
          localStorage.setItem(DPA_GATE_KEY,'1');
          sessionStorage.setItem(DPA_GATE_KEY,'1');
        } catch(e){}
        const consentInline = document.getElementById('consent_inline');
        if (consentInline) consentInline.checked = true;
      }

      document.addEventListener('DOMContentLoaded', function(){
        const btnAgree = document.getElementById('dpaAgree');
        if (btnAgree) btnAgree.addEventListener('click', markGateConsent);

        const form = document.getElementById('houseForm');
        const consentInline = document.getElementById('consent_inline');
        if (form) {
          form.addEventListener('submit', function(){
            if (consentInline && consentInline.checked) markGateConsent();
          });
        }
      });

      document.addEventListener('DOMContentLoaded', function(){
        const urlHasSuccess = new URLSearchParams(location.search).get('success') === '1';
        const styleNode = document.getElementById('dpaOnceCSS');
        const backdrop = document.getElementById('dpaBackdrop');

        if (hasGateConsent() || urlHasSuccess) {
          const consentInline = document.getElementById('consent_inline');
          if (consentInline) consentInline.checked = true;
          return;
        }

        if (styleNode && styleNode.parentNode) styleNode.parentNode.removeChild(styleNode);
        if (backdrop && window.getComputedStyle(backdrop).display === 'none') {
          backdrop.style.display = 'flex';
          document.body.style.overflow = 'hidden';
          const btnAgree = document.getElementById('dpaAgree');
          setTimeout(() => { btnAgree && btnAgree.focus(); }, 50);
        }
      });
    })();
  </script>

  <!-- REVIEW JS -->
  <script>
    (function(){
      const form = document.getElementById('houseForm');
      const submitBtn = form ? form.querySelector('.actions button[type="submit"]') : null;
      const confirmBtn = document.getElementById('confirmSubmitBtn');
      const reviewModalEl = document.getElementById('reviewModal');

      const fields = form ? {
        owner_lastname: document.getElementById('owner_lastname'),
        owner_firstname: document.getElementById('owner_firstname'),
        owner_middlename: document.getElementById('owner_middlename'),
        owner_ext: document.getElementById('owner_ext'),
        contact_info: document.getElementById('contact_info'),
        home_address: document.getElementById('home_address'),
        attendees_count: document.getElementById('attendees_count'),
        special_request: document.getElementById('special_request'),
        appointment_date: form.querySelector('input[name="appointment_date"]'),
        appointment_time: form.querySelector('input[name="appointment_time"]'),
        appointment_service: form.querySelector('input[name="appointment_service"]'),
        service_date: document.getElementById('service_date'),
        consent_inline: document.getElementById('consent_inline')
      } : {};

      function setText(id, val){
        const el = document.getElementById(id);
        if (el) el.textContent = (val && String(val).trim() !== '') ? String(val).trim() : '—';
      }

      function buildFullName() {
        const ln = fields.owner_lastname ? fields.owner_lastname.value.trim() : '';
        const fn = fields.owner_firstname ? fields.owner_firstname.value.trim() : '';
        const mn = fields.owner_middlename ? fields.owner_middlename.value.trim() : '';
        const ex = fields.owner_ext ? fields.owner_ext.value.trim() : '';
        let name = '';

        if (ln || fn) {
          name = ln;
          if (fn) name += (name ? ', ' : '') + fn;
        }
        if (mn) name += ' ' + mn;
        if (ex) name += ' ' + ex;

        return name || '—';
      }

      function populateReview(){
        setText('rev_date', fields.appointment_date ? fields.appointment_date.value : '');
        setText('rev_time', fields.appointment_time ? fields.appointment_time.value : '');
        setText('rev_service', fields.appointment_service ? fields.appointment_service.value : '');
        setText('rev_service_date', fields.service_date ? fields.service_date.value : '');
        setText('rev_owner_full_name', buildFullName());
        setText('rev_contact_info', fields.contact_info ? fields.contact_info.value : '');
        setText('rev_home_address', fields.home_address ? fields.home_address.value : '');
        setText('rev_attendees_count', fields.attendees_count ? fields.attendees_count.value : '');
        setText('rev_special_request', fields.special_request ? fields.special_request.value : '');
      }

      function ensureModal(){
        if (reviewModalEl && window.bootstrap && bootstrap.Modal) {
          return new bootstrap.Modal(reviewModalEl, { backdrop:'static', keyboard:false });
        }
        return null;
      }

      if (submitBtn && form) {
        submitBtn.addEventListener('click', function(e){
          if (!form.checkValidity()) return;
          if (!fields.consent_inline || !fields.consent_inline.checked) return;

          e.preventDefault();
          e.stopPropagation();
          populateReview();
          const m = ensureModal();
          if (m) m.show();
        });
      }

      if (confirmBtn && form) {
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

  <!-- Occupied Dates bootstrapping (PHP -> JS) -->
  <?php
    try {
      $CLOSED = ['Approved','Declined','Cancelled','Archived','Done'];
      $inList = "'" . implode("','", array_map([$db_connection, 'real_escape_string'], $CLOSED)) . "'";
      $sqlOcc = "SELECT DISTINCT COALESCE(service_date, appointment_date) AS d
                 FROM service_house
                 WHERE (service_status IS NULL OR service_status NOT IN ($inList))
                   AND COALESCE(service_date, appointment_date) IS NOT NULL";
      $occ = [];
      if ($resOcc = $db_connection->query($sqlOcc)) {
        while ($row = $resOcc->fetch_assoc()) {
          if (!empty($row['d'])) $occ[] = $row['d'];
        }
        $resOcc->free();
      }
    } catch (Throwable $ex) { $occ = []; }
  ?>
  <script>
    window.__OCCUPIED_DATES__ = <?php echo json_encode($occ ?? [], JSON_UNESCAPED_SLASHES); ?>;
  </script>

  <!-- Floating Calendar markup -->
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

  <!-- Floating Calendar JS -->
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

      const OCCUPIED = new Set((window.__OCCUPIED_DATES__ || []).map(String));
      let cursor = new Date();
      let pendingPick = null;

      function ymd(d){
        const m = d.getMonth()+1, day=d.getDate();
        return d.getFullYear() + '-' + String(m).padStart(2,'0') + '-' + String(day).padStart(2,'0');
      }
      function startOfMonth(d){ return new Date(d.getFullYear(), d.getMonth(), 1); }
      function endOfMonth(d){ return new Date(d.getFullYear(), d.getMonth()+1, 0); }
      function openCal(){
        backdrop.style.display = 'flex';
        pendingPick = input.value || null;
        render();
      }
      function closeCal(){ backdrop.style.display='none'; }
      function apply(){
        if(pendingPick){ input.value = pendingPick; }
        closeCal();
      }
      function render(){
        const s = startOfMonth(cursor);
        const e = endOfMonth(cursor);
        title.textContent = s.toLocaleString(undefined, {month:'long', year:'numeric'});
        grid.innerHTML = '';
        const firstDow = s.getDay();
        for(let i=0;i<firstDow;i++){
          const cell = document.createElement('div');
          cell.className = 'sd-cell disabled';
          grid.appendChild(cell);
        }
        const today = new Date(); today.setHours(0,0,0,0);
        for(let day=1; day<=e.getDate(); day++){
          const d = new Date(cursor.getFullYear(), cursor.getMonth(), day);
          const dateStr = ymd(d);
          const cell = document.createElement('div');
          const isPast = d < today;
          const isOccupied = OCCUPIED.has(dateStr);
          cell.className = 'sd-cell' + (isPast ? ' disabled' : '');
          const num = document.createElement('div'); num.className='num'; num.textContent=String(day);
          const badge = document.createElement('div');
          badge.className='pill';
          if(isOccupied){
            badge.textContent='occupied';
          } else {
            badge.style.opacity=.6; badge.textContent='available';
          }
          const btn = document.createElement('button');
          btn.setAttribute('type','button');
          btn.onclick = function(){
            if(isPast) return;
            pendingPick = dateStr;
            Array.from(grid.querySelectorAll('.sd-cell')).forEach(c => { c.style.outline=''; });
            cell.style.outline='2px solid var(--accent)';
          };
          btn.appendChild(num);
          cell.appendChild(btn);
          cell.appendChild(badge);
          grid.appendChild(cell);
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

  <!-- Safety: restore body scroll -->
  <script>
    (function(){
      document.addEventListener('DOMContentLoaded', function(){
        try { if (!document.body.classList.contains('modal-open')) document.body.style.overflow = 'auto'; } catch(e){}
      });
      window.addEventListener('focus', function(){
        try {
          if (!document.querySelector('.modal.show') && document.getElementById('dpaBackdrop')?.style.display !== 'flex') {
            document.body.style.overflow = 'auto';
          }
        } catch(e){}
      });
    })();
  </script>

  <!-- Scroll Fallback Shim -->
  <script>
    (function(){
      const main = document.querySelector('main');
      function ensureScrollable() {
        const canMainScroll = !!main && main.scrollHeight > main.clientHeight;
        if (!canMainScroll) {
          document.documentElement.style.overflow = 'auto';
          document.body.style.overflow = 'auto';
        }
      }
      document.addEventListener('DOMContentLoaded', ensureScrollable);
      window.addEventListener('resize', ensureScrollable);

      if (main) {
        main.addEventListener('wheel', function(e){}, { passive: true });
        main.addEventListener('touchmove', function(){}, { passive: true });
      }
    })();
  </script>
</body>
</html>
