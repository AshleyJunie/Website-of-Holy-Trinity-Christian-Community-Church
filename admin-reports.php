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

/* Inputs (month range + sort + status) */
$fromIn = isset($_GET['from']) ? trim($_GET['from']) : '';   // YYYY-MM
$toIn   = isset($_GET['to'])   ? trim($_GET['to'])   : '';   // YYYY-MM
$sort   = isset($_GET['sort']) ? trim($_GET['sort']) : 'date_desc';
$statusFilter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'done'; // default to done

/* New: service type + day-level date filters */
$svcKey = isset($_GET['svc']) ? strtolower(trim($_GET['svc'])) : 'all';
$allowedSvc = ['all','baptism','dedication','funeral','house','wedding'];
if (!in_array($svcKey, $allowedSvc, true)) $svcKey = 'all';

$svcLabelMap = [
  'baptism'   => 'Baptism',
  'dedication'=> 'Dedication',
  'funeral'   => 'Funeral',
  'house'     => 'House Blessing',
  'wedding'   => 'Wedding',
];

$svcLabelFilter = ($svcKey === 'all') ? 'all' : ($svcLabelMap[$svcKey] ?? 'all');

/* Day-level filter inputs (YYYY-MM-DD) */
$dateFromIn = isset($_GET['df']) ? trim($_GET['df']) : '';
$dateToIn   = isset($_GET['dt']) ? trim($_GET['dt']) : '';

$dayFromObj = null;
$dayToObj   = null;
if ($dateFromIn && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromIn)) {
  try { $dayFromObj = new DateTime($dateFromIn . ' 00:00:00'); } catch(Exception $e) {}
}
if ($dateToIn && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToIn)) {
  try { $dayToObj = new DateTime($dateToIn . ' 23:59:59'); } catch(Exception $e) {}
}

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
if ($rangeStart > $nowLast) {
  $rangeStart = clone $nowFirst;
  $errors[] = 'Start month was in the future and was adjusted to the current month.';
}
if ($rangeEnd > $nowLast) {
  $rangeEnd   = clone $nowLast;
  $errors[] = 'End month was in the future and was adjusted to the current month.';
}
if ($rangeStart > $rangeEnd){
  $t=$rangeStart; $rangeStart=$rangeEnd; $rangeEnd=$t;
  $errors[] = 'Range start was after range end. The dates were swapped.';
}

/* Apply day-level filters inside the month window (smart intersection) */
if ($dayFromObj) {
  if ($dayFromObj > $rangeEnd) {
    $rangeStart = clone $rangeEnd;
    $errors[] = '“Date from” was after the selected range; it was clamped.';
  } elseif ($dayFromObj > $rangeStart) {
    $rangeStart = clone $dayFromObj;
  }
}
if ($dayToObj) {
  if ($dayToObj < $rangeStart) {
    $rangeEnd = clone $rangeStart;
    $errors[] = '“Date to” was before the selected range; it was clamped.';
  } elseif ($dayToObj < $rangeEnd) {
    $rangeEnd = clone $dayToObj;
  }
}

/* Final safety swap */
if ($rangeStart > $rangeEnd){
  $t=$rangeStart; $rangeStart=$rangeEnd; $rangeEnd=$t;
}

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
$exprMap['done'] = "LOWER(service_status) = 'done'";

if (!isset($exprMap[$statusFilter])) $statusFilter = 'done';
$STATUS_EXPR = $exprMap[$statusFilter];

/* ---------- IMPORTANT CHANGE: treat 'Scheduled' KPI as 'Done' counts (legacy) ---------- */
$SCHEDULED_EXPR = $exprMap['done'];       // legacy “Total Scheduled” = Done
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

/* Helper: which tables are active based on svcKey */
function svc_table_allowed(string $svcKey, string $tableName): bool {
  if ($svcKey === 'all') return true;
  $map = [
    'baptism'   => 'service_baptism',
    'dedication'=> 'service_dedication',
    'funeral'   => 'service_funeral',
    'house'     => 'service_house',
    'wedding'   => 'service_wedding',
  ];
  return isset($map[$svcKey]) && $map[$svcKey] === $tableName;
}

