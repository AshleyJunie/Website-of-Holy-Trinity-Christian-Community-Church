<?php
require __DIR__ . '/vendor/autoload.php';
require 'db-connection.php';

use Dompdf\Dompdf;
use Dompdf\Options;

session_start();

/* -------------------- Safety / DB -------------------- */
if (!$db_connection) {
  http_response_code(500);
  die('Failed to connect to database');
}

mysqli_set_charset($db_connection, 'utf8mb4');
mysqli_query($db_connection, "SET collation_connection = 'utf8mb4_unicode_ci'");

/* -------------------- Helpers -------------------- */
function svc_table_allowed(string $svcKey, string $tableName): bool {
  if ($svcKey === 'all') return true;
  $map = [
    'baptism'    => 'service_baptism',
    'dedication' => 'service_dedication',
    'funeral'    => 'service_funeral',
    'house'      => 'service_house',
    'wedding'    => 'service_wedding',
  ];
  return isset($map[$svcKey]) && $map[$svcKey] === $tableName;
}

/* Formal name: "Lastname, Firstname Middlename" (fallback: Administrator) */
function getPreparedBy(mysqli $conn): string {
  $adminId = (int)($_SESSION['admin_id'] ?? 0);
  if ($adminId <= 0) return 'Administrator';

  // Use cached value if available
  $cached = trim((string)($_SESSION['admin_name'] ?? ''));
  if ($cached !== '') return $cached;

  $name = '';
  if ($st = @mysqli_prepare($conn, "
    SELECT admin_firstname, admin_middlename, admin_lastname
    FROM admin_table
    WHERE admin_id=? LIMIT 1
  ")) {
    mysqli_stmt_bind_param($st, "i", $adminId);
    if (@mysqli_stmt_execute($st)) {
      $rs = mysqli_stmt_get_result($st);
      if ($row = mysqli_fetch_assoc($rs)) {
        $fn = trim((string)($row['admin_firstname'] ?? ''));
        $mn = trim((string)($row['admin_middlename'] ?? ''));
        $ln = trim((string)($row['admin_lastname'] ?? ''));

        $firstMid = trim($fn . ($mn !== '' ? ' ' . $mn : ''));
        if ($ln !== '' && $firstMid !== '') $name = trim($ln . ', ' . $firstMid);
        elseif ($ln !== '') $name = $ln;
        else $name = $firstMid;
      }
    }
    mysqli_stmt_close($st);
  }

  $name = trim($name);
  if ($name === '') $name = 'Administrator';

  // Cache for next requests
  $_SESSION['admin_name'] = $name;

  return $name;
}

/* -------------------- Inputs (range + filters) -------------------- */
$fromIn = isset($_GET['from']) ? trim($_GET['from']) : ''; // YYYY-MM
$toIn   = isset($_GET['to'])   ? trim($_GET['to'])   : ''; // YYYY-MM
$sort   = isset($_GET['sort']) ? trim($_GET['sort']) : 'date_desc';

$svcKey = isset($_GET['svc']) ? strtolower(trim($_GET['svc'])) : 'all';
$statusFilter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';

$allowedSvc = ['all','baptism','dedication','funeral','house','wedding'];
if (!in_array($svcKey, $allowedSvc, true)) $svcKey = 'all';

$allowedSort = ['date_asc','date_desc','service'];
if (!in_array($sort, $allowedSort, true)) $sort = 'date_desc';

/* Status filter (kept for indicator + filtering, but not shown in table) */
$allowedStatus = ['all','done','scheduled','cancelled'];
if (!in_array($statusFilter, $allowedStatus, true)) $statusFilter = 'all';

$exprMap = [
  'done'      => "LOWER(service_status) = 'done'",
  'scheduled' => "LOWER(service_status) = 'scheduled'",
  'cancelled' => "LOWER(service_status) IN ('cancelled','canceled')",
  'all'       => "1=1",
];
$STATUS_EXPR = $exprMap[$statusFilter] ?? "1=1";

/* Formal indicator label */
$indicatorMap = [
  'done'      => 'All Done Services',
  'scheduled' => 'Scheduled Services',
  'cancelled' => 'Cancelled Services',
  'all'       => 'All Service Statuses',
];
$statusIndicatorLabel = $indicatorMap[$statusFilter] ?? 'All Service Statuses';

/* Optional day-level filters (YYYY-MM-DD) */
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

/* -------------------- Range logic (clamped to current month) -------------------- */
$errors = [];

$nowFirst = new DateTime('first day of this month 00:00:00');
$nowLast  = (clone $nowFirst)->modify('last day of this month 23:59:59');

try {
  if ($fromIn && preg_match('/^\d{4}-\d{2}$/', $fromIn)) {
    $rangeStart = new DateTime($fromIn . '-01 00:00:00');
  }
  if ($toIn && preg_match('/^\d{4}-\d{2}$/', $toIn)) {
    $tmpEnd = new DateTime($toIn . '-01 00:00:00');
    $rangeEnd = (clone $tmpEnd)->modify('last day of this month 23:59:59');
  }
} catch(Exception $e) {}

if (!isset($rangeStart) || !isset($rangeEnd)) {
  // Default: last 12 months including current
  $rangeEnd   = clone $nowLast;
  $rangeStart = (clone $nowFirst)->modify('-11 months');
}

if ($rangeStart > $nowLast) {
  $rangeStart = clone $nowFirst;
  $errors[] = 'The start month was in the future and has been adjusted to the current month.';
}
if ($rangeEnd > $nowLast) {
  $rangeEnd   = clone $nowLast;
  $errors[] = 'The end month was in the future and has been adjusted to the current month.';
}
if ($rangeStart > $rangeEnd) {
  $t=$rangeStart; $rangeStart=$rangeEnd; $rangeEnd=$t;
  $errors[] = 'The start month was later than the end month; the range was automatically corrected.';
}

if ($dayFromObj) {
  if ($dayFromObj > $rangeEnd) {
    $rangeStart = clone $rangeEnd;
    $errors[] = 'The “Date From” value exceeded the reporting range and was clamped accordingly.';
  } elseif ($dayFromObj > $rangeStart) {
    $rangeStart = clone $dayFromObj;
  }
}
if ($dayToObj) {
  if ($dayToObj < $rangeStart) {
    $rangeEnd = clone $rangeStart;
    $errors[] = 'The “Date To” value preceded the reporting range and was clamped accordingly.';
  } elseif ($dayToObj < $rangeEnd) {
    $rangeEnd = clone $dayToObj;
  }
}

if ($rangeStart > $rangeEnd) {
  $t=$rangeStart; $rangeStart=$rangeEnd; $rangeEnd=$t;
}

$rangeStartSql = $rangeStart->format('Y-m-d H:i:s');
$rangeEndSql   = $rangeEnd->format('Y-m-d H:i:s');

$formFrom = (new DateTime($rangeStart->format('Y-m-01')))->format('Y-m');
$formTo   = (new DateTime($rangeEnd->format('Y-m-01')))->format('Y-m');

$rangeHuman = $rangeStart->format('F d, Y') . ' – ' . $rangeEnd->format('F d, Y');

/* -------------------- Service label -------------------- */
$svcLabelMap = [
  'all'        => 'All Services',
  'baptism'    => 'Baptism',
  'dedication' => 'Dedication',
  'funeral'    => 'Funeral',
  'house'      => 'House Blessing',
  'wedding'    => 'Wedding',
];
$svcFilterLabel = $svcLabelMap[$svcKey] ?? 'All Services';

/* -------------------- Unified list -------------------- */
$COL = "utf8mb4_unicode_ci";
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
                CASE WHEN guardian_lastname IS NOT NULL AND guardian_lastname <> '' THEN ', ' ELSE '' END,
                COALESCE(guardian_firstname, ''),
                CASE WHEN guardian_middlename IS NOT NULL AND guardian_middlename <> '' THEN CONCAT(' ', guardian_middlename) ELSE '' END,
                CASE WHEN guardian_ext IS NOT NULL AND guardian_ext <> '' THEN CONCAT(' ', guardian_ext) ELSE '' END
              )
            ),
            ''
          ),
          CONCAT('Baptism #', individual_id)
        ) AS CHAR
      ) COLLATE $COL AS display_name,
      COALESCE(service_date, appointment_date) AS display_date
    FROM service_baptism
    WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql'
      AND $STATUS_EXPR
  ";
}

