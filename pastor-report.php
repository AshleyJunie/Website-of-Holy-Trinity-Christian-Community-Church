<?php
// =======================================================
// Admin - Reports (Range + Sorting + Status filtering)
// Status values in DB (ENUM): Pending, Scheduled, Done, Cancelled
// =======================================================
include 'db-connection.php'; // $db_connection (MySQLi)

if (!$db_connection) {
  http_response_code(500);
  die('Failed to connect to database');
}

/* Charset/collation para solid ang UNION */
mysqli_set_charset($db_connection, 'utf8mb4');
mysqli_query($db_connection, "SET collation_connection = 'utf8mb4_unicode_ci'");

/* ---------- Helpers ---------- */
function scalar(mysqli $conn, string $sql): int {
  $res = mysqli_query($conn, $sql);
  if (!$res) return 0;
  $row = mysqli_fetch_row($res);
  return (int)($row[0] ?? 0);
}

/* Inputs */
$fromIn = isset($_GET['from']) ? trim($_GET['from']) : '';   // YYYY-MM
$toIn   = isset($_GET['to'])   ? trim($_GET['to'])   : '';   // YYYY-MM
$sort   = isset($_GET['sort']) ? trim($_GET['sort']) : 'date_desc';
$statusFilter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'scheduled'; // scheduled|pending|cancelled|all

$errors = [];

/* Prevent future months */
$nowFirst = new DateTime('first day of this month 00:00:00');
$nowLast  = (clone $nowFirst)->modify('last day of this month 23:59:59');

/* Month range parsing */
try {
  if ($fromIn && preg_match('/^\d{4}-\d{2}$/', $fromIn)) {
    $rangeStart = new DateTime($fromIn . '-01 00:00:00');
  }
  if ($toIn && preg_match('/^\d{4}-\d{2}$/', $toIn)) {
    $tmpEnd = new DateTime($toIn . '-01 00:00:00');
    $rangeEnd = (clone $tmpEnd)->modify('last day of this month 23:59:59');
  }
} catch(Exception $e) {}

/* Default to last 12 months */
if (!isset($rangeStart) || !isset($rangeEnd)) {
  $rangeEnd   = clone $nowLast;
  $rangeStart = (clone $nowFirst)->modify('-11 months');
}

/* Clamp to not exceed current month */
if ($rangeStart > $nowLast) { $rangeStart = clone $nowFirst; $errors[] = 'Start month was in the future and was adjusted to the current month.'; }
if ($rangeEnd > $nowLast)   { $rangeEnd   = clone $nowLast;  $errors[] = 'End month was in the future and was adjusted to the current month.'; }
if ($rangeStart > $rangeEnd){ $t=$rangeStart; $rangeStart=$rangeEnd; $rangeEnd=$t; $errors[] = 'Range start was after range end. The dates were swapped.'; }

/* SQL datetime strings */
$rangeStartSql = $rangeStart->format('Y-m-d H:i:s');
$rangeEndSql   = $rangeEnd->format('Y-m-d H:i:s');

/* Status expressions (DB stores proper case; we use LOWER() for safety) */
$exprMap = [
  'scheduled' => "LOWER(service_status) = 'scheduled'",
  'pending'   => "LOWER(service_status) = 'pending'",
  'cancelled' => "LOWER(service_status) = 'cancelled'",
  'all'       => "1=1",
];
if (!isset($exprMap[$statusFilter])) $statusFilter = 'scheduled';
$STATUS_EXPR = $exprMap[$statusFilter];

$SCHEDULED_EXPR = $exprMap['scheduled'];
$PENDING_EXPR   = $exprMap['pending'];
$CANCELLED_EXPR = $exprMap['cancelled'];

/* Date expressions per table */
$tables = [
  'service_baptism'    => "COALESCE(service_date, appointment_date)",
  'service_dedication' => "COALESCE(service_date, appointment_date)",
  'service_funeral'    => "COALESCE(service_date, funeral_date, appointment_date)",
  'service_house'      => "COALESCE(service_date, appointment_date)",
  'service_wedding'    => "COALESCE(service_date, appointment_date)"
];

/* ---------- KPIs (by status) ---------- */
$totalScheduled = $totalPending = $totalCancelled = 0;
foreach ($tables as $t => $dateExpr) {
  $totalScheduled += scalar($db_connection, "SELECT COUNT(*) FROM $t WHERE $dateExpr BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $SCHEDULED_EXPR");
  $totalPending   += scalar($db_connection, "SELECT COUNT(*) FROM $t WHERE $dateExpr BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $PENDING_EXPR");
  $totalCancelled += scalar($db_connection, "SELECT COUNT(*) FROM $t WHERE $dateExpr BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $CANCELLED_EXPR");
}

