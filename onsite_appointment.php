<?php
// ================== PHP (top of file) ==================
session_start();

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
    case 'PRAYER':
      return 'service_prayer';
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
 * counting rows with service_status/status = 'Scheduled'.
 * Returns array like ['YYYY-MM-DD' => count, ...]
 */
function get_service_occupancy_for_month(mysqli $db, string $serviceName, int $year, int $month): array {
  $result = [];

  $table = map_service_name_to_table($serviceName);
  if (!$table) return $result;

  $schema = get_db_schema($db);
  if ($schema === '') return $result;

  $statusCol = get_service_status_column($db, $schema, $table);
  if (!$statusCol) return $result;

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

  $sql = "SELECT service_date, COUNT(*) AS cnt
          FROM `{$schema}`.`{$table}`
          WHERE service_date BETWEEN ? AND ?
            AND `{$statusCol}` = 'Scheduled'
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

/* ------------------------ JSON occupancy endpoint ------------------------ */

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

  $counts = [];
  if ($svc !== '') {
    $counts = get_service_occupancy_for_month($db_connection, $svc, $year, $month);
  }

  echo json_encode([
    'ok'      => true,
    'service' => strtoupper(trim($svc)),
    'year'    => $year,
    'month'   => $month,
    'counts'  => $counts
  ], JSON_UNESCAPED_SLASHES);
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>HTCCC - Appointment Form</title>
<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">

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
    padding: 6px 6px 24px;
    overflow: hidden;
    border-radius: 6px;
  }

  .status-badge{
    position:absolute; left:6px; right:6px; bottom:5px;
    text-align:center; font-size:10px; font-weight:800;
    padding:4px 6px; border-radius:6px; line-height:1;
    background:#20306b; color:#fff;
  }

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
  .calendar-days div.reserved-full .day-number{
    font-size:14px;
    margin-bottom:3px;
  }
  .calendar-days div.reserved-full .reserved-label{
    font-size:9px;
    text-transform:uppercase;
    letter-spacing:.08em;
    opacity:.95;
  }
  .calendar-days div.reserved-full{
    border:none !important;
    box-shadow:none !important;
  }
  .calendar-days div.reserved-full *{
    border:none !important;
    text-decoration:none !important;
  }
  .calendar-days div.reserved-full .day-number,
  .calendar-days div.reserved-full .reserved-label{
    font-weight:800;
  }

  .time-display{ display:flex; gap:12px; align-items:center; }
  .time-display input{ background:#f2f2f6; border:1px solid #ccd; border-radius:12px; padding:12px 14px; font-weight:600; flex:1; }
  .time-pick-btn{ background:#8ec2ff; border:none; color:#0b1333; border-radius:12px; padding:11px 16px; font-weight:800; }
</style>
</head>
<body class="bg-white">
<header class="mb-3">
  <a href="appointment-schedule.php" style="display:inline-block; z-index:10;" class="ms-2">
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
    <form method="POST" id="appointmentForm">
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
                  <button type="button">PRAYER</button>
                </div>
              </div>
              <input type="hidden" id="appointmentService" name="appointment_service">
            </div>

            <div style="margin-bottom:2rem;">
              <h2>Step 2. Pick Preferred Time for Service <span style="color:red;">*</span></h2>
              <div class="time-display">
                <input id="serviceTimeDisplay" type="text" placeholder="e.g., 1:30 PM" readonly aria-label="Selected time">
                <button type="button" id="openTimePicker" class="time-pick-btn">Pick</button>
              </div>
              <small class="text-muted d-block mt-1">Use 12-hour time (hh:mm AM/PM).</small>
              <input type="hidden" id="serviceTime" name="service_time">
            </div>

            <button type="submit" id="proceedBtn" class="proceed-button btn btn-primary mt-2">PROCEED</button>
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

            <!-- Hidden field for selected date -->
            <input type="hidden" id="serviceDate" name="service_date" />
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
  const form = document.getElementById('appointmentForm');

  const calendarDays = document.getElementById('calendarDays');
  const monthYear = document.getElementById('monthYear');
  const prevMonthBtn = document.getElementById('prevMonth');
  const nextMonthBtn = document.getElementById('nextMonth');

  const serviceDate = document.getElementById('serviceDate');
  const serviceTime = document.getElementById('serviceTime');
  const serviceTimeDisplay = document.getElementById('serviceTimeDisplay');

  const appointmentService = document.getElementById('appointmentService');
  const serviceDropdownBtn = document.getElementById("serviceDropdownBtn");
  const serviceDropdownMenu = document.getElementById("serviceDropdownMenu");

  // OCCUPANCY endpoint (per service, per month)
  const AJAX_OCCUPANCY_BASE = <?php echo json_encode($_SERVER['PHP_SELF'].'?__ajax=occupancy'); ?>;
  let OCCUPANCY = {}; // { 'YYYY-MM-DD': count }

  function serviceKeyUpper() {
    return (appointmentService.value || '').trim().toUpperCase();
  }
  function maxSlotsForServiceKey(key) {
    // Dedication: 4 per Sunday; others: 1 per date
    switch (key) {
      case 'DEDICATION':
        return 4;
      case 'WEDDING':
      case 'FUNERAL SERVICE':
      case 'HOUSE BLESSING':
      case 'PRAYER':
      default:
        return 1;
    }
  }

  function fetchOccupancyFor(serviceText, year, monthIndexZeroBased) {
    const svc = (serviceText || '').trim();
    if (!svc) {
      OCCUPANCY = {};
      return Promise.resolve();
    }
    const y = year;
    const m = monthIndexZeroBased + 1;
    const url = AJAX_OCCUPANCY_BASE
      + '&service=' + encodeURIComponent(svc)
      + '&y=' + encodeURIComponent(String(y))
      + '&m=' + encodeURIComponent(String(m));
    return fetch(url)
      .then(r => r.json())
      .then(data => {
        if (data && data.ok && data.counts && typeof data.counts === 'object') {
          OCCUPANCY = data.counts;
        } else {
          OCCUPANCY = {};
        }
      })
      .catch(() => {
        OCCUPANCY = {};
      });
  }

  let selectedDate = null;
  const now = new Date();
  let currentYear = now.getFullYear();
  let currentMonth = now.getMonth();

  function daysInMonth(y, m){ return new Date(y, m + 1, 0).getDate(); }
  function isPastDateObj(dt){
    const today = new Date(); today.setHours(0,0,0,0);
    dt.setHours(0,0,0,0);
    return dt < today;
  }

  function renderCalendar(year, month){
    const names = ["January","February","March","April","May","June","July","August","September","October","November","December"];
    monthYear.textContent = names[month] + " " + year;
    calendarDays.innerHTML = '';

    const firstDay = new Date(year, month, 1).getDay();
    const total = daysInMonth(year, month);

    for (let i=0; i<firstDay; i++) {
      const pad = document.createElement('div');
      pad.setAttribute('aria-hidden','true');
      calendarDays.appendChild(pad);
    }

    const svcKey = serviceKeyUpper();
    const hasService = !!svcKey;
    const maxSlots = hasService ? maxSlotsForServiceKey(svcKey) : 0;

    for (let d=1; d<=total; d++){
      const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
      const cell = document.createElement('div');
      cell.style.textAlign = "center";

      const dayLabel = document.createElement('div');
      dayLabel.textContent = d;
      cell.appendChild(dayLabel);

      const [Y,M,D] = dateStr.split('-').map(Number);
      const dt = new Date(Y, M-1, D);

      const isPast = isPastDateObj(new Date(dt.getTime()));
      const dayOfWeek = dt.getDay();
      const isSunday = (dayOfWeek === 0);

      let count = 0;
      let isFull = false;
      if (hasService) {
        count = Number(OCCUPANCY[dateStr] || 0);
        isFull = maxSlots > 0 && count >= maxSlots;
      }

      // service rules
      let allowedByService = true;
      if (hasService) {
        if (svcKey === 'DEDICATION' && !isSunday) allowedByService = false;
        if ((svcKey === 'WEDDING' || svcKey === 'FUNERAL SERVICE' || svcKey === 'HOUSE BLESSING') && isSunday) allowedByService = false;
      }

      const clickable = !isPast && hasService && !isFull && allowedByService;

      if (!clickable) {
        if (isPast) {
          cell.style.background = "#ddd";
          cell.style.color = "#888";
        } else {
          cell.style.background = "#f3f3f7";
          cell.style.color = "#999";
        }

        if (hasService && isFull && !isPast) {
          cell.classList.add('reserved-full');
          cell.innerHTML = `
            <div class="day-number">${d}</div>
            <div class="reserved-label">FULL ${count}/${maxSlots}</div>
          `;
        }

        cell.style.pointerEvents = "none";
        cell.setAttribute('aria-disabled','true');
      } else {
        cell.style.cursor = "pointer";
        cell.addEventListener('click', () => {
          selectedDate = dateStr;
          serviceDate.value = dateStr;
          renderCalendar(year, month);
        });
        if (selectedDate === dateStr) {
          cell.classList.add("selected");
        }

        if (hasService && count > 0 && maxSlots > 0) {
          const badge = document.createElement('div');
          badge.className = 'status-badge';
          badge.textContent = `${count}/${maxSlots}`;
          cell.appendChild(badge);
        }
      }

      calendarDays.appendChild(cell);
    }
  }

  function refreshCalendarForCurrentService() {
    const svcText = (appointmentService.value || '').trim();
    return fetchOccupancyFor(svcText, currentYear, currentMonth).then(() => {
      renderCalendar(currentYear, currentMonth);
    });
  }

  prevMonthBtn.addEventListener('click', () => {
    currentMonth--;
    if (currentMonth < 0){
      currentMonth = 11;
      currentYear--;
    }
    refreshCalendarForCurrentService();
  });
  nextMonthBtn.addEventListener('click', () => {
    currentMonth++;
    if (currentMonth > 11){
      currentMonth = 0;
      currentYear++;
    }
    refreshCalendarForCurrentService();
  });

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
      refreshCalendarForCurrentService();
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
    serviceTime.value = txt.replace(/^0/,''); // "09:00 AM" -> "9:00 AM"
    serviceTimeDisplay.value = serviceTime.value;
    closeTP();
  }
  openTimePicker?.addEventListener('click', () => {
    openTP();
  });
  tpApply?.addEventListener('click', applyTP);
  tpCancel?.addEventListener('click', closeTP);
  tpClose?.addEventListener('click', closeTP);
  tpBackdrop?.addEventListener('click', (e)=>{ if(e.target===tpBackdrop) closeTP(); });

  // Submit: DIRECT TO FORMS, NO LOGIN, NO SWEETALERT, NO CONFIRM
  const serviceToForm = {
    "WEDDING": "onsite-wedding.php",
    "FUNERAL SERVICE": "onsite-funeral.php",
    "HOUSE BLESSING": "onsite-house.php",
    "DEDICATION": "onsite-dedication.php",
    "PRAYER": "form-prayer.php"
  };
  function isBaptismValue(v){
    v = (v || '').toUpperCase();
    return v === 'BAPTISM' || v === 'WATER BAPTISM';
  }

  form?.addEventListener('submit', function(e){
    e.preventDefault();

    const svcRaw = (appointmentService.value || '').trim();
    const svcKey = svcRaw.toUpperCase();
    if (!svcKey || isBaptismValue(svcKey)) {
      // Walang valid service, tahimik lang.
      return;
    }

    const base = serviceToForm[svcKey];
    if (!base) return;

    const date = serviceDate.value || '';
    const time = serviceTime.value || '';

    const q = new URLSearchParams();
    q.set('service', svcRaw);
    if (date) q.set('date', date);
    if (time) q.set('time', time);

    const targetUrl = `${base}?${q.toString()}`;
    window.location.href = targetUrl;
  });

  // Initial render (no preselects)
  refreshCalendarForCurrentService();

})();
</script>

<footer style="background-color:#1B1B4B;color:white;padding:30px 40px;">
  <?php include $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/includes/footer.php'; ?>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
