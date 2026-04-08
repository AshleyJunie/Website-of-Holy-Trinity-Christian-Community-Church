<?php
require_once 'db-connection.php';

// PHPMailer (same pattern as your OTP mailer)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

// MAIL CREDENTIALS (protect against re-defining)
if (!defined('MAIL_USER')) {
    define('MAIL_USER', 'holytrinitychristiancommunityc@gmail.com');
}
if (!defined('MAIL_PASS')) {
    define('MAIL_PASS', 'jngx vtqb urun yjur');
}

// helper for safe HTML output
if (!function_exists('esc')) {
    function esc($str) {
        return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
    }
}

$restrictionSuccess  = false;
$restrictionError    = '';
$unrestrictSuccess   = false;
$unrestrictError     = '';

// function to send restriction email
function sendRestrictionEmail($toEmail, $toName, $reasonText) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USER;
        $mail->Password   = MAIL_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->setFrom(MAIL_USER, 'HTCCC Admin');
        $mail->addAddress($toEmail, $toName ?: $toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Your HTCCC Account Has Been Restricted';

        $safeName    = esc($toName ?: 'Member');
        $safeReasons = array_map('trim', explode(';', $reasonText));

        $body  = "<p>Dear {$safeName},</p>";
        $body .= "<p>Your HTCCC online account has been <strong>restricted (Suspended)</strong> by an administrator.</p>";
        $body .= "<p><strong>Reason(s) for this restriction:</strong></p><ul>";
        foreach ($safeReasons as $r) {
            if ($r !== '') {
                $body .= '<li>' . esc($r) . '</li>';
            }
        }
        $body .= "</ul>";
        $body .= "<p>If you believe this is a mistake or if you have questions, please contact the church office for clarification.</p>";
        $body .= "<p>Blessings,<br>Holy Trinity Christian Community Church</p>";

        $mail->Body    = $body;
        $mail->AltBody = "Your HTCCC online account has been restricted. Reason(s): " . $reasonText;

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// function to send unrestrict email
function sendUnrestrictEmail($toEmail, $toName) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USER;
        $mail->Password   = MAIL_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->setFrom(MAIL_USER, 'HTCCC Admin');
        $mail->addAddress($toEmail, $toName ?: $toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Your HTCCC Account Has Been Re-Activated';

        $safeName = esc($toName ?: 'Member');

        $body  = "<p>Dear {$safeName},</p>";
        $body .= "<p>Your HTCCC online account has been <strong>re-activated</strong> by an administrator. ";
        $body .= "You may now log in and continue using the online services.</p>";
        $body .= "<p>If you have any questions, please contact the church office.</p>";
        $body .= "<p>Blessings,<br>Holy Trinity Christian Community Church</p>";

        $mail->Body    = $body;
        $mail->AltBody = "Your HTCCC online account has been re-activated.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// --- Handle Restrict form submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restrict_individual_id'])) {
    $individualId = (int)($_POST['restrict_individual_id'] ?? 0);
    $email        = trim($_POST['restrict_email'] ?? '');
    $fullName     = trim($_POST['restrict_fullname'] ?? '');

    $reasons = $_POST['restrict_reason'] ?? [];
    if (!is_array($reasons)) {
        $reasons = [];
    }

    $otherReason = trim($_POST['restrict_other'] ?? '');
    if ($otherReason !== '') {
        $reasons[] = $otherReason;
    }

    if (empty($reasons)) {
        $restrictionError = 'Please select at least one reason or specify in Others.';
    } elseif ($individualId <= 0) {
        $restrictionError = 'Invalid individual selected.';
    } else {
        $reasonText = implode('; ', $reasons);

        $stmt = mysqli_prepare(
            $db_connection,
            "UPDATE individual_table SET account_status = 'Suspended' WHERE individual_id = ?"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $individualId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($email !== '') {
                sendRestrictionEmail($email, $fullName, $reasonText);
            }

            $restrictionSuccess = true;
        } else {
            $restrictionError = 'Database error while restricting account.';
        }
    }
}

// --- Handle Unrestrict form submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unrestrict_individual_id'])) {
    $individualId = (int)($_POST['unrestrict_individual_id'] ?? 0);
    $email        = trim($_POST['unrestrict_email'] ?? '');
    $fullName     = trim($_POST['unrestrict_fullname'] ?? '');

    if ($individualId <= 0) {
        $unrestrictError = 'Invalid individual selected for unrestriction.';
    } else {
        $stmt = mysqli_prepare(
            $db_connection,
            "UPDATE individual_table SET account_status = 'Active' WHERE individual_id = ?"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $individualId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($email !== '') {
                sendUnrestrictEmail($email, $fullName);
            }

            $unrestrictSuccess = true;
        } else {
            $unrestrictError = 'Database error while unrestricting account.';
        }
    }
}

// Fetch all individuals EXCLUDING Suspended
$sql = "
    SELECT 
        individual_id,
        individual_lastname,
        individual_firstname,
        individual_middlename,
        individual_extension,
        individual_baptised,
        account_status,
        individual_birthday,
        individual_gender,
        individual_phone_number,
        individual_email_address,
        individual_street,
        individual_city,
        individual_zip_code,
        civil_status,
        img_baptismal_cert,
        baptised_church,
        baptism_verification
    FROM individual_table
    WHERE account_status <> 'Suspended' OR account_status IS NULL
    ORDER BY individual_lastname, individual_firstname
";
$result = mysqli_query($db_connection, $sql);

// Fetch only suspended individuals
$sqlSuspended = "
    SELECT 
        individual_id,
        individual_lastname,
        individual_firstname,
        individual_middlename,
        individual_extension,
        individual_baptised,
        account_status,
        individual_birthday,
        individual_gender,
        individual_phone_number,
        individual_email_address,
        individual_street,
        individual_city,
        individual_zip_code,
        civil_status,
        img_baptismal_cert,
        baptised_church,
        baptism_verification
    FROM individual_table
    WHERE account_status = 'Suspended'
    ORDER BY individual_lastname, individual_firstname
