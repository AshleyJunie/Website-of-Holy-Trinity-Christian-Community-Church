<?php
// verify-email.php  (ONE PAGE: token link + mobile OTP fallback)
require_once 'db-connection.php';
mysqli_set_charset($db_connection, 'utf8mb4');

if (session_status() === PHP_SESSION_NONE) session_start();

/* =========================
   PHPMailer (same structure)
========================= */
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* Build token link (used in OTP email) */
function buildVerifyUrl($token) {
    return sprintf('%s://%s%s/verify-email.php?token=%s',
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http',
        $_SERVER['HTTP_HOST'],
        rtrim(dirname($_SERVER['PHP_SELF']), '/\\'),
        urlencode($token)
    );
}

/* Send OTP + link (ADDED; does not modify your existing login mailer) */
function sendVerificationOTPEmail($toEmail, $toName, $otp, $token) {
    $verifyUrl = buildVerifyUrl($token);
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'holytrinitychristiancommunityc@gmail.com';
        $mail->Password   = 'jngx vtqb urun yjur'; // app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('holytrinitychristiancommunityc@gmail.com', 'HTCCC Verification');
        $mail->addAddress($toEmail, $toName ?: $toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Your HTCCC verification code';
        $mail->Body = '
          <div style="font-family:Arial,Helvetica,sans-serif;line-height:1.6">
            <h2>Verify your account</h2>
            <p>Hi '.htmlspecialchars($toName ?: $toEmail).',</p>
            <p>Here is your <b>verification code</b> (valid for 15 minutes):</p>
            <p style="font-size:28px;letter-spacing:3px;margin:16px 0;"><b>'.htmlspecialchars($otp).'</b></p>
            <p>You can also verify by clicking this link:</p>
            <p><a href="'.htmlspecialchars($verifyUrl).'" style="color:#2563eb">'.htmlspecialchars($verifyUrl).'</a></p>
            <hr>
            <p style="color:#555;font-size:12px">If you didn\'t try to verify, you can ignore this email.</p>
          </div>';
        $mail->AltBody = "Your verification code: $otp\n\nOr verify via: $verifyUrl";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return "Mailer Error: ".$mail->ErrorInfo;
    }
}

/* =========================
   Server result (kept)
========================= */
$token   = $_GET['token'] ?? '';
$status  = '';
$message = '';

/* =========================
   Handle OTP actions (ADDED)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['__action'] ?? '';
    if ($action === 'request_code') {
        $identity = trim($_POST['identity'] ?? '');
        if ($identity === '') {
            $_SESSION['vf_error'] = 'Please enter your username or email.';
            header('Location: verify-email.php'); exit;
        }

        $sql = "SELECT individual_id, individual_username, individual_firstname, individual_lastname, individual_email_address, email_verification_token
                FROM individual_table
                WHERE individual_username = ? OR individual_email_address = ?
                LIMIT 1";
        if ($st = $db_connection->prepare($sql)) {
            $st->bind_param('ss', $identity, $identity);
            $st->execute();
            $res = $st->get_result();
            if ($u = $res->fetch_assoc()) {
                // ensure token exists
                $tok = $u['email_verification_token'];
                if (!$tok) {
                    try { $tok = bin2hex(random_bytes(32)); }
                    catch (Throwable $e) { $tok = bin2hex(openssl_random_pseudo_bytes(32)); }
                    if ($up = $db_connection->prepare("UPDATE individual_table SET email_verification_token=? WHERE individual_id=? LIMIT 1")) {
                        $up->bind_param('si', $tok, $u['individual_id']);
                        $up->execute();
                        $up->close();
                    }
                }

                // generate OTP
                $otp = str_pad((string)random_int(0,999999), 6, '0', STR_PAD_LEFT);
                $exp = date('Y-m-d H:i:s', time() + 15*60);
                if ($up2 = $db_connection->prepare("UPDATE individual_table SET otp_code=?, otp_expiry=? WHERE individual_id=? LIMIT 1")) {
                    $up2->bind_param('ssi', $otp, $exp, $u['individual_id']);
                    $up2->execute();
                    $up2->close();
                }

                $name  = trim(($u['individual_firstname'] ?? '').' '.($u['individual_lastname'] ?? '')) ?: $u['individual_username'];
                $email = $u['individual_email_address'];

                $sent = sendVerificationOTPEmail($email, $name, $otp, $tok);
                $_SESSION['vf_ok']    = ($sent === true) ? 'We sent a 6-digit code to your email.' : null;
                $_SESSION['vf_error'] = ($sent === true) ? null : ('Could not send code. '.$sent);
            } else {
                $_SESSION['vf_error'] = 'Account not found for that username/email.';
            }
            $st->close();
        }
        header('Location: verify-email.php'); exit;
    }

    if ($action === 'submit_code') {
        $identity = trim($_POST['identity'] ?? '');
        $code     = trim($_POST['otp'] ?? '');
        if ($identity === '' || $code === '') {
            $_SESSION['vf_error'] = 'Enter your username/email and the 6-digit code.';
            header('Location: verify-email.php'); exit;
        }

        $sql = "SELECT individual_id, otp_code, otp_expiry
                FROM individual_table
                WHERE (individual_username = ? OR individual_email_address = ?)
                LIMIT 1";
        if ($st = $db_connection->prepare($sql)) {
            $st->bind_param('ss', $identity, $identity);
            $st->execute();
            $res = $st->get_result();
            if ($u = $res->fetch_assoc()) {
                $now = time();
                $valid = ($u['otp_code'] && $u['otp_code'] === $code);
                $notExpired = ($u['otp_expiry'] && strtotime($u['otp_expiry']) >= $now);
                if ($valid && $notExpired) {
                    if ($up = $db_connection->prepare("UPDATE individual_table
                        SET account_status='Active', email_verified_at=NOW(),
                            otp_code=NULL, otp_expiry=NULL, email_verification_token=NULL
                        WHERE individual_id=? LIMIT 1")) {
                        $up->bind_param('i', $u['individual_id']);
                        $up->execute();
                        $up->close();
                    }
                    $_SESSION['vf_ok'] = 'Your account is now verified. You can log in.';
                    header('Location: all_log-in.php'); exit;
                } else {
                    $_SESSION['vf_error'] = 'Invalid or expired code. Please request a new one.';
                }
            } else {
                $_SESSION['vf_error'] = 'Account not found.';
            }
            $st->close();
        }
        header('Location: verify-email.php'); exit;
    }
}

/* =========================
   Token link verification (KEPT)
========================= */
if (!$token) {
    $status = 'error';
    $message = 'Invalid verification link.';
} else {
    $stmt = $db_connection->prepare("SELECT individual_id, email_verified_at, account_status FROM individual_table WHERE email_verification_token = ? LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if ($row['email_verified_at']) {
            $status = 'info';
            $message = 'Your account is already verified.';
        } else {
            $update = $db_connection->prepare("
                UPDATE individual_table 
                SET email_verified_at = NOW(), account_status = 'Active', email_verification_token = NULL, otp_code=NULL, otp_expiry=NULL
                WHERE individual_id = ? LIMIT 1
            ");
            $update->bind_param("i", $row['individual_id']);
            $update->execute();
            $status = 'success';
            $message = 'Your email has been successfully verified! You can now log in.';
        }
    } else {
        $status = 'error';
        $message = 'Invalid or expired verification token.';
    }
    $stmt->close();
}