/* ---------- KPIs (by status, honoring service filter) ---------- */
$totalScheduled = $totalPending = $totalCancelled = 0;
foreach ($tables as $t => $dateExpr) {
  if (!svc_table_allowed($svcKey, $t)) continue;

  $totalScheduled += scalar($db_connection, "SELECT COUNT(*) FROM $t WHERE $dateExpr BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $SCHEDULED_EXPR");
  $totalPending   += scalar($db_connection, "SELECT COUNT(*) FROM $t WHERE $dateExpr BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $PENDING_EXPR");
  $totalCancelled += scalar($db_connection, "SELECT COUNT(*) FROM $t WHERE $dateExpr BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $CANCELLED_EXPR");
}

/* CHANGED: add real Scheduled count, and expose Done separately */
$totalDone = $totalScheduled; // legacy -> now clearly “Done”
$totalScheduledReal = 0;
foreach ($tables as $t => $dateExpr) {
  if (!svc_table_allowed($svcKey, $t)) continue;
  $totalScheduledReal += scalar($db_connection, "SELECT COUNT(*) FROM $t WHERE $dateExpr BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND {$exprMap['scheduled']}");
}

/* ---------- Unified list with STATUS filter (table-level WHERE) ---------- */
$COL = "utf8mb4_unicode_ci";

/* Build UNION manually (clear + fast), then filter by service type in outer query */
$listSqlInner = [];

/* Baptism */
if (svc_table_allowed($svcKey, 'service_baptism')) {
  $listSqlInner[] = "
    SELECT 
      'Baptism' COLLATE $COL AS service_type,
      CAST(
        COALESCE(
          NULLIF(
            TRIM(
              CONCAT(
                COALESCE(guardian_lastname, ''),
                CASE 
                  WHEN guardian_lastname IS NOT NULL AND guardian_lastname <> '' THEN ', '
                  ELSE ''
                END,
                COALESCE(guardian_firstname, ''),
                CASE 
                  WHEN guardian_middlename IS NOT NULL AND guardian_middlename <> '' THEN CONCAT(' ', guardian_middlename)
                  ELSE ''
                END,
                CASE 
                  WHEN guardian_ext IS NOT NULL AND guardian_ext <> '' THEN CONCAT(' ', guardian_ext)
                  ELSE ''
                END
              )
            ),
            ''
          ),
          CONCAT('Baptism #', individual_id)
        ) AS CHAR
      ) COLLATE $COL AS display_name,
      COALESCE(service_date, appointment_date) AS display_date,
      CAST(service_status AS CHAR) COLLATE $COL AS display_status
    FROM service_baptism
    WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
  ";
}

/* Dedication */
if (svc_table_allowed($svcKey, 'service_dedication')) {
  $listSqlInner[] = "
    SELECT 
      'Dedication' COLLATE $COL AS service_type,
      CAST(
        TRIM(
          CONCAT(
            COALESCE(child_lastname, ''),
            CASE 
              WHEN child_lastname IS NOT NULL AND child_lastname <> '' THEN ', '
              ELSE ''
            END,
            COALESCE(child_firstname, ''),
            CASE 
              WHEN child_middlename IS NOT NULL AND child_middlename <> '' THEN CONCAT(' ', child_middlename)
              ELSE ''
            END,
            CASE 
              WHEN child_ext IS NOT NULL AND child_ext <> '' THEN CONCAT(' ', child_ext)
              ELSE ''
            END
          )
        ) AS CHAR
      ) COLLATE $COL AS display_name,
      COALESCE(service_date, appointment_date) AS display_date,
      CAST(service_status AS CHAR) COLLATE $COL AS display_status
    FROM service_dedication
    WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
  ";
}

