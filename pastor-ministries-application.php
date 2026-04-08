<?php
include 'db-connection.php'; // must set $db_connection = new mysqli(...)

/* ================= PHPMailer Setup ================= */
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// If using Composer: require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';

/**
 * Send email to applicant depending on final status.
 * Uses ministries_table.ministry_email
 * Returns [bool $ok, string $msg]
 */
function sendMinistryEmail(string $toEmail, string $toName, string $ministryType, string $finalStatus): array {
    $mail = new PHPMailer(true);
    try {
        // SMTP (Gmail)
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'holytrinitychristiancommunityc@gmail.com';
        $mail->Password   = 'jngx vtqb urun yjur'; // Gmail App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // FROM / TO
        $mail->setFrom('holytrinitychristiancommunityc@gmail.com', 'Holy Trinity Christian Community Church');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);

        $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
        $safeType = htmlspecialchars($ministryType, ENT_QUOTES, 'UTF-8');

        if ($finalStatus === 'Approved') {
            $mail->Subject = "Your {$safeType} Ministry Application has been Approved";
            $mail->Body = "
                <div style='font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111;line-height:1.6'>
                    <p>Dear <strong>{$safeName}</strong>,</p>
                    <p>Congratulations! Your application for the <strong>{$safeType}</strong> has been <strong style=\"color:#2e7d32\">Approved</strong>.</p>
                    <p>You may now proceed to book an appointment for our services through the HTCCC System.</p>
                    <p>God bless,<br>Holy Trinity Christian Community Church</p>
                </div>";
            $mail->AltBody = "Dear {$toName},\n\nYour {$ministryType} application has been approved. You may now book an appointment via the HTCCC System.\n\nGod bless,\nHTCCC";
        } else { // Declined
            $mail->Subject = "Your {$safeType} Ministry Application has been Declined";
            $mail->Body = "
                <div style='font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111;line-height:1.6'>
                    <p>Dear <strong>{$safeName}</strong>,</p>
                    <p>We regret to inform you that your application for the <strong>{$safeType}</strong> has been <strong style=\"color:#c62828\">Declined</strong>.</p>
                    <p>If you have questions or wish to re-apply, please contact the HTCCC office.</p>
                    <p>God bless,<br>Holy Trinity Christian Community Church</p>
                </div>";
            $mail->AltBody = "Dear {$toName},\n\nYour {$ministryType} application has been declined.\n\nGod bless,\nHTCCC";
        }

        $mail->send();
        return [true, "{$finalStatus} email sent."];
    } catch (Exception $e) {
        return [false, "{$finalStatus} email not sent."];
    }
}

/* ================= Handle Approve / Decline (POST) ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    $id  = (int)$_POST['id'];
    $act = $_POST['action'] === 'approve' ? 'approve' : 'decline';
    $newStatus = ($act === 'approve') ? 'Approved' : 'Declined';

    if ($id > 0) {
        if ($stmt = mysqli_prepare($db_connection, "UPDATE ministries_table SET status = ? WHERE ministry_id = ? LIMIT 1")) {
            mysqli_stmt_bind_param($stmt, "si", $newStatus, $id);
            mysqli_stmt_execute($stmt);
            $affected = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);

            // keep current filters/sort; prepare toast
            $qs = $_GET;

            if ($affected > 0) {
                // Fetch applicant info (dito tayo mag-co-compare kung saan ipapadala)
                if ($sel = mysqli_prepare($db_connection, "SELECT ministry_email, ministry_firstname, ministry_lastname, ministry_type FROM ministries_table WHERE ministry_id = ? LIMIT 1")) {
                    mysqli_stmt_bind_param($sel, "i", $id);
                    mysqli_stmt_execute($sel);
                    $res = mysqli_stmt_get_result($sel);
                    $row = $res ? mysqli_fetch_assoc($res) : null;
                    mysqli_stmt_close($sel);

                    $email = trim($row['ministry_email'] ?? '');
                    $fname = trim($row['ministry_firstname'] ?? '');
                    $lname = trim($row['ministry_lastname'] ?? '');
                    $name  = trim($fname . ' ' . $lname);
                    $mtype = trim($row['ministry_type'] ?? 'Ministry');

                    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        [$ok, $mailMsg] = sendMinistryEmail($email, $name, $mtype, $newStatus);
                        $qs['toast'] = urlencode("Application #{$id} set to {$newStatus}. {$mailMsg}");
                        $qs['toastType'] = $ok ? 'success' : 'warning';
                    } else {
                        $qs['toast'] = urlencode("Application #{$id} set to {$newStatus}. Invalid or missing ministry_email.");
                        $qs['toastType'] = 'warning';
                    }
                } else {
                    $qs['toast'] = urlencode("Application #{$id} set to {$newStatus}. (Email lookup failed)");
                    $qs['toastType'] = 'warning';
                }
            } else {
                $qs['toast'] = urlencode("No changes made.");
                $qs['toastType'] = 'error';
            }

            header("Location: " . $_SERVER['PHP_SELF'] . '?' . http_build_query($qs));
            exit;
        } else {
            $qs = $_GET;
            $qs['toast'] = urlencode('Database error while preparing update.');
            $qs['toastType'] = 'error';
            header("Location: " . $_SERVER['PHP_SELF'] . '?' . http_build_query($qs));
            exit;
        }
    }
}

/* ================= Sorting ================= */
$sort = $_GET['sort'] ?? 'newest';
switch ($sort) {
    case 'oldest': $orderBy = "ORDER BY date_join ASC"; break;
    case 'type'  : $orderBy = "ORDER BY ministry_type ASC, date_join DESC"; break;
    default      : $orderBy = "ORDER BY date_join DESC"; $sort = 'newest';
}

