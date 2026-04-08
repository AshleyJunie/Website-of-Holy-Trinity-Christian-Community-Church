<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!ob_get_level()) ob_start();

// ---------- DB CONNECTION ----------
require_once 'db-connection.php';

// ---------- HELPERS ----------
function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function clean($v){ return trim($v ?? ''); }
function valid_phone($v){ return preg_match('/^[0-9+\-\s()]{7,20}$/', $v); }
function valid_date($v){
  if(!$v) return false;
  [$y,$m,$d] = array_pad(explode('-', $v), 3, null);
  return checkdate((int)$m,(int)$d,(int)$y);
}

/**
 * notifyAdmins() - mysqli version
 * (unchanged)
 */
if (!function_exists('notifyAdmins')) {
  function notifyAdmins(mysqli $db, $formType, $formRecordId, $formSummary) {
    // ... existing notifyAdmins body unchanged ...
    // (keep exactly what you already have here)
    /*  ─────────────────────────────────────────────── */
    if (!isset($_SESSION) || session_status() !== PHP_SESSION_ACTIVE) {
      return false;
    }
    $allowedUserTypes = ['admin', 'individual', 'pastor'];
    $createdByType = isset($_SESSION['user_type']) ? trim((string)$_SESSION['user_type']) : '';
    $createdById   = isset($_SESSION['user_id'])   ? (int)$_SESSION['user_id'] : 0;
    if (!in_array($createdByType, $allowedUserTypes, true) || $createdById <= 0) {
      return false;
    }

    $formType = trim((string)$formType);
    if ($formType === '') return false;
    if (!is_numeric($formRecordId)) return false;
    $formRecordId = (int)$formRecordId;
    if ($formRecordId <= 0) return false;
    $formSummary = trim((string)$formSummary);
    if ($formSummary === '') return false;
    if (mb_strlen($formSummary) > 2000) {
      $formSummary = mb_substr($formSummary, 0, 2000);
    }

    $composeFullName = function(array $row, array $hints = []) : string {
      $lower = [];
      foreach ($row as $k => $v) {
        $lower[strtolower($k)] = $v;
      }
      $pick = function(array $cands) use ($lower) {
        foreach ($cands as $c) {
          $lc = strtolower($c);
          if (array_key_exists($lc, $lower) && trim((string)$lower[$lc]) !== '') {
            return trim((string)$lower[$lc]);
          }
        }
        return '';
      };
      $last   = $pick(array_merge($hints['last']   ?? [], ['individual_lastname','lastname','last_name','surname','family_name','admin_lastname','pastor_lastname']));
      $first  = $pick(array_merge($hints['first']  ?? [], ['individual_firstname','firstname','first_name','given_name','admin_firstname','pastor_firstname']));
      $middle = $pick(array_merge($hints['middle'] ?? [], ['individual_middlename','middlename','middle_name']));
      $suf    = $pick(array_merge($hints['suffix'] ?? [], ['individual_extension','extension','suffix']));

      $title = function($s){
        return preg_replace_callback(
          '/\b(\p{L})(\p{L}*)/u',
          fn($m)=>mb_strtoupper($m[1]).mb_strtolower($m[2]),
          (string)$s
        );
      };
      $last=$title($last); $first=$title($first); $middle=$title($middle); $suf=trim((string)$suf);

      $given = trim($first . ($middle!=='' ? ' '.$middle : ''));
      $suffixStr = $suf !== '' ? ' ' . $suf : '';
      if ($last !== '' && $given !== '') return "{$last}, {$given}{$suffixStr}";
      if ($last !== '') return $last . $suffixStr;
      if ($given !== '') return $given . $suffixStr;
      return '';
    };

    $fetchSubmitterName = function(string $type, int $id) use ($db, $composeFullName) : string {
      try {
        if ($type === 'individual') {
          $sql = "SELECT * FROM individual_table WHERE individual_id = ? LIMIT 1";
          if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
              $res = $stmt->get_result();
              if ($row = $res->fetch_assoc()) {
                $name = $composeFullName($row, [
                  'last'   => ['individual_lastname'],
                  'first'  => ['individual_firstname'],
                  'middle' => ['individual_middlename'],
                  'suffix' => ['individual_extension'],
                ]);
                $stmt->close();
                if ($name !== '') return $name;
              } else {
                $stmt->close();
              }
            } else {
              $stmt->close();
            }
          }
        } elseif ($type === 'admin') {
          $sql = "SELECT * FROM admin_table WHERE admin_id = ? LIMIT 1";
          if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
              $res = $stmt->get_result();
              if ($row = $res->fetch_assoc()) {
                $name = $composeFullName($row);
                $stmt->close();
                if ($name !== '') return $name;
              } else {
                $stmt->close();
              }
            } else {
              $stmt->close();
            }
          }
        } elseif ($type === 'pastor') {
          $sql = "SELECT * FROM pastor_account WHERE Pastor_ID = ? LIMIT 1";
          if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
              $res = $stmt->get_result();
              if ($row = $res->fetch_assoc()) {
                $name = $composeFullName($row);
                $stmt->close();
                if ($name !== '') return $name;
              } else {
                $stmt->close();
              }
            } else {
              $stmt->close();
            }
          }
        }
      } catch (Throwable $e) { }
      return ucfirst($type) . " #{$id}";
    };

    $submitterName = $fetchSubmitterName($createdByType, $createdById);
    $title = "New " . ucfirst($formType) . " submission";
    $body  = "Submitter: {$submitterName}\n"
           . "Type: {$formType}\n"
           . "Record ID: {$formRecordId}\n"
           . "Summary: {$formSummary}";

    try {
      $db->begin_transaction();

      $sqlNotif = "INSERT INTO notifications (title, body, created_by_type, created_by_id)
                   VALUES (?, ?, ?, ?)";
      if (!($stmtNotif = $db->prepare($sqlNotif))) {
        $db->rollback();
        return false;
      }
      $stmtNotif->bind_param('sssi', $title, $body, $createdByType, $createdById);
      if (!$stmtNotif->execute()) {
        $stmtNotif->close();
        $db->rollback();
        return false;
      }
      $stmtNotif->close();

      $notificationId = (int)$db->insert_id;
      if ($notificationId <= 0) {
        $db->rollback();
        return false;
      }

      $adminIds = [];
      $sqlAdmin = "SELECT admin_id FROM admin_table";
      if ($resAdm = $db->query($sqlAdmin)) {
        while ($row = $resAdm->fetch_assoc()) {
          $aid = (int)($row['admin_id'] ?? 0);
          if ($aid > 0) $adminIds[] = $aid;
        }
        $resAdm->free();
      }

      if ($adminIds) {
        $sqlRec = "INSERT INTO notification_recipients (notification_id, user_type, user_id, status)
                   VALUES (?, 'admin', ?, 'unread')";
        if (!($stmtRec = $db->prepare($sqlRec))) {
          $db->rollback();
          return false;
        }
        foreach ($adminIds as $aid) {
          $stmtRec->bind_param('ii', $notificationId, $aid);
          if (!$stmtRec->execute()) {
            $stmtRec->close();
            $db->rollback();
            return false;
          }
        }
        $stmtRec->close();
      }

      $db->commit();
      return true;
    } catch (Throwable $e) {
      if ($db->errno) {
        $db->rollback();
      }
      return false;
    }
  }
}

