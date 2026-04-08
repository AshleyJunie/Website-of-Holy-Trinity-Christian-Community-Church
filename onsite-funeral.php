<?php
// =========================
// HTCCC - Funeral Form
// =========================
session_start();

// Bring over the appointment selections from the appointment page
$selDate    = isset($_GET['date'])    ? trim($_GET['date'])    : '';
$selTime    = isset($_GET['time'])    ? trim($_GET['time'])    : '';
$selService = isset($_GET['service']) ? trim($_GET['service']) : 'FUNERAL SERVICE';

/* ===== Name helpers (same style as wedding) ===== */
if (!function_exists('htccc_titlecase_name')) {
  function htccc_titlecase_name(string $s): string {
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if ($s === '') return '';
    return preg_replace_callback('/\b(\p{L})(\p{L}*)/u', function($m){
      return mb_strtoupper($m[1]) . mb_strtolower($m[2]);
    }, $s);
  }
}
if (!function_exists('htccc_normalize_suffix')) {
  function htccc_normalize_suffix(?string $s): string {
    $s = trim((string)$s);
    if ($s === '') return '';
    $map = [
      'jr' => 'Jr.', 'jr.' => 'Jr.', 'junior' => 'Jr.',
      'sr' => 'Sr.', 'sr.' => 'Sr.', 'senior' => 'Sr.',
      'i'=>'I','ii'=>'II','iii'=>'III','iv'=>'IV','v'=>'V','vi'=>'VI','vii'=>'VII','viii'=>'VIII','ix'=>'IX','x'=>'X'
    ];
    $k = strtolower(str_replace('.', '', $s));
    return $map[$k] ?? htccc_titlecase_name($s);
  }
}