/* ================= Build ministry chips from DB ================= */
$validMinistries = [];
$resTypes = mysqli_query($db_connection, "SELECT DISTINCT ministry_type FROM ministries_table ORDER BY ministry_type ASC");
if ($resTypes) {
    while ($r = mysqli_fetch_assoc($resTypes)) {
        if ($r['ministry_type'] !== null && $r['ministry_type'] !== '') {
            $validMinistries[] = $r['ministry_type'];
        }
    }
    mysqli_free_result($resTypes);
}
// Fallback labels if table empty
if (!$validMinistries) {
    $validMinistries = [
        "Women Ministry",
        "Men Ministry",
        "Music Ministry",
        "Usher & Usherette",
        "Junior Christ Ambassador",
    ];
}

$ministryFilter = $_GET['ministry'] ?? 'all';
$useFilter = in_array($ministryFilter, $validMinistries, true);

/* ================= Data query ================= */
/* add ministry_email so we can show in SweetAlert View */
$baseSelect = "SELECT ministry_id, date_join, ministry_type, ministry_position,
                      ministry_lastname, ministry_firstname, ministry_middlename, ministry_extensionname,
                      ministry_email
               FROM ministries_table
               WHERE status = 'Pending'";

if ($useFilter) {
    $sql  = $baseSelect . " AND ministry_type = ? $orderBy";
    $stmt = mysqli_prepare($db_connection, $sql);
    mysqli_stmt_bind_param($stmt, "s", $ministryFilter);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $sql    = $baseSelect . " $orderBy";
    $result = mysqli_query($db_connection, $sql);
}