/* Dedication */
if (svc_table_allowed($svcKey, 'service_dedication')) {
  $listSqlInner[] = "
    SELECT 
      'Dedication' COLLATE $COL AS service_type,
      CAST(
        COALESCE(
          NULLIF(
            TRIM(
              CONCAT(
                COALESCE(child_lastname, ''),
                CASE WHEN child_lastname IS NOT NULL AND child_lastname <> '' THEN ', ' ELSE '' END,
                COALESCE(child_firstname, ''),
                CASE WHEN child_middlename IS NOT NULL AND child_middlename <> '' THEN CONCAT(' ', child_middlename) ELSE '' END,
                CASE WHEN child_ext IS NOT NULL AND child_ext <> '' THEN CONCAT(' ', child_ext) ELSE '' END
              )
            ),
            ''
          ),
          CONCAT('Dedication #', dedicationId)
        ) AS CHAR
      ) COLLATE $COL AS display_name,
      COALESCE(service_date, appointment_date) AS display_date
    FROM service_dedication
    WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql'
      AND $STATUS_EXPR
  ";
}

/* Funeral */
if (svc_table_allowed($svcKey, 'service_funeral')) {
  $listSqlInner[] = "
    SELECT 
      'Funeral' COLLATE $COL AS service_type,
      CAST(
        COALESCE(
          NULLIF(
            TRIM(
              CONCAT(
                COALESCE(deceased_lastname, ''),
                CASE WHEN deceased_lastname IS NOT NULL AND deceased_lastname <> '' THEN ', ' ELSE '' END,
                COALESCE(deceased_firstname, ''),
                CASE WHEN deceased_middlename IS NOT NULL AND deceased_middlename <> '' THEN CONCAT(' ', deceased_middlename) ELSE '' END,
                CASE WHEN deceased_ext IS NOT NULL AND deceased_ext <> '' THEN CONCAT(' ', deceased_ext) ELSE '' END
              )
            ),
            ''
          ),
          CONCAT('Funeral #', funeral_id)
        ) AS CHAR
      ) COLLATE $COL AS display_name,
      COALESCE(service_date, funeral_date, appointment_date) AS display_date
    FROM service_funeral
    WHERE COALESCE(service_date, funeral_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql'
      AND $STATUS_EXPR
  ";
}

/* House Blessing */
if (svc_table_allowed($svcKey, 'service_house')) {
  $listSqlInner[] = "
    SELECT 
      'House Blessing' COLLATE $COL AS service_type,
      CAST(
        COALESCE(
          NULLIF(
            TRIM(
              CONCAT(
                COALESCE(owner_lastname, ''),
                CASE WHEN owner_lastname IS NOT NULL AND owner_lastname <> '' THEN ', ' ELSE '' END,
                COALESCE(owner_firstname, ''),
                CASE WHEN owner_middlename IS NOT NULL AND owner_middlename <> '' THEN CONCAT(' ', owner_middlename) ELSE '' END,
                CASE WHEN owner_ext IS NOT NULL AND owner_ext <> '' THEN CONCAT(' ', owner_ext) ELSE '' END
              )
            ),
            ''
          ),
          CONCAT('House #', house_id)
        ) AS CHAR
      ) COLLATE $COL AS display_name,
      COALESCE(service_date, appointment_date) AS display_date
    FROM service_house
    WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql'
      AND $STATUS_EXPR
  ";
}

/* Wedding */
if (svc_table_allowed($svcKey, 'service_wedding')) {
  $listSqlInner[] = "
    SELECT 
      'Wedding' COLLATE $COL AS service_type,
      CAST(
        COALESCE(
          NULLIF(
            TRIM(
              CONCAT(
                TRIM(CONCAT(
                  COALESCE(groom_lastname, ''),
                  CASE WHEN groom_lastname IS NOT NULL AND groom_lastname <> '' THEN ', ' ELSE '' END,
                  COALESCE(groom_firstname, ''),
                  CASE WHEN groom_middlename IS NOT NULL AND groom_middlename <> '' THEN CONCAT(' ', groom_middlename) ELSE '' END,
                  CASE WHEN groom_extension IS NOT NULL AND groom_extension <> '' THEN CONCAT(' ', groom_extension) ELSE '' END
                )),
                ' & ',
                TRIM(CONCAT(
                  COALESCE(bride_lastname, ''),
                  CASE WHEN bride_lastname IS NOT NULL AND bride_lastname <> '' THEN ', ' ELSE '' END,
                  COALESCE(bride_firstname, ''),
                  CASE WHEN bride_middlename IS NOT NULL AND bride_middlename <> '' THEN CONCAT(' ', bride_middlename) ELSE '' END,
                  CASE WHEN bride_extension IS NOT NULL AND bride_extension <> '' THEN CONCAT(' ', bride_extension) ELSE '' END
                ))
              )
            ),
            ''
          ),
          CONCAT('Wedding #', wedding_id)
        ) AS CHAR
      ) COLLATE $COL AS display_name,
      COALESCE(service_date, appointment_date) AS display_date
    FROM service_wedding
    WHERE COALESCE(service_date, appointment_date) BETWEEN '$rangeStartSql' AND '$rangeEndSql'
      AND $STATUS_EXPR
  ";
}

$listSql = "SELECT * FROM ( " . implode(" UNION ALL ", $listSqlInner) . " ) u ";

$sortMap = [
  'date_asc'  => " ORDER BY u.display_date ASC, u.service_type ASC ",
  'date_desc' => " ORDER BY u.display_date DESC, u.service_type ASC ",
  'service'   => " ORDER BY u.service_type ASC, u.display_date DESC ",
];
$listSql .= $sortMap[$sort];

/* Execute list query */
$listRes = mysqli_query($db_connection, $listSql);
$rows = [];
if ($listRes && mysqli_num_rows($listRes)) {
  while ($r = mysqli_fetch_assoc($listRes)) $rows[] = $r;
}

/* -------------------- PDF HTML (formal layout) -------------------- */
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base   = $scheme . '://' . $_SERVER['HTTP_HOST'];
$logo   = $base . '/HTCCC-SYSTEM/image/httc_main-logo.jpg';

$preparedBy = getPreparedBy($db_connection);
$generated  = date('F d, Y');

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Church Summary Report</title>
<style>
  @page { margin: 18mm 14mm; }

  body {
    font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
    color: #111827;
    font-size: 12px;
  }

  .header {
    border-bottom: 2px solid #111827;
    padding-bottom: 10px;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
  }

  .brand {
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .brand img {
    width: 54px;
    height: 54px;
    border-radius: 50%;
  }

  .title {
    margin: 0;
    font-size: 18px;
    letter-spacing: 0.2px;
  }

  .meta {
    font-size: 11px;
    color: #374151;
    line-height: 1.45;
    margin-top: 3px;
  }

  .meta strong { color: #111827; }

  .section-title {
    margin: 12px 0 6px;
    font-size: 12px;
    color: #111827;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
  }

  table.data {
    width: 100%;
    border-collapse: collapse;
    border: 1px solid #d1d5db;
  }

  table.data thead th {
    background: #111827;
    color: #ffffff;
    padding: 8px;
    text-align: left;
    font-weight: 700;
    font-size: 11px;
    letter-spacing: 0.2px;
  }

  table.data tbody td {
    padding: 8px;
    border-top: 1px solid #e5e7eb;
  }

  .footer {
    margin-top: 10px;
    font-size: 10px;
    color: #6b7280;
    border-top: 1px solid #e5e7eb;
    padding-top: 8px;
    display: flex;
    justify-content: space-between;
    gap: 10px;
  }

  .notes { margin-top: 8px; font-size: 10px; color: #6b7280; }
</style>
</head>
<body>

  <div class="header">
    <div class="brand">
      <img src="<?php echo htmlspecialchars($logo); ?>" alt="Logo">
      <div>
        <h1 class="title">Church Summary Report</h1>
        <div class="meta">
          <div><strong>Reporting Period:</strong> <?php echo htmlspecialchars($rangeHuman); ?></div>
          <div><strong>Service Filter:</strong> <?php echo htmlspecialchars($svcFilterLabel); ?></div>
          <div><strong>Status Indicator:</strong> <?php echo htmlspecialchars($statusIndicatorLabel); ?></div>
          <div><strong>Date Generated:</strong> <?php echo htmlspecialchars($generated); ?></div>
          <div><strong>Prepared By:</strong> <?php echo htmlspecialchars($preparedBy); ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="section-title">Detailed Records</div>

  <table class="data">
    <thead>
      <tr>
        <th style="width:55%;">Name</th>
        <th style="width:25%;">Service Type</th>
        <th style="width:20%;">Date</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr>
          <td colspan="3" style="text-align:center; color:#6b7280; padding:14px;">
            No records were found for the selected date range and filters.
          </td>
        </tr>
      <?php else: foreach ($rows as $r):
        $dateRaw  = $r['display_date'] ?? null;
        $dateDisp = $dateRaw ? date('M d, Y', strtotime($dateRaw)) : '—';
      ?>
        <tr>
          <td><?php echo htmlspecialchars($r['display_name'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($r['service_type'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($dateDisp); ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <div class="footer">
    <div>This report is system-generated and intended for internal administrative use only.</div>
    <div>Prepared by: <?php echo htmlspecialchars($preparedBy); ?></div>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="notes">
      <strong>Notes:</strong>
      <?php foreach ($errors as $e): ?>
        &nbsp;•&nbsp;<?php echo htmlspecialchars($e); ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</body>
</html>
<?php
$html = ob_get_clean();

/* -------------------- Dompdf render -------------------- */
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$fname = 'Church_Summary_Report_' . str_replace('-', '', $formFrom) . '_to_' . str_replace('-', '', $formTo) . '.pdf';
$dompdf->stream($fname, ['Attachment' => true]);
exit;
