<?php
// ======================================================================
// FILE: resend_verification.php
// ----------------------------------------------------------------------
// POST: individual_id=...  → regenerates email_verification_token and
// sends a verification email (using phone/LAN-safe verify link).
// Returns a simple text message (you can switch to JSON if preferred).
// ======================================================================

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/db-connection.php';
require_once __DIR__ . '/app-url.php';

// PHPMailer (manual)
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendVerificationEmail_resend($toEmail, $toName, $token) {
    $verifyUrl = htccc_verify_link($token);

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'holytrinitychristiancommunityc@gmail.com';
        $mail->Password   = 'jngx vtqb urun yjur'; // Gmail App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('holytrinitychristiancommunityc@gmail.com', 'HTCCC Verification');
        $mail->addAddress($toEmail, $toName ?: $toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Verify your HTCCC account';
        $mail->Body = '
          <div style="font-family:Arial,Helvetica,sans-serif;line-height:1.5">
            <h2>Verify your account</h2>
            <p>Hi '.htmlspecialchars($toName ?: $toEmail).',</p>
            <p>Click the button below to verify your email:</p>
            <p>
              <a href="'.htmlspecialchars($verifyUrl).'"
                 style="display:inline-block;padding:10px 16px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px">
                Verify my email
              </a>
            </p>
            <p>If the button doesn\'t work, copy and paste this link:<br>'.
              htmlspecialchars($verifyUrl).'</p>
          </div>';
        $mail->AltBody = "Verify your account: $verifyUrl";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return 'Mailer Error: '.$mail->ErrorInfo;
    }
}

header('Content-Type: text/plain; charset=utf-8');

$indId = isset($_POST['individual_id']) ? (int)$_POST['individual_id'] : 0;
if ($indId <= 0) {
    http_response_code(400);
    echo "Invalid user."; exit;
}

$q = "SELECT individual_id,
             TRIM(NULLIF(CONCAT_WS(' ', individual_firstname, individual_lastname), '')) AS built_name,
             individual_username,
             individual_email_address
      FROM individual_table
      WHERE individual_id = ?
      LIMIT 1";
if ($stmt = mysqli_prepare($db_connection, $q)) {
    mysqli_stmt_bind_param($stmt, "i", $indId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {

        // new token
        try { $token = bin2hex(random_bytes(32)); }
        catch (Throwable $e) { $token = bin2hex(openssl_random_pseudo_bytes(32)); }

        $u = "UPDATE individual_table SET email_verification_token=? WHERE individual_id=? LIMIT 1";
        if ($uStmt = mysqli_prepare($db_connection, $u)) {
            mysqli_stmt_bind_param($uStmt, "si", $token, $indId);
            mysqli_stmt_execute($uStmt);
            mysqli_stmt_close($uStmt);

            $name = $row['built_name'] ?: ($row['individual_username'] ?: $row['individual_email_address']);
            $sent = sendVerificationEmail_resend($row['individual_email_address'], $name, $token);
            if ($sent === true) {
                echo "A new verification email has been sent.";
            } else {
                http_response_code(500);
                echo "Token updated but failed to send email. $sent";
            }
        } else {
            http_response_code(500);
            echo "Could not update verification token.";
        }
    } else {
        http_response_code(404);
        echo "User not found.";
    }
    mysqli_stmt_close($stmt);
} else {
    http_response_code(500);
    echo "Failed to prepare query.";
}
