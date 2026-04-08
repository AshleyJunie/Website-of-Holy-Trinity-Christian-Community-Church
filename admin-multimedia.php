<?php
session_start();
require_once 'db-connection.php';

/* -------------------------------------------
   Gate: admin only
------------------------------------------- */
if (!isset($_SESSION['admin_id'])) {
  header("Location: all_log_in.php");
  exit;
}
$admin_id = (int)$_SESSION['admin_id'];

/* -------------------------------------------
   Flash helpers
------------------------------------------- */
function flash_set($type, $msg) { $_SESSION["flash_$type"] = $msg; }
function flash_get($type) {
  if (!empty($_SESSION["flash_$type"])) {
    $m = $_SESSION["flash_$type"];
    unset($_SESSION["flash_$type"]);
    return $m;
  }
  return null;
}

/* -------------------------------------------
   Ensure username/email present in session
------------------------------------------- */
if (empty($_SESSION['admin_user']) || empty($_SESSION['admin_email'])) {
  if ($stmt = @mysqli_prepare($db_connection,
      "SELECT admin_username, admin_emailaddress FROM admin_table WHERE admin_id=? LIMIT 1")) {
    mysqli_stmt_bind_param($stmt, "i", $admin_id);
    if (@mysqli_stmt_execute($stmt)) {
      $res = mysqli_stmt_get_result($stmt);
      if ($row = mysqli_fetch_assoc($res)) {
        if (!empty($row['admin_username']) && empty($_SESSION['admin_user'])) {
          $_SESSION['admin_user'] = (string)$row['admin_username'];
        }
        if (!empty($row['admin_emailaddress']) && empty($_SESSION['admin_email'])) {
          $_SESSION['admin_email'] = (string)$row['admin_emailaddress'];
        }
      }
    }
    mysqli_stmt_close($stmt);
  }
}

/* ======================================================================
   AUDIT writer (3 levels)
====================================================================== */
if (!function_exists('audit_multimedia_upload')) {
  function audit_multimedia_upload(
    mysqli $db,
    int $actorId,
    string $actorUser,
    string $actorEmail,
    string $recordPk,
    string $postedUrl
  ): array {

    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $detailsAfterJson = json_encode(
      ['fb_link' => (string)$postedUrl],
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    $doPrepared = function(string $action, ?string $detailsJson) use ($db, $actorId, $actorUser, $actorEmail, $recordPk, $ip, $ua): array {
      $sql = "INSERT INTO audit_trail
              (txn_id, actor_admin_id, actor_username, actor_email,
               action, source_table, record_pk, form_name,
               ip_address, user_agent, notes, details_before, details_after)
              VALUES
              (UUID(), ?, ?, ?, ?, 'multimedia', ?, 'admin-multimedia.php', ?, ?, 'Posted live stream link', NULL, ?)";
      $err = '';
      if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param(
          $stmt,
          "isssssss",
          $actorId, $actorUser, $actorEmail, $action, $recordPk, $ip, $ua, $detailsJson
        );
        $ok = mysqli_stmt_execute($stmt);
        if (!$ok) $err = mysqli_error($db);
        mysqli_stmt_close($stmt);
        return ['ok'=>(bool)$ok,'msg'=>$err];
      }
      return ['ok'=>false,'msg'=>mysqli_error($db)];
    };

    $r = $doPrepared('UPLOAD', $detailsAfterJson);
    if ($r['ok']) return ['ok'=>true,'msg'=>'','attempt'=>'UPLOAD'];

    $r2 = $doPrepared('INSERT', $detailsAfterJson);
    if ($r2['ok']) return ['ok'=>true,'msg'=>'(UPLOAD not allowed; used INSERT)','attempt'=>'INSERT'];

    $u  = $db->real_escape_string($actorUser);
    $e  = $db->real_escape_string($actorEmail);
    $pk = $db->real_escape_string($recordPk);
    $ipEsc = $db->real_escape_string($ip);
    $uaEsc = $db->real_escape_string($ua);
    $sql3 = "
      INSERT INTO audit_trail
      (txn_id, actor_admin_id, actor_username, actor_email,
       action, source_table, record_pk, form_name,
       ip_address, user_agent, notes, details_before, details_after)
      VALUES
      (UUID(), {$actorId}, '{$u}', '{$e}',
       'INSERT', 'multimedia', '{$pk}', 'admin-multimedia.php',
       '{$ipEsc}', '{$uaEsc}', 'Posted live stream link', NULL, NULL)
    ";
    $ok3 = mysqli_query($db, $sql3);
    if ($ok3) return ['ok'=>true,'msg'=>'(Wrote minimal audit row)','attempt'=>'MINIMAL'];

    $last = mysqli_error($db) ?: ($r2['msg'] ?: $r['msg'] ?: 'Unknown audit error');
    return ['ok'=>false,'msg'=>$last,'attempt'=>'FAILED'];
  }
}

