<?php
// ================== PHP (top of file) ==================
session_start();
$isLoggedIn = isset($_SESSION['individual_id']);

$showLoginSuccess = false;
if (!empty($_SESSION['just_logged_in'])) {
  $showLoginSuccess = true;
  unset($_SESSION['just_logged_in']); // consume flash
}

require_once 'db-connection.php'; // must define $db_connection (mysqli)

/* ------------------------------------------------------------------
   Helpers: map service names to tables + occupancy by service
-------------------------------------------------------------------*/

/** Get current database/schema name (cached) */
function get_db_schema(mysqli $db): string {
  static $schema = '';
  if ($schema !== '') return $schema;

  if ($res = $db->query("SELECT DATABASE()")) {
    $row = $res->fetch_row();
    $schema = $row[0] ?? '';
    $res->free();
  }
  return $schema;
}

/**
 * Map a human-readable service name to its service_* table.
 * Adjust names here if needed.
 */
function map_service_name_to_table(string $svc): ?string {
  $u = strtoupper(trim($svc));
  switch ($u) {
    case 'WEDDING':
      return 'service_wedding';
    case 'FUNERAL SERVICE':
      return 'service_funeral';
    case 'HOUSE BLESSING':
      return 'service_house';
    case 'DEDICATION':
      return 'service_dedication';
    default:
      return null;
  }
}

/**
 * Returns the status column name ('service_status' or 'status') if it exists,
 * else null.
 */
function get_service_status_column(mysqli $db, string $schema, string $table): ?string {
  static $cache = [];
  $key = "{$schema}.{$table}.statuscol";
  if (array_key_exists($key, $cache)) {
    return $cache[$key];
  }

  $sql = "SELECT COLUMN_NAME
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = ?
            AND TABLE_NAME = ?
            AND COLUMN_NAME IN ('service_status','status')
          LIMIT 1";

  if ($stmt = $db->prepare($sql)) {
    $stmt->bind_param('ss', $schema, $table);
    $stmt->execute();
    $rs = $stmt->get_result();
    $col = null;
    if ($rs && ($row = $rs->fetch_assoc())) {
      $col = (string)($row['COLUMN_NAME'] ?? '');
    }
    $stmt->close();
    $cache[$key] = $col ?: null;
    return $cache[$key];
  }

  $cache[$key] = null;
  return null;
}

/**
 * Get per-date occupancy for a specific service (for a given month),
 * counting rows with service_status = 'Scheduled'.
 * Returns array like ['YYYY-MM-DD' => count, ...]
 *
 * NOTE: This version is simplified for HTCCC:
 * - All service_* tables use columns: service_date (DATE) and service_status (VARCHAR).
 * - We rely on the currently selected database and do not try to auto-detect the schema.
 */
function get_service_occupancy_for_month(mysqli $db, string $serviceName, int $year, int $month): array {
  $result = [];

  $table = map_service_name_to_table($serviceName);
  if (!$table) return $result;

  // Basic sanity; fall back to today's Y/m if something weird comes in
  if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
  }
  if ($month < 1 || $month > 12) {
    $month = (int)date('n');
  }

  $start = sprintf('%04d-%02d-01', $year, $month);
  $dt = DateTime::createFromFormat('Y-m-d', $start);
  if (!$dt) return $result;
  $dt->modify('last day of this month');
  $end = $dt->format('Y-m-d');

  // All service tables share service_date + service_status
  $sql = "SELECT service_date, COUNT(*) AS cnt
          FROM `{$table}`
          WHERE service_date BETWEEN ? AND ?
            AND `service_status` = 'Scheduled'
          GROUP BY service_date";

  if ($stmt = $db->prepare($sql)) {
    $stmt->bind_param('ss', $start, $end);
    if ($stmt->execute()) {
      $rs = $stmt->get_result();
      while ($rs && ($row = $rs->fetch_assoc())) {
        $d = (string)($row['service_date'] ?? '');
        if ($d !== '') {
          $result[$d] = (int)($row['cnt'] ?? 0);
        }
      }
    }
    $stmt->close();
  }

  return $result;
}

/**
 * Get global per-date occupancy for Wedding, Funeral Service, and House Blessing
 * combined (for a given month). Any scheduled booking from these services
 * makes that date occupied for all of them.
 *
 * Returns array like ['YYYY-MM-DD' => count, ...]
 *
 * NOTE: Simplified for HTCCC:
 * - Tables: service_wedding, service_funeral, service_house
 * - Columns: service_date, service_status
 */
function get_global_occupancy_for_month(mysqli $db, int $year, int $month): array {
  $result = [];

  // Basic sanity
  if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
  }
  if ($month < 1 || $month > 12) {
    $month = (int)date('n');
  }

  $start = sprintf('%04d-%02d-01', $year, $month);
  $dt = DateTime::createFromFormat('Y-m-d', $start);
  if (!$dt) return $result;
  $dt->modify('last day of this month');
  $end = $dt->format('Y-m-d');

  // Services that share a global occupancy (1 booking blocks the date)
  $serviceNames = ['WEDDING', 'FUNERAL SERVICE', 'HOUSE BLESSING'];

  foreach ($serviceNames as $svcName) {
    $table = map_service_name_to_table($svcName);
    if (!$table) continue;

    $sql = "SELECT service_date, COUNT(*) AS cnt
            FROM `{$table}`
            WHERE `service_status` = 'Scheduled'
              AND service_date BETWEEN ? AND ?
            GROUP BY service_date";

    if ($stmt = $db->prepare($sql)) {
      $stmt->bind_param('ss', $start, $end);
      if ($stmt->execute()) {
        $rs = $stmt->get_result();
        while ($rs && ($row = $rs->fetch_assoc())) {
          $d = (string)($row['service_date'] ?? '');
          if ($d !== '') {
            $prev = isset($result[$d]) ? (int)$result[$d] : 0;
            $result[$d] = $prev + (int)($row['cnt'] ?? 0);
          }
        }
      }
      $stmt->close();
    }
  }

  return $result;
}

