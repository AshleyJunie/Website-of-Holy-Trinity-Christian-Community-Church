<?php
/* =========================================================
HTCCC Login (Individuals only)
- Keeps throttle, PHPMailer verify/resend, Unverified modal
- Adds Suspended appeal modal (kept)
- Removed entirely:
    • Any connection/queries to admin_table and pastor_account
    • Admin/Pastor pre-checks and login paths
    • Admin audit hooks and PDO-based admin handler
    • set_db_actor_from_session, audit_log, uuid helpers
========================================================= */

if (session_status() === PHP_SESSION_NONE) session_start();

// ===== HONOR return_to FOR SAFE REDIRECTS =====
if (isset($_GET['return_to']) && !isset($_GET['next'])) {
    $_GET['next'] = $_GET['return_to'];
}

// ---------- DB ----------
require_once 'db-connection.php'; // must set $db_connection (mysqli)

/* ============================
PHPMailer (manual, no Composer)
Folder structure:
HTCCC-SYSTEM/PHPMailer/src/...
============================= */
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error_message      = null;
$login_success      = false;  // to trigger success SweetAlert
$redirect_url       = null;   // where to go after success

// NEW: used to trigger the Unverified SweetAlert with "Resend" option
$need_verification  = false;
$unverified_payload = null; // will hold individual_id, email, name

// Flags and payload for Suspended-account handling.
$is_suspended       = false;
$suspended_payload  = null;

/* =========================================================
CONFIG / FLAGS
========================================================= */
$AJAX_DEBUG = true;            // set to false in production

/* =========================================================
NEW FLAGS: ministry join context (for "already member" alert)
========================================================= */
$already_ministry_member = false;   // true if user is already part of the ministry they tried to join
$ministry_join_context   = null;    // e.g. "Handmaid's of the Lord"

/* =========================================================
HELPERS
========================================================= */

