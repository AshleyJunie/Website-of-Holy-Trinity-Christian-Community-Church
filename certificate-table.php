<?php

if (isset($_GET['htccc_certificate'])) {
  /* ---------- Config (edit if needed) ---------- */
  $CHURCH_SHORT    = 'HTCCC';
  $CHURCH_NAME     = 'Holy Trinity Christian Community Church (HTCCC)';
  $CHURCH_LINE1    = 'Blk 1 Lot 2, Sample Street, Sample Barangay, Sample City';
  $CHURCH_LINE2    = 'Sunday Service 10:00 AM • www.htccc.example.com • (000) 000-0000';
  $OFFICIANT_NAME  = 'Pastor Irving Demesa';
  $CITY_PLACE_TEXT = 'at HTCCC';

  /* ---------- DB bootstrap ---------- */
  $pdo = null;
  if (!isset($pdo)) { @include_once __DIR__ . '/connection.php'; }
  if (!isset($pdo) || !($pdo instanceof PDO)) {
    try {
      $pdo = new PDO(
        'mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4',
        'root','',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
      );
    } catch (Throwable $e) {
      http_response_code(500); echo 'Database connection failed.'; exit;
    }
  }

  /* ---------- Helpers ---------- */
  $type    = isset($_GET['type']) ? strtolower(trim((string)$_GET['type'])) : '';
  $id      = isset($_GET['id'])   ? (string)$_GET['id'] : '';
  $preview = isset($_GET['preview']);

  $tableMap = [
    'baptism'    => 'service_baptism',
    'dedication' => 'service_dedication',
    'house'      => 'service_house',
    'wedding'    => 'service_wedding',
    // if you add funeral later:
    // 'funeral'    => 'service_funeral',
  ];
  $table = $tableMap[$type] ?? null;
  if (!$table || $id === '') { http_response_code(400); echo 'Missing or invalid parameters.'; exit; }

  // preferred PK per table (real primary keys)
  $preferredPkMap = [
    'service_baptism'    => 'baptism_id',
    'service_dedication' => 'dedicationId',
    'service_house'      => 'house_id',      // <-- FIXED (house_id, not appointmentId)
    'service_wedding'    => 'wedding_id',
    // 'service_funeral'     => 'funeral_id', // ready if you add funeral
  ];
  $preferredPk = $preferredPkMap[$table] ?? null;

  $pick = function(array $row, array $keys, $def = null) {
    foreach ($keys as $k) {
      if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) return $row[$k];
    }
    return $def;
  };

  $ordinal = function(int $n) {
    $s = 'th'; $v = $n % 100;
    if ($v < 11 || $v > 13) {
      $m = $n % 10;
      if     ($m === 1) $s = 'st';
      elseif ($m === 2) $s = 'nd';
      elseif ($m === 3) $s = 'rd';
    }
    return $n.$s;
  };

  $parseDate = function(?string $s) {
    if (!$s) return null;
    $s = trim($s);
    $try = [
      'Y-m-d H:i A','Y-m-d h:i A','Y-m-d',
      'm/d/Y h:i A','m/d/Y',
      'd/m/Y',
      'F d, Y','M d, Y'
    ];
    foreach ($try as $fmt) {
      $dt = DateTimeImmutable::createFromFormat($fmt, $s);
      if ($dt) return $dt;
    }
    $ts = strtotime($s);
    return $ts ? (new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone(date_default_timezone_get())) : null;
  };

  $datePhrase = function($dt) use ($ordinal) {
    if (!$dt) return '';
    return 'on the '.$ordinal((int)$dt->format('j')).' day of '.$dt->format('F').' in the year our '.$dt->format('Y');
  };

  // Helper to build full name from split columns:
  // Display: Firstname Middlename Lastname Ext
  $joinNameParts = function($last, $first, $middle = null, $ext = null) {
    $parts = [];
    if ($first)  $parts[] = trim((string)$first);
    if ($middle) $parts[] = trim((string)$middle);
    if ($last)   $parts[] = trim((string)$last);
    $name = trim(implode(' ', $parts));
    if ($ext) {
      $ext = trim((string)$ext);
      if ($ext !== '') $name .= ($name ? ' ' : '') . $ext;
    }
    return $name !== '' ? $name : '—';
  };

  $recipientFrom = function($type, $row) use($pick, $joinNameParts) {
    switch ($type) {
      case 'baptism': {
        $full = $joinNameParts(
          $pick($row, ['baptized_lastname','lastname','last_name']),
          $pick($row, ['baptized_firstname','firstname','first_name']),
          $pick($row, ['baptized_middlename','middlename','middle_name','middle_initial']),
          $pick($row, ['baptized_ext','ext','extension'])
        );
        if ($full === '—') {
          $full = $pick($row, ['baptized_name','full_name','name'], '—');
        }
        return $full;
      }

      case 'dedication': {
        $childName = $joinNameParts(
          $pick($row, ['child_lastname']),
          $pick($row, ['child_firstname']),
          $pick($row, ['child_middlename']),
          $pick($row, ['child_ext'])
        );
        return $childName;
      }

      case 'house': {
        $ownerName = $joinNameParts(
          $pick($row, ['owner_lastname']),
          $pick($row, ['owner_firstname']),
          $pick($row, ['owner_middlename']),
          $pick($row, ['owner_ext'])
        );
        return $ownerName;
      }

      case 'wedding': {
        // 1) Try split name columns
        $groom = $joinNameParts(
          $pick($row, ['groom_lastname']),
          $pick($row, ['groom_firstname']),
          $pick($row, ['groom_middlename']),
          $pick($row, ['groom_extension'])
        );
        $bride = $joinNameParts(
          $pick($row, ['bride_lastname']),
          $pick($row, ['bride_firstname']),
          $pick($row, ['bride_middlename']),
          $pick($row, ['bride_extension'])
        );

        // 2) If split fields are empty, fall back to any combined fullname
        if ($groom === '—') {
          $groom = $pick($row, ['groom_fullname','groom_name','groom'], '—');
        }
        if ($bride === '—') {
          $bride = $pick($row, ['bride_fullname','bride_name','bride'], '—');
        }

        // IMPORTANT: no more default "Groom"/"Bride" placeholders.
        return trim(($groom !== '—' ? $groom : '') . ' & ' . ($bride !== '—' ? $bride : ''));
      }

      default:
        return '—';
    }
  };

  $serviceDate = function($row) use($pick, $parseDate) {
    $date = $pick($row, ['service_date','schedule_date','appointment_date','date','event_date']);
    $time = $pick($row, ['service_time','schedule_time','appointment_time','time','event_time']);
    $dt   = trim(($date ?? ' ').' '.($time ?? ''));
    return $parseDate($dt ?: ($date ?? ''));
  };

  $base64Logo = function() {
    $p = __DIR__.'/image/httc_main-logo.jpg';
    if (!is_file($p)) $p = $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/image/httc_main-logo.jpg';
    if (!is_file($p)) return null;
    $bin = @file_get_contents($p); if (!$bin) return null;
    $mime = 'image/jpeg'; $l = strtolower($p);
    if (str_ends_with($l,'.png'))  $mime = 'image/png';
    if (str_ends_with($l,'.webp')) $mime = 'image/webp';
    return 'data:'.$mime.';base64,'.base64_encode($bin);
  };

  /* ---------- Fetch row ---------- */
  $pkCandidates = ['id','ID','service_id','appointment_id','record_id'];

  $pkCandidates = array_values(array_unique(array_merge(
    array_filter([$preferredPk]),
    // include all known PK names so far (for safety)
    ['baptism_id','dedicationId','house_id','wedding_id','funeral_id'],
    $pkCandidates
  )));

  $row = null;
  if ($preferredPk) {
    try {
      $st = $pdo->prepare("SELECT * FROM `$table` WHERE `$preferredPk` = :id LIMIT 1");
      $st->execute([':id'=>$id]);
      $row = $st->fetch() ?: null;
    } catch (Throwable $e) { /* ignore */ }
  }

  if (!$row) {
    foreach ($pkCandidates as $pk) {
      try {
        $st = $pdo->prepare("SELECT * FROM `$table` WHERE `$pk` = :id LIMIT 1");
        $st->execute([':id'=>$id]);
        $r = $st->fetch();
        if ($r) { $row = $r; break; }
      } catch (Throwable $e) {}
    }
  }

  if (!$row) { http_response_code(404); echo 'Record not found.'; exit; }

  $recipient = $recipientFrom($type, $row);
  $dt        = $serviceDate($row);
  $date_line = $datePhrase($dt);
  $logo_src  = $base64Logo();

  $titleMap = [
    'baptism'    => 'CERTIFICATE OF BAPTISM',
    'dedication' => 'CERTIFICATE OF CHILD DEDICATION',
    'house'      => 'CERTIFICATE OF HOUSE BLESSING',
    'wedding'    => 'CERTIFICATE OF MARRIAGE',
    // 'funeral'    => 'CERTIFICATE OF FUNERAL SERVICE',
  ];
  $bodyMap = [
    'baptism'    => 'was baptized in the name of the Father, the Son, and the Holy Spirit',
    'dedication' => 'was dedicated to the Lord in the presence of family and church',
    'house'      => 'received a prayer of blessing and dedication unto the Lord',
    'wedding'    => 'entered into holy matrimony before God and witnesses',
    // 'funeral'    => 'was remembered and commended to the Lord with thanksgiving and hope',
  ];
  $title     = $titleMap[$type] ?? 'CERTIFICATE';
  $body_line = $bodyMap[$type] ?? 'is recognized with this certificate';

  /* ---------- HTML ---------- */
  ob_start(); ?>
  <!doctype html>
  <html>
  <head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($title) ?></title>
    <style>
      @page { margin: 40px; }
      * { box-sizing: border-box; }
      body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; color: #111; }
      .frame {
        position: relative; padding: 32px; height: 100%;
        border: 12px solid #cda434; border-radius: 18px;
        background: linear-gradient(#f7e7a7,#fef7d0) padding-box;
      }
      .frame:before, .frame:after {
        content:""; position:absolute; inset:10px;
        border:2px solid #cda434; border-radius:12px; pointer-events:none;
      }
      .top-cross { text-align:center; margin-top:4px; margin-bottom:10px; }
      .top-cross svg { width:26px; height:26px; }
      .title {
        text-align:center; font-weight:800; letter-spacing:2px;
        font-size:30px; margin:6px 0 12px;
      }
      .subtitle { text-align:center; font-size:14px; font-weight:700; margin-top:10px; }
      .recipient {
        text-align:center; font-size:50px; margin:10px 0 6px;
        font-style:italic; font-weight:600; line-height:1.1;
      }
      .rule { width:82%; height:1px; margin:6px auto 10px; background:#1f2937; }
      .line { text-align:center; font-size:14px; }
      .line strong { font-weight:800; }
      .church-line { text-align:center; margin-top:8px; font-size:14px; }
      .logo { margin:6px auto 2px; text-align:center; }
      .logo img { height:54px; }
      .para {
        margin:14px auto 0; width:85%; text-align:center;
        font-size:13px; line-height:1.5; color:#374151;
      }
      .signatures { display:table; width:100%; margin-top:40px; }
      .sig-col { display:table-cell; width:50%; padding:0 24px; vertical-align:bottom; }
      .sig-line { border-bottom:1.8px solid #1f2937; margin:0 auto 6px; height:48px; }
      .sig-name { text-align:center; font-size:13px; font-weight:700; }
      .footer { margin-top:22px; text-align:center; font-size:10.5px; color:#374151; }
      .seal {
        position:absolute; right:46px; bottom:110px;
        width:96px; height:96px; border-radius:50%;
        border:3px solid #cda434; opacity:.2;
      }
    </style>
  </head>
  <body>
    <div class="frame">
      <div class="top-cross">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path fill="#cda434" d="M10 2h4v6h6v4h-6v10h-4V12H4V8h6z"/>
        </svg>
      </div>
      <div class="title"><?= htmlspecialchars($title) ?></div>
      <div class="subtitle">This certifies that</div>
      <div class="recipient"><?= htmlspecialchars($recipient ?: ' ') ?></div>
      <div class="rule"></div>
      <div class="line"><?= htmlspecialchars($body_line) ?></div>
      <?php if ($dt): ?>
        <div class="line"><?= htmlspecialchars($date_line) ?></div>
      <?php endif; ?>
      <div class="church-line"><?= htmlspecialchars($CITY_PLACE_TEXT) ?></div>
      <div class="logo">
        <?php if ($logo_src): ?><img src="<?= $logo_src ?>" alt="HTCCC Logo"><?php endif; ?>
      </div>
      <div class="line"><strong><?= htmlspecialchars($CHURCH_NAME) ?></strong></div>
      <div class="para">
        This certificate is presented as a testimony to the grace of God and the faith of the recipient,
        in the fellowship of <?= htmlspecialchars($CHURCH_SHORT) ?>.
      </div>
      <div class="signatures">
        <div class="sig-col">
          <div class="sig-line"></div>
          <div class="sig-name">Signature</div>
        </div>
        <div class="sig-col">
          <div class="sig-line"></div>
          <div class="sig-name"><?= htmlspecialchars($OFFICIANT_NAME) ?><br>Officiating Minister</div>
        </div>
      </div>
      <div class="footer">
        <div><?= htmlspecialchars($CHURCH_LINE1) ?></div>
        <div><?= htmlspecialchars($CHURCH_LINE2) ?></div>
      </div>
      <div class="seal"></div>
    </div>
  </body>
  </html>
  <?php
  $html = ob_get_clean();

  if ($preview) {
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
  }

  require_once __DIR__ . '/vendor/autoload.php';
  $options = new Dompdf\Options();
  $options->set('isRemoteEnabled', true);
  $options->set('defaultFont', 'DejaVu Sans');
  $dompdf = new Dompdf\Dompdf($options);
  $dompdf->loadHtml($html, 'UTF-8');
  $dompdf->setPaper('A4','landscape');
  $dompdf->render();
  $filename = sprintf(
    '%s_%s_%s.pdf',
    preg_replace('/\s+/', '_', ucfirst($type)),
    preg_replace('/\s+/', '_', $recipient ?: 'Recipient'),
    date('Ymd_His')
  );
  $dompdf->stream($filename, ['Attachment'=>false]);
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1"/>
<title>Admin – Generate Certificate</title>

<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link rel="stylesheet" href="/HTCCC-SYSTEM/css/admin-ministry-women.css<?php
  $cssPath = $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/admin-ministry-women.css';
  echo file_exists($cssPath) ? ('?v=' . filemtime($cssPath)) : '';
?>">

<style>
html, body { height: 100%; margin: 0; }
body { display: flex; background: #f8fafc; }
.sidebar { flex: 0 0 280px; }
.main-spacer { flex: 1; }

.main-container { padding: 20px 24px; max-width: 1200px; margin: 0 auto; }
.h1-title { font-size: 20px; font-weight: 700; margin: 8px 0 16px 0; color: #0f172a; }
.card { background: #fff; border-radius: 14px; box-shadow: 0 6px 20px rgba(2,6,23,0.06); border: 1px solid #e5e7eb; overflow: hidden; }
.card-header { padding: 14px 16px; font-weight: 600; background: #f1f5f9; border-bottom: 1px solid #e5e7eb; color: #0f172a; }
.table-wrap { overflow: auto; }
.table { width: 100%; border-collapse: collapse; min-width: 980px; }
.table th, .table td { padding: 12px 14px; border-bottom: 1px solid #eef2f7; text-align: left; font-size: 14px; color: #0f172a; }
.table thead th { background: #f8fafc; position: sticky; top: 0; z-index: 1; }
.badge { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; padding: 4px 8px; border-radius: 999px; border: 1px solid #e5e7eb; background: #fff; color: #0f172a; }
.badge i { font-size: 12px; }
.btn { appearance: none; border: none; cursor: pointer; border-radius: 10px; padding: 8px 12px; font-size: 14px; font-weight: 600; background: #2563eb; color: #fff; transition: transform .03s ease, box-shadow .2s ease, background .2s ease; display: inline-flex; align-items: center; gap: 8px; }
.btn:hover { background: #1e40af; }
.btn:active { transform: translateY(1px); }
.util-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 16px; border-bottom: 1px solid #e5e7eb; background: #fafafa; }
.util-left { display: flex; align-items: center; gap: 8px; }
.search-input { width: 320px; max-width: 60vw; padding: 10px 12px; border-radius: 10px; border: 1px solid #d1d5db; outline: none; }
.empty-state { padding: 28px 16px; text-align: center; color: #475569; font-size: 14px; }
.meta { font-size: 12px; color: #64748b; }

.table--with-service { min-width: 980px; }
.table--with-service th:nth-child(1), .table--with-service td:nth-child(1) { width: 36%; }
.table--with-service th:nth-child(2), .table--with-service td:nth-child(2) { width: 18%; }
.table--with-service th:nth-child(3), .table--with-service td:nth-child(3) { width: 26%; white-space: nowrap; }
.table--with-service th:nth-child(4), .table--with-service td:nth-child(4) { width: 20%; text-align: right; }
.table--with-service td:nth-child(1) { font-weight: 600; }

.controls-row { display:flex; align-items:center; gap:12px; flex-wrap:wrap; padding:10px 16px; }
.chip-group { display:flex; gap:8px; background:#fff; border:1px solid #e5e7eb; padding:6px; border-radius:12px; position: relative; }
.chip { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:999px; border:1px solid #e5e7eb; background:#f8fafc; cursor:pointer; font-weight:600; font-size:13px; }
.chip i { font-size:12px; }
.chip.active { background:#111e6c; color:#fff; border-color:#111e6c; box-shadow:0 2px 6px rgba(17,30,108,.25); }
.chip:not(.active):hover { border-color:#cbd5e1; }

#dateSort, #dateSort2 { display: none !important; }

.popover { position:absolute; top:100%; left:0; margin-top:8px; background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 10px 30px rgba(2,6,23,.12); padding:12px; width: 280px; z-index: 10; display:none; }
.popover.open { display:block; }
.popover .row { display:flex; gap:8px; margin-bottom:10px; }
.popover select { flex:1; padding:8px 10px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; }
.popover .actions { display:flex; gap:8px; justify-content:flex-end; }
.popover .actions .btn { padding:8px 10px; }
.popover .actions .btn.clear { background:#0f172a; }
.date-chip { min-width: 170px; justify-content: space-between; }
.date-chip .label { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

</style>
</head>
<body>

<aside class="sidebar">
  <div class="brand">
    <img src="image/httc_main-logo.jpg" alt="" />
    <span>HTCCC SYSTEM</span>
  </div>

  <div class="user-card">
    <img src="css/image/profile.png" alt="user">
    <div>
      <div class="user-title">Secretary</div>
      <div class="user-sub">Dashboard</div>
    </div>
  </div>

  <nav class="nav">
    <div class="section-title">Main</div>
    <a class="navlink" href="secretary_dashboard.php"><i class="fas fa-home"></i>Dashboard</a>

    <div class="section-title">Online Requests</div>
    <a class="navlink" href="admin-schedule-request.php">
      <i class="fas fa-calendar-plus"></i>Schedule Requests
    </a>
    <a class="navlink" href="admin-prayer-request.php">
      <i class="fas fa-praying-hands"></i><span>Prayer Requests</span>
    </a>

    <div class="section-title">Online Applications</div>
    <a class="navlink" href="">
      <i class="fas fa-water"></i>Baptismal Applications
    </a>
    <a class="navlink" href="admin-application.php">
      <i class="fas fa-user-cog"></i>Baptismal Account Verification
    </a>
    <a class="navlink" href="application_ministry.php">
      <i class="fas fa-users"></i>Ministry Applications
    </a>

    <div class="section-title">Schedule</div>
    <a class="navlink" href="appointment-schedule.php">
      <i class="fas fa-calendar-check"></i>Service Schedule
    </a>

    <div class="section-title">All Done Services</div>
    <a class="navlink" href="done-service-wedding.php">
      <i class="fas fa-ring"></i>Wedding Service
    </a>
    <a class="navlink" href="done-service-dedication.php">
      <i class="fas fa-baby"></i>Child Dedication
    </a>
    <a class="navlink" href="done-service-funeral.php">
      <i class="fas fa-cross"></i>Funeral Service
    </a>
    <a class="navlink" href="done-service-house.php">
      <i class="fas fa-home"></i>House Blessing
    </a>
    <a class="navlink" href="done-service-baptism.php">
      <i class="fas fa-tint"></i>Water Baptism
    </a>

    <div class="section-title">Streaming</div>
    <a class="navlink" href="admin-multimedia.php">
      <i class="fas fa-broadcast-tower"></i>Streaming
    </a>

    <div class="section-title">Ministry Management</div>
    <a class="navlink" href="admin-ministry-women.php">
      <i class="fas fa-female"></i>Handmaid's of the Lord
    </a>
    <a class="navlink" href="admin-ministry-men.php">
      <i class="fas fa-male"></i>Men's Ministry
    </a>
    <a class="navlink" href="admin-ministry-music.php">
      <i class="fas fa-music"></i>Music Ministry
    </a>
    <a class="navlink" href="admin-ministry-usher.php">
      <i class="fas fa-hands-helping"></i>Usher &amp; Usherette
    </a>
    <a class="navlink" href="admin-ministry-junior.php">
      <i class="fas fa-child"></i>Junior Christ Ambassador
    </a>

    <div class="section-title">Reports</div>
    <a class="navlink" href="admin-reports.php">
      <i class="fas fa-file-alt"></i>Reports
    </a>

    <div class="section-title">Content</div>
    <a class="navlink" href="content-management_home-page.php">
      <i class="fas fa-edit"></i>Content Management
    </a>

    <div class="section-title">Certificates</div>
    <a class="navlink active" href="certificate-table.php">
      <i class="fas fa-award"></i>Generate Certificate
    </a>

    <div class="section-title">Account</div>
    <a class="navlink" href="admin-account-settings.php">
      <i class="fas fa-user-shield"></i>Account Settings
    </a>

    <div class="section-title">More</div>
    <a class="navlink logout" href="all_log-in.php">
      <i class="fas fa-sign-out-alt"></i>Log Out
    </a>
  </nav>
</aside>

<div class="main-spacer"></div>

<?php
if (!isset($pdo)) { @include_once __DIR__ . '/connection.php'; }
if (!isset($pdo) || !($pdo instanceof PDO)) {
  try {
    $pdo = new PDO(
      'mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4',
      'root', '',
      [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]
    );
  } catch (Throwable $e) { $pdo = null; }
}

function pickField(array $row, array $candidates) {
  foreach ($candidates as $k) {
    if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) return $row[$k];
  }
  return null;
}

function combineDateTime(array $row) {
  $date = pickField($row, ['service_date','schedule_date','appointment_date','date','event_date']);
  $time = pickField($row, ['service_time','schedule_time','appointment_time','time','event_time']);
  $date = $date ? trim($date) : '';
  $time = $time ? trim($time) : '';
  if ($date && $time) return $date.' '.$time;
  if ($date) return $date;
  if ($time) return $time;
  return '—';
}

function joinFullName($last, $first, $middle = null, $ext = null) {
  $parts = [];
  if ($first)  $parts[] = trim((string)$first);
  if ($middle) $parts[] = trim((string)$middle);
  if ($last)   $parts[] = trim((string)$last);
  $name = trim(implode(' ', $parts));
  if ($ext) {
    $ext = trim((string)$ext);
    if ($ext !== '') $name .= ($name ? ' ' : '') . $ext;
  }
  return $name !== '' ? $name : '—';
}

$SERVICE_DATA = [];
if ($pdo instanceof PDO) {
  $tables = [
    ['name' => 'service_baptism',   'type' => 'baptism'],
    ['name' => 'service_dedication','type' => 'dedication'],
    ['name' => 'service_house',     'type' => 'house'],
    ['name' => 'service_wedding',   'type' => 'wedding'],
    // if you want to include funeral in the table later:
    // ['name' => 'service_funeral',   'type' => 'funeral'],
  ];

  // Map type to its true primary key
  $pkPerType = [
    'baptism'   => 'baptism_id',
    'dedication'=> 'dedicationId',
    'house'     => 'house_id',
    'wedding'   => 'wedding_id',
    // 'funeral'   => 'funeral_id',
  ];

  foreach ($tables as $t) {
    try {
      $stmt = $pdo->query("SELECT * FROM `{$t['name']}`");
      while ($row = $stmt->fetch()) {

        // Prefer the real primary key for each service type
        $pkField = $pkPerType[$t['type']] ?? null;
        $id = $pkField ? pickField($row, [$pkField]) : null;

        // Fallback if for some reason PK is missing
        if ($id === null || $id === '') {
          $id = pickField($row, [
            'id','ID','service_id','appointment_id','record_id',
            'baptism_id','dedicationId','house_id','wedding_id','funeral_id'
          ]) ?? '';
        }

        $fullname = '—';
        switch ($t['type']) {
          case 'baptism': {
            $fullname = joinFullName(
              pickField($row, ['baptized_lastname','lastname','last_name']),
              pickField($row, ['baptized_firstname','firstname','first_name']),
              pickField($row, ['baptized_middlename','middlename','middle_name','middle_initial']),
              pickField($row, ['baptized_ext','ext','extension'])
            );
            if ($fullname === '—') {
              $fullname = pickField($row, ['baptized_name','full_name','name']) ?? '—';
            }
            break;
          }

          case 'dedication': {
            $fullname = joinFullName(
              pickField($row, ['child_lastname']),
              pickField($row, ['child_firstname']),
              pickField($row, ['child_middlename']),
              pickField($row, ['child_ext'])
            );
            break;
          }

          case 'house': {
            $fullname = joinFullName(
              pickField($row, ['owner_lastname']),
              pickField($row, ['owner_firstname']),
              pickField($row, ['owner_middlename']),
              pickField($row, ['owner_ext'])
            );
            break;
          }

          case 'wedding': {
            // Same wedding logic as certificate
            $groom = joinFullName(
              pickField($row, ['groom_lastname']),
              pickField($row, ['groom_firstname']),
              pickField($row, ['groom_middlename']),
              pickField($row, ['groom_extension'])
            );
            $bride = joinFullName(
              pickField($row, ['bride_lastname']),
              pickField($row, ['bride_firstname']),
              pickField($row, ['bride_middlename']),
              pickField($row, ['bride_extension'])
            );
            if ($groom === '—') {
              $groom = pickField($row, ['groom_fullname','groom_name','groom']) ?? '—';
            }
            if ($bride === '—') {
              $bride = pickField($row, ['bride_fullname','bride_name','bride']) ?? '—';
            }
            $fullname = trim(($groom !== '—' ? $groom : '') . ' & ' . ($bride !== '—' ? $bride : ''));
            if ($fullname === '&') $fullname = '—';
            break;
          }
        }

        $datetime = combineDateTime($row);

        $SERVICE_DATA[] = [
          'type'           => $t['type'],
          'table'          => $t['name'],
          'id'             => (string)$id,
          'fullname'       => (string)$fullname,
          'datetime'       => (string)$datetime,
          'service_status' => (string)(pickField($row, ['service_status','status']) ?? ''),
        ];
      }
    } catch (Throwable $e) {
      continue;
    }
  }
}
?>
<script>
  (function () {
    const host = document.querySelector('.main-spacer');
    if (!host) return;

    const data = <?php echo json_encode($SERVICE_DATA, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;

    host.innerHTML = `
      <div class="main-container" role="region" aria-label="Certificates Table">
        <div class="h1-title">Generate Certificate</div>
        <div class="card">
          <div class="util-row">
            <div class="util-left">
              <span class="badge" title="Total rows">
                <i class="fa fa-database"></i>
                <span id="rowCount">0</span>
              </span>
              <span class="meta">Showing data from Baptism, Dedication, House Blessing, and Wedding</span>
            </div>
            <div>
              <input id="searchInput" class="search-input" type="search" placeholder="Search fullname or date/time..." aria-label="Search">
            </div>
          </div>
          <div class="card-header">All Services</div>
          <div class="table-wrap">
            <table class="table" aria-label="Services Table">
              <thead>
                <tr>
                  <th>Fullname</th>
                  <th>Service Date and Time</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="servicesTbody">
                <tr class="empty-state"><td colspan="3">No data available.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    `;

    const tbody      = host.querySelector('#servicesTbody');
    const rowCountEl = host.querySelector('#rowCount');
    const searchInput = host.querySelector('#searchInput');

    function renderRows(list) {
      tbody.innerHTML = '';
      if (!list || list.length === 0) {
        tbody.innerHTML = '<tr class="empty-state"><td colspan="3">No data found.</td></tr>';
        rowCountEl.textContent = '0';
        return;
      }
      const frag = document.createDocumentFragment();
      list.forEach((r) => {
        const tr = document.createElement('tr');

        const tdName = document.createElement('td');
        tdName.textContent = r.fullname || '—';

        const tdDT = document.createElement('td');
        tdDT.textContent = r.datetime || '—';

        const tdAct = document.createElement('td');
        const btn = document.createElement('button');
        btn.className = 'btn';
        btn.type = 'button';
        btn.innerHTML = '<i class="fa fa-certificate" aria-hidden="true"></i><span>Generate</span>';
        btn.setAttribute('aria-label', `Generate certificate for ${r.fullname || 'record'}`);
        btn.addEventListener('click', () => {
          const type = encodeURIComponent(r.type || '');
          const id   = encodeURIComponent(r.id || '');
          window.location.href = `certificate-generate.php?htccc_certificate=1&type=${type}&id=${id}`;
        });
        tdAct.appendChild(btn);

        tr.appendChild(tdName);
        tr.appendChild(tdDT);
        tr.appendChild(tdAct);
        frag.appendChild(tr);
      });
      tbody.appendChild(frag);
      rowCountEl.textContent = String(list.length);
    }

    function normalize(s) { return (s || '').toString().toLowerCase(); }

    function applySearch(list) {
      const q = normalize(searchInput.value);
      if (!q) return renderRows(list);
      const filtered = list.filter(r =>
        normalize(r.fullname).includes(q) ||
        normalize(r.datetime).includes(q)
      );
      renderRows(filtered);
    }

    renderRows(data);
    searchInput.addEventListener('input', () => applySearch(data));
  })();
</script>

<?php
$SERVICE_DATA_DONE = [];
foreach ($SERVICE_DATA as $r) {
  $status = isset($r['service_status']) ? trim($r['service_status']) : '';
  if (strcasecmp($status, 'Done') === 0) $SERVICE_DATA_DONE[] = $r;
}
?>
<script>
  (function () {
    const host = document.querySelector('.main-spacer'); if (!host) return;
    const dataDone = <?php echo json_encode($SERVICE_DATA_DONE, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;

    host.innerHTML = `
      <div class="main-container" role="region" aria-label="Certificates Table (Done Only)">
        <div class="h1-title">Generate Certificate</div>
        <div class="card">
          <div class="util-row">
            <div class="util-left">
              <span class="badge" title="Total rows (Done)">
                <i class="fa fa-database"></i>
                <span id="rowCount2">0</span>
              </span>
              <span class="meta"></span>
            </div>
            <div>
              <input id="searchInput2" class="search-input" type="search" placeholder="Search fullname or date/time..." aria-label="Search Done">
            </div>
          </div>
          <div class="card-header">All Services (Done)</div>
          <div class="table-wrap">
            <table class="table" aria-label="Services Table (Done)">
              <thead>
                <tr>
                  <th>Fullname</th>
                  <th>Service Date and Time</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="servicesTbody2">
                <tr class="empty-state"><td colspan="3">No data available.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    `;

    const tbody       = host.querySelector('#servicesTbody2');
    const rowCountEl  = host.querySelector('#rowCount2');
    const searchInput = host.querySelector('#searchInput2');

    function renderRows(list) {
      tbody.innerHTML = '';
      if (!list || list.length === 0) {
        tbody.innerHTML = '<tr class="empty-state"><td colspan="3">No data found.</td></tr>';
        rowCountEl.textContent = '0';
        return;
      }
      const frag = document.createDocumentFragment();
      list.forEach((r) => {
        const tr = document.createElement('tr');

        const tdName = document.createElement('td');
        tdName.textContent = r.fullname || '—';

        const tdDT = document.createElement('td');
        tdDT.textContent = r.datetime || '—';

        const tdAct = document.createElement('td');
        const btn = document.createElement('button');
        btn.className = 'btn'; btn.type = 'button';
        btn.innerHTML = '<i class="fa fa-certificate" aria-hidden="true"></i><span>Generate</span>';
        btn.setAttribute('aria-label', `Generate certificate for ${r.fullname || 'record'}`);
        btn.addEventListener('click', () => {
          const type = encodeURIComponent(r.type || '');
          const id   = encodeURIComponent(r.id || '');
          window.location.href = `certificate-generate.php?htccc_certificate=1&type=${type}&id=${id}`;
        });
        tdAct.appendChild(btn);

        tr.appendChild(tdName);
        tr.appendChild(tdDT);
        tr.appendChild(tdAct);
        frag.appendChild(tr);
      });
      tbody.appendChild(frag);
      rowCountEl.textContent = String(list.length);
    }

    function normalize(s) { return (s || '').toString().toLowerCase(); }
    function applySearch(list) {
      const q = normalize(searchInput.value);
      if (!q) return renderRows(list);
      const filtered = list.filter(r =>
        normalize(r.fullname).includes(q) ||
        normalize(r.datetime).includes(q)
      );
      renderRows(filtered);
    }

    renderRows(dataDone);
    searchInput.addEventListener('input', () => applySearch(dataDone));
  })();
</script>

<?php
function service_label_from_type($t) {
  $map = [
    'baptism'   => "Baptism",
    'wedding'   => "Wedding",
    'dedication'=> "Dedication",
    'house'     => "House Blessing",
    // 'funeral'   => "Funeral",
  ];
  return $map[$t] ?? ucwords((string)$t);
}

$SERVICE_DATA_DONE_LABELED = [];
foreach ($SERVICE_DATA as $r) {
  $status = isset($r['service_status']) ? trim($r['service_status']) : '';
  if (strcasecmp($status, 'Done') !== 0) continue;
  $SERVICE_DATA_DONE_LABELED[] = [
    'id'      => (string)($r['id'] ?? ''),
    'fullname'=> (string)($r['fullname'] ?? '—'),
    'datetime'=> (string)($r['datetime'] ?? '—'),
    'type'    => (string)($r['type'] ?? ''),
    'service' => service_label_from_type((string)($r['type'] ?? '')),
  ];
}
?>
<style>
  .table--with-service { min-width: 980px; }
  .table--with-service th:nth-child(1), .table--with-service td:nth-child(1) { width: 36%; }
  .table--with-service th:nth-child(2), .table--with-service td:nth-child(2) { width: 18%; }
  .table--with-service th:nth-child(3), .table--with-service td:nth-child(3) { width: 26%; white-space: nowrap; }
  .table--with-service th:nth-child(4), .table--with-service td:nth-child(4) { width: 20%; text-align: right; }
</style>
<script>
  (function(){
    const host = document.querySelector('.main-spacer'); if (!host) return;
    const RAW  = <?php echo json_encode($SERVICE_DATA_DONE_LABELED, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;

    function parseTime12h(str){
      const m = (str||'').match(/(\d{1,2}):?(\d{2})?\s*(AM|PM)/i);
      if(!m) return {h:0,min:0};
      let h=parseInt(m[1],10), min=parseInt(m[2]||'0',10), ap=m[3].toUpperCase();
      if(ap==='PM' && h<12) h+=12;
      if(ap==='AM' && h===12) h=0;
      return {h,min};
    }
    function parseDateSmart(s){
      if(!s) return new Date(0);
      const left = String(s).trim().split(' - ')[0].trim();

      const mlong = left.match(/\b([A-Za-z]+)\s+(\d{1,2}),\s*(\d{4})(?:\s+(\d{1,2}:\d{2}\s*[AP]M))?/);
      if(mlong){
        const months={january:0,february:1,march:2,april:3,may:4,june:5,july:6,august:7,september:8,october:9,november:10,december:11};
        const mon=months[mlong[1].toLowerCase()], day=parseInt(mlong[2],10), yr=parseInt(mlong[3],10);
        const t=parseTime12h(mlong[4]||'12:00 AM');
        return new Date(yr,mon,day,t.h,t.min);
      }
      const miso = left.match(/\b(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{1,2}:\d{2}\s*[AP]M))?/);
      if(miso){
        const yr=parseInt(miso[1],10), mon=parseInt(miso[2],10)-1, day=parseInt(miso[3],10);
        const t=parseTime12h(miso[4]||'12:00 AM');
        return new Date(yr,mon,day,t.h,t.min);
      }
      const mslash = left.match(/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})(?:\s+(\d{1,2}:\d{2}\s*[AP]M))?/);
      if(mslash){
        let a=parseInt(mslash[1],10), b=parseInt(mslash[2],10), y=parseInt(mslash[3],10);
        if(y<100) y+=2000;
        let month, day;
        if(a>12 && b<=12){ day=a; month=b; }
        else { month=a; day=b; }
        const t=parseTime12h(mslash[4]||'12:00 AM');
        return new Date(y,month-1,day,t.h,t.min);
      }
      const any = Date.parse(left);
      return Number.isNaN(any) ? new Date(0) : new Date(any);
    }

    const DATA = RAW.map(r => ({...r, _date: parseDateSmart(r.datetime)}));

    const SERVICE_BUTTONS = [
      {key:'ALL',            label:'All',            icon:'fa-layer-group'},
      {key:'Baptism',        label:'Baptism',        icon:'fa-baby'},
      {key:'Wedding',        label:'Wedding',        icon:'fa-ring'},
      {key:'Dedication',     label:'Dedication',     icon:'fa-child'},
      {key:'House Blessing', label:'House Blessing', icon:'fa-home'}
    ];

    function renderFrame(){
      const dates = DATA.map(d=>d._date).filter(d=>!isNaN(d));
      const minY  = dates.length ? Math.min(...dates.map(d=>d.getFullYear())) : new Date().getFullYear();
      const maxY  = dates.length ? Math.max(...dates.map(d=>d.getFullYear())) : new Date().getFullYear();

      const yearOpts = Array.from({length:(maxY-minY+1)},(_,i)=>minY+i)
        .map(y=>`<option value="${y}">${y}</option>`).join('');

      const months    = ['January','February','March','April','May','June','July','August','September','October','November','December'];
      const monthOpts = months.map((m,i)=>`<option value="${i}">${m}</option>`).join('');

      host.innerHTML = `
        <div class="main-container">
          <div class="h1-title">Generate Certificate</div>
          <div class="card">
            <div class="util-row">
              <div class="util-left">
                <span class="badge">
                  <i class="fa fa-database"></i>
                  <span id="rowCount3">${DATA.length}</span>
                </span>
                <span class="meta"></span>
              </div>
              <input id="searchInput3" class="search-input" placeholder="Smart search: name, service, or date…">
            </div>
            <div class="controls-row">
              <div class="chip-group" id="svcFilters" aria-label="Filter by Service">
                ${SERVICE_BUTTONS.map((b,i)=>`
                  <button class="chip ${i===0?'active':''}" data-svc="${b.key}">
                    <i class="fa ${b.icon}"></i> ${b.label}
                  </button>`).join('')}
              </div>

              <div class="chip-group" id="monthChooserGrp" aria-label="Filter by Month">
                <button class="chip date-chip" id="monthChooserBtn">
                  <span class="label" id="monthChooserLabel">All Dates</span>
                  <i class="fa fa-calendar-alt"></i>
                </button>
                <div class="popover" id="monthPopover">
                  <div class="row">
                    <select id="monthSelect">${monthOpts}</select>
                    <select id="yearSelect">${yearOpts}</select>
                  </div>
                  <div class="actions">
                    <button class="btn clear" id="clearMonth">Clear</button>
                    <button class="btn" id="applyMonth">Apply</button>
                  </div>
                </div>
              </div>

              <div class="chip-group" id="svcSort" aria-label="Sort by Service">
                <button class="chip" data-sort="service-asc"><i class="fa fa-font"></i> Service A–Z</button>
                <button class="chip" data-sort="service-desc"><i class="fa fa-font"></i> Service Z–A</button>
              </div>
            </div>

            <div class="card-header">All Services (Done)</div>
            <div class="table-wrap">
              <table class="table table--with-service">
                <thead>
                  <tr>
                    <th>Fullname</th>
                    <th>Service</th>
                    <th>Service Date and Time</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody id="servicesTbody3">
                  <tr class="empty-state"><td colspan="4">No data available.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>`;
    }

    function normalize(s){ return (s||'').toString().toLowerCase(); }
    const MONTHS = ['january','february','march','april','may','june','july','august','september','october','november','december'];

    function tokens(q){ return normalize(q).split(/[,\s]+/).filter(Boolean); }

    function smartSearchFilter(q, svc, rows, monthFilter){
      const tks = tokens(q);
      return rows.filter(r=>{
        if(svc!=='ALL' && r.service!==svc) return false;
        if(monthFilter){
          if(r._date.getMonth()!==monthFilter.m || r._date.getFullYear()!==monthFilter.y) return false;
        }
        if(tks.length===0) return true;
        const hay = normalize(`${r.fullname} ${r.service} ${r.datetime}`);
        return tks.every(t=>{
          if(MONTHS.includes(t)) return hay.includes(t) || MONTHS[r._date.getMonth()]===t;
          if(/^\d{4}$/.test(t))  return hay.includes(t) || String(r._date.getFullYear())===t;
          if(/^\d{1,2}$/.test(t))return hay.includes(t) || String(r._date.getDate())===t;
          return hay.includes(t);
        });
      });
    }

    let state = { svc:'ALL', sort:'date-asc', query:'', monthFilter:null };

    function sortRows(list, sortKey){
      function norm(x){ return (x||'').toString().toLowerCase(); }
      if(sortKey==='service-asc')
        return list.slice().sort((a,b)=> norm(a.service).localeCompare(norm(b.service)) || norm(a.fullname).localeCompare(norm(b.fullname)));
      if(sortKey==='service-desc')
        return list.slice().sort((a,b)=> norm(b.service).localeCompare(norm(a.service)) || norm(a.fullname).localeCompare(norm(b.fullname)));
      return list.slice().sort((a,b)=> a._date - b._date);
    }

    function renderRows(list){
      const tbody = host.querySelector('#servicesTbody3');
      tbody.innerHTML='';
      if(!list.length){
        tbody.innerHTML = '<tr class="empty-state"><td colspan="4">No data found.</td></tr>';
        return;
      }
      const frag = document.createDocumentFragment();
      list.forEach(r=>{
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${r.fullname||'—'}</td>
          <td>${r.service||'—'}</td>
          <td>${r.datetime||'—'}</td>
          <td style="text-align:right">
            <button class="btn" onclick="location.href='certificate-generate.php?htccc_certificate=1&type=${encodeURIComponent(r.type)}&id=${encodeURIComponent(r.id)}'">
              <i class="fa fa-certificate"></i> <span>Generate</span>
            </button>
          </td>`;
        frag.appendChild(tr);
      });
      tbody.appendChild(frag);
    }

    function apply(){
      const filtered = smartSearchFilter(state.query, state.svc, DATA, state.monthFilter);
      const sorted   = sortRows(filtered, state.sort);
      renderRows(sorted);
      const label = host.querySelector('#monthChooserLabel');
      label.textContent = state.monthFilter
        ? (['January','February','March','April','May','June','July','August','September','October','November','December'][state.monthFilter.m] + ' ' + state.monthFilter.y)
        : 'All Dates';
    }

    renderFrame();
    apply();

    host.addEventListener('input', e=>{
      if(e.target && e.target.id==='searchInput3'){
        state.query = e.target.value;
        apply();
      }
    });

    host.addEventListener('click', e=>{
      const btn = e.target.closest('#svcFilters .chip');
      if(btn){
        host.querySelectorAll('#svcFilters .chip').forEach(b=>b.classList.remove('active'));
        btn.classList.add('active');
        state.svc = btn.dataset.svc;
        apply();
      }
      const sbtn = e.target.closest('#svcSort [data-sort]');
      if(sbtn){
        host.querySelectorAll('#svcSort [data-sort]').forEach(b=>b.classList.remove('active'));
        sbtn.classList.add('active');
        state.sort = sbtn.dataset.sort;
        apply();
      }
    });

    const btnMonth = host.querySelector('#monthChooserBtn');
    if(btnMonth){
      const pop      = host.querySelector('#monthPopover');
      const selMonth = host.querySelector('#monthSelect');
      const selYear  = host.querySelector('#yearSelect');
      const applyBtn = host.querySelector('#applyMonth');
      const clearBtn = host.querySelector('#clearMonth');

      btnMonth.addEventListener('click', (e)=>{
        e.stopPropagation();
        pop.classList.toggle('open');
      });
      document.addEventListener('click', (e)=>{
        if(!pop.contains(e.target) && e.target!==btnMonth) pop.classList.remove('open');
      });

      applyBtn.addEventListener('click', ()=>{
        state.monthFilter = {
          m: parseInt(selMonth.value,10),
          y: parseInt(selYear.value,10)
        };
        pop.classList.remove('open');
        state.sort = 'date-asc';
        apply();
      });
      clearBtn.addEventListener('click', ()=>{
        state.monthFilter = null;
        pop.classList.remove('open');
        state.sort = 'date-asc';
        apply();
      });
    }

    const utilRow = host.querySelector('.util-row');
    if (utilRow) {
      const rightBox = document.createElement('div');
      rightBox.style.display = 'flex';
      rightBox.style.gap = '8px';
      rightBox.innerHTML = `
        <button id="manualOpenBtn" class="btn" type="button" title="Open manual generator">
          <i class="fa fa-magic"></i><span>Generate Certificate Manually</span>
        </button>
      `;
      utilRow.appendChild(rightBox);
    }

    const modal = document.createElement('div');
    modal.id = 'manualModal';
    modal.innerHTML = `
      <div class="manual-modal-backdrop" aria-hidden="true"></div>
      <div class="manual-modal" role="dialog" aria-modal="true" aria-labelledby="manualTitle">
        <div class="manual-head">
          <div id="manualTitle">Manual Certificate (System Generated)</div>
          <button type="button" class="manual-close" aria-label="Close">&times;</button>
        </div>
        <div class="manual-body">
          <div class="form-row">
            <label for="m_fullname">Fullname <span class="req">*</span></label>
            <input type="text" id="m_fullname" placeholder="Enter full name" autocomplete="off" />
          </div>
          <div class="form-row">
            <label for="m_type">Type of Service <span class="req">*</span></label>
            <select id="m_type">
              <option value="">-- Select --</option>
              <option value="baptism">Baptism</option>
              <option value="dedication">Dedication</option>
              <option value="house">House Blessing</option>
              <option value="wedding">Wedding</option>
            </select>
          </div>
          <div class="form-row">
            <label for="m_service_date">Service Date <span class="req">*</span></label>
            <input type="date" id="m_service_date" />
          </div>
        </div>
        <div class="manual-foot">
          <button type="button" class="btn" id="manualGenerate">
            <i class="fa fa-download"></i><span>Generate</span>
          </button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);

    const css = document.createElement('style');
    css.textContent = `
      .manual-modal-backdrop {
        position: fixed; inset: 0; background: rgba(15,23,42,.45);
        opacity: 0; pointer-events: none; transition: opacity .2s ease;
        z-index: 999;
      }
      .manual-modal {
        position: fixed; left: 50%; top: 50%;
        transform: translate(-50%, -52%);
        width: 520px; max-width: 92vw;
        background: #fff; border-radius: 16px;
        box-shadow: 0 30px 80px rgba(2,6,23,.35);
        border: 1px solid #e5e7eb;
        opacity: 0; pointer-events: none;
        transition: transform .18s ease, opacity .18s ease;
        z-index: 1000;
      }
      .manual-modal.open,
      .manual-modal-backdrop.open {
        opacity: 1; pointer-events: auto;
      }
      .manual-modal.open { transform: translate(-50%, -50%); }
      .manual-head {
        padding: 14px 16px; background: #0f172a; color: #fff;
        border-radius: 16px 16px 0 0;
        display: flex; justify-content: space-between; align-items: center;
      }
      .manual-head #manualTitle { font-weight: 700; }
      .manual-close {
        background: transparent; border: none; color: #fff;
        font-size: 22px; line-height: 1; cursor: pointer;
      }
      .manual-body { padding: 14px 16px 6px; }
      .form-row { display: grid; gap: 6px; margin-bottom: 12px; }
      .form-row label { font-size: 13px; color: #0f172a; font-weight: 700; }
      .form-row .req { color: #ef4444; }
      .form-row input[type="text"],
      .form-row input[type="date"],
      .form-row select {
        padding: 10px 12px; border-radius: 10px;
        border: 1px solid #d1d5db; outline: none; font-size: 14px;
      }
      .manual-foot {
        display: flex; justify-content: flex-end;
        gap: 8px; padding: 12px 16px 16px;
      }
      @media (max-width: 520px) { .manual-modal { width: 94vw; } }
    `;
    document.head.appendChild(css);

    const openBtn   = host.querySelector('#manualOpenBtn');
    const backdrop  = document.querySelector('.manual-modal-backdrop');
    const dialog    = document.querySelector('.manual-modal');
    const closeBtn  = dialog.querySelector('.manual-close');
    const inputName = dialog.querySelector('#m_fullname');
    const inputType = dialog.querySelector('#m_type');
    const inputDate = dialog.querySelector('#m_service_date');
    const btnGen    = dialog.querySelector('#manualGenerate');

    function openModal(){
      backdrop.classList.add('open');
      dialog.classList.add('open');
      setTimeout(()=>inputName.focus(), 80);
    }
    function closeModal(){
      backdrop.classList.remove('open');
      dialog.classList.remove('open');
    }
    if(openBtn) openBtn.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);
    document.addEventListener('keydown', (e)=>{
      if(e.key === 'Escape') closeModal();
    });

    function go(){
      const fullname = (inputName.value || '').trim();
      const type     = (inputType.value || '').trim();
      const sdate    = (inputDate.value || '').trim();

      if(!fullname){ alert('Please enter the Fullname.'); inputName.focus(); return; }
      if(!type){ alert('Please select the Type of Service.'); inputType.focus(); return; }
      if(!sdate){ alert('Please choose the Service Date.'); inputDate.focus(); return; }

      const q = new URLSearchParams({
        manual: '1',
        type: type,
        fullname: fullname,
        service_date: sdate
      });
      const url = 'certificate-generate.php?' + q.toString();
      window.location.href = url;
      closeModal();
    }

    btnGen.addEventListener('click', go);
  })();
</script>

<style>
  #svcSort { display: none !important; }
  thead { background-color: #0f172a; }
  :root { --sidebar-width: 280px; }
  .sidebar {
    position: fixed; left: 0; top: 0; bottom: 0;
    width: var(--sidebar-width); height: 100vh;
    overflow-y: auto; z-index: 1000;
  }
  .main-spacer {
    margin-left: var(--sidebar-width);
    min-width: 0; padding-left: 16px; box-sizing: border-box;
  }
  @media (max-width: 720px) {
    .main-spacer { margin-left: var(--sidebar-width); padding-left: 8px; }
    .table-wrap { overflow-x: auto; }
  }
</style>

<style>
  .cert-no,
  [data-cert-no],
  [data-field="certificate_no"],
  [data-col="certificate_no"],
  [name="certificate_no"] {
    display: none !important;
  }
</style>
<script>
  (function () {
    const isCertNoText = (el) =>
      /\bcertificate\s*no\.?:?\b/i.test((el.textContent || '').trim());

    document.querySelectorAll('label, span, div, p').forEach(el => {
      if (isCertNoText(el)) {
        el.style.display = 'none';
        const next = el.nextElementSibling;
        if (next && ['DIV','SPAN','P','TD'].includes(next.tagName)) next.style.display = 'none';
      }
    });

    document.querySelectorAll('table').forEach(table => {
      const headerRow = table.tHead ? table.tHead.rows[0] : table.querySelector('thead tr');
      if (!headerRow) return;
      const ths = Array.from(headerRow.children);
      const idx = ths.findIndex(th => isCertNoText(th));
      if (idx >= 0) {
        ths[idx].style.display = 'none';
        table.querySelectorAll('tr').forEach(tr => {
          const cell = tr.children[idx];
          if (cell) cell.style.display = 'none';
        });
      }
    });
  })();
</script>

</body>
</html>