/* ---------- Unified list with STATUS filter (table-level WHERE) ---------- */
$COL = "utf8mb4_unicode_ci";
/* Build UNION manually (clear + fast) */
$listSql = "
  SELECT * FROM (
    SELECT 
      'Baptism' COLLATE $COL AS service_type,
      CAST(COALESCE(baptized_name, guardian_name, CONCAT('Baptism #', individual_id)) AS CHAR) COLLATE $COL AS display_name,
      COALESCE(service_date, appointment_date) AS display_date,
      CAST(service_status AS CHAR) COLLATE $COL AS display_status
    FROM service_baptism
    WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR

    UNION ALL

    SELECT 
      'Dedication' COLLATE $COL,
      CAST(child_full_name AS CHAR) COLLATE $COL,
      COALESCE(service_date, appointment_date),
      CAST(service_status AS CHAR) COLLATE $COL
    FROM service_dedication
    WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR

    UNION ALL

    SELECT 
      'Funeral' COLLATE $COL,
      CAST(deceased_name AS CHAR) COLLATE $COL,
      COALESCE(service_date, funeral_date, appointment_date),
      CAST(service_status AS CHAR) COLLATE $COL
    FROM service_funeral
    WHERE COALESCE(service_date, funeral_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR

    UNION ALL

    SELECT 
      'House Blessing' COLLATE $COL,
      CAST(owner_full_name AS CHAR) COLLATE $COL,
      COALESCE(service_date, appointment_date),
      CAST(service_status AS CHAR) COLLATE $COL
    FROM service_house
    WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR

    UNION ALL

    SELECT 
      'Wedding' COLLATE $COL,
      CAST(TRIM(CONCAT(groom_name, ' & ', bride_name)) AS CHAR) COLLATE $COL,
      COALESCE(service_date, appointment_date),
      CAST(service_status AS CHAR) COLLATE $COL
    FROM service_wedding
    WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
  ) u
";

/* Sorting */
$sortMap = [
  'date_asc'  => " ORDER BY u.display_date ASC, u.service_type ASC ",
  'date_desc' => " ORDER BY u.display_date DESC, u.service_type ASC ",
  'status'    => " ORDER BY u.display_status ASC, u.display_date DESC ",
  'service'   => " ORDER BY u.service_type ASC, u.display_date DESC "
];
$listSql .= $sortMap[$sort] ?? $sortMap['date_desc'];
$listSql .= " LIMIT 200";

$listRes = mysqli_query($db_connection, $listSql);
$rows = [];
if ($listRes && mysqli_num_rows($listRes)) {
  while ($r = mysqli_fetch_assoc($listRes)) $rows[] = $r;
}

/* ---------- Chart (monthly counts) with same STATUS filter ---------- */
$chartSql = "
  SELECT DATE_FORMAT(d,'%Y-%m-01') AS month_key, COUNT(*) AS cnt
  FROM (
    SELECT COALESCE(service_date, appointment_date) AS d
      FROM service_baptism
      WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
    UNION ALL
    SELECT COALESCE(service_date, appointment_date)
      FROM service_dedication
      WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
    UNION ALL
    SELECT COALESCE(service_date, funeral_date, appointment_date)
      FROM service_funeral
      WHERE COALESCE(service_date, funeral_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
    UNION ALL
    SELECT COALESCE(service_date, appointment_date)
      FROM service_house
      WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
    UNION ALL
    SELECT COALESCE(service_date, appointment_date)
      FROM service_wedding
      WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
  ) x
  GROUP BY month_key
  ORDER BY month_key
";
$chartRes = mysqli_query($db_connection, $chartSql);
$raw = [];
if ($chartRes) {
  while ($r = mysqli_fetch_assoc($chartRes)) $raw[$r['month_key']] = (int)$r['cnt'];
}

/* Month buckets for chart */
$labels = [];
$counts = [];
$it = (clone $rangeStart)->modify('first day of this month');
$end = (clone $rangeEnd)->modify('first day of next month');
while ($it < $end) {
  $key = $it->format('Y-m-01');
  $labels[] = $it->format('M Y');
  $counts[] = $raw[$key] ?? 0;
  $it->modify('+1 month');
}

/* Controls */
$formFrom = (new DateTime($rangeStart->format('Y-m-01')))->format('Y-m');
$formTo   = (new DateTime($rangeEnd->format('Y-m-01')))->format('Y-m');
$maxMonth = $nowFirst->format('Y-m');

/* (optional) prayer count */
$pendingPrayer = 0;

/* helper to build querystring with overrides */
function build_qs(array $overrides = []) {
  $q = $_GET;
  foreach ($overrides as $k => $v) $q[$k] = $v;
  return htmlspecialchars($_SERVER['PHP_SELF'] . '?' . http_build_query($q));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin - Reports</title>
  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/admin-reports.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/admin-reports.css'); ?>">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>

<body>
  <main>
    <header class="site-header">
      <a href="admin-dashboard.php" class="back-btn" aria-label="Go back">
        <img src="image/btn-back.png" alt="Back">
      </a>
      <div class="header-center">
        <img src="image/httc_main-logo.jpg" alt="Logo" class="logo">
        <h1 class="header-title">SUMMARY REPORT</h1>
      </div>
      <div class="header-spacer"></div>
    </header>

    <h1 class="page-title">CHURCH REPORTS</h1>

    <section class="report-page">
      <?php if (!empty($errors)): ?>
        <div class="alert alert-warning">
          <ul><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <!-- Filters -->
      <form class="filter-bar" method="get" action="">
        <div class="filter-group">
          <label for="from">From</label>
          <input type="month" id="from" name="from" value="<?php echo htmlspecialchars($formFrom); ?>" max="<?php echo htmlspecialchars($maxMonth); ?>" required>
        </div>
        <div class="filter-group">
          <label for="to">To</label>
          <input type="month" id="to" name="to" value="<?php echo htmlspecialchars($formTo); ?>" max="<?php echo htmlspecialchars($maxMonth); ?>" required>
        </div>
        <div class="filter-group">
          <label for="sort">Sort</label>
          <select id="sort" name="sort">
            <option value="date_desc" <?php if($sort==='date_desc') echo 'selected'; ?>>Date (Newest)</option>
            <option value="date_asc"  <?php if($sort==='date_asc')  echo 'selected'; ?>>Date (Oldest)</option>
            <option value="status"    <?php if($sort==='status')    echo 'selected'; ?>>Status (A→Z)</option>
            <option value="service"   <?php if($sort==='service')   echo 'selected'; ?>>Service Type (A→Z)</option>
          </select>
        </div>
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
        <div class="filter-actions">
          <button class="btn btn-primary" type="submit">Apply</button>
          <a class="btn btn-secondary" href="<?php echo strtok($_SERVER['REQUEST_URI'], '?'); ?>">Reset</a>
        </div>
      </form>

      <!-- Main block -->
      <div class="report-block">
        <div class="block-header">
          <h2 class="subtitle">Service Reservations</h2>
          <span class="block-sub">
            <?php echo htmlspecialchars($labels[0] ?? ''); ?>
            <?php if (!empty($labels)) echo ' – ' . htmlspecialchars(end($labels)); ?>
          </span>
        </div>

        <!-- Chart -->
        <div class="chart-container">
          <canvas id="reservationsChart"></canvas>
        </div>

        <!-- KPI Cards (clickable filters) -->
        <div class="stats">
          <a class="stat-box primary <?php echo $statusFilter==='scheduled'?'active':''; ?>"
             href="<?php echo build_qs(['status'=>'scheduled']); ?>">
            <span class="stat-label">Total Scheduled Services</span>
            <span class="stat-number"><?php echo number_format($totalScheduled); ?></span>
          </a>

          <a class="stat-box <?php echo $statusFilter==='pending'?'active':''; ?>"
             href="<?php echo build_qs(['status'=>'pending']); ?>">
            <span class="stat-label">Pending Services</span>
            <span class="stat-number"><?php echo number_format($totalPending); ?></span>
          </a>

          <a class="stat-box <?php echo $statusFilter==='cancelled'?'active':''; ?>"
             href="<?php echo build_qs(['status'=>'cancelled']); ?>">
            <span class="stat-label">Canceled Services</span>
            <span class="stat-number"><?php echo number_format($totalCancelled); ?></span>
          </a>
        </div>

        <!-- Table -->
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Service Type</th>
                <th>Date</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="4" class="empty">No <?php echo htmlspecialchars($statusFilter); ?> service records for selected range.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r):
                $dateDisp = $r['display_date'] ? date('M d, Y', strtotime($r['display_date'])) : '—';
                $st = strtolower($r['display_status'] ?? '');
                $cls = $st === 'pending' ? 'status-pending'
                     : ($st === 'cancelled' ? 'status-canceled'
                     : ($st === 'scheduled' ? 'status-confirmed' : ''));
              ?>
                <tr>
                  <td><?php echo htmlspecialchars($r['display_name'] ?? ''); ?></td>
                  <td><?php echo htmlspecialchars($r['service_type']); ?></td>
                  <td><?php echo htmlspecialchars($dateDisp); ?></td>
                  <td class="<?php echo $cls; ?>"><?php echo htmlspecialchars(ucfirst($st)); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Actions -->
        <div class="actions">
          <a class="btn btn-primary"
             href="download-report.php?from=<?php echo urlencode($formFrom); ?>&to=<?php echo urlencode($formTo); ?>&sort=<?php echo urlencode($sort); ?>&status=<?php echo urlencode($statusFilter); ?>"
             target="_blank">
            Download PDF
          </a>
        </div>
      </div>

      <!-- Prayer Requests -->
      <div class="report-block">
        <div class="report-row">
          <div class="report-col">
            <h3 class="block-title">Prayer Requests</h3>
            <p class="muted"><?php echo (int)$pendingPrayer; ?> pending</p>
          </div>
          <div class="report-actions">
            <button class="btn btn-secondary" type="button">Print</button>
          </div>
        </div>
      </div>
    </section>
  </main>

  <script>
    // Guard: from<=to & block future
    (function(){
      const maxMonth = '<?php echo $maxMonth; ?>';
      const from = document.getElementById('from');
      const to   = document.getElementById('to');
      [from, to].forEach(el => el.setAttribute('max', maxMonth));
      function clamp(){
        if (from.value && to.value && from.value > to.value) to.value = from.value;
        if (from.value > maxMonth) from.value = maxMonth;
        if (to.value   > maxMonth) to.value   = maxMonth;
      }
      from.addEventListener('change', clamp);
      to.addEventListener('change', clamp);
      clamp();
    })();

    // Chart.js
    const canvas = document.getElementById('reservationsChart');
    const ctx = canvas.getContext('2d');
    const gradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
    gradient.addColorStop(0, 'rgba(37,99,235,0.25)');
    gradient.addColorStop(1, 'rgba(37,99,235,0.02)');

    const labels = <?php echo json_encode($labels); ?>;
    const counts = <?php echo json_encode($counts); ?>;

    new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: 'Reservations (<?php echo htmlspecialchars($statusFilter); ?>)',
          data: counts,
          borderWidth: 3,
          tension: 0.35,
          borderColor: '#2563eb',
          pointBackgroundColor: '#2563eb',
          pointRadius: 3.5,
          pointHoverRadius: 5,
          fill: true,
          backgroundColor: gradient
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
        interaction: { mode: 'nearest', intersect: false },
        scales: {
          x: { grid: { color: 'rgba(148,163,184,.25)' }, ticks: { color: '#475569' } },
          y: { beginAtZero: true, grid: { color: 'rgba(148,163,184,.25)' }, ticks: { precision: 0, color: '#475569' } }
        }
      }
    });
  </script>
  <script>
  // --- Keep scroll position on reload / filter clicks ---
  (function() {
    const KEY = 'reportScrollY';

    // Control default browser behavior
    if ('scrollRestoration' in history) {
      history.scrollRestoration = 'manual';
    }

    // Save scroll position before navigating (KPI links / Apply / Reset)
    function saveScroll() {
      sessionStorage.setItem(KEY, String(window.scrollY || 0));
    }

    // Attach to KPI cards, Apply button, Reset link, at kahit anong link sa filters
    document.querySelectorAll('.stats .stat-box[href], .filter-actions a, .filter-actions button[type="submit"]').forEach(el => {
      el.addEventListener('click', saveScroll, {passive: true});
    });
    window.addEventListener('beforeunload', saveScroll);

    // Restore scroll position on load
    const y = parseInt(sessionStorage.getItem(KEY) || '0', 10);
    if (!isNaN(y) && y > 0) {
      window.scrollTo({ top: y, left: 0, behavior: 'auto' });
      // optional: clear once restored
      // sessionStorage.removeItem(KEY);
    }
  })();
</script>

</body>
</html>
