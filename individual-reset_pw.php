<?php
// ---- safe headers + session (prevents "headers already sent") ----
if (!headers_sent()) { ob_start(); }
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Bring the verified email either from the URL (?email=) or session set during OTP send
$verifiedEmail = null;
if (!empty($_GET['email'])) {
    $verifiedEmail = trim($_GET['email']);
} elseif (!empty($_SESSION['otp_email'])) {
    $verifiedEmail = trim($_SESSION['otp_email']);
}

// If no email, we still show the form but will block submission for safety
$canSubmit = filter_var($verifiedEmail, FILTER_VALIDATE_EMAIL);

// Process password change
$changeSuccess = false;
$errorMsg = null;

/* ===== CSRF token (kept; non-breaking) ===== */
if (empty($_SESSION['__csrf'])) {
    $_SESSION['__csrf'] = bin2hex(random_bytes(16));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__do_change'])) {
    require_once 'db-connection.php'; // must define $db_connection (mysqli)

    $email = trim($_POST['email'] ?? '');
    $new  = (string)($_POST['new_password'] ?? '');
    $conf = (string)($_POST['confirm_password'] ?? '');

    // CSRF validate
    if (empty($_POST['__csrf']) || !hash_equals($_SESSION['__csrf'] ?? '', $_POST['__csrf'])) {
        $errorMsg = 'Form expired. Please reload and try again.';
    }
    // Basic validations
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMsg = 'Invalid or missing email reference.';
    }
    // ensure hidden email matches the verified email we detected
    elseif (!empty($verifiedEmail) && strcasecmp($email, $verifiedEmail) !== 0) {
        $errorMsg = 'Email mismatch from verified session.';
    }
    elseif ($new === '' || $conf === '') {
        $errorMsg = 'Please fill in both password fields.';
    } elseif ($new !== $conf) {
        $errorMsg = 'Passwords do not match.';
    } elseif (strlen($new) < 8) { // keep server min; client enforces full criteria
        $errorMsg = 'Password must be at least 8 characters.';
    } else {
        // verify account exists first
        if ($stmt = $db_connection->prepare("SELECT individual_id FROM individual_table WHERE individual_email_address = ? LIMIT 1")) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();
            $exists = $stmt->num_rows > 0;
            $stmt->close();

            if (!$exists) {
                $errorMsg = 'No account found for that email.';
            } else {
                // ====== STORE PLAINTEXT PASSWORD DIRECTLY (as requested) ======
                if ($stmt = $db_connection->prepare("UPDATE individual_table 
                    SET individual_password = ?, otp_code = NULL, otp_expiry = NULL
                    WHERE individual_email_address = ? LIMIT 1")) {
                    $stmt->bind_param("ss", $new, $email);
                    $stmt->execute();
                    $rows = $stmt->affected_rows;
                    $stmt->close();

                    if ($rows >= 0) {
                        $changeSuccess = true;
                        unset($_SESSION['otp_email']);
                        $_SESSION['__csrf'] = bin2hex(random_bytes(16));
                    } else {
                        $errorMsg = 'No account found for that email.';
                    }
                } else {
                    $errorMsg = 'Database error while updating password.';
                }
            }
        } else {
            $errorMsg = 'Database error while checking account.';
        }
    }

    // SweetAlert feedback
    echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
    if ($changeSuccess) {
        echo "<script>
            Swal.fire({
                icon: 'success',
                title: 'Password Updated',
                text: 'You can now log in with your new password.',
                confirmButtonColor: '#0E7AFE',
                timer: 2200,
                showConfirmButton: true
            }).then(() => {
                window.location.href='all_log-in.php';
            });
        </script>";
    } else {
        $msg = $errorMsg ?: 'Unable to change password.';
        $msgJson = json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo "<script>
            Swal.fire({
                icon: 'error',
                title: 'Update Failed',
                text: $msgJson,
                confirmButtonColor: '#d33'
            });
        </script>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta content="width=device-width, initial-scale=1" name="viewport" />
  <title>HTTC Reset Password</title>

  <!-- keep your original stylesheet -->
  <link rel="stylesheet" href="individual-reset_pw.css">
  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">

  <!-- Inter font -->
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">

  <style>
    :root{
      --brand:#0A0E3F;
      --brand-2:#0E7AFE;
      --brand-3:#0064D6;
      --text:#0f172a;
      --muted:#6b7280;
      --ring: rgba(14,122,254,.28);
      --card: rgba(255,255,255,.90);
    }
    *{ box-sizing: border-box; }
    html,body{ height:100%; }
    body{
      margin:0;
      font-family: "Inter", system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      color:var(--text);
      background:
        radial-gradient(1200px 600px at 10% -10%, rgba(14,122,254,.18), transparent 60%),
        radial-gradient(1100px 700px at 110% 120%, rgba(10,14,63,.25), transparent 55%),
        linear-gradient(180deg, rgba(10,14,63,.45), rgba(10,14,63,.45)),
        url("Image/log_in-form-bg.jpg") center/cover no-repeat fixed;
      display:flex; align-items:center; justify-content:center;
      padding:28px;
    }
    .reset-container{ width:100%; max-width:540px; }
    .reset-card{
      background: var(--card);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      border-radius: 22px;
      padding: 28px 24px 24px;
      border: 1px solid rgba(255,255,255,.35);
      box-shadow: 0 22px 44px rgba(2,6,23,.30), inset 0 1px 0 rgba(255,255,255,.2);
      position:relative; overflow:hidden;
    }
    .reset-card::before{
      content:""; position:absolute; inset:-2px;
      background: radial-gradient(500px 220px at 10% 0%, rgba(14,122,254,.15), transparent 60%),
                  radial-gradient(400px 200px at 100% 100%, rgba(255,255,255,.35), transparent 60%);
      pointer-events:none; z-index:0;
    }
    .brand{
      display:flex; align-items:center; gap:12px; margin-bottom:6px; position:relative; z-index:1;
    }
    .brand img{ width:44px; height:44px; border-radius:12px; object-fit:cover; box-shadow:0 8px 18px rgba(2,6,23,.25); }
    .brand .t1{ margin:0; font-size:1.02rem; font-weight:800; color:var(--brand); letter-spacing:.2px; }
    .brand .t2{ margin:2px 0 0; font-size:.86rem; color:var(--muted); font-weight:600; }

    .reset-card h2{
      margin: 12px 0 6px; font-size:1.35rem; color:#0b122d; letter-spacing:.2px; position:relative; z-index:1;
    }
    .helper{ margin:0 0 16px; font-size:.95rem; color:var(--muted); position:relative; z-index:1; }

    .input-group{ margin:14px 0; position:relative; z-index:1; }
    label{ display:block; font-size:.9rem; font-weight:700; margin-bottom:8px; color:#0b122d; }
    .field{
      display:flex; align-items:center; gap:10px;
      border:1px solid #d1d5db; border-radius:14px; padding: 12px 14px; background:#fff;
      transition: box-shadow .2s, border-color .2s;
    }
    .field:focus-within{ border-color: var(--brand-2); box-shadow: 0 0 0 8px var(--ring); }
    .field input{
      flex:1; border:0; outline:0; font-size:1rem; background:transparent; color:#0b122d; padding:0;
    }
    /* Hide the eye/monkey toggles without removing them */
    .toggle-password{ display:none !important; }

    .submit-button{
      display:inline-flex; align-items:center; justify-content:center; gap:8px;
      width:100%; padding:12px 18px; border:none;
      background: linear-gradient(180deg, var(--brand-2), var(--brand-3));
      color:#fff; border-radius:14px; font-weight:800; letter-spacing:.2px;
      cursor:pointer; box-shadow: 0 12px 26px rgba(14,122,254,.35);
      transition: transform .06s, opacity .2s, box-shadow .2s;
      text-decoration:none;
    }
    .submit-button:hover{ transform: translateY(-1px); }
    .submit-button:active{ transform: translateY(0); opacity:.96; }

    .back{
      display:inline-flex; align-items:center; gap:8px; margin-top:12px;
      font-size:.95rem; color:var(--brand); text-decoration:none; font-weight:800;
    }
    .back:hover{ text-decoration:underline; }

    .hint{ font-size:.83rem; color:var(--muted); margin-top:8px; }
    @media (max-width:480px){
      body{ padding:18px; }
      .reset-card{ padding:24px 18px 18px; }
      .reset-card h2{ font-size:1.25rem; }
    }
  </style>
</head>
<body>
  <div class="reset-container">
    <div class="reset-card">
      <!-- non-breaking brand -->
      <div class="brand">
        <img src="image/httc_main-logo.jpg" alt="HTCCC" />
        <div>
          <p class="t1">Holy Trinity Christian Community Church</p>
          <p class="t2">Reset Password</p>
        </div>
      </div>

      <h2>RESET YOUR PASSWORD</h2>
      <p class="helper">
        <?php if ($canSubmit): ?>
          Updating password for <strong><?php echo htmlspecialchars($verifiedEmail); ?></strong>.
        <?php else: ?>
          We couldn’t detect a verified email. Please go back and verify your OTP.
        <?php endif; ?>
      </p>

      <form method="post" action="">
        <input type="hidden" name="__do_change" value="1">
        <input type="hidden" name="email" value="<?php echo htmlspecialchars($verifiedEmail ?? ''); ?>">
        <!-- CSRF hidden -->
        <input type="hidden" name="__csrf" value="<?php echo htmlspecialchars($_SESSION['__csrf']); ?>">

        <label>Enter your New Password</label>
        <div class="input-group">
          <div class="field">
            <input id="new_password" name="new_password" type="password" placeholder="New Password" minlength="8" required <?php echo $canSubmit ? '' : 'disabled'; ?>>
            <span class="toggle-password" data-target="new_password">👁️</span>
          </div>
        </div>

        <!-- Password Criteria (kept in DOM but hidden; will pop-up on focus) -->
        <div id="pwCriteria" class="mt-2 rounded-lg"
             style="display:none;background:#ffffff; color:#0a1030; border:1px solid rgba(0,0,0,0.1); padding:12px;">
          <p style="font-weight:800;margin:0 0 6px;">Password must have:</p>
          <ul style="margin:0;padding-left:18px;font-size:.9rem;">
            <li data-crit="len">At least <strong>8</strong> characters</li>
            <li data-crit="upper">At least <strong>1 uppercase</strong> letter (A–Z)</li>
            <li data-crit="lower">At least <strong>1 lowercase</strong> letter (a–z)</li>
            <li data-crit="num">At least <strong>1 number</strong> (0–9)</li>
            <li data-crit="spec">At least <strong>1 special</strong> character (!@#$%^&* etc.)</li>
          </ul>
        </div>

        <label>Confirm your Password</label>
        <div class="input-group">
          <div class="field">
            <input id="confirm_password" name="confirm_password" type="password" placeholder="Confirm Password" minlength="8" required <?php echo $canSubmit ? '' : 'disabled'; ?>>
            <span class="toggle-password" data-target="confirm_password">👁️</span>
          </div>
        </div>

        <button type="submit" class="submit-button" <?php echo $canSubmit ? '' : 'disabled'; ?>>CHANGE</button>
      </form>

      <p class="hint">Password must be at least 8 characters. For better security, include letters, numbers, and a symbol.</p>

      <a class="back" href="all_log-in.php">← Back to login</a>
    </div>
  </div>

  <script>
    // Live confirm hint (kept)
    const np = document.getElementById('new_password');
    const cp = document.getElementById('confirm_password');
    function softHint() {
      if (!np || !cp) return;
      if (cp.value.length === 0) { cp.style.outline = ''; return; }
      if (np.value !== cp.value) {
        cp.style.outline = '2px solid rgba(220,38,38,.6)';
      } else {
        cp.style.outline = '2px solid rgba(16,185,129,.6)';
      }
    }
    ['input','change','blur'].forEach(ev=>{
      if (np) np.addEventListener(ev, softHint);
      if (cp) cp.addEventListener(ev, softHint);
    });

    /* =============================
       Password Criteria: show ONLY when textbox is focused/clicked
       Pop-over that follows the field; hides on blur/click-away.
       ============================= */
    (function () {
      const form = document.querySelector('form');
      const box = document.getElementById('pwCriteria');
      if (!form || !np || !box) return;

      // transform criteria into floating popover appended to <body>
      const body = document.body;
      Object.assign(box.style, {
        position: 'absolute',
        top: '-9999px',
        left: '-9999px',
        width: 'min(360px, 92vw)',
        zIndex: '9999',
        display: 'none',
        background: '#ffffff',
        color: '#0a1030',
        borderRadius: '12px',
        boxShadow: '0 10px 30px rgba(0,0,0,0.2)',
        border: '1px solid rgba(0,0,0,0.1)',
        padding: '12px'
      });
      body.appendChild(box);

      // little arrow
      const arrow = document.createElement('div');
      Object.assign(arrow.style, {
        position: 'absolute',
        width: '12px',
        height: '12px',
        background: '#ffffff',
        borderLeft: '1px solid rgba(0,0,0,0.1)',
        borderTop: '1px solid rgba(0,0,0,0.1)',
        transform: 'rotate(45deg)',
        zIndex: '10000',
        display: 'none'
      });
      body.appendChild(arrow);

      function positionBox() {
        const r = np.getBoundingClientRect();
        const scrollY = window.scrollY || document.documentElement.scrollTop;
        const scrollX = window.scrollX || document.documentElement.scrollLeft;
        const gap = 8;
        const estWidth = Math.min(360, window.innerWidth * 0.92);
        const top = r.bottom + scrollY + gap;
        let left = r.left + scrollX;
        if (left + estWidth > scrollX + window.innerWidth - 8) {
          left = scrollX + window.innerWidth - estWidth - 8;
        }
        box.style.top = `${top}px`;
        box.style.left = `${left}px`;
        box.style.display = 'block';
        arrow.style.top = `${r.bottom + scrollY + 2}px`;
        arrow.style.left = `${r.left + scrollX + 16}px`;
        arrow.style.display = 'block';
      }
      function show() { positionBox(); }
      function hide() { box.style.display = 'none'; arrow.style.display = 'none'; }

      // Only show when password field is focused/typed/clicked
      np.addEventListener('focus', show);
      np.addEventListener('click', show);
      np.addEventListener('input', show);

      // Hide when clicking elsewhere (allow clicks inside box)
      document.addEventListener('mousedown', (e) => {
        if (e.target === np || box.contains(e.target)) return;
        hide();
      });
      np.addEventListener('blur', () => setTimeout(() => {
        const active = document.activeElement;
        if (active === np || box.contains(active)) return;
        hide();
      }, 100));

      window.addEventListener('resize', () => { if (box.style.display === 'block') positionBox(); });
      window.addEventListener('scroll', () => { if (box.style.display === 'block') positionBox(); }, { passive: true });

      // RULE CHECKING: color each rule red/green; block submit if not met
      const critEls = {
        len:   box.querySelector('[data-crit="len"]'),
        upper: box.querySelector('[data-crit="upper"]'),
        lower: box.querySelector('[data-crit="lower"]'),
        num:   box.querySelector('[data-crit="num"]'),
        spec:  box.querySelector('[data-crit="spec"]'),
      };
      function setState(el, ok) {
        if (!el) return;
        el.style.color = ok ? '#16a34a' : '#dc2626';
        el.style.fontWeight = ok ? '700' : '400';
      }
      function check(p) {
        const rules = {
          len:   p.length >= 8,
          upper: /[A-Z]/.test(p),
          lower: /[a-z]/.test(p),
          num:   /[0-9]/.test(p),
          spec:  /[!@#$%^&*(),.?":{}|<>\[\]\\\/_\-+=;\'`~]/.test(p),
        };
        setState(critEls.len, rules.len);
        setState(critEls.upper, rules.upper);
        setState(critEls.lower, rules.lower);
        setState(critEls.num, rules.num);
        setState(critEls.spec, rules.spec);
        return rules;
      }
      const update = () => check(np.value || '');
      np.addEventListener('input', update);
      np.addEventListener('blur', update);
      document.addEventListener('DOMContentLoaded', update);

      form.addEventListener('submit', function (e) {
        const r = check(np.value || '');
        const okAll = r.len && r.upper && r.lower && r.num && r.spec;
        if (!okAll) {
          e.preventDefault();
          if (typeof Swal !== 'undefined') {
            Swal.fire({
              icon: 'warning',
              title: 'Password does not meet criteria',
              html: 'Please follow the password rules shown.',
              confirmButtonColor: '#f59e0b'
            }).then(() => { np.focus(); show(); });
          }
        } else if (cp && np.value !== cp.value) {
          e.preventDefault();
          if (typeof Swal !== 'undefined') {
            Swal.fire({
              icon: 'warning',
              title: 'Passwords do not match',
              text: 'Please make sure both passwords match.',
              confirmButtonColor: '#f59e0b'
            }).then(() => { cp.focus(); });
          }
        }
      }, true);
    })();
  </script>
</body>
</html>

<?php
if (ob_get_level() > 0) { ob_end_flush(); }
?>
