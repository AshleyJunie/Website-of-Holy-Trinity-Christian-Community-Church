<?php
/* ============================================================================
  HTCCC – Single-file Certificate Generator (Blue Reference)
  URL: certificate-generate.php?type=baptism&id=123
  Tables covered: service_baptism, service_dedication, service_house, service_wedding, service_funeral
  Primary Keys:
    baptism   -> baptism_id
    dedication-> dedicationId
    house     -> house_id
    funeral   -> funeral_id
    wedding   -> wedding_id

  ADD-ONLY: Supports manual mode
  URL: certificate-generate.php?manual=1&type=baptism&fullname=Juan%20Dela%20Cruz&service_date=2025-11-08
============================================================================ */

/* ------------------------------- Config ---------------------------------- */
$CHURCH_SHORT    = 'HTCCC';
$CHURCH_NAME     = 'HOLY TRINITY CHRISTIAN COMMUNITY CHURCH';
$CHURCH_LINE0    = 'Republic of the Philippines';
$CHURCH_LINE1    = 'Villa Porta Vaga, Cavite City';
$OFFICIANT_NAME  = 'Rev. Dr. IRVING E. DE MESA';
$OFFICIANT_TITLE = 'Church Pastor';

/* ------------------------------- Input ----------------------------------- */
$manual  = isset($_GET['manual']);
$type    = isset($_GET['type']) ? strtolower(trim((string)$_GET['type'])) : '';
$id      = isset($_GET['id'])   ? (string)$_GET['id'] : '';

/* Map tables */
$tableMap = [
  'baptism'    => 'service_baptism',
  'dedication' => 'service_dedication',
  'house'      => 'service_house',
  'wedding'    => 'service_wedding',
  'funeral'    => 'service_funeral',
];
$table = $tableMap[$type] ?? null;

/* Preferred PK per table */
$preferredPkMap = [
  'service_baptism'    => 'baptism_id',
  'service_dedication' => 'dedicationId',
  'service_house'      => 'house_id',      // CHANGED from appointmentId to house_id
  'service_wedding'    => 'wedding_id',
  'service_funeral'    => 'funeral_id',
];
$preferredPk = $preferredPkMap[$table] ?? null;

/* ------------------------------- Helpers --------------------------------- */
$pick = function(array $row, array $keys, $def=null){
  foreach ($keys as $k) {
    if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) return $row[$k];
  }
  return $def;
};

$parseDate = function(?string $s){
  if(!$s) return null; $s=trim($s);
  $try=['Y-m-d H:i:s','Y-m-d H:i A','Y-m-d h:i A','Y-m-d','m/d/Y h:i A','m/d/Y','d/m/Y','F d, Y','M d, Y'];
  foreach($try as $fmt){
    $dt=DateTimeImmutable::createFromFormat($fmt,$s);
    if($dt){ return $dt; }
  }
  $ts=strtotime($s);
  return $ts ? (new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone(date_default_timezone_get())) : null;
};

$serviceDateFromRow = function($row) use($pick,$parseDate){
  $date=$pick($row,['service_date','schedule_date','appointment_date','date','event_date']);
  $time=$pick($row,['service_time','schedule_time','appointment_time','time','event_time']);
  $dt=trim(($date??'').' '.($time??'')); 
  return $parseDate($dt ?: ($date ?? ''));
};

$formatDateLine = function(?DateTimeImmutable $dt){
  if(!$dt) return '';
  return 'on ' . $dt->format('F j, Y');
};

/**
 * Build a full name from split parts.
 * Order: First Middle Last Ext (simple and readable)
 */
$buildName = function($last, $first, $middle, $ext){
  $parts = [];
  if ($first)  $parts[] = trim($first);
  if ($middle) $parts[] = trim($middle);
  if ($last)   $parts[] = trim($last);

  $name = trim(implode(' ', array_filter($parts)));
  if ($ext) {
    $name = trim($name . ' ' . trim($ext));
  }
  return trim($name);
};