/** Simple JSON reply and exit (for AJAX). */
function json_reply($arr, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

/**
 * Pick a safe redirect URL after login.
 * Only local relative paths are allowed.
 */
function pick_redirect_url_or_default($default) {
    $next = $_GET['next'] ?? '';
    if ($next === '') return $default;
    if (preg_match('~^[a-zA-Z][a-zA-Z0-9+.-]*://~', $next)) return $default; // absolute scheme
    if (strpos($next, '//') === 0) return $default; // protocol-relative
    if (strpos($next, "\n") !== false || strpos($next, "\r") !== false) return $default;
    return $next;
}

/* ---------------------------------------------------------
PHPMailer: Send verification email
--------------------------------------------------------- */
function sendVerificationEmail($toEmail, $toName, $token) {
    // build absolute verify link
    $verifyUrl = sprintf('%s://%s%s/verify-email.php?token=%s',
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http',
        $_SERVER['HTTP_HOST'],
        rtrim(dirname($_SERVER['PHP_SELF']), '/\\'),
        urlencode($token)
    );

    $mail = new PHPMailer(true);
    try {
        // ======= SMTP CONFIG (Gmail app password) =======
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'holytrinitychristiancommunityc@gmail.com';
        $mail->Password   = 'jngx vtqb urun yjur'; // app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        // ================================================

        $mail->setFrom('holytrinitychristiancommunityc@gmail.com', 'HTCCC Verification');
        $mail->addAddress($toEmail, $toName ?: $toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Verify your HTCCC account';
        $mail->Body = '
            <div style="font-family:Arial,Helvetica,sans-serif;line-height:1.5">
            <h2>Verify your account</h2>
            <p>Hi '.htmlspecialchars($toName ?: $toEmail).',</p>
            <p>Thanks for signing up. Please verify your email by clicking the button below:</p>
            <p>
                <a href="'.htmlspecialchars($verifyUrl).'"
                style="display:inline-block;padding:10px 16px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px">
                Verify my email
                </a>
            </p>
            <p>If the button doesn\'t work, copy and paste this link in your browser:<br>'.
                htmlspecialchars($verifyUrl).'</p>
            <hr>
            <p style="color:#555;font-size:12px">If you didn\'t sign up, you can ignore this email.</p>
            </div>
        ';
        $mail->AltBody = "Verify your account: $verifyUrl";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return "Mailer Error: ".$mail->ErrorInfo;
    }
}

/* =========================================================
LOGIN THROTTLE (5 attempts -> 3 minutes lock)
========================================================= */
const MAX_LOGIN_ATTEMPTS = 5;
const LOGIN_LOCK_SECONDS = 180; // 3 minutes

if (!isset($_SESSION['login_throttle'])) {
    $_SESSION['login_throttle'] = []; // [ key => ['count'=>int,'locked_until'=>int] ]
}
function throttle_key($username) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return strtolower(trim($username)).'|'.$ip;
}
function throttle_is_locked($key) {
    $rec = $_SESSION['login_throttle'][$key] ?? ['count'=>0,'locked_until'=>0];
    $now = time();
    if (!empty($rec['locked_until']) && $rec['locked_until'] > $now) {
        return $rec['locked_until'] - $now; // seconds remaining
    }
    return 0;
}
function throttle_record_fail($key) {
    $rec = $_SESSION['login_throttle'][$key] ?? ['count'=>0,'locked_until'=>0];
    $now = time();

    // expire old lock
    if (!empty($rec['locked_until']) && $rec['locked_until'] <= $now) {
        $rec['locked_until'] = 0;
        $rec['count'] = 0;
    }

    $rec['count'] = (int)$rec['count'] + 1;
    $locked = false;
    $left   = max(0, MAX_LOGIN_ATTEMPTS - $rec['count']);

    if ($rec['count'] >= MAX_LOGIN_ATTEMPTS) {
        $rec['locked_until'] = $now + LOGIN_LOCK_SECONDS;
        $rec['count'] = 0; // reset counter when lock begins
        $locked = true;
        $left   = 0;
    }

    $_SESSION['login_throttle'][$key] = $rec;
    return ['locked' => $locked, 'left' => $left, 'remaining' => throttle_is_locked($key)];
}
function throttle_clear($key) {
    unset($_SESSION['login_throttle'][$key]);
}

/* =========================================================
AJAX: Resend verification email
========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['__action']) &&
    $_POST['__action'] === 'resend_verification'
) {
    ob_start();
    $resp = ['ok' => false, 'message' => 'Unable to process request'];

    try {
        $indId = isset($_POST['individual_id']) ? (int)$_POST['individual_id'] : 0;
        if ($indId <= 0) {
            $resp['message'] = 'Invalid user.';
            $debug = ob_get_clean(); if ($AJAX_DEBUG && $debug !== '') $resp['debug'] = $debug;
            json_reply($resp, 400);
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
            if ($user = mysqli_fetch_assoc($res)) {
                $displayName = $user['built_name'] ?: ($user['individual_username'] ?: $user['individual_email_address']);

                try { $newToken = bin2hex(random_bytes(32)); }
                catch (Throwable $e) { $newToken = bin2hex(openssl_random_pseudo_bytes(32)); }

                $u = "UPDATE individual_table
                    SET email_verification_token = ?
                    WHERE individual_id = ?
                    LIMIT 1";
                if ($uStmt = mysqli_prepare($db_connection, $u)) {
                    mysqli_stmt_bind_param($uStmt, "si", $newToken, $indId);
                    mysqli_stmt_execute($uStmt);
                    $okDb = mysqli_stmt_affected_rows($uStmt) >= 0;
                    mysqli_stmt_close($uStmt);

                    if ($okDb) {
                        $sent = sendVerificationEmail($user['individual_email_address'], $displayName, $newToken);
                        if ($sent === true) {
                            $resp = ['ok' => true, 'message' => 'A new verification email has been sent.'];
                        } else {
                            $resp = ['ok' => false, 'message' => 'Token updated but failed to send email.', 'mailer' => $sent];
                        }
                    } else {
                        $resp = ['ok' => false, 'message' => 'Could not update verification token.'];
                    }
                } else {
                    $resp = ['ok' => false, 'message' => 'Failed to prepare update statement.'];
                }
            } else {
                $resp = ['ok' => false, 'message' => 'User not found.'];
            }
            mysqli_stmt_close($stmt);
        } else {
            $resp = ['ok' => false, 'message' => 'Failed to prepare select statement.'];
        }

    } catch (Throwable $ex) {
        $resp = ['ok' => false, 'message' => 'Server exception: '.$ex->getMessage()];
    }

    $debug = ob_get_clean();
    if ($AJAX_DEBUG && $debug !== '') $resp['debug'] = $debug;
    json_reply($resp, 200);
}

/* =========================================================
Sticky username (clear password on error)
========================================================= */
$old_username = isset($_POST['username']) ? (string)$_POST['username'] : '';
$old_password = ''; // cleared for security

/* =========================================================
Compute realtime lock remaining (for UI countdown)
========================================================= */
$lock_remaining = 0;
if ($old_username !== '') {
    $lock_remaining = throttle_is_locked(throttle_key($old_username));
} elseif (!empty($_SESSION['last_login_user'])) {
    $lock_remaining = throttle_is_locked(throttle_key($_SESSION['last_login_user']));
}

/* =========================================================
MAIN AUTH FLOW + throttle (INDIVIDUALS ONLY)
========================================================= */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    (!isset($_POST['__action']) || $_POST['__action'] !== 'resend_verification')
) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $_SESSION['last_login_user'] = $username;

    if ($username === '' || $password === '') {
        $error_message = "Please enter your username and password.";
    } else {
        // --- throttle pre-check ---
        $tkey = throttle_key($username);
        $remaining = throttle_is_locked($tkey);

        if ($remaining > 0) {
            $lock_remaining = $remaining;
            $mins = floor($remaining / 60);
            $secs = $remaining % 60;
            $error_message = sprintf("Too many attempts. Try again in %d:%02d minutes.", $mins, $secs);
        } else {
            $auth_hard_fail     = false;
            $wrong_pass_flag    = false;
            $unknown_user_flag  = false;

            // Individual pre-check
            if (!$auth_hard_fail) {
                $sql = "SELECT individual_id, individual_password
                        FROM individual_table
                        WHERE individual_username = ?
                        LIMIT 1";
                if ($stmt = mysqli_prepare($db_connection, $sql)) {
                    mysqli_stmt_bind_param($stmt, "s", $username);
                    mysqli_stmt_execute($stmt);
                    $res = mysqli_stmt_get_result($stmt);
                    if ($row = mysqli_fetch_assoc($res)) {
                        if ($password !== $row['individual_password']) {
                            $error_message   = "Incorrect password for this username.";
                            $auth_hard_fail  = true;
                            $wrong_pass_flag = true;
                        }
                    }
                    mysqli_stmt_close($stmt);
                }
            }

            // If no user matched at all -> account not found
            if (!$auth_hard_fail) {
                $exists = false;

                $q3 = "SELECT 1 FROM individual_table WHERE individual_username = ? LIMIT 1";
                if ($stmt = mysqli_prepare($db_connection, $q3)) {
                    mysqli_stmt_bind_param($stmt, "s", $username);
                    mysqli_stmt_execute($stmt);
                    $r = mysqli_stmt_get_result($stmt);
                    if (mysqli_fetch_row($r)) $exists = true;
                    mysqli_stmt_close($stmt);
                }

                if (!$exists) {
                    $error_message     = "Account not found. Please check your username or sign up.";
                    $auth_hard_fail    = true;
                    $unknown_user_flag = true;
                }
            }

            // Record throttle fail if needed
            if ($auth_hard_fail || (!empty($error_message) && !$login_success && !$need_verification)) {
                $th = throttle_record_fail($tkey);
                $lock_remaining = $th['remaining'];
                if ($th['locked']) {
                    $error_message = "Too many attempts. Try again in 3:00 minutes.";
                } else {
                    if ($wrong_pass_flag || !$unknown_user_flag) {
                        $error_message .= " You have {$th['left']} attempt(s) left.";
                    }
                }
            }

            /* ---------------------------------------------
            ORIGINAL AUTH FLOW (INDIVIDUAL LOGIN)
            --------------------------------------------- */
            if (!$auth_hard_fail && !$error_message && !$login_success) {
                $sql = "SELECT *
                        FROM individual_table
                        WHERE individual_username = ?
                        LIMIT 1";
                if ($stmt = mysqli_prepare($db_connection, $sql)) {
                    mysqli_stmt_bind_param($stmt, "s", $username);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    if ($row = mysqli_fetch_assoc($result)) {
                        if ($password === $row['individual_password']) {
                            $acctStatus = isset($row['account_status']) ? trim($row['account_status']) : '';
                            if (strcasecmp($acctStatus, 'Active') === 0) {
                                session_regenerate_id(true);

                                $_SESSION['individual_id']          = $row['individual_id'];
                                $_SESSION['individual_username']    = $row['individual_username'];
                                $_SESSION['individual_profile_img'] = isset($row['individual_profile_img']) && $row['individual_profile_img']
                                    ? $row['individual_profile_img']
                                    : 'image/default-profile.png';
                                $_SESSION['role'] = 'individual';
                                $_SESSION['just_logged_in'] = true;

                                /* =============================================
                                 * NEW: Check if login came from a ministry JOIN
                                 * (e.g. next=ministry-form.php?ministry=Handmaid's+of+the+Lord)
                                 * If user is already an Active member of that ministry,
                                 * DO NOT redirect to the form; instead, show a SweetAlert.
                                 * ============================================= */
                                $already_ministry_member = false;
                                $ministry_join_context   = null;

                                $rawNext = $_GET['next'] ?? '';
                                if ($rawNext !== '') {
                                    $parts = parse_url($rawNext);
                                    $path  = $parts['path'] ?? '';
                                    if ($path && stripos($path, 'ministry-form.php') !== false) {
                                        $query = $parts['query'] ?? '';
                                        $qParams = [];
                                        if ($query !== '') {
                                            parse_str($query, $qParams);
                                        }
                                        if (!empty($qParams['ministry'])) {
                                            $ministry_join_context = $qParams['ministry']; // e.g. "Handmaid's of the Lord"
                                            $ministrySafe = $ministry_join_context;
                                            $indId = (int)$row['individual_id'];

                                            $sqlM = "SELECT 1
                                                     FROM ministries_table
                                                     WHERE individual_id = ?
                                                       AND ministry_type = ?
                                                       AND archive_status = 'Active'
                                                     LIMIT 1";
                                            if ($stmtM = mysqli_prepare($db_connection, $sqlM)) {
                                                mysqli_stmt_bind_param($stmtM, "is", $indId, $ministrySafe);
                                                mysqli_stmt_execute($stmtM);
                                                $resM = mysqli_stmt_get_result($stmtM);
                                                if (mysqli_fetch_row($resM)) {
                                                    $already_ministry_member = true;
                                                }
                                                mysqli_stmt_close($stmtM);
                                            }
                                        }
                                    }
                                }

                                $login_success = true;
                                if ($already_ministry_member) {
                                    // Already part of the ministry → do NOT go to ministry-form.php.
                                    // Redirect to homepage (or change to any page you want).
                                    $redirect_url = "main-page.php";
                                } else {
                                    // Normal behavior: honor ?next= or fallback to main-page.php
                                    $redirect_url  = pick_redirect_url_or_default("main-page.php");
                                }

                            } else {
                                $builtName    = trim(($row['individual_firstname'] ?? '') . ' ' . ($row['individual_lastname'] ?? ''));
                                $displayName  = $builtName !== '' ? $builtName : ($row['individual_username'] ?? ($row['individual_email_address'] ?? ''));
                                $need_verification  = true;
                                $error_message      = "Please verify your account to proceed.";
                                $unverified_payload = [
                                    'individual_id' => (int)$row['individual_id'],
                                    'email'         => $row['individual_email_address'] ?? $row['individual_username'],
                                    'name'          => $displayName
                                ];
                            }
                        }
                    }
                    mysqli_stmt_close($stmt);
                }
            }

            if ($login_success) {
                throttle_clear($tkey);
                $lock_remaining = 0;
            }

            if (!$login_success && !$need_verification && !$error_message) {
                $th = throttle_record_fail($tkey);
                $lock_remaining = $th['remaining'];
                $msg = "Incorrect username or password.";
                if ($th['locked']) {
                    $msg = "Too many attempts. Try again in 3:00 minutes.";
                } else {
                    $msg .= " You have {$th['left']} attempt(s) left.";
                }
                $error_message = $msg;
            }
        }
    }
}