/* ===== Utility: ensure columns exist (auto-ADD if missing) - same as wedding ===== */
function htccc_ensure_columns(PDO $pdo, string $table, array $ddlByCol): void {
  $in = implode(',', array_fill(0, count($ddlByCol), '?'));
  $q = $pdo->prepare("
    SELECT COLUMN_NAME 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = ? 
      AND COLUMN_NAME IN ($in)
  ");
  $params = array_merge([$table], array_keys($ddlByCol));
  $q->execute($params);
  $have = [];
  foreach ($q->fetchAll(PDO::FETCH_COLUMN, 0) as $c) $have[$c] = true;
  foreach ($ddlByCol as $col => $ddl) {
    if (!isset($have[$col])) {
      $sql = "ALTER TABLE $table ADD COLUMN $col $ddl";
      try { $pdo->exec($sql); } catch (Throwable $e) { /* ignore race */ }
    }
  }
}

/* Relax NOT NULL → NULL for specific columns (so guests work) */
if (!function_exists('htccc_relax_nullable')) {
  function htccc_relax_nullable(PDO $pdo, string $table, array $colDefs): void {
    $stmt = $pdo->prepare("
      SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_TYPE
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    if (!$stmt) return;
    $stmt->execute([$table]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) $map[$r['COLUMN_NAME']] = $r;

    foreach ($colDefs as $col => $def) {
      if (!isset($map[$col])) continue;
      $isNullable = strtoupper((string)$map[$col]['IS_NULLABLE']) === 'YES';
      if ($isNullable) continue;
      $sql = "ALTER TABLE {$table} MODIFY COLUMN {$col} {$def}";
      try { $pdo->exec($sql); } catch (Throwable $e) { /* ignore */ }
    }
  }
}

/* ===== Inline submission handler ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $pdo = new PDO(
      "mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4",
      "root",
      "",
      [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]
    );

    $req = function($key) {
      if (!isset($_POST[$key]) || $_POST[$key] === '') {
        throw new RuntimeException("Missing required field: {$key}");
      }
      return trim($_POST[$key]);
    };

    // Server fallback for service_date: if blank, use appointment_date
    if (empty($_POST['service_date'] ?? '')) {
      if (!empty($_POST['appointment_date'] ?? '')) {
        $_POST['service_date'] = trim((string)$_POST['appointment_date']);
      }
    }

    // Required fields
    $appointment_date    = $req('appointment_date');
    $appointment_time    = $req('appointment_time');
    $appointment_service = $_POST['appointment_service'] ?? 'FUNERAL SERVICE';
    $service_date        = $req('service_date');   // YYYY-MM-DD

    // Basic service_date validation (not in the past)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $service_date)) {
      throw new RuntimeException('Invalid service date format.');
    }
    $todayYmd = (new DateTime('today'))->format('Y-m-d');
    if ($service_date < $todayYmd) {
      throw new RuntimeException('Service date cannot be in the past.');
    }

    // Deceased split name
    $deceased_lastname   = htccc_titlecase_name($req('deceased_lastname'));
    $deceased_firstname  = htccc_titlecase_name($req('deceased_firstname'));
    $deceased_middlename = isset($_POST['deceased_middlename']) ? htccc_titlecase_name($_POST['deceased_middlename']) : '';
    $deceased_ext        = isset($_POST['deceased_ext']) ? htccc_normalize_suffix($_POST['deceased_ext']) : '';

    $deceased_birthdate  = $req('deceased_birthdate');
    $home_address        = $req('home_address');
    $funeral_date        = $req('funeral_date');

    $contact_person      = isset($_POST['contact_person']) ? trim($_POST['contact_person']) : '';
    $contact_number      = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
    $email_address       = isset($_POST['email_address'])  ? trim($_POST['email_address'])  : '';
    $remarks             = isset($_POST['remarks'])        ? trim($_POST['remarks'])        : null;

    // Ensure required columns exist in service_funeral (no more individual_id)
    htccc_ensure_columns($pdo, 'service_funeral', [
      'appointment_date'    => "DATE NULL",
      'appointment_time'    => "VARCHAR(50) NULL",
      'appointment_service' => "VARCHAR(100) NULL",
      'service_date'        => "DATE NULL",
      'deceased_lastname'   => "VARCHAR(150) NULL",
      'deceased_firstname'  => "VARCHAR(150) NULL",
      'deceased_middlename' => "VARCHAR(150) NULL",
      'deceased_ext'        => "VARCHAR(20) NULL",
      'deceased_birthdate'  => "DATE NULL",
      'home_address'        => "VARCHAR(500) NULL",
      'funeral_date'        => "DATE NULL",
      'contact_person'      => "VARCHAR(200) NULL",
      'contact_number'      => "VARCHAR(100) NULL",
      'email_address'       => "VARCHAR(200) NULL",
      'remarks'             => "TEXT NULL",
      'status'              => "VARCHAR(50) NULL",
      'service_status'      => "VARCHAR(50) NULL"
    ]);

    // Relax NOT NULL → NULL for contact fields (guests)
    htccc_relax_nullable($pdo, 'service_funeral', [
      'contact_person' => "VARCHAR(200) NULL",
      'contact_number' => "VARCHAR(100) NULL",
      'email_address'  => "VARCHAR(200) NULL"
    ]);

    // Final INSERT (status = Pending, service_status = Scheduled)
    $sql = "INSERT INTO service_funeral (
        appointment_date, appointment_time, appointment_service, service_date,
        deceased_lastname, deceased_firstname, deceased_middlename, deceased_ext,
        deceased_birthdate, home_address,
        contact_person, contact_number, email_address,
        funeral_date, remarks,
        status, service_status
      ) VALUES (
        :appointment_date, :appointment_time, :appointment_service, :service_date,
        :deceased_lastname, :deceased_firstname, :deceased_middlename, :deceased_ext,
        :deceased_birthdate, :home_address,
        :contact_person, :contact_number, :email_address,
        :funeral_date, :remarks,
        'Pending', 'Scheduled'
      )";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':appointment_date'    => $appointment_date,
      ':appointment_time'    => $appointment_time,
      ':appointment_service' => $appointment_service,
      ':service_date'        => $service_date,

      ':deceased_lastname'   => $deceased_lastname,
      ':deceased_firstname'  => $deceased_firstname,
      ':deceased_middlename' => $deceased_middlename,
      ':deceased_ext'        => $deceased_ext,

      ':deceased_birthdate'  => $deceased_birthdate,
      ':home_address'        => $home_address,

      ':contact_person'      => $contact_person,
      ':contact_number'      => $contact_number,
      ':email_address'       => $email_address,

      ':funeral_date'        => $funeral_date,
      ':remarks'             => $remarks
    ]);

    // Redirect with success flag to trigger SweetAlert on this same page
    header('Location: ' . basename(__FILE__)
      . '?success=1'
      . '&date='    . rawurlencode($appointment_date)
      . '&time='    . rawurlencode($appointment_time)
      . '&service=' . rawurlencode($appointment_service)
    );
    exit;

  } catch (Throwable $e) {
    http_response_code(400);
    echo "<h2 style='font-family:Arial'>Submission Error</h2>";
    echo "<p style='font-family:Arial'>".htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')."</p>";
    echo "<p><a href='javascript:history.back()'>Go back</a></p>";
    exit;
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta content="width=device-width, initial-scale=1" name="viewport" />
<title>HTCCC - Funeral Service Appointment Form</title>
<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">

<!-- Bootstrap -->
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
  background-position:center; background-size:cover; background-attachment:fixed; background-repeat:no-repeat;
  margin:0; color:var(--text);
}
nav { background-color: var(--bg); display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:10px 20px; color:#fff; }
nav img { width:30px; height:30px; border-radius:50%; }
nav h1 { margin:0; font-size:20px; font-weight:800; }
nav a { color:#fff; text-decoration:none; display:flex; align-items:center; gap:8px; }
main { max-width:1120px; margin:1.5rem auto 0; padding:0 1rem; }
.top-sections { margin-bottom:1.5rem; }
section.border-box { border:2px solid var(--border); border-radius:.75rem; padding:1rem; background:var(--panelAlpha); height:100%; }
section.border-box h2 { font-weight:700; font-size:1.15rem; margin-bottom:.75rem; text-align:center; color:#fff; }
section.border-box ul { list-style-type:disc; padding-left:1.25rem; font-weight:600; font-size:.95rem; line-height:1.4rem; margin:0; color:#fff; }

.summary { color:#fff; font-weight:700; background:var(--ink); border-radius:12px; padding:12px 16px; text-align:center; margin-bottom:1rem; }

.form-grid { background-color:var(--panel); border-radius:.75rem; padding:1.25rem; border:2px solid var(--border); }
@media (min-width:768px){ .form-grid{ padding:1.5rem; } }
@media (min-width:992px){ .form-grid{ padding:2rem; } }
.form-card { background:rgba(0,0,0,0.08); border:2px solid var(--border); border-radius:12px; padding:16px; height:100%; }
@media (min-width:768px){ .form-card{ padding:18px; } }
.form-card h3 { margin:0 0 10px; font-size:1.05rem; font-weight:800; }

label { font-weight:600; font-size:.92rem; display:block; color:#f9fafb; margin-bottom:6px; }
label span { font-weight:400; opacity:.9; }

input[type="text"],input[type="date"],textarea,select {
  width:100%; border-radius:8px; padding:8px 12px; font-weight:600; font-size:.98rem; color:#1f2937; border:none; outline:none; background:var(--field);
}
.as-bs.form-control { background:var(--field); border:1px solid #ced4da; border-radius:.5rem; font-weight:600; }
.as-bs.form-control:focus { border-color:#86b7fe; box-shadow:0 0 0 .25rem rgba(13,110,253,.25); }
textarea.as-bs { resize:vertical; }

.actions { display:flex; justify-content:center; padding:10px 0 0 0; }
.btn-primary-ht { background-color:var(--accent); color:#000; border-radius:10px; border:none; font-weight:800; }
.btn-primary-ht:hover { filter:brightness(0.95); }

/* DPA */
.dpa-backdrop { position:fixed; inset:0; background:rgba(0,0,0,0.65); display:none; align-items:center; justify-content:center; z-index:9999; padding:16px; }
.dpa-modal { max-width:720px; width:100%; background:#111631; color:#fff; border:2px solid var(--border); border-radius:14px; box-shadow:0 20px 60px rgba(0,0,0,0.45); overflow:hidden; }
.dpa-header { background:var(--ink); padding:14px 18px; font-weight:800; font-size:1.05rem; display:flex; align-items:center; justify-content:space-between; }
.dpa-body { padding:16px 18px; line-height:1.55; font-size:.96rem; }
.dpa-body p { margin:0 0 12px; }
.dpa-footer { padding:14px 18px; display:flex; gap:10px; justify-content:flex-end; background:rgba(255,255,255,0.04); }
.dpa-btn { border:none; border-radius:10px; padding:10px 14px; cursor:pointer; font-weight:800; }
.dpa-btn.primary { background:var(--accent); color:#000; }
.dpa-btn.secondary { background:#2a2f57; color:#fff; }
.dpa-close-x { background:transparent; border:none; color:#fff; font-size:20px; line-height:1; cursor:pointer; }

/* Name breakdown */
.name-breakdown-hint { color:#dce4ff; font-size:.85rem; margin-top:.35rem; }
.name-breakdown-grid { display:grid; gap:.6rem; grid-template-columns: 1fr; }
@media (min-width: 768px){ .name-breakdown-grid { grid-template-columns: 1fr 1fr; } }
.name-preview { font-size:.9rem; color:#bcd7ff; margin-top:.35rem; }

/* Floating Calendar Styles */
.sd-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.4);display:none;align-items:center;justify-content:center;z-index:10000;padding:10px}
.sd-panel{width:min(680px,95vw);background:#0e1330;color:#fff;border:2px solid var(--border);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,0.45);padding:14px}
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
  <a href="appoint-page.php" class="d-inline-flex align-items-center gap-2">
    <img src="image/btn-back.png" alt="Back"><span class="fw-bold">Back</span>
  </a>
  <div class="d-flex align-items-center gap-2">
    <img src="image/httc_main-logo.jpg" alt="Funeral logo" style="width:50px; height:50px; border-radius:50%;">
    <h1 class="m-0 text-center text-uppercase">FUNERAL SERVICE APPOINTMENT FORM</h1>
  </div>
  <div style="width:30px;"></div>
</nav>

<main class="container py-3 py-md-4">
  <div class="top-sections row g-3 g-md-4">
    <div class="col-12 col-md-6">
      <section class="border-box h-100">
        <h2>What to Prepare?</h2>
        <ul class="mb-0">
          <li>Deceased’s full name and birthdate</li>
          <li>Home address and wake location (if applicable)</li>
          <li>Preferred funeral date and special requests</li>
        </ul>
      </section>
    </div>
    <div class="col-12 col-md-6">
      <section class="border-box h-100">
        <h2>What to Expect?</h2>
        <ul class="mb-0">
          <li>Electricity fee (if using church facilities)</li>
          <li>Gas fee for the Pastor</li>
          <li>Offerings are optional but encouraged</li>
        </ul>
      </section>
    </div>
  </div>

  <div class="summary px-3 py-2">
    <?php
      $safeDate = htmlspecialchars($selDate ?: '—', ENT_QUOTES, 'UTF-8');
      $safeTime = htmlspecialchars($selTime ?: '—', ENT_QUOTES, 'UTF-8');
      $safeSvc  = htmlspecialchars($selService ?: 'FUNERAL SERVICE', ENT_QUOTES, 'UTF-8');
      echo "Selected Date: <b>{$safeDate}</b> &nbsp; | &nbsp; Time: <b>{$safeTime}</b> &nbsp; | &nbsp; Service: <b>{$safeSvc}</b>";
    ?>
  </div>

  <!-- SINGLE form -->
  <form id="funeralForm" action="" method="post" class="needs-validation" novalidate>
    <div class="form-grid">
      <div class="row g-3 g-md-4">

        <!-- Deceased Information -->
        <div class="col-12 col-lg-6">
          <div class="form-card">
            <h3>Deceased Information</h3>

            <div class="name-breakdown-hint">
              Enter in this order: <b>Last Name, First Name, Middle Name (optional), Extension (optional)</b>.
            </div>

            <div class="name-breakdown-grid">
              <div>
                <label for="dc_last">Last Name <span class="text-danger">*</span></label>
                <input type="text" id="dc_last" name="deceased_lastname" class="form-control as-bs" required>
              </div>
              <div>
                <label for="dc_first">First Name <span class="text-danger">*</span></label>
                <input type="text" id="dc_first" name="deceased_firstname" class="form-control as-bs" required>
              </div>
              <div>
                <label for="dc_middle">Middle Name <span class="text-light opacity-75">(optional)</span></label>
                <input type="text" id="dc_middle" name="deceased_middlename" class="form-control as-bs">
              </div>
              <div>
                <label for="dc_ext">Extension <span class="text-light opacity-75">(optional)</span></label>
                <select id="dc_ext" name="deceased_ext" class="form-control as-bs">
                  <option value="">—</option>
                  <option>Jr.</option><option>Sr.</option>
                  <option>I</option><option>II</option><option>III</option><option>IV</option><option>V</option>
                </select>
              </div>
            </div>

            <div class="name-preview">Preview: <span id="dc_preview">—</span></div>

            <label for="deceased_birthdate" class="mt-2">Deceased’s Birthdate <span class="text-danger">*</span></label>
            <input type="date" id="deceased_birthdate" name="deceased_birthdate" class="form-control as-bs" required />

            <label for="home_address" class="mt-2">Home Address / Wake Location <span class="text-danger">*</span></label>
            <textarea id="home_address" name="home_address" rows="3" class="form-control as-bs" required></textarea>
          </div>
        </div>

        <!-- Contact + Schedule -->
        <div class="col-12 col-lg-6">
          <div class="form-card">
            <h3>Contact & Schedule</h3>

            <label for="contact_person">Contact Person <span class="text-danger">*</span></label>
            <input type="text" id="contact_person" name="contact_person" class="form-control as-bs" required>

            <label for="contact_number" class="mt-2">Contact Number <span class="text-danger">*</span></label>
            <input type="text" id="contact_number" name="contact_number" class="form-control as-bs" required>

            <label for="email_address" class="mt-2">Email Address <span class="text-light">(optional)</span></label>
            <input type="text" id="email_address" name="email_address" class="form-control as-bs">

            <label for="service_date" class="mt-3">Service Date <span class="text-danger">*</span></label>
            <div class="d-flex gap-2">
              <input type="text" id="service_date" name="service_date" class="form-control as-bs"
                     placeholder="Select service date" readonly required
                     value="<?php echo htmlspecialchars($selDate, ENT_QUOTES, 'UTF-8'); ?>" />
              <button type="button" id="openServiceCalendar" class="btn btn-primary-ht px-3">Pick</button>
            </div>
            <div class="form-text text-light" style="opacity:.8">Click the field or “Pick” to open the calendar.</div>

            <label for="funeral_date" class="mt-3">Funeral Date <span class="text-danger">*</span></label>
            <input type="date" id="funeral_date" name="funeral_date" class="form-control as-bs" required />

            <label for="remarks" class="mt-3">Special Request or Message (optional)</label>
            <textarea id="remarks" name="remarks" rows="4"
                      placeholder="Share any details or requests for the funeral service"
                      class="form-control as-bs"></textarea>
          </div>
        </div>

      </div>

      <!-- Inline consent -->
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

      <!-- Hidden context -->
      <input type="hidden" name="appointment_date" value="<?php echo htmlspecialchars($selDate, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="appointment_time" value="<?php echo htmlspecialchars($selTime, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="appointment_service" value="<?php echo htmlspecialchars($selService ?: 'FUNERAL SERVICE', ENT_QUOTES, 'UTF-8'); ?>">

      <div class="actions mt-1">
        <button type="submit" class="btn btn-primary-ht px-4 py-2">SUBMIT</button>
      </div>
    </div>
  </form>
</main>

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

<!-- DPA Consent Gate -->
<div class="dpa-backdrop" id="dpaBackdrop" role="dialog" aria-modal="true" aria-labelledby="dpaTitle" aria-describedby="dpaDesc">
  <div class="dpa-modal">
    <div class="dpa-header">
      <div id="dpaTitle">Data Privacy Act Consent (RA 10173)</div>
      <button class="dpa-close-x" id="dpaCloseX" aria-label="Close">×</button>
    </div>
    <div class="dpa-body" id="dpaDesc">
      <p>Holy Trinity Christian Community Church (HTCCC) is committed to protecting your personal data in compliance with the <strong>Data Privacy Act of 2012 (RA 10173)</strong> and its Implementing Rules and Regulations.</p>
      <p><strong>What we collect:</strong> Information you provide in this form (e.g., names, birthdates, contact details, and funeral details).</p>
      <p><strong>Purpose:</strong> To process and manage your funeral service appointment, verify details, coordinate schedules, and comply with church and legal requirements.</p>
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

<!-- REVIEW MODAL (summary like wedding) -->
<style>
#reviewModal .modal-content { border:2px solid var(--border); background:#0e1330; color:#fff; }
#reviewModal .modal-header { background:var(--ink); }
#reviewModal .table-review th, #reviewModal .table-review td { border-color:#2a2f57 !important; }
#reviewModal .table-review th { width:220px; color:#dbe7ff; }
#reviewModal .section-title { font-weight:800; color:#dbe7ff; margin-top:6px; }
</style>
<div class="modal fade" id="reviewModal" tabindex="-1" aria-labelledby="reviewLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="reviewLabel">Review &amp; Confirm Your Funeral Appointment</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="section-title">Appointment</div>
        <table class="table table-sm table-bordered table-review align-middle">
          <tbody>
            <tr><th>Date</th><td id="rev_date">—</td></tr>
            <tr><th>Time</th><td id="rev_time">—</td></tr>
            <tr><th>Service</th><td id="rev_service">—</td></tr>
            <tr><th>Service Date</th><td id="rev_service_date">—</td></tr>
          </tbody>
        </table>

        <div class="section-title">Deceased</div>
        <table class="table table-sm table-bordered table-review align-middle">
          <tbody>
            <tr><th>Full Name</th><td id="rev_deceased_name">—</td></tr>
            <tr><th>Birthdate</th><td id="rev_deceased_birthdate">—</td></tr>
            <tr><th>Home Address / Wake Location</th><td id="rev_home_address">—</td></tr>
          </tbody>
        </table>

        <div class="section-title">Contact & Schedule</div>
        <table class="table table-sm table-bordered table-review align-middle">
          <tbody>
            <tr><th>Contact Person</th><td id="rev_contact_person">—</td></tr>
            <tr><th>Contact Number</th><td id="rev_contact_number">—</td></tr>
            <tr><th>Email Address</th><td id="rev_email_address">—</td></tr>
            <tr><th>Funeral Date</th><td id="rev_funeral_date">—</td></tr>
            <tr><th>Special Request / Message</th><td id="rev_remarks">—</td></tr>
          </tbody>
        </table>
      </div>
      <div class="modal-footer" style="background:rgba(255,255,255,0.04);">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Back</button>
        <button type="button" id="confirmSubmitBtn" class="btn btn-primary" style="background:var(--accent); color:#000; font-weight:800;">Confirm &amp; Submit</button>
      </div>
    </div>
  </div>
</div>

<!-- DPA show-once CSS marker -->
<style id="dpaOnceCSS">.dpa-backdrop{display:none}</style>

<?php
// Occupied dates for funeral service (for floating calendar)
// Based on service_funeral: COALESCE(service_date, funeral_date)
try {
  $pdoShow = new PDO("mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4","root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $CLOSED = ['Approved','Declined','Cancelled','Archived','Done'];
  $ph = implode(',', array_fill(0, count($CLOSED), '?'));
  $q = $pdoShow->prepare("
    SELECT DISTINCT COALESCE(service_date, funeral_date) AS d
    FROM service_funeral
    WHERE (status IS NULL OR status NOT IN ($ph))
      AND COALESCE(service_date, funeral_date) IS NOT NULL
  ");
  $q->execute($CLOSED);
  $occ = [];
  foreach ($q as $r) {
    $d = $r['d'] ?? null;
    if ($d) $occ[] = $d;
  }
} catch (Throwable $ex) { $occ = []; }
?>
<script>window.__OCCUPIED_DATES__ = <?php echo json_encode($occ, JSON_UNESCAPED_SLASHES); ?>;</script>

<!-- JS: Name preview -->
<script>
(function(){
  const last = document.getElementById('dc_last');
  const first= document.getElementById('dc_first');
  const mid  = document.getElementById('dc_middle');
  const ext  = document.getElementById('dc_ext');
  const prev = document.getElementById('dc_preview');

  function val(x){ return (x && typeof x.value === 'string') ? x.value.trim() : ''; }
  function titlecaseWords(s){
    return (s||'').toString().replace(/\s+/g,' ').trim()
      .replace(/\b([A-Za-z])([A-Za-z]*)/g,(_,a,b)=>a.toUpperCase()+b.toLowerCase());
  }
  function compose(){
    const ln = titlecaseWords(val(last));
    const fn = titlecaseWords(val(first));
    const mn = titlecaseWords(val(mid));
    const ex = val(ext);
    const after = [fn,mn].filter(Boolean).join(' ');
    const sx = ex ? (' '+ex) : '';
    const full = (ln||'') + (after ? (', '+after) : '') + sx;
    prev.textContent = full || '—';
  }
  ['input','change'].forEach(ev => {
    [last,first,mid,ext].forEach(el => el && el.addEventListener(ev, compose));
  });
  compose();
})();
</script>

<!-- CONSOLIDATED DPA SHOW-ONCE + link trigger -->
<script>
(function(){
  const DPA_GATE_KEY = 'htccc_dpa_gate_funeral_v1';
  const backdrop    = document.getElementById('dpaBackdrop');
  const btnAgree    = document.getElementById('dpaAgree');
  const btnCancel   = document.getElementById('dpaCancel');
  const btnCloseX   = document.getElementById('dpaCloseX');
  const linkViewDpa = document.getElementById('viewDpaLink');
  const form        = document.getElementById('funeralForm');
  const consentInline = document.getElementById('consent_inline');

  (function removeHideStyle(){
    const styleNode = document.getElementById('dpaOnceCSS');
    if (styleNode && styleNode.parentNode) { styleNode.parentNode.removeChild(styleNode); }
  })();

  function lockBody(lock){ document.body.style.overflow = lock ? 'hidden' : ''; }
  function openDPA(){ if(!backdrop) return; backdrop.style.setProperty('display','flex','important'); lockBody(true); setTimeout(()=>{ btnAgree&&btnAgree.focus(); },50); }
  function closeDPA(){ if(!backdrop) return; backdrop.style.setProperty('display','none','important'); lockBody(false); }
  function hasGateConsent(){ try { return localStorage.getItem(DPA_GATE_KEY)==='1' || sessionStorage.getItem(DPA_GATE_KEY)==='1'; } catch(e){ return false; } }
  function markGateConsent(){ try { localStorage.setItem(DPA_GATE_KEY,'1'); sessionStorage.setItem(DPA_GATE_KEY,'1'); } catch(e){} if (consentInline) consentInline.checked = true; }

  document.addEventListener('DOMContentLoaded', function(){
    const urlHasSuccess = new URLSearchParams(location.search).get('success') === '1';
    if (!hasGateConsent() && !urlHasSuccess) { openDPA(); }
    else { if (consentInline) consentInline.checked = true; }

    if (linkViewDpa) { linkViewDpa.addEventListener('click', function(e){ e.preventDefault(); openDPA(); }); }
  });

  btnAgree && btnAgree.addEventListener('click', function(){ markGateConsent(); closeDPA(); });
  function cancelFlow(){ window.location.href = 'appoint-page.php'; }
  btnCancel && btnCancel.addEventListener('click', cancelFlow);
  btnCloseX && btnCloseX.addEventListener('click', cancelFlow);

  form && form.addEventListener('submit', function(e){
    if (!consentInline || !consentInline.checked) { e.preventDefault(); openDPA(); alert('Please agree to the Data Privacy Act consent to proceed.'); return; }
    markGateConsent();
    if (!form.checkValidity()) { e.preventDefault(); e.stopPropagation(); }
    form.classList.add('was-validated');
  }, false);
})();
</script>

<!-- REVIEW JS -->
<script>
(function(){
  const form = document.getElementById('funeralForm');
  if (!form) return;
  const submitBtn = form.querySelector('.actions button[type="submit"]');
  const confirmBtn = document.getElementById('confirmSubmitBtn');
  const reviewModalEl = document.getElementById('reviewModal');

  function id(x){ return document.getElementById(x); }

  const fields = {
    dc_last: id('dc_last'),
    dc_first: id('dc_first'),
    dc_middle: id('dc_middle'),
    dc_ext: id('dc_ext'),
    deceased_birthdate: id('deceased_birthdate'),
    home_address: id('home_address'),

    contact_person: id('contact_person'),
    contact_number: id('contact_number'),
    email_address: id('email_address'),

    funeral_date: id('funeral_date'),
    service_date: id('service_date'),
    remarks: id('remarks'),

    appointment_date: form.querySelector('input[name="appointment_date"]'),
    appointment_time: form.querySelector('input[name="appointment_time"]'),
    appointment_service: form.querySelector('input[name="appointment_service"]'),
    consent_inline: id('consent_inline')
  };

  function setText(idName, val){
    const el = document.getElementById(idName);
    if (!el) return;
    el.textContent = (val && String(val).trim() !== '') ? String(val).trim() : '—';
  }
  function titlecaseWords(s){
    return (s||'').toString().replace(/\s+/g,' ').trim()
      .replace(/\b([A-Za-z])([A-Za-z]*)/g,(_,a,b)=>a.toUpperCase()+b.toLowerCase());
  }
  function composeDisplay(ln, fn, mn, ex){
    const after=[titlecaseWords(fn),titlecaseWords(mn)].filter(Boolean).join(' ');
    const sx=ex?(' '+ex):'';
    return (titlecaseWords(ln)||'') + (after?(', '+after):'') + sx;
  }

  function populateReview(){
    setText('rev_date', fields.appointment_date.value);
    setText('rev_time', fields.appointment_time.value);
    setText('rev_service', fields.appointment_service.value);
    setText('rev_service_date', fields.service_date.value);

    setText('rev_deceased_name', composeDisplay(fields.dc_last.value, fields.dc_first.value, fields.dc_middle.value, fields.dc_ext.value));
    setText('rev_deceased_birthdate', fields.deceased_birthdate.value);
    setText('rev_home_address', fields.home_address.value);

    setText('rev_contact_person', fields.contact_person.value);
    setText('rev_contact_number', fields.contact_number.value);
    setText('rev_email_address', fields.email_address.value);
    setText('rev_funeral_date', fields.funeral_date.value);
    setText('rev_remarks', fields.remarks.value);
  }

  function ensureModal(){
    if (reviewModalEl && window.bootstrap && bootstrap.Modal) {
      return new bootstrap.Modal(reviewModalEl, { backdrop:'static', keyboard:false });
    }
    return null;
  }

  if (submitBtn) {
    submitBtn.addEventListener('click', function(e){
      if (!form.checkValidity()) return;
      if (!fields.consent_inline || !fields.consent_inline.checked) return;
      e.preventDefault(); e.stopPropagation();
      populateReview();
      const m = ensureModal(); if (m) m.show();
    });
  }

  if (confirmBtn) {
    confirmBtn.addEventListener('click', function(){
      confirmBtn.disabled = true;
      confirmBtn.textContent = 'Submitting...';
      const marker = document.createElement('input');
      marker.type='hidden'; marker.name='review_confirmed'; marker.value='1';
      form.appendChild(marker);
      form.submit();
    });
  }
})();
</script>

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
    const m=d.getMonth()+1, day=d.getDate();
    return d.getFullYear()+'-'+String(m).padStart(2,'0')+'-'+String(day).padStart(2,'0');
  }
  function startOfMonth(d){ return new Date(d.getFullYear(), d.getMonth(), 1); }
  function endOfMonth(d){ return new Date(d.getFullYear(), d.getMonth()+1, 0); }

  function openCal(){ backdrop.style.display='flex'; pendingPick = input.value || null; render(); }
  function closeCal(){ backdrop.style.display='none'; }
  function apply(){
    if(pendingPick){
      input.value = pendingPick;
      input.dispatchEvent(new Event('input'));
      input.dispatchEvent(new Event('change'));
    }
    closeCal();
  }

  function render(){
    const s=startOfMonth(cursor), e=endOfMonth(cursor);
    title.textContent = s.toLocaleString(undefined, {month:'long', year:'numeric'});
    grid.innerHTML = '';
    for(let i=0;i<s.getDay();i++){
      const c=document.createElement('div');
      c.className='sd-cell disabled';
      grid.appendChild(c);
    }

    const today=new Date(); today.setHours(0,0,0,0);
    for(let day=1; day<=e.getDate(); day++){
      const d=new Date(cursor.getFullYear(), cursor.getMonth(), day);
      const dateStr=ymd(d);
      const cell=document.createElement('div');
      const isPast = d < today;
      const isOcc  = OCCUPIED.has(dateStr);
      cell.className='sd-cell'+(isPast?' disabled':'');

      const num=document.createElement('div'); num.className='num'; num.textContent=String(day);
      const badge=document.createElement('div'); badge.className='pill'; badge.textContent=isOcc?'occupied':'available'; if(!isOcc) badge.style.opacity=.6;

      const btn=document.createElement('button'); btn.type='button';
      btn.onclick=function(){ if(isPast) return; pendingPick=dateStr; Array.from(grid.querySelectorAll('.sd-cell')).forEach(c=>c.style.outline=''); cell.style.outline='2px solid var(--accent)'; };

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

<?php if (isset($_GET['success']) && $_GET['success'] === '1'): ?>
<script>
(function(){
  const details = {
    date: <?= json_encode($selDate ?: '—') ?>,
    time: <?= json_encode($selTime ?: '—') ?>,
    service: <?= json_encode($selService ?: 'FUNERAL SERVICE') ?>
  };
  Swal.fire({
    icon: 'success',
    title: 'Service Scheduled',
    html: `
      <p>The funeral service has been <b>scheduled successfully</b>.</p>
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

</body>
</html>