$db_connection->close();

/* flashes for OTP forms */
$vf_ok    = $_SESSION['vf_ok']    ?? null;
$vf_error = $_SESSION['vf_error'] ?? null;
unset($_SESSION['vf_ok'], $_SESSION['vf_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Email Verification - HTCCC</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <!-- Hard redirect fallback even if JS fails (3s) -->
  <meta http-equiv="refresh" content="3;url=all_log-in.php">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
  <style>
    body {
      margin: 0; min-height: 100vh;
      background: url('image/log_in-form-bg.jpg') center/cover no-repeat;
      display: flex; align-items: center; justify-content: center;
      font-family: "Inter", system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      color:#fff;
    }
    .wrap{max-width:560px;width:92%;background:#0a1030cc;border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:20px 18px}
    .h{margin:0 0 6px;font-weight:800;font-size:20px}
    .p{margin:0 0 12px;opacity:.88}
    .row{display:flex;gap:8px;flex-wrap:wrap}
    input,button{width:100%;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#0f1540;color:#fff;font-size:15px;outline:none}
    button{background:#3bb9ff;border:0;font-weight:800;color:#001a2d;cursor:pointer}
    .note{font-size:12px;opacity:.75}
    .sep{height:1px;background:linear-gradient(90deg,transparent,#3bb9ff44,transparent);margin:14px 0}
    .tag{display:inline-block;padding:4px 8px;border-radius:999px;font-weight:800}
    .ok{background:#16a34acc;color:#001a2d}
    .err{background:#ef444455}
  </style>
</head>
<body>

  <!-- Plain HTML message so it works even without JS -->
  <div class="wrap" id="noscriptMessage">
    <div class="h">
      <?= ($status==='success'?'Email Verified!':($status==='info'?'Already Verified':'Verification Failed')) ?>
    </div>
    <p class="p"><?= htmlspecialchars($message) ?></p>

    <?php if ($vf_ok): ?>
      <div class="tag ok"><?= htmlspecialchars($vf_ok) ?></div>
    <?php elseif ($vf_error): ?>
      <div class="tag err"><?= htmlspecialchars($vf_error) ?></div>
    <?php endif; ?>

    <div class="sep"></div>

    <!-- OTP panels (available on the same page for phones) -->
    <div class="h">Verify using a 6-digit code</div>
    <p class="note">If the email link can’t be opened on your phone, request a code and enter it below.</p>

    <form method="POST" action="" class="row" style="margin-bottom:8px">
      <input type="hidden" name="__action" value="request_code">
      <input name="identity" type="text" placeholder="Username or Email" required>
      <button type="submit">Send code to my email</button>
    </form>

    <form method="POST" action="" class="row">
      <input type="hidden" name="__action" value="submit_code">
      <input name="identity" type="text" placeholder="Username or Email" required>
      <input name="otp" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="6-digit code" required>
      <button type="submit">Verify my account</button>
    </form>

    <p class="note" style="margin-top:10px">You’ll be redirected to the login page automatically in a few seconds.</p>
  </div>

  <!-- Your existing SweetAlert flow (KEPT), but redirect still happens even if JS fails -->
  <script>
  document.addEventListener('DOMContentLoaded', () => {
      const status  = <?= json_encode($status) ?>;
      const message = <?= json_encode($message) ?>;

      let title = '';
      let icon = '';
      switch (status) {
          case 'success': title = 'Email Verified!';   icon = 'success'; break;
          case 'info':    title = 'Already Verified';  icon = 'info';    break;
          default:        title = 'Verification Failed'; icon = 'error';
      }

      // Show the alert for better UX (desktop or phone), but redirection works without it
      Swal.fire({
          icon, title, text: message,
          background: '#0a1030', color: '#fff',
          showConfirmButton: true,
          confirmButtonColor: '#3bb9ff',
          confirmButtonText: 'Go to Login',
          timer: 3000, timerProgressBar: true
      }).then(() => { window.location.href = 'all_log-in.php'; });

      // Extra safety redirect
      setTimeout(() => { window.location.href = 'all_log-in.php'; }, 3000);
  });
  </script>
</body>
</html>
