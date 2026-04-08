<?php
/* =========================================================
HTCCC Login (Pastor + Admin only)
- Keeps your original logic (throttle, PHPMailer scaffolding, safe redirects)
- Admin audit hooks remain
- All individual_table (user) logic removed
========================================================= */

if (session_status() === PHP_SESSION_NONE) session_start();

// ADD BELOW THIS LINE: SESSION START
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// ADD BELOW THIS LINE: SESSION START

// ===== ADD BELOW THIS LINE — HONOR return_to FOR SAFE REDIRECTS =====
// Your picker reads $_GET['next'], while some pages send ?return_to=...
// This tiny bridge makes pick_redirect_url_or_default(...) respect it.
if (isset($_GET['return_to']) && !isset($_GET['next'])) {
    $_GET['next'] = $_GET['return_to'];
}
// ===== END ADD =====

$error_message = null;
$login_success = false;  // to trigger success SweetAlert
$redirect_url  = null;   // where to go after success

// ---------- DB ----------
require_once 'db-connection.php'; // must set $db_connection (mysqli)

/* =========================================================
ADD BELOW THIS LINE: EARLY AJAX HANDLER (force password reset)
- Runs before any HTML is sent, so fetch() receives clean JSON
- Uses inline JSON response (no dependency on later helpers)
========================================================= */
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' &&
    (isset($_POST['__action']) && $_POST['__action'] === 'admin_force_pw_reset')
) {
    // Only allow for logged-in admin (session created by successful login)
    if (empty($_SESSION['admin_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Not authorized']);
        exit;
    }

    $aid     = (int)$_SESSION['admin_id'];
    $newPass = trim((string)($_POST['new_password'] ?? ''));
    $q       = trim((string)($_POST['security_question'] ?? ''));
    $ans     = trim((string)($_POST['security_answer'] ?? ''));

    if ($newPass === '' || $q === '' || $ans === '') {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'All fields are required.']);
        exit;
    }
    if (strlen($newPass) < 4) {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Password must be at least 4 characters.']);
        exit;
    }

    if ($stmt = $db_connection->prepare("
        UPDATE admin_table
        SET admin_password = ?, security_question = ?, security_answer = ?, new_password = 'No'
        WHERE admin_id = ?
        LIMIT 1
    ")) {
        $stmt->bind_param("sssi", $newPass, $q, $ans, $aid);
        $ok = $stmt->execute();
        $stmt->close();

        header('Content-Type: application/json; charset=utf-8');
        if ($ok) {
            echo json_encode(['ok' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Database update failed.']);
        }
        exit; // IMPORTANT: short-circuit page rendering
    } else {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Database error.']);
        exit;
    }
}
/* =========================================================
END: EARLY AJAX HANDLER
========================================================= */

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

// ADD BELOW THIS LINE: DB CONNECT (PDO)
/**
 * Independent PDO connection (non-intrusive to your existing mysqli $db_connection).
 * Replace placeholders with real credentials (or wire to your actual DB constants).
 */
if (!defined('DB_HOST'))    define('DB_HOST', 'localhost');
if (!defined('DB_NAME'))    define('DB_NAME', 'your_database_name');
if (!defined('DB_USER'))    define('DB_USER', 'your_database_user');
if (!defined('DB_PASS'))    define('DB_PASS', 'your_database_password');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

if (!isset($pdo) || !($pdo instanceof PDO)) {
    try {
        $pdo = new PDO(
            "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (Throwable $e) {
        // Silent failure to avoid UI impact
        $pdo = null;
    }
}
// ADD BELOW THIS LINE: DB CONNECT (PDO)

// ADD BELOW THIS LINE: AUDIT HELPER
if (!function_exists('uuidv4')) {
    function uuidv4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('client_ip')) {
    function client_ip(): string {
        $keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($keys as $k) {
            if (!empty($_SERVER[$k])) {
                $val = trim((string)$_SERVER[$k]);
                if ($k === 'HTTP_X_FORWARDED_FOR') {
                    $parts = array_map('trim', explode(',', $val));
                    $val = $parts[0] ?? $val;
                }
                return substr($val, 0, 45);
            }
        }
        return '';
    }
}

if (!function_exists('audit_log')) {
    function audit_log(?PDO $pdo, array $params): void {
        if (!$pdo) return;

        $uuid        = uuidv4();
        $actorId     = isset($params['actor_admin_id']) ? (is_numeric($params['actor_admin_id']) ? (int)$params['actor_admin_id'] : null) : null;
        $actorUser   = $params['actor_username']   ?? null;
        $actorEmail  = $params['actor_email']      ?? null;
        $action      = $params['action']           ?? 'LOGIN';
        $sourceTable = $params['source_table']     ?? 'admin_table';
        $recordPk    = $params['record_pk']        ?? null;
        $formName    = $params['form_name']        ?? 'all_log-in.php';
        $ip          = client_ip();
        $ua          = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $notes       = $params['notes']            ?? null;

        try {
            $sql = "INSERT INTO audit_trail (
                        txn_id, actor_admin_id, actor_username, actor_email,
                        action, source_table, record_pk, form_name,
                        ip_address, user_agent, notes, details_before, details_after
                    ) VALUES (
                        :txn_id, :actor_admin_id, :actor_username, :actor_email,
                        :action, :source_table, :record_pk, :form_name,
                        :ip_address, :user_agent, :notes, NULL, NULL
                    )";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':txn_id'         => $uuid,
                ':actor_admin_id' => $actorId,
                ':actor_username' => $actorUser,
                ':actor_email'    => $actorEmail,
                ':action'         => $action,
                ':source_table'   => $sourceTable,
                ':record_pk'      => $recordPk,
                ':form_name'      => $formName,
                ':ip_address'     => $ip,
                ':user_agent'     => substr((string)$ua, 0, 255),
                ':notes'          => $notes
            ]);
        } catch (Throwable $e) {
            // Silent by design
        }
    }
}
// ADD BELOW THIS LINE: AUDIT HELPER