/**
 * Recipient name per service using split fields
 * - Baptism:  baptized_lastname, baptized_firstname, baptized_middlename, baptized_ext
 * - Dedication: child_lastname, child_firstname, child_middlename, child_ext
 * - House: owner_lastname, owner_firstname, owner_middlename, owner_ext
 * - Wedding:
 *     Groom: groom_lastname, groom_firstname, groom_middlename, groom_ext
 *     Bride: bride_lastname, bride_firstname, bride_middlename, bride_ext
 * - Funeral: still uses deceased_name (or similar) as you didn’t define splits
 */
$recipientFrom = function($type,$row) use($pick,$buildName){
  switch($type){
    case 'baptism': {
      // New split fields
      $last   = $pick($row,['baptized_lastname']);
      $first  = $pick($row,['baptized_firstname']);
      $middle = $pick($row,['baptized_middlename']);
      $ext    = $pick($row,['baptized_ext']);
      $name   = $buildName($last,$first,$middle,$ext);

      if ($name !== '') return $name;

      // Fallback for legacy data
      return $pick($row,['baptized_name','full_name','name'],'—');
    }

    case 'dedication': {
      // New split fields for child
      $last   = $pick($row,['child_lastname']);
      $first  = $pick($row,['child_firstname']);
      $middle = $pick($row,['child_middlename']);
      $ext    = $pick($row,['child_ext']);
      $name   = $buildName($last,$first,$middle,$ext);

      if ($name !== '') return $name;

      // Fallback for legacy data
      return $pick($row,['service_dedication','dedication_name','child_name','full_name','name'],'—');
    }

    case 'house': {
      // New split fields for owner
      $last   = $pick($row,['owner_lastname']);
      $first  = $pick($row,['owner_firstname']);
      $middle = $pick($row,['owner_middlename']);
      $ext    = $pick($row,['owner_ext']);
      $name   = $buildName($last,$first,$middle,$ext);

      if ($name !== '') return $name;

      // Fallback for legacy data
      return $pick($row,['owner_full_name','owner_name','full_name','name'],'—');
    }

    case 'wedding': {
      // Groom (split fields)
      $g_last   = $pick($row,['groom_lastname']);
      $g_first  = $pick($row,['groom_firstname']);
      $g_middle = $pick($row,['groom_middlename']);
      $g_ext    = $pick($row,['groom_ext']);
      $groom    = $buildName($g_last,$g_first,$g_middle,$g_ext);

      // Bride (split fields)
      $b_last   = $pick($row,['bride_lastname']);
      $b_first  = $pick($row,['bride_firstname']);
      $b_middle = $pick($row,['bride_middlename']);
      $b_ext    = $pick($row,['bride_ext']);
      $bride    = $buildName($b_last,$b_first,$b_middle,$b_ext);

      if ($groom !== '' || $bride !== '') {
        if ($groom !== '' && $bride !== '') return trim($groom . ' & ' . $bride);
        return $groom !== '' ? $groom : $bride;
      }

      // Fallback for legacy data
      $g = $pick($row,['groom_name','groom'],'Groom');
      $b = $pick($row,['bride_name','bride'],'Bride');
      return trim($g.' & '.$b);
    }

    case 'funeral':
      // You didn’t specify split name fields for funeral
      return $pick($row,['deceased_name','full_name','name'],'—');

    default:
      return '—';
  }
};

$base64 = function($pathCandidates){
  foreach ($pathCandidates as $p) {
    if (!$p) continue;
    if (is_file($p)) {
      $bin=@file_get_contents($p); if(!$bin) continue;
      $mime='image/png';
      $l=strtolower($p);
      if (str_ends_with($l,'.jpg') || str_ends_with($l,'.jpeg')) $mime='image/jpeg';
      if (str_ends_with($l,'.webp')) $mime='image/webp';
      if (str_ends_with($l,'.svg'))  $mime='image/svg+xml';
      return 'data:'.$mime.';base64,'.base64_encode($bin);
    }
  }
  return null;
};