/* ================= Helpers ================= */
function linkQS($params) {
  $base = $_SERVER['PHP_SELF'];
  $merged = array_merge($_GET, $params);
  return htmlspecialchars($base.'?'.http_build_query($merged), ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1"/>
<title>Admin – Ministry Applications</title>
<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link rel="stylesheet" href="/HTCCC-SYSTEM/css/application_ministry.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/application_ministry.css'); ?>">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
/* ---- Sorting/Filter design ---- */
.sort-bar{ display:flex; gap:14px; align-items:center; flex-wrap:wrap; margin-bottom:14px }
.sort-bar .search-box{ min-width:260px }

.segmented{
  display:inline-flex; border:1px solid #d9e1ef; border-radius:10px; overflow:hidden;
  background:#fff; box-shadow:0 2px 8px rgba(18,38,63,.06)
}
.seg-btn{
  padding:8px 14px; font-weight:600; font-size:14px; color:#334155;
  text-decoration:none; border-right:1px solid #e8eef9; display:flex; align-items:center; gap:8px
}
.seg-btn:last-child{ border-right:none }
.seg-btn i{ font-size:14px; opacity:.8 }
.seg-btn:hover{ background:#f7f9ff }
.seg-btn.active{ background:#1b1b4b; color:#fff; border-right-color:transparent }
.seg-btn.active i{ opacity:1 }

.chips{ display:flex; gap:8px; flex-wrap:wrap; align-items:center }
.chip{
  display:inline-flex; align-items:center; gap:8px;
  padding:8px 12px; border:1px solid #d9e1ef; border-radius:999px; background:#fff;
  color:#0f172a; text-decoration:none; font-weight:600; font-size:13px;
  box-shadow:0 1px 6px rgba(18,38,63,.05)
}
.chip:hover{ background:#f8fbff }
.chip.active{ background:#6b5ae3; color:#fff; border-color:transparent; box-shadow:none }
.chip i{ font-size:14px; opacity:.85 }

.btn-view{background:#3f51b5;color:#fff;border:none;border-radius:6px;padding:6px 10px;cursor:pointer}
.btn-approve{background:#2e7d32;color:#fff;border:none;border-radius:6px;padding:6px 10px;cursor:pointer}
.btn-decline{background:#c62828;color:#fff;border:none;border-radius:6px;padding:6px 10px;cursor:pointer}
table td form{display:inline}
@media (max-width:680px){
  .sort-bar{ flex-direction:column; align-items:stretch }
  .segmented{ width:100% }
  .seg-btn{ justify-content:center; flex:1 }
  .chips{ justify-content:flex-start }
}

/* ====== ADDED: Smart Search UI bits (non-breaking) ====== */
.smart-help{position:relative;display:inline-block;margin-left:8px;cursor:pointer;font-size:12px;color:#334155}
.smart-help .bubble{
  position:absolute;right:0;top:130%;
  background:#0f1720;color:#e5efff;border:1px solid rgba(255,255,255,.15);
  padding:10px 12px;border-radius:10px;width:min(440px,92vw);display:none;z-index:50;
  box-shadow:0 10px 30px rgba(0,0,0,.25)
}
.smart-help:hover .bubble{display:block}
mark.smart-hit{background:#ffe08a;color:#111;padding:0 .12em;border-radius:4px}
</style>
</head>

<body>

<!-- SIDEBAR -->
<!-- ======================== SIDEBAR ======================== -->
<aside class="sidebar">
  <div class="brand">
    <img src="image/httc_main-logo.jpg" alt="" />
    <span>HTCCC SYSTEM</span>
  </div>

  <div class="user-card">
    <img src="css/image/profile.png" alt="user">
    <div>
      <div class="user-title">Pastor</div>
      <div class="user-sub">Dashboard</div>
    </div>
  </div>

  <nav class="nav">
    <div class="section-title">Main</div>
    <a class="navlink" href="secretary_dashboard.php"><i class="fas fa-th-large"></i>Dashboard</a>

    <div class="section-title">Requests</div>
    <a class="navlink" href="pastor-admin-request.php">
      <i class="far fa-calendar-plus"></i>Appointment Request
    </a>
    <a class="navlink" href="pastor-prayer-request.php">
      <i class="far fa-hand-paper"></i><span>Prayer Request</span>
    </a>

    <div class="section-title">Schedule</div>
      <a class="navlink" href="pastor-appointment-schedule.php">
      <i class="far fa-calendar-alt"></i>Appointment Schedule
    </a>
    <a class="navlink" href="pastor-service-schedule.php">
      <i class="fas fa-calendar-alt"></i>Service Schedule
    </a>

    <div class="section-title">Application</div>
    <a class="navlink active" href="pastor-ministries-application.php">
      <i class="fas fa-users"></i>Ministries Application
    </a>
    <a class="navlink" href="pastor-user-application.php">
      <i class="far fa-user"></i>User Application
    </a>

    <div class="section-title">Streaming</div>
    <a class="navlink" href="pastor-streaming.php">
      <i class="fas fa-video"></i>Streaming
    </a>

    <div class="section-title">Ministry List</div>
    <a class="navlink" href="pastor-women-ministries.php">
      <i class="fas fa-female"></i>Handmaid's of the Lord
    </a>
    <a class="navlink" href="pastor-men-ministries.php">
      <i class="fas fa-male"></i>Men's Ministry
    </a>
    <a class="navlink" href="pastor-music-ministries.php">
      <i class="fas fa-music"></i>Music's Ministry
    </a>
    <a class="navlink" href="pastor-usher-ministries.php">
      <i class="fas fa-hands-helping"></i>Usher &amp; Usherette
    </a>
    <a class="navlink" href="pastor-junior-ministries.php">
      <i class="fas fa-child"></i>Junior Christ Ambassador
    </a>

    <div class="section-title">Reports</div>
    <a class="navlink" href="pastor-report.php">
      <i class="fas fa-file-alt"></i>Reports
    </a>

    <div class="section-title">Content</div>
    <a class="navlink" href="pastor-content management.php">
      <i class="fas fa-edit"></i>Content Management
    </a>

     <div class="section-title">Management</div>
    <a class="navlink" href="content-management_home-page.php">
      <i class="fas fa-edit"></i> Audit Trails
    </a>

    <div class="section-title">More</div>
    <a class="navlink logout" href="all_log-in.php">
      <img alt="Logout" class="icon" src="image/logo-logout.png" width="18" height="18" style="vertical-align:middle;margin-right:8px;">
      Log Out
    </a>
  </nav>
</aside>

<!-- MAIN PAGE -->
<div class="page">
  <header class="topbar"><h1>Ministry Applications</h1></header>

  <div class="container">
    <!-- Sort and Filter Bar -->
    <div class="top-bar sort-bar">
      <input type="text" placeholder="🔍 Search" class="search-box">

      <!-- Segmented Sort -->
      <nav class="segmented" aria-label="Sort results">
        <a class="seg-btn <?php echo $sort==='newest'?'active':''; ?>" href="<?php echo linkQS(['sort'=>'newest']); ?>">
          <i class="fas fa-sort-amount-down-alt"></i> Newest
        </a>
        <a class="seg-btn <?php echo $sort==='oldest'?'active':''; ?>" href="<?php echo linkQS(['sort'=>'oldest']); ?>">
          <i class="fas fa-sort-amount-up"></i> Oldest
        </a>
        <a class="seg-btn <?php echo $sort==='type'?'active':''; ?>" href="<?php echo linkQS(['sort'=>'type']); ?>">
          <i class="fas fa-list"></i> Ministry Type
        </a>
      </nav>

      <!-- Ministry Filter Chips (dynamic from DB) -->
      <div class="chips" role="group" aria-label="Filter by ministry">
        <?php
        $chip = function($label, $value) use ($ministryFilter) {
          $isActive = ($ministryFilter===$value) || ($value==='all' && $ministryFilter==='all');
          $href = htmlspecialchars($_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($_GET, ['ministry'=>$value])), ENT_QUOTES, 'UTF-8');

          // Choose an icon by rough match
          $icon = 'fas fa-layer-group';
          if ($value !== 'all') {
            $lower = strtolower($value);
            if (strpos($lower,'women') !== false) $icon = 'fas fa-female';
            elseif (strpos($lower,'men') !== false) $icon = 'fas fa-male';
            elseif (strpos($lower,'music') !== false) $icon = 'fas fa-music';
            elseif (strpos($lower,'usher') !== false) $icon = 'fas fa-hands-helping';
            elseif (strpos($lower,'junior') !== false) $icon = 'fas fa-child';
          }

          echo '<a class="chip '.($isActive?'active':'').'" href="'.$href.'"><i class="'.$icon.'"></i>'.htmlspecialchars($label).'</a>';
        };

        $chip('All', 'all');
        foreach ($validMinistries as $m) { $chip($m, $m); }
        ?>
      </div>
    </div>

    <!-- Table -->
    <table>
      <thead>
        <tr>
          <th>Date Requested</th>
          <th>Ministry Type</th>
          <th>Position</th>
          <th>Lastname</th>
          <th>Firstname</th>
          <th>Middlename</th>
          <th>Extensionname</th>
          <th style="text-align:center;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php
        if ($result && mysqli_num_rows($result) > 0) {
          while ($row = mysqli_fetch_assoc($result)) {
            $id = (int)$row['ministry_id'];
            $fullName = trim(($row['ministry_firstname'] ?? '').' '.($row['ministry_lastname'] ?? ''));
            $email    = htmlspecialchars($row['ministry_email'] ?? '', ENT_QUOTES, 'UTF-8');
            $type     = htmlspecialchars($row['ministry_type'] ?? '', ENT_QUOTES, 'UTF-8');
            $pos      = htmlspecialchars($row['ministry_position'] ?? '', ENT_QUOTES, 'UTF-8');
            $dateF    = date('F j, Y', strtotime($row['date_join']));

            // Build a small data packet for SweetAlert View
            $viewPayload = htmlspecialchars(json_encode([
              'id' => $id,
              'name' => $fullName,
              'email' => $row['ministry_email'] ?? '',
              'type' => $row['ministry_type'] ?? '',
              'position' => $row['ministry_position'] ?? '',
              'date' => $dateF,
              'lastname' => $row['ministry_lastname'] ?? '',
              'firstname' => $row['ministry_firstname'] ?? '',
              'middlename' => $row['ministry_middlename'] ?? '',
              'extensionname' => $row['ministry_extensionname'] ?? '',
            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

            echo '<tr>';
            echo '<td>' . $dateF . '</td>';
            echo '<td>' . $type . '</td>';
            echo '<td>' . $pos . '</td>';
            echo '<td>' . htmlspecialchars($row['ministry_lastname']) . '</td>';
            echo '<td>' . htmlspecialchars($row['ministry_firstname']) . '</td>';
            echo '<td>' . htmlspecialchars($row['ministry_middlename']) . '</td>';
            echo '<td>' . htmlspecialchars($row['ministry_extensionname']) . '</td>';
            echo '<td style="text-align:center;">';
            echo '  <form method="post" class="row-form" data-id="'.$id.'">';
            echo '    <input type="hidden" name="id" value="'.$id.'">';
            echo '    <button type="button" class="btn-view js-view" data-payload="'.$viewPayload.'"><i class="fas fa-eye"></i> View</button> ';
            echo '    <button type="button" class="btn-approve js-approve" data-id="'.$id.'">Approve</button> ';
            echo '    <button type="button" class="btn-decline js-decline" data-id="'.$id.'">Decline</button>';
            echo '    <input type="hidden" name="action" value="">';
            echo '  </form>';
            echo '</td></tr>';
          }
        } else {
          echo '<tr><td colspan="8">No pending applications found.</td></tr>';
        }
        if (isset($stmt) && $stmt instanceof mysqli_stmt) { mysqli_stmt_close($stmt); }
        ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// ---- SweetAlert2: Action Modal via "View" ----
document.querySelectorAll('.js-view').forEach(btn => {
  btn.addEventListener('click', () => {
    const form = btn.closest('form');
    const data = JSON.parse(btn.dataset.payload || '{}');

    const html = `
      <div style="text-align:left;font-size:14px;line-height:1.5">
        <div><strong>Date Requested:</strong> ${data.date || ''}</div>
        <div><strong>Ministry Type:</strong> ${escapeHtml(data.type || '')}</div>
        <div><strong>Position:</strong> ${escapeHtml(data.position || '')}</div>
        <hr style="margin:10px 0;">
        <div><strong>Lastname:</strong> ${escapeHtml(data.lastname || '')}</div>
        <div><strong>Firstname:</strong> ${escapeHtml(data.firstname || '')}</div>
        <div><strong>Middlename:</strong> ${escapeHtml(data.middlename || '')}</div>
        <div><strong>Extension:</strong> ${escapeHtml(data.extensionname || '')}</div>
        <div><strong>Email:</strong> ${escapeHtml(data.email || '')}</div>
      </div>
    `;

    Swal.fire({
      title: `Application #${data.id}`,
      html,
      icon: 'info',
      showCancelButton: true,
      showDenyButton: true,
      confirmButtonText: 'Approve',
      denyButtonText: 'Decline',
      cancelButtonText: 'Close',
      confirmButtonColor: '#2e7d32',
      denyButtonColor: '#c62828',
      width: 600
    }).then(res => {
      if (res.isConfirmed) {
        form.querySelector('input[name="action"]').value = 'approve';
        showSubmitting(); form.submit();
      } else if (res.isDenied) {
        form.querySelector('input[name="action"]').value = 'decline';
        showSubmitting(); form.submit();
      }
    });
  });
});

// ---- Separate Approve/Decline buttons (still available) ----
document.querySelectorAll('.js-approve').forEach(btn => {
  btn.addEventListener('click', () => {
    const form = btn.closest('form');
    const id = btn.dataset.id;
    Swal.fire({
      title: 'Approve this application?',
      text: 'ID ' + id + ' will be marked as Approved.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, approve',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#2e7d32'
    }).then((res) => {
      if (res.isConfirmed) {
        form.querySelector('input[name="action"]').value = 'approve';
        showSubmitting(); form.submit();
      }
    });
  });
});

document.querySelectorAll('.js-decline').forEach(btn => {
  btn.addEventListener('click', () => {
    const form = btn.closest('form');
    const id = btn.dataset.id;
    Swal.fire({
      title: 'Decline this application?',
      text: 'ID ' + id + ' will be marked as Declined.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, decline',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#c62828'
    }).then((res) => {
      if (res.isConfirmed) {
        form.querySelector('input[name="action"]').value = 'decline';
        showSubmitting(); form.submit();
      }
    });
  });
});

// ---- Success/Error toast after redirect ----
(function(){
  const params = new URLSearchParams(window.location.search);
  const msg  = params.get('toast');
  const type = params.get('toastType') || 'success';
  if (msg) {
    Swal.fire({
      toast: true,
      icon: type,
      title: msg,
      position: 'top-end',
      showConfirmButton: false,
      timer: 2000,
      timerProgressBar: true
    });
    // clean URL (remove toast params) without reload
    params.delete('toast'); params.delete('toastType');
    const clean = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
    window.history.replaceState({}, '', clean);
  }
})();

// ---- Small helpers ----
function escapeHtml(s){return (s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));}
function showSubmitting(){
  Swal.fire({
    title: 'Processing...',
    text: 'Updating status and sending email (if any).',
    allowOutsideClick: false,
    didOpen: () => { Swal.showLoading(); }
  });
}

/* =====================================================
   ADDED (functions only): Limit visible rows to 4
   - No CSS/HTML modifications.
   - We dynamically wrap the <table> in a scrollable DIV.
   - Header remains as-is; we do not restyle anything.
===================================================== */
(function(){
  const TABLE_SELECTOR = '.container > table';
  const WRAP_CLASS = 'js-table-scroll-wrap';

  function ensureWrapper(table) {
    // If already wrapped, return it
    if (table.parentElement && table.parentElement.classList.contains(WRAP_CLASS)) {
      return table.parentElement;
    }
    // Create wrapper and insert before table
    const wrap = document.createElement('div');
    wrap.className = WRAP_CLASS;
    // Only functional styles (no design changes)
    wrap.style.overflowY = 'auto';
    wrap.style.width = '100%';
    // Insert wrapper and move table inside
    table.parentElement.insertBefore(wrap, table);
    wrap.appendChild(table);
    return wrap;
  }

  function firstVisibleRow(tbody) {
    const rows = tbody ? Array.from(tbody.rows) : [];
    return rows.find(r => r && r.offsetParent !== null); // visible in layout
  }

  function setRowLimit(n) {
    const table = document.querySelector(TABLE_SELECTOR);
    if (!table) return;

    const wrap = ensureWrapper(table);
    const theadRow = table.tHead && table.tHead.rows[0] ? table.tHead.rows[0] : null;
    const tbody = table.tBodies && table.tBodies[0] ? table.tBodies[0] : null;
    if (!theadRow || !tbody) return;

    // Measure heights (fallbacks keep function safe)
    const headH = Math.ceil(theadRow.getBoundingClientRect().height || 44);
    const rowEl = firstVisibleRow(tbody) || tbody.rows[0];
    if (!rowEl) return;
    const rowH = Math.ceil(rowEl.getBoundingClientRect().height || 44);

    // Total height = header + N rows + small buffer for borders
    const total = headH + (rowH * n) + 2;

    // Apply only to the wrapper; no CSS classes changed
    wrap.style.maxHeight = total + 'px';
  }

  // Public-ish: call with 4 rows
  function applyLimit() { setRowLimit(8); }

  // Events
  window.addEventListener('load', applyLimit);
  window.addEventListener('resize', applyLimit);
  // A couple delayed runs to catch async font/layout shifts
  window.addEventListener('load', () => {
    setTimeout(applyLimit, 120);
    setTimeout(applyLimit, 300);
  });
})();

/* ==========================================================
   ADDED: SMART SEARCH (Always ON, no checkbox)
   - Enhances search for Ministry Applications without altering HTML.
   - Works on the table rows currently rendered (Pending only).
   - Features:
       "exact phrase"        -> phrase match
       -word                 -> exclude token
       type:music|women      -> OR match in Ministry Type
       position:lead         -> Position contains
       last:cruz first:juan  -> Name parts
       middle:a. ext:jr      -> Middlename / Extension
       date:2025-10-01       -> exact Date Requested
       date:2025-10-01..2025-10-31 -> range (inclusive)
       juan~                 -> fuzzy token (len>=5)
   - Highlights matches with <mark class="smart-hit">
========================================================== */

// Inject small help bubble next to the search box (no HTML edits)
(function injectSmartHelp(){
  const input = document.querySelector('.search-box');
  if (!input) return;
  const tip = document.createElement('span');
  tip.className = 'smart-help';
  tip.innerHTML = `?
    <span class="bubble">
      <div style="font-weight:700;margin-bottom:6px">Smart search (always on)</div>
      <ul style="margin:0;padding-left:16px;line-height:1.5">
        <li><code>"exact phrase"</code>, <code>-exclude</code></li>
        <li><code>type:music|women</code>, <code>position:lead</code></li>
        <li><code>last:cruz</code>, <code>first:juan</code>, <code>middle:a.</code>, <code>ext:jr</code></li>
        <li><code>date:2025-10-01</code> or <code>date:2025-10-01..2025-10-31</code></li>
        <li><code>rodriguez~</code> (fuzzy)</li>
      </ul>
    </span>`;
  input.insertAdjacentElement('afterend', tip);
})();

// Utilities
function ms_norm(s){ return (s||'').toString().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase(); }
function ms_lev(a,b){
  a=ms_norm(a); b=ms_norm(b);
  const m=[]; for(let i=0;i<=b.length;i++){ m[i]=[i]; } for(let j=0;j<=a.length;j++){ m[0][j]=j; }
  for(let i=1;i<=b.length;i++){ for(let j=1;j<=a.length;j++){
    m[i][j]=Math.min(m[i-1][j]+1, m[i][j-1]+1, m[i-1][j-1]+(a[j-1]===b[i-1]?0:1));
  }} return m[b.length][a.length];
}
function ms_parseDateToTs(s){
  if(!s) return null;
  const m=s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if(m){ const d=new Date(+m[1],+m[2]-1,+m[3]); const t=d.getTime(); return isNaN(t)?null:Math.floor(t/1000); }
  const t=Date.parse(s); return isNaN(t)?null:Math.floor(t/1000);
}
function ms_cellDateToTs(cellText){
  // example: "October 12, 2025"
  const t = Date.parse(String(cellText).trim());
  return isNaN(t) ? null : Math.floor(t/1000);
}
function ms_clearHighlights(row){
  row.querySelectorAll('mark.smart-hit').forEach(el=>{
    const p=el.parentNode; p.replaceChild(document.createTextNode(el.textContent), el); p.normalize();
  });
}
function ms_highlightRow(row, needles){
  if(!needles||!needles.length) return;
  const walker=document.createTreeWalker(row, NodeFilter.SHOW_TEXT, {
    acceptNode(node){
      if(!node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
      if(node.parentElement && (node.parentElement.tagName==='BUTTON' || node.parentElement.closest('button'))) return NodeFilter.FILTER_REJECT;
      return NodeFilter.FILTER_ACCEPT;
    }
  });
  const nodes=[]; while(walker.nextNode()) nodes.push(walker.currentNode);
  for(const n of nodes){
    const txt=n.nodeValue, low=ms_norm(txt);
    let ranges=[];
    for(const needle of needles){
      const q=ms_norm(needle); if(!q) continue;
      let idx=0; while((idx=low.indexOf(q,idx))!==-1){ ranges.push([idx,idx+q.length]); idx+=q.length; }
    }
    if(!ranges.length) continue;
    ranges.sort((a,b)=>a[0]-b[0]);
    const merged=[]; let [s,e]=ranges[0];
    for(let i=1;i<ranges.length;i++){ const [ns,ne]=ranges[i]; if(ns<=e){ e=Math.max(e,ne); } else { merged.push([s,e]); [s,e]=[ns,ne]; } }
    merged.push([s,e]);
    const frag=document.createDocumentFragment(); let last=0;
    for(const [ms,me] of merged){
      if(last<ms) frag.appendChild(document.createTextNode(txt.slice(last,ms)));
      const mark=document.createElement('mark'); mark.className='smart-hit'; mark.textContent=txt.slice(ms,me);
      frag.appendChild(mark); last=me;
    }
    if(last<txt.length) frag.appendChild(document.createTextNode(txt.slice(last)));
    n.parentNode.replaceChild(frag,n);
  }
}
function ms_parseQuery(q){
  const tokens=[], neg=[], fields={type:[], position:[], last:[], first:[], middle:[], ext:[], date:[], phrase:[]}, fuzzy=[];
  q=q.trim(); if(!q) return {tokens,neg,fields,fuzzy};
  // phrases
  const ph=[]; q=q.replace(/"([^"]+)"/g,(_,p)=>{ ph.push(p.trim()); return ' '; }); fields.phrase.push(...ph);
  // parts
  const parts=q.split(/\s+/).filter(Boolean);
  for(const part of parts){
    if(/^-/.test(part)){ neg.push(part.slice(1)); continue; }
    const m=part.match(/^(\w+):(.*)$/);
    if(m){
      const k=m[1].toLowerCase(), v=m[2];
      if(k==='type') fields.type.push(...v.split('|').map(s=>s.toLowerCase()));
      else if(k==='position') fields.position.push(v);
      else if(k==='last') fields.last.push(v);
      else if(k==='first') fields.first.push(v);
      else if(k==='middle') fields.middle.push(v);
      else if(k==='ext') fields.ext.push(v);
      else if(k==='date') fields.date.push(v);
      else tokens.push(part);
    } else if(part.endsWith('~')){ fuzzy.push(part.slice(0,-1)); }
    else if(part==='~'){ /* ignore */ }
    else { tokens.push(part); }
  }
  return {tokens,neg,fields,fuzzy};
}

(function smartSearchAlwaysOn(){
  const input = document.querySelector('.search-box');
  const tbody = document.querySelector('.container > table tbody');
  if(!input || !tbody) return;

  const rows = Array.from(tbody.querySelectorAll('tr'));

  // base quick filter (existing UX), then refine with smart rules
  input.addEventListener('input', ()=> {
    const qRaw = input.value;
    const q = ms_norm(qRaw);

    // 1) basic contains filter on full row text
    rows.forEach(r=>{
      const text = ms_norm(r.innerText);
      r.style.display = text.includes(q) ? '' : 'none';
    });

    // 2) clear previous highlights before smart refinement
    rows.forEach(ms_clearHighlights);

    // 3) smart parsing & refinement (default ON)
    const parsed = ms_parseQuery(qRaw);

    // needles for highlight (from positive pieces)
    const needles = [
      ...parsed.fields.phrase,
      ...parsed.tokens,
      ...parsed.fields.type,
      ...parsed.fields.position,
      ...parsed.fields.last,
      ...parsed.fields.first,
      ...parsed.fields.middle,
      ...parsed.fields.ext
    ];

    rows.forEach(row=>{
      if(row.style.display==='none') return; // already filtered out by quick filter

      const cDate = row.cells[0]?.innerText || '';
      const cType = row.cells[1]?.innerText || '';
      const cPos  = row.cells[2]?.innerText || '';
      const cLast = row.cells[3]?.innerText || '';
      const cFirst= row.cells[4]?.innerText || '';
      const cMid  = row.cells[5]?.innerText || '';
      const cExt  = row.cells[6]?.innerText || '';
      const flat  = `${cDate} ${cType} ${cPos} ${cLast} ${cFirst} ${cMid} ${cExt}`;

      const whole = ms_norm(flat);

      // type:
      if(parsed.fields.type.length){
        const ev = ms_norm(cType);
        const ok = parsed.fields.type.some(t => ev===t || ev.includes(t));
        if(!ok){ row.style.display='none'; return; }
      }

      // position:
      if(parsed.fields.position.length){
        const pos = ms_norm(cPos);
        const ok = parsed.fields.position.some(p => pos.includes(ms_norm(p)));
        if(!ok){ row.style.display='none'; return; }
      }

      // lastname/firstname/middlename/extension:
      if(parsed.fields.last.length){
        const ok = parsed.fields.last.some(v => ms_norm(cLast).includes(ms_norm(v)));
        if(!ok){ row.style.display='none'; return; }
      }
      if(parsed.fields.first.length){
        const ok = parsed.fields.first.some(v => ms_norm(cFirst).includes(ms_norm(v)));
        if(!ok){ row.style.display='none'; return; }
      }
      if(parsed.fields.middle.length){
        const ok = parsed.fields.middle.some(v => ms_norm(cMid).includes(ms_norm(v)));
        if(!ok){ row.style.display='none'; return; }
      }
      if(parsed.fields.ext.length){
        const ok = parsed.fields.ext.some(v => ms_norm(cExt).includes(ms_norm(v)));
        if(!ok){ row.style.display='none'; return; }
      }

      // date:
      if(parsed.fields.date.length){
        const ts = ms_cellDateToTs(cDate);
        let dateOK=false;
        for(const d of parsed.fields.date){
          if(d.includes('..')){
            const [a,b]=d.split('..');
            const ta=ms_parseDateToTs(a), tb=ms_parseDateToTs(b);
            if(ts && ta && tb && ts>=ta && ts<=tb){ dateOK=true; break; }
          } else {
            const t=ms_parseDateToTs(d);
            if(ts && t && Math.abs(ts-t)<86400){ dateOK=true; break; }
          }
        }
        if(!dateOK){ row.style.display='none'; return; }
      }

      // negative tokens
      if(parsed.neg.length){
        const bad = parsed.neg.some(n => whole.includes(ms_norm(n)));
        if(bad){ row.style.display='none'; return; }
      }

      // phrases must ALL match
      if(parsed.fields.phrase.length){
        const ok = parsed.fields.phrase.every(p => whole.includes(ms_norm(p)));
        if(!ok){ row.style.display='none'; return; }
      }

      // plain tokens: AND
      if(parsed.tokens.length){
        const ok = parsed.tokens.every(t => whole.includes(ms_norm(t)));
        if(!ok){ row.style.display='none'; return; }
      }

      // fuzzy tokens: ANY may match (distance<=2 for len>=5)
      if(parsed.fuzzy.length){
        const ok = parsed.fuzzy.some(f => {
          if(whole.includes(ms_norm(f))) return true;
          if(f.length>=5) return ms_lev(flat, f) <= 2;
          return false;
        });
        if(!ok){ row.style.display='none'; return; }
      }

      // highlight positives
      ms_highlightRow(row, needles);
    });
  });

  // trigger once so it's default-on
  window.addEventListener('load', ()=> input.dispatchEvent(new Event('input')));
})();
</script>

</body>
</html>