/* =======================
   STOP LIVE (ALL Active -> Inactive)
======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stop_live'])) {
  $stop_sql = "UPDATE multimedia SET live_status='Inactive' WHERE live_status='Active'";
  if (mysqli_query($db_connection, $stop_sql)) {
    flash_set('success', 'All active live streams have been set to Inactive.');
  } else {
    flash_set('error', 'Failed to stop live streams: ' . mysqli_error($db_connection));
  }
  header('Location: ' . $_SERVER['PHP_SELF']);
  exit;
}

/* =======================
   POST NEW LIVE STREAM (tied to Post button)
======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['liveStreamUrl'])) {
  $fb_link = trim($_POST['liveStreamUrl'] ?? '');
  if ($fb_link === '') {
    flash_set('error', 'Please enter a valid link.');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
  }

  mysqli_begin_transaction($db_connection);
  try {
    if (!mysqli_query($db_connection, "UPDATE multimedia SET live_status='Inactive'")) {
      throw new Exception('Failed to deactivate previous streams: '.mysqli_error($db_connection));
    }

    $sql = "INSERT INTO multimedia (fb_link, live_status, admin_id) VALUES (?, 'Active', ?)";
    if (!$stmt = mysqli_prepare($db_connection, $sql)) {
      throw new Exception('Prepare failed: '.mysqli_error($db_connection));
    }
    mysqli_stmt_bind_param($stmt, "si", $fb_link, $admin_id);
    if (!mysqli_stmt_execute($stmt)) {
      $err = mysqli_error($db_connection);
      mysqli_stmt_close($stmt);
      throw new Exception('Execute failed: '.$err);
    }
    mysqli_stmt_close($stmt);

    $new_id = (int)mysqli_insert_id($db_connection);

    $actorId    = (int)($_SESSION['admin_id']   ?? 0);
    $actorUser  = (string)($_SESSION['admin_user']  ?? '');
    $actorEmail = (string)($_SESSION['admin_email'] ?? '');

    $audit = audit_multimedia_upload(
      $db_connection, $actorId, $actorUser, $actorEmail, (string)$new_id, $fb_link
    );

    mysqli_commit($db_connection);

    if (!$audit['ok']) {
      error_log('[AUDIT_TRAIL][multimedia] '.$audit['attempt'].' failed: '.$audit['msg']);
      flash_set('success', 'Live stream link saved. (Audit failed: '.$audit['msg'].')');
    } else {
      $note = ($audit['attempt']==='UPLOAD') ? '' : ' '.$audit['msg'];
      flash_set('success', 'Live stream link saved successfully!'.$note);
    }

  } catch (Throwable $e) {
    mysqli_rollback($db_connection);
    error_log('[MULTIMEDIA_POST] '.$e->getMessage());
    flash_set('error', 'Error saving link: '.$e->getMessage());
  }

  header('Location: ' . $_SERVER['PHP_SELF']);
  exit;
}

/* =======================
   Fetch history & active
======================= */
$history = [];
$res = mysqli_query($db_connection, "SELECT livemassId, fb_link, live_status, admin_id, date_uploaded FROM multimedia ORDER BY livemassId DESC");
if ($res) while ($row = mysqli_fetch_assoc($res)) $history[] = $row;

$active_iframe_src = null;
$q = mysqli_query($db_connection, "SELECT fb_link FROM multimedia WHERE live_status='Active' ORDER BY livemassId DESC LIMIT 1");
if ($q && ($r = mysqli_fetch_assoc($q))) $active_iframe_src = $r['fb_link'];

$flash_success = flash_get('success');
$flash_error   = flash_get('error');

/* =============================================================================
   === SINGLE DOMPDF DOWNLOAD ROUTE (Live Stream History, NO LINK COLUMN) ======
   Route: admin-multimedia.php?download=live_pdf[&from=YYYY-MM-DD&to=YYYY-MM-DD]
   ============================================================================= */