$logo_src = (function() use($base64){
  $c = [
    __DIR__.'/image/httc_main-logo.jpg',
    __DIR__.'/image/httc_main-logo.png',
    ($_SERVER['DOCUMENT_ROOT'] ?? '').'/HTCCC-SYSTEM/image/httc_main-logo.jpg',
    ($_SERVER['DOCUMENT_ROOT'] ?? '').'/HTCCC-SYSTEM/image/httc_main-logo.png',
  ];
  return $base64($c);
})();

$bg_src = (function() use($base64){
  $c = [
    __DIR__.'/Blue Floral Watercolor Baptism Certificate.png',
    '/mnt/data/Blue Floral Watercolor Baptism Certificate.png',
    __DIR__.'/image/certificates/blue-floral-baptism.png',
    ($_SERVER['DOCUMENT_ROOT'] ?? '').'/HTCCC-SYSTEM/image/certificates/blue-floral-baptism.png',
  ];
  return $base64($c);
})();

/* ---------------------------- DB Bootstrap ------------------------------- */
/* NOTE: In manual mode, we skip DB fetch entirely. */
$pdo = null;
if (!$manual) {
  if (!isset($pdo)) { @include_once __DIR__ . '/connection.php'; }
  if (!isset($pdo) || !($pdo instanceof PDO)) {
    try {
      $pdo = new PDO(
        'mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4',
        'root','',
        [
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
      );
    } catch (Throwable $e) {
      http_response_code(500);
      echo 'Database connection failed.';
      exit;
    }
  }
}

/* ---------------------------- Fetch or Build Row ------------------------- */
$row = null;

if ($manual) {
  // Build a synthetic row from GET for uniform downstream handling
  $manual_fullname = isset($_GET['fullname']) ? trim((string)$_GET['fullname']) : '';
  $manual_date     = isset($_GET['service_date']) ? trim((string)$_GET['service_date']) : '';

  if (!$type || !$manual_fullname || !$manual_date) {
    http_response_code(400);
    echo 'Missing required parameters for manual generation (fullname, type, service_date).';
    exit;
  }

  $row = [
    'service_date'    => $manual_date,
    'full_name'       => $manual_fullname,
    'name'            => $manual_fullname,
    'baptized_name'   => $manual_fullname,
    'owner_full_name' => $manual_fullname,
  ];

} else {
  // Automated DB path
  if (!$table || $id === '') {
    http_response_code(400);
    echo 'Missing or invalid parameters.';
    exit;
  }

  // Include each service's true primary key plus some legacy / generic fallbacks
  $pkCandidates = array_values(array_unique(array_merge(
    array_filter([$preferredPk]),
    [
      'baptism_id',
      'dedicationId',
      'house_id',
      'funeral_id',
      'wedding_id',
      'appointmentId',       // legacy / fallback
    ],
    [
      'id',
      'ID',
      'service_id',
      'appointment_id',
      'record_id'
    ]
  )));

  // Try preferred PK first (already included above), then fallbacks
  foreach($pkCandidates as $pk){
    try {
      $st = $pdo->prepare("SELECT * FROM `$table` WHERE `$pk` = :id LIMIT 1");
      $st->execute([':id'=>$id]);
      $r = $st->fetch();
      if ($r) { $row = $r; break; }
    } catch (Throwable $e) {
      // continue trying next pk
    }
  }

  if (!$row) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
  }
}

/* ------------------------------ Build View ------------------------------ */
$recipient = $manual
  ? (string)($_GET['fullname'] ?? '')
  : $recipientFrom($type,$row);

$dt        = $manual
  ? $parseDate((string)($_GET['service_date'] ?? ''))
  : $serviceDateFromRow($row);

$date_line = $formatDateLine($dt);