// ---------- LOGIN CHECK ----------
$loggedIn = isset($_SESSION['individual_id']);
if (!$loggedIn) {
    echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
    echo '<script>
      Swal.fire({
        icon: "warning",
        title: "Login Required",
        text: "Please log in first before joining a ministry.",
        confirmButtonText: "Go to Login"
      }).then(() => {
        window.location.href = "all_log-in.php?next=ministry-form.php";
      });
    </script>';
    exit;
}

// Logged-in individual_id (for ministries_table.individual_id)
$individualId = (int)$_SESSION['individual_id'];

/* ========== DUPLICATE HANDMAID MEMBERSHIP GUARD (SERVER-SIDE) ========== */
$handmaidLabel = "Handmaid's of the Lord";
$selectedMinistryGuard = '';

if (isset($_GET['ministry']) && $_GET['ministry'] !== '') {
    $selectedMinistryGuard = clean($_GET['ministry']);
} elseif (!empty($_SESSION['selected_ministry'])) {
    $selectedMinistryGuard = $_SESSION['selected_ministry'];
}

if ($individualId > 0 && $selectedMinistryGuard === $handmaidLabel) {
    $sqlGuard = "SELECT 1 FROM ministries_table
                 WHERE individual_id = ?
                   AND ministry_type = ?
                   AND archive_status = 'Active'
                 LIMIT 1";
    if ($stmtGuard = mysqli_prepare($db_connection, $sqlGuard)) {
        mysqli_stmt_bind_param($stmtGuard, "is", $individualId, $handmaidLabel);
        if (mysqli_stmt_execute($stmtGuard)) {
            mysqli_stmt_bind_result($stmtGuard, $dummy);
            if (mysqli_stmt_fetch($stmtGuard)) {
                mysqli_stmt_close($stmtGuard);
                header("Location: ministries-handmaid.php?already_member=1");
                exit;
            }
        }
        mysqli_stmt_close($stmtGuard);
    }
}
/* ========== /DUPLICATE HANDMAID MEMBERSHIP GUARD ========== */