/* ------------------------ JSON endpoints ------------------------ */

/**
 * AJAX: get occupancy for a given service & month.
 */
if (isset($_GET['__ajax']) && $_GET['__ajax'] === 'occupancy') {
  header('Content-Type: application/json; charset=utf-8');

  $svc   = isset($_GET['service']) ? (string)$_GET['service'] : '';
  $year  = isset($_GET['y']) ? (int)$_GET['y'] : (int)date('Y');
  $month = isset($_GET['m']) ? (int)$_GET['m'] : (int)date('n');

  if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
  }
  if ($month < 1 || $month > 12) {
    $month = (int)date('n');
  }

  $svcKey = strtoupper(trim($svc));
  $counts = [];
  if ($svcKey !== '') {
    if (in_array($svcKey, ['WEDDING','FUNERAL SERVICE','HOUSE BLESSING'], true)) {
      $counts = get_global_occupancy_for_month($db_connection, $year, $month);
    } else {
      $counts = get_service_occupancy_for_month($db_connection, $svcKey, $year, $month);
    }
  }

  echo json_encode([
    'ok'      => true,
    'service' => $svcKey,
    'year'    => $year,
    'month'   => $month,
    'counts'  => $counts
  ], JSON_UNESCAPED_SLASHES);
  exit;
}