if (isset($_GET['download']) && $_GET['download'] === 'live_pdf') {
  @mysqli_set_charset($db_connection, 'utf8mb4');
  @mysqli_query($db_connection, "SET collation_connection = 'utf8mb4_unicode_ci'");

  // Optional date range
  $from = isset($_GET['from']) ? trim($_GET['from']) : '';
  $to   = isset($_GET['to'])   ? trim($_GET['to'])   : '';

  $where = "1=1";
  $params = [];
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where .= " AND m.date_uploaded >= CONCAT(?, ' 00:00:00')";
    $params[] = $from;
  }
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where .= " AND m.date_uploaded <= CONCAT(?, ' 23:59:59')";
    $params[] = $to;
  }

  $rows = [];
  $sql = "
    SELECT m.date_uploaded, m.live_status,
           a.admin_username AS admin_name
    FROM multimedia m
    LEFT JOIN admin_table a ON a.admin_id = m.admin_id
    WHERE $where
    ORDER BY m.livemassId DESC
  ";
  if ($params) {
    $types = str_repeat('s', count($params));
    if ($stmt = mysqli_prepare($db_connection, $sql)) {
      mysqli_stmt_bind_param($stmt, $types, ...$params);
      if (mysqli_stmt_execute($stmt)) {
        $resPdf = mysqli_stmt_get_result($stmt);
        while ($r = mysqli_fetch_assoc($resPdf)) $rows[] = $r;
      }
      mysqli_stmt_close($stmt);
    }
  } else {
    if ($rs = mysqli_query($db_connection, $sql)) {
      while ($r = mysqli_fetch_assoc($rs)) $rows[] = $r;
    }
  }

  // Build absolute logo URL
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $base   = $scheme . '://' . $_SERVER['HTTP_HOST'];
  $logo   = $base . '/HTCCC-SYSTEM/image/httc_main-logo.jpg';

  $pdfTitle  = "Live Stream History — Multimedia";
  $generated = date('M d, Y');

  ob_start();
  ?>
  <!DOCTYPE html>
  <html>
  <head>
    <meta charset="utf-8">
    <title><?php echo htmlspecialchars($pdfTitle); ?></title>
    <style>
      @page { margin: 18mm 14mm; }
      body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; color:#111827; font-size:12px; }
      .header { display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid #e5e7eb; padding-bottom:8px; margin-bottom:12px; }
      .brand { display:flex; gap:10px; align-items:center; }
      .brand img { width:48px; height:48px; border-radius:50%; }
      h1 { margin:0; font-size:18px; }
      .sub { color:#64748b; font-size:12px; }
      table { width:100%; border-collapse:collapse; }
      thead th { background:#1f2a6b; color:#fff; padding:8px; text-align:left; font-weight:700; }
      tbody td { border-bottom:1px solid #e5e7eb; padding:8px; }
      .muted { color:#64748b; }

      /* 3 columns only */
      .col-date   { width: 34%; }
      .col-status { width: 16%; }
      .col-admin  { width: 50%; }

      .badge { display:inline-block; padding:2px 8px; border-radius:999px; font-weight:600; font-size:10.5px; border:1px solid #cbd5e1; color:#334155; background:#eef2ff; }
      .badge.active { background:#ecfdf3; color:#065f46; border-color:#bbf7d0; }
      .badge.inactive { background:#fff1f2; color:#b42318; border-color:#fecdd3; }
    </style>
  </head>
  <body>
    <div class="header">
      <div class="brand">
        <img src="<?php echo htmlspecialchars($logo); ?>" alt="Logo">
        <div>
          <h1><?php echo htmlspecialchars($pdfTitle); ?></h1>
          <div class="sub">Generated: <?php echo htmlspecialchars($generated); ?></div>
        </div>
      </div>
      <div class="sub">
        HTCCC System
        <?php if ($from || $to): ?>
          <div>Range:
            <?php
              $fr = $from ? date('M d, Y', strtotime($from)) : '—';
              $tt = $to ? date('M d, Y', strtotime($to)) : '—';
              echo htmlspecialchars("$fr to $tt");
            ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <table>
      <thead>
        <tr>
          <th class="col-date">Date Uploaded</th>
          <th class="col-status">Status</th>
          <th class="col-admin">Admin</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="3" style="text-align:center;" class="muted">No records found.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?php
              $date = !empty($r['date_uploaded']) ? date('M d, Y — g:i A', strtotime($r['date_uploaded'])) : '—';
              echo htmlspecialchars($date);
            ?></td>
            <td>
              <?php
                $st = strtolower((string)($r['live_status'] ?? ''));
                $cls = $st === 'active' ? 'badge active' : 'badge inactive';
                echo '<span class="'.$cls.'">'.htmlspecialchars($r['live_status'] ?? '').'</span>';
              ?>
            </td>
            <td><?php echo htmlspecialchars($r['admin_name'] ?: '—'); ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </body>
  </html>
  <?php
  $html = ob_get_clean();

  require __DIR__ . '/vendor/autoload.php';
  $options = new \Dompdf\Options();
  $options->set('isRemoteEnabled', true);
  $options->set('defaultFont', 'DejaVu Sans');
  $options->set('isHtml5ParserEnabled', true);

  $dompdf = new \Dompdf\Dompdf($options);
  $dompdf->loadHtml($html);
  $dompdf->setPaper('A4', 'portrait');
  $dompdf->render();

  $fname = 'Live_Stream_History_' . date('Y-m-d_His') . '.pdf';
  $dompdf->stream($fname, ['Attachment' => true]);
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta content="width=device-width, initial-scale=1"/>
  <title>Admin - Multimedia</title>

  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/admin-multimedia.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/admin-multimedia.css'); ?>">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <!-- UI refinements -->
  <style>
    .panel-body label[for="liveStreamUrl"]{display:block;font-weight:600;color:#1B1B4B;margin:0 0 8px 2px;}
    .stream-form{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
    .input-wrap{position:relative;flex:1 1 520px;min-width:260px;}
    .input-wrap .icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:14px;color:#6B5AE3;pointer-events:none;}
    .stream-input{width:100%;height:44px;border:1.5px solid #e2e7ff;border-radius:12px;background:#fff;color:#1B1B4B;padding:10px 14px 10px 36px;outline:none;box-shadow:0 1px 0 rgba(0,0,0,0.02) inset;transition:.15s;}
    .stream-input::placeholder{color:#9aa3b2;}
    .stream-input:focus{border-color:#6B5AE3;box-shadow:0 0 0 4px rgba(107,90,227,.12);}
    .btn{display:inline-flex;align-items:center;gap:8px;border:0;cursor:pointer;border-radius:12px;padding:10px 16px;font-size:.95rem}
    .btn.primary{height:44px;background:#6B5AE3;color:#fff;font-weight:700;}
    .btn.secondary{height:38px;background:#eef;color:#1B1B4B}
    .btn.ghost{height:38px;background:#f4f6f8;color:#1B1B4B}
    @media (max-width:640px){.stream-form{gap:10px}.input-wrap{flex:1 1 100%}.btn.primary{width:100%}}

    .table-wrap{width:100%;overflow:auto;}
    .tbl{width:100%;border-collapse:separate;border-spacing:0;background:#fff;border:1px solid #e7e9f1;border-radius:12px;overflow:hidden;font-size:14px;}
    .tbl th{background:#f7f8fc;color:#28304a;text-align:left;padding:12px 14px;border-bottom:1px solid #e7e9f1;font-weight:700;position:sticky;top:0;z-index:1;cursor:pointer;user-select:none;}
    .tbl td{padding:10px 14px;border-bottom:1px solid #f0f2f7;}
    .tbl tbody tr:nth-child(even){background:#fbfcfe;}
    .badge{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;border:1px solid transparent;}
    .badge.active{background:#ecfdf3;color:#067647;border-color:#b7f0d0;}
    .badge.inactive{background:#fff1f2;color:#b42318;border-color:#ffccd0;}

    .lf-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:1000}
    .lf-modal{background:#fff;border-radius:14px;max-width:720px;width:92%;box-shadow:0 10px 30px rgba(0,0,0,.25)}
    .lf-modal header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #eee}
    .lf-modal .lf-close{background:none;border:0;font-size:20px;cursor:pointer;opacity:.7}
    .lf-modal .body{padding:18px 20px}
    .lf-modal textarea,.lf-modal input[type="text"]{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:10px;font-size:.95rem}
    .lf-modal textarea{min-height:120px;resize:vertical}
    .lf-modal .actions{display:flex;gap:10px;justify-content:flex-end;padding:14px 20px;border-top:1px solid #eee}

    /* Toolbar: search + sort dropdown + date range (no apply/clear buttons) */
    .hist-tools{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:8px 0 12px}
    .search-wrap{position:relative;flex:1 1 260px;min-width:220px}
    .search-input{
      width:100%;height:38px;border:1px solid #e2e7ff;border-radius:10px;padding:8px 34px 8px 34px;background:#fff;color:#1B1B4B;outline:none;
      transition:border-color .15s, box-shadow .15s;
    }
    .search-input::placeholder{color:#8f97a6}
    .search-input:focus{border-color:#6B5AE3;box-shadow:0 0 0 3px rgba(107,90,227,.12)}
    .search-icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);opacity:.7}
    .search-clear{position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:0;cursor:pointer;padding:6px;font-size:14px;color:#6B5AE3}
    .sort-select{
      height:38px;border:1px solid #e2e7ff;border-radius:10px;padding:6px 10px;background:#fff;color:#1B1B4B;
      outline:none;transition:border-color .15s, box-shadow .15s;
    }
    .date-wrap{display:flex;gap:6px;align-items:center}
    .date-input{height:38px;border:1px solid #e2e7ff;border-radius:10px;padding:6px 10px;background:#fff;color:#1B1B4B;outline:none;}
    .muted{color:#8f97a6}
    .result-count{font-size:12px;color:#6B5AE3;font-weight:600}

    /* Link column (web table only uses this class; PDFs no longer include links) */
    .tbl td.link-cell a{ text-decoration:underline; word-break:break-all; }
    .tbl td.link-cell{ max-width:420px; }
  </style>
</head>
<body>

<!-- ======================== SIDEBAR ======================== -->
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
    <a class="navlink active" href="admin-multimedia.php">
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
    <a class="navlink" href="certificate-table.php">
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

<div class="page">
  <header class="topbar">
    <h1>Multimedia</h1>
  </header>

  <main class="content">
    <section class="panel">
      <div class="panel-head" style="display:flex;align-items:center;justify-content:space-between;">
        <h2>Post Live Stream</h2>
        <button type="button" class="btn secondary" id="openLinkFixer"><i class="fas fa-link"></i> Link Fixer</button>
      </div>
      <div class="panel-body">
        <form class="stream-form" method="post">
          <div style="flex:1 1 100%;"><label for="liveStreamUrl">Live stream link URL</label></div>
          <div class="input-wrap">
            <i class="fas fa-link icon"></i>
            <input type="text" id="liveStreamUrl" name="liveStreamUrl" class="stream-input" placeholder="    https://…" required>
          </div>
          <button type="submit" class="btn primary">Post</button>
        </form>
        <p class="small muted" style="margin-top:8px;color:#667085;">
          Tip: Use <strong>Link Fixer</strong> to extract only the <code>src</code> URL from a full Facebook embed.
        </p>
      </div>
    </section>

    <section class="grid">
      <article class="panel">
        <div class="panel-head"><h2>Live Stream History</h2></div>
        <div class="panel-body">

          <!-- Toolbar: Search + Sort + Date Range (Apply-less) -->
          <div class="hist-tools" id="histTools">
            <div class="search-wrap">
              <i class="fas fa-search search-icon"></i>
              <input id="histSearch" class="search-input" type="text" placeholder="Search ID, date, status, admin, or link…">
              <button id="histClear" class="search-clear" title="Clear search"><i class="fas fa-times"></i></button>
            </div>

            <select id="histSortSelect" class="sort-select" aria-label="Sort Live Stream History">
              <option value="1:date:desc">Date ↓ (Latest first)</option>
              <option value="1:date:asc">Date ↑</option>
              <option value="0:number:desc">ID ↓ (Newest first)</option>
              <option value="0:number:asc">ID ↑</option>
              <option value="2:text:asc">Status A → Z</option>
              <option value="2:text:desc">Status Z → A</option>
              <option value="3:text:asc">Admin Name A → Z</option>
              <option value="3:text:desc">Admin Name Z → A</option>
              <option value="4:text:asc">Link A → Z</option>
              <option value="4:text:desc">Link Z → A</option>
            </select>

            <div class="date-wrap" aria-label="Filter by date range">
              <label for="dateFrom" class="muted">From</label>
              <input type="date" id="dateFrom" class="date-input">
              <label for="dateTo" class="muted">to</label>
              <input type="date" id="dateTo" class="date-input">
            </div>

            <span id="histCount" class="result-count"></span>
          </div>

          <div class="table-wrap" id="historyWrap">
            <table class="tbl" id="historyTable">
              <thead>
                <tr>
                  <th data-col="0" data-type="number">ID</th>
                  <th data-col="1" data-type="date">Date Uploaded</th>
                  <th data-col="2" data-type="text">Status</th>
                  <th data-col="3" data-type="text">Admin</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($history): foreach ($history as $h): ?>
                  <tr>
                    <td><?= htmlspecialchars($h['livemassId']) ?></td>
                    <td>
                      <?php
                        $date = !empty($h['date_uploaded'])
                          ? date("F j, Y — g:i A", strtotime($h['date_uploaded']))
                          : '—';
                        echo htmlspecialchars($date);
                      ?>
                    </td>
                    <td>
                      <span class="badge <?= strtolower($h['live_status'])==='active'?'active':'inactive' ?>">
                        <?= htmlspecialchars($h['live_status']) ?>
                      </span>
                    </td>
                    <td><?= htmlspecialchars($h['admin_id']) ?></td>
                  </tr>
                <?php endforeach; else: ?>
                  <tr><td colspan="4" class="muted">No records yet.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <!-- Bottom: single PDF button (kept in DOM; href syncs live with range) -->
          <div id="__download_pdf_bottom" style="display:flex;justify-content:flex-end;margin-top:12px;">
            <a id="__download_pdf_bottom_btn" class="btn secondary" href="admin-multimedia.php?download=live_pdf" style="text-decoration:none;">
              <i class="fas fa-file-download"></i> Download PDF
            </a>
          </div>
        </div>
      </article>

      <article class="panel">
        <div class="panel-head"><h2>On Streaming</h2></div>
        <div class="panel-body" style="text-align:center;">
          <?php if ($active_iframe_src): ?>
            <iframe src="<?= htmlspecialchars($active_iframe_src) ?>"
              width="560" height="314"
              style="border:none;overflow:hidden;max-width:100%;border-radius:10px"
              frameborder="0" allowfullscreen="true"
              allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share">
            </iframe>

            <form method="post" id="stopLiveForm" style="margin-top:16px;">
              <input type="hidden" name="stop_live" value="1">
              <button type="submit" class="btn secondary" id="stopLiveBtn">
                <i class="fas fa-stop-circle"></i> End Live Stream
              </button>
            </form>
          <?php else: ?>
            <div class="no-stream">NO STREAM AVAILABLE</div>
          <?php endif; ?>
        </div>
      </article>
    </section>
  </main>
</div>

<!-- Link Fixer Modal -->
<div class="lf-modal-backdrop" id="lfModalBackdrop" aria-hidden="true">
  <div class="lf-modal" role="dialog" aria-modal="true" aria-labelledby="lfTitle">
    <header>
      <h3 id="lfTitle">Link Fixer</h3>
      <button class="lf-close" id="lfCloseBtn" aria-label="Close">&times;</button>
    </header>
    <div class="body">
      <label for="lfInput">Paste full Facebook embed code</label>
      <textarea id="lfInput" placeholder='Example: &lt;iframe src="https://www.facebook.com/plugins/video.php?..."&gt;&lt;/iframe&gt;'></textarea>
      <label for="lfOutput" style="margin-top:10px;">Fixed Link</label>
      <input type="text" id="lfOutput" readonly placeholder="Result will appear here">
    </div>
    <div class="actions">
      <button class="btn ghost" id="lfClearBtn"><i class="fas fa-eraser"></i> Clear</button>
      <button class="btn secondary" id="lfCopyBtn"><i class="fas fa-copy"></i> Copy</button>
      <button class="btn primary" id="lfExtractBtn"><i class="fas fa-magic"></i> Fix Link</button>
    </div>
  </div>
</div>

<script>
/* Link Fixer events */
(function(){
  const openBtn   = document.getElementById('openLinkFixer');
  const backdrop  = document.getElementById('lfModalBackdrop');
  const closeBtn  = document.getElementById('lfCloseBtn');
  const inputEl   = document.getElementById('lfInput');
  const outputEl  = document.getElementById('lfOutput');
  const extractBtn= document.getElementById('lfExtractBtn');
  const copyBtn   = document.getElementById('lfCopyBtn');
  const clearBtn  = document.getElementById('lfClearBtn');
  const mainInput = document.getElementById('liveStreamUrl');

  function openModal(){backdrop.style.display='flex';backdrop.setAttribute('aria-hidden','false');setTimeout(()=>inputEl&&inputEl.focus(),0);}
  function closeModal(){backdrop.style.display='none';backdrop.setAttribute('aria-hidden','true');}
  if (openBtn) openBtn.addEventListener('click', openModal);
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  if (backdrop) backdrop.addEventListener('click', (e)=>{ if(e.target===backdrop) closeModal(); });
  document.addEventListener('keydown', (e)=>{ if(e.key==='Escape' && backdrop && backdrop.style.display==='flex') closeModal(); });

  function extractSrc(raw){
    if(!raw) return '';
    const s = raw.trim();
    if (/^https?:\/\//i.test(s)) return s;
    const m = s.match(/\s(?:src)\s*=\s*(['"])(.*?)\1/i);
    if (m && m[2]) return m[2].trim();
    try {
      const d = document.createElement('div'); d.innerHTML = s;
      const ifr = d.querySelector('iframe');
      if (ifr && ifr.getAttribute('src')) return ifr.getAttribute('src').trim();
    } catch(e){}
    return '';
  }

  if (extractBtn) extractBtn.addEventListener('click', ()=>{
    const src = extractSrc(inputEl.value);
    outputEl.value = src || '';
    if (!src) { outputEl.placeholder = 'No src found. Paste a full iframe embed or a direct URL.'; return; }
    if (mainInput) mainInput.value = src;
    Swal.fire({icon:'success',title:'Link fixed!',text:'The link has been extracted and added to the input.'});
  });

  if (copyBtn) copyBtn.addEventListener('click', async ()=>{
    const val = (outputEl.value || '').trim(); if (!val) return;
    try {
      await navigator.clipboard.writeText(val);
      copyBtn.innerHTML = '<i class="fas fa-check"></i> Copied';
      setTimeout(()=> copyBtn.innerHTML = '<i class="fas fa-copy"></i> Copy', 1200);
    } catch {
      outputEl.select(); document.execCommand('copy');
      copyBtn.innerHTML = '<i class="fas fa-check"></i> Copied';
      setTimeout(()=> copyBtn.innerHTML = '<i class="fas fa-copy"></i> Copy', 1200);
    }
  });

  if (clearBtn) clearBtn.addEventListener('click', ()=>{ inputEl.value=''; outputEl.value=''; inputEl.focus(); });
})();
</script>

<!-- =========================
     Smart Link column + Search + Smart Sort + Smart Date Filter + 3-row limiter + Live PDF href
     ========================= -->
<script>
/* Map: { id -> fb_link } */
const HISTORY_LINKS = <?php
  $map = [];
  foreach ($history as $row) {
    $map[(string)$row['livemassId']] = (string)$row['fb_link'];
  }
  echo json_encode($map, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
?>;

(function(){
  const table   = document.getElementById('historyTable');
  const wrap    = document.getElementById('historyWrap');
  const select  = document.getElementById('histSortSelect');
  const search  = document.getElementById('histSearch');
  const clear   = document.getElementById('histClear');
  const countEl = document.getElementById('histCount');
  const dateFrom = document.getElementById('dateFrom');
  const dateTo   = document.getElementById('dateTo');
  const pdfBtn   = document.getElementById('__download_pdf_bottom_btn');

  if (!table) return;

  // --- Add web-only "Link" column once ---
  if (!window.__LINK_COL_ADDED__) {
    window.__LINK_COL_ADDED__ = true;

    const headRow = table.tHead && table.tHead.rows[0];
    if (headRow && !Array.from(headRow.cells).some(th => th.textContent.trim().toLowerCase()==='link')) {
      const th = document.createElement('th');
      th.textContent = 'Link';
      th.dataset.col = '4';
      th.dataset.type = 'text';
      headRow.appendChild(th);
    }

    const tbody = table.tBodies[0];
    Array.from(tbody.rows).forEach(tr=>{
      const needCells = table.tHead.rows[0].cells.length;
      if (tr.cells.length >= needCells) return;

      const idTxt = (tr.cells[0].innerText || '').trim();
      const link  = HISTORY_LINKS && HISTORY_LINKS[idTxt] ? HISTORY_LINKS[idTxt] : '';

      const td = document.createElement('td');
      td.className = 'link-cell';
      if (link) {
        const a = document.createElement('a');
        a.href = link; a.target="_blank"; a.rel="noopener noreferrer";
        a.textContent = link.length>64? (link.slice(0,63)+'…') : link;
        a.title = link;
        td.appendChild(a);
      } else {
        td.innerHTML = '<span class="muted">—</span>';
      }
      tr.appendChild(td);
    });
  }

  // --- Helpers ---
  const tbody = table.tBodies[0];
  Array.from(tbody.rows).forEach((tr,i)=> tr.dataset._order = i); // stable index

  function normalize(s){ return (s||'').toString().toLowerCase(); }
  function parseDisplayDate(s){
    const cleaned = (s||'').replace(/\s*—\s*/,' ');
    const ts = Date.parse(cleaned);
    return isNaN(ts) ? NaN : ts;
  }

  // Smart date range based on inputs
  function toStartOfDay(ts){ const d=new Date(ts); d.setHours(0,0,0,0); return d.getTime(); }
  function toEndOfDay(ts){ const d=new Date(ts); d.setHours(23,59,59,999); return d.getTime(); }

  let rangeFrom = null; // ms start-of-day
  let rangeTo   = null; // ms end-of-day

  function syncRangeFromInputs(){
    rangeFrom = dateFrom && dateFrom.value ? toStartOfDay(new Date(dateFrom.value).getTime()) : null;
    rangeTo   = dateTo   && dateTo.value   ? toEndOfDay(new Date(dateTo.value).getTime())   : null;
  }

  function buildPdfHref(){
    const params = new URLSearchParams({ download: 'live_pdf' });
    if (dateFrom && dateFrom.value) params.set('from', dateFrom.value);
    if (dateTo && dateTo.value)     params.set('to',   dateTo.value);
    return 'admin-multimedia.php?' + params.toString();
  }

  function updatePdfHref(){ if (pdfBtn) pdfBtn.href = buildPdfHref(); }

  function rowMatches(tr, qTokens){
    if (!qTokens.length && rangeFrom===null && rangeTo===null) {
      return true;
    }
    const id    = tr.cells[0]?.innerText || '';
    const date  = tr.cells[1]?.innerText || '';
    const stat  = tr.cells[2]?.querySelector('.badge')?.innerText || tr.cells[2]?.innerText || '';
    const adm   = tr.cells[3]?.innerText || '';
    const linkA = tr.cells[4]?.querySelector('a')?.href || tr.cells[4]?.innerText || '';
    const hay   = normalize([id,date,stat,adm,linkA].join(' '));
    const textOk = qTokens.every(t => hay.includes(t));

    if (rangeFrom!==null || rangeTo!==null) {
      const dts = parseDisplayDate(date);
      if (isNaN(dts)) return false;
      if (rangeFrom!==null && dts < rangeFrom) return false;
      if (rangeTo!==null   && dts > rangeTo)   return false;
    }
    return textOk;
  }

  function applyFilter(){
    const q = normalize(search.value.trim());
    const tokens = q ? q.split(/\s+/).filter(Boolean) : [];
    let shown = 0;

    Array.from(tbody.rows).forEach(tr=>{
      const ok = rowMatches(tr, tokens);
      tr.style.display = ok ? '' : 'none';
      if (ok) shown++;
    });

    countEl.textContent = shown + ' result' + (shown===1?'':'s');
    limitRowsHeight();
  }

  function getCellValue(tr, idx, type){
    const td = tr.cells[idx];
    if (!td) return type==='text' ? '' : 0;
    let raw = td.innerText.trim();
    if (idx===2){ raw = (td.querySelector('.badge')?.innerText || raw).trim(); }
    if (idx===4){ raw = (td.querySelector('a')?.href || raw).trim(); }
    if (idx===3 && type==='number'){
      const idNum = parseFloat((td.dataset && td.dataset.adminId) ? td.dataset.adminId : raw.replace(/[^\d.-]/g,'')) || 0;
      return idNum;
    }
    if (type==='number') return parseFloat(raw.replace(/[^\d.-]/g,'')) || 0;
    if (type==='date')   { const ts = parseDisplayDate(raw); return isNaN(ts)?0:ts; }
    return raw.toLowerCase();
  }

  // --- Smart sorting ---
  let currentSort = { col: 1, type: 'date', asc: false }; // default: Date desc

  function applySort(){
    const { col, type, asc } = currentSort;
    const rows = Array.from(tbody.rows);
    rows.sort((a,b)=>{
      const av = getCellValue(a,col,type||'text');
      const bv = getCellValue(b,col,type||'text');
      let cmp = (type==='number'||type==='date') ? (av - bv) : (''+av).localeCompare((''+bv));
      if (cmp===0) cmp = (+a.dataset._order) - (+b.dataset._order);
      return asc ? cmp : -cmp;
    });
    rows.forEach(r=>tbody.appendChild(r));
  }

  function setSort(col, type, asc){
    currentSort = { col, type, asc };
    // keep dropdown in sync (if matches a known option)
    const mapKey = `${col}:${type}:${asc?'asc':'desc'}`;
    for (const opt of Array.from(select.options)) {
      if (opt.value === mapKey) { select.value = mapKey; break; }
    }
    applySort(); limitRowsHeight();
  }

  // Dropdown -> sort immediately
  if (select) select.addEventListener('change', ()=>{
    const [colStr, type, dir] = (select.value||'').split(':');
    setSort(parseInt(colStr,10), type||'text', (dir||'asc')==='asc');
  });

  // Clickable header sort (toggle asc/desc; infer type from data-attrs)
  const headCells = Array.from(table.tHead.rows[0].cells);
  headCells.forEach(th=>{
    const col = parseInt(th.dataset.col || headCells.indexOf(th), 10);
    const type = th.dataset.type || (col===0?'number':col===1?'date':'text');
    th.addEventListener('click', ()=>{
      const asc = !(currentSort.col===col && currentSort.asc===true); // toggle
      setSort(col, type, asc);
    });
  });

  // --- 3 visible rows max (scroll) ---
  function limitRowsHeight(){
    const visibleRows = Array.from(tbody.rows).filter(r=>r.style.display!=='none');
    const firstVisible = visibleRows[0];
    const headH = table.tHead?.rows[0]?.getBoundingClientRect().height || 44;
    const rowH  = firstVisible ? firstVisible.getBoundingClientRect().height : 44;
    const maxRows = Math.min(3, Math.max(visibleRows.length, 0));
    wrap.style.maxHeight = (headH + (rowH * maxRows) + 2) + 'px';
  }

  // --- Instant search & date range ---
  if (search) search.addEventListener('input', applyFilter);
  if (clear)  clear.addEventListener('click', (e)=>{ e.preventDefault(); search.value=''; applyFilter(); });

  function onRangeInput(){
    syncRangeFromInputs();
    applyFilter();
    updatePdfHref();
  }
  if (dateFrom) { dateFrom.addEventListener('input', onRangeInput); dateFrom.addEventListener('change', onRangeInput); }
  if (dateTo)   { dateTo.addEventListener('input', onRangeInput);   dateTo.addEventListener('change', onRangeInput); }

  // Initial render
  setSort(1,'date',false); // Date ↓ default
  applyFilter();
  updatePdfHref();

  window.addEventListener('load', ()=>{ setTimeout(limitRowsHeight, 120); setTimeout(limitRowsHeight, 300); });
  window.addEventListener('resize', limitRowsHeight);
})();
</script>

<?php if($flash_success): ?>
<script>Swal.fire({icon:'success',title:'Success',text:<?= json_encode($flash_success) ?>});</script>
<?php elseif($flash_error): ?>
<script>Swal.fire({icon:'error',title:'Error',text:<?= json_encode($flash_error) ?>});</script>
<?php endif; ?>

<!-- =========================
     Admin names & sort by Admin Name
     ========================= -->
<script>
/* ADMIN NAME MAP from PHP (admin_id -> admin_username) */
const ADMIN_NAMES = <?php
  $ids = [];
  foreach ($history as $row) { $ids[(int)$row['admin_id']] = true; }
  $idList = array_keys($ids);
  $nameMap = [];
  if ($idList) {
    $in = implode(',', array_map('intval', $idList));
    $sqlAdmins = "SELECT admin_id, admin_username FROM admin_table WHERE admin_id IN ($in)";
    if ($rs = mysqli_query($db_connection, $sqlAdmins)) {
      while ($a = mysqli_fetch_assoc($rs)) {
        $nameMap[(int)$a['admin_id']] = (string)$a['admin_username'];
      }
    }
  }
  echo json_encode($nameMap, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
?>;

(function(){
  const table  = document.getElementById('historyTable');
  const headTh = table?.tHead?.rows?.[0]?.cells?.[3];
  const tbody  = table?.tBodies?.[0];
  if (!table || !tbody) return;

  // Header label shows "Admin"
  if (headTh) headTh.textContent = 'Admin';

  // Replace Admin ID cell text with name, keep numeric in data-admin-id
  Array.from(tbody.rows).forEach(tr=>{
    const td = tr.cells[3];
    if (!td) return;
    const idTxt = (td.innerText || '').trim();
    const idNum = parseInt(idTxt, 10);
    td.dataset.adminId = isNaN(idNum) ? '' : String(idNum);
    const name = ADMIN_NAMES && ADMIN_NAMES[idNum] ? ADMIN_NAMES[idNum] : (idTxt || '—');
    td.textContent = name || '—';
  });
})();
</script>
<!-- =========================
     ADD BELOW THIS LINE — Clear All button (search, date range, sort, PDF href)
     ========================= -->
<script>
(function(){
  // Wait for DOM to be ready so earlier scripts have already bound their handlers
  function addClearAll(){
    const tools   = document.getElementById('histTools');
    if (!tools || document.getElementById('histClearAll')) return;

    // Reuse existing controls
    const search  = document.getElementById('histSearch');
    const dateFrom= document.getElementById('dateFrom');
    const dateTo  = document.getElementById('dateTo');
    const sortSel = document.getElementById('histSortSelect');
    const pdfBtn  = document.getElementById('__download_pdf_bottom_btn');

    // Build button
    const btn = document.createElement('button');
    btn.id = 'histClearAll';
    btn.type = 'button';
    btn.className = 'btn ghost';
    btn.style.marginLeft = 'auto'; // push to the right edge of the toolbar
    btn.innerHTML = '<i class="fas fa-broom"></i> Clear';

    // Insert at the end of the toolbar
    tools.appendChild(btn);

    // Clear logic (no direct access to inner IIFE functions needed)
    btn.addEventListener('click', () => {
      // 1) Reset search
      if (search) {
        search.value = '';
        search.dispatchEvent(new Event('input', { bubbles: true }));
      }

      // 2) Reset date range
      if (dateFrom) {
        dateFrom.value = '';
        // fire both input & change so listeners react immediately
        dateFrom.dispatchEvent(new Event('input',  { bubbles: true }));
        dateFrom.dispatchEvent(new Event('change', { bubbles: true }));
      }
      if (dateTo) {
        dateTo.value = '';
        dateTo.dispatchEvent(new Event('input',  { bubbles: true }));
        dateTo.dispatchEvent(new Event('change', { bubbles: true }));
      }

      // 3) Reset sort (Date ↓ Latest first)
      if (sortSel) {
        // matches the default option value used by your script
        const defaultVal = '1:date:desc';
        sortSel.value = defaultVal;
        sortSel.dispatchEvent(new Event('change', { bubbles: true }));
      }

      // 4) Ensure PDF link is back to base route (no from/to params)
      if (pdfBtn) {
        pdfBtn.href = 'admin-multimedia.php?download=live_pdf';
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addClearAll);
  } else {
    addClearAll();
  }
})();
</script>
<!-- =========================
     END ADDITION — Clear All button
     ========================= -->


<!-- (Single PDF route; everything updates instantly) -->
</body>
</html>