/* Ensure notifier identity is set for this workflow */
if (empty($_SESSION['user_type'])) {
    $_SESSION['user_type'] = 'individual';
}
if (empty($_SESSION['user_id'])) {
    $_SESSION['user_id'] = (int)($_SESSION['individual_id'] ?? 0);
}

/* ===== Fetch logged-in individual profile ===== */
$individualRow = null;
if ($individualId > 0) {
    $sqlInd = "SELECT 
                  individual_lastname,
                  individual_firstname,
                  individual_middlename,
                  individual_extension,
                  individual_email_address,
                  individual_phone_number,
                  individual_birthday,
                  individual_gender
               FROM individual_table
               WHERE individual_id = ?
               LIMIT 1";
    if ($stmtInd = mysqli_prepare($db_connection, $sqlInd)) {
        mysqli_stmt_bind_param($stmtInd, "i", $individualId);
        if (mysqli_stmt_execute($stmtInd)) {
            mysqli_stmt_bind_result(
                $stmtInd,
                $i_lastname,
                $i_firstname,
                $i_middlename,
                $i_extension,
                $i_email,
                $i_phone,
                $i_bday,
                $i_gender
            );
            if (mysqli_stmt_fetch($stmtInd)) {
                $individualRow = [
                    'individual_lastname'       => $i_lastname,
                    'individual_firstname'      => $i_firstname,
                    'individual_middlename'     => $i_middlename,
                    'individual_extension'      => $i_extension,
                    'individual_email_address'  => $i_email,
                    'individual_phone_number'   => $i_phone,
                    'individual_birthday'       => $i_bday,
                    'individual_gender'         => $i_gender,
                ];
            }
        }
        mysqli_stmt_close($stmtInd);
    }
}

// ---------- INIT ----------
$errors = [];
$success = false;

$lastname=$firstname=$middlename=$extension=$email=$contact=$birthday=$sex=$ministry=$position='';
$agree_contact=$agree_privacy='';

$position = 'Member';

/* Prefill from profile on first load */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($individualRow)) {
    $lastname   = $individualRow['individual_lastname']      ?? $lastname;
    $firstname  = $individualRow['individual_firstname']     ?? $firstname;
    $middlename = $individualRow['individual_middlename']    ?? $middlename;
    $extension  = $individualRow['individual_extension']     ?? $extension;
    $email      = $individualRow['individual_email_address'] ?? $email;
    $contact    = $individualRow['individual_phone_number']  ?? $contact;
    $birthday   = $individualRow['individual_birthday']      ?? $birthday;
    $sex        = $individualRow['individual_gender']        ?? $sex;
}

// Preselect ministry if coming from page
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $preMinistry = clean($_GET['ministry'] ?? '');
    if ($preMinistry !== '') {
        $ministry = $preMinistry;
    }
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isset($_GET['ministry']) && $_GET['ministry'] !== '') {
        $_SESSION['selected_ministry'] = clean($_GET['ministry']);
    }
    if (!empty($_SESSION['selected_ministry'])) {
        $ministry = $_SESSION['selected_ministry'];
    }
}
// (the repeated blocks you already had can stay as-is or be cleaned later)

