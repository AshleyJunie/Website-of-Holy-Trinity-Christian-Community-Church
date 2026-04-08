<?php
// ==== Prevent "headers already sent" + safe session ====
if (!headers_sent()) { ob_start(); }
if (session_status() === PHP_SESSION_NONE) { session_start(); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Verify Identity</title>

    <!-- keep your original stylesheet links -->
    <link rel="stylesheet" href="individual-verify.css">
    <link rel="icon" type="image/x-icon" href="image//httc_main-logo.jpg">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="/HTCCC-SYSTEM/css/individual-verify.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/individual-verify.css'); ?>">

    <!-- Added: Inter font for modern look -->
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">

    <!-- Added: Modern visual layer (add-only; doesn’t remove your CSS) -->
    <style>
      :root{
        --brand:#0A0E3F;
        --brand-2:#0E7AFE;
        --brand-3:#0064D6;
        --text:#0f172a;
        --muted:#6b7280;
        --ring: rgba(14,122,254,.28);
        --card: rgba(255,255,255,.88);
      }

      html,body{height:100%}
      *{box-sizing:border-box}

      body{
        margin:0;
        font-family:"Inter", system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
        color:var(--text);
        /* gradient + image overlay for depth */
        background:
          radial-gradient(1200px 600px at 10% -10%, rgba(14,122,254,.18), transparent 60%),
          radial-gradient(1100px 700px at 110% 120%, rgba(10,14,63,.25), transparent 55%),
          linear-gradient(180deg, rgba(10,14,63,.45), rgba(10,14,63,.45)),
          url("Image/log_in-form-bg.jpg") center/cover no-repeat fixed;
        display:flex;
        align-items:center;
        justify-content:center;
        padding:28px;
      }

      /* center stage container to avoid layout jump if external CSS loads later */
      .viewport{
        width:100%;
        max-width: 520px;
      }

      .form-container{
        background: var(--card);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border-radius: 20px;
        padding: 28px 24px 24px;
        box-shadow:
          0 20px 40px rgba(2,6,23,.30),
          inset 0 1px 0 rgba(255,255,255,.22);
        border: 1px solid rgba(255,255,255,.35);
        position: relative;
        overflow: hidden;
      }

      /* subtle decorative glow */
      .form-container::before{
        content:"";
        position:absolute; inset:-2px;
        background: radial-gradient(500px 220px at 10% 0%, rgba(14,122,254,.15), transparent 60%),
                    radial-gradient(400px 200px at 100% 100%, rgba(255,255,255,.4), transparent 60%);
        pointer-events:none;
        z-index:0;
      }

      /* brand header (add-only) */
      .brand{
        display:flex; align-items:center; gap:12px; margin-bottom:6px; position:relative; z-index:1;
      }
      .brand img{
        width:44px; height:44px; border-radius:12px; object-fit:cover;
        box-shadow: 0 8px 18px rgba(2,6,23,.25);
      }
      .brand .t1{ margin:0; font-size: 1.05rem; font-weight: 800; color: var(--brand); letter-spacing:.2px; }
      .brand .t2{ margin:2px 0 0; font-size: .86rem; color: var(--muted); font-weight:600; }

      .form-container h2{
        margin: 14px 0 8px;
        font-size: 1.4rem;
        color: #0b122d;
        letter-spacing:.25px;
        position:relative; z-index:1;
      }
      .helper{
        margin:0 0 16px;
        font-size:.95rem;
        color: var(--muted);
        position:relative; z-index:1;
      }

      /* input groups */
      .input-group{ text-align:left; margin: 14px 0; position:relative; z-index:1; }
      .input-group label{
        display:block; font-size:.9rem; font-weight:700; margin-bottom:8px; color:#0b122d;
      }
      .input-affix{
        display:flex; align-items:center; gap:10px;
        border:1px solid #d1d5db; border-radius:14px; padding: 12px 14px;
        background:#fff;
        transition: box-shadow .2s, border-color .2s, transform .06s;
      }
      .input-affix:focus-within{
        border-color: var(--brand-2);
        box-shadow: 0 0 0 8px var(--ring);
      }
      .input-affix span{ font-size:1.05rem; color:#6b7280; }
      .input-affix input{
        flex:1; border:0; outline:0; font-size:1rem; padding:0; background:transparent; color:#0b122d;
      }
      input[type="text"]::placeholder, input[type="email"]::placeholder{ color:#9aa3af; }

      /* buttons */
      .btn-row{ display:flex; gap:10px; align-items:center; margin-top:14px; }
      .submit-button, .send-code-button{
        display:inline-flex; align-items:center; justify-content:center; gap:8px;
        width:100%;
        padding: 12px 18px;
        background: linear-gradient(180deg, var(--brand-2), var(--brand-3));
        color:#fff; border:none; border-radius:14px;
        font-weight:800; letter-spacing:.2px;
        cursor:pointer;
        transition: transform .06s ease, box-shadow .2s ease, opacity .2s ease;
        box-shadow: 0 12px 26px rgba(14,122,254,.35);
        text-decoration:none;
      }
      .submit-button:hover, .send-code-button:hover{ transform: translateY(-1px); }
      .submit-button:active, .send-code-button:active{ transform: translateY(0); opacity:.96; }

      .note{ margin-top:10px; font-size:.86rem; color: var(--muted); }

      .back-link{
        display:inline-flex; align-items:center; gap:8px;
        margin-top:14px; font-size:.95rem; text-decoration:none;
        color: var(--brand);
        font-weight:800;
        position:relative; z-index:1;
      }
      .back-link:hover{ text-decoration:underline; }

      /* small screens */
      @media (max-width:480px){
        body{ padding:18px; }
        .form-container{ padding:24px 18px 18px; }
        .brand .t1{ font-size:1rem; }
        .form-container h2{ font-size:1.28rem; }
      }
    </style>
</head>
<body>
  <div class="viewport">
    <div class="form-container">
      <!-- Added non-breaking brand header -->
      <div class="brand">
        <img src="image/httc_main-logo.jpg" alt="HTCCC">
        <div>
          <p class="t1">Holy Trinity Christian Community Church</p>
          <p class="t2">Account Recovery</p>
        </div>
      </div>

      <h2>Forgot Your Password?</h2>
      <p class="helper">Enter your registered email to receive a one-time 6-digit OTP code.</p>

      <!-- Form to request OTP (kept) -->
      <?php if (!isset($_POST['send_otp_success'])): ?>
      <form method="post" action="">
          <div class="input-group">
            <label for="email">Email Address</label>
            <div class="input-affix">
              <span>📧</span>
              <input id="email" type="email" name="email" required placeholder="you@example.com" autocomplete="email" autofocus>
            </div>
          </div>
          <div class="btn-row">
            <button type="submit" name="send_otp" class="send-code-button">Send OTP</button>
          </div>
          <p class="note">We’ll email a code that expires in 10 minutes.</p>
      </form>
      <?php endif; ?>

      <!-- OTP Verification Form (kept) -->
      <?php if (isset($_POST['send_otp_success'])): ?>
      <form method="post" action="">
          <div class="input-group">
            <label for="otp">OTP Code</label>
            <div class="input-affix">
              <span>🔒</span>
              <input id="otp" type="text" name="otp" required placeholder="Enter 6-digit OTP" inputmode="numeric" pattern="[0-9]*" minlength="6" maxlength="6" autofocus>
            </div>
          </div>
          <!-- hidden email field -->
          <input type="hidden" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
          <div class="btn-row">
            <button type="submit" name="verify_otp" class="submit-button">Verify</button>
          </div>
          <p class="note">Didn’t receive it? Check Spam/Promotions or request again.</p>
      </form>
      <?php endif; ?>

      <a href="all_log-in.php" class="back-link">← Back to login page</a>
    </div>
  </div>
</body>
</html>

<?php
// ===== PHP logic (unchanged functionality) =====
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
include "db-connection.php"; 

if (isset($_POST['send_otp'])) {
    $email = $_POST['email'];

    $stmt = $db_connection->prepare("SELECT individual_id FROM individual_table WHERE individual_email_address=?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $otp = rand(100000, 999999);
        $expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

        $update = $db_connection->prepare("UPDATE individual_table SET otp_code=?, otp_expiry=? WHERE individual_email_address=?");
        $update->bind_param("sss", $otp, $expiry, $email);
        $update->execute();

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'holytrinitychristiancommunityc@gmail.com';
            $mail->Password   = 'grqx sjzc clta qhhr'; 
            $mail->SMTPSecure = 'tls';
            $mail->Port       = 587;

            $mail->setFrom('holytrinitychristiancommunityc@gmail.com', 'HTCCC ADMIN');
            $mail->addAddress($email);

            $mail->isHTML(true);
            $mail->Subject = 'RESET PASSWORD';
            $mail->Body    = "Your OTP code is: <b>$otp</b>. It will expire in 10 minutes.";

            $mail->send();

            // store email in session
            $_SESSION['otp_email'] = $email;

            echo "<script>
                Swal.fire({
                    icon: 'success',
                    title: 'OTP Sent!',
                    text: 'We have sent an OTP to your email.',
                    confirmButtonColor: '#007BFF'
                }).then(() => {
                    window.location.href='otp_verify.php';
                });
            </script>";
        } catch (Exception $e) {
            echo "<script>
                Swal.fire({
                    icon: 'error',
                    title: 'Mailer Error',
                    text: '". addslashes($mail->ErrorInfo) ."',
                    confirmButtonColor: '#d33'
                });
            </script>";
        }
    } else {
        echo "<script>
            Swal.fire({
                icon: 'error',
                title: 'Email Not Found',
                text: 'Please use a registered email address.',
                confirmButtonColor: '#d33'
            });
        </script>";
    }
}

// VERIFY OTP
if (isset($_POST['verify_otp'])) {
    $email = $_POST['email'];
    $otp   = $_POST['otp'];

    $stmt = $db_connection->prepare("SELECT otp_code, otp_expiry FROM individual_table WHERE individual_email_address=?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if ($row) {
        if ($row['otp_code'] == $otp && strtotime($row['otp_expiry']) > time()) {

            // ADD-ONLY: persist verified email again for the reset page
            $_SESSION['otp_email'] = $email;

            echo "<script>
                Swal.fire({
                    icon: 'success',
                    title: 'OTP Verified!',
                    text: 'Redirecting to reset password...',
                    confirmButtonColor: '#007BFF'
                }).then(() => {
                    window.location.href='individual-reset_pw.php?email=" . rawurlencode($email) . "';
                });
            </script>";
        } else {
            echo "<script>
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid OTP',
                    text: 'The OTP is incorrect or expired.',
                    confirmButtonColor: '#d33'
                });
            </script>";
        }
    } else {
        echo "<script>
            Swal.fire({
                icon: 'error',
                title: 'Email Not Found',
                text: 'Please try again.',
                confirmButtonColor: '#d33'
            });
        </script>";
    }
}

// optional: flush buffer
if (ob_get_level() > 0) { ob_end_flush(); }
?>