";
$resultSuspended = mysqli_query($db_connection, $sqlSuspended);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1"/>
<title>Admin – Individual List</title>

<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
:root{
  --bg:#f6f9fc;
  --nav:#00216e;
  --line:#e6edf5;
  --muted:#6b7280;
  --panel:#ffffff;
  --shadow:0 10px 30px rgba(16,24,40,.08);
  --table-header:#003474;
  --table-header-text:#ffffff;
  --primary:#007bff;
  --danger:#dc2626;
}
*{box-sizing:border-box}
body{
  margin:0;
  background:var(--bg);
  font-family:"Inter", sans-serif;
  color:#111827;
}

/* Sidebar only */
.sidebar{
  position:fixed; inset:0 auto 0 0;
  width:280px; background:var(--nav); color:#fff;
  display:flex; flex-direction:column; padding:18px 16px; overflow-y:auto;
}
.brand{ display:flex; align-items:center; gap:10px; font-weight:800; letter-spacing:.5px; }
.brand img{ width:26px; height:26px; border-radius:6px; }
.user-card{ display:flex; gap:12px; align-items:center; padding:12px 8px; background:rgba(255,255,255,.05); border-radius:12px; margin:14px 0; }
.user-card img{ width:40px; height:40px; border-radius:999px; }
.user-title{ font-weight:700 }
.user-sub{ font-size:12px; color:#cbd5e1 }
.nav{ display:flex; flex-direction:column; gap:6px; }
.section-title{ margin-top:12px; margin-bottom:6px; font-size:11px; letter-spacing:.08em; color:#93a4b8; text-transform:uppercase }
.navlink{
  display:flex; align-items:center; gap:10px; color:#e2e8f0; text-decoration:none; padding:10px 12px; border-radius:10px;
}
.navlink:hover{ background:rgba(255,255,255,.06) }
.navlink.active{ background:#1f2937; color:#fff }
.navlink.logout{ color:#fca5a5 }

/* Main content */
.main{
  margin-left:280px;
  padding:24px 28px 40px;
}
.page-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  margin-bottom:18px;
}
.page-title{
  font-size:24px;
  font-weight:700;
}
.page-subtitle{
  font-size:13px;
  color:var(--muted);
}

/* Table card */
.table-card{
  background:var(--panel);
  border-radius:16px;
  box-shadow:var(--shadow);
  padding:18px 20px;
}

/* Smart-sort toolbar */
.table-toolbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-bottom:12px;
  flex-wrap:wrap;
}
.sort-group{
  display:flex;
  align-items:center;
  gap:8px;
  font-size:13px;
}
.sort-label{
  font-weight:500;
  color:var(--muted);
}
.sort-select{
  padding:6px 12px;
  border-radius:20px;
  border:1px solid var(--line);
  font-size:13px;
  background:#f9fafb;
}
.sort-header{
  font-size:14px;
  font-weight:600;
  color:#111827;
}

/* Search input */
.search-input{
  padding:6px 12px;
  border-radius:20px;
  border:1px solid var(--line);
  font-size:13px;
  background:#ffffff;
  min-width:220px;
}

/* Table styles */
.table-wrapper{
  overflow-x:auto;
}
.table-wrapper.scrollable{
  max-height:400px;
  overflow-y:auto;
}
table{
  width:100%;
  border-collapse:collapse;
  font-size:14px;
  background:#ffffff;
}
thead{
  background:var(--table-header);
}
th, td{
  padding:12px 16px;
  text-align:left;
  white-space:nowrap;
  border-bottom:1px solid #e5e7eb;
}
th{
  font-size:14px;
  font-weight:600;
  color:var(--table-header-text);
}
tbody tr:nth-child(even){
  background:#fdfdfd;
}
tbody tr:hover td{
  background:#f1f5f9;
}

/* Status pills */
.badge{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:4px 14px;
  border-radius:999px;
  font-size:12px;
  font-weight:500;
  border:1px solid transparent;
  min-width:80px;
}
.badge-success{
  background:rgba(34,197,94,0.08);
  color:#16a34a;
  border-color:#bbf7d0;
}
.badge-warning{
  background:#f8fafc;
  color:#6b7280;
  border-color:#e5e7eb;
}
.badge-danger{
  background:#fef2f2;
  color:#b91c1c;
  border-color:#fecaca;
}

/* Buttons */
.btn{
  display:inline-flex;
  align-items:center;
  gap:6px;
  font-size:13px;
  border-radius:6px;
  padding:6px 14px;
  border:none;
  cursor:pointer;
}
.btn + .btn{
  margin-left:6px;
}
.btn-view{
  background:var(--primary);
  color:#fff;
}
.btn-view i{
  font-size:13px;
}
.btn-view:hover{
  filter:brightness(0.93);
}
.btn-restrict{
  background:var(--danger);
  color:#fff;
}
.btn-restrict:hover{
  filter:brightness(0.93);
}
.btn-outline{
  background:#fff;
  border:1px solid var(--line);
}

/* Modal */
.modal-backdrop{
  position:fixed;
  inset:0;
  background:rgba(15,23,42,.55);
  display:none;
  align-items:center;
  justify-content:center;
  z-index:1000;
}
.modal-backdrop.show{
  display:flex;
}
.modal{
  background:#fff;
  border-radius:16px;
  max-width:900px;
  width:95%;
  max-height:90vh;
  overflow:auto;
  box-shadow:var(--shadow);
}
.modal-header{
  padding:16px 20px;
  border-bottom:1px solid var(--line);
  display:flex;
  justify-content:space-between;
  align-items:center;
}
.modal-title{
  font-size:18px;
  font-weight:600;
}
.modal-close{
  background:none;
  border:none;
  font-size:20px;
  cursor:pointer;
}
.modal-body{
  padding:18px 20px 20px;
  font-size:14px;
}
.modal-grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(230px,1fr));
  gap:12px 30px;
}
.field-label{
  font-size:11px;
  text-transform:uppercase;
  letter-spacing:.06em;
  color:var(--muted);
  margin-bottom:2px;
}
.field-value{
  font-weight:500;
}
.modal-section-title{
  margin-top:14px;
  margin-bottom:8px;
  font-size:13px;
  font-weight:600;
  border-top:1px solid var(--line);
  padding-top:10px;
}
.cert-image{
  max-width:100%;
  max-height:250px;
  border-radius:10px;
  border:1px solid var(--line);
  object-fit:contain;
}

