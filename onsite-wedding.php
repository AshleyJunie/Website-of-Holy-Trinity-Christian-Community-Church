<?php
// ===== Guard & context =====
session_start();
// Prefer session; fallback to GET so deep links still work
$individualId = $_SESSION['individual_id'] ?? (isset($_GET['individual_id']) ? (int)$_GET['individual_id'] : null);

// Bring over the appointment selections from the appointment page
$selDate    = isset($_GET['date'])    ? trim($_GET['date'])    : '';
$selTime    = isset($_GET['time'])    ? trim($_GET['time'])    : '';
$selService = isset($_GET['service']) ? trim($_GET['service']) : 'WEDDING';

// NOTE: Login is now OPTIONAL. We no longer force redirect to all_log-in.php
// If an individual_id exists (from session/GET), we use it for profile lookups,
// otherwise this form works as a guest submission.

/* ===== Fetch profile ONLY from individual_table (gender-aware) ===== */
$__PROFILE = null;
if ($individualId) {
  try {
    $pdoProfile = new PDO(
      "mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4",
      "root",
      "",
      [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]
    );
    $stmt = $pdoProfile->prepare("
      SELECT individual_id, individual_lastname, individual_firstname, individual_middlename,
             individual_birthday, individual_gender, individual_extension, baptised_church
      FROM individual_table
      WHERE individual_id = :id LIMIT 1
    ");
    $stmt->execute([':id' => (int)$individualId]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $g = strtolower(trim((string)($row['individual_gender'] ?? '')));
      $gender = (in_array($g, ['m','male'])) ? 'Male' : ((in_array($g, ['f','female'])) ? 'Female' : null);
      $birth = $row['individual_birthday'] ?? '';
      $birthNorm = '';
      if ($birth !== '') {
        $ts = strtotime($birth);
        if ($ts !== false) $birthNorm = date('Y-m-d', $ts);
      }
      $__PROFILE = [
        'gender' => $gender,
        'first'  => (string)($row['individual_firstname']  ?? ''),
        'last'   => (string)($row['individual_lastname']   ?? ''),
        'middle' => (string)($row['individual_middlename'] ?? ''),
        'suffix' => (string)($row['individual_extension']  ?? ''),
        'birth'  => $birthNorm,
        'church' => (string)($row['baptised_church']       ?? ''),
      ];
    }
  } catch (Throwable $___pex) { $__PROFILE = null; }
}

/* ===== ADD BELOW THIS LINE: Fetch civil_status + DB-level comparison (add-only) ===== */
$__CIVIL_STATUS = null;
$__IS_WIDOWED   = null;
if ($individualId) {
  try {
    $pdoCivil = new PDO(
      "mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4",
      "root",
      "",
      [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]
    );
    // Get raw civil_status
    $stmtCivil = $pdoCivil->prepare("SELECT civil_status FROM individual_table WHERE individual_id = :id LIMIT 1");
    $stmtCivil->execute([':id' => (int)$individualId]);
    $__CIVIL_STATUS = (string)($stmtCivil->fetchColumn() ?: null);

    // DB-level, case-insensitive comparison flag
    $stmtIsW = $pdoCivil->prepare("
       SELECT (LOWER(TRIM(civil_status)) = 'widowed') AS is_widowed
       FROM individual_table WHERE individual_id = :id LIMIT 1
    ");
    $stmtIsW->execute([':id' => (int)$individualId]);
    $__IS_WIDOWED = (bool)$stmtIsW->fetchColumn();
  } catch (Throwable $___csx) {
    $__CIVIL_STATUS = null; $__IS_WIDOWED = null;
  }
}
/* ===== END ADD ===== */

/* ===== Name helpers ===== */
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

/* ================================ SERVER-SIDE: cross-service ACTIVE-appointment limit (max 5) =================================== */
if (!function_exists('htccc_count_active_across_services_pdo')) {
  function htccc_count_active_across_services_pdo(PDO $pdo, int $individualId, array $closedStatuses): int {
    $tables = [];
    $stmtTbl = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'service\\_%'");
    if ($stmtTbl) {
      foreach ($stmtTbl as $r) {
        $t = $r['TABLE_NAME'] ?? '';
        if (!$t) continue;
        $chk = $pdo->prepare("
          SELECT COUNT(*) AS c
          FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tname
            AND COLUMN_NAME IN ('individual_id','status')
        ");
        $chk->execute([':tname' => $t]);
        if ((int)$chk->fetchColumn() >= 2) $tables[] = $t;
      }
    }
    if (!$tables) return 0;

    $ph = implode(',', array_fill(0, count($closedStatuses), '?'));
    $total = 0;
    foreach ($tables as $t) {
      $sql = "SELECT COUNT(*) FROM {$t} WHERE individual_id = ? AND (status IS NULL OR status NOT IN ($ph))";
      $params = array_merge([$individualId], $closedStatuses);
      $q = $pdo->prepare($sql);
      if ($q && $q->execute($params)) $total += (int)$q->fetchColumn();
    }
    return $total;
  }
}

// Early POST check for quota before the main submit handler
$__quotaExceeded = (isset($_GET['quota']) && $_GET['quota'] === '1');

// Only enforce quota if we have an identified account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $individualId) {
  try {
    $pdoPre = new PDO(
      "mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4",
      "root",
      "",
      [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]
    );
    $CLOSED = ['Approved','Declined','Cancelled','Archived','Done'];
    $activeCount = htccc_count_active_across_services_pdo($pdoPre, (int)$individualId, $CLOSED);
    if ($activeCount >= 5) {
      $q = http_build_query([
        'quota'   => '1',
        'date'    => $selDate,
        'time'    => $selTime,
        'service' => $selService ?: 'WEDDING',
      ]);
      header('Location: ' . basename(__FILE__) . '?' . $q);
      exit;
    }
  } catch (Throwable $___qex) {
    // If counting fails, allow flow to continue.
  }
}

/* ===== Utility: ensure columns exist (auto-ADD if missing) ===== */
function htccc_ensure_columns(PDO $pdo, string $table, array $ddlByCol): void {
  $in = implode(',', array_fill(0, count($ddlByCol), '?'));
  $q = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN ($in)");
  $params = array_merge([$table], array_keys($ddlByCol));
  $q->execute($params);
  $have = [];
  foreach ($q->fetchAll(PDO::FETCH_COLUMN, 0) as $c) $have[$c] = true;
  foreach ($ddlByCol as $col => $ddl) {
    if (!isset($have[$col])) {
      $sql = "ALTER TABLE $table ADD COLUMN $col $ddl";
      try { $pdo->exec($sql); } catch (Throwable $e) { /* ignore if racing */ }
    }
  }
}

/* ---------- ADD BELOW THIS LINE: Utility to RELAX NOT NULL → NULL on specific columns (add-only) ---------- */
if (!function_exists('htccc_relax_nullable')) {
  /**
   * Ensure selected columns are nullable. Pass the desired column definitions (e.g., "VARCHAR(500) NULL").
   * This will modify the column only if it's currently NOT NULL.
   */
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
    foreach ($rows as $r) { $map[$r['COLUMN_NAME']] = $r; }

    foreach ($colDefs as $col => $def) {
      if (!isset($map[$col])) continue;
      $isNullable = strtoupper((string)$map[$col]['IS_NULLABLE']) === 'YES';
      if ($isNullable) continue;
      // Relax to provided definition (e.g., VARCHAR(500) NULL)
      $sql = "ALTER TABLE {$table} MODIFY COLUMN {$col} {$def}";
      try { $pdo->exec($sql); } catch (Throwable $e) { /* ignore if another process changed it */ }
    }
  }
}
/* ---------- END ADD ---------- */

/* ===== Inline submission handler ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $pdo = new PDO(
      "mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4",
      "root",
      "",
      [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]
    );

    // Try session first, then fallback to the resolved individualId.
    // If still null, this is a GUEST submission (allowed).
    $sessionId = $_SESSION['individual_id'] ?? $individualId ?? null;

    $req = function($key) {
      if (!isset($_POST[$key]) || $_POST[$key] === '') {
        throw new RuntimeException("Missing required field: {$key}");
      }
      return trim($_POST[$key]);
    };

    // ---------- ADD BELOW THIS LINE (server fallback for service_date) ----------
    // If service_date wasn't picked via the floating calendar, default it to appointment_date.
    if (empty($_POST['service_date'] ?? '')) {
      if (!empty($_POST['appointment_date'] ?? '')) {
        $_POST['service_date'] = trim((string)$_POST['appointment_date']);
      }
    }
    // ---------- END ADD ----------

    // Upload rules
    $rules = [
      'groom_birth_cert'       => ['jpg','jpeg','png','pdf'],
      'bride_birth_cert'       => ['jpg','jpeg','png','pdf'],
      'groom_valid_id'         => ['jpg','jpeg','png','pdf'],
      'bride_valid_id'         => ['jpg','jpeg','png','pdf'],
      'groom_baptismal_cert'   => ['jpg','jpeg','png'],
      'bride_baptismal_cert'   => ['jpg','jpeg','png'],
      'bride_cenomar'          => ['jpg','jpeg','png','pdf'],
      'groom_cenomar'          => ['jpg','jpeg','png','pdf'],
      // ==== NEW: side-specific widow uploads ====
      'groom_widowed_file'     => ['jpg','jpeg','png','pdf'],
      'bride_widowed_file'     => ['jpg','jpeg','png','pdf'],
    ];
    $mimeMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','pdf'=>'application/pdf'];

    // == REMOVED: generic widow_doc / partner_* logic and mappings ==

    $save_upload = function(string $field, string $baseDir, array $allowedExts) use ($mimeMap) : string {
      if (!isset($_FILES[$field]) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        throw new RuntimeException("Missing required file: {$field}");
      }
      $file = $_FILES[$field];
      if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException("Upload error ({$field}): code " . $file['error']);
      $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
      if (!in_array($ext, $allowedExts, true)) throw new RuntimeException("Invalid file type for {$field}. Allowed: " . implode(', ', $allowedExts));
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      $mime = finfo_file($finfo, $file['tmp_name']) ?: '';
      finfo_close($finfo);
      $expected = $mimeMap[$ext] ?? '';
      if ($expected && $mime !== $expected) throw new RuntimeException("File content type mismatch for {$field}.");
      if (in_array($ext, ['jpg','jpeg','png'], true) && @getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException("Invalid image file for {$field}.");
      }
      if (!is_dir($baseDir)) {
        if (!mkdir($baseDir, 0775, true) && !is_dir($baseDir)) throw new RuntimeException("Failed to create directory: {$baseDir}");
      }
      $targetAbs = rtrim($baseDir, '/\\') . '/' . uniqid($field . '_', true) . '.' . $ext;
      if (!move_uploaded_file($file['tmp_name'], $targetAbs)) throw new RuntimeException("Failed to move uploaded file for {$field}");
      $docRoot = rtrim(str_replace('\\','/', $_SERVER['DOCUMENT_ROOT']), '/');
      $targetNorm = str_replace('\\','/', $targetAbs);
      if (strpos($targetNorm, $docRoot) === 0) return ltrim(substr($targetNorm, strlen($docRoot)), '/');
      return $targetNorm;
    };

    // ---------- ADD BELOW THIS LINE: determine situational requirements ----------
    $loggedGender   = $__PROFILE['gender'] ?? null;
    $loggedIsWidowed = $__IS_WIDOWED === true;

    $partnerStatus = isset($_POST['partner_status']) ? strtolower(trim($_POST['partner_status'])) : '';
    // Hidden flags come from the JS controller
    $groomWidowedOn = (($_POST['groom_widowed_enabled'] ?? '0') === '1');
    $brideWidowedOn = (($_POST['bride_widowed_enabled'] ?? '0') === '1');

    // Fallback derivation if hidden flags were missing (shouldn't happen)
    if ($loggedGender === 'Male') {
      if ($loggedIsWidowed) $groomWidowedOn = true;
      if ($partnerStatus === 'widowed') $brideWidowedOn = true;
    } elseif ($loggedGender === 'Female') {
      if ($loggedIsWidowed) $brideWidowedOn = true;
      if ($partnerStatus === 'widowed') $groomWidowedOn = true;
    }
    // ---------- END ADD ----------

    // Required fields
    $appointment_date   = $req('appointment_date');
    $appointment_time   = $req('appointment_time');
    $appointment_service= $_POST['appointment_service'] ?? 'WEDDING';
    // New required field (service_date)
    $service_date       = $req('service_date'); // YYYY-MM-DD

    // ===== Read split name fields from POST =====
    $groom_lastname   = htccc_titlecase_name($req('groom_lastname'));
    $groom_firstname  = htccc_titlecase_name($req('groom_firstname'));
    $groom_middlename = isset($_POST['groom_middlename']) ? htccc_titlecase_name($_POST['groom_middlename']) : '';
    $groom_extension  = isset($_POST['groom_extension']) ? htccc_normalize_suffix($_POST['groom_extension']) : '';

    $bride_lastname   = htccc_titlecase_name($req('bride_lastname'));
    $bride_firstname  = htccc_titlecase_name($req('bride_firstname'));
    $bride_middlename = isset($_POST['bride_middlename']) ? htccc_titlecase_name($_POST['bride_middlename']) : '';
    $bride_extension  = isset($_POST['bride_extension']) ? htccc_normalize_suffix($_POST['bride_extension']) : '';

    $groom_birthdate  = $req('groom_birthdate');
    $groom_church     = $_POST['groom_church'] ?? null;
    $bride_birthdate  = $req('bride_birthdate');
    $bride_church     = $_POST['bride_church'] ?? null;

    $contact_number   = '';
    $special_request  = $_POST['special_request'] ?? null;

    // ===== If logged-in profile exists, override/transfer into the current values =====
    if (isset($__PROFILE) && is_array($__PROFILE) && !empty($__PROFILE['gender'])) {
      if ($__PROFILE['gender'] === 'Male') {
        $groom_lastname   = htccc_titlecase_name($__PROFILE['last']   ?: $groom_lastname);
        $groom_firstname  = htccc_titlecase_name($__PROFILE['first']  ?: $groom_firstname);
        $groom_middlename = htccc_titlecase_name($__PROFILE['middle'] ?: $groom_middlename);
        $groom_extension  = htccc_normalize_suffix($__PROFILE['suffix'] ?: $groom_extension);
        $groom_birthdate  = $__PROFILE['birth']   ?: $groom_birthdate;
        $groom_church     = $__PROFILE['church']  ?: $groom_church;
      } elseif ($__PROFILE['gender'] === 'Female') {
        $bride_lastname   = htccc_titlecase_name($__PROFILE['last']   ?: $bride_lastname);
        $bride_firstname  = htccc_titlecase_name($__PROFILE['first']  ?: $bride_firstname);
        $bride_middlename = htccc_titlecase_name($__PROFILE['middle'] ?: $bride_middlename);
        $bride_extension  = htccc_normalize_suffix($__PROFILE['suffix'] ?: $bride_extension);
        $bride_birthdate  = $__PROFILE['birth']   ?: $bride_birthdate;
        $bride_church     = $__PROFILE['church']  ?: $bride_church;
      }
    }

    /* ===== ADD BELOW THIS LINE: Server-side MINOR age gate (must be 18+) ===== */
    if (!function_exists('htccc_age_years')) {
      function htccc_age_years(?string $ymd): ?int {
        if (!$ymd) return null;
        $dt = DateTime::createFromFormat('Y-m-d', $ymd);
        if (!$dt) return null;
        $today = new DateTime('today');
        return (int)$dt->diff($today)->y;
      }
    }
    $ageGroom = htccc_age_years($groom_birthdate);
    $ageBride = htccc_age_years($bride_birthdate);
    if (($ageGroom !== null && $ageGroom < 18) || ($ageBride !== null && $ageBride < 18)) {
      throw new RuntimeException("Applicants must be at least 18 years old. We can’t accept minors.");
    }
    /* ===== END ADD ===== */

    // ===== Ensure required columns exist (auto-add if missing) =====
    htccc_ensure_columns($pdo, 'service_wedding', [
      // scheduling
      'service_date' => "DATE NULL",
      // SPLIT NAMES (groom)
      'groom_lastname'   => "VARCHAR(150) NULL",
      'groom_firstname'  => "VARCHAR(150) NULL",
      'groom_middlename' => "VARCHAR(150) NULL",
      'groom_extension'  => "VARCHAR(20) NULL",
      // SPLIT NAMES (bride)
      'bride_lastname'   => "VARCHAR(150) NULL",
      'bride_firstname'  => "VARCHAR(150) NULL",
      'bride_middlename' => "VARCHAR(150) NULL",
      'bride_extension'  => "VARCHAR(20) NULL",
      // NEW: service_status column for scheduling state
      'service_status'   => "VARCHAR(50) NULL"
    ]);
    /* ===== keep columns for side-specific widow files ===== */
    htccc_ensure_columns($pdo, 'service_wedding', [
      'groom_widowed_file' => "VARCHAR(500) NULL",
      'bride_widowed_file' => "VARCHAR(500) NULL"
    ]);

    /* ---------- ADD BELOW THIS LINE: Relax NOT NULL → NULL for situational file columns ---------- */
    htccc_relax_nullable($pdo, 'service_wedding', [
      'individual_id'      => "INT NULL",
      'groom_cenomar_path' => "VARCHAR(500) NULL",
      'bride_cenomar_path' => "VARCHAR(500) NULL",
      'groom_widowed_file' => "VARCHAR(500) NULL",
      'bride_widowed_file' => "VARCHAR(500) NULL",
    ]);
    /* ---------- END ADD ---------- */

    // Upload target
    $stamp = date('Ymd_His');

    // If logged in: use numeric ID; if guest: bucket under guest_<sessionid>
    $ownerBucket = $sessionId ? (int)$sessionId : ('guest_' . session_id());
    $baseDir = __DIR__ . "/uploads/wedding/{$ownerBucket}/{$stamp}";

    // Required uploads (always required)
    $groom_birth_cert_path = $save_upload('groom_birth_cert', $baseDir, $rules['groom_birth_cert']);
    $groom_valid_id_path   = $save_upload('groom_valid_id',   $baseDir, $rules['groom_valid_id']);
    $bride_birth_cert_path = $save_upload('bride_birth_cert', $baseDir, $rules['bride_birth_cert']);
    $bride_valid_id_path   = $save_upload('bride_valid_id',   $baseDir, $rules['bride_valid_id']);

    // Optional uploads
    $groom_baptismal_cert_path = null;
    if (!empty($_FILES['groom_baptismal_cert']['name'])) $groom_baptismal_cert_path = $save_upload('groom_baptismal_cert', $baseDir, $rules['groom_baptismal_cert']);
    $bride_baptismal_cert_path = null;
    if (!empty($_FILES['bride_baptismal_cert']['name'])) $bride_baptismal_cert_path = $save_upload('bride_baptismal_cert', $baseDir, $rules['bride_baptismal_cert']);

    // ---------- ADD BELOW THIS LINE: situational CENOMAR / Widowed uploads ----------
    // These two are situational: if widowed, require widowed file; else require CENOMAR.
    $groom_widowed_file_path = null;
    $bride_widowed_file_path = null;

    if ($groomWidowedOn) {
      // require groom widowed document
      if (!empty($_FILES['groom_widowed_file']['name'])) {
        $groom_widowed_file_path = $save_upload('groom_widowed_file', $baseDir, $rules['groom_widowed_file']);
      } else {
        throw new RuntimeException("Missing required file: groom_widowed_file (Groom marked as Widowed).");
      }
      $groom_cenomar_path = null; // not required
    } else {
      // require groom CENOMAR
      $groom_cenomar_path = $save_upload('groom_cenomar', $baseDir, $rules['groom_cenomar']);
    }

    if ($brideWidowedOn) {
      if (!empty($_FILES['bride_widowed_file']['name'])) {
        $bride_widowed_file_path = $save_upload('bride_widowed_file', $baseDir, $rules['bride_widowed_file']);
      } else {
        throw new RuntimeException("Missing required file: bride_widowed_file (Bride marked as Widowed).");
      }
      $bride_cenomar_path = null; // not required
    } else {
      $bride_cenomar_path = $save_upload('bride_cenomar', $baseDir, $rules['bride_cenomar']);
    }
    // ---------- END ADD ----------

    // ===== FINAL INSERT (now includes side-specific widow files + service_status scheduled) =====
    $sql = "INSERT INTO service_wedding (
        individual_id,
        appointment_date, appointment_time, appointment_service, service_date,
        groom_lastname, groom_firstname, groom_middlename, groom_extension,
        bride_lastname, bride_firstname, bride_middlename, bride_extension,
        groom_birthdate, groom_church, bride_birthdate, bride_church,
        contact_number, special_request,
        groom_birth_cert_path, groom_valid_id_path, groom_baptismal_cert_path,
        bride_birth_cert_path, bride_valid_id_path, bride_baptismal_cert_path,
        bride_cenomar_path, groom_cenomar_path,
        groom_widowed_file, bride_widowed_file,
        status,
        service_status
      ) VALUES (
        :individual_id,
        :appointment_date, :appointment_time, :appointment_service, :service_date,
        :groom_lastname, :groom_firstname, :groom_middlename, :groom_extension,
        :bride_lastname, :bride_firstname, :bride_middlename, :bride_extension,
        :groom_birthdate, :groom_church, :bride_birthdate, :bride_church,
        :contact_number, :special_request,
        :groom_birth_cert_path, :groom_valid_id_path, :groom_baptismal_cert_path,
        :bride_birth_cert_path, :bride_valid_id_path, :bride_baptismal_cert_path,
        :bride_cenomar_path, :groom_cenomar_path,
        :groom_widowed_file, :bride_widowed_file,
        'Pending',
        'Scheduled'
      )";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':individual_id'               => $sessionId ?: null,
      ':appointment_date'            => $appointment_date,
      ':appointment_time'            => $appointment_time,
      ':appointment_service'         => $appointment_service,
      ':service_date'                => $service_date,

      ':groom_lastname'              => $groom_lastname,
      ':groom_firstname'             => $groom_firstname,
      ':groom_middlename'            => $groom_middlename,
      ':groom_extension'             => $groom_extension,

      ':bride_lastname'              => $bride_lastname,
      ':bride_firstname'             => $bride_firstname,
      ':bride_middlename'            => $bride_middlename,
      ':bride_extension'             => $bride_extension,

      ':groom_birthdate'             => $groom_birthdate,
      ':groom_church'                => $groom_church,
      ':bride_birthdate'             => $bride_birthdate,
      ':bride_church'                => $bride_church,

      ':contact_number'              => $contact_number,
      ':special_request'             => $special_request,

      ':groom_birth_cert_path'       => $groom_birth_cert_path,
      ':groom_valid_id_path'         => $groom_valid_id_path,
      ':groom_baptismal_cert_path'   => $groom_baptismal_cert_path,

      ':bride_birth_cert_path'       => $bride_birth_cert_path,
      ':bride_valid_id_path'         => $bride_valid_id_path,
      ':bride_baptismal_cert_path'   => $bride_baptismal_cert_path,

      ':bride_cenomar_path'          => $bride_cenomar_path,
      ':groom_cenomar_path'          => $groom_cenomar_path,

      ':groom_widowed_file'          => $groom_widowed_file_path,
      ':bride_widowed_file'          => $bride_widowed_file_path,
    ]);

    // Success screen (MODIFIED: new SweetAlert asking admin to book again)
    $safeDate = htmlspecialchars($appointment_date, ENT_QUOTES, 'UTF-8');
    $safeTime = htmlspecialchars($appointment_time, ENT_QUOTES, 'UTF-8');
    $safeSvc  = htmlspecialchars($appointment_service, ENT_QUOTES, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Service Scheduled</title>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#fff}</style>
</head><body>
<script>
// service_status is now automatically "Scheduled" in the database.
// This alert replaces the old one and asks the admin if they want to book again.
Swal.fire({
  icon: 'success',
  title: 'Service Scheduled',
  html: `<p>The wedding service has been <b>scheduled successfully</b>.</p>
         <p><b>Details</b><br>
           Date: <?= $safeDate ?><br>
           Time: <?= $safeTime ?><br>
           Service: <?= $safeSvc ?>
         </p>
         <hr style="opacity:0.2;">
         <p>Do you want to book another service?</p>`,
  showCancelButton: true,
  confirmButtonText: 'Yes, book again',
  cancelButtonText: 'No, go to dashboard',
  confirmButtonColor: '#7FC1FF',
  cancelButtonColor: '#6c757d',
  allowOutsideClick: false,
  allowEscapeKey: false
}).then((result) => {
  if (result.isConfirmed) {
    // Admin wants to book another service
    window.location.href = 'onsite_appointment.php';
  } else {
    // Admin does not want to book again
    window.location.href = 'secretary_dashboard.php';
  }
});
</script>
</body></html>
<?php
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
<title>HTCCC - Wedding Appointment Form</title>
<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<!-- Bootstrap -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
input[type="text"],input[type="date"],textarea,select,.file-input {
  width:100%; border-radius:8px; padding:8px 12px; font-weight:600; font-size:.98rem; color:#1f2937; border:none; outline:none; background:var(--field);
}
.as-bs.form-control { background:var(--field); border:1px solid #ced4da; border-radius:.5rem; font-weight:600; }
.as-bs.form-control:focus { border-color:#86b7fe; box-shadow:0 0 0 .25rem rgba(13,110,253,.25); }
textarea.as-bs { resize:vertical; }
.file-input.as-bs { border:1px solid #ced4da; }
.file-input.as-bs::-webkit-file-upload-button { margin-right:8px; }
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

/* Locked badge + read-only look */
.locked-badge{display:inline-block;margin-left:8px;background:#17345c;color:#dceaff;border:1px solid #315d93;border-radius:999px;padding:2px 8px;font-size:.75rem;font-weight:800;vertical-align:middle}
.readonly-look{background:#e9ecef !important; color:#6c757d !important; cursor:not-allowed}

/* Inline widowed UI */
.inline-widow-wrap { margin-top:10px; }
.hidden-htccc { display:none !important; }

/* ===== ADD BELOW THIS LINE: Widowed heading style to match section labeling ===== */
.widowed-heading{
  font-weight:800; color:#dbe7ff; margin-top:8px; margin-bottom:6px;
  font-size:1rem; line-height:1.2;
}
/* ===== END ADD ===== */

/* ===== ADD BELOW THIS LINE: tiny help text under file labels ===== */
.label-who-must {
  display:block; margin-top:2px; font-size:.8rem; color:#bcd7ff; opacity:.95;
}
/* ===== END ADD ===== */
</style>
</head>
<body>
<nav class="w-100">
  <a href="onsite_appointment.php" class="d-inline-flex align-items-center gap-2">
    <img src="image/btn-back.png" alt="Back"><span class="fw-bold">Back</span>
  </a>
  <div class="d-flex align-items-center gap-2">
    <img src="image/httc_main-logo.jpg" alt="Wedding logo" style="width:50px; height:50px; border-radius:50%;">
    <h1 class="m-0 text-center text-uppercase">WEDDING APPOINTMENT FORM</h1>
  </div>
  <div style="width:30px;"></div>
</nav>

<main class="container py-3 py-md-4">
  <div class="top-sections row g-3 g-md-4">
    <div class="col-12 col-md-6 col-lg-5">
      <section class="border-box h-100">
        <h2>What to Bring?</h2>
        <ul class="mb-0">
          <li>Birth Certificate of the Groom and Bride</li>
          <li>1 Valid ID each</li>
          <li>Baptismal Certificate (If Baptized)</li>
          <li>CENOMAR (Certificate of No Marriage)</li>
        </ul>
      </section>
    </div>
    <div class="col-12 col-md-6 col-lg-5">
      <section class="border-box h-100">
        <h2>What to Expect?</h2>
        <ul class="mb-0">
          <li>Electricity Fee (if inside the Church)</li>
          <li>Gas Fee for the Pastor</li>
          <li>Offerings are Optional but Encouraged</li>
        </ul>
      </section>
    </div>
  </div>

  <div class="summary px-3 py-2">
    <?php
      $safeDate = htmlspecialchars($selDate ?: '—', ENT_QUOTES, 'UTF-8');
      $safeTime = htmlspecialchars($selTime ?: '—', ENT_QUOTES, 'UTF-8');
      $safeSvc  = htmlspecialchars($selService ?: 'WEDDING', ENT_QUOTES, 'UTF-8');
      echo "Selected Date: <b>{$safeDate}</b> &nbsp; | &nbsp; Time: <b>{$safeTime}</b> &nbsp; | &nbsp; Service: <b>{$safeSvc}</b>";
    ?>
  </div>

  <!-- SINGLE form; enctype for file uploads -->
  <form id="weddingForm" action="" method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
    <div class="form-grid">
      <div class="row g-3 g-md-4">

        <!-- Groom -->
        <div class="col-12 col-lg-4">
          <div class="form-card">
            <h3>Groom Information</h3>

            <div class="name-breakdown-hint">
              Enter in this order: <b>Last Name, First Name, Middle Name (optional), Extension (optional)</b>.
            </div>

            <div class="name-breakdown-grid">
              <div>
                <label for="gr_last">Last Name <span class="text-danger">*</span></label>
                <input type="text" id="gr_last" name="groom_lastname" class="form-control as-bs" required>
              </div>
              <div>
                <label for="gr_first">First Name <span class="text-danger">*</span></label>
                <input type="text" id="gr_first" name="groom_firstname" class="form-control as-bs" required>
              </div>
              <div>
                <label for="gr_middle">Middle Name <span class="text-light opacity-75">(optional)</span></label>
                <input type="text" id="gr_middle" name="groom_middlename" class="form-control as-bs">
              </div>
              <div>
                <label for="gr_ext">Extension <span class="text-light opacity-75">(optional)</span></label>
                <select id="gr_ext" name="groom_extension" class="form-control as-bs">
                  <option value="">—</option><option>Jr.</option><option>Sr.</option>
                  <option>I</option><option>II</option><option>III</option><option>IV</option><option>V</option>
                </select>
              </div>
            </div>

            <div class="name-preview">Preview: <span id="gr_preview">—</span></div>

            <label for="groom_birthdate" class="mt-2">Groom’s Birthdate <span class="text-danger">*</span></label>
            <input type="date" id="groom_birthdate" name="groom_birthdate" class="form-control as-bs" required />

            <label for="groom_church" class="mt-2">Groom’s Church Membership <span>(optional)</span></label>
            <input type="text" id="groom_church" name="groom_church" class="form-control as-bs" />

            <label for="groom_birth_cert" class="mt-2">Upload Birth Certificate <span class="text-danger">*</span></label>
            <input class="file-input as-bs form-control" type="file" id="groom_birth_cert" name="groom_birth_cert" accept=".pdf,.jpg,.jpeg,.png" required />

            <label for="groom_valid_id" class="mt-2">Upload Valid ID <span class="text-danger">*</span></label>
            <input class="file-input as-bs form-control" type="file" id="groom_valid_id" name="groom_valid_id" accept=".pdf,.jpg,.jpeg,.png" required />

            <label for="groom_baptismal_cert" class="mt-2">Upload Baptismal Certificate (optional)</label>
            <input class="file-input as-bs form-control" type="file" id="groom_baptismal_cert" name="groom_baptismal_cert" accept=".jpg,.jpeg,.png" />

            <!-- NEW: Groom widowed toggle + file (auto-managed/hidden by JS) -->
            <div class="inline-widow-wrap mt-3">
              <div class="form-check hidden-htccc"><!-- hidden; JS manages -->
                <input class="form-check-input" type="checkbox" id="groom_widowed_toggle">
                <label class="form-check-label" for="groom_widowed_toggle">Groom is Widowed — upload death certificate</label>
              </div>
              <input type="hidden" name="groom_widowed_enabled" id="groom_widowed_enabled" value="0">
              <div id="groom_widow_row" class="hidden-htccc mt-2">
                <input class="file-input as-bs form-control" type="file" id="groom_widowed_file" name="groom_widowed_file" accept=".pdf,.jpg,.jpeg,.png" />
              </div>
            </div>
          </div>
        </div>

        <!-- Bride -->
        <div class="col-12 col-lg-4">
          <div class="form-card">
            <h3>Bride Information</h3>

            <div class="name-breakdown-hint">
              Enter in this order: <b>Last Name, First Name, Middle Name (optional), Extension (optional)</b>.
            </div>

            <div class="name-breakdown-grid">
              <div>
                <label for="br_last">Last Name <span class="text-danger">*</span></label>
                <input type="text" id="br_last" name="bride_lastname" class="form-control as-bs" required>
              </div>
              <div>
                <label for="br_first">First Name <span class="text-danger">*</span></label>
                <input type="text" id="br_first" name="bride_firstname" class="form-control as-bs" required>
              </div>
              <div>
                <label for="br_middle">Middle Name <span class="text-light opacity-75">(optional)</span></label>
                <input type="text" id="br_middle" name="bride_middlename" class="form-control as-bs">
              </div>
              <div>
                <label for="br_ext">Extension <span class="text-light opacity-75">(optional)</span></label>
                <select id="br_ext" name="bride_extension" class="form-control as-bs">
                  <option value="">—</option><option>Jr.</option><option>Sr.</option>
                  <option>I</option><option>II</option><option>III</option><option>IV</option><option>V</option>
                </select>
              </div>
            </div>

            <div class="name-preview">Preview: <span id="br_preview">—</span></div>

            <label for="bride_birthdate" class="mt-2">Bride’s Birthdate <span class="text-danger">*</span></label>
            <input type="date" id="bride_birthdate" name="bride_birthdate" class="form-control as-bs" required />

            <label for="bride_church" class="mt-2">Bride’s Church Membership <span>(optional)</span></label>
            <input type="text" id="bride_church" name="bride_church" class="form-control as-bs" />

            <label for="bride_birth_cert" class="mt-2">Upload Birth Certificate <span class="text-danger">*</span></label>
            <input class="file-input as-bs form-control" type="file" id="bride_birth_cert" name="bride_birth_cert" accept=".pdf,.jpg,.jpeg,.png" required />

            <label for="bride_valid_id" class="mt-2">Upload Valid ID <span class="text-danger">*</span></label>
            <input class="file-input as-bs form-control" type="file" id="bride_valid_id" name="bride_valid_id" accept=".pdf,.jpg,.jpeg,.png" required />

            <label for="bride_baptismal_cert" class="mt-2">Upload Baptismal Certificate (optional)</label>
            <input class="file-input as-bs form-control" type="file" id="bride_baptismal_cert" name="bride_baptismal_cert" accept=".jpg,.jpeg,.png" />

            <!-- NEW: Bride widowed toggle + file (auto-managed/hidden by JS) -->
            <div class="inline-widow-wrap mt-3">
              <div class="form-check hidden-htccc"><!-- hidden; JS manages -->
                <input class="form-check-input" type="checkbox" id="bride_widowed_toggle">
                <label class="form-check-label" for="bride_widowed_toggle">Bride is Widowed — upload death certificate</label>
              </div>
              <input type="hidden" name="bride_widowed_enabled" id="bride_widowed_enabled" value="0">
              <div id="bride_widow_row" class="hidden-htccc mt-2">
                <input class="file-input as-bs form-control" type="file" id="bride_widowed_file" name="bride_widowed_file" accept=".pdf,.jpg,.jpeg,.png" />
              </div>
            </div>
          </div>
        </div>

        <!-- Additional -->
        <div class="col-12 col-lg-4">
          <div class="form-card">
            <h3>Additional</h3>

            <label for="service_date" class="mt-0">Service Date <span class="text-danger">*</span></label>
            <div class="d-flex gap-2">
              <!-- ADD: default value to avoid blank submissions -->
              <input type="text" id="service_date" name="service_date" class="form-control as-bs" placeholder="Select service date" readonly required
                     value="<?php echo htmlspecialchars($selDate, ENT_QUOTES, 'UTF-8'); ?>" />
              <button type="button" id="openServiceCalendar" class="btn btn-primary-ht px-3">Pick</button>
            </div>
            <div class="form-text text-light" style="opacity:.8">Click the field or “Pick” to open the calendar.</div>

            <!-- ========== ADD BELOW THIS LINE: Partner Civil Status selector ========== -->
            <label for="partner_status" class="mt-3">Partner Civil Status <span class="text-danger">*</span></label>
            <select id="partner_status" name="partner_status" class="form-control as-bs" required>
              <option value="">— Select —</option>
              <option value="Single">Single</option>
              <option value="Widowed">Widowed</option>
            </select>
            <div class="form-text text-light" style="opacity:.8">
              This sets which document your partner must upload (CENOMAR vs Widowed document).
            </div>
            <!-- ========== END ADD ========== -->

            <label for="special_request" class="mt-3">Special Request or Message (optional)</label>
            <textarea id="special_request" name="special_request" rows="4" placeholder="Share any details or requests for the ceremony" class="form-control as-bs"></textarea>

            <!-- These two inputs will be shown/hidden by JS depending on civil status for each side -->
            <label for="bride_cenomar" class="mt-2" id="label_bride_cenomar">Upload (Bride) CENOMAR <span class="text-danger">*</span></label>
            <input class="file-input as-bs form-control" type="file" id="bride_cenomar" name="bride_cenomar" accept=".pdf,.jpg,.jpeg,.png" required />

            <label for="groom_cenomar" class="mt-2" id="label_groom_cenomar">Upload (Groom) CENOMAR <span class="text-danger">*</span></label>
            <input class="file-input as-bs form-control" type="file" id="groom_cenomar" name="groom_cenomar" accept=".pdf,.jpg,.jpeg,.png" required />

            <!-- ===== ADD BELOW THIS LINE: Widowed upload heading + placeholders in the same area ===== -->
            <div id="widowed_heading" class="widowed-heading hidden-htccc">Widowed File Upload</div>
            <div id="bride_widow_placeholder" class="hidden-htccc"></div>
            <div id="groom_widow_placeholder" class="hidden-htccc"></div>
            <!-- ===== END ADD ===== -->

          </div>
        </div>

      </div>

      <div class="row mt-3">
        <div class="col-12">
          <div class="consent-row">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="consent_inline" required>
              <label class="form-check-label" for="consent_inline">
                I agree to the collection and processing of my personal data for wedding appointment purposes in accordance with the Data Privacy Act of 2012 (RA 10173) and
                <a href="#" id="viewDpaLink" class="text-decoration-underline">HTCCC’s Privacy Notice</a>.
              </label>
            </div>
          </div>
        </div>
      </div>

      <!-- Hidden context -->
      <input type="hidden" name="individual_id" value="<?php echo (int)$individualId; ?>">
      <input type="hidden" name="appointment_date" value="<?php echo htmlspecialchars($selDate, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="appointment_time" value="<?php echo htmlspecialchars($selTime, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="appointment_service" value="<?php echo htmlspecialchars($selService ?: 'WEDDING', ENT_QUOTES, 'UTF-8'); ?>">

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
      <p><strong>What we collect:</strong> Information you provide in this form (e.g., names, birthdates, contact details, IDs and certificates).</p>
      <p><strong>Purpose:</strong> To process and manage your wedding appointment, verify eligibility, coordinate schedules, and comply with church and legal requirements.</p>
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

<!-- QUOTA LIMIT MODAL -->
<div class="modal fade" id="quotaLimitModal" tabindex="-1" aria-labelledby="quotaLimitLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content" style="border:2px solid var(--border); background:#0e1330; color:#fff;">
      <div class="modal-header" style="background:var(--ink);">
        <h5 class="modal-title fw-bold" id="quotaLimitLabel">Appointment Limit Reached</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2"><b>You have exceeded the appointment request.</b></p>
        <p class="mb-2">Please wait until your appointments are processed so you can appoint again.</p>
      </div>
      <div class="modal-footer" style="background:rgba(255,255,255,0.04);">
        <a href="main-page.php" class="btn btn-secondary">Go to main page</a>
        <button type="button" class="btn btn-primary" style="background:var(--accent); color:#000; font-weight:800;" data-bs-dismiss="modal">Okay</button>
      </div>
    </div>
  </div>
</div>

<!-- =============================== -->
<!-- ADD BELOW THIS LINE: REVIEW MODAL -->
<!-- =============================== -->
<style>
/* Internal CSS for Review Modal (add-only) */
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
        <h5 class="modal-title fw-bold" id="reviewLabel">Review &amp; Confirm Your Wedding Appointment</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="section-title">Appointment</div>
        <table class="table table-sm table-bordered table-review align-middle">
          <tbody>
            <tr><th>Date</th><td id="rev_date">—</td></tr>
            <tr><th>Time</th><td id="rev_time">—</td></tr>
            <tr><th>Service</th><td id="rev_service">—</td></tr>
            <tr><th>Service Date</th><td><span id="service_date_review_span"></span></td></tr>
            <tr><th>Groom Widow Document</th><td id="rev_groom_widowed_file">—</td></tr>
            <tr><th>Bride Widow Document</th><td id="rev_bride_widowed_file">—</td></tr>
          </tbody>
        </table>

        <div class="section-title">Groom</div>
        <table class="table table-sm table-bordered table-review align-middle">
          <tbody>
            <tr><th>Full Name</th><td id="rev_groom_name">—</td></tr>
            <tr><th>Birthdate</th><td id="rev_groom_birthdate">—</td></tr>
            <tr><th>Church</th><td id="rev_groom_church">—</td></tr>
            <tr><th>Birth Certificate</th><td id="rev_groom_birth_cert">—</td></tr>
            <tr><th>Valid ID</th><td id="rev_groom_valid_id">—</td></tr>
            <tr><th>Baptismal Certificate</th><td id="rev_groom_baptismal_cert">—</td></tr>
            <tr><th>CENOMAR</th><td id="rev_groom_cenomar">—</td></tr>
          </tbody>
        </table>

        <div class="section-title">Bride</div>
        <table class="table table-sm table-bordered table-review align-middle">
          <tbody>
            <tr><th>Full Name</th><td id="rev_bride_name">—</td></tr>
            <tr><th>Birthdate</th><td id="rev_bride_birthdate">—</td></tr>
            <tr><th>Church</th><td id="rev_bride_church">—</td></tr>
            <tr><th>Birth Certificate</th><td id="rev_bride_birth_cert">—</td></tr>
            <tr><th>Valid ID</th><td id="rev_bride_valid_id">—</td></tr>
            <tr><th>Baptismal Certificate</th><td id="rev_bride_baptismal_cert">—</td></tr>
            <tr><th>CENOMAR</th><td id="rev_bride_cenomar">—</td></tr>
          </tbody>
        </table>

        <div class="section-title">Notes</div>
        <table class="table table-sm table-bordered table-review align-middle">
          <tbody>
            <tr><th>Special Request / Message</th><td id="rev_special_request">—</td></tr>
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
<!-- END: REVIEW MODAL -->

<!-- Smart upload filter -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  const fileRules = {
    'groom_birth_cert': ['jpg','jpeg','png','pdf'],
    'bride_birth_cert': ['jpg','jpeg','png','pdf'],
    'groom_valid_id': ['jpg','jpeg','png','pdf'],
    'bride_valid_id': ['jpg','jpeg','png','pdf'],
    'groom_baptismal_cert': ['jpg','jpeg','png'],
    'bride_baptismal_cert': ['jpg','jpeg','png'],
    'bride_cenomar': ['jpg','jpeg','png','pdf'],
    'groom_cenomar': ['jpg','jpeg','png','pdf'],
    // NEW:
    'groom_widowed_file': ['jpg','jpeg','png','pdf'],
    'bride_widowed_file': ['jpg','jpeg','png','pdf']
  };
  const mimeFor = { jpg:'image/jpeg', jpeg:'image/jpeg', png:'image/png', pdf:'application/pdf' };

  const form = document.querySelector('#weddingForm'); if (!form) return;

  const alertBox = document.createElement('div');
  alertBox.className = 'alert alert-danger alert-dismissible fade show mt-3 d-none';
  alertBox.setAttribute('role','alert');
  alertBox.innerHTML = `<strong>Invalid file type!</strong> Please upload only the allowed formats for each document.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
  form.prepend(alertBox);
  alertBox.addEventListener('closed.bs.alert', () => alertBox.classList.add('d-none'));
  function showAlert(msg) {
    if (msg) {
      alertBox.innerHTML = `<strong>Invalid file type!</strong> ${msg}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
    }
    alertBox.classList.remove('d-none');
    window.scrollTo({ top: alertBox.offsetTop - 80, behavior: 'smooth' });
  }

  Object.keys(fileRules).forEach(id => {
    const el = document.getElementById(id); if (!el) return;
    const exts = fileRules[id];
    const accepts = exts.map(x => `.${x}`).concat(exts.map(x => mimeFor[x] || '')).filter(Boolean);
    el.setAttribute('accept', accepts.join(','));
  });

  form.querySelectorAll('input[type="file"]').forEach(input => {
    input.addEventListener('change', function(e) {
      const field = e.target.id;
      const files = e.target.files;
      const allowedExts = fileRules[field] || [];
      const allowedMIMEs = new Set(allowedExts.map(x => mimeFor[x]).filter(Boolean));
      for (let i = 0; i < files.length; i++) {
        const f = files[i];
        const ext = (f.name.split('.').pop() || '').toLowerCase();
        const type = (f.type || '').toLowerCase();
        const extOk  = allowedExts.includes(ext);
        const mimeOk = allowedMIMEs.size ? allowedMIMEs.has(type) : true;
        if (!extOk || !mimeOk) {
          e.target.value = '';
          const nice = allowedExts.length ? allowedExts.map(x => x.toUpperCase()).join(', ') : 'NO FILES ALLOWED';
          showAlert(`Please upload only ${nice} for this field.`);
          return;
        }
      }
    });
  });

  // Keep Service Date also visible in the review modal
  const serviceDateInput = document.getElementById('service_date');
  const serviceDateSpan  = document.getElementById('service_date_review_span');
  if (serviceDateInput && serviceDateSpan) {
    const sync = () => { serviceDateSpan.textContent = serviceDateInput.value || '—'; };
    serviceDateInput.addEventListener('input', sync);
    serviceDateInput.addEventListener('change', sync);
    sync();
  }

  // NEW: Toggle show/hide widow inputs + required + hidden flags
  function bindWidowToggle(toggleId, rowId, hiddenId, fileId) {
    const t = document.getElementById(toggleId);
    const r = document.getElementById(rowId);
    const h = document.getElementById(hiddenId);
    const f = document.getElementById(fileId);
    if (!t || !r || !h || !f) return;
    function apply() {
      const on = !!t.checked;
      r.classList.toggle('hidden-htccc', !on);
      h.value = on ? '1' : '0';
      if (on) { f.setAttribute('required', 'required'); }
      else { f.removeAttribute('required'); f.value=''; }
    }
    t.addEventListener('change', apply);
    apply();
  }
  bindWidowToggle('groom_widowed_toggle','groom_widow_row','groom_widowed_enabled','groom_widowed_file');
  bindWidowToggle('bride_widowed_toggle','bride_widow_row','bride_widowed_enabled','bride_widowed_file');

  // ========== ADD BELOW THIS LINE: Auto-control CENOMAR vs Widowed based on statuses ==========
  const P   = window.__PROFILE__ || null;
  const MY_STATUS = (window.__CIVIL_STATUS__ || '').toString().trim();
  const IS_WIDOWED = !!window.__IS_WIDOWED__;

  const partnerSelect = document.getElementById('partner_status');

  const groomCeno = document.getElementById('groom_cenomar');
  const brideCeno = document.getElementById('bride_cenomar');
  const lblGroomC = document.getElementById('label_groom_cenomar');
  const lblBrideC = document.getElementById('label_bride_cenomar');

  const groomWidowToggle = document.getElementById('groom_widowed_toggle');
  const brideWidowToggle = document.getElementById('bride_widowed_toggle');

  const groomWidowRow  = document.getElementById('groom_widow_row');
  const brideWidowRow  = document.getElementById('bride_widow_row');

  const groomWidowFile = document.getElementById('groom_widowed_file');
  const brideWidowFile = document.getElementById('bride_widowed_file');

  function requireOn(el, lbl) { if (!el) return; el.setAttribute('required','required'); }
  function requireOff(el)     { if (!el) return; el.removeAttribute('required'); el.value=''; }

  function hide(el, on=true){ if(!el) return; el.classList.toggle('hidden-htccc', on); }
  function show(el){ hide(el,false); }

  // *** UPDATED: dynamically change label text & swap visible control ***
  function setGroomWidowed(on){
    groomWidowToggle.checked = !!on; 
    groomWidowToggle.dispatchEvent(new Event('change')); // sets required on widow file when ON

    if (on) {
      // Switch label to Widowed File Upload and show widow file while hiding CENOMAR
      if (lblGroomC) lblGroomC.innerHTML = 'Widowed File Upload <span class="text-danger">*</span>';
      requireOff(groomCeno);
      hide(groomCeno, true);
      show(groomWidowRow);
    } else {
      // Restore CENOMAR label and hide widowed file row
      if (lblGroomC) lblGroomC.innerHTML = 'Upload (Groom) CENOMAR <span class="text-danger">*</span>';
      requireOn(groomCeno, lblGroomC);
      hide(groomCeno, false);
      hide(groomWidowRow, true);
      if (groomWidowFile) groomWidowFile.value=''; 
    }
  }

  function setBrideWidowed(on){
    brideWidowToggle.checked = !!on; 
    brideWidowToggle.dispatchEvent(new Event('change'));

    if (on) {
      if (lblBrideC) lblBrideC.innerHTML = 'Widowed File Upload <span class="text-danger">*</span>';
      requireOff(brideCeno);
      hide(brideCeno, true);
      show(brideWidowRow);
    } else {
      if (lblBrideC) lblBrideC.innerHTML = 'Upload (Bride) CENOMAR <span class="text-danger">*</span>';
      requireOn(brideCeno, lblBrideC);
      hide(brideCeno, false);
      hide(brideWidowRow, true);
      if (brideWidowFile) brideWidowFile.value=''; 
    }
  }

  function applyInitialFromAccount(){
    const gender = (P && P.gender) ? P.gender : null;
    if (gender === 'Male') {
      // Logged-in is Groom
      setGroomWidowed(IS_WIDOWED);
    } else if (gender === 'Female') {
      // Logged-in is Bride
      setBrideWidowed(IS_WIDOWED);
    } else {
      // Unknown gender: don't force either side (defaults to CENOMAR)
      setGroomWidowed(false);
      setBrideWidowed(false);
    }
  }

  function applyPartnerChoice(){
    const gender = (P && P.gender) ? P.gender : null;
    const choice = (partnerSelect && partnerSelect.value) ? partnerSelect.value : '';
    if (!choice) return;

    const partnerIsWidowed = (choice === 'Widowed');

    if (gender === 'Male') {
      // Partner is Bride — show Bride widowed upload in same area
      setBrideWidowed(partnerIsWidowed);
    } else if (gender === 'Female') {
      // Partner is Groom — show Groom widowed upload in same area
      setGroomWidowed(partnerIsWidowed);
    } else {
      // Unknown gender: requirement says default back to CENOMAR
      setGroomWidowed(false);
      setBrideWidowed(false);
    }
  }

  // Initialize
  applyInitialFromAccount();
  partnerSelect && partnerSelect.addEventListener('change', applyPartnerChoice);

  // ========== END ADD ==========
});
</script>

<!-- CONSOLIDATED DPA SHOW-ONCE + link trigger -->
<style id="dpaOnceCSS">.dpa-backdrop{display:none}</style>
<script>
(function(){
  const DPA_GATE_KEY = 'htccc_dpa_gate_wedding_v2';
  const backdrop    = document.getElementById('dpaBackdrop');
  const btnAgree    = document.getElementById('dpaAgree');
  const btnCancel   = document.getElementById('dpaCancel');
  const btnCloseX   = document.getElementById('dpaCloseX');
  const linkViewDpa = document.getElementById('viewDpaLink');
  const form        = document.getElementById('weddingForm');
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

<!-- Occupied Dates bootstrapping (PHP -> JS) -->
<?php
try {
  $pdoShow = new PDO("mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4","root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $CLOSED = ['Approved','Declined','Cancelled','Archived','Done'];
  $ph = implode(',', array_fill(0, count($CLOSED), '?'));
  $q = $pdoShow->prepare("
    SELECT DISTINCT COALESCE(service_date, appointment_date) AS d
    FROM service_wedding
    WHERE (status IS NULL OR status NOT IN ($ph))
      AND COALESCE(service_date, appointment_date) IS NOT NULL
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

<!-- Inject profile into JS for UI auto-fill & locking -->
<script>window.__PROFILE__ = <?php echo json_encode($__PROFILE, JSON_UNESCAPED_SLASHES); ?>;</script>

<!-- ===== ADD BELOW THIS LINE: expose civil status + DB compare flag to JS (add-only) ===== -->
<script>
  window.__CIVIL_STATUS__ = <?php echo json_encode($__CIVIL_STATUS, JSON_UNESCAPED_SLASHES); ?>;
  window.__IS_WIDOWED__   = <?php echo json_encode($__IS_WIDOWED,   JSON_UNESCAPED_SLASHES); ?>;
</script>
<!-- ===== END ADD ===== -->

<script>
(function(){
  const P = window.__PROFILE__ || null;
  if (!P || !P.gender) return;

  function id(x){ return document.getElementById(x); }

  const gr_last=id('gr_last'), gr_first=id('gr_first'), gr_middle=id('gr_middle'), gr_ext=id('gr_ext'),
        groom_birthdate=id('groom_birthdate'), groom_church=id('groom_church');
  const br_last=id('br_last'), br_first=id('br_first'), br_middle=id('br_middle'), br_ext=id('br_ext'),
        bride_birthdate=id('bride_birthdate'), bride_church=id('bride_church');

  function lock(el){
    if(!el) return;
    if(el.tagName==='SELECT'){ el.setAttribute('tabindex','-1'); el.style.pointerEvents='none'; el.classList.add('readonly-look'); }
    else { el.readOnly=true; el.classList.add('readonly-look'); }
  }
  function badge(i){
    const h=document.querySelectorAll('.form-card h3')[i];
    if(h && !h.querySelector('.locked-badge')){
      const b=document.createElement('span');
      b.className='locked-badge';
      b.textContent='Auto-filled from your account';
      h.appendChild(b);
    }
  }

  if (P.gender === 'Male') {
    gr_last.value=P.last||''; gr_first.value=P.first||''; gr_middle.value=P.middle||''; gr_ext.value=P.suffix||''; 
    groom_birthdate.value=P.birth||''; groom_church.value=P.church||'';
    [gr_last,gr_first,gr_middle,gr_ext,groom_birthdate,groom_church].forEach(lock); badge(0);
  } else if (P.gender === 'Female') {
    br_last.value=P.last||''; br_first.value=P.first||''; br_middle.value=P.middle||''; br_ext.value=P.suffix||''; 
    bride_birthdate.value=P.birth||''; bride_church.value=P.church||'';
    [br_last,br_first,br_middle,br_ext,bride_birthdate,bride_church].forEach(lock); badge(1);
  }
})();
</script>

<!-- Name Preview only (for the review modal display) -->
<script>
(function(){
  const grLast = document.getElementById('gr_last');
  const grFirst= document.getElementById('gr_first');
  const grMid  = document.getElementById('gr_middle');
  const grExt  = document.getElementById('gr_ext');
  const grPrev = document.getElementById('gr_preview');

  const brLast = document.getElementById('br_last');
  const brFirst= document.getElementById('br_first');
  const brMid  = document.getElementById('br_middle');
  const brExt  = document.getElementById('br_ext');
  const brPrev = document.getElementById('br_preview');

  function val(x){ return (x && typeof x.value === 'string') ? x.value.trim() : ''; }
  function titlecaseWords(s){ return (s||'').toString().replace(/\s+/g,' ').trim().replace(/\b([A-Za-z])([A-Za-z]*)/g,(_,a,b)=>a.toUpperCase()+b.toLowerCase()); }
  function compose(ln, fn, mn, ex){
    const after = [titlecaseWords(fn), titlecaseWords(mn)].filter(Boolean).join(' ');
    const suffix = ex ? (' ' + ex) : '';
    return (titlecaseWords(ln)||'') + (after?(', '+after):'') + suffix;
  }
  function update(){
    grPrev.textContent = compose(val(grLast), val(grFirst), val(grMid), val(grExt)) || '—';
    brPrev.textContent = compose(val(brLast), val(brFirst), val(brMid), val(brExt)) || '—';
  }
  ['input','change'].forEach(ev => {
    [grLast,grFirst,grMid,grExt,brLast,brFirst,brMid,brExt].forEach(el => el && el.addEventListener(ev, update));
  });
  update();
})();
</script>

<!-- REVIEW & QUOTA JS -->
<script>
(function(){
  const form = document.getElementById('weddingForm');
  const submitBtn = form ? form.querySelector('.actions button[type="submit"]') : null;
  const confirmBtn = document.getElementById('confirmSubmitBtn');
  const reviewModalEl = document.getElementById('reviewModal');
  const quotaFlag = <?= json_encode(isset($_GET['quota']) && $_GET['quota'] === '1') ?>;

  function id(x){ return document.getElementById(x); }

  const fields = form ? {
    gr_last: id('gr_last'),
    gr_first: id('gr_first'),
    gr_middle: id('gr_middle'),
    gr_ext: id('gr_ext'),
    br_last: id('br_last'),
    br_first: id('br_first'),
    br_middle: id('br_middle'),
    br_ext: id('br_ext'),
    groom_birthdate: id('groom_birthdate'),
    groom_church: id('groom_church'),
    bride_birthdate: id('bride_birthdate'),
    bride_church: id('bride_church'),
    special_request: id('special_request'),
    appointment_date: form.querySelector('input[name="appointment_date"]'),
    appointment_time: form.querySelector('input[name="appointment_time"]'),
    appointment_service: form.querySelector('input[name="appointment_service"]'),
    service_date: id('service_date'),
    files: {
      groom_birth_cert: id('groom_birth_cert'),
      groom_valid_id: id('groom_valid_id'),
      groom_baptismal_cert: id('groom_baptismal_cert'),
      groom_cenomar: id('groom_cenomar'),
      bride_birth_cert: id('bride_birth_cert'),
      bride_valid_id: id('bride_valid_id'),
      bride_baptismal_cert: id('bride_baptismal_cert'),
      bride_cenomar: id('bride_cenomar'),
      groom_widowed_file: id('groom_widowed_file'),
      bride_widowed_file: id('bride_widowed_file')
    },
    consent_inline: id('consent_inline')
  } : {}

  function setText(id, val){ const el=document.getElementById(id); if(el) el.textContent=(val&&String(val).trim()!=='')?String(val).trim():'—'; }
  function fileNameOf(input){ const f = input && input.files && input.files[0] ? input.files[0].name : ''; return f || '—'; }
  function titlecaseWords(s){ return (s||'').toString().replace(/\s+/g,' ').trim().replace(/\b([A-Za-z])([A-Za-z]*)/g,(_,a,b)=>a.toUpperCase()+b.toLowerCase()); }
  function composeDisplay(ln, fn, mn, ex){ const after=[titlecaseWords(fn),titlecaseWords(mn)].filter(Boolean).join(' '); const sx=ex?(' '+ex):''; return (titlecaseWords(ln)||'')+(after?(', '+after):'')+sx; }

  function populateReview(){
    setText('rev_date', fields.appointment_date.value);
    setText('rev_time', fields.appointment_time.value);
    setText('rev_service', fields.appointment_service.value);

    setText('rev_groom_name', composeDisplay(fields.gr_last.value, fields.gr_first.value, fields.gr_middle.value, fields.gr_ext.value));
    setText('rev_bride_name', composeDisplay(fields.br_last.value, fields.br_first.value, fields.br_middle.value, fields.br_ext.value));

    setText('rev_groom_birthdate', fields.groom_birthdate.value);
    setText('rev_groom_church', fields.groom_church.value);
    setText('rev_groom_birth_cert', fileNameOf(fields.files.groom_birth_cert));
    setText('rev_groom_valid_id', fileNameOf(fields.files.groom_valid_id));
    setText('rev_groom_baptismal_cert', fileNameOf(fields.files.groom_baptismal_cert));
    setText('rev_groom_cenomar', fileNameOf(fields.files.groom_cenomar));

    setText('rev_bride_birthdate', fields.bride_birthdate.value);
    setText('rev_bride_church', fields.bride_church.value);
    setText('rev_bride_birth_cert', fileNameOf(fields.files.bride_birth_cert));
    setText('rev_bride_valid_id', fileNameOf(fields.files.bride_valid_id));
    setText('rev_bride_baptismal_cert', fileNameOf(fields.files.bride_baptismal_cert));
    setText('rev_bride_cenomar', fileNameOf(fields.files.bride_cenomar));

    setText('rev_special_request', fields.special_request.value);

    // Also mirror service_date into the small span inside the modal if present
    const sdr = document.getElementById('service_date_review_span');
    if (sdr) sdr.textContent = (fields.service_date && fields.service_date.value) ? fields.service_date.value : '—';

    // Side-specific widow docs
    setText('rev_groom_widowed_file', fileNameOf(fields.files.groom_widowed_file));
    setText('rev_bride_widowed_file', fileNameOf(fields.files.bride_widowed_file));
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
      e.preventDefault(); e.stopPropagation();
      populateReview();
      const m = ensureModal(); if (m) m.show();
    });
  }
  if (confirmBtn && form) {
    confirmBtn.addEventListener('click', function(){
      confirmBtn.disabled = true;
      confirmBtn.textContent = 'Submitting...';
      const marker = document.createElement('input');
      marker.type='hidden'; marker.name='review_confirmed'; marker.value='1';
      form.appendChild(marker);
      form.submit();
    });
  }

  if (quotaFlag) {
    document.addEventListener('DOMContentLoaded', function(){
      try {
        const mEl = document.getElementById('quotaLimitModal');
        if (mEl && window.bootstrap && bootstrap.Modal) {
          new bootstrap.Modal(mEl, {backdrop:'static', keyboard:false}).show();
        }
      } catch(e){}
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
  function apply(){ if(pendingPick){ input.value = pendingPick; input.dispatchEvent(new Event('input')); input.dispatchEvent(new Event('change')); } closeCal(); }

  function render(){
    const s=startOfMonth(cursor), e=endOfMonth(cursor);
    title.textContent = s.toLocaleString(undefined, {month:'long', year:'numeric'});
    grid.innerHTML = '';
    for(let i=0;i<s.getDay();i++){ const c=document.createElement('div'); c.className='sd-cell disabled'; grid.appendChild(c); }

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

<script>
(function(){
  // DOM targets in the Additional (3rd) column
  const lblBrideC = document.getElementById('label_bride_cenomar');
  const lblGroomC = document.getElementById('label_groom_cenomar');
  const brideCeno = document.getElementById('bride_cenomar');
  const groomCeno = document.getElementById('groom_cenomar');

  // Placeholders where we will SHOW the widowed file inputs (so they appear in the 3rd column)
  const bridePH  = document.getElementById('bride_widow_placeholder');
  const groomPH  = document.getElementById('groom_widow_placeholder');

  // Original widowed inputs (currently live inside Groom/Bride cards)
  const brideRow = document.getElementById('bride_widow_row');
  const groomRow = document.getElementById('groom_widow_row');
  const brideWid = document.getElementById('bride_widowed_file');
  const groomWid = document.getElementById('groom_widowed_file');

  // Hidden flags used by server
  const brideFlag = document.getElementById('bride_widowed_enabled');
  const groomFlag = document.getElementById('groom_widowed_enabled');

  // Partner selector + profile flags
  const partnerSelect = document.getElementById('partner_status');
  const PROFILE       = window.__PROFILE__ || null;
  const MY_GENDER     = PROFILE && PROFILE.gender ? PROFILE.gender : null;     // 'Male' | 'Female' | null
  const I_AM_WIDOWED  = !!window.__IS_WIDOWED__;                               // DB compare flag
  const widHeading    = document.getElementById('widowed_heading');

  if (!lblBrideC || !lblGroomC || !brideCeno || !groomCeno || !bridePH || !groomPH || !brideWid || !groomWid) return;

  // Move the real file inputs into the 3rd column placeholders (we keep rows hidden so nothing shows at the bottom)
  try {
    if (brideRow) brideRow.classList.add('hidden-htccc');      // keep original container hidden
    if (groomRow) groomRow.classList.add('hidden-htccc');
    bridePH.classList.add('placeholder-widow');
    groomPH.classList.add('placeholder-widow');
    bridePH.appendChild(brideWid);  // physically reparent input to 3rd column
    groomPH.appendChild(groomWid);
  } catch(e){ /* no-op */ }

  function reqOn(el){ el && el.setAttribute('required','required'); }
  function reqOff(el){ if(!el) return; el.removeAttribute('required'); el.value=''; }
  function show(el){ if(!el) return; el.classList.remove('hidden-htccc'); }
  function hide(el){ if(!el) return; el.classList.add('hidden-htccc'); }

  // Compute who is widowed right now, using: my gender + DB flag + partner selection
  function computeWidowedSides(){
    const partnerChoice = partnerSelect && partnerSelect.value ? partnerSelect.value : '';
    const partnerIsWidowed = partnerChoice === 'Widowed';
    let groomWidowedOn = false;
    let brideWidowedOn = false;

    if (MY_GENDER === 'Male') {
      // I'm the groom
      groomWidowedOn = I_AM_WIDOWED;
      brideWidowedOn = partnerIsWidowed;
    } else if (MY_GENDER === 'Female') {
      // I'm the bride
      brideWidowedOn = I_AM_WIDOWED;
      groomWidowedOn = partnerIsWidowed;
    } else {
      // Unknown gender: per requirement, default back to CENOMAR (no widowed UI)
      groomWidowedOn = false;
      brideWidowedOn = false;
    }
    return { groomWidowedOn, brideWidowedOn };
  }

  function applyUI(){
    const { groomWidowedOn, brideWidowedOn } = computeWidowedSides();

    // ----- Groom side -----
    if (groomWidowedOn) {
      if (lblGroomC) lblGroomC.innerHTML = 'Widowed File Upload <span class="text-danger">*</span>';
      reqOff(groomCeno); hide(groomCeno);
      reqOn(groomWid);   show(groomPH);
      if (groomFlag) groomFlag.value = '1';
    } else {
      if (lblGroomC) lblGroomC.innerHTML = 'Upload (Groom) CENOMAR <span class="text-danger">*</span>';
      reqOn(groomCeno);  groomCeno.classList.remove('hidden-htccc');
      reqOff(groomWid);  hide(groomPH);
      if (groomFlag) groomFlag.value = '0';
    }

    // ----- Bride side -----
    if (brideWidowedOn) {
      if (lblBrideC) lblBrideC.innerHTML = 'Widowed File Upload <span class="text-danger">*</span>';
      reqOff(brideCeno); hide(brideCeno);
      reqOn(brideWid);   show(bridePH);
      if (brideFlag) brideFlag.value = '1';
    } else {
      if (lblBrideC) lblBrideC.innerHTML = 'Upload (Bride) CENOMAR <span class="text-danger">*</span>';
      reqOn(brideCeno);  brideCeno.classList.remove('hidden-htccc');
      reqOff(brideWid);  hide(bridePH);
      if (brideFlag) brideFlag.value = '0';
    }

    // Heading visibility: show when ANY widowed side is on
    if (widHeading) {
      if (groomWidowedOn || brideWidowedOn) show(widHeading);
      else hide(widHeading);
    }
  }

  // Initial render + react to changes
  applyUI();
  partnerSelect && partnerSelect.addEventListener('change', applyUI);
})();
</script>

<!-- REMOVED: Widow Upload CTA/Modal and Partner status logic -->

<!-- =========================================== -->
<!-- ADD BELOW THIS LINE: LABEL CLARITY ENHANCER -->
<!-- =========================================== -->
<script>
(function(){
  // This block **adds clarity** to the labels so users instantly see WHO must upload.
  // It does not modify previous logic; it just rewrites the label text & hint based on current state.

  const lblBride = document.getElementById('label_bride_cenomar');
  const lblGroom = document.getElementById('label_groom_cenomar');
  const brideCeno = document.getElementById('bride_cenomar');
  const groomCeno = document.getElementById('groom_cenomar');
  const brideWid = document.getElementById('bride_widowed_file');
  const groomWid = document.getElementById('groom_widowed_file');
  const partnerSel = document.getElementById('partner_status');
  const PROFILE = window.__PROFILE__ || null;
  const MY_GENDER = PROFILE && PROFILE.gender ? PROFILE.gender : null;
  const I_AM_WIDOWED = !!window.__IS_WIDOWED__;

  if (!lblBride || !lblGroom || !brideCeno || !groomCeno || !brideWid || !groomWid) return;

  function ensureHintBelow(inputEl){
    // Inserts (or returns existing) tiny hint node immediately after an input
    if (!inputEl) return null;
    if (inputEl.nextElementSibling && inputEl.nextElementSibling.classList && inputEl.nextElementSibling.classList.contains('label-who-must')) {
      return inputEl.nextElementSibling;
    }
    const hint = document.createElement('small');
    hint.className = 'label-who-must';
    inputEl.insertAdjacentElement('afterend', hint);
    return hint;
  }

  const hintBrideC = ensureHintBelow(brideCeno);
  const hintGroomC = ensureHintBelow(groomCeno);
  const hintBrideW = ensureHintBelow(brideWid);
  const hintGroomW = ensureHintBelow(groomWid);

  function whoRequires(side){ return side === 'Groom' ? 'Groom must upload' : 'Bride must upload'; }

  function state(){
    const partnerChoice = partnerSel && partnerSel.value ? partnerSel.value : '';
    const partnerIsWidowed = partnerChoice === 'Widowed';
    let groomWidOn = false, brideWidOn = false;

    if (MY_GENDER === 'Male') {
      groomWidOn = I_AM_WIDOWED;
      brideWidOn = partnerIsWidowed;
    } else if (MY_GENDER === 'Female') {
      brideWidOn = I_AM_WIDOWED;
      groomWidOn = partnerIsWidowed;
    } else {
      groomWidOn = false;
      brideWidOn = false;
    }
    return { groomWidOn, brideWidOn };
  }

  function setIfDiff(el, attr, val){
    if (!el) return;
    if (el.getAttribute(attr) !== val) el.setAttribute(attr, val);
  }

  function applyClarity(){
    const { groomWidOn, brideWidOn } = state();

    // Groom side label text + aria + hints
    if (groomWidOn) {
      lblGroom.innerHTML = 'Upload Widowed Document — Groom <span class="text-danger">*</span>';
      setIfDiff(groomWid, 'aria-label', 'Upload Widowed Document — Groom');
      if (hintGroomW) hintGroomW.textContent = whoRequires('Groom');
      if (hintGroomC) hintGroomC.textContent = ''; // not required
    } else {
      lblGroom.innerHTML = 'Upload CENOMAR — Groom <span class="text-danger">*</span>';
      setIfDiff(groomCeno, 'aria-label', 'Upload CENOMAR — Groom');
      if (hintGroomC) hintGroomC.textContent = whoRequires('Groom');
      if (hintGroomW) hintGroomW.textContent = '';
    }

    // Bride side label text + aria + hints
    if (brideWidOn) {
      lblBride.innerHTML = 'Upload Widowed Document — Bride <span class="text-danger">*</span>';
      setIfDiff(brideWid, 'aria-label', 'Upload Widowed Document — Bride');
      if (hintBrideW) hintBrideW.textContent = whoRequires('Bride');
      if (hintBrideC) hintBrideC.textContent = '';
    } else {
      lblBride.innerHTML = 'Upload CENOMAR — Bride <span class="text-danger">*</span>';
      setIfDiff(brideCeno, 'aria-label', 'Upload CENOMAR — Bride');
      if (hintBrideC) hintBrideC.textContent = whoRequires('Bride');
      if (hintBrideW) hintBrideW.textContent = '';
    }
  }

  // Re-apply when things change
  ['change', 'input'].forEach(ev => {
    partnerSel && partnerSel.addEventListener(ev, applyClarity);
  });

  // Initial run (slightly delayed to let prior JS finish)
  window.addEventListener('load', () => setTimeout(applyClarity, 0));
})();
</script>
<!-- ========================================= -->
<!-- END LABEL CLARITY ENHANCER (ADD-ONLY)    -->
<!-- ========================================= -->
<!-- ============================== -->
<!-- ADD BELOW THIS LINE (ADD-ONLY) -->
<!-- Widowed input label switcher   -->
<!-- ============================== -->
<style>
  /* Internal CSS for the dynamic widowed labels */
  .widow-dyn-label{
    display:block;
    font-weight:600;
    font-size:.92rem;
    color:#f9fafb;
    margin:10px 0 6px;
  }
</style>
<script>
(function(){
  // DOM we need
  const partnerSel   = document.getElementById('partner_status');

  // Placeholders where widowed <input type="file"> were moved
  const bridePH      = document.getElementById('bride_widow_placeholder');
  const groomPH      = document.getElementById('groom_widow_placeholder');

  // Actual file inputs (unchanged names -> server receives: bride_widowed_file / groom_widowed_file)
  const brideInput   = document.getElementById('bride_widowed_file');
  const groomInput   = document.getElementById('groom_widowed_file');

  // CENOMAR labels in the Additional column (we hide them when widowed is ON to avoid duplicate labels)
  const lblBrideCeno = document.getElementById('label_bride_cenomar');
  const lblGroomCeno = document.getElementById('label_groom_cenomar');

  // Profile flags already exposed by your PHP
  const PROFILE      = window.__PROFILE__ || null;
  const MY_GENDER    = PROFILE && PROFILE.gender ? PROFILE.gender : null; // 'Male' | 'Female' | null
  const I_AM_WIDOWED = !!window.__IS_WIDOWED__; // DB-level comparison flag

  if (!bridePH || !groomPH || !brideInput || !groomInput) return;

  // Create (once) a visible <label> right above each widowed file input
  function ensureWidowLabel(side){
    const id   = side === 'Bride' ? 'label_bride_widowed_dynamic' : 'label_groom_widowed_dynamic';
    const forId= side === 'Bride' ? 'bride_widowed_file' : 'groom_widowed_file';
    let label  = document.getElementById(id);
    if (!label){
      label = document.createElement('label');
      label.id = id;
      label.className = 'widow-dyn-label';
      // Insert immediately before each placeholder so it sits right above the <input type="file">
      const targetPH = side === 'Bride' ? bridePH : groomPH;
      targetPH.parentNode.insertBefore(label, targetPH);
    }
    label.setAttribute('for', forId);
    return label;
  }

  function computeWidowedSides(){
    const partnerIsWidowed = (partnerSel && partnerSel.value === 'Widowed');
    let groomWid = false, brideWid = false;

    if (MY_GENDER === 'Male') {
      groomWid = I_AM_WIDOWED;
      brideWid = partnerIsWidowed;
    } else if (MY_GENDER === 'Female') {
      brideWid = I_AM_WIDOWED;
      groomWid = partnerIsWidowed;
    } else {
      groomWid = false;
      brideWid = false;
    }
    return { groomWid, brideWid };
  }

  function setLabel(label, text, show){
    if (!label) return;
    label.innerHTML = text + ' <span class="text-danger">*</span>';
    label.style.display = show ? 'block' : 'none';
  }

  function apply(){
    const { groomWid, brideWid } = computeWidowedSides();

    // Ensure labels exist
    const gLbl = ensureWidowLabel('Groom');
    const bLbl = ensureWidowLabel('Bride');

    // Required phrasing
    setLabel(gLbl, 'Upload death certificate of the deceased spouse of the groom', groomWid);
    setLabel(bLbl, 'Upload death certificate of the deceased spouse of the bride',  brideWid);

    // Keep inputs accessible
    if (groomInput) groomInput.setAttribute('aria-label', 'Upload death certificate of the deceased spouse of the groom');
    if (brideInput) brideInput.setAttribute('aria-label', 'Upload death certificate of the deceased spouse of the bride');

    // Hide the old CENOMAR labels when widowed is ON (to avoid duplicate/confusing labels in this area)
    if (lblGroomCeno) lblGroomCeno.style.display = groomWid ? 'none' : '';
    if (lblBrideCeno) lblBrideCeno.style.display = brideWid ? 'none' : '';
  }

  // Run now and on changes
  apply();
  partnerSel && partnerSel.addEventListener('change', apply);
})();
</script>

<!-- ========================================= -->
<!-- ADD BELOW THIS LINE: MINOR AGE GATE (client-side, SweetAlert) -->
<!-- ========================================= -->
<script>
(function(){
  const form = document.getElementById('weddingForm');
  if (!form) return;

  const submitBtn = form.querySelector('.actions button[type="submit"]');
  const confirmBtn = document.getElementById('confirmSubmitBtn');
  const groomBD = document.getElementById('groom_birthdate');
  const brideBD = document.getElementById('bride_birthdate');

  function ageYears(ymd){
    if (!ymd) return null;
    const parts = ymd.split('-'); if (parts.length !== 3) return null;
    const d = new Date(parseInt(parts[0],10), parseInt(parts[1],10)-1, parseInt(parts[2],10));
    if (isNaN(d.getTime())) return null;
    const today = new Date();
    let age = today.getFullYear() - d.getFullYear();
    const m = today.getMonth() - d.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < d.getDate())) age--;
    return age;
  }

  function blockIfMinor(e){
    const g = groomBD ? groomBD.value : '';
    const b = brideBD ? brideBD.value : '';
    const ag = ageYears(g);
    const ab = ageYears(b);
    const isMinor = (ag !== null && ag < 18) || (ab !== null && ab < 18);

    if (isMinor) {
      if (e) { e.preventDefault(); e.stopPropagation(); }
      if (window.Swal && Swal.fire) {
        Swal.fire({
          icon: 'error',
          title: 'Not eligible (minor)',
          text: 'Applicants must be at least 18 years old. We can’t accept minors.',
          confirmButtonText: 'Okay',
          confirmButtonColor: '#7FC1FF',
          allowOutsideClick: false,
          allowEscapeKey: true
        });
      } else {
        alert('Applicants must be at least 18 years old. We can’t accept minors.');
      }
      return true;
    }
    return false;
  }

  // Run BEFORE other click handlers (capture=true) on SUBMIT button
  if (submitBtn) {
    submitBtn.addEventListener('click', function(e){
      if (blockIfMinor(e)) return; // stops opening the review modal
    }, true);
  }

  // Also guard the final Confirm & Submit button
  if (confirmBtn) {
    confirmBtn.addEventListener('click', function(e){
      if (blockIfMinor(e)) return; // prevent actual submission
    }, true);
  }

  // And guard raw form submissions (e.g., Enter key)
  form.addEventListener('submit', function(e){
    if (blockIfMinor(e)) return;
  }, true);
})();
</script>
<!-- ========================================= -->
<!-- END MINOR AGE GATE (client-side)         -->
<!-- ========================================= -->

</body>
</html>