// ---------- HANDLE POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lastname   = clean($_POST['lastname'] ?? '');
    $firstname  = clean($_POST['firstname'] ?? '');
    $middlename = clean($_POST['middlename'] ?? '');
    $extension  = clean($_POST['extension'] ?? '');
    $email      = clean($_POST['email'] ?? '');
    $contact    = clean($_POST['contact'] ?? '');
    $birthday   = clean($_POST['birthday'] ?? '');
    $sex        = clean($_POST['sex'] ?? '');
    $ministry   = clean($_POST['ministry'] ?? '');
    $position   = clean($_POST['position'] ?? '');

    if (!empty($individualRow)) {
        $lastname   = clean($individualRow['individual_lastname']      ?? $lastname);
        $firstname  = clean($individualRow['individual_firstname']     ?? $firstname);
        $middlename = clean($individualRow['individual_middlename']    ?? $middlename);
        $extension  = clean($individualRow['individual_extension']     ?? $extension);
        $email      = clean($individualRow['individual_email_address'] ?? $email);
        $contact    = clean($individualRow['individual_phone_number']  ?? $contact);
        $birthday   = clean($individualRow['individual_birthday']      ?? $birthday);
        $sex        = clean($individualRow['individual_gender']        ?? $sex);
    }

    $agree_contact = isset($_POST['agree-contact']) ? '1' : '0';
    $agree_privacy = isset($_POST['agree-privacy']) ? '1' : '0';

    $position = 'Member';

    if ($lastname === '')   $errors[] = 'Lastname is required.';
    if ($firstname === '')  $errors[] = 'Firstname is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (!valid_date($birthday))  $errors[] = 'Valid birthday is required.';
    if ($sex === '' || !in_array($sex, ['Male','Female'], true)) $errors[] = 'Please select your sex.';
    if ($ministry === '' || $ministry === 'Select Ministry') $errors[] = 'Please select a ministry.';
    if ($agree_contact !== '1') $errors[] = 'You must agree to be contacted.';
    if ($agree_privacy !== '1') $errors[] = 'You must agree to the privacy policy.';
    if ($birthday && strtotime($birthday) > strtotime('today')) $errors[] = 'Birthday cannot be in the future.';

    if ($sex === 'Male' && $ministry === "Handmaid's of the Lord") {
        $errors[] = "Handmaid's of the Lord is available to Female applicants only.";
    }
    if ($sex === 'Female' && $ministry === "Men's Ministry") {
        $errors[] = "Men's Ministry is available to Male applicants only.";
    }

    if (!$errors) {
        $sql = "INSERT INTO ministries_table
                (ministry_type, ministry_position, ministry_lastname, ministry_firstname, ministry_middlename, ministry_extensionname, sex, birthday, ministry_email, archive_status, status, individual_id, contact_num, email_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', 'Pending', ?, ?, ?)";
        if ($stmt = mysqli_prepare($db_connection, $sql)) {
            mysqli_stmt_bind_param(
                $stmt,
                "sssssssssiss",
                $ministry,
                $position,
                $lastname,
                $firstname,
                $middlename,
                $extension,
                $sex,
                $birthday,
                $email,
                $individualId,
                $contact,
                $email
            );
            $ok = mysqli_stmt_execute($stmt);

            if ($ok) {
                $newMinistryId = (int)mysqli_insert_id($db_connection);

                $fullName = trim($lastname . ' ' . $firstname . ' ' . $middlename . ' ' . $extension);
                $fullName = preg_replace('/\s+/', ' ', $fullName);

                $summaryParts = [];
                if ($fullName !== '') $summaryParts[] = "Applicant: {$fullName}";
                if ($ministry !== '') $summaryParts[] = "Ministry: {$ministry}";
                if ($sex !== '')      $summaryParts[] = "Sex: {$sex}";
                if ($birthday !== '') $summaryParts[] = "Birthday: {$birthday}";
                if ($email !== '')    $summaryParts[] = "Email: {$email}";
                if ($contact !== '')  $summaryParts[] = "Contact: {$contact}";
                $formSummary = implode(' | ', $summaryParts);

                if ($newMinistryId > 0) {
                    try {
                        notifyAdmins($db_connection, $ministry, $newMinistryId, $formSummary);
                    } catch (Throwable $___e) { }
                }
            }

            mysqli_stmt_close($stmt);
            if ($ok) {
                $success = true;
                $lastname=$firstname=$middlename=$extension=$email=$contact=$birthday=$sex=$ministry=$position='';
                $agree_contact=$agree_privacy='';
            } else $errors[] = 'Database error while saving your application.';
        } else $errors[] = 'Failed to prepare save statement.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Church Ministries Form</title>
<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link rel="stylesheet" href="/HTCCC-SYSTEM/css/ministry-form.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/ministry-form.css'); ?>">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
  /* Suppress the original SweetAlert DPA so we can use the custom modal instead */
  try { sessionStorage.setItem('privacy_ack_ministries','1'); } catch(e) {}
  window.__htccc_use_custom_dpa = true;
</script>
<style>
  :root {
    --ink:#1a1f4a;
    --panel:#111631;
    --border:#3b436e;
    --accent:#7FC1FF;
    --text:#fff;
  }
  .dpa-backdrop {
    position: fixed; inset: 0; background: rgba(0,0,0,0.65);
    display: none; align-items: center; justify-content: center; z-index: 9999; padding: 16px;
  }
  .dpa-modal {
    max-width: 720px; width: 100%; background: var(--panel); color: var(--text);
    border: 2px solid var(--border); border-radius: 14px; box-shadow: 0 20px 60px rgba(0,0,0,0.45); overflow: hidden;
  }
  .dpa-header { background: var(--ink); padding: 14px 18px; font-weight: 800; font-size: 1.05rem; display:flex; align-items:center; justify-content:space-between; }
  .dpa-body { padding: 16px 18px; line-height: 1.55; font-size: 0.96rem; }
  .dpa-body p { margin: 0 0 12px; }
  .dpa-footer { padding: 14px 18px; display: flex; gap: 10px; justify-content: flex-end; background: rgba(255,255,255,0.04); }
  .dpa-btn { border: none; border-radius: 10px; padding: 10px 14px; cursor: pointer; font-weight: 800; min-height: 44px; }
  .dpa-btn.primary { background: var(--accent); color: #000; }
  .dpa-btn.secondary { background: #2a2f57; color: #fff; }
  .dpa-close-x { background: transparent; border: none; color: #fff; font-size: 20px; line-height: 1; cursor: pointer; }
  @media (max-width: 575.98px) {
    .dpa-modal { max-width: 100%; }
    .dpa-body { font-size: .95rem; }
  }

  /* ===== ADD-ONLY: small utility to visually hide disabled options (optional) ===== */
  /* NOTE: kept, but no longer used since we no longer disable/hide options */
  .opt-hidden { display: none; }
  .note-muted { font-size: 0.85rem; margin-top: 6px; opacity: .8; }

  /* ===== ADD-ONLY: Hide the visible Position input + label; we still submit "Member" ===== */
  label[for="position"], input[name="position"] { display: none !important; }

  /* ===== ADD-ONLY: Greyed/locked fields for profile ===== */
  .locked-field {
    background-color: #e9ecef;
    color: #555;
  }
  .locked-field[readonly] {
    cursor: not-allowed;
  }
  .locked-choice,
  .locked-choice:disabled {
    background-color: #e9ecef;
    color: #555;
    cursor: not-allowed;
  }
</style>

<!-- ===== ADD-ONLY: Expose profile + locked ministry flags to JS ===== -->
<script>
  const HTCCC_HAS_PROFILE = <?php echo !empty($individualRow) ? 'true' : 'false'; ?>;
  const HTCCC_LOCKED_MINISTRY = <?php echo json_encode($ministry); ?>;
</script>
<!-- ===== /ADD-ONLY ===== -->

</head>
<body>
<nav style="background-color:#0A0E3F;display:flex;align-items:center;justify-content:space-between;padding:10px 20px;color:white;">
  <a href="main-page.php" style="display:inline-flex;align-items:center;justify-content:center;">
    <img src="image/btn-back.png" alt="Back" style="width:30px;height:30px;cursor:pointer;">
  </a>
  <div style="display:flex;align-items:center;gap:10px;min-width:0;">
    <img src="image/httc_main-logo.jpg" alt="Logo" style="width:50px;height:50px;border-radius:50%;">
    <h1 style="margin:0;font-size:20px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">CHURCH MINISTRY FORM</h1>
  </div>
  <div style="width:30px;"></div>
</nav>

<main>
<form method="POST" novalidate>
  <h2>FILL UP FORM</h2>

  <div>
    <label for="lastname">LASTNAME<span class="required">*</span></label>
    <input type="text" name="lastname" required value="<?php echo h($lastname); ?>">
  </div>

  <div>
    <label for="email">EMAIL<span class="required">*</span></label>
    <input type="email" name="email" required value="<?php echo h($email); ?>">
  </div>

  <div>
    <label for="firstname">FIRSTNAME<span class="required">*</span></label>
    <input type="text" name="firstname" required value="<?php echo h($firstname); ?>">
  </div>

  <div>
    <label for="contact">CONTACT (optional)</label>
    <input type="tel" name="contact" value="<?php echo h($contact); ?>">
  </div>

  <div>
    <label for="middlename">MIDDLENAME</label>
    <input type="text" name="middlename" value="<?php echo h($middlename); ?>">
  </div>

  <div>
    <label for="birthday">BIRTHDAY<span class="required">*</span></label>
    <input type="date" name="birthday" required value="<?php echo h($birthday); ?>">
  </div>

  <div>
    <label for="extension">EXTENSION NAME</label>
    <input type="text" name="extension" placeholder="Jr., Sr., III" value="<?php echo h($extension); ?>">
  </div>

  <div>
    <label for="sex">SEX<span class="required">*</span></label>
    <select name="sex" required>
      <option disabled <?php echo $sex===''?'selected':''; ?>>Select Sex</option>
      <option <?php echo $sex==='Male'?'selected':''; ?>>Male</option>
      <option <?php echo $sex==='Female'?'selected':''; ?>>Female</option>
    </select>
  </div>

  <div>
    <label for="position">POSITION (optional)</label>
    <input type="text" name="position" placeholder="Member, Leader, etc." value="<?php echo h($position); ?>">
  </div>

  <div>
    <label for="ministry">WHICH MINISTRY?<span class="required">*</span></label>
    <select name="ministry" required>
      <option disabled <?php echo $ministry===''?'selected':''; ?>>Select Ministry</option>
      <option <?php echo $ministry==='Junior Christ Ambassador'?'selected':''; ?>>Junior Christ Ambassador</option>
      <option <?php echo $ministry==="Handmaid's of the Lord"?'selected':''; ?>>Handmaid's of the Lord</option>
      <option <?php echo $ministry==="Men's Ministry"?'selected':''; ?>>Men's Ministry</option>
      <option <?php echo $ministry==="Music's Ministry"?'selected':''; ?>>Music's Ministry</option>
      <option <?php echo $ministry==='Usher & Usherette'?'selected':''; ?>>Usher & Usherette</option>
    </select>
    <div id="ministry-note" class="note-muted"></div>
  </div>

  <div class="checkbox-container" style="grid-column:1/3;">
    <div class="checkbox-group">
      <input type="checkbox" id="agree-contact" name="agree-contact" required <?php echo $agree_contact==='1'?'checked':''; ?>>
      <label for="agree-contact">I AGREE TO BE CONTACTED<span class="required">*</span></label>
    </div>
    <div class="checkbox-group">
      <input type="checkbox" id="agree-privacy" name="agree-privacy" required <?php echo $agree_privacy==='1'?'checked':''; ?>>
      <label for="agree-privacy">I AGREE TO THE PRIVACY POLICY<span class="required">*</span></label>
    </div>
  </div>

  <button type="submit">SUBMIT</button>
</form>
</main>

<?php if (!empty($errors)): ?>
<script>
Swal.fire({
  icon:'error',
  title:'Please check your input',
  html:`<ul style="text-align:left;margin:0;padding-left:18px;"><?php foreach($errors as $e){ echo '<li>'.h($e).'</li>'; } ?></ul>`
});
</script>
<?php endif; ?>

<?php if ($success): ?>
<script>
Swal.fire({
  icon:'success',
  title:'Application submitted!',
  text:'Your ministry application is now pending review.',
  confirmButtonText:'OK'
}).then(()=>{window.location.href='ministries-men-page.php';});
</script>
<?php endif; ?>

<!-- === Original SweetAlert DPA (kept) — now suppressed by sessionStorage flag === -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  try {
    var shown = sessionStorage.getItem('privacy_ack_ministries');
    if (shown === '1') return; // suppressed so our custom modal shows instead

    Swal.fire({
      icon: 'info',
      title: 'Data Privacy Notice (RA 10173)',
      width: 700,
      padding: '1rem',
      html: `
        <div style="text-align:left;max-height:45vh;overflow:auto;line-height:1.45">
          <p><strong>Holy Trinity Christian Community Church</strong> values your privacy. By proceeding, you consent to the collection and processing of your personal data for the purpose of ministry application, membership records, communications, and related church activities.</p>
          <ul style="padding-left:20px;margin:8px 0;">
            <li><strong>Data Collected:</strong> name, contact info, sex, birthday, ministry preferences, and other form details.</li>
            <li><strong>Use:</strong> evaluate applications, contact you for ministry matters, maintain church records, and comply with legal obligations.</li>
            <li><strong>Access & Sharing:</strong> limited to authorized church personnel and service providers bound by confidentiality.</li>
            <li><strong>Storage & Retention:</strong> retained only as long as necessary for the stated purposes or as required by law.</li>
            <li><strong>Your Rights:</strong> to access, correct, withdraw consent, and file complaints consistent with the Data Privacy Act of 2012.</li>
          </ul>
          <p>For questions or requests about your data, contact our Data Protection Officer via the church office.</p>
          <label style="display:block;margin-top:12px">
            <input type="checkbox" id="dpaAgree"> I have read and agree to this Privacy Notice.
          </label>
        </div>
      `,
      focusConfirm: false,
      allowOutsideClick: false,
      allowEscapeKey: false,
      showDenyButton: true,
      confirmButtonText: 'I Agree',
      denyButtonText: 'Decline',
      preConfirm: () => {
        const cb = document.getElementById('dpaAgree');
        if (!cb || !cb.checked) {
          Swal.showValidationMessage('Please tick the checkbox to continue.');
          return false;
        }
        return true;
      }
    }).then((res) => {
      if (res.isConfirmed) {
        sessionStorage.setItem('privacy_ack_ministries', '1');
        var privacyBox = document.getElementById('agree-privacy');
        if (privacyBox) privacyBox.checked = true;
      } else {
        window.location.href = 'main-page.php';
      }
    });
  } catch (e) {
    console.error('Privacy popup error:', e);
  }
});
</script>
<!-- === /Original SweetAlert DPA === -->

<!-- ========= HTCCC – Data Privacy Act Consent (Custom Modal) ========= -->
<div class="dpa-backdrop" id="dpaBackdrop" role="dialog" aria-modal="true" aria-labelledby="dpaTitle" aria-describedby="dpaDesc">
  <div class="dpa-modal">
    <div class="dpa-header">
      <div id="dpaTitle">Data Privacy Act Consent (RA 10173)</div>
      <button class="dpa-close-x" id="dpaCloseX" aria-label="Close">×</button>
    </div>
    <div class="dpa-body" id="dpaDesc">
      <p><strong>Holy Trinity Christian Community Church (HTCCC)</strong> is committed to protecting your personal data in compliance with the <strong>Data Privacy Act of 2012 (RA 10173)</strong> and its IRR.</p>
      <p><strong>What we collect:</strong> Details you provide in this ministry form (e.g., name, contact info, sex, birthday, ministry preference).</p>
      <p><strong>Purpose:</strong> Evaluate your application, coordinate with you, maintain membership records, and comply with church and legal requirements.</p>
      <p><strong>Storage &amp; Retention:</strong> Your data will be securely stored and retained only as long as necessary or required by law.</p>
      <p><strong>Sharing:</strong> Access is limited to authorized church personnel and service providers bound by confidentiality. We do not sell your data.</p>
      <p><strong>Your rights:</strong> You may access, correct, or withdraw consent under RA 10173, subject to legal/contractual limits. For concerns, contact our DPO via the church office.</p>
      <p class="mb-0">By selecting <em>“I Agree &amp; Proceed”</em>, you acknowledge you have read and consent to the data processing described above.</p>
    </div>
    <div class="dpa-footer">
      <button class="dpa-btn secondary" id="dpaCancel">Cancel</button>
      <button class="dpa-btn primary" id="dpaAgree">I Agree &amp; Proceed</button>
    </div>
  </div>
</div>
<!-- ========= /HTCCC – DPA Modal ========= -->

<!-- ===== Custom DPA behavior (ADD-ONLY) ===== -->
<script>
(function () {
  if (!window.__htccc_use_custom_dpa) return;

  const backdrop   = document.getElementById('dpaBackdrop');
  const btnAgree   = document.getElementById('dpaAgree');
  const btnCancel  = document.getElementById('dpaCancel');
  const btnCloseX  = document.getElementById('dpaCloseX');
  const privacyBox = document.getElementById('agree-privacy');

  function lockBody(lock){ document.body.style.overflow = lock ? 'hidden' : ''; }
  function openDPA(){ backdrop.style.display = 'flex'; lockBody(true); setTimeout(()=>{ btnAgree && btnAgree.focus(); },50); }
  function closeDPA(){ backdrop.style.display = 'none'; lockBody(false); }

  const SEEN_KEY = 'privacy_ack_ministries_custom';

  document.addEventListener('DOMContentLoaded', function () {
    try {
      if (sessionStorage.getItem(SEEN_KEY) === '1') return;
      openDPA();
    } catch (e) { openDPA(); }
  });

  btnAgree && btnAgree.addEventListener('click', function() {
    try { sessionStorage.setItem(SEEN_KEY, '1'); } catch(e) {}
    try { sessionStorage.setItem('privacy_ack_ministries', '1'); } catch(e) {}
    if (privacyBox) privacyBox.checked = true;
    closeDPA();
  });

  function cancelFlow(){ window.location.href = 'main-page.php'; }
  btnCancel && btnCancel.addEventListener('click', cancelFlow);
  btnCloseX && btnCloseX.addEventListener('click', cancelFlow);
})();
</script>
<!-- ===== /Custom DPA behavior ===== -->

<!-- ===== UPDATED: Client-side gender note for ministry dropdown (NO DISABLING) ===== -->
<script>
(function(){
  const sexSelect = document.querySelector('select[name="sex"]');
  const minSelect = document.querySelector('select[name="ministry"]');
  const noteEl    = document.getElementById('ministry-note');

  if (!sexSelect || !minSelect) return;

  function setNote(text){
    if (!noteEl) return;
    noteEl.textContent = text || '';
  }

  function updateNote() {
    const sex = (sexSelect.value || '').trim();
    if (sex === 'Male') {
      setNote("Note: “Handmaid's of the Lord” is for Female applicants only (server will validate).");
    } else if (sex === 'Female') {
      setNote("Note: “Men's Ministry” is for Male applicants only (server will validate).");
    } else {
      setNote('');
    }
  }

  document.addEventListener('DOMContentLoaded', updateNote);
  sexSelect.addEventListener('change', updateNote);
  minSelect.addEventListener('change', updateNote);
})();
</script>
<!-- ===== /UPDATED ===== -->

<!-- ===== ADD-ONLY: Ensure position is always set to Member on the client too ===== -->
<script>
document.addEventListener('DOMContentLoaded', function(){
  var pos = document.querySelector('input[name="position"]');
  if (pos) {
    pos.value = 'Member';
    pos.readOnly = true;
    pos.setAttribute('aria-readonly','true');
  }
});
</script>
<!-- ===== /ADD-ONLY ===== -->

<!-- ===== UPDATED: Lock profile fields ONLY (DO NOT DISABLE MINISTRY DROPDOWN) ===== -->
<script>
document.addEventListener('DOMContentLoaded', function(){
  try {
    if (typeof HTCCC_HAS_PROFILE !== 'undefined' && HTCCC_HAS_PROFILE) {
      var fieldNames = ['lastname','firstname','middlename','extension','email','contact','birthday'];
      fieldNames.forEach(function(name){
        var el = document.querySelector('input[name="'+name+'"]');
        if (el) {
          el.readOnly = true;
          el.classList.add('locked-field');
          el.setAttribute('aria-readonly','true');
        }
      });

      var sexSelect = document.querySelector('select[name="sex"]');
      if (sexSelect) {
        sexSelect.classList.add('locked-choice');
        sexSelect.disabled = true;
        var hiddenSex = document.createElement('input');
        hiddenSex.type = 'hidden';
        hiddenSex.name = 'sex';
        hiddenSex.value = sexSelect.value;
        if (sexSelect.form) sexSelect.form.appendChild(hiddenSex);
      }
    }

    // IMPORTANT CHANGE:
    // We still preselect ministry (HTCCC_LOCKED_MINISTRY) BUT we do NOT disable the dropdown.
    if (typeof HTCCC_LOCKED_MINISTRY !== 'undefined' && HTCCC_LOCKED_MINISTRY) {
      var minSelect = document.querySelector('select[name="ministry"]');
      if (minSelect) {
        minSelect.value = HTCCC_LOCKED_MINISTRY; // preselect only
        // do NOT disable; do NOT add hidden input
      }
    }
  } catch(e) {
    console.error('Lock profile/ministry error:', e);
  }
});
</script>
<!-- ===== /UPDATED ===== -->

</body>
</html>
