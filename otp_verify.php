<?php
session_start();

/**
 * otp_verify.php
 */

require_once 'db-connection.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

// MAIL CREDENTIALS
define('MAIL_USER', 'holytrinitychristiancommunityc@gmail.com');
define('MAIL_PASS', 'jngx vtqb urun yjur');

/* --- FUNCTION: SEND OTP MAIL --- */
function sendOtpMail($toEmail, $otp) {
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
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Your OTP Code';
        $mail->Body    = "Your OTP code is: <b>{$otp}</b>. It will expire in 10 minutes.";

        $mail->send();
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $mail->ErrorInfo];
    }
}

/* --- HANDLE BACK BUTTON: CLEAR SESSION --- */
if (isset($_GET['back'])) {
    unset($_SESSION['otp_email']);
    header("Location: individual-verify.php");
    exit;
}

/* --- HANDLE incoming email param --- */
if (isset($_REQUEST['email']) && filter_var($_REQUEST['email'], FILTER_VALIDATE_EMAIL)) {
    $_SESSION['otp_email'] = $_REQUEST['email'];
}

/* --- GET email from session --- */
$email = $_SESSION['otp_email'] ?? null;

/* --- Handle resend OTP --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_otp') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$email) {
        echo json_encode(['success' => false, 'message' => 'No email in session.']);
        exit;
    }

    $stmt = $db_connection->prepare("SELECT individual_id FROM individual_table WHERE individual_email_address=?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if (!$res || $res->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Email not found.']);
        exit;
    }

    $otp = random_int(100000, 999999);
    $expiry = date("Y-m-d H:i:s", strtotime('+10 minutes'));

    $update = $db_connection->prepare("UPDATE individual_table SET otp_code=?, otp_expiry=? WHERE individual_email_address=?");
    $update->bind_param("sss", $otp, $expiry, $email);
    $update->execute();

    $mailRes = sendOtpMail($email, $otp);
    echo json_encode($mailRes['success']
        ? ['success' => true, 'message' => 'OTP sent.']
        : ['success' => false, 'message' => 'Mailer Error: ' . ($mailRes['error'] ?? 'unknown')]
    );
    exit;
}

/* --- Handle verify OTP --- */
$verify_message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_otp') {
    $code = trim($_POST['otp_code'] ?? '');
    if (!$email) {
        $verify_message = ['type' => 'error', 'text' => 'Session expired. Please re-enter email.'];
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $verify_message = ['type' => 'error', 'text' => 'Enter a 6-digit OTP code.'];
    } else {
        $stmt = $db_connection->prepare("SELECT otp_code, otp_expiry FROM individual_table WHERE individual_email_address=?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            $verify_message = ['type' => 'error', 'text' => 'Email not found.'];
        } elseif ($row['otp_code'] === $code && strtotime($row['otp_expiry']) > time()) {
            $clear = $db_connection->prepare("UPDATE individual_table SET otp_code=NULL, otp_expiry=NULL WHERE individual_email_address=?");
            $clear->bind_param("s", $email);
            $clear->execute();

            header("Location: individual-reset_pw.php?email=" . urlencode($email));
            exit;
        } else {
            $verify_message = ['type' => 'error', 'text' => 'Invalid or expired OTP.'];
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Enter OTP Code</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
body {
  font-family: Arial, sans-serif;
  /* Correct way: combine image, position, repeat, and color in background */
  background: lightblue url("css/image/log_in-form-bg.jpg") center/cover no-repeat;

  display: flex;
  justify-content: center;
  align-items: center;
  height: 100vh;
  margin: 0;
}

    .card { width:380px; background:white; border-radius:14px; box-shadow:0 8px 30px rgba(0,0,0,0.12); padding:26px; text-align:center; }
    .card h2 { font-size:20px; margin:10px 0; color:#333; }
    .lead { color:#777; font-size:13px; margin-bottom:16px; }
    .otp-inputs { display:flex; justify-content:space-between; gap:8px; margin-bottom:12px; }
    .otp-inputs input { width:44px; height:56px; border-radius:8px; border:1px solid #ccc; text-align:center; font-size:20px; }
    .verify-btn, .back-btn, .send-email-btn { width:100%; padding:12px; border-radius:28px; border:none; font-weight:700; cursor:pointer; margin-top:10px; }
    .verify-btn { background: blue; color:white; }
    .back-btn { background:#6c757d; color:white; }
    .send-email-btn { background:#0d6efd; color:white; }
    .small-link { display:block; margin:10px 0; color:#007bff; cursor:pointer; }
</style>
</head>
<body>
<div class="card">
    <h2>Enter OTP Code</h2>
    <p class="lead">We sent a 6-digit code to your email.</p>

    <?php if (!$email): ?>
        <form method="get">
            <input type="email" name="email" required placeholder="Enter your email" style="width:100%;padding:10px;margin-bottom:12px;border-radius:8px;border:1px solid #ccc;">
            <button type="submit" class="send-email-btn">Send OTP</button>
        </form>
        <a href="individual-verify.php" class="small-link">← Back</a>
    <?php else: ?>
        <form method="post" id="verifyForm">
            <div class="otp-inputs">
                <input type="text" maxlength="1" class="otp-digit">
                <input type="text" maxlength="1" class="otp-digit">
                <input type="text" maxlength="1" class="otp-digit">
                <input type="text" maxlength="1" class="otp-digit">
                <input type="text" maxlength="1" class="otp-digit">
                <input type="text" maxlength="1" class="otp-digit">
            </div>
            <input type="hidden" name="otp_code" id="otp_code">
            <input type="hidden" name="action" value="verify_otp">
            <button type="submit" class="verify-btn">Verify Code</button>
        </form>
        <form method="get">
            <button type="submit" name="back" value="1" class="back-btn">← Back</button>
        </form>
        <a id="resendBtn" class="small-link">Resend Code</a>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const inputs = document.querySelectorAll('.otp-digit');
    const otpHidden = document.getElementById('otp_code');
    function updateOtp() {
        otpHidden.value = [...inputs].map(i => i.value).join('');
    }
    inputs.forEach((inp, idx) => {
        inp.addEventListener('input', e => {
            e.target.value = e.target.value.replace(/\D/g,'');
            if (e.target.value && idx < inputs.length - 1) inputs[idx+1].focus();
            updateOtp();
        });
    });
    const resendBtn = document.getElementById('resendBtn');
    if (resendBtn) {
        resendBtn.addEventListener('click', e => {
            e.preventDefault();
            resendBtn.textContent = 'Sending...';
            fetch("", {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: new URLSearchParams({action:'send_otp'})
            }).then(r=>r.json()).then(data=>{
                Swal.fire({icon:data.success?'success':'error', text:data.message});
            }).finally(()=>resendBtn.textContent = 'Resend Code');
        });
    }
});
</script>

<?php
if ($verify_message) {
    $type = $verify_message['type'] === 'error' ? 'error' : 'success';
    $text = addslashes($verify_message['text']);
    echo "<script>Swal.fire({icon:'$type', text:'$text'});</script>";
}
?>
</body>
</html>