/* Restrict modal specific */
.reason-list label{
  display:block;
  margin-bottom:6px;
}
.reason-list input[type="checkbox"]{
  margin-right:6px;
}
#reason_other_text{
  width:100%;
  margin-top:6px;
  resize:vertical;
}
.modal-footer{
  display:flex;
  justify-content:flex-end;
  gap:8px;
  margin-top:16px;
}
</style>
</head>
<body>

  <!-- SIDEBAR -->
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

      <div class="section-title">Individual Management</div>
      <a class="navlink active" href="admin-individual-list.php">
        <i class="fas fa-user"></i>Individual List
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

  <!-- MAIN CONTENT -->
  <main class="main">
    <div class="page-header">
      <div>
        <div class="page-title">Individual List</div>
      </div>
      <button class="btn btn-outline" id="btnShowRestrictedAccounts">
        <i class="fas fa-ban"></i> Restricted Accounts
      </button>
    </div>

    <div class="table-card">
      <!-- SMART SORT TOOLBAR -->
      <div class="table-toolbar">
        <div class="sort-group">
          <span class="sort-label"><i class="fas fa-filter"></i> Smart Sort:</span>
          <select id="filterBaptismStatus" class="sort-select">
            <option value="">All Accounts</option>
            <option value="baptised">Baptised Accounts</option>
            <option value="non-baptised">Non-Baptised Accounts</option>
          </select>
        </div>

        <div class="sort-group">
          <span class="sort-label"><i class="fas fa-search"></i> Search:</span>
          <input
            type="text"
            id="mainTableSearch"
            class="search-input"
            placeholder="Search by name, email, status..."
          />
        </div>

        <div class="sort-group">
          <button type="button" class="btn btn-outline" id="btnSortMainSurname">
            <i class="fas fa-sort-alpha-down"></i> Sort Surname A–Z / Z–A
          </button>
        </div>

        <div id="filterHeader" class="sort-header">
          All Accounts
        </div>
      </div>

      <div class="table-wrapper" id="tableWrapper">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Lastname</th>
              <th>Firstname</th>
              <th>Middle Name</th>
              <th>Ext.</th>
              <th>Baptised</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody id="mainTableBody">
          <?php
          if ($result && mysqli_num_rows($result) > 0):
              $i = 1;
              while ($row = mysqli_fetch_assoc($result)):
                  $status = $row['account_status'];
                  $badgeClass = 'badge-warning';
                  if ($status === 'Active') $badgeClass = 'badge-success';
                  elseif ($status === 'Suspended') $badgeClass = 'badge-danger';

                  $baptismVerification = $row['baptism_verification'];
                  $fullName = trim($row['individual_firstname'] . ' ' . $row['individual_lastname']);
          ?>
            <tr 
              class="data-row"
              data-baptism-verification="<?php echo esc($baptismVerification); ?>"
            >
              <td class="row-index"><?php echo $i++; ?></td>
              <td><?php echo esc($row['individual_lastname']); ?></td>
              <td><?php echo esc($row['individual_firstname']); ?></td>
              <td><?php echo esc($row['individual_middlename']); ?></td>
              <td><?php echo esc($row['individual_extension']); ?></td>
              <td><?php echo esc($row['individual_baptised']); ?></td>
              <td><span class="badge <?php echo $badgeClass; ?>"><?php echo esc($status); ?></span></td>
              <td>
                <button
                  class="btn btn-view btn-open-modal"
                  data-lastname="<?php echo esc($row['individual_lastname']); ?>"
                  data-firstname="<?php echo esc($row['individual_firstname']); ?>"
                  data-middlename="<?php echo esc($row['individual_middlename']); ?>"
                  data-extension="<?php echo esc($row['individual_extension']); ?>"

                  data-baptised="<?php echo esc($row['individual_baptised']); ?>"
                  data-account-status="<?php echo esc($row['account_status']); ?>"

                  data-birthday="<?php echo esc($row['individual_birthday']); ?>"
                  data-gender="<?php echo esc($row['individual_gender']); ?>"

                  data-phone="<?php echo esc($row['individual_phone_number']); ?>"
                  data-email="<?php echo esc($row['individual_email_address']); ?>"

                  data-street="<?php echo esc($row['individual_street']); ?>"
                  data-city="<?php echo esc($row['individual_city']); ?>"
                  data-zip="<?php echo esc($row['individual_zip_code']); ?>"
                  data-civil-status="<?php echo esc($row['civil_status']); ?>"

                  data-baptismal-cert="<?php echo esc($row['img_baptismal_cert']); ?>"
                  data-baptised-church="<?php echo esc($row['baptised_church']); ?>"
                >
                  <i class="fas fa-eye"></i> View
                </button>

                <button
                  type="button"
                  class="btn btn-restrict btn-restrict-open"
                  data-id="<?php echo (int)$row['individual_id']; ?>"
                  data-email="<?php echo esc($row['individual_email_address']); ?>"
                  data-fullname="<?php echo esc($fullName); ?>"
                >
                  <i class="fas fa-ban"></i> Restrict
                </button>
              </td>
            </tr>
          <?php
              endwhile;
          ?>
            <tr id="noMatchesRow" style="display:none;">
              <td colspan="8">No matching records found.</td>
            </tr>
          <?php
          else:
          ?>
            <tr id="noRecordsRow">
              <td colspan="8">No records found.</td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>

  <!-- VIEW MODAL (main list) -->
  <div class="modal-backdrop" id="viewModalBackdrop">
    <div class="modal">
      <div class="modal-header">
        <div class="modal-title"><i class="fas fa-user"></i> Individual Details</div>
        <button class="modal-close" id="modalCloseBtn">&times;</button>
      </div>
      <div class="modal-body">
        <div class="modal-section-title">Basic Information</div>
        <div class="modal-grid">
          <div>
            <div class="field-label">Last Name</div>
            <div class="field-value" id="m_lastname"></div>
          </div>
          <div>
            <div class="field-label">First Name</div>
            <div class="field-value" id="m_firstname"></div>
          </div>
          <div>
            <div class="field-label">Middle Name</div>
            <div class="field-value" id="m_middlename"></div>
          </div>
          <div>
            <div class="field-label">Extension</div>
            <div class="field-value" id="m_extension"></div>
          </div>
          <div>
            <div class="field-label">Birthday</div>
            <div class="field-value" id="m_birthday"></div>
          </div>
          <div>
            <div class="field-label">Gender</div>
            <div class="field-value" id="m_gender"></div>
          </div>
          <div>
            <div class="field-label">Civil Status</div>
            <div class="field-value" id="m_civil_status"></div>
          </div>
        </div>

        <div class="modal-section-title">Baptism Information</div>
        <div class="modal-grid">
          <div>
            <div class="field-label">Baptised</div>
            <div class="field-value" id="m_baptised"></div>
          </div>
          <div>
            <div class="field-label">Account Status</div>
            <div class="field-value" id="m_account_status"></div>
          </div>
          <div>
            <div class="field-label">Baptised Church</div>
            <div class="field-value" id="m_baptised_church"></div>
          </div>
        </div>

        <div class="modal-section-title">Contact Information</div>
        <div class="modal-grid">
          <div>
            <div class="field-label">Phone Number</div>
            <div class="field-value" id="m_phone"></div>
          </div>
          <div>
            <div class="field-label">Email Address</div>
            <div class="field-value" id="m_email"></div>
          </div>
          <div>
            <div class="field-label">Street</div>
            <div class="field-value" id="m_street"></div>
          </div>
          <div>
            <div class="field-label">City</div>
            <div class="field-value" id="m_city"></div>
          </div>
          <div>
            <div class="field-label">ZIP Code</div>
            <div class="field-value" id="m_zip"></div>
          </div>
        </div>

        <div class="modal-section-title">Baptismal Certificate</div>
        <div class="modal-grid">
          <div>
            <div class="field-label">Certificate Image</div>
            <div id="m_cert_container">
              <img id="m_baptismal_cert" class="cert-image" src="" alt="Baptismal Certificate" style="display:none;">
              <div id="m_cert_text" class="field-value"></div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- RESTRICT MODAL -->
  <div class="modal-backdrop" id="restrictModalBackdrop">
    <div class="modal">
      <div class="modal-header">
        <div class="modal-title"><i class="fas fa-ban"></i> Restrict Account</div>
        <button class="modal-close" id="restrictModalCloseBtn">&times;</button>
      </div>
      <form method="post">
        <div class="modal-body">
          <p style="margin-top:0; margin-bottom:10px;">
            Please select the reason(s) why this account should be restricted (Suspended):
          </p>
          <div class="reason-list">
            <label>
              <input type="checkbox" class="restrict-reason-checkbox" name="restrict_reason[]" value="Submitted invalid or fake baptismal certificate">
              Submitted invalid or fake baptismal certificate
            </label>
            <label>
              <input type="checkbox" class="restrict-reason-checkbox" name="restrict_reason[]" value="Information provided does not match church records">
              Information provided does not match church records
            </label>
            <label>
              <input type="checkbox" class="restrict-reason-checkbox" name="restrict_reason[]" value="Multiple or duplicate accounts detected">
              Multiple or duplicate accounts detected
            </label>
            <label>
              <input type="checkbox" class="restrict-reason-checkbox" name="restrict_reason[]" value="Violation of HTCCC online guidelines or policies">
              Violation of HTCCC online guidelines or policies
            </label>
            <label>
              <input type="checkbox" class="restrict-reason-checkbox" name="restrict_reason[]" value="Reported inappropriate or abusive behavior">
              Reported inappropriate or abusive behavior
            </label>
            <label>
              <input type="checkbox" class="restrict-reason-checkbox" name="restrict_reason[]" value="Member requested for account deactivation / restriction">
              Member requested for account deactivation / restriction
            </label>
            <label>
              <input type="checkbox" id="reason_other_checkbox">
              Others (please specify)
            </label>
            <textarea name="restrict_other" id="reason_other_text" rows="3" placeholder="Type other reason here..." disabled></textarea>
          </div>

          <input type="hidden" name="restrict_individual_id" id="restrict_individual_id">
          <input type="hidden" name="restrict_email" id="restrict_email">
          <input type="hidden" name="restrict_fullname" id="restrict_fullname">

          <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="restrictCancelBtn">Cancel</button>
            <button type="submit" class="btn btn-restrict">Confirm Restriction</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- RESTRICTED ACCOUNTS MODAL -->
  <div class="modal-backdrop" id="restrictedListModalBackdrop">
    <div class="modal">
      <div class="modal-header">
        <div class="modal-title"><i class="fas fa-ban"></i> Restricted Accounts</div>
        <button class="modal-close" id="restrictedListModalCloseBtn">&times;</button>
      </div>
      <div class="modal-body">
        <p style="margin-top:0; margin-bottom:10px;">
          Below are all accounts with status <strong>Suspended</strong>.
        </p>

        <!-- Smart search + sort inside Restricted Accounts modal -->
        <div class="table-toolbar" style="margin-bottom:10px;">
          <div class="sort-group">
            <span class="sort-label"><i class="fas fa-search"></i> Search:</span>
            <input
              type="text"
              id="restrictedSearchInput"
              class="search-input"
              placeholder="Search restricted accounts..."
            />
          </div>
          <div class="sort-group">
            <button type="button" class="btn btn-outline" id="btnSortRestrictedSurname">
              <i class="fas fa-sort-alpha-down"></i> Sort Surname A–Z / Z–A
            </button>
          </div>
        </div>

        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Lastname</th>
                <th>Firstname</th>
                <th>Middle Name</th>
                <th>Ext.</th>
                <th>Baptised</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="restrictedTableBody">
            <?php
            if ($resultSuspended && mysqli_num_rows($resultSuspended) > 0):
                $j = 1;
                while ($rowS = mysqli_fetch_assoc($resultSuspended)):
                    $statusS = $rowS['account_status'];
                    $badgeClassS = 'badge-warning';
                    if ($statusS === 'Active') $badgeClassS = 'badge-success';
                    elseif ($statusS === 'Suspended') $badgeClassS = 'badge-danger';

                    $fullNameS = trim($rowS['individual_firstname'] . ' ' . $rowS['individual_lastname']);
            ?>
              <tr class="restricted-row">
                <td class="row-index"><?php echo $j++; ?></td>
                <td><?php echo esc($rowS['individual_lastname']); ?></td>
                <td><?php echo esc($rowS['individual_firstname']); ?></td>
                <td><?php echo esc($rowS['individual_middlename']); ?></td>
                <td><?php echo esc($rowS['individual_extension']); ?></td>
                <td><?php echo esc($rowS['individual_baptised']); ?></td>
                <td><span class="badge <?php echo $badgeClassS; ?>"><?php echo esc($statusS); ?></span></td>
                <td>
                  <!-- View: opens a NEW modal on top of Restricted Accounts modal -->
                  <button
                    class="btn btn-view btn-view-restricted"
                    data-lastname="<?php echo esc($rowS['individual_lastname']); ?>"
                    data-firstname="<?php echo esc($rowS['individual_firstname']); ?>"
                    data-middlename="<?php echo esc($rowS['individual_middlename']); ?>"
                    data-extension="<?php echo esc($rowS['individual_extension']); ?>"

                    data-baptised="<?php echo esc($rowS['individual_baptised']); ?>"
                    data-account-status="<?php echo esc($rowS['account_status']); ?>"

                    data-birthday="<?php echo esc($rowS['individual_birthday']); ?>"
                    data-gender="<?php echo esc($rowS['individual_gender']); ?>"

                    data-phone="<?php echo esc($rowS['individual_phone_number']); ?>"
                    data-email="<?php echo esc($rowS['individual_email_address']); ?>"

                    data-street="<?php echo esc($rowS['individual_street']); ?>"
                    data-city="<?php echo esc($rowS['individual_city']); ?>"
                    data-zip="<?php echo esc($rowS['individual_zip_code']); ?>"
                    data-civil-status="<?php echo esc($rowS['civil_status']); ?>"

                    data-baptismal-cert="<?php echo esc($rowS['img_baptismal_cert']); ?>"
                    data-baptised-church="<?php echo esc($rowS['baptised_church']); ?>"
                  >
                    <i class="fas fa-eye"></i> View
                  </button>

                  <button
                    type="button"
                    class="btn btn-outline btn-unrestrict-open"
                    data-id="<?php echo (int)$rowS['individual_id']; ?>"
                    data-email="<?php echo esc($rowS['individual_email_address']); ?>"
                    data-fullname="<?php echo esc($fullNameS); ?>"
                  >
                    <i class="fas fa-unlock"></i> Unrestrict
                  </button>
                </td>
              </tr>
            <?php
                endwhile;
            else:
            ?>
              <tr>
                <td colspan="8">No restricted accounts found.</td>
              </tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Hidden form used by Unrestrict buttons -->
        <form method="post" id="unrestrictForm" style="display:none;">
          <input type="hidden" name="unrestrict_individual_id" id="unrestrict_individual_id">
          <input type="hidden" name="unrestrict_email" id="unrestrict_email">
          <input type="hidden" name="unrestrict_fullname" id="unrestrict_fullname">
        </form>
      </div>
    </div>
  </div>

  <!-- NESTED VIEW MODAL (for Restricted Accounts list) -->
  <div class="modal-backdrop" id="viewRestrictedModalBackdrop" style="z-index:1100;">
    <div class="modal">
      <div class="modal-header">
        <div class="modal-title"><i class="fas fa-user"></i> Individual Details</div>
        <button class="modal-close" id="viewRestrictedModalCloseBtn">&times;</button>
      </div>
      <div class="modal-body">
        <div class="modal-section-title">Basic Information</div>
        <div class="modal-grid">
          <div>
            <div class="field-label">Last Name</div>
            <div class="field-value" id="vr_lastname"></div>
          </div>
          <div>
            <div class="field-label">First Name</div>
            <div class="field-value" id="vr_firstname"></div>
          </div>
          <div>
            <div class="field-label">Middle Name</div>
            <div class="field-value" id="vr_middlename"></div>
          </div>
          <div>
            <div class="field-label">Extension</div>
            <div class="field-value" id="vr_extension"></div>
          </div>
          <div>
            <div class="field-label">Birthday</div>
            <div class="field-value" id="vr_birthday"></div>
          </div>
          <div>
            <div class="field-label">Gender</div>
            <div class="field-value" id="vr_gender"></div>
          </div>
          <div>
            <div class="field-label">Civil Status</div>
            <div class="field-value" id="vr_civil_status"></div>
          </div>
        </div>

        <div class="modal-section-title">Baptism Information</div>
        <div class="modal-grid">
          <div>
            <div class="field-label">Baptised</div>
            <div class="field-value" id="vr_baptised"></div>
          </div>
          <div>
            <div class="field-label">Account Status</div>
            <div class="field-value" id="vr_account_status"></div>
          </div>
          <div>
            <div class="field-label">Baptised Church</div>
            <div class="field-value" id="vr_baptised_church"></div>
          </div>
        </div>

        <div class="modal-section-title">Contact Information</div>
        <div class="modal-grid">
          <div>
            <div class="field-label">Phone Number</div>
            <div class="field-value" id="vr_phone"></div>
          </div>
          <div>
            <div class="field-label">Email Address</div>
            <div class="field-value" id="vr_email"></div>
          </div>
          <div>
            <div class="field-label">Street</div>
            <div class="field-value" id="vr_street"></div>
          </div>
          <div>
            <div class="field-label">City</div>
            <div class="field-value" id="vr_city"></div>
          </div>
          <div>
            <div class="field-label">ZIP Code</div>
            <div class="field-value" id="vr_zip"></div>
          </div>
        </div>

        <div class="modal-section-title">Baptismal Certificate</div>
        <div class="modal-grid">
          <div>
            <div class="field-label">Certificate Image</div>
            <div>
              <img id="vr_baptismal_cert" class="cert-image" src="" alt="Baptismal Certificate" style="display:none;">
              <div id="vr_cert_text" class="field-value"></div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