/* =========================================================
CONFIG / FLAGS
========================================================= */
$AJAX_DEBUG = true; // set to false in production

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
AUDIT HOOKS
- Push session admin details into MySQL session variables
- So triggers can read @actor_admin_id/@actor_username/@actor_email
--------------------------------------------------------- */
function set_db_actor_from_session(mysqli $db) {
    // default reset (also creates a txn UUID for the request)
    $db->query("SET @actor_admin_id = NULL, @actor_username = NULL, @actor_email = NULL, @ip_address = NULL, @user_agent = NULL, @txn_id = UUID()");

    if (!empty($_SESSION['role']) && $_SESSION['role'] === 'admin' && !empty($_SESSION['admin_id'])) {
        $id  = (int)$_SESSION['admin_id'];
        $usr = $db->real_escape_string($_SESSION['admin_user'] ?? '');
        $em  = $db->real_escape_string($_SESSION['admin_email'] ?? '');

        $ip  = $db->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');
        $ua  = $db->real_escape_string($_SERVER['HTTP_USER_AGENT'] ?? '');

        $db->query("SET @actor_admin_id = {$id}");
        $db->query("SET @actor_username = '{$usr}'");
        $db->query("SET @actor_email    = '{$em}'");
        $db->query("SET @ip_address     = '{$ip}'");
        $db->query("SET @user_agent     = '{$ua}'");
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
MAIN AUTH FLOW + throttle (Pastor + Admin only)
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
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
            $auth_hard_fail = false;
            $wrong_pass_flag = false;
            $unknown_user_flag = false;

            // 1) Pastor pre-check
            if (!$auth_hard_fail) {
                $sql = "SELECT pastor_id, pastor_password
                        FROM pastor_account
                        WHERE pastor_username = ?
                        LIMIT 1";
                if ($stmt = mysqli_prepare($db_connection, $sql)) {
                    mysqli_stmt_bind_param($stmt, "s", $username);
                    mysqli_stmt_execute($stmt);
                    $res = mysqli_stmt_get_result($stmt);
                    if ($row = mysqli_fetch_assoc($res)) {
                        if ($password !== $row['pastor_password']) {
                            $error_message = "Incorrect password for this username.";
                            $auth_hard_fail = true;
                            $wrong_pass_flag = true;
                        }
                    }
                    mysqli_stmt_close($stmt);
                }
            }

            // 2) Admin pre-check
            if (!$auth_hard_fail) {
                $sql = "SELECT admin_id, admin_password
                        FROM admin_table
                        WHERE admin_username = ?
                        LIMIT 1";
                if ($stmt = mysqli_prepare($db_connection, $sql)) {
                    mysqli_stmt_bind_param($stmt, "s", $username);
                    mysqli_stmt_execute($stmt);
                    $res = mysqli_stmt_get_result($stmt);
                    if ($row = mysqli_fetch_assoc($res)) {
                        if ($password !== $row['admin_password']) {
                            $error_message = "Incorrect password for this username.";
                            $auth_hard_fail = true;
                            $wrong_pass_flag = true;
                        }
                    }
                    mysqli_stmt_close($stmt);
                }
            }

            // 3) If no user matched at all -> account not found
            if (!$auth_hard_fail) {
                $exists = false;

                $q1 = "SELECT 1 FROM pastor_account WHERE pastor_username = ? LIMIT 1";
                if ($stmt = mysqli_prepare($db_connection, $q1)) {
                    mysqli_stmt_bind_param($stmt, "s", $username);
                    mysqli_stmt_execute($stmt);
                    $r = mysqli_stmt_get_result($stmt);
                    if (mysqli_fetch_row($r)) $exists = true;
                    mysqli_stmt_close($stmt);
                }

                if (!$exists) {
                    $q2 = "SELECT 1 FROM admin_table WHERE admin_username = ? LIMIT 1";
                    if ($stmt = mysqli_prepare($db_connection, $q2)) {
                        mysqli_stmt_bind_param($stmt, "s", $username);
                        mysqli_stmt_execute($stmt);
                        $r = mysqli_stmt_get_result($stmt);
                        if (mysqli_fetch_row($r)) $exists = true;
                        mysqli_stmt_close($stmt);
                    }
                }

                if (!$exists) {
                    $error_message = "Account not found. Please check your username.";
                    $auth_hard_fail = true;
                    $unknown_user_flag = true;
                }
            }

            // Record throttle fail if needed
            if ($auth_hard_fail || (!empty($error_message) && !$login_success)) {
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
            ORIGINAL AUTH FLOW (actual login)
            --------------------------------------------- */

            // 1) Pastor login
            if (!$auth_hard_fail && !$error_message && !$login_success) {
                $sql = "SELECT pastor_id, pastor_username, pastor_password
                        FROM pastor_account
                        WHERE pastor_username = ?
                        LIMIT 1";
                if ($stmt = mysqli_prepare($db_connection, $sql)) {
                    mysqli_stmt_bind_param($stmt, "s", $username);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    if ($row = mysqli_fetch_assoc($result)) {
                        if ($password === $row['pastor_password']) {
                            session_regenerate_id(true);
                            $_SESSION['pastor_id'] = $row['pastor_id'];
                            $_SESSION['role'] = 'pastor';
                            $_SESSION['just_logged_in'] = true;

                            $login_success = true;
                            $redirect_url  = pick_redirect_url_or_default("admin-dashboard.php");
                        }
                    }
                    mysqli_stmt_close($stmt);
                }
            }

            // 2) Admin login (WE HOOK AUDIT HERE)
            if (!$auth_hard_fail && !$error_message && !$login_success) {
                $sql = "SELECT admin_id, admin_username, admin_password, admin_emailaddress
                        FROM admin_table
                        WHERE admin_username = ?
                        LIMIT 1";
                if ($stmt = mysqli_prepare($db_connection, $sql)) {
                    mysqli_stmt_bind_param($stmt, "s", $username);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    if ($row = mysqli_fetch_assoc($result)) {
                        if ($password === $row['admin_password']) {
                            session_regenerate_id(true);
                            $_SESSION['admin_id']    = $row['admin_id'];
                            $_SESSION['admin_user']  = $row['admin_username'];
                            $_SESSION['admin_email'] = $row['admin_emailaddress'];
                            $_SESSION['role']        = 'admin';
                            $_SESSION['just_logged_in'] = true;

                            // === NEW: set DB actor session vars for triggers ===
                            set_db_actor_from_session($db_connection);

                            // === NEW: log the LOGIN event into audit_log ===
                            if ($ins = $db_connection->prepare("
                                INSERT INTO audit_trail (
                                  event_time, txn_id, actor_admin_id, actor_username, actor_email,
                                  action, source_table, record_pk, form_name, ip_address, user_agent, notes
                                ) VALUES (NOW(), @txn_id, ?, ?, ?,
                                  'LOGIN', 'auth', ?, 'Login Form', @ip_address, @user_agent, 'Admin logged in')
                            ")) {
                                $adminId   = (int)$_SESSION['admin_id'];
                                $adminUser = $_SESSION['admin_user'];
                                $adminEmail= $_SESSION['admin_email'];
                                // FIX: 4 placeholders => 4 type specifiers ("issi")
                                $ins->bind_param("issi", $adminId, $adminUser, $adminEmail, $adminId);
                                $ins->execute();
                                $ins->close();
                            }

                            $login_success = true;
                            $redirect_url  = pick_redirect_url_or_default("secretary_dashboard.php");
                        }
                    }
                    mysqli_stmt_close($stmt);
                }
            }

            if ($login_success) {
                throttle_clear($tkey);
                $lock_remaining = 0;
            }

            if (!$login_success && !$error_message) {
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

// ADD BELOW THIS LINE: LOGIN HANDLER (Admin PDO fallback only)
/**
 * Add-only PDO-based admin login audit that runs AFTER the original flow.
 * It only executes if no success path was taken above and request is a normal POST.
 * On success: writes to audit_trail. (Does not change UI/session here.)
 */
try {
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && !$login_success
        && (empty($error_message) || !is_string($error_message))
        && isset($_POST['username'], $_POST['password'])
        && is_string($_POST['username']) && is_string($_POST['password'])
    ) {
        if (isset($pdo) && ($pdo instanceof PDO)) {
            $u = trim((string)$_POST['username']);
            $p = (string)$_POST['password'];

            if ($u !== '' && $p !== '') {
                $stmt = $pdo->prepare("
                    SELECT admin_id, admin_username, admin_password, admin_emailaddress
                    FROM admin_table
                    WHERE admin_username = :u
                    LIMIT 1
                ");
                $stmt->execute([':u' => $u]);
                $row = $stmt->fetch();

                $ok = false;
                if ($row) {
                    $stored = (string)$row['admin_password'];
                    if (preg_match('/^\$2[aby]\$|^\$argon2(id|i|d)\$/', $stored)) {
                        $ok = password_verify($p, $stored);
                    } else {
                        $ok = hash_equals($stored, $p);
                    }
                }

                if ($ok) {
                    // Record success audit (does not change UI/session here)
                    audit_log($pdo, [
                        'actor_admin_id' => (int)$row['admin_id'],
                        'actor_username' => (string)$row['admin_username'],
                        'actor_email'    => (string)($row['admin_emailaddress'] ?? ''),
                        'action'         => 'LOGIN',
                        'source_table'   => 'admin_table',
                        'record_pk'      => (string)$row['admin_id'],
                        'form_name'      => 'all_log-in.php',
                        'notes'          => 'Admin login success'
                    ]);
                } else {
                    $actorId = $row ? (int)$row['admin_id'] : null;
                    $actorEm = $row ? (string)($row['admin_emailaddress'] ?? null) : null;

                    audit_log($pdo, [
                        'actor_admin_id' => $actorId,
                        'actor_username' => $u,
                        'actor_email'    => $actorEm,
                        'action'         => 'LOGIN',
                        'source_table'   => 'admin_table',
                        'record_pk'      => $actorId ? (string)$actorId : null,
                        'form_name'      => 'all_log-in.php',
                        'notes'          => 'Admin login failed'
                    ]);
                }
            }
        }
    }
} catch (Throwable $e) {
    // Silent
}
// ADD BELOW THIS LINE: LOGIN HANDLER

// ========================================================
// ADD BELOW THIS LINE: FORCE RESET GATE (server-side flag)
// - If admin just logged in and admin_table.new_password='Yes',
//   suppress redirect and show the Force Password Reset modal.
// ========================================================
$FORCE_PW_RESET = false;
$FORCE_TARGET = "secretary_dashboard.php";
if ($login_success && isset($_SESSION['role']) && $_SESSION['role'] === 'admin' && !empty($_SESSION['admin_id'])) {
    if ($stmt = $db_connection->prepare("SELECT new_password FROM admin_table WHERE admin_id = ? LIMIT 1")) {
        $aid = (int)$_SESSION['admin_id'];
        $stmt->bind_param("i", $aid);
        if ($stmt->execute() && ($res = $stmt->get_result()) && ($row = $res->fetch_assoc())) {
            if (strcasecmp((string)$row['new_password'], 'Yes') === 0) {
                $FORCE_PW_RESET = true;
                // Suppress the default redirect block below by clearing $redirect_url
                $redirect_url = null;
            }
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HTCCC Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/HTCCC-SYSTEM/css/admin-login.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/admin-login.css'); ?>">
    <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    html { overflow-y: scroll; }
    .lock-fixed { font-variant-numeric: tabular-nums; }
    #lock-count { display:inline-block; width: 5ch; text-align:center; }
    #lockout-banner { display:none; color:red; font-size:14px; margin-bottom:10px; }

    /* ADD BELOW THIS LINE: FORGOT-PASSWORD MODAL (internal CSS only) */
    .fp-overlay {
        position: fixed; inset: 0; background: rgba(0,0,0,.5);
        display: none; align-items: center; justify-content: center; z-index: 9999;
    }
    .fp-overlay.open { display: flex; }
    .fp-card {
        width: min(92vw, 480px); background: #fff; border-radius: 16px;
        box-shadow: 0 20px 50px rgba(0,0,0,.18); padding: 20px 20px 16px;
        font-family: Poppins, Arial, sans-serif;
    }
    .fp-header { display:flex; align-items:center; justify-content:space-between; margin-bottom: 8px; }
    .fp-title { font-size: 18px; font-weight: 600; margin: 0; }
    .fp-close {
        appearance: none; border: none; background: transparent; font-size: 20px; line-height: 1;
        cursor: pointer; padding: 6px; border-radius: 8px;
    }
    .fp-body { margin-top: 8px; }
    .fp-row { margin: 10px 0; }
    .fp-label { display:block; font-size: 13px; color:#374151; margin-bottom:6px; }
    .fp-input, .fp-select {
        width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 10px; outline: none;
    }
    .fp-input:focus, .fp-select:focus { border-color: #2563eb; }
    .fp-actions { display:flex; gap: 8px; justify-content: flex-end; margin-top: 14px; }
    .btn {
        padding: 10px 14px; border: none; border-radius: 10px; cursor: pointer; font-weight: 600;
    }
    .btn-secondary { background: #e5e7eb; }
    .btn-primary { background: #2563eb; color: #fff; }
    .fp-note { font-size: 12px; color:#6b7280; margin-top: 4px; }
    /* ADD ABOVE THIS LINE: FORGOT-PASSWORD MODAL (internal CSS only) */

    /* ADD BELOW THIS LINE: FORCE RESET MODAL (internal CSS only) */
    .pr-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.55); display:none; align-items:center; justify-content:center; z-index:10000; }
    .pr-overlay.open { display:flex; }
    .pr-card { width:min(92vw,520px); background:#fff; border-radius:18px; box-shadow:0 20px 55px rgba(0,0,0,.22); padding:22px; font-family:Poppins, Arial, sans-serif; }
    .pr-title { margin:0 0 6px; font-size:20px; font-weight:700; }
    .pr-sub { margin:0 0 12px; color:#4b5563; font-size:13px; }
    .pr-row { margin:10px 0; }
    .pr-label { display:block; font-size:13px; color:#374151; margin-bottom:6px; }
    .pr-input, .pr-select { width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:10px; outline:none; }
    .pr-input:focus, .pr-select:focus { border-color:#2563eb; }
    .pr-actions { display:flex; gap:8px; justify-content:flex-end; margin-top:14px; }
    .pr-note { font-size:12px; color:#6b7280; margin-top:4px; }
    /* ADD ABOVE THIS LINE: FORCE RESET MODAL (internal CSS only) */
    </style>
</head>
<body>
    <div class="container">
        <div class="left-section">
            <img src="image/main-logo.png" alt="HTCCC Logo">
        </div>
        <div class="right-section">
            <h2>HTCCC Personnel Sign-In</h2>

            <?php if (!empty($error_message)): ?>
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

            <div class="links" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:8px;">
                <!-- ADD BELOW THIS LINE: FORGOT PASSWORD LINK (opens modal) -->
                <a href="#" id="forgot-password-link" style="text-decoration: underline; margin-left: 110px; margin-top: 30px;">Forgot Password?</a>
                <!-- ADD ABOVE THIS LINE: FORGOT PASSWORD LINK (opens modal) -->
            </div>
        </div>
    </div>

    <!-- ADD BELOW THIS LINE: FORGOT-PASSWORD MODAL (NO BACKEND; SECURITY QUESTION ONLY) -->
    <div class="fp-overlay" id="fp-modal" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="fp-card" role="document">
            <div class="fp-header">
                <h3 class="fp-title">Reset Password</h3>
                <button type="button" class="fp-close" id="fp-close" aria-label="Close">&times;</button>
            </div>
            <div class="fp-body">
                <form id="fp-form">
                    <div class="fp-row">
                        <label class="fp-label" for="fp-username">Username</label>
                        <input class="fp-input" id="fp-username" name="username" type="text" required>
                    </div>
                    <div class="fp-row">
                        <label class="fp-label" for="fp-question">Security Question</label>
                        <select class="fp-select" id="fp-question" name="security_question" required>
                            <option value="">— Select a question —</option>
                            <option value="birthplace">What city were you born in?</option>
                            <option value="pet">What was the name of your first pet?</option>
                            <option value="mother_maiden">What is your mother’s maiden name?</option>
                        </select>
                        <div class="fp-note">We’ll use this to confirm your identity.</div>
                    </div>
                    <div class="fp-row">
                        <label class="fp-label" for="fp-answer">Your Answer</label>
                        <input class="fp-input" id="fp-answer" name="answer" type="text" required>
                    </div>
                    <div class="fp-actions">
                        <button type="button" class="btn btn-secondary" id="fp-cancel">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="fp-submit">Continue</button>
                    </div>
            </div>
        </div>
    </div>
    <!-- ADD ABOVE THIS LINE: FORGOT-PASSWORD MODAL (NO BACKEND; SECURITY QUESTION ONLY) -->

    <!-- ADD BELOW THIS LINE: FORCE PASSWORD RESET MODAL (BACKED BY AJAX) -->
    <div class="pr-overlay" id="pr-modal" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="pr-card" role="document">
            <h3 class="pr-title">Set a New Password</h3>
            <p class="pr-sub">Before continuing, please set a new password and a security question.</p>
            <form id="pr-form" autocomplete="off">
                <div class="pr-row">
                    <label class="pr-label" for="pr-new">New Password</label>
                    <input id="pr-new" class="pr-input" type="password" required minlength="4" autocomplete="new-password">
                </div>
                <div class="pr-row">
                    <label class="pr-label" for="pr-confirm">Confirm Password</label>
                    <input id="pr-confirm" class="pr-input" type="password" required minlength="4" autocomplete="new-password">
                </div>
                <div class="pr-row">
                    <label class="pr-label" for="pr-question">Security Question</label>
                    <select id="pr-question" class="pr-select" required>
                        <option value="">— Select a question —</option>
                        <option value="birthplace">What city were you born in?</option>
                        <option value="pet">What was the name of your first pet?</option>
                        <option value="mother_maiden">What is your mother’s maiden name?</option>
                    </select>
                </div>
                <div class="pr-row">
                    <label class="pr-label" for="pr-answer">Your Answer</label>
                    <input id="pr-answer" class="pr-input" type="text" required>
                </div>
                <div class="pr-actions">
                    <button type="submit" class="btn btn-primary">Save & Continue</button>
                </div>
                <div class="pr-note">After saving, you’ll be redirected to your dashboard.</div>
            </form>
        </div>
    </div>
    <!-- ADD ABOVE THIS LINE: FORCE PASSWORD RESET MODAL (BACKED BY AJAX) -->

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

    <?php if (!empty($error_message)): ?>
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
    showAlert({
        icon: 'success',
        title: 'Login successful!',
        text: 'Redirecting you…',
        timer: 1200,
        showConfirmButton: false
    }).then(() => {
        window.location.href = <?php echo json_encode($redirect_url); ?>;
    });
    </script>
    <?php endif; ?>

    <!-- ADD BELOW THIS LINE: FORGOT-PASSWORD MODAL JS (OPEN/CLOSE/SUBMIT — FRONTEND ONLY) -->
    <script>
    (function(){
        var link   = document.getElementById('forgot-password-link');
        var modal  = document.getElementById('fp-modal');
        var closeB = document.getElementById('fp-close');
        var cancel = document.getElementById('fp-cancel');
        var form   = document.getElementById('fp-form');

        function openModal(e){
            if (e) e.preventDefault();
            if (!modal) return;
            modal.classList.add('open');
            modal.setAttribute('aria-hidden','false');
            // focus first field
            var u = document.getElementById('fp-username');
            if (u) setTimeout(function(){ try{ u.focus(); }catch(_){}} , 50);
        }
        function closeModal(){
            if (!modal) return;
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden','true');
        }

        if (link)   link.addEventListener('click', openModal);
        if (closeB) closeB.addEventListener('click', closeModal);
        if (cancel) cancel.addEventListener('click', closeModal);
        if (modal)  modal.addEventListener('click', function(ev){
            if (ev.target === modal) closeModal();
        });

        if (form) {
            form.addEventListener('submit', function(ev){
                ev.preventDefault();
                var username = (document.getElementById('fp-username')||{}).value || '';
                var q        = (document.getElementById('fp-question')||{}).value || '';
                var ans      = (document.getElementById('fp-answer')||{}).value || '';

                if (!username || !q || !ans) {
                    Swal.fire({icon:'warning', title:'Incomplete', text:'Please complete all fields.'});
                    return;
                }

                // No backend yet (per request). Just show a confirmation.
                closeModal();
                Swal.fire({
                    icon:'info',
                    title:'Security check submitted',
                    html: 'We will verify your answer for <b>' + (username.replace(/[<>&]/g,'') || 'your account') + '</b>.' +
                          '<br>This will be connected to the actual reset flow later.',
                    confirmButtonText:'OK'
                });
            });
        }
    })();
    </script>
    <!-- ADD ABOVE THIS LINE: FORGOT-PASSWORD MODAL JS (OPEN/CLOSE/SUBMIT — FRONTEND ONLY) -->

    <!-- ADD BELOW THIS LINE: FORCE PASSWORD RESET — OPEN & AJAX SAVE -->
    <script>
    (function(){
        var mustReset = <?php echo $FORCE_PW_RESET ? 'true' : 'false'; ?>;
        var modal = document.getElementById('pr-modal');
        var form  = document.getElementById('pr-form');
        function openPR(){
            if (!modal) return;
            modal.classList.add('open');
            modal.setAttribute('aria-hidden','false');
            setTimeout(function(){ var el=document.getElementById('pr-new'); if(el) try{el.focus();}catch(_){}} , 60);
        }
        function closePR(){
            if (!modal) return;
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden','true');
        }

        if (mustReset) {
            // Friendly info then open modal
            showAlert({
                icon: 'info',
                title: 'Password update required',
                text: 'Please set a new password to continue.',
                confirmButtonText: 'Continue'
            }).then(openPR);
        }

        if (form) {
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var p1 = (document.getElementById('pr-new')||{}).value || '';
                var p2 = (document.getElementById('pr-confirm')||{}).value || '';
                var q  = (document.getElementById('pr-question')||{}).value || '';
                var a  = (document.getElementById('pr-answer')||{}).value || '';

                if (!p1 || !p2 || !q || !a) {
                    Swal.fire({icon:'warning', title:'Incomplete', text:'Please complete all fields.'});
                    return;
                }
                if (p1 !== p2) {
                    Swal.fire({icon:'error', title:'Mismatch', text:'Passwords do not match.'});
                    return;
                }
                if (p1.length < 4) {
                    Swal.fire({icon:'warning', title:'Too short', text:'Please use at least 4 characters.'});
                    return;
                }

                var fd = new FormData();
                fd.append('__action', 'admin_force_pw_reset');
                fd.append('new_password', p1);
                fd.append('security_question', q);
                fd.append('security_answer', a);

                fetch(window.location.href, { method:'POST', body: fd, credentials:'same-origin' })
                  .then(r => r.json())
                  .then(j => {
                      if (j && j.ok) {
                          closePR();
                          Swal.fire({
                              icon:'success',
                              title:'Updated',
                              text:'Your password and security question have been saved.',
                              timer:1200,
                              showConfirmButton:false
                          }).then(function(){
                              window.location.href = <?php echo json_encode($FORCE_TARGET); ?>;
                          });
                      } else {
                          var msg = (j && j.error) ? j.error : 'Unable to save. Please try again.';
                          Swal.fire({icon:'error', title:'Error', text: msg});
                      }
                  })
                  .catch(function(){
                      Swal.fire({icon:'error', title:'Network error', text:'Please try again.'});
                  });
            });
        }
    })();
    </script>
    <!-- ADD ABOVE THIS LINE: FORCE PASSWORD RESET — OPEN & AJAX SAVE -->

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
<?php
// ========================================================
// ADD BELOW THIS LINE: AJAX ENDPOINT — SAVE FORCED PW RESET
// (Kept for backward-compat; early handler above exits first.)
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['__action'] ?? '') === 'admin_force_pw_reset') {
    if (empty($_SESSION['admin_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
        json_reply(['ok'=>false, 'error'=>'Not authorized'], 403);
    }
    $aid = (int)$_SESSION['admin_id'];

    $newPass = trim((string)($_POST['new_password'] ?? ''));
    $q       = trim((string)($_POST['security_question'] ?? ''));
    $ans     = trim((string)($_POST['security_answer'] ?? ''));

    if ($newPass === '' || $q === '' || $ans === '') {
        json_reply(['ok'=>false, 'error'=>'All fields are required.'], 422);
    }
    if (strlen($newPass) < 4) {
        json_reply(['ok'=>false, 'error'=>'Password must be at least 4 characters.'], 422);
    }

    if ($stmt = $db_connection->prepare("
        UPDATE admin_table
        SET admin_password = ?, security_question = ?, security_answer = ?, new_password = 'No'
        WHERE admin_id = ?
        LIMIT 1
    ")) {
        $stmt->bind_param("sssi", $newPass, $q, $ans, $aid);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            json_reply(['ok'=>true]);
        } else {
            json_reply(['ok'=>false, 'error'=>'Database update failed.'], 500);
        }
    } else {
        json_reply(['ok'=>false, 'error'=>'Database error.'], 500);
    }
}
// ========================================================
// ADD ABOVE THIS LINE: AJAX ENDPOINT — SAVE FORCED PW RESET