/* Funeral */
if (svc_table_allowed($svcKey, 'service_funeral')) {
  $listSqlInner[] = "
    SELECT 
      'Funeral' COLLATE $COL AS service_type,
      CAST(
        TRIM(
          CONCAT(
            COALESCE(deceased_lastname, ''),
            CASE 
              WHEN deceased_lastname IS NOT NULL AND deceased_lastname <> '' THEN ', '
              ELSE ''
            END,
            COALESCE(deceased_firstname, ''),
            CASE 
              WHEN deceased_middlename IS NOT NULL AND deceased_middlename <> '' THEN CONCAT(' ', deceased_middlename)
              ELSE ''
            END,
            CASE 
              WHEN deceased_ext IS NOT NULL AND deceased_ext <> '' THEN CONCAT(' ', deceased_ext)
              ELSE ''
            END
          )
        ) AS CHAR
      ) COLLATE $COL AS display_name,
      COALESCE(service_date, funeral_date, appointment_date) AS display_date,
      CAST(service_status AS CHAR) COLLATE $COL AS display_status
    FROM service_funeral
    WHERE COALESCE(service_date, funeral_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
  ";
}

/* House Blessing */
if (svc_table_allowed($svcKey, 'service_house')) {
  $listSqlInner[] = "
    SELECT 
      'House Blessing' COLLATE $COL AS service_type,
      CAST(
        TRIM(
          CONCAT(
            COALESCE(owner_lastname, ''),
            CASE 
              WHEN owner_lastname IS NOT NULL AND owner_lastname <> '' THEN ', '
              ELSE ''
            END,
            COALESCE(owner_firstname, ''),
            CASE 
              WHEN owner_middlename IS NOT NULL AND owner_middlename <> '' THEN CONCAT(' ', owner_middlename)
              ELSE ''
            END,
            CASE 
              WHEN owner_ext IS NOT NULL AND owner_ext <> '' THEN CONCAT(' ', owner_ext)
              ELSE ''
            END
          )
        ) AS CHAR
      ) COLLATE $COL AS display_name,
      COALESCE(service_date, appointment_date) AS display_date,
      CAST(service_status AS CHAR) COLLATE $COL AS display_status
    FROM service_house
    WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
  ";
}

/* Wedding */
if (svc_table_allowed($svcKey, 'service_wedding')) {
  $listSqlInner[] = "
    SELECT 
      'Wedding' COLLATE $COL AS service_type,
      CAST(
        NULLIF(
          TRIM(
            CONCAT(
              /* Groom full name */
              TRIM(
                CONCAT(
                  COALESCE(groom_lastname, ''),
                  CASE 
                    WHEN groom_lastname IS NOT NULL AND groom_lastname <> '' THEN ', '
                    ELSE ''
                  END,
                  COALESCE(groom_firstname, ''),
                  CASE 
                    WHEN groom_middlename IS NOT NULL AND groom_middlename <> '' THEN CONCAT(' ', groom_middlename)
                    ELSE ''
                  END,
                  CASE 
                    WHEN groom_extension IS NOT NULL AND groom_extension <> '' THEN CONCAT(' ', groom_extension)
                    ELSE ''
                  END
                )
              ),
              ' & ',
              /* Bride full name */
              TRIM(
                CONCAT(
                  COALESCE(bride_lastname, ''),
                  CASE 
                    WHEN bride_lastname IS NOT NULL AND bride_lastname <> '' THEN ', '
                    ELSE ''
                  END,
                  COALESCE(bride_firstname, ''),
                  CASE 
                    WHEN bride_middlename IS NOT NULL AND bride_middlename <> '' THEN CONCAT(' ', bride_middlename)
                    ELSE ''
                  END,
                  CASE 
                    WHEN bride_extension IS NOT NULL AND bride_extension <> '' THEN CONCAT(' ', bride_extension)
                    ELSE ''
                  END
                )
              )
            )
          ),
          ''
        ) AS CHAR
      ) COLLATE $COL AS display_name,
      COALESCE(service_date, appointment_date) AS display_date,
      CAST(service_status AS CHAR) COLLATE $COL AS display_status
    FROM service_wedding
    WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
  ";
}

$listSql = "SELECT * FROM ( " . implode(" UNION ALL ", $listSqlInner) . " ) u ";

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

/* ---------- Chart prep (monthly counts) ---------- */
$chartUnion = [];

if (svc_table_allowed($svcKey, 'service_baptism')) {
  $chartUnion[] = "
    SELECT COALESCE(service_date, appointment_date) AS d
      FROM service_baptism
      WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
  ";
}
if (svc_table_allowed($svcKey, 'service_dedication')) {
  $chartUnion[] = "
    SELECT COALESCE(service_date, appointment_date)
      FROM service_dedication
      WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
  ";
}
if (svc_table_allowed($svcKey, 'service_funeral')) {
  $chartUnion[] = "
    SELECT COALESCE(service_date, funeral_date, appointment_date)
      FROM service_funeral
      WHERE COALESCE(service_date, funeral_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
  ";
}
if (svc_table_allowed($svcKey, 'service_house')) {
  $chartUnion[] = "
    SELECT COALESCE(service_date, appointment_date)
      FROM service_house
      WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
  ";
}
if (svc_table_allowed($svcKey, 'service_wedding')) {
  $chartUnion[] = "
    SELECT COALESCE(service_date, appointment_date)
      FROM service_wedding
      WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
  ";
}

$chartSql = "
  SELECT DATE_FORMAT(d,'%Y-%m-01') AS month_key, COUNT(*) AS cnt
  FROM (
    " . implode(" UNION ALL ", $chartUnion) . "
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
$it = (clone $rangeStart)->modify('first day of this month');
$end = (clone $rangeEnd)->modify('first day of next month');
while ($it < $end) {
  $labels[] = $it->format('M Y');
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

/* ===================== SERVICE-TYPE CHART (PHP) ===================== */
$serviceTypesAll = ['Baptism','Dedication','Funeral','House Blessing','Wedding'];
if ($svcLabelFilter === 'all') {
  $serviceTypes = $serviceTypesAll;
} else {
  $serviceTypes = [$svcLabelFilter];
}

/* build month index map for quick fill */
$labelToIdx = [];
$it2 = (clone $rangeStart)->modify('first day of this month');
$idx = 0;
while ($it2 < (clone $rangeEnd)->modify('first day of next month')) {
  $labelToIdx[$it2->format('Y-m-01')] = $idx++;
  $it2->modify('+1 month');
}

/* init series arrays filled with zeros */
$svcSeries = [];
foreach ($serviceTypes as $st) {
  $svcSeries[$st] = array_fill(0, count($labels), 0);
}

/* UNION with service type tag + month_key (respecting service filter) */
$bySvcSub = [];

if (svc_table_allowed($svcKey, 'service_baptism')) {
  $bySvcSub[] = "
    SELECT 'Baptism' AS svc, COALESCE(service_date, appointment_date) AS d
      FROM service_baptism
      WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
  ";
}
if (svc_table_allowed($svcKey, 'service_dedication')) {
  $bySvcSub[] = "
    SELECT 'Dedication' AS svc, COALESCE(service_date, appointment_date) AS d
      FROM service_dedication
      WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
  ";
}
if (svc_table_allowed($svcKey, 'service_funeral')) {
  $bySvcSub[] = "
    SELECT 'Funeral' AS svc, COALESCE(service_date, funeral_date, appointment_date) AS d
      FROM service_funeral
      WHERE COALESCE(service_date, funeral_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
  ";
}
if (svc_table_allowed($svcKey, 'service_house')) {
  $bySvcSub[] = "
    SELECT 'House Blessing' AS svc, COALESCE(service_date, appointment_date) AS d
      FROM service_house
      WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
  ";
}
if (svc_table_allowed($svcKey, 'service_wedding')) {
  $bySvcSub[] = "
    SELECT 'Wedding' AS svc, COALESCE(service_date, appointment_date) AS d
      FROM service_wedding
      WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql' AND $STATUS_EXPR
  ";
}

$bySvcSql = "
  SELECT svc, DATE_FORMAT(d,'%Y-%m-01') AS month_key, COUNT(*) AS cnt
  FROM (
    " . implode(" UNION ALL ", $bySvcSub) . "
  ) t
  GROUP BY svc, month_key
  ORDER BY month_key
";
$bySvcRes = mysqli_query($db_connection, $bySvcSql);
if ($bySvcRes) {
  while ($r = mysqli_fetch_assoc($bySvcRes)) {
    $svc = $r['svc'];
    $mk  = $r['month_key'];
    $cnt = (int)$r['cnt'];
    if (isset($labelToIdx[$mk]) && isset($svcSeries[$svc])) {
      $svcSeries[$svc][$labelToIdx[$mk]] = $cnt;
    }
  }
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

  <style>
    /* Advanced filters UI polish + alignment */
    .local-filter-toggle {
      margin: 1.25rem 0 .5rem;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      padding: .35rem .95rem;
      border-radius: 999px;
      border: 1px solid #cbd5e1;
      background: #f8fafc;
      color: #0f172a;
      font-size: .8rem;
      text-transform: uppercase;
      letter-spacing: .035em;
      transition: background .15s ease, color .15s ease, box-shadow .15s ease, transform .05s ease;
    }
    .local-filter-toggle::before {
      content: '⚙';
      font-size: .9rem;
    }
    .local-filter-toggle:hover {
      background: #e0edff;
      color: #1f3ab2;
      box-shadow: 0 4px 10px rgba(15,23,42,.12);
      transform: translateY(-1px);
    }

    .local-filter-panel {
      display: none;
      border: none;
      background: #f9fafb;
      border-radius: 1rem;
      padding: 1rem 1.25rem 1rem;
      margin: .5rem 0 1.25rem;
      box-shadow: 0 10px 25px rgba(15,23,42,.08);
    }
    .local-filter-panel.open { display:block; }

    .lf-row {
      display:grid;
      grid-template-columns:repeat(4,minmax(0,1fr));
      gap:1rem;
      align-items:end;
      margin-top:.25rem;
    }
    .lf-field label{
      display:block;
      font-size:.8rem;
      color:#64748b;
      margin-bottom:.25rem;
      font-weight:600;
      text-transform:uppercase;
      letter-spacing:.04em;
    }
    .lf-field input[type="date"],
    .lf-field select{
      width:80%;
      height:40px;
      border:1px solid #cbd5e1;
      border-radius:.75rem;
      padding:.25rem .75rem;
      background:#fff;
      color:#0f172a;
      font-size:.9rem;
      outline:none;
      transition:border-color .15s ease, box-shadow .15s ease;
    }
    .lf-field input[type="date"]:focus,
    .lf-field select:focus{
      border-color:#2563eb;
      box-shadow:0 0 0 1px rgba(37,99,235,.35);
    }

    .lf-footer{
      display:flex;
      justify-content:space-between;
      align-items:center;
      margin-top:.75rem;
      gap:.75rem;
    }

    .lf-actions{
      display:flex;
      gap:.5rem;
      justify-content:flex-end;
      align-items:center;
    }
    .lf-btn{
      height:40px;
      padding:0 1.1rem;
      border-radius:.75rem;
      border:1px solid transparent;
      cursor:pointer;
      font-weight:600;
      font-size:.9rem;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:90px;
    }
    .lf-btn.apply{
      background:#1d4ed8;
      color:#fff;
      border-color:#1d4ed8;
    }
    .lf-btn.apply:hover{
      background:#1e40af;
      border-color:#1e40af;
    }
    .lf-btn.clear{
      background:#ffffff;
      color:#1e3a8a;
      border-color:#cbd5e1;
    }
    .lf-btn.clear:hover{
      background:#eef2ff;
    }

    .lf-meta{
      font-size:.8rem;
      color:#94a3b8;
      font-style:italic;
      white-space:nowrap;
    }

    @media (max-width:860px){
      .lf-row{grid-template-columns:1fr 1fr}
      .lf-footer{flex-direction:column-reverse;align-items:flex-end;}
    }
    @media (max-width:520px){
      .lf-row{grid-template-columns:1fr}
      .lf-footer{align-items:stretch;}
      .lf-actions{width:100%;justify-content:stretch}
      .lf-btn{flex:1 1 auto}
    }
  </style>
</head>

<body>
  <main>
    <header class="site-header">
      <a href="secretary_dashboard.php" class="back-btn" aria-label="Go back">
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

      <!-- Filters (month-level, always affect everything) -->
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
        <!-- preserve advanced filters when using month-level form -->
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
        <input type="hidden" name="svc" value="<?php echo htmlspecialchars($svcKey); ?>">
        <input type="hidden" name="df" value="<?php echo htmlspecialchars($dateFromIn); ?>">
        <input type="hidden" name="dt" value="<?php echo htmlspecialchars($dateToIn); ?>">
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
            <?php if ($svcLabelFilter !== 'all'): ?>
              &middot; Service: <?php echo htmlspecialchars($svcLabelFilter); ?>
            <?php endif; ?>
          </span>
        </div>

        <div class="block-header" style="margin-top:.75rem;">
          <h2 class="subtitle">Reservations by Service Type</h2>
          <span class="block-sub">
            Breakdown per month (<?php echo htmlspecialchars($statusFilter); ?>)
            <?php if ($svcLabelFilter !== 'all'): ?>
              &middot; Service: <?php echo htmlspecialchars($svcLabelFilter); ?>
            <?php endif; ?>
          </span>
        </div>
        <div class="chart-container chart-container--by-service">
          <canvas id="reservationsByServiceChart"></canvas>
        </div>

        <!-- KPI Cards -->
        <div class="stats">
          <!-- DONE -->
          <a class="stat-box primary <?php echo $statusFilter==='done'?'active':''; ?>"
             href="<?php echo build_qs(['status'=>'done']); ?>">
            <span class="stat-label">Done Services</span>
            <span class="stat-number"><?php echo number_format($totalDone); ?></span>
          </a>

          <!-- SCHEDULED -->
          <a class="stat-box <?php echo $statusFilter==='scheduled'?'active':''; ?>"
             href="<?php echo build_qs(['status'=>'scheduled']); ?>">
            <span class="stat-label">Scheduled Services</span>
            <span class="stat-number"><?php echo number_format($totalScheduledReal); ?></span>
          </a>

          <!-- CANCELLED -->
          <a class="stat-box <?php echo $statusFilter==='cancelled'?'active':''; ?>"
             href="<?php echo build_qs(['status'=>'cancelled']); ?>">
            <span class="stat-label">Cancelled Services</span>
            <span class="stat-number"><?php echo number_format($totalCancelled); ?></span>
          </a>
        </div>

        <!-- Advanced (global) filters -->
        <button type="button"
                id="localFilterToggle"
                class="local-filter-toggle"
                aria-expanded="false"
                aria-controls="localFilterPanel">
          Advanced table filters
        </button>

        <div id="localFilterPanel" class="local-filter-panel" aria-hidden="true">
          <div class="lf-row">
            <div class="lf-field">
              <label for="lf-from">Date from</label>
              <input type="date" id="lf-from" autocomplete="off"
                     value="<?php echo htmlspecialchars($dateFromIn); ?>">
            </div>
            <div class="lf-field">
              <label for="lf-to">Date to</label>
              <input type="date" id="lf-to" autocomplete="off"
                     value="<?php echo htmlspecialchars($dateToIn); ?>">
            </div>
            <div class="lf-field">
              <label for="lf-service">Service type</label>
              <select id="lf-service">
                <option value="all"      <?php if($svcKey==='all')      echo 'selected'; ?>>All</option>
                <option value="baptism"  <?php if($svcKey==='baptism')  echo 'selected'; ?>>Baptism</option>
                <option value="dedication"<?php if($svcKey==='dedication') echo 'selected'; ?>>Dedication</option>
                <option value="funeral"  <?php if($svcKey==='funeral')  echo 'selected'; ?>>Funeral</option>
                <option value="house"    <?php if($svcKey==='house')    echo 'selected'; ?>>House Blessing</option>
                <option value="wedding"  <?php if($svcKey==='wedding')  echo 'selected'; ?>>Wedding</option>
              </select>
            </div>
            <div class="lf-field">
              <label for="lf-status">Status</label>
              <select id="lf-status">
                <option value="all"       <?php if($statusFilter==='all')       echo 'selected'; ?>>All</option>
                <option value="scheduled" <?php if($statusFilter==='scheduled') echo 'selected'; ?>>Scheduled</option>
                <option value="pending"   <?php if($statusFilter==='pending')   echo 'selected'; ?>>Pending</option>
                <option value="cancelled" <?php if($statusFilter==='cancelled') echo 'selected'; ?>>Cancelled</option>
                <option value="done"      <?php if($statusFilter==='done')      echo 'selected'; ?>>Done</option>
              </select>
            </div>
          </div>
          <div class="lf-footer">
            <div class="lf-meta"><span id="lf-count"><?php echo count($rows); ?></span> row(s) shown</div>
            <div class="lf-actions">
              <button type="button" class="lf-btn clear" id="lf-clear">Clear</button>
              <button type="button" class="lf-btn apply" id="lf-apply">Apply</button>
            </div>
          </div>
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
                $dateRaw  = $r['display_date'] ?? null;
                $dateDisp = $dateRaw ? date('M d, Y', strtotime($dateRaw)) : '—';
                $st = strtolower($r['display_status'] ?? '');
                $cls = $st === 'pending' ? 'status-pending'
                     : ($st === 'cancelled' ? 'status-canceled'
                     : ($st === 'scheduled' ? 'status-confirmed' : ''));
                $svcType = $r['service_type'] ?? '';
              ?>
                <tr>
                  <td><?php echo htmlspecialchars($r['display_name'] ?? ''); ?></td>
                  <td><?php echo htmlspecialchars($svcType); ?></td>
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
             href="download-report.php?from=<?php echo urlencode($formFrom); ?>
             &to=<?php echo urlencode($formTo); ?>
             &sort=<?php echo urlencode($sort); ?>
             &status=<?php echo urlencode($statusFilter); ?>
             &svc=<?php echo urlencode($svcKey); ?>
             &df=<?php echo urlencode($dateFromIn); ?>
             &dt=<?php echo urlencode($dateToIn); ?>"
             target="_blank">
            Download PDF
          </a>
        </div>
      </div>

    </section>
  </main>

  <!-- BY-SERVICE CHART -->
  <script>
    (function () {
      const svcSeries = <?php echo json_encode($svcSeries); ?>;
      const labelsSvc = <?php echo json_encode($labels); ?>;
      const svcFilterLabel = <?php echo json_encode($svcLabelFilter); ?>;

      const COLOR_MAP = {
        'Baptism': '#2563eb',
        'Dedication': '#16a34a',
        'Funeral': '#64748b',
        'House Blessing': '#ea580c',
        'Wedding': '#9333ea'
      };

      const allValues = Object.values(svcSeries).reduce((a, v) => a.concat(v), []);
      const hardMax = Math.max(0, ...(allValues.length ? allValues : [0]));
      const paddedMax = hardMax + 5;

      const datasetNames = Object.keys(svcSeries).filter(name =>
        svcFilterLabel === 'all' ? true : name === svcFilterLabel
      );

      const datasets = datasetNames.map(name => ({
        label: name,
        data: svcSeries[name] || [],
        backgroundColor: COLOR_MAP[name] || '#94a3b8',
        borderColor: COLOR_MAP[name] || '#94a3b8',
        borderWidth: 1,
        categoryPercentage: 0.7,
        barPercentage: 0.85
      }));

      const canvas = document.getElementById('reservationsByServiceChart');
      if (!canvas) return;
      const existing = Chart.getChart(canvas);
      if (existing) existing.destroy();

      const ctx = canvas.getContext('2d');
      new Chart(ctx, {
        type: 'bar',
        data: { labels: labelsSvc, datasets },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { display: true, position: 'bottom' },
            tooltip: {
              mode: 'index',
              intersect: false,
              callbacks: {
                footer: (items) => {
                  const sum = items.reduce((s, i) => s + i.parsed.y, 0);
                  return 'Total: ' + sum;
                }
              }
            }
          },
          scales: {
            x: { stacked: false, grid: { color: 'rgba(148,163,184,.25)' }, ticks: { color: '#475569' } },
            y: { stacked: false, beginAtZero: true, max: paddedMax, ticks: { stepSize: 1, precision: 0, color: '#475569' }, grid: { color: 'rgba(148,163,184,.25)' } }
          }
        }
      });
    })();
  </script>

  <!-- Advanced filters: drive URL (affects table + chart + PDF), smart auto-apply -->
  <script>
  (function() {
    const panel    = document.getElementById('localFilterPanel');
    const toggle   = document.getElementById('localFilterToggle');
    const inputFrom  = document.getElementById('lf-from');
    const inputTo    = document.getElementById('lf-to');
    const selectSvc  = document.getElementById('lf-service');
    const selectStat = document.getElementById('lf-status');
    const btnApply = document.getElementById('lf-apply');
    const btnClear = document.getElementById('lf-clear');

    if (!panel || !toggle) return;

    toggle.addEventListener('click', function() {
      const isOpen = panel.classList.toggle('open');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      panel.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    });

    function applyFilters() {
      const url = new URL(window.location.href);

      const df = (inputFrom.value || '').trim();
      const dt = (inputTo.value || '').trim();
      const svc = (selectSvc.value || '').trim();
      const st  = (selectStat.value || '').trim();

      if (df) url.searchParams.set('df', df); else url.searchParams.delete('df');
      if (dt) url.searchParams.set('dt', dt); else url.searchParams.delete('dt');

      if (svc && svc !== 'all') url.searchParams.set('svc', svc);
      else url.searchParams.delete('svc');

      if (st && st !== 'all') url.searchParams.set('status', st);
      else url.searchParams.delete('status');

      window.location.href = url.toString();
    }

    let debounceTimer = null;
    function applyFiltersDebounced() {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(applyFilters, 150); // small debounce for "smart" behaviour
    }

    function clearFilters() {
      const url = new URL(window.location.href);
      url.searchParams.delete('df');
      url.searchParams.delete('dt');
      url.searchParams.delete('svc');
      url.searchParams.delete('status');
      window.location.href = url.toString();
    }

    // Smart auto-apply: change events
    [inputFrom, inputTo, selectSvc, selectStat].forEach(el => {
      if (!el) return;
      el.addEventListener('change', applyFiltersDebounced);
    });

    // Buttons still work (optional)
    if (btnApply) btnApply.addEventListener('click', applyFilters);
    if (btnClear) btnClear.addEventListener('click', clearFilters);
  })();
  </script>

  <!-- Keep scroll position -->
  <script>
  (function() {
    const KEY = 'reportScrollY';
    if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; }
    function saveScroll(){ sessionStorage.setItem(KEY, String(window.scrollY || 0)); }
    document.querySelectorAll('.stats .stat-box[href], .filter-actions a, .filter-actions button[type="submit"]').forEach(el => {
      el.addEventListener('click', saveScroll, {passive: true});
    });
    window.addEventListener('beforeunload', saveScroll);
    const y = parseInt(sessionStorage.getItem(KEY) || '0', 10);
    if (!isNaN(y) && y > 0) window.scrollTo({ top: y, left: 0, behavior: 'auto' });
  })();
  </script>
</body>
</html>