<script>
// Helper to fallback to N/A
function displayValue(value){
  if(!value || value.trim() === '') return 'N/A';
  return value;
}

document.addEventListener('DOMContentLoaded', function(){
  const modalBackdrop   = document.getElementById('viewModalBackdrop');
  const modalCloseBtn   = document.getElementById('modalCloseBtn');
  const filterSelect    = document.getElementById('filterBaptismStatus');
  const filterHeader    = document.getElementById('filterHeader');
  const tableWrapper    = document.getElementById('tableWrapper');
  const mainTableBody   = document.getElementById('mainTableBody');
  const dataRows        = Array.from(document.querySelectorAll('#mainTableBody tr.data-row'));
  const mainSearchInput = document.getElementById('mainTableSearch');
  const noMatchesRow    = document.getElementById('noMatchesRow');
  const btnSortMainSurname = document.getElementById('btnSortMainSurname');

  let mainSortAsc = true; // toggle flag for main table surname sort

  const restrictModalBackdrop = document.getElementById('restrictModalBackdrop');
  const restrictModalCloseBtn = document.getElementById('restrictModalCloseBtn');
  const restrictCancelBtn     = document.getElementById('restrictCancelBtn');
  const restrictIdInput       = document.getElementById('restrict_individual_id');
  const restrictEmailInput    = document.getElementById('restrict_email');
  const restrictFullnameInput = document.getElementById('restrict_fullname');
  const reasonOtherCheckbox   = document.getElementById('reason_other_checkbox');
  const reasonOtherText       = document.getElementById('reason_other_text');

  const restrictedListModalBackdrop = document.getElementById('restrictedListModalBackdrop');
  const restrictedListModalCloseBtn = document.getElementById('restrictedListModalCloseBtn');
  const btnShowRestrictedAccounts   = document.getElementById('btnShowRestrictedAccounts');

  const restrictedSearchInput     = document.getElementById('restrictedSearchInput');
  const restrictedTableBody       = document.getElementById('restrictedTableBody');
  const btnSortRestrictedSurname  = document.getElementById('btnSortRestrictedSurname');
  const restrictedRows            = Array.from(document.querySelectorAll('#restrictedTableBody tr.restricted-row'));

  let restrictedSortAsc = true; // toggle flag for restricted table surname sort

  const unrestrictForm        = document.getElementById('unrestrictForm');
  const unrestrictIdInput     = document.getElementById('unrestrict_individual_id');
  const unrestrictEmailInput  = document.getElementById('unrestrict_email');
  const unrestrictFullnameInput = document.getElementById('unrestrict_fullname');

  const viewRestrictedModalBackdrop = document.getElementById('viewRestrictedModalBackdrop');
  const viewRestrictedModalCloseBtn = document.getElementById('viewRestrictedModalCloseBtn');

  // fields for nested restricted view modal
  const vr_lastname       = document.getElementById('vr_lastname');
  const vr_firstname      = document.getElementById('vr_firstname');
  const vr_middlename     = document.getElementById('vr_middlename');
  const vr_extension      = document.getElementById('vr_extension');
  const vr_birthday       = document.getElementById('vr_birthday');
  const vr_gender         = document.getElementById('vr_gender');
  const vr_civil_status   = document.getElementById('vr_civil_status');
  const vr_baptised       = document.getElementById('vr_baptised');
  const vr_account_status = document.getElementById('vr_account_status');
  const vr_baptised_church= document.getElementById('vr_baptised_church');
  const vr_phone          = document.getElementById('vr_phone');
  const vr_email          = document.getElementById('vr_email');
  const vr_street         = document.getElementById('vr_street');
  const vr_city           = document.getElementById('vr_city');
  const vr_zip            = document.getElementById('vr_zip');
  const vr_cert_img       = document.getElementById('vr_baptismal_cert');
  const vr_cert_text      = document.getElementById('vr_cert_text');

  // --------- MODAL (view main) ----------
  function openModal(btn){
    document.getElementById('m_lastname').textContent       = displayValue(btn.dataset.lastname);
    document.getElementById('m_firstname').textContent      = displayValue(btn.dataset.firstname);
    document.getElementById('m_middlename').textContent     = displayValue(btn.dataset.middlename);
    document.getElementById('m_extension').textContent      = displayValue(btn.dataset.extension);

    document.getElementById('m_baptised').textContent       = displayValue(btn.dataset.baptised);
    document.getElementById('m_account_status').textContent = displayValue(btn.dataset.accountStatus);

    document.getElementById('m_birthday').textContent       = displayValue(btn.dataset.birthday);
    document.getElementById('m_gender').textContent         = displayValue(btn.dataset.gender);

    document.getElementById('m_phone').textContent          = displayValue(btn.dataset.phone);
    document.getElementById('m_email').textContent          = displayValue(btn.dataset.email);

    document.getElementById('m_street').textContent         = displayValue(btn.dataset.street);
    document.getElementById('m_city').textContent           = displayValue(btn.dataset.city);
    document.getElementById('m_zip').textContent            = displayValue(btn.dataset.zip);
    document.getElementById('m_civil_status').textContent   = displayValue(btn.dataset.civilStatus);

    document.getElementById('m_baptised_church').textContent = displayValue(btn.dataset.baptisedChurch);

    const certPath = btn.dataset.baptismalCert;
    const imgEl = document.getElementById('m_baptismal_cert');
    const textEl = document.getElementById('m_cert_text');

    if(certPath && certPath.trim() !== ''){
      imgEl.src = certPath;
      imgEl.style.display = 'block';
      textEl.textContent = '';
    }else{
      imgEl.style.display = 'none';
      textEl.textContent = 'No certificate uploaded.';
    }

    modalBackdrop.classList.add('show');
  }

  function closeModal(){
    modalBackdrop.classList.remove('show');
  }

  document.querySelectorAll('.btn-open-modal').forEach(function(btn){
    btn.addEventListener('click', function(){
      openModal(this);
    });
  });

  if(modalCloseBtn){
    modalCloseBtn.addEventListener('click', closeModal);
  }
  if(modalBackdrop){
    modalBackdrop.addEventListener('click', function(e){
      if(e.target === modalBackdrop){
        closeModal();
      }
    });
  }

  // --------- SMART FILTER + SEARCH (main table) ----------
  function applyFilter(){
    const value = filterSelect ? filterSelect.value : '';
    const term  = mainSearchInput ? mainSearchInput.value.trim().toLowerCase() : '';
    let visibleCount = 0;

    dataRows.forEach(function(row){
      const verification = (row.dataset.baptismVerification || '').trim();
      let show = true;

      if(value === 'baptised'){
        show = (verification === 'Verified');
      }else if(value === 'non-baptised'){
        show = (verification === 'NonVerified');
      }else{
        show = true;
      }

      if(term !== ''){
        const text = row.textContent.toLowerCase();
        if(!text.includes(term)){
          show = false;
        }
      }

      row.style.display = show ? '' : 'none';
      if(show) visibleCount++;
    });

    if(filterHeader){
      if(value === 'baptised'){
        filterHeader.textContent = 'Baptised Accounts (' + visibleCount + ')';
      }else if(value === 'non-baptised'){
        filterHeader.textContent = 'Non-Baptised Accounts (' + visibleCount + ')';
      }else{
        filterHeader.textContent = 'All Accounts (' + visibleCount + ')';
      }
    }

    if(noMatchesRow){
      noMatchesRow.style.display = (visibleCount === 0 ? '' : 'none');
    }

    if(visibleCount > 10){
      tableWrapper.classList.add('scrollable');
    }else{
      tableWrapper.classList.remove('scrollable');
    }

    // Re-index # column after filtering/sorting
    let idx = 1;
    dataRows.forEach(function(row){
      if(row.style.display !== 'none'){
        const idxCell = row.querySelector('.row-index');
        if(idxCell) idxCell.textContent = idx++;
      }
    });
  }

  if(filterSelect){
    filterSelect.addEventListener('change', applyFilter);
  }
  if(mainSearchInput){
    mainSearchInput.addEventListener('input', applyFilter);
  }

  // --------- SORT Surname A–Z / Z–A (main table) ----------
  function sortMainBySurname(){
    const rows = Array.from(mainTableBody.querySelectorAll('tr.data-row'));
    rows.sort(function(a,b){
      const aLast = a.children[1].textContent.trim().toLowerCase();
      const bLast = b.children[1].textContent.trim().toLowerCase();
      if (mainSortAsc) {
        return aLast.localeCompare(bLast);
      } else {
        return bLast.localeCompare(aLast);
      }
    });

    rows.forEach(function(row){
      mainTableBody.appendChild(row);
    });

    // keep the "noMatchesRow" at the bottom
    if(noMatchesRow){
      mainTableBody.appendChild(noMatchesRow);
    }

    // flip the direction for next click
    mainSortAsc = !mainSortAsc;

    applyFilter(); // reapply filter + re-index
  }

  if(btnSortMainSurname){
    btnSortMainSurname.addEventListener('click', sortMainBySurname);
  }

  // Initial filter on load
  applyFilter();

  // --------- Restrict button logic ----------
  function openRestrictModal(id, email, fullname){
    document.querySelectorAll('.restrict-reason-checkbox').forEach(function(cb){
      cb.checked = false;
    });
    if(reasonOtherCheckbox){
      reasonOtherCheckbox.checked = false;
    }
    if(reasonOtherText){
      reasonOtherText.value = '';
      reasonOtherText.disabled = true;
    }

    restrictIdInput.value       = id;
    restrictEmailInput.value    = email;
    restrictFullnameInput.value = fullname;

    restrictModalBackdrop.classList.add('show');
  }

  document.querySelectorAll('.btn-restrict-open').forEach(function(btn){
    btn.addEventListener('click', function(){
      const id       = this.dataset.id;
      const email    = this.dataset.email || '';
      const fullname = this.dataset.fullname || '';

      Swal.fire({
        title: 'Restrict Account',
        text: 'Are you sure you want to Restrict this account?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Restrict',
        cancelButtonText: 'Cancel'
      }).then((result) => {
        if(result.isConfirmed){
          openRestrictModal(id, email, fullname);
        }
      });
    });
  });

  function closeRestrictModal(){
    restrictModalBackdrop.classList.remove('show');
  }

  if(restrictModalCloseBtn){
    restrictModalCloseBtn.addEventListener('click', closeRestrictModal);
  }
  if(restrictCancelBtn){
    restrictCancelBtn.addEventListener('click', closeRestrictModal);
  }
  if(restrictModalBackdrop){
    restrictModalBackdrop.addEventListener('click', function(e){
      if(e.target === restrictModalBackdrop){
        closeRestrictModal();
      }
    });
  }

  if(reasonOtherCheckbox){
    reasonOtherCheckbox.addEventListener('change', function(){
      if(this.checked){
        reasonOtherText.disabled = false;
        reasonOtherText.focus();
      }else{
        reasonOtherText.disabled = true;
        reasonOtherText.value = '';
      }
    });
  }

  // --------- Restricted Accounts List Modal ----------
  function applyRestrictedSearch(){
    if(!restrictedSearchInput || restrictedRows.length === 0) return;
    const term = restrictedSearchInput.value.trim().toLowerCase();

    let idx = 1;
    restrictedRows.forEach(function(row){
      const text = row.textContent.toLowerCase();
      const show = (term === '' || text.includes(term));
      row.style.display = show ? '' : 'none';
      if(show){
        const idxCell = row.querySelector('.row-index');
        if(idxCell) idxCell.textContent = idx++;
      }
    });
  }

  function sortRestrictedBySurname(){
    const rows = Array.from(restrictedTableBody.querySelectorAll('tr.restricted-row'));
    rows.sort(function(a,b){
      const aLast = a.children[1].textContent.trim().toLowerCase();
      const bLast = b.children[1].textContent.trim().toLowerCase();
      if (restrictedSortAsc) {
        return aLast.localeCompare(bLast);
      } else {
        return bLast.localeCompare(aLast);
      }
    });

    rows.forEach(function(row){
      restrictedTableBody.appendChild(row);
    });

    // flip direction for next click
    restrictedSortAsc = !restrictedSortAsc;

    // Re-apply search + re-index
    applyRestrictedSearch();
  }

  function openRestrictedListModal(){
    if(restrictedSearchInput){
      restrictedSearchInput.value = '';
    }
    applyRestrictedSearch();
    restrictedListModalBackdrop.classList.add('show');
  }
  function closeRestrictedListModal(){
    restrictedListModalBackdrop.classList.remove('show');
  }

  if(btnShowRestrictedAccounts){
    btnShowRestrictedAccounts.addEventListener('click', function(){
      openRestrictedListModal();
    });
  }

  if(restrictedSearchInput){
    restrictedSearchInput.addEventListener('input', applyRestrictedSearch);
  }

  if(btnSortRestrictedSurname){
    btnSortRestrictedSurname.addEventListener('click', sortRestrictedBySurname);
  }

  if(restrictedListModalCloseBtn){
    restrictedListModalCloseBtn.addEventListener('click', closeRestrictedListModal);
  }
  if(restrictedListModalBackdrop){
    restrictedListModalBackdrop.addEventListener('click', function(e){
      if(e.target === restrictedListModalBackdrop){
        closeRestrictedListModal();
      }
    });
  }

  // --------- Nested view modal for restricted accounts ----------
  function openRestrictedViewModal(btn){
    vr_lastname.textContent       = displayValue(btn.dataset.lastname);
    vr_firstname.textContent      = displayValue(btn.dataset.firstname);
    vr_middlename.textContent     = displayValue(btn.dataset.middlename);
    vr_extension.textContent      = displayValue(btn.dataset.extension);

    vr_baptised.textContent       = displayValue(btn.dataset.baptised);
    vr_account_status.textContent = displayValue(btn.dataset.accountStatus);

    vr_birthday.textContent       = displayValue(btn.dataset.birthday);
    vr_gender.textContent         = displayValue(btn.dataset.gender);

    vr_phone.textContent          = displayValue(btn.dataset.phone);
    vr_email.textContent          = displayValue(btn.dataset.email);

    vr_street.textContent         = displayValue(btn.dataset.street);
    vr_city.textContent           = displayValue(btn.dataset.city);
    vr_zip.textContent            = displayValue(btn.dataset.zip);
    vr_civil_status.textContent   = displayValue(btn.dataset.civilStatus);

    vr_baptised_church.textContent = displayValue(btn.dataset.baptisedChurch);

    const certPath = btn.dataset.baptismalCert;
    if(certPath && certPath.trim() !== ''){
      vr_cert_img.src = certPath;
      vr_cert_img.style.display = 'block';
      vr_cert_text.textContent = '';
    }else{
      vr_cert_img.style.display = 'none';
      vr_cert_text.textContent = 'No certificate uploaded.';
    }

    // show nested modal (Restricted modal stays open in background)
    viewRestrictedModalBackdrop.classList.add('show');
  }

  document.querySelectorAll('.btn-view-restricted').forEach(function(btn){
    btn.addEventListener('click', function(){
      openRestrictedViewModal(this);
    });
  });

  function closeRestrictedViewModal(){
    viewRestrictedModalBackdrop.classList.remove('show');
  }

  if(viewRestrictedModalCloseBtn){
    viewRestrictedModalCloseBtn.addEventListener('click', closeRestrictedViewModal);
  }
  if(viewRestrictedModalBackdrop){
    viewRestrictedModalBackdrop.addEventListener('click', function(e){
      if(e.target === viewRestrictedModalBackdrop){
        closeRestrictedViewModal();
      }
    });
  }

  // --------- Unrestrict buttons ----------
  document.querySelectorAll('.btn-unrestrict-open').forEach(function(btn){
    btn.addEventListener('click', function(){
      const id       = this.dataset.id;
      const email    = this.dataset.email || '';
      const fullname = this.dataset.fullname || '';

      Swal.fire({
        title: 'Unrestrict Account',
        text: 'Are you sure you want to Unrestrict this account?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Unrestrict',
        cancelButtonText: 'Cancel'
      }).then((result) => {
        if(result.isConfirmed){
          if(unrestrictForm && unrestrictIdInput){
            unrestrictIdInput.value       = id;
            unrestrictEmailInput.value    = email;
            unrestrictFullnameInput.value = fullname;
            unrestrictForm.submit();
          }
        }
      });
    });
  });
});
</script>

<?php if ($restrictionSuccess): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  Swal.fire({
    icon: 'success',
    title: 'Account Restricted',
    text: 'The account has been set to Suspended and the member has been notified via email.'
  });
});
</script>
<?php elseif (!empty($restrictionError)): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  Swal.fire({
    icon: 'error',
    title: 'Restriction Failed',
    text: <?php echo json_encode($restrictionError); ?>
  });
});
</script>
<?php endif; ?>

<?php if ($unrestrictSuccess): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  Swal.fire({
    icon: 'success',
    title: 'Account Unrestricted',
    text: 'The account has been set to Active and the member has been notified via email.'
  });
});
</script>
<?php elseif (!empty($unrestrictError)): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  Swal.fire({
    icon: 'error',
    title: 'Unrestriction Failed',
    text: <?php echo json_encode($unrestrictError); ?>
  });
});
</script>
<?php endif; ?>

</body>
</html>
<?php
mysqli_close($db_connection);
?>