/* Remember selections (flash) before redirecting to login */
if (isset($_GET['__ajax']) && $_GET['__ajax'] === 'remember') {
  header('Content-Type: application/json; charset=utf-8');
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true) ?: [];
  $_SESSION['preselect_date']    = isset($data['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$data['date']) ? (string)$data['date'] : '';
  $_SESSION['preselect_time']    = isset($data['time']) ? trim((string)$data['time']) : '';
  $_SESSION['preselect_service'] = isset($data['service']) ? trim((string)$data['service']) : '';
  echo json_encode(['ok' => true]);
  exit;
}

/* ---- Preselects from query/session ---- */
$__raw_service = isset($_GET['service']) ? trim((string)$_GET['service']) : '';
$__svc_u = strtoupper($__raw_service);
if (in_array($__svc_u, ['BAPTISM','WATER BAPTISM'], true)) { $__svc_u = ''; } // excluded
$__valid_services = ['WEDDING','FUNERAL SERVICE','HOUSE BLESSING','DEDICATION'];
$__preselect_service = in_array($__svc_u, $__valid_services, true) ? $__svc_u : '';

$__preselect_date = '';
$__preselect_time = '';
$__preselect_service_from_session = '';

if (!empty($_SESSION['preselect_date']) || !empty($_SESSION['preselect_time']) || !empty($_SESSION['preselect_service'])) {
  $__preselect_date    = (string)$_SESSION['preselect_date'] ?? '';
  $__preselect_time    = (string)$_SESSION['preselect_time'] ?? '';
  $__preselect_service_from_session = strtoupper(trim((string)$_SESSION['preselect_service'] ?? ''));
  unset($_SESSION['preselect_date'], $_SESSION['preselect_time'], $_SESSION['preselect_service']);
}
if ($__preselect_date === '' && isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date'])) {
  $__preselect_date = (string)$_GET['date'];
}
if ($__preselect_time === '' && isset($_GET['time'])) {
  $__preselect_time = trim((string)$_GET['time']);
}

/* Dedication / Wedding gate — fetch baptism_verification */
$__baptism_verification = '';
if ($isLoggedIn && isset($_SESSION['individual_id'])) {
  if ($stmt = $db_connection->prepare("SELECT baptism_verification FROM individual_table WHERE individual_id = ? LIMIT 1")) {
    $stmt->bind_param('i', $_SESSION['individual_id']);
    if ($stmt->execute()) {
      $res = $stmt->get_result();
      if ($res && ($row = $res->fetch_assoc())) {
        $__baptism_verification = (string)($row['baptism_verification'] ?? '');
      }
    }
    $stmt->close();
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>HTCCC - Appointment Form</title>
<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Expose basics to JS -->
<script>
  const IS_LOGGED_IN = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
  const INDIVIDUAL_ID = <?php echo isset($_SESSION['individual_id']) ? json_encode((int)$_SESSION['individual_id']) : 'null'; ?>;
  const PRESELECT_SERVICE = <?php echo json_encode($__preselect_service, JSON_UNESCAPED_UNICODE); ?>;
  const PRESELECT_DATE    = <?php echo json_encode($__preselect_date); ?>;
  const PRESELECT_TIME    = <?php echo json_encode($__preselect_time); ?>;
  const PRESELECT_SERVICE_FROM_SESSION = <?php echo json_encode($__preselect_service_from_session); ?>;
  const BAPTISM_VERIF_STATUS = <?php echo json_encode($__baptism_verification); ?>;
</script>

<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" />
<!-- Bootstrap -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Page CSS -->
<link rel="stylesheet" href="/HTCCC-SYSTEM/appoint-page.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/appoint-page.css'); ?>">

<style>
  .btn[disabled]{ cursor:not-allowed; opacity:.75; }

  /* Time Picker modal */
  .tp-backdrop{ position:fixed; inset:0; background:rgba(0,0,0,.35); display:none; align-items:center; justify-content:center; z-index:1000; }
  .tp-backdrop.open{ display:flex; }
  .tp-modal{ background:#0f1430; color:#fff; border-radius:18px; padding:18px; min-width:320px; box-shadow:0 10px 30px rgba(0,0,0,.4); }
  .tp-header{ display:flex; align-items:center; justify-content:space-between; background:#1b2353; padding:10px 14px; border-radius:12px; font-weight:700; margin-bottom:14px; }
  .tp-close{ background:#2c346b; color:#fff; border:none; width:42px; height:42px; border-radius:12px; font-size:18px; }
  .tp-row{ display:flex; gap:12px; }
  .tp-select{ background:#11173f; color:#fff; border:1px solid #2b377e; border-radius:12px; padding:12px; flex:1; font-size:18px; }
  .tp-actions{ display:flex; gap:12px; justify-content:flex-end; margin-top:16px; }
  .tp-cancel{ background:#3a4372; border:none; color:#fff; padding:10px 16px; border-radius:12px; font-weight:600; }
  .tp-apply{ background:#8ec2ff; border:none; color:#0b1333; padding:10px 18px; border-radius:12px; font-weight:800; }

  /* Calendar cells */
  #calendarDays > div{
    position: relative;
    min-height: 76px;
    padding: 6px 6px 24px; /* reserved space for badge */
    overflow: hidden;
    border-radius: 6px;
    text-align:center;
  }

  .status-badge{
    position:absolute;
    left:6px;
    right:6px;
    bottom:5px;
    text-align:center;
    font-size:10px;
    font-weight:800;
    padding:4px 6px;
    border-radius:6px;
    line-height:1;
    background:#20306b;
    color:#fff;
    z-index:5;
    display:block;
    pointer-events:none;
  }
  .status-badge.available{ background:#1c7c3b; }
  .status-badge.occupied{ background:#b32d3f; }
  .status-badge.unavailable{ background:#6b6b6b; }
  .status-badge.full{ background:#9b1b6b; }

  .calendar-days div.selected{ outline:2px solid #5da9ff; border-radius:6px; }

  .calendar-days div.reserved-full{
    background:#1b4fad !important;
    color:#ffffff !important;
    border-radius:8px;
    display:flex;
    flex-direction:column;
    justify-content:center;
    align-items:center;
    font-weight:700;
  }
  .calendar-days div.reserved-full .day-number{ font-size:14px; margin-bottom:3px; }
  .calendar-days div.reserved-full .reserved-label{
    font-size:9px;
    text-transform:uppercase;
    letter-spacing:.08em;
    opacity:.95;
  }
  .calendar-days div.reserved-full,
  .calendar-days div.reserved-full *{
    border:none !important;
    text-decoration:none !important;
  }
</style>
</head>
<body class="bg-white">
<header class="mb-3">
  <a href="main-page.php" style="display:inline-block; z-index:10;" class="ms-2">
    <img src="image/btn-back.png" alt="Back" class="img-fluid" style="width:30px; height:30px; cursor:pointer; display:block;">
  </a>
  <div class="logo-title text-center w-100 d-flex justify-content-center align-items-center">
    <img src="image/httc_main-logo.jpg" alt="Circular logo with blue and white colors" class="img-fluid" />
    <span class="ms-1 text-uppercase">HOLY TRINITY CHRISTIAN COMMUNITY CHURCH</span>
  </div>
  <div style="width:96px; height:24px;"></div>
</header>

<main class="py-3">
  <div class="container">
    <form method="POST">
      <div class="row g-4 align-items-start">
        <div class="col-12">
          <h1 class="appointment-txt mb-4">BOOK A SERVICE</h1>
        </div>

        <div style="display:flex; gap:2rem; align-items:flex-start; flex-wrap:wrap;" class="col-12 d-lg-flex">
          <!-- LEFT: Service + Time -->
          <section class="right-side w-100" style="flex:1; max-width:400px; margin-bottom:2rem;">
            <div style="margin-bottom:2rem;">
              <h2>Step 1. Select your desired Service <span style="color:red;">*</span></h2>
              <div class="dropdown-container" id="serviceDropdown">
                <button class="dropdown-button btn btn-light w-100 text-start d-flex justify-content-between align-items-center" type="button" id="serviceDropdownBtn">
                  <span>SELECT</span> <i class="fas fa-chevron-down"></i>
                </button>
                <div class="dropdown-menu" id="serviceDropdownMenu">
                  <button type="button">WEDDING</button>
                  <button type="button">FUNERAL SERVICE</button>
                  <button type="button">HOUSE BLESSING</button>
                  <button type="button">DEDICATION</button>
                </div>
              </div>
              <input type="hidden" id="appointmentService" name="appointment_service" required>
            </div>

            <div style="margin-bottom:2rem;">
              <h2>Step 2. Pick Preferred Time for Service <span style="color:red;">*</span></h2>
              <div class="time-display">
                <input id="serviceTimeDisplay" type="text" placeholder="e.g., 1:30 PM" readonly aria-label="Selected time">
                <button type="button" id="openTimePicker" class="time-pick-btn">Pick</button>
              </div>
              <small class="text-muted d-block mt-1">Use 12-hour time (hh:mm AM/PM).</small>
              <input type="hidden" id="serviceTime" name="service_time" required>
            </div>
          </section>

          <!-- RIGHT: Calendar -->
          <section style="flex:1; max-width:600px;" class="w-100">
            <h2 class="step-header">Step 3. Select Available Date<span style="color: red;">*</span></h2>
            <div class="calendar" id="calendar">
              <div class="calendar-header">
                <button id="prevMonth" type="button" aria-label="Previous month">«</button>
                <div id="monthYear"></div>
                <button id="nextMonth" type="button" aria-label="Next month">»</button>
              </div>
              <div class="calendar-weekdays">
                <div>Sun</div><div>Mon</div><div>Tue</div>
                <div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
              </div>
              <div class="calendar-days" id="calendarDays" style="font-size: 10px;"></div>
            </div>

            <input type="hidden" id="serviceDate" name="service_date" />
            <button type="submit" id="proceedBtn" class="proceed-button btn btn-primary mt-2">PROCEED</button>
          </section>
        </div>
      </div>
    </form>
  </div>
</main>

<!-- TIME PICKER MODAL -->
<div class="tp-backdrop" id="tpBackdrop" aria-hidden="true">
  <div class="tp-modal" role="dialog" aria-modal="true" aria-labelledby="tpTitle">
    <div class="tp-header">
      <div id="tpTitle">Pick Service Time</div>
      <button type="button" class="tp-close" id="tpClose" aria-label="Close">✕</button>
    </div>
    <div class="tp-row">
      <select id="tpHour" class="tp-select" aria-label="Hour">
        <?php for($h=1;$h<=12;$h++): ?>
          <option value="<?php echo str_pad((string)$h,2,'0',STR_PAD_LEFT); ?>"><?php echo str_pad((string)$h,2,'0',STR_PAD_LEFT); ?></option>
        <?php endfor; ?>
      </select>
      <select id="tpMinute" class="tp-select" aria-label="Minute">
        <?php for($m=0;$m<60;$m+=1): ?>
          <option value="<?php echo str_pad((string)$m,2,'0',STR_PAD_LEFT); ?>"><?php echo str_pad((string)$m,2,'0',STR_PAD_LEFT); ?></option>
        <?php endfor; ?>
      </select>
      <select id="tpAmPm" class="tp-select" aria-label="AM or PM">
        <option>AM</option>
        <option>PM</option>
      </select>
    </div>
    <div class="tp-actions">
      <button type="button" class="tp-cancel" id="tpCancel">Cancel</button>
      <button type="button" class="tp-apply" id="tpApply">Apply</button>
    </div>
  </div>
</div>

<script>
(function() {
  const form = document.querySelector('main form');
  const calendarDays = document.getElementById('calendarDays');
  const monthYear = document.getElementById('monthYear');
  const prevMonthBtn = document.getElementById('prevMonth');
  const nextMonthBtn = document.getElementById('nextMonth');

  const serviceDate = document.getElementById('serviceDate');
  const serviceTime = document.getElementById('serviceTime');
  const serviceTimeDisplay = document.getElementById('serviceTimeDisplay');

  const appointmentService = document.getElementById('appointmentService');
  const proceedBtn = document.getElementById('proceedBtn');
  const serviceDropdownBtn = document.getElementById("serviceDropdownBtn");
  const serviceDropdownMenu = document.getElementById("serviceDropdownMenu");

  // OCCUPANCY endpoint (per service, per month)
  const AJAX_OCCUPANCY_BASE = <?php echo json_encode($_SERVER['PHP_SELF'].'?__ajax=occupancy'); ?>;
  let OCCUPANCY = {}; // { 'YYYY-MM-DD': count }

  function serviceKeyUpper() {
    return (appointmentService.value || '').trim().toUpperCase();
  }
  function maxSlotsForServiceKey(key) {
    switch (key) {
      case 'DEDICATION': return 4;
      default: return 1;
    }
  }

  function fetchOccupancyFor(serviceText, year, monthIndexZeroBased) {
    const svc = (serviceText || '').trim();
    if (!svc) { OCCUPANCY = {}; return Promise.resolve(); }
    const y = year;
    const m = monthIndexZeroBased + 1;
    const url = AJAX_OCCUPANCY_BASE
      + '&service=' + encodeURIComponent(svc)
      + '&y=' + encodeURIComponent(String(y))
      + '&m=' + encodeURIComponent(String(m));
    return fetch(url)
      .then(r => r.json())
      .then(data => {
        if (data && data.ok && data.counts && typeof data.counts === 'object') OCCUPANCY = data.counts;
        else OCCUPANCY = {};
      })
      .catch(() => { OCCUPANCY = {}; });
  }

  let selectedDate = null;
  const now = new Date();
  let currentYear = now.getFullYear();
  let currentMonth = now.getMonth();

  function daysInMonth(y, m){ return new Date(y, m + 1, 0).getDate(); }

  function isPastDate(dateStr){
    if (!dateStr) return false;
    const today = new Date(); today.setHours(0,0,0,0);
    const [Y,M,D] = dateStr.split('-').map(Number);
    const check = new Date(Y, M-1, D); check.setHours(0,0,0,0);
    return check < today;
  }

  // =========================
  // NEW: Time validation (ERROR TRAP)
  // =========================
  function todayYMD(){
    const t = new Date();
    return `${t.getFullYear()}-${String(t.getMonth()+1).padStart(2,'0')}-${String(t.getDate()).padStart(2,'0')}`;
  }
  function isToday(dateStr){ return !!dateStr && dateStr === todayYMD(); }

  // "9:05 AM" -> minutes since midnight
  function parseTime12ToMinutes(text){
    if (!text) return null;
    const s = String(text).trim().toUpperCase();
    const m = s.match(/^(\d{1,2}):(\d{2})\s*(AM|PM)$/);
    if (!m) return null;

    let hh = parseInt(m[1], 10);
    const mm = parseInt(m[2], 10);
    const ap = m[3];

    if (isNaN(hh) || isNaN(mm)) return null;
    if (hh < 1 || hh > 12 || mm < 0 || mm > 59) return null;

    if (ap === 'AM') { if (hh === 12) hh = 0; }
    else { if (hh !== 12) hh += 12; }

    return (hh * 60) + mm;
  }

  function getNowMinutes(){
    const t = new Date();
    return (t.getHours() * 60) + t.getMinutes();
  }

  // Trap invalid time ONLY when selected date is today:
  // - If chosen time is earlier than current time -> invalid
  // - "present" means equal to current minute is OK
  function isChosenTimeValidForSelectedDate(timeText){
    const d = (serviceDate?.value || selectedDate || '').trim();
    if (!isToday(d)) return true; // future dates: any time ok (your rules can change later)
    const mins = parseTime12ToMinutes(timeText);
    if (mins == null) return true; // let existing required checks handle empty/format
    return mins >= getNowMinutes();
  }

  function showInvalidTimeTrap(){
    if (typeof Swal !== 'undefined') {
      Swal.fire({
        icon: 'error',
        title: 'Invalid Time',
        text: 'The time you selected is already past. Please choose the present or a future time.'
      });
    } else {
      alert('Invalid Time: The time you selected is already past. Please choose the present or a future time.');
    }
  }
  // =========================
  // END: Time validation
  // =========================

  function setDisabled(el, disabled){
    if (!el) return;
    el.disabled = !!disabled;
    el.setAttribute('aria-disabled', disabled ? 'true' : 'false');
  }

  // === STEP LOCKS:
  function updateStepLocks(){
    const svcKey = serviceKeyUpper();
    const isDedication = (svcKey === 'DEDICATION');

    if (isDedication) {
      if (serviceTime) serviceTime.value = '11:30 AM';
      if (serviceTimeDisplay) serviceTimeDisplay.value = '11:30 AM';
    }

    const hasService = !!appointmentService.value;
    const hasDate = !!serviceDate.value && !isPastDate(serviceDate.value);
    const hasTime = !!serviceTime.value;

    const tpBtn = document.getElementById('openTimePicker');

    if (tpBtn) {
      if (isDedication) setDisabled(tpBtn, true);
      else setDisabled(tpBtn, !hasService);
    }

    setDisabled(proceedBtn, !(hasService && hasDate && hasTime));
  }
  window.updateStepLocks = updateStepLocks;

  // --------- CALENDAR RENDERING ----------
  function renderCalendar(year, month){
    const names = ["January","February","March","April","May","June","July","August","September","October","November","December"];
    monthYear.textContent = names[month] + " " + year;
    calendarDays.innerHTML = '';

    const firstDay = new Date(year, month, 1).getDay();
    const total = daysInMonth(year, month);

    for (let i = 0; i < firstDay; i++) {
      const pad = document.createElement('div');
      pad.setAttribute('aria-hidden','true');
      calendarDays.appendChild(pad);
    }

    const today = new Date(); today.setHours(0,0,0,0);
    const svcKey = serviceKeyUpper();
    const hasService = !!svcKey;
    const hasTime = !!(serviceTime && serviceTime.value);

    for (let d = 1; d <= total; d++){
      const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
      const cell = document.createElement('div');

      const dayLabel = document.createElement('div');
      dayLabel.textContent = d;
      cell.appendChild(dayLabel);

      const [Y,M,D] = dateStr.split('-').map(Number);
      const dt = new Date(Y, M-1, D); dt.setHours(0,0,0,0);
      const isPast = dt < today;
      const dow = dt.getDay();
      const isSunday = (dow === 0);

      let count = Number(OCCUPANCY[dateStr] || 0);
      let badgeText = '';
      let badgeVariant = '';
      let titleText = '';
      let clickable = false;

      if (hasService && !isPast) {
        const maxSlots = maxSlotsForServiceKey(svcKey);

        if (svcKey === 'DEDICATION') {
          if (!isSunday) {
            badgeText = 'Unavailable';
            badgeVariant = 'unavailable';
            titleText = 'Dedication bookings are available on Sundays only.';
          } else if (count >= maxSlots) {
            badgeText = 'Full<br>0/' + maxSlots;
            badgeVariant = 'full';
            titleText = 'This Sunday is fully booked for Dedication.';
          } else {
            const remaining = Math.max(maxSlots - count, 0);
            badgeText = 'Available<br>' + remaining + '/' + maxSlots;
            badgeVariant = 'available';
            titleText = `Slots available for Dedication this Sunday: ${remaining} of ${maxSlots}.`;
            clickable = true;
          }
        } else if (svcKey === 'FUNERAL SERVICE' || svcKey === 'HOUSE BLESSING') {
          if (isSunday) {
            badgeText = 'Unavailable';
            badgeVariant = 'unavailable';
            titleText = `${svcKey} is not available on Sundays.`;
          } else if (count >= 1) {
            badgeText = 'Occupied';
            badgeVariant = 'occupied';
            titleText = 'This date is already occupied.';
          } else {
            badgeText = 'Available';
            badgeVariant = 'available';
            titleText = 'You can book this date.';
            clickable = true;
          }
        } else if (svcKey === 'WEDDING') {
          if (count >= 1) {
            badgeText = 'Occupied';
            badgeVariant = 'occupied';
            titleText = 'This date is already occupied.';
          } else {
            badgeText = 'Available';
            badgeVariant = 'available';
            titleText = 'You can book this date.';
            clickable = true;
          }
        }
      }

      if (isPast) {
        cell.style.background = "#ddd";
        cell.style.color = "#888";
        cell.style.pointerEvents = "none";
        cell.setAttribute('aria-disabled','true');
      } else {
        if (!hasService) {
          cell.style.background = "#f3f3f7";
          cell.style.color = "#999";
          cell.style.pointerEvents = "none";
          cell.setAttribute('aria-disabled','true');
          titleText = "Please select a service first.";
          badgeText = '';
        } else {
          if (clickable && hasTime) {
            cell.style.cursor = "pointer";
            cell.addEventListener('click', () => {
              selectedDate = dateStr;
              serviceDate.value = dateStr;
              renderCalendar(year, month);
              updateStepLocks();
            });
          } else {
            cell.style.pointerEvents = "none";
            cell.setAttribute('aria-disabled','true');
          }
        }
      }

      if (badgeText) {
        const badge = document.createElement('div');
        badge.innerHTML = badgeText;

        badge.style.position = 'absolute';
        badge.style.left = '4px';
        badge.style.right = '4px';
        badge.style.bottom = '4px';
        badge.style.padding = '4px 6px';
        badge.style.borderRadius = '6px';
        badge.style.fontSize = '10px';
        badge.style.fontWeight = '800';
        badge.style.lineHeight = '1';
        badge.style.textAlign = 'center';
        badge.style.color = '#ffffff';
        badge.style.pointerEvents = 'none';
        badge.style.zIndex = '10';

        if (badgeVariant === 'available') badge.style.backgroundColor = '#1c7c3b';
        else if (badgeVariant === 'occupied') badge.style.backgroundColor = '#b32d3f';
        else if (badgeVariant === 'unavailable') badge.style.backgroundColor = '#6b6b6b';
        else if (badgeVariant === 'full') badge.style.backgroundColor = '#9b1b6b';
        else badge.style.backgroundColor = '#20306b';

        cell.appendChild(badge);
      }

      if (selectedDate === dateStr) cell.classList.add('selected');
      if (titleText) cell.title = titleText;

      calendarDays.appendChild(cell);
    }
  }
  // --------- END CALENDAR RENDERING ----------

  function refreshCalendarForCurrentService() {
    const svcText = (appointmentService.value || '').trim();
    return fetchOccupancyFor(svcText, currentYear, currentMonth).then(() => {
      renderCalendar(currentYear, currentMonth);
      updateStepLocks();
    });
  }

  prevMonthBtn.addEventListener('click', () => {
    currentMonth--;
    if (currentMonth < 0){ currentMonth = 11; currentYear--; }
    refreshCalendarForCurrentService();
  });
  nextMonthBtn.addEventListener('click', () => {
    currentMonth++;
    if (currentMonth > 11){ currentMonth = 0; currentYear++; }
    refreshCalendarForCurrentService();
  });

  // Handle extra messaging & clearing when service changes
  function handleServiceSelection(serviceLabel) {
    const raw = (serviceLabel || '').trim();
    const svcKey = raw.toUpperCase();
    if (!svcKey) return;

    const tpBtn = document.getElementById('openTimePicker');

    if (svcKey === 'DEDICATION' && typeof Swal !== 'undefined') {
      Swal.fire({
        icon: 'info',
        title: 'Dedication Scheduling',
        html: 'Dedication services are only available on Sundays after the preaching, at <b>11:30 AM</b>.',
        confirmButtonText: 'Got it'
      });

      if (serviceTime) serviceTime.value = '11:30 AM';
      if (serviceTimeDisplay) serviceTimeDisplay.value = '11:30 AM';
      if (tpBtn) setDisabled(tpBtn, true);
    } else {
      if (serviceTime) serviceTime.value = '';
      if (serviceTimeDisplay) serviceTimeDisplay.value = '';
      if (tpBtn) setDisabled(tpBtn, !appointmentService.value);
    }

    updateStepLocks();

    if (serviceDate && serviceDate.value) {
      const parts = serviceDate.value.split('-').map(Number);
      if (parts.length === 3) {
        const dt = new Date(parts[0], parts[1] - 1, parts[2]);
        const isSunday = (dt.getDay() === 0);
        let mustClear = false;

        if (svcKey === 'DEDICATION' && !isSunday) mustClear = true;
        else if ((svcKey === 'FUNERAL SERVICE' || svcKey === 'HOUSE BLESSING') && isSunday) mustClear = true;

        if (mustClear) {
          serviceDate.value = '';
          selectedDate = null;
        }
      }
    }

    refreshCalendarForCurrentService();
  }

  // Service dropdown
  serviceDropdownBtn?.addEventListener('click', (e) => {
    e.stopPropagation();
    serviceDropdownMenu.classList.toggle("open");
    serviceDropdownBtn.classList.toggle("open");
  });
  serviceDropdownMenu?.querySelectorAll("button").forEach(btn => {
    btn.addEventListener("click", () => {
      const txt = (btn.textContent || '').trim();
      appointmentService.value = txt;
      serviceDropdownBtn.childNodes[0].textContent = txt + " ";
      serviceDropdownMenu.classList.remove("open");
      serviceDropdownBtn.classList.remove("open");

      handleServiceSelection(txt);
      refreshCalendarForCurrentService();
      updateStepLocks();
    });
  });
  document.addEventListener("click", (e) => {
    if (serviceDropdownBtn && serviceDropdownMenu && !serviceDropdownBtn.contains(e.target) && !serviceDropdownMenu.contains(e.target)) {
      serviceDropdownMenu.classList.remove("open");
      serviceDropdownBtn.classList.remove("open");
    }
  });

  // Time picker modal
  const tpBackdrop = document.getElementById('tpBackdrop');
  const tpHour = document.getElementById('tpHour');
  const tpMinute = document.getElementById('tpMinute');
  const tpAmPm = document.getElementById('tpAmPm');
  const openTimePicker = document.getElementById('openTimePicker');
  const tpApply = document.getElementById('tpApply');
  const tpCancel = document.getElementById('tpCancel');
  const tpClose = document.getElementById('tpClose');

  function openTP(){ tpBackdrop.classList.add('open'); tpBackdrop.setAttribute('aria-hidden','false'); }
  function closeTP(){ tpBackdrop.classList.remove('open'); tpBackdrop.setAttribute('aria-hidden','true'); }
  function toText(hh,mm,ap){
    hh=parseInt(hh,10); mm=parseInt(mm,10);
    if(isNaN(hh)||isNaN(mm)) return '';
    const h=(hh===0?12:hh);
    return `${String(h).padStart(2,'0')}:${String(mm).padStart(2,'0')} ${ap}`;
  }

  function applyTP(){
    const txt = toText(tpHour.value, tpMinute.value, tpAmPm.value);
    if (!txt) return;

    // ✅ ERROR TRAP: if date is today and time is past => show error and DO NOT APPLY
    if (!isChosenTimeValidForSelectedDate(txt.replace(/^0/,''))) {
      showInvalidTimeTrap();
      return;
    }

    serviceTime.value = txt.replace(/^0/,''); // "09:00 AM" -> "9:00 AM"
    serviceTimeDisplay.value = serviceTime.value;

    updateStepLocks();
    refreshCalendarForCurrentService();
    closeTP();
  }

  openTimePicker?.addEventListener('click', () => {
    if (serviceKeyUpper() === 'DEDICATION') {
      Swal.fire({
        icon: 'info',
        title: 'Fixed Time for Dedication',
        text: 'Dedication services are only available on Sundays after the preaching, at 11:30 AM.'
      });
      return;
    }

    if (!appointmentService.value){
      Swal.fire({ icon:'info', title:'Pick a Service First', text:'Please select a service before choosing a time.' });
      return;
    }

    openTP();
  });

  tpApply?.addEventListener('click', applyTP);
  tpCancel?.addEventListener('click', closeTP);
  tpClose?.addEventListener('click', closeTP);
  tpBackdrop?.addEventListener('click', (e)=>{ if(e.target===tpBackdrop) closeTP(); });

  // Submit flow
  const serviceToForm = {
    "WEDDING": "form-wedding.php",
    "FUNERAL SERVICE": "form-funeral.php",
    "HOUSE BLESSING": "form-house.php",
    "DEDICATION": "form-dedication.php"
  };
  function buildServiceUrl(serviceText, { date, time, service }) {
    const base = serviceToForm[serviceText];
    if (!base) return null;
    const q = new URLSearchParams();
    if (date)    q.set('date', date);
    if (time)    q.set('time', time);
    if (service) q.set('service', service);
    if (IS_LOGGED_IN && INDIVIDUAL_ID) q.set('individual_id', INDIVIDUAL_ID);
    return `${base}?${q.toString()}`;
  }
  function isBaptismValue(v){ v=(v||'').toUpperCase(); return v==='BAPTISM'||v==='WATER BAPTISM'; }

  form?.addEventListener('submit', function(e){
    e.preventDefault();

    if (!appointmentService.value || isBaptismValue(appointmentService.value)) {
      Swal.fire({icon:'warning',title:'Missing Service',text:'Please select a valid service.'});
      return;
    }
    if (!serviceTime.value) {
      Swal.fire({icon:'warning',title:'Missing Time',text:'Please pick a time.'});
      return;
    }
    if (!serviceDate.value) {
      Swal.fire({icon:'warning',title:'Missing Date',text:'Please select a date.'});
      return;
    }
    if (isPastDate(serviceDate.value)) {
      Swal.fire({icon:'error',title:'Invalid Date',text:'Past dates are not allowed.'});
      return;
    }

    // ✅ ERROR TRAP on submit as well (extra safety)
    if (!isChosenTimeValidForSelectedDate(serviceTime.value)) {
      showInvalidTimeTrap();
      return;
    }

    const svcKey = serviceKeyUpper();
    const countForDate = Number(OCCUPANCY[serviceDate.value] || 0);
    if ((svcKey === 'DEDICATION' && countForDate >= 4) ||
        (svcKey !== 'DEDICATION' && countForDate >= 1)) {
      const msg = (svcKey === 'DEDICATION')
        ? `Dedication on ${serviceDate.value} is fully booked (4/4). Please choose another Sunday.`
        : `${appointmentService.value} on ${serviceDate.value} is already reserved. Please choose another date.`;
      Swal.fire({icon:'error',title:'Unavailable',text: msg});
      return;
    }

    const [Y,M,D] = serviceDate.value.split('-').map(Number);
    const dt = new Date(Y, M-1, D);
    const isSunday = (dt.getDay() === 0);

    if (svcKey === 'DEDICATION' && !isSunday) {
      Swal.fire({icon:'error',title:'Invalid Date',text:'Dedication bookings are available on Sundays only.'});
      return;
    }
    if ((svcKey === 'FUNERAL SERVICE' || svcKey === 'HOUSE BLESSING') && isSunday) {
      Swal.fire({icon:'error',title:'Invalid Date',text:`${appointmentService.value} is not available on Sundays.`});
      return;
    }

    const svc = appointmentService.value.trim().toUpperCase();
    const targetUrl = buildServiceUrl(svc, {
      date: serviceDate.value, time: serviceTime.value, service: appointmentService.value
    });
    if (!targetUrl) {
      Swal.fire({icon:'error',title:'Service Not Found',text:'Selected service has no form configured.'});
      return;
    }

    Swal.fire({
      icon:'question',
      title:'Confirm Appointment',
      html:`<p><b>Date:</b> ${serviceDate.value}</p>
            <p><b>Time:</b> ${serviceTime.value}</p>
            <p><b>Service:</b> ${appointmentService.value}</p>`,
      showCancelButton:true,
      confirmButtonText:'Confirm & Continue',
      cancelButtonText:'Review'
    }).then((result)=>{
      if (!result.isConfirmed) return;

      if (!IS_LOGGED_IN) {
        Swal.fire({
          icon:'info', title:'Login Required',
          text:'You must be logged in to proceed.',
          confirmButtonText:'Go to Login', showCancelButton:true, cancelButtonText:'Cancel'
        }).then((res)=>{
          if (res.isConfirmed) {
            try {
              const payload = JSON.stringify({date: serviceDate.value, time: serviceTime.value, service: appointmentService.value});
              const url = <?php echo json_encode($_SERVER['PHP_SELF'].'?__ajax=remember'); ?>;
              if (navigator.sendBeacon) {
                const blob = new Blob([payload], {type:'application/json'}); navigator.sendBeacon(url, blob);
              } else {
                fetch(url, {method:'POST', headers:{'Content-Type':'application/json'}, body:payload, keepalive:true}).catch(()=>{});
              }
            } catch(e){}
            const returnTo = encodeURIComponent(targetUrl);
            window.location.href = `all_log-in.php?return_to=${returnTo}`;
          }
        });
        return;
      }

      window.location.href = targetUrl;
    });
  });

  // Dedication + Wedding verification gate (capture-phase)
  (function(){
    function selectedServiceKey() {
      return (document.getElementById('appointmentService')?.value || '').trim().toUpperCase();
    }
    function requiresBaptismVerification() {
      const svc = selectedServiceKey();
      return svc === 'DEDICATION' || svc === 'WEDDING';
    }
    function statusUpper() { return (BAPTISM_VERIF_STATUS || '').toString().trim().toUpperCase(); }

    form?.addEventListener('submit', function(e){
      if (!requiresBaptismVerification()) return;
      if (!IS_LOGGED_IN) return;

      const st = statusUpper();
      if (st === 'VERIFIED') return;

      e.preventDefault();
      e.stopImmediatePropagation();

      const svc = selectedServiceKey();
      const svcLabel = (svc === 'WEDDING') ? 'Wedding' : 'Dedication';
      const svcLabelLower = (svc === 'WEDDING') ? 'wedding' : 'dedication';

      if (st === 'PENDING') {
        Swal.fire({
          icon:'info',
          title:'Please wait for Approval',
          text:`Wait for the Approval of Admin before booking a ${svcLabel}.`,
          confirmButtonText:'OK'
        });
        return;
      }

      Swal.fire({
        icon:'warning',
        title:'Baptismal Certificate Required',
        text:`Your baptism verification must be approved before booking a ${svcLabelLower}. Please re-upload your Baptismal Certificate.`,
        showCancelButton:true,
        confirmButtonText:'Re-Upload Now',
        cancelButtonText:'Later'
      }).then(res=>{
        if(res.isConfirmed) window.location.href='user-profile.php';
      });
    }, true);
  })();

  // Preselects
  (function applyPreselects(){
    let svcSession = (typeof PRESELECT_SERVICE_FROM_SESSION === 'string' ? PRESELECT_SERVICE_FROM_SESSION.trim() : '');
    let svcQuery   = (typeof PRESELECT_SERVICE === 'string' ? PRESELECT_SERVICE.trim() : '');
    const isBap = (v)=> (v||'').toUpperCase()==='BAPTISM'||(v||'').toUpperCase()==='WATER BAPTISM';
    if (isBap(svcSession)) svcSession = '';
    if (isBap(svcQuery)) svcQuery = '';
    const svc = svcSession || svcQuery;

    const sBtn = document.getElementById('serviceDropdownBtn');
    const sMenu = document.getElementById('serviceDropdownMenu');

    if (svc) {
      appointmentService.value = svc;
      if (sBtn) sBtn.childNodes[0].textContent = svc + ' ';
      if (sMenu) sMenu.querySelectorAll('button').forEach(b=>{
        b.classList.toggle('active', b.textContent.trim().toUpperCase()===svc.toUpperCase());
      });
      handleServiceSelection(svc);
    }

    if (typeof PRESELECT_DATE === 'string' && PRESELECT_DATE.trim() !== '') {
      serviceDate.value = PRESELECT_DATE.trim();
      selectedDate = PRESELECT_DATE.trim();
    }
    if (typeof PRESELECT_TIME === 'string' && PRESELECT_TIME.trim() !== '') {
      serviceTime.value = PRESELECT_TIME.trim();
      serviceTimeDisplay.value = PRESELECT_TIME.trim();
    }
  })();

  // Initial draw
  refreshCalendarForCurrentService();

})();
</script>

<footer style="background-color:#1B1B4B;color:white;padding:30px 40px;">
  <?php include $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/includes/footer.php'; ?>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