$serviceWordMap = [
  'baptism'    => 'BAPTISM',
  'dedication' => 'CHILD DEDICATION',
  'house'      => 'HOUSE BLESSING',
  'wedding'    => 'MARRIAGE',
  'funeral'    => 'FUNERAL SERVICE',
];
$serviceWord = $serviceWordMap[$type] ?? strtoupper($type);

$bodyMap = [
  'baptism'    => 'was received into church fellowship by water baptism',
  'dedication' => 'was dedicated to the Lord in the presence of family and church',
  'house'      => 'received a prayer of blessing and dedication unto the Lord',
  'wedding'    => 'entered into holy matrimony before God and witnesses',
  'funeral'    => 'was remembered and honored in a service of thanksgiving to God',
];
$body_line = $bodyMap[$type] ?? 'is recognized with this certificate';

/* ------------------------------ HTML Output ----------------------------- */
ob_start(); ?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars('Certificate of '.$serviceWord) ?></title>
  <style>
    /* Keep it to one page */
    @page { size: A4 landscape; margin: 14mm; }
    html, body { margin:0; padding:0; }
    * { box-sizing: border-box; }

    body { font-family: "DejaVu Sans", "Times New Roman", Georgia, serif; color:#0f172a; }

    .sheet {
      position: relative;
      padding: 20px 26px 18px;
      border: 12px solid #2E3B5B;
      border-radius: 10px;
      background: #f4f6f9;
      overflow: hidden;
    }
    <?php if ($bg_src): ?>
    .sheet:before {
      content:"";
      position:absolute; inset:0;
      background-image:url('<?= $bg_src ?>');
      background-size: cover;
      background-position: center;
      opacity:.95;
      z-index:0;
    }
    <?php endif; ?>

    .content { position: relative; z-index:1; }
    .country { text-align:center; font-size:13px; margin-top:2px; }
    .church  { text-align:center; font-size:20px; font-weight:800; letter-spacing:.6px; margin-top:4px; }
    .address { text-align:center; font-size:12px; color:#334155; margin-top:4px; }
    .title { text-align:center; margin: 16px 0 6px; letter-spacing:5px; font-weight:700; font-size:26px; }
    .subtitle { text-align:center; font-size:14px; margin: 6px 0 10px; color:#0f172a; }
    .recipient { text-align:center; font-size:56px; line-height:1.06; font-weight:800; letter-spacing:1px; color:#2E3B5B; margin-top:2px; }
    .rule { width:55%; height:2px; margin:6px auto 12px; background:#2E3B5B; opacity:.35; }
    .line { text-align:center; font-size:14px; color:#0f172a; }
    .date { text-align:center; font-size:14px; margin-top:6px; color:#0f172a; }
    .sigwrap { width:92%; margin:28px auto 0; }
    .sigrow  { display:block; width:85%; margin:18px auto 0; }
    .sigline { height:38px; border-bottom:2px solid #2E3B5B; opacity:.7; }
    .siglabel { text-align:center; font-size:13px; font-weight:700; margin-top:6px; color:#0f172a; }
    .sigsub   { text-align:center; font-size:11px; color:#334155; margin-top:2px; }
    .footer { margin-top:12px; text-align:center; color:#334155; font-size:12px; }
    .logo { position:absolute; left: 16px; top: 12px; z-index:2; }
    .logo img { height: 48px; width:auto; display:block; }

    <?php if (!$bg_src): ?>
    .sheet {
      background: radial-gradient(1000px 500px at 50% 8%, rgba(46,59,91,.05), rgba(46,59,91,0) 60%) #f7f8fb;
    }
    <?php endif; ?>
  </style>
</head>
<body>
  <div class="sheet">
    <?php if ($logo_src): ?><div class="logo" style="margin-left: 100px;"><img src="<?= $logo_src ?>" alt="HTCCC"></div><?php endif; ?>
    <div class="content">
      <div class="country"><?= htmlspecialchars($CHURCH_LINE0) ?></div>
      <div class="church"><?= htmlspecialchars($CHURCH_NAME) ?></div>
      <div class="address"><?= htmlspecialchars($CHURCH_LINE1) ?></div>

      <div class="title" style="margin-top: 100px; font-size:50px;">CERTIFICATE OF <?= htmlspecialchars($serviceWord) ?></div>
      <div class="subtitle">This certifies that</div>

      <div class="recipient"><?= htmlspecialchars($recipient) ?></div>
      <div class="rule"></div>

      <div class="line"><?= htmlspecialchars($body_line) ?></div>
      <?php if ($dt): ?><div class="date"><?= htmlspecialchars($date_line) ?></div><?php endif; ?>

      <div class="sigwrap">
        <div class="sigrow">
          <div class="sigline"></div>
          <div class="siglabel"><b>Rev. Dr. Irving E. De Mesa, Th. D.</b></div>
          <div class="siglabel">Church Pastor</div>
        </div>
        <div class="sigrow">
          <div class="sigline"></div>
          <div class="siglabel"><?= htmlspecialchars($OFFICIANT_NAME) ?></div>
          <div class="sigsub"><?= htmlspecialchars($OFFICIANT_TITLE) ?></div>
        </div>
      </div>

      <div class="footer"><?= htmlspecialchars($CHURCH_SHORT) ?></div>
    </div>
  </div>
</body>
</html>
<?php
$html = ob_get_clean();

/* ============================== ADD BELOW THIS LINE ============================== */
$__date_text = $dt ? $dt->format('F j, Y') : null;

switch ($type) {
  case 'baptism':
    $__msg = "This certificate affirms the sacrament of baptism at Holy Trinity Christian Community Church. The baptism was solemnly recorded on " . ($__date_text ?: "the stated date") . ".";
    break;
  case 'dedication':
    $__msg = "This certificate acknowledges the child dedication unto the Lord at Holy Trinity Christian Community Church. The dedication took place on " . ($__date_text ?: "the stated date") . ".";
    break;
  case 'house':
    $__msg = "This certificate records the house blessing conducted by Holy Trinity Christian Community Church. The blessing was performed on " . ($__date_text ?: "the stated date") . ".";
    break;
  case 'wedding':
    $__msg = "This certificate recognizes the solemnization of marriage at Holy Trinity Christian Community Church. The union was celebrated on " . ($__date_text ?: "the stated date") . ".";
    break;
  case 'funeral':
    $__msg = "This certificate commemorates the life and memory remembered at Holy Trinity Christian Community Church. The funeral service was held on " . ($__date_text ?: "the stated date") . ".";
    break;
  default:
    $__msg = "This certificate is issued by Holy Trinity Christian Community Church. It was recorded on " . ($__date_text ?: "the stated date") . ".";
}

$html = preg_replace('#<div class="line">.*?</div>#s','<div class="line">'.htmlspecialchars($__msg).'</div>',$html,1);

/* VISUAL TWEAKS */
$__visual_css = <<<CSS
<style>
  :root{ --navy:#2E3B5B; --ivory:#F1EFEA; --ink:#0f172a; }
  html, body { height:100%; }
  body { position:relative; }
  .sheet{ position:absolute !important; inset:0; border-width:28px !important; background:var(--ivory) !important; padding:30px 34px 28px !important; }
  .sheet:before{ content:""; position:absolute; inset:22px; background:rgba(255,255,255,.55); border-radius:2px; box-shadow: inset 0 0 0 1.25px rgba(0,0,0,.06); z-index:0; }
  .church{ position:relative; padding-bottom:10px; }
  .church:after{ content:""; position:absolute; left:18%; right:18%; bottom:0; height:1.5px; background:rgba(46,59,91,.25); }
  .logo{ left:56px !important; top:50px !important; z-index:5 !important; }
  .logo img{ height:56px !important; }
  .sigwrap{ position:relative; width:92%; margin:54px auto 0; padding-bottom:110px; }
  .sigrow:last-child{ position:absolute; right:86px; bottom:78px; width:42%; margin:0; text-align:right; z-index:6; }
  .sigrow:last-child .siglabel{ font-weight:700; font-size:14px; color:#0f172a; }
  .sigrow:last-child .sigsub{ font-size:12px; color:#374151; }
  .sheet:after{ display:none !important; content:none !important; }
</style>
CSS;
$html = str_replace('</head>', $__visual_css.'</head>', $html);

/* Bold “service date” only if it differs from main date, then remove the duplicate standalone date line */
$__service_date_raw = null;
if ($manual) {
  $__service_date_raw = isset($_GET['service_date']) ? (string)$_GET['service_date'] : null;
} else {
  if (!empty($row['service_date'])) {
    $__service_date_raw = (string)$row['service_date'];
  } else {
    foreach (['schedule_date','appointment_date','date','event_date'] as $__k) {
      if (!empty($row[$__k])) { $__service_date_raw = (string)$row[$__k]; break; }
    }
  }
}
$__service_date_pretty = null;
if ($__service_date_raw !== null) {
  $__tmp_dt = $parseDate($__service_date_raw);
  $__service_date_pretty = $__tmp_dt ? $__tmp_dt->format('F j, Y') : $__service_date_raw;
}
$__pretty_main = $dt ? $dt->format('F j, Y') : null;

if ($__service_date_pretty) {
  $same = ($__pretty_main && strcasecmp($__service_date_pretty, $__pretty_main) === 0);
  if (!$same) {
    $pretty_main_safe = htmlspecialchars($__pretty_main ?: 'the stated date');
    $__service_date_html = '<strong>'.htmlspecialchars($__service_date_pretty).'</strong>';
    switch ($type) {
      case 'baptism':
        $__msg2_html = "This certificate affirms the sacrament of baptism at Holy Trinity Christian Community Church. The baptism was solemnly recorded on ".$pretty_main_safe." ({$__service_date_html}).";
        break;
      case 'dedication':
        $__msg2_html = "This certificate acknowledges the child dedication unto the Lord at Holy Trinity Christian Community Church. The dedication took place on ".$pretty_main_safe." ({$__service_date_html}).";
        break;
      case 'house':
        $__msg2_html = "This certificate records the house blessing conducted by Holy Trinity Christian Community Church. The blessing was performed on ".$pretty_main_safe." ({$__service_date_html}).";
        break;
      case 'wedding':
        $__msg2_html = "This certificate recognizes the solemnization of marriage at Holy Trinity Christian Community Church. The union was celebrated on ".$pretty_main_safe." ({$__service_date_html}).";
        break;
      case 'funeral':
        $__msg2_html = "This certificate commemorates the life and memory remembered at Holy Trinity Christian Community Church. The funeral service was held on ".$pretty_main_safe." ({$__service_date_html}).";
        break;
      default:
        $__msg2_html = "This certificate is issued by Holy Trinity Christian Community Church. It was recorded on ".$pretty_main_safe." ({$__service_date_html}).";
    }
    $html = preg_replace('#<div class="line">.*?</div>#s', '<div class="line">'.$__msg2_html.'</div>', $html, 1);
  }
}

/* Remove the standalone date block to avoid duplicate date lines */
$html = preg_replace('#<div class="date">.*?</div>#s', '', $html, 1);

/* ---------------- ALWAYS DOWNLOAD (no preview path used) ---------------- */
$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
  header('Content-Type: text/plain; charset=utf-8');
  echo "Dompdf not found. Run: composer require dompdf/dompdf";
  exit;
}
require_once $autoload;

$options = new Dompdf\Options();
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);

$dompdf = new Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$filename = sprintf('%s_%s_%s.pdf',
  preg_replace('/\s+/', '_', ucfirst($type)),
  preg_replace('/\s+/', '_', $recipient ?: 'Recipient'),
  date('Ymd_His')
);

while (ob_get_level()) { @ob_end_clean(); }
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $dompdf->output();
exit;