/* =========================================================
SUSPENDED HANDLER (Individuals)
========================================================= */
try {
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && (!isset($_POST['__action']) || $_POST['__action'] !== 'resend_verification')
        && isset($_POST['username'], $_POST['password'])
        && is_string($_POST['username']) && is_string($_POST['password'])
    ) {
        $u = trim((string)$_POST['username']);
        $p = (string)$_POST['password'];

        if ($u !== '' && $p !== '') {
            if ($stmt = mysqli_prepare($db_connection, "SELECT individual_id, individual_username, individual_email_address, individual_firstname, individual_lastname, individual_password, account_status FROM individual_table WHERE individual_username = ? LIMIT 1")) {
                mysqli_stmt_bind_param($stmt, "s", $u);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                if ($row = mysqli_fetch_assoc($res)) {
                    // Only proceed if password is correct
                    if ((string)$row['individual_password'] === $p) {
                        $status = trim((string)($row['account_status'] ?? ''));
                        if (strcasecmp($status, 'Suspended') === 0) {
                            $is_suspended = true;
                            $name = trim(($row['individual_firstname'] ?? '').' '.($row['individual_lastname'] ?? ''));
                            $suspended_payload = [
                                'individual_id' => (int)$row['individual_id'],
                                'username'      => (string)$row['individual_username'],
                                'email'         => (string)($row['individual_email_address'] ?? ''),
                                'name'          => $name !== '' ? $name : (string)$row['individual_username']
                            ];
                            // Suppress the generic Unverified modal/message in this specific case
                            $need_verification = false;
                            $error_message = null;
                        }
                    }
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
} catch (Throwable $e) {
    // Silent by design
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HTCCC Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/HTCCC-SYSTEM/css/all_log-in.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/all_log-in.css'); ?>">
    <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    html { overflow-y: scroll; }
    .lock-fixed { font-variant-numeric: tabular-nums; }
    #lock-count { display:inline-block; width: 5ch; text-align:center; }
    #lockout-banner { display:none; color:red; font-size:14px; margin-bottom:10px; }

    /* Suspended modal minor styles */
    .appeal-hint { font-size: 12px; color:#555; margin-top:8px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="left-section">
            <img src="image/main-logo.png" alt="HTCCC Logo">
        </div>
        <div class="right-section">
            <h2>LOGIN</h2>

            <?php if (!empty($error_message) && !$need_verification): ?>
                <div style="color: red; font-size: 14px; margin-bottom: 10px;">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <div id="lockout-banner" class="lock-fixed"></div>

            <form method="POST" action="">
                <div class="input-box">
                    <input type="text" name="username" placeholder="Username"
                        value="<?php echo htmlspecialchars($old_username, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="input-box">
                    <input type="password" name="password" placeholder="Password" value="" required>
                </div>
                <button type="submit" class="login-btn">Log in</button>
            </form>

            <div class="links">
                <a href="individual-verify.php">Forgot Password?</a>
                <a href="individual_sign-up.php">Sign Up</a><br><br>
                <a href="main-page.php" style="text-decoration: underline;">Back to homepage</a>
            </div>
        </div>
    </div>

    <script>
    (function () {
        let savedScrollY = 0;
        function lockScroll() {
        savedScrollY = window.scrollY || document.documentElement.scrollTop || 0;
        document.body.style.top = `-${savedScrollY}px`;
        document.body.classList.add('modal-open');
        }
        function unlockScroll() {
        document.body.classList.remove('modal-open');
        document.body.style.top = '';
        window.scrollTo(0, savedScrollY);
        }
        window.showAlert = function (opts) {
        lockScroll();
        const defaults = {
            scrollbarPadding: false,
            heightAuto: false,
            returnFocus: false,
            didClose: unlockScroll,
            willClose: unlockScroll
        };
        return Swal.fire(Object.assign({}, defaults, opts)).then((res) => {
            unlockScroll();
            return res;
        });
        };
    })();
    </script>

    <!-- Make ministry-membership context available to JS -->
    <script>
      const ALREADY_MINISTRY_MEMBER = <?php echo $already_ministry_member ? 'true' : 'false'; ?>;
      const MINISTRY_JOIN_LABEL     = <?php echo json_encode($ministry_join_context ?? ''); ?>;
      const REDIRECT_URL            = <?php echo json_encode($redirect_url); ?>;
    </script>

    <?php if (!empty($error_message) && !$need_verification): ?>
    <script>
    showAlert({
        icon: 'error',
        title: 'Login failed',
        text: <?php echo json_encode($error_message); ?>,
        confirmButtonText: 'OK'
    });
    </script>
    <?php endif; ?>

    <?php if ($login_success && $redirect_url): ?>
    <script>
    // If user logged in from a ministry JOIN and is already a member,
    // block direct access to the form and show info instead.
    if (ALREADY_MINISTRY_MEMBER && MINISTRY_JOIN_LABEL) {
        showAlert({
            icon: 'info',
            title: 'Already part of this ministry',
            text: 'Our records show that your account is already a member of the ' + MINISTRY_JOIN_LABEL + ' ministry.',
            confirmButtonText: 'OK'
        }).then(() => {
            window.location.href = REDIRECT_URL || 'main-page.php';
        });
    } else {
        showAlert({
            icon: 'success',
            title: 'Login successful!',
            text: 'Redirecting you…',
            timer: 1200,
            showConfirmButton: false
        }).then(() => {
            window.location.href = REDIRECT_URL || 'main-page.php';
        });
    }
    </script>
    <?php endif; ?>

    <?php if ($need_verification && is_array($unverified_payload)): ?>
    <script>
    // SweetAlert for Unverified account with "Resend verification"
    (function(){
        const payload = <?php echo json_encode($unverified_payload); ?>;
        const POST_URL = window.location.origin + window.location.pathname;

        showAlert({
            icon: 'warning',
            title: 'Please verify your account',
            html: `
                <div style="font-size:14px;line-height:1.5;text-align:left">
                Your account is <b>Unverified</b>. Please check your email (<b>${payload.email}</b>) for the verification link.
                <br><br>
                <i>Didn’t get the email?</i> You can resend a new verification email now.
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Resend verification',
            cancelButtonText: 'Close'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch(POST_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams({
                        '__action': 'resend_verification',
                        'individual_id': String(payload.individual_id)
                    })
                })
                .then(async (r) => {
                    const ct = r.headers.get('content-type') || '';
                    let data = null, text = '';
                    try {
                        if (ct.includes('application/json')) data = await r.json();
                        else text = await r.text();
                    } catch (_) {}
                    if (!r.ok) throw new Error((data && data.message) || text || ('HTTP '+r.status));
                    return data || { ok:false, message: text || 'Unexpected response' };
                })
                .then((data) => {
                    const dbg = data && data.debug ? `\n\nDetails:\n${data.debug}` : '';
                    if (data && data.ok) {
                        showAlert({
                            icon: 'success',
                            title: 'Verification sent',
                            text: (data.message || 'Please check your inbox.') + dbg,
                            confirmButtonText: 'OK'
                        });
                    } else {
                        const msg = (data && (data.mailer || data.message)) ? (data.mailer || data.message) : 'Please try again later.';
                        showAlert({
                            icon: 'error',
                            title: 'Could not send',
                            text: msg + dbg,
                            confirmButtonText: 'OK'
                        });
                    }
                })
                .catch((err) => {
                    showAlert({
                        icon: 'error',
                        title: 'Network error',
                        text: (err && err.message) ? err.message : 'Please try again.',
                        confirmButtonText: 'OK'
                    });
                });
            }
        });
    })();
    </script>
    <?php endif; ?>

    <!-- SUSPENDED MODAL + APPEAL FUNCTION -->
    <?php if ($is_suspended && is_array($suspended_payload)): ?>
    <script>
    (function(){
        const payload = <?php echo json_encode($suspended_payload, JSON_UNESCAPED_UNICODE); ?>;
        const SUPPORT_EMAIL = "holytrinitychristiancommunityc@gmail.com";

        function launchAppeal(to, subject, body) {
            const gmailUrl = "https://mail.google.com/mail/?view=cm&fs=1"
                + "&to="   + encodeURIComponent(to)
                + "&su="   + encodeURIComponent(subject)
                + "&body=" + encodeURIComponent(body);

            // Try Gmail web compose first, then fallback to mailto (apps).
            try {
                window.location.href = gmailUrl;
                setTimeout(function(){
                    window.location.href = "mailto:" + encodeURIComponent(to)
                        + "?subject=" + encodeURIComponent(subject)
                        + "&body="    + encodeURIComponent(body);
                }, 600);
            } catch (e) {
                window.location.href = "mailto:" + encodeURIComponent(to)
                    + "?subject=" + encodeURIComponent(subject)
                    + "&body="    + encodeURIComponent(body);
            }
        }

        const subject = "Account Suspension Appeal";
        const body = [
            "Hello HTCCC Team,",
            "",
            "I would like to appeal my account suspension.",
            "Name: "     + (payload.name || ""),
            "Username: " + (payload.username || ""),
            "Email: "    + (payload.email || ""),
            "User ID: "  + (payload.individual_id || ""),
            "",
            "Please advise on the next steps.",
            "",
            "Thank you."
        ].join("\n");

        showAlert({
            icon: 'info',
            title: 'Account Suspended',
            html: `
                <div style="font-size:14px;line-height:1.5;text-align:left">
                    Your account is currently <b>Suspended</b>.<br>
                    You can send us an appeal via email.
                    <div class="appeal-hint">We’ll open Gmail (or your email app) with the details prefilled.</div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Appeal',
            cancelButtonText: 'Cancel'
        }).then((res) => {
            if (res.isConfirmed) {
                launchAppeal(SUPPORT_EMAIL, subject, body);
            }
        });
    })();
    </script>
    <?php endif; ?>

    <!-- MAKE SUSPENSION PAYLOAD GLOBAL -->
    <?php if ($is_suspended && is_array($suspended_payload)): ?>
    <script>
        // Expose to later helpers so we can build the email body outside the IIFE.
        window.__susp_payload = <?php echo json_encode($suspended_payload, JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <?php endif; ?>

    <!-- OPEN ONE GMAIL TAB (INTERCEPT & PREVENT DOUBLE OPEN) -->
    <script>
    (function(){
        // Open exactly one Gmail tab and keep login page intact.
        // We intercept the SweetAlert confirm click in CAPTURE phase to stop the original handler.
        var opened = false;

        function buildAppealBody(p){
            p = p || {};
            return [
                "Hello HTCCC Team,",
                "",
                "I would like to appeal my account suspension.",
                "Name: "     + (p.name || ""),
                "Username: " + (p.username || ""),
                "Email: "    + (p.email || ""),
                "User ID: "  + (p.individual_id || ""),
                "",
                "Please advise on the next steps.",
                "",
                "Thank you."
            ].join("\n");
        }

        function openGmailOnce(){
            if (opened) return;
            opened = true;

            var p = window.__susp_payload || {};
            var url = "https://mail.google.com/mail/?view=cm&fs=1&tf=1"
                + "&to="   + encodeURIComponent("holytrinitychristiancommunityc@gmail.com")
                + "&su="   + encodeURIComponent("Appeal for Suspension Account")
                + "&body=" + encodeURIComponent(buildAppealBody(p));

            var w = window.open(url, "_blank", "noopener");
            if (!w || w.closed) { window.location.href = url; } // fallback if popup blocked
            if (window.Swal && Swal.close) { try { Swal.close(); } catch(_){} }
        }

        document.addEventListener("click", function(ev){
            var el = ev.target;
            if (!el || !el.classList || !el.classList.contains("swal2-confirm")) return;

            // Make sure it's the Account Suspended dialog.
            var titleEl = document.querySelector(".swal2-title");
            var titleTxt = titleEl ? (titleEl.textContent || "").trim().toLowerCase() : "";
            if (titleTxt === "account suspended") {
                ev.preventDefault();
                ev.stopImmediatePropagation(); // stop original .then(...) from running
                openGmailOnce();
            }
        }, true); // capture = true
    })();
    </script>

    <!-- Realtime lockout countdown -->
    <script>
    (function () {
        const lockRemaining = <?php echo (int)$lock_remaining; ?>; // seconds from PHP
        if (!lockRemaining) return;

        const submitBtn = document.querySelector('.login-btn');
        const pwdInput  = document.querySelector('input[name="password"]');
        const rs        = document.querySelector('.right-section');
        const form      = rs ? rs.querySelector('form') : null;

        function fmt(sec) {
            const m = Math.floor(sec / 60);
            const s = sec % 60;
            return String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        }

        let errEl = null;
        if (form && form.previousElementSibling && form.previousElementSibling.tagName === 'DIV'
            && form.previousElementSibling.id !== 'lockout-banner') {
            errEl = form.previousElementSibling;
        }
        if (!errEl) {
            errEl = document.getElementById('lockout-banner');
            if (errEl) { errEl.style.display = 'block'; }
        }
        if (!errEl && rs) {
            const div = document.createElement('div');
            div.id = 'lockout-banner';
            div.className = 'lock-fixed';
            div.style.cssText = 'color:red;font-size:14px;margin-bottom:10px;';
            rs.insertBefore(div, form || rs.firstChild);
            errEl = div;
        }
        if (!errEl) return;

        errEl.classList.add('lock-fixed');
        if (!document.getElementById('lock-count')) {
            errEl.innerHTML = 'Too many attempts. Try again in <span id="lock-count">--:--</span> minutes.';
        }
        const span = document.getElementById('lock-count');

        let secs = lockRemaining;
        if (submitBtn) submitBtn.disabled = true;
        if (pwdInput)  { pwdInput.value = ''; pwdInput.disabled = true; }

        (function tick(){
            if (secs <= 0) {
                span.textContent = '00:00';
                if (submitBtn) { submitBtn.disabled = false; }
                if (pwdInput)  { pwdInput.disabled  = false; }
                return;
            }
            span.textContent = fmt(secs);
            secs -= 1;
            setTimeout(tick, 1000);
        })();
    })();
    </script>
</body>
</html>
