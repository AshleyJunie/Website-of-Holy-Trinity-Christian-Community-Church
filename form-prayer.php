<?php
// Use your existing mysqli connection ($conn)
include 'db-connection.php';

$successMsg = $errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect inputs
    $prayer_option      = trim($_POST['prayer_option'] ?? '');
    $prayer_request     = trim($_POST['prayer_request'] ?? '');
    $prayer_description = trim($_POST['prayer_description'] ?? '');
    $prayer_mem_name    = trim($_POST['prayer_mem_name'] ?? '');

    if ($prayer_option === '' || $prayer_request === '') {
        $errorMsg = 'Please complete the required fields.';
    } else {
        try {
            // Insert using prepared statement (mysqli)
            $stmt = $db_connection->prepare("
                INSERT INTO prayer_table
                    (prayer_date, prayer_type, prayer_request, prayer_option, prayer_description, prayer_mem_name, prayer_status, created_at, updated_at)
                VALUES
                    (CURDATE(), 'General', ?, ?, ?, ?, 'Pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }

            $stmt->bind_param(
                "ssss",
                $prayer_request,
                $prayer_option,
                $prayer_description,
                $prayer_mem_name
            );

            if (!$stmt->execute()) {
                throw new Exception('Execute failed: ' . $stmt->error);
            }

            $stmt->close();
            $successMsg = 'Your prayer request has been submitted successfully.';
            // Clear POST so fields reset after success
            $_POST = [];
        } catch (Throwable $e) {
            $errorMsg = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>HTCCC • Prayer Request Form</title>
<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link rel="stylesheet" href="/HTCCC-SYSTEM/css/form-prayer.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/form-prayer.css'); ?>">

<style>
/* ------------ Toast ------------ */
#toastContainer {
  position: fixed;
  top: 16px;
  right: 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  z-index: 2000;
}
.toast {
  min-width: 260px;
  max-width: 90vw;
  padding: 12px 14px;
  border-radius: 10px;
  box-shadow: 0 10px 30px rgba(0,0,0,.18);
  color: #0b1020;
  background: #fff;
  border-left: 6px solid #64748b; /* default: info */
  animation: slideIn .25s ease-out;
}
.toast.success { border-left-color: #10b981; }
.toast.error   { border-left-color: #ef4444; }
.toast.info    { border-left-color: #64748b; }
.toast .title { font-weight: 700; margin-bottom: 2px; }
.toast .msg   { opacity: .9; }
.toast .close {
  all: unset; float: right; margin-left: 10px; cursor: pointer; opacity: .6;
}
@keyframes slideIn { from { transform: translateY(-10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

/* ------------ Modal + Blur ------------ */
body.modal-open main {
  filter: blur(6px);
  pointer-events: none;
  user-select: none;
}
#privacyModal {
  position: fixed; inset: 0; display: flex; align-items: center; justify-content: center; z-index: 1500;
}
#privacyModal .backdrop {
  position: absolute; inset: 0;
  background: rgba(0,0,0,.45);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
}
#privacyModal .modal-content {
  position: relative; z-index: 1;
  background: #fff; border-radius: 14px; padding: 24px 20px;
  width: min(620px, 92vw);
  box-shadow: 0 18px 60px rgba(0,0,0,.28);
}
#privacyModal h2 { margin: 0 0 8px; }
#privacyModal .modal-buttons { display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px; }
#privacyModal button { border: 0; padding: 10px 16px; border-radius: 8px; cursor: pointer; }
#agreeBtn { background:#0f766e; color:#fff; }
#declineBtn { background:#e5e7eb; }

/* ------------ Layout helpers ------------ */
main#formContainer { display: flex !important; flex-direction: column; gap: 12px; padding: 16px; }
/* Remove the faint background image behind the form by hiding the old element if it ever exists */
.cross-image { display: none !important; }
</style>
</head>

<body class="modal-open">

<!-- Toast container -->
<div id="toastContainer" aria-live="polite" aria-atomic="true"></div>

<!-- NAVBAR -->
<nav>
  <a href="main-page.php" class="back-btn">
    <img src="image/btn-back.png" alt="Back">
  </a>
  <img src="image/httc_main-logo.jpg" alt="HTCCC Logo" class="logo">
  <h1>PRAYER REQUEST FORM</h1>
</nav>

<!-- DATA PRIVACY MODAL -->
<div id="privacyModal" role="dialog" aria-modal="true" aria-labelledby="privacyTitle">
  <div class="backdrop" aria-hidden="true"></div>
  <div class="modal-content">
    <h2 id="privacyTitle">Data Privacy Consent</h2>
    <p>
      In compliance with the <strong>Data Privacy Act of 2012 (RA 10173)</strong>,
      HTCCC values and protects your personal data. The information you provide
      will be used solely for prayer and pastoral purposes.
    </p>
    <p>
      By clicking “I Agree”, you consent to the collection and use of your information
      in accordance with our <a href="#">Privacy Policy</a>.
    </p>
    <div class="modal-buttons">
      <button id="declineBtn" type="button">Decline</button>
      <button id="agreeBtn" type="button">I Agree</button>
    </div>
  </div>
</div>

<!-- MAIN FORM -->
<main id="formContainer">
  <section class="form-section">
    <!-- Removed the watermark image behind the form -->
    <?php // server messages will be shown as toasts via JS below ?>

    <form method="POST" action="">
      <label for="prayer_option">Prayer Privacy</label>
      <select id="prayer_option" name="prayer_option" required>
        <option value="">-- Select --</option>
        <option value="Private" <?= (($_POST['prayer_option'] ?? '')==='Private')?'selected':''; ?>>Private (kept confidential)</option>
        <option value="Public"  <?= (($_POST['prayer_option'] ?? '')==='Public')?'selected':''; ?>>Public (mentioned during Mass)</option>
      </select>

      <label for="prayer_request">Prayer Request</label>
      <input type="text" id="prayer_request" name="prayer_request" placeholder="e.g., Healing for my father" required
             value="<?= htmlspecialchars($_POST['prayer_request'] ?? '') ?>">

      <label for="prayer_description">Details (Optional)</label>
      <textarea id="prayer_description" name="prayer_description" placeholder="Additional details..."><?= htmlspecialchars($_POST['prayer_description'] ?? '') ?></textarea>

      <label for="prayer_mem_name">Prayer Recipient (Optional)</label>
      <input type="text" id="prayer_mem_name" name="prayer_mem_name" placeholder="Name of the recipient"
             value="<?= htmlspecialchars($_POST['prayer_mem_name'] ?? '') ?>">

      <div class="form-actions">
        <button type="submit" class="form-button">SUBMIT</button>
      </div>
    </form>
  </section>
</main>

<script>
// ---------- Toast Utility ----------
function showToast(type = 'info', message = '', title = null, timeout = 3500) {
  const container = document.getElementById('toastContainer');
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.setAttribute('role', 'status');

  const t = title || (type === 'success' ? 'Success' : type === 'error' ? 'Error' : 'Notice');
  toast.innerHTML = `
    <button class="close" aria-label="Close" title="Dismiss">&times;</button>
    <div class="title">${t}</div>
    <div class="msg">${message}</div>
  `;

  // Close handlers
  toast.querySelector('.close').addEventListener('click', () => toast.remove());
  setTimeout(() => { if (toast.isConnected) toast.remove(); }, timeout);

  container.appendChild(toast);
}

// ---------- Privacy Modal control ----------
const modal = document.getElementById('privacyModal');
document.getElementById('agreeBtn').addEventListener('click', () => {
  modal.style.display = 'none';
  document.body.classList.remove('modal-open'); // unblur & enable form
});
document.getElementById('declineBtn').addEventListener('click', () => {
  showToast('error', 'You must agree to the Data Privacy Policy to proceed.', 'Privacy');
});

// ---------- Server-side messages -> toast ----------
<?php if ($successMsg): ?>
  showToast('success', <?= json_encode($successMsg) ?>);
<?php elseif ($errorMsg): ?>
  showToast('error', <?= json_encode($errorMsg) ?>);
<?php endif; ?>
</script>
</body>
</html>
