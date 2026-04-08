<?php
// ---------- SESSION ----------
if (session_status() === PHP_SESSION_NONE) session_start();

// ---------- DB ----------
include 'db-connection.php'; // should define $db_connection = new mysqli(...)

$success    = null;
$error      = null;
$errorField = null; // which input caused the last error (for SweetAlert focus)

/* ============================
   PHPMailer (manual, no Composer)
   Folder structure based on your screenshot:
   HTCCC-SYSTEM/PHPMailer/src/...
============================= */
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ============================
   NOTIFICATION LOGIC (copied & applied)
   Uses mysqli; validates session identity; inserts to notifications and notification_recipients
============================= */
/**
 * notifyAdmins() - mysqli version
 * Mirrors the baptism form notification logic, but uses mysqli instead of PDO.
 *  - Validates session identity (admin / individual / pastor)
 *  - Builds a normalized display name
 *  - Inserts into notifications
 *  - Inserts notification_recipients rows for all admins
 */
if (!function_exists('notifyAdmins')) {
  function notifyAdmins(mysqli $db, $formType, $formRecordId, $formSummary) {
    // Validate session identity
    if (!isset($_SESSION) || session_status() !== PHP_SESSION_ACTIVE) {
      return false;
    }
    $allowedUserTypes = ['admin', 'individual', 'pastor'];
    $createdByType = isset($_SESSION['user_type']) ? trim((string)$_SESSION['user_type']) : '';
    $createdById   = isset($_SESSION['user_id'])   ? (int)$_SESSION['user_id'] : 0;
    if (!in_array($createdByType, $allowedUserTypes, true) || $createdById <= 0) {
      return false;
    }

    // Validate args
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

    // Helper to compose a human name from a row
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

    // Fetch submitter's display name
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
      } catch (Throwable $e) { /* fall back */ }
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

      // Insert notification
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

      // Fetch admins
      $adminIds = [];
      $sqlAdmin = "SELECT admin_id FROM admin_table";
      if ($resAdm = $db->query($sqlAdmin)) {
        while ($row = $resAdm->fetch_assoc()) {
          $aid = (int)($row['admin_id'] ?? 0);
          if ($aid > 0) $adminIds[] = $aid;
        }
        $resAdm->free();
      }

      // Insert recipients
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

/* ============================
   Send verification email
============================= */
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
        // ======= SMTP CONFIG: EDIT THESE =======
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'holytrinitychristiancommunityc@gmail.com';      // <-- your SMTP email
        $mail->Password   = 'jngx vtqb urun yjur'; // <-- app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // or ENCRYPTION_SMTPS
        $mail->Port       = 587; // 465 if ENCRYPTION_SMTPS
        // =======================================

        // Use an address aligned with your SMTP for best deliverability
        $mail->setFrom('your_smtp_email@example.com', 'HTCCC Verification');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = 'Verify your HTCCC account';
        $mail->Body = '
            <div style="font-family:Arial,Helvetica,sans-serif;line-height:1.5">
              <h2>Verify your account</h2>
              <p>Hi '.htmlspecialchars($toName).',</p>
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

/* ============================
   Check that email has a real domain
============================= */
function hasRealEmailDomain($email) {
    $email = trim((string)$email);
    if ($email === '') return false;

    $atPos = strrpos($email, '@');
    if ($atPos === false) return false;

    $domain = substr($email, $atPos + 1);
    if ($domain === '') return false;

    // Simple domain pattern check
    if (!preg_match('/^[A-Z0-9.-]+\.[A-Z]{2,}$/i', $domain)) {
        return false;
    }

    // If DNS functions are available, validate MX/A
    if (function_exists('checkdnsrr')) {
        if (checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A')) {
            return true;
        }
        return false;
    }

    // Fallback: if DNS check unavailable, don't over-block
    return true;
}

// small helper to ensure folder exists
function ensure_dir($dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // ---------- collect ----------
    $lastname   = trim($_POST["lastname"]   ?? '');
    $firstname  = trim($_POST["firstname"]  ?? '');
    $middlename = trim($_POST["middlename"] ?? '');
    $birthday   = trim($_POST["birthday"]   ?? '');
    $gender     = trim($_POST["gender"]     ?? '');
    $username   = trim($_POST["username"]   ?? '');
    $password   = trim($_POST["password"]   ?? '');
    $confirm    = trim($_POST["confirm_password"] ?? '');
    $phone      = trim($_POST["phone"]      ?? '');
    $email      = trim($_POST["email"]      ?? '');
    $street     = trim($_POST["street"]     ?? '');
    $city       = trim($_POST["city"]       ?? '');
    $zipcode    = trim($_POST["zipcode"]    ?? '');

    // ============================
    // NEW SERVER-SIDE FIELDS (collect only)
    // ============================
    $civil_status        = trim($_POST['civil_status'] ?? '');
    $individual_baptised = trim($_POST['individual_baptised'] ?? '');

    // NEW: collect Baptised Church controls
    $baptised_church_select = trim($_POST['baptised_church_select'] ?? '');
    $baptised_church_other  = trim($_POST['baptised_church_other']  ?? '');
    $baptised_church_value  = null; // will be computed if needed
    // ============================

    // ---------- validate ----------
    if ($password !== $confirm) {
        $error = "Passwords do not match.";
        $errorField = 'confirm_password';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please provide a valid email address (e.g., name@example.com).";
        $errorField = 'email';
    } elseif (!hasRealEmailDomain($email)) {
        $error = "Please use an email address with a real domain (e.g., Gmail, Yahoo, Outlook).";
        $errorField = 'email';
    } elseif (!in_array($gender, ['Male','Female'], true)) {
        $error = "Please select a valid sex.";
        $errorField = 'gender';
    } elseif (!preg_match('/^\d{11}$/', $phone)) {
        // *** enforce exactly 11 digits on the server ***
        $error = "Phone number must be exactly 11 digits.";
        $errorField = 'phone';
    }

    // ---------- AGE VALIDATION: must be at least 12 ----------
    if (!$error) {
        if ($birthday === '') {
            $error = "Please provide your birthday.";
            $errorField = 'birthday';
        } else {
            try {
                $birthDate = new DateTime($birthday);
                $today     = new DateTime('today');

                // sanity check: birthday cannot be in the future
                if ($birthDate > $today) {
                    $error = "Birthday cannot be in the future.";
                    $errorField = 'birthday';
                } else {
                    $age = $birthDate->diff($today)->y;
                    if ($age < 12) {
                        $error = "You must be at least 12 years old to register.";
                        $errorField = 'birthday';
                    }
                }
            } catch (Exception $e) {
                $error = "Invalid birthday format.";
                $errorField = 'birthday';
            }
        }
    }

    // ---------- unique username/email ----------
    if (!$error) {
        $sqlCheck = "SELECT 1 FROM individual_table WHERE individual_username = ? OR individual_email_address = ? LIMIT 1";
        if ($stmt = $db_connection->prepare($sqlCheck)) {
            $stmt->bind_param("ss", $username, $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $error = "Username or email already exists.";
                $errorField = 'username';
            }
            $stmt->close();
        } else {
            $error = "Failed to prepare uniqueness check.";
            $errorField = 'username';
        }
    }

    // ============================
    // NEW VALIDATION FOR CIVIL STATUS & BAPTISED
    // Only run if no previous errors.
    // ============================
    if (!$error) {
        if (!in_array($civil_status, ['Single','Married','Widowed'], true)) {
            $error = "Please select a valid civil status.";
            $errorField = 'civil_status';
        } elseif (!in_array($individual_baptised, ['Yes','No'], true)) {
            $error = "Please indicate if you are baptised.";
            $errorField = 'individual_baptised';
        }
    }
    // ============================

    // ============================
    // VALIDATION FOR BAPTISED CHURCH (when baptised = Yes)
    // ============================
    if (!$error && $individual_baptised === 'Yes') {
        if ($baptised_church_select === 'Holy Trinity Christian Community Church') {
            $baptised_church_value = 'Holy Trinity Christian Community Church';
        } elseif ($baptised_church_select === 'Other') {
            if ($baptised_church_other === '') {
                $error = "Please enter your church name.";
                $errorField = 'baptised_church_other';
            } else {
                $baptised_church_value = $baptised_church_other;
            }
        } else {
            $error = "Please select where you were baptised.";
            $errorField = 'baptised_church_select';
        }
    }
    // ============================

    // uploads presence
    $validIdErr  = $_FILES['valid_id']['error']       ?? UPLOAD_ERR_NO_FILE;
    $selfieErr   = $_FILES['selfie_id']['error']      ?? UPLOAD_ERR_NO_FILE;
    $bapErr      = $_FILES['baptismal_cert']['error'] ?? UPLOAD_ERR_NO_FILE;

    // allowed types
    $allowedImg = ['image/jpeg','image/png','image/jpg'];
    $allowedDoc = array_merge($allowedImg, ['application/pdf']);

    if (!$error) {
        if ($validIdErr !== UPLOAD_ERR_NO_FILE && !in_array($_FILES['valid_id']['type'], $allowedDoc, true)) {
            $error = "Valid ID must be JPG/PNG/PDF.";
            $errorField = 'valid_id';
        } elseif ($selfieErr !== UPLOAD_ERR_NO_FILE && !in_array($_FILES['selfie_id']['type'], $allowedImg, true)) {
            $error = "Selfie with valid ID must be JPG/PNG.";
            $errorField = 'selfie_id';
        } elseif ($bapErr !== UPLOAD_ERR_NO_FILE && !in_array($_FILES['baptismal_cert']['type'], $allowedDoc, true)) {
            $error = "Baptismal Certificate must be JPG/PNG/PDF.";
            $errorField = 'baptismal_cert';
        }
    }

    // ---------- insert & upload ----------
    if (!$error) {
        // Generate secure token
        $token = bin2hex(random_bytes(32)); // 64 hex chars

        $sql = "INSERT INTO individual_table (
            individual_lastname,
            individual_firstname,
            individual_middlename,
            individual_birthday,
            individual_gender,
            individual_username,
            individual_password,
            individual_phone_number,
            individual_email_address,
            individual_street,
            individual_city,
            individual_zip_code,
            individual_baptised,
            img_file_name,
            img_file_path,
            img_upload_at,
            img_valid_id,
            img_baptismal_cert,
            baptism_verification,
            account_status,
            email_verification_token,
            email_verified_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Yes', NULL, NULL, NULL, NULL, NULL, 'NonVerified', 'Unverified', ?, NULL)";

        if ($stmt = $db_connection->prepare($sql)) {
            $zipInt = (int)$zipcode;

            // Types: 11 strings + 1 integer + 1 string (token) = "sssssssssssis"
            $stmt->bind_param(
                "sssssssssssis",
                $lastname, $firstname, $middlename, $birthday, $gender,
                $username, $password, $phone, $email, $street, $city,
                $zipInt,
                $token
            );

            if ($stmt->execute()) {
                $newUserId = $stmt->insert_id;
                $stmt->close();

                // send verification email
                $sendRes = sendVerificationEmail($email, $firstname ?: $username, $token);

                // files
                $baseDir = __DIR__ . '/uploads/individuals';
                ensure_dir($baseDir);

                $saveFile = function($tmp, $origName, $prefix) use ($baseDir, $newUserId) {
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $safeBase = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', pathinfo($origName, PATHINFO_FILENAME));
                    $finalName = $prefix . '_' . $newUserId . '_' . time() . '_' . $safeBase . '.' . $ext;
                    $relative = "uploads/individuals/" . $finalName; // what we store in DB
                    $abs = $baseDir . '/' . $finalName;
                    if (move_uploaded_file($tmp, $abs)) {
                        return [$relative, $finalName];
                    }
                    return [null, null];
                };

                // Track what we’ll write back to DB in one UPDATE
                $selfiePath = null; $selfieName = null;
                $validIdPath = null;
                $bapCertPath = null;

                // Selfie with ID => store path AND name in DB (existing behavior)
                if ($selfieErr === UPLOAD_ERR_OK) {
                    [$selfiePath, $selfieName] = $saveFile($_FILES['selfie_id']['tmp_name'], $_FILES['selfie_id']['name'], 'selfie');
                }

                // Valid ID
                if ($validIdErr === UPLOAD_ERR_OK) {
                    [$validIdPath, ] = $saveFile($_FILES['valid_id']['tmp_name'], $_FILES['valid_id']['name'], 'validid');
                }

                // Baptismal Certificate
                if ($bapErr === UPLOAD_ERR_OK) {
                    [$bapCertPath, ] = $saveFile($_FILES['baptismal_cert']['tmp_name'], $_FILES['baptismal_cert']['name'], 'bapcert');
                }

                // ---------- single UPDATE for any uploaded files ----------
                $sets = [];
                $types = '';
                $params = [];

                if ($selfiePath && $selfieName) {
                    $sets[] = "img_file_name = ?";
                    $sets[] = "img_file_path = ?";
                    $sets[] = "img_upload_at = NOW()";
                    $types .= 'ss';
                    $params[] = $selfieName;
                    $params[] = $selfiePath;
                }
                if ($validIdPath) {
                    $sets[] = "img_valid_id = ?";
                    $types .= 's';
                    $params[] = $validIdPath;
                }
                if ($bapCertPath) {
                    $sets[] = "img_baptismal_cert = ?";
                    $types .= 's';
                    $params[] = $bapCertPath;

                    // NEW: when baptismal certificate is uploaded, mark baptism_verification as Pending
                    $sets[] = "baptism_verification = 'Pending'";
                }

                // ============================
                // persist NEW dropdown values via same single UPDATE
                // ============================
                if ($civil_status !== '') {
                    $sets[] = "civil_status = ?";
                    $types .= 's';
                    $params[] = $civil_status; // maps to DB column civil_status
                }
                if ($individual_baptised !== '') {
                    $sets[] = "individual_baptised = ?";
                    $types .= 's';
                    $params[] = $individual_baptised; // maps to DB column individual_baptised
                }
                // NEW: persist baptised_church when applicable
                if ($baptised_church_value !== null && $baptised_church_value !== '') {
                    $sets[] = "baptised_church = ?";
                    $types .= 's';
                    $params[] = $baptised_church_value;
                }
                // ============================

                if (!empty($sets)) {
                    $sqlUpd = "UPDATE individual_table SET " . implode(", ", $sets) . " WHERE individual_id = ?";
                    $stmtUpd = $db_connection->prepare($sqlUpd);
                    $types .= 'i';
                    $params[] = $newUserId;

                    // bind dynamically
                    $stmtParams = [];
                    $stmtParams[] = & $types;
                    foreach ($params as $k => $v) {
                        $stmtParams[] = & $params[$k];
                    }
                    call_user_func_array([$stmtUpd, 'bind_param'], $stmtParams);
                    $stmtUpd->execute();
                    $stmtUpd->close();
                }

                /* ============================
                   NOTIFICATION: If user uploaded a Baptismal Certificate ONLY,
                   notify admins with message:
                   "The user want to verify his/her account to be baptised"
                   (Trigger = bapCert uploaded AND no valid ID AND no selfie)
                ============================ */
                $uploadedOnlyBap = ($bapErr === UPLOAD_ERR_OK)
                                   && ($validIdErr === UPLOAD_ERR_NO_FILE || $validIdErr === UPLOAD_ERR_NO_TMP_DIR || $validIdErr === UPLOAD_ERR_NO_FILE)
                                   && ($selfieErr === UPLOAD_ERR_NO_FILE || $selfieErr === UPLOAD_ERR_NO_TMP_DIR || $selfieErr === UPLOAD_ERR_NO_FILE);

                if ($uploadedOnlyBap) {
                    // Ensure notifier identity is present for this workflow without logging the user in fully.
                    $prevType = $_SESSION['user_type'] ?? null;
                    $prevId   = $_SESSION['user_id']   ?? null;

                    $_SESSION['user_type'] = 'individual';
                    $_SESSION['user_id']   = (int)$newUserId;

                    $formType = 'Baptismal Verification Request';
                    $summaryParts = [];
                    $summaryParts[] = "The user want to verify his/her account to be baptised.";
                    $fullName = trim($lastname . ' ' . $firstname . ' ' . $middlename);
                    $fullName = preg_replace('/\s+/', ' ', $fullName);
                    if ($fullName !== '') $summaryParts[] = "Applicant: {$fullName}";
                    if ($email !== '')    $summaryParts[] = "Email: {$email}";
                    if ($individual_baptised !== '') $summaryParts[] = "Baptised: {$individual_baptised}";
                    if (!empty($baptised_church_value)) $summaryParts[] = "Church: {$baptised_church_value}";
                    $formSummary = implode(' | ', $summaryParts);

                    try {
                        notifyAdmins($db_connection, $formType, $newUserId, $formSummary);
                    } catch (Throwable $___e) {
                        // do not block user on notification error
                    }

                    // Restore previous session identity (optional)
                    if ($prevType === null) unset($_SESSION['user_type']); else $_SESSION['user_type'] = $prevType;
                    if ($prevId   === null) unset($_SESSION['user_id']);   else $_SESSION['user_id']   = $prevId;
                }
                /* ============================ */

                $success = "Registration received. Please check your email to verify your account.";
            } else {
                $error = "Database error: " . htmlspecialchars($stmt->error);
                $errorField = 'form';
                $stmt->close();
            }
        } else {
            $error = "Failed to prepare insert query.";
            $errorField = 'form';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>HTCCC - Sign Up</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
  <style>
    html { scroll-behavior: smooth; }
    /* ============================
       optional tiny helper styles
       ============================ */
    .hint { font-size:11px; color: rgba(255,255,255,.8); }

    /* ============================================
       Baptismal section hider
       (keeps layout clean when hidden)
       ============================================ */
    .bapcert-hidden { display: none !important; }

    /* ============================================
       Baptised Church block hider
       ============================================ */
    .bapchurch-hidden { display: none !important; }
  </style>
</head>

<body class="min-h-screen bg-fixed bg-center bg-cover relative"
      style="background-image:url('image/log_in-form-bg.jpg');">

  <div class="absolute inset-0 bg-[#0a1030]/70"></div>

  <main class="relative z-10 flex items-center justify-center min-h-screen p-4">
    <form id="signupForm" action="" method="POST" enctype="multipart/form-data"
      class="w-full max-w-4xl rounded-2xl bg-white/10 backdrop-blur-md shadow-2xl ring-1 ring-white/15
             text-white p-6 sm:p-8 md:p-10 space-y-8">

      <!-- Header -->
      <header class="text-center space-y-1">
        <h1 class="text-3xl font-extrabold tracking-wide">Create your Account</h1>
        <p class="text-sm text-white/80">Please fill in the details below to continue.</p>
        <div class="mx-auto mt-4 h-px w-28 bg-gradient-to-r from-[#6B5AE3] via-[#8AC9FF] to-[#6B5AE3]"></div>
      </header>

      <!-- PERSONAL INFO -->
      <section class="space-y-4">
        <h2 class="text-lg font-semibold uppercase tracking-wide text-[#8AC9FF]">Personal Information</h2>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="space-y-1">
            <label class="block text-xs font-bold">Last Name <span style="color: red">*<span><span class="text-red-400">*</span></label>
            <input name="lastname" type="text"
                   class="w-full rounded-lg px-3 py-2 text-gray-900 text-sm bg-white focus:outline-none
                          focus:ring-2 focus:ring-[#8AC9FF] focus:ring-offset-2 focus:ring-offset-[#0a1030]"
                   required>
            <p class="text-[11px] text-white/80">Enter your family/surname (e.g., Dela Cruz).</p>
          </div>

          <div class="space-y-1">
            <label class="block text-xs font-bold">First Name <span style="color: red">*<span></span> <span class="text-red-400">*</span></label>
            <input name="firstname" type="text"
                   class="w-full rounded-lg px-3 py-2 text-gray-900 text-sm bg-white focus:outline-none
                          focus:ring-2 focus:ring-[#8AC9FF] focus:ring-offset-2 focus:ring-offset-[#0a1030]"
                   required>
            <p class="text:[11px] text-white/80">Your given name (e.g., Juan).</p>
          </div>

          <div class="space-y-1">
            <label class="block text-xs font-bold">Middle Name</label>
            <input name="middlename" type="text"
                   class="w-full rounded-lg px-3 py-2 text-gray-900 text-sm bg-white focus:outline-none
                          focus:ring-2 focus:ring-[#8AC9FF] focus:ring-offset-2 focus:ring-offset-[#0a1030]">
            <p class="text-[11px] text-white/80">Optional — leave blank if none.</p>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-1">
            <label class="block text-xs font-bold">Birthday<span style="color: red">*<span></span> <span class="text-red-400">*</span></label>
            <input name="birthday" type="date"
                   max="<?php echo date('Y-m-d', strtotime('-12 years')); ?>"
                   class="w-full rounded-lg px-3 py-2 text-gray-900 text-sm bg-white focus:outline-none
                          focus:ring-2 focus:ring-[#8AC9FF] focus:ring-offset-2 focus:ring-offset-[#0a1030]"
                   required>
            <p class="text-[11px] text-white/80">You must be at least 12 years old (YYYY-MM-DD).</p>
          </div>

          <div class="space-y-1">
            <label class="block text-xs font-bold">Sex<span style="color: red">*<span></span><span class="text-red-400">*</span></label>
            <select name="gender"
                    class="w-full rounded-lg px-3 py-2 text-gray-900 text-sm bg-white focus:outline-none
                           focus:ring-2 focus:ring-[#8AC9FF] focus:ring-offset-2 focus:ring-offset-[#0a1030]"
                    required>
              <option value="">Select</option>
              <option value="Male">Male</option>
              <option value="Female">Female</option>
            </select>
            <p class="text-[11px] text-white/80">Choose one.</p>
          </div>
        </div>

        <!-- CIVIL STATUS & BAPTISED -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-1">
            <label class="block text-xs font-bold">Civil Status <span style="color: red">*<span> </span> <span class="text-red-400">*</span></label>
            <select name="civil_status"
                    class="w-full rounded-lg px-3 py-2 text-gray-900 text-sm bg-white focus:outline-none
                           focus:ring-2 focus:ring-[#8AC9FF] focus:ring-offset-2 focus:ring-offset-[#0a1030]"
                    required>
              <option value="">Select</option>
              <option value="Single">Single</option>
              <option value="Married">Married</option>
              <option value="Widowed">Widowed</option>
            </select>
            <p class="hint">Select your Civil Status.</p>
          </div>

          <div class="space-y-1">
            <label class="block text-xs font-bold">Baptised <span style="color: red">*<span></span> <span class="text-red-400">*</span></label>
            <select name="individual_baptised"
                    class="w-full rounded-lg px-3 py-2 text-gray-900 text-sm bg-white focus:outline-none
                           focus:ring-2 focus:ring-[#8AC9FF] focus:ring-offset-2 focus:ring-offset-[#0a1030]"
                    required>
              <option value="">Select</option>
              <option value="Yes">Yes</option>
              <option value="No">No</option>
            </select>
            <p class="hint">Please be specify if you are Baptised or Not</p>
          </div>
        </div>

        <!-- BAPTISED CHURCH UI (shows only if Baptised = Yes) -->
        <div id="bapChurchBlock" class="space-y-2 bapchurch-hidden" aria-hidden="true">
          <div class="space-y-1">
            <label class="block text-xs font-bold">In which Church?<span style="color: red">*<span></span><span class="text-red-400">*</span></label>
            <select name="baptised_church_select"
                    class="w-full rounded-lg px-3 py-2 text-gray-900 text-sm bg-white focus:outline-none
                           focus:ring-2 focus:ring-[#8AC9FF] focus:ring-offset-2 focus:ring-offset-[#0a1030]">
              <option value="">Select</option>
              <option value="Holy Trinity Christian Community Church">Holy Trinity Christian Community Church</option>
              <option value="Other">Other</option>
            </select>
            <p class="hint">Please be specify which church that you are Baptised.</p>
          </div>

          <div id="bapChurchOtherWrap" class="space-y-1 bapchurch-hidden" aria-hidden="true">
            <label class="block text-xs font-bold">Other Church Name <span class="text-red-400">*</span></label>
            <input name="baptised_church_other" type="text"
                   placeholder="Enter church name"
                   class="w-full rounded-lg px-3 py-2 text-gray-900 text-sm bg-white focus:outline-none
                          focus:ring-2 focus:ring-[#8AC9FF] focus:ring-offset-2 focus:ring-offset-[#0a1030]">
          </div>
        </div>
      </section>

      <!-- ACCOUNT -->
      <section class="space-y-4">
        <h2 class="text-lg font-semibold uppercase tracking-wide text-[#8AC9FF]">Account Information</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-1">
            <label class="block text-xs font-bold">Username <span style="color: red">*<span></span><span class="text-red-400">*</span></label>
            <input name="username" type="text"
                   class="w-full rounded-lg px-3 py-2 text-gray-900 text-sm bg-white focus:outline-none
                          focus:ring-2 focus:ring-[#8AC9FF] focus:ring-offset-2 focus:ring-offset-[#0a1030]"
                   required>
            <p class="text-[11px] text-white/80">Letters/numbers only; must be unique.</p>
          </div>

          <div class="space-y-1">
            <label class="block text-xs font-bold">Password <span style="color: red">*<span></span><span class="text-red-400">*</span></label>
            <input id="password" name="password" type="password"
                   class="w-full rounded-lg px-3 py-2 text-gray-900 text-sm bg-white focus:outline-none
                          focus:ring-2 focus:ring-[#8AC9FF] focus:ring-offset-2 focus:ring-offset-[#0a1030]"
                   required>
            <p class="text-[11px] text-white/80">Min 6–8 chars recommended.</p>

            <!-- Password Criteria (popover) -->
            <div id="pwCriteria" class="mt-2 rounded-lg ring-1 ring-black/10 p-3 text-[12px] shadow-2xl">
              <p class="font-bold mb-1">Password must have:</p>
              <ul class="space-y-1">
                <li data-crit="len" class="flex items-center gap-2">
                  <span class="w-4 h-4 inline-flex items-center justify-center rounded-full bg-black/10">•</span>
                  <span>At least <strong>8</strong> characters</span>
                </li>
                <li data-crit="upper" class="flex items-center gap-2">
                  <span class="w-4 h-4 inline-flex items-center justify-center rounded-full bg-black/10">•</span>
                  <span>At least <strong>1 uppercase</strong> letter (A–Z)</span>
                </li>
                <li data-crit="lower" class="flex items-center gap-2">
                  <span class="w-4 h-4 inline-flex items-center justify-center rounded-full bg-black/10">•</span>
                  <span>At least <strong>1 lowercase</strong> letter (a–z)</span>
                </li>
                <li data-crit="num" class="flex items-center gap-2">
                  <span class="w-4 h-4 inline-flex items-center justify-center rounded-full bg-black/10">•</span>
                  <span>At least <strong>1 number</strong> (0–9)</span>
                </li>
                <li data-crit="spec" class="flex items-center gap-2">
                  <span class="w-4 h-4 inline-flex items-center justify-center rounded-full bg-black/10">•</span>
                  <span>At least <strong>1 special</strong> character (!@#$%^&* etc.)</span>
                </li>
              </ul>
            </div>
          </div>

          <!-- ✅ FIXED: removed md:col-span-2 so Confirm Password matches Username box width -->
          <div class="space-y-1">
            <label class="block text-xs font-bold">Confirm Password <span style="color: red">*<span></span><span class="text-red-400">*</span></label>
            <input id="confirm_password" name="confirm_password" type="password"
                   class="w-full rounded-lg px-3 py-2 text-gray-900 text-sm bg-white focus:outline-none
                          focus:ring-2 focus:ring-[#8AC9FF] focus:ring-offset-2 focus:ring-offset-[#0a1030]"
                   required>
            <p class="text-[11px] text-white/80">Must match your password exactly.</p>
          </div>
        </div>
      </section>

      <!-- CONTACT -->
      <section class="space-y-4">
        <h2 class="text-lg font-semibold uppercase tracking-wide text-[#8AC9FF]">Contact Details</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-1">
            <label class="block text-xs font-bold">Phone Number <span style="color: red">*<span></span><span class="text-red-400">*</span></label>
            <input id="phone" name="phone" type="text" maxlength="11" inputmode="numeric"
                   class="w-full rounded-lg px-3 py-2 text-gray-900 text-sm bg-white focus:outline-none
                          focus:ring-2 focus:ring-[#8AC9FF] focus:ring-offset-2 focus:ring-offset-[#0a1030]"
                   required>
            <p class="text-[11px] text-white/80">Example: 09XXXXXXXXX.</p>
          </div>

          <div class="space-y-1">
            <label class="block text-xs font-bold">Email <span style="color: red">*<span></span><span class="text-red-400">*</span></label>
            <input name="email" type="email"
                   class="w-full rounded-lg px-3 py-2 text-gray-900 text-sm bg-white focus:outline-none
                          focus:ring-2 focus:ring-[#8AC9FF] focus:ring-offset-2 focus:ring-offset-[#0a1030]"
                   required>
            <p class="text-[11px] text-white/80">We’ll send notifications here.</p>
          </div>
        </div>
      </section>

      <!-- ADDRESS -->
      <section class="space-y-4">
        <h2 class="text-lg font-semibold uppercase tracking-wide text-[#8AC9FF]">Address</h2>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="space-y-1">
            <label class="block text-xs font-bold">Street <span style="color: red">*<span></span><span class="text-red-400">*</span></label>
            <input name="street" type="text"
                   class="w-full rounded-lg px-3 py-2 text-gray-900 text-sm bg-white focus:outline-none
                          focus:ring-2 focus:ring-[#8AC9FF] focus:ring-offset-2 focus:ring-offset-[#0a1030]"
                   required>
            <p class="text-[11px] text-white/80">House no., street, subdivision.</p>
          </div>

          <div class="space-y-1">
            <label class="block text-xs font-bold">City <span style="color: red">*<span></span><span class="text-red-400">*</span></label>
            <input name="city" type="text"
                   class="w-full rounded-lg px-3 py-2 text-gray-900 text-sm bg-white focus:outline-none
                          focus:ring-2 focus:ring-[#8AC9FF] focus:ring-offset-2 focus:ring-offset-[#0a1030]"
                   required>
            <p class="text-[11px] text-white/80">Your city/municipality.</p>
          </div>

          <div class="space-y-1">
            <label class="block text-xs font-bold">Zip Code <span style="color: red">*<span></span><span class="text-red-400">*</span></label>
            <input id="zipcode" name="zipcode" type="text" inputmode="numeric"
                   class="w-full rounded-lg px-3 py-2 text-gray-900 text-sm bg-white focus:outline-none
                          focus:ring-2 focus:ring-[#8AC9FF] focus:ring-offset-2 focus:ring-offset-[#0a1030]"
                   required>
            <p class="text-[11px] text-white/80">4-digit postal code.</p>
          </div>
        </div>
      </section>

      <!-- Sacramental Document -->
      <section class="space-y-4">
        <h2 class="text-lg font-semibold uppercase tracking-wide text-[#8AC9FF]">Sacramental Document</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-1 md:col-span-2">
            <label class="block text-xs font-bold">Baptismal Certificate (JPG/PNG/PDF)</label>
            <input name="baptismal_cert" type="file" accept=".jpg,.jpeg,.png,.pdf"
                   class="w-full rounded-lg px-3 py-2 text-gray-900 text-sm bg-white focus:outline-none
                          focus:ring-2 focus:ring-[#8AC9FF] focus:ring-offset-2 focus:ring-offset-[#0a1030]">
            <p class="hint">Optional. If provided, the file path is saved to <code>img_baptismal_cert</code>.</p>
          </div>
        </div>
      </section>

      <!-- PRIVACY / DPA ONLY (Terms removed) -->
      <section class="space-y-3 text-xs select-none">
        <div class="flex items-start gap-3">
          <input id="privacy" name="privacy" type="checkbox" class="mt-1 h-3 w-3 rounded border-white/30 bg-white/10" required />
          <label for="privacy" class="font-bold">
            I agree to the
            <a href="privacy-policy.php" class="underline text-[#8AC9FF] hover:text-white">Privacy Policy</a>
            and understand how my data will be used in accordance with the Data Privacy Act.
            <span class="text-red-400">*</span>
          </label>
        </div>
      </section>

      <!-- SUBMIT -->
      <div class="flex flex-col items-center gap-2 pt-2">
        <button type="submit"
                class="group inline-flex items-center gap-2 rounded-full bg-gradient-to-r from-[#6B5AE3] to-[#3bb9ff]
                       px-8 py-2 text-sm font-bold text-white shadow-lg shadow-[#1B1B4B]/30
                       hover:from-[#7a6df0] hover:to-[#54c6ff] focus:outline-none focus:ring-2 focus:ring-offset-2
                       focus:ring-[#8AC9FF] focus:ring-offset-[#0a1030] transition">
          <span>Create Account</span>
          <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 transition group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="currentColor"><path d="M13.172 12 8.222 7.05l1.414-1.414L16 12l-6.364 6.364-1.414-1.414z"/></svg>
        </button>
        <p class="text-[12px] font-bold">
          Already have an account?
          <a href="all_log-in.php" class="underline text-[#8AC9FF] hover:text-white">Sign in</a>
        </p>
      </div>
    </form>
  </main>

<!-- SweetAlert2 (server result, field-aware) -->
<?php if ($error): ?>
<script>
(function () {
  const msg   = <?= json_encode($error) ?>;
  const field = <?= json_encode($errorField ?? null) ?>;

  let title    = 'Registration Failed';
  let selector = null;

  switch (field) {
    case 'email':
      title = 'Email Problem';
      selector = 'input[name="email"]';
      break;
    case 'phone':
      title = 'Invalid Phone Number';
      selector = '#phone';
      break;
    case 'birthday':
      title = 'Invalid Birthday';
      selector = 'input[name="birthday"]';
      break;
    case 'gender':
      title = 'Sex Required';
      selector = 'select[name="gender"]';
      break;
    case 'civil_status':
      title = 'Civil Status Required';
      selector = 'select[name="civil_status"]';
      break;
    case 'individual_baptised':
      title = 'Baptised Status Required';
      selector = 'select[name="individual_baptised"]';
      break;
    case 'baptised_church_select':
      title = 'Church Selection Required';
      selector = 'select[name="baptised_church_select"]';
      break;
    case 'baptised_church_other':
      title = 'Church Name Required';
      selector = 'input[name="baptised_church_other"]';
      break;
    case 'confirm_password':
      title = 'Password Mismatch';
      selector = '#confirm_password';
      break;
    case 'username':
      title = 'Username / Email Already Used';
      selector = 'input[name="username"]';
      break;
    case 'valid_id':
      title = 'Invalid Valid ID';
      selector = 'input[name="valid_id"]';
      break;
    case 'selfie_id':
      title = 'Invalid Selfie with ID';
      selector = 'input[name="selfie_id"]';
      break;
    case 'baptismal_cert':
      title = 'Invalid Baptismal Certificate';
      selector = 'input[name="baptismal_cert"]';
      break;
    case 'form':
    default:
      title = 'Registration Failed';
      selector = null;
      break;
  }

  Swal.fire({
    icon: 'error',
    title: title,
    text: msg,
    confirmButtonColor: '#d33',
    confirmButtonText: 'OK'
  }).then(() => {
    if (selector) {
      const el = document.querySelector(selector);
      if (el) {
        el.focus();
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }
  });
})();
</script>
<?php elseif ($success): ?>
<script>
Swal.fire({
  icon: 'success',
  title: 'Verify your email',
  text: <?= json_encode($success) ?>,
  confirmButtonColor: '#3085d6',
  confirmButtonText: 'OK'
}).then(() => { window.location.href = 'all_log-in.php'; });
</script>
<?php endif; ?>

<!-- Client-side quick check for password (existing) -->
<script>
document.getElementById('signupForm').addEventListener('submit', function (e) {
  const pass = document.getElementById('password').value.trim();
  const conf = document.getElementById('confirm_password').value.trim();
  if (pass !== conf) {
    e.preventDefault();
    Swal.fire({
      icon: 'warning',
      title: 'Password mismatch',
      text: 'Please make sure the passwords match.',
      confirmButtonColor: '#f59e0b'
    });
  }
});
</script>

<!-- Data Privacy modal gate (scroll-to-enable) -->
<script>
(function () {
  const FORM_SEL = '#signupForm';
  const SESSION_KEY = 'dpaAccepted';
  const form = document.querySelector(FORM_SEL);
  if (!form) return;

  const toggleForm = (disabled) => {
    form.querySelectorAll('input, select, textarea, button').forEach(el => { el.disabled = disabled; });
  };

  if (sessionStorage.getItem(SESSION_KEY) === 'yes') { toggleForm(false); return; }
  toggleForm(true);

  const atBottom = (el) => (el.scrollTop + el.clientHeight >= el.scrollHeight - 3);

  Swal.fire({
    title: 'Data Privacy Notice & Consent',
    width: Math.min(window.innerWidth - 32, 720),
    allowOutsideClick: false,
    allowEscapeKey: false,
    showCloseButton: false,
    showCancelButton: true,
    cancelButtonText: 'I do not agree',
    confirmButtonText: 'I Agree',
    didOpen: () => {
      const html = `
        <div style="text-align:left">
          <div id="dpaScrollBox" style="max-height:320px; overflow:auto; padding-right:6px;">
            <p class="mb-2"><strong>HTCCC</strong> values your privacy. In accordance with the <strong>Data Privacy Act of 2012 (RA 10173)</strong>, we request your permission to collect and process your personal data when creating your account.</p>
            <p class="mt-3 font-semibold">What we collect</p>
            <ul class="list-disc ml-5">
              <li>Identity: name, birthday, gender</li>
              <li>Contact: phone, email, address</li>
              <li>Account: username and password (stored per current system setup)</li>
              <li>Verification files: valid ID, selfie with ID, baptismal certificate (if provided)</li>
            </ul>
            <p class="mt-3 font-semibold">Purpose</p>
            <ul class="list-disc ml-5">
              <li>Account creation and verification</li>
              <li>Scheduling, notifications, and church services administration</li>
              <li>Security, audit, and compliance</li>
            </ul>
            <p class="mt-3 font-semibold">Storage & Security</p>
            <p>Files are stored on our server. We apply reasonable safeguards against loss, misuse, and unauthorized access. Data is retained only as long as needed for the above purposes or as required by law.</p>
            <p class="mt-3 font-semibold">Your Rights</p>
            <ul class="list-disc ml-5">
              <li>Be informed, access, and correct your data</li>
              <li>Object to processing, withdraw consent, and request deletion where applicable</li>
            </ul>
            <p class="mt-3">For questions, contact <em>holytrinitychristiancommunityc@gmail.com</em>.</p>
            <hr class="my-3"/>
            <p class="text-sm">Please scroll to the bottom to enable the consent checkbox.</p>
          </div>
          <label class="mt-3 flex items-start gap-2">
            <input id="dpaCheckbox" type="checkbox" disabled class="mt-1 h-4 w-4 rounded border-gray-300">
            <span class="text-sm">
              I have read and understood the Data Privacy Notice and I consent to the collection and processing of my personal data as described.
            </span>
          </label>
        </div>
      `;
      Swal.update({ html });

      const box = Swal.getHtmlContainer().querySelector('#dpaScrollBox');
      const chk = Swal.getHtmlContainer().querySelector('#dpaCheckbox');
      const confirmBtn = Swal.getConfirmButton();
      confirmBtn.disabled = true;

      const maybeEnableCheckbox = () => { if (atBottom(box)) chk.disabled = false; };
      box.addEventListener('scroll', maybeEnableCheckbox);
      maybeEnableCheckbox();

      chk.addEventListener('change', () => { confirmBtn.disabled = !chk.checked; });
    }
  }).then((res) => {
    if (res.isConfirmed) {
      sessionStorage.setItem(SESSION_KEY, 'yes');
      toggleForm(false);
      Swal.fire({ toast:true, position:'top-end', timer:1800, showConfirmButton:false, icon:'success', title:'You can now Sign up!' });
    } else {
      toggleForm(true);
      Swal.fire({ icon:'info', title:'Consent required', text:'You need to accept the Data Privacy Notice to continue.', confirmButtonText:'Review Notice' })
      .then(() => { sessionStorage.removeItem(SESSION_KEY); location.reload(); });
    }
  });

  form.addEventListener('submit', function (e) {
    if (sessionStorage.getItem(SESSION_KEY) !== 'yes') {
      e.preventDefault();
      Swal.fire({ icon:'warning', title:'Consent needed', text:'Please accept the Data Privacy Notice before submitting the form.' });
    }
  });
})();
</script>

<!-- SUBMIT-ONLY VALIDATION (replaces old dynamic-required-asterisks) -->
<script id="dynamic-required-asterisks">
(function () {
  const form = document.getElementById('signupForm');
  if (!form) return;

  // Hide ALL existing red asterisks initially
  const allAsterisks = Array.from(form.querySelectorAll('label .text-red-400'));
  allAsterisks.forEach(span => span.classList.add('hidden'));

  // Gather all required controls
  const requiredControls = Array.from(form.querySelectorAll('input[required], select[required], textarea[required]'));

  function findLabel(el) {
    let p = el.parentElement;
    while (p && p !== form) {
      const possible = p.querySelector('label');
      if (possible) return possible;
      p = p.parentElement;
    }
    let prev = el.previousElementSibling;
    if (prev && prev.tagName === 'LABEL') return prev;
    return el.closest('div')?.querySelector('label') || null;
  }

  function toggleAsterisk(el, show) {
    const lbl = findLabel(el);
    if (!lbl) return;
    const star = lbl.querySelector('.text-red-400');
    if (star) {
      if (show) star.classList.remove('hidden');
      else star.classList.add('hidden');
    }
  }

  function markInvalid(el, invalid) {
    if (invalid) {
      el.classList.add('ring-2','ring-red-500','ring-offset-2');
      if (![...el.classList].some(c => c.startsWith('ring-offset-['))) {
        el.classList.add('ring-offset-[#0a1030]');
      }
      if (el.type === 'checkbox') {
        el.classList.add('outline','outline-2','outline-red-500');
      }
    } else {
      el.classList.remove('ring-2','ring-red-500','ring-offset-2','outline','outline-2','outline-red-500');
    }
  }

  function isInvalid(el) {
    if (!el.hasAttribute('required')) return false;
    if (el.getAttribute('name')==='birthday') {
      const v = (el.value || '').trim();
      if (!v) return true;

      const inputDate = new Date(v + 'T00:00:00');
      const today = new Date();
      today.setHours(0, 0, 0, 0);

      // invalid if birthday is in the future
      if (inputDate.getTime() > today.getTime()) return true;

      // compute age
      let age = today.getFullYear() - inputDate.getFullYear();
      const m = today.getMonth() - inputDate.getMonth();
      if (m < 0 || (m === 0 && today.getDate() < inputDate.getDate())) {
        age--;
      }

      // invalid if younger than 12
      return age < 12;
    }
    if (el.type === 'checkbox') return !el.checked;
    const val = (el.value||'').trim();
    if (el.tagName === 'SELECT') return val === '';
    if (el.type === 'email') {
      if (val === '') return true;
      return !el.checkValidity();
    }
    return val === '';
  }

  // Batch validate ONLY on submit
  function batchValidate() {
    let anyInvalid = false;
    for (const el of requiredControls) {
      const invalid = isInvalid(el);
      if (invalid) anyInvalid = true;
      markInvalid(el, invalid);
      toggleAsterisk(el, invalid);
    }
    return anyInvalid;
  }

  // expose (some other scripts call this)
  window.__htccc_batchValidate = batchValidate;

  // run ONLY on submit
  form.addEventListener('submit', function (e) {
    const anyInvalid = batchValidate();
    if (anyInvalid) {
      e.preventDefault();
      const firstInvalid = requiredControls.find(isInvalid);
      if (firstInvalid) {
        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        firstInvalid.classList.add('animate-[wiggle_0.25s_ease-in-out_1]');
        setTimeout(() => firstInvalid.classList.remove('animate-[wiggle_0.25s_ease-in-out_1]'), 300);
      }
      Swal.fire({
        icon: 'warning',
        title: 'Please complete the required fields',
        text: 'Some fields need your attention. Check the messages and highlights.',
        confirmButtonColor: '#f59e0b'
      });
    }
  }, true);

  // Minimal keyframes for subtle wiggle
  const style = document.createElement('style');
  style.textContent = `@keyframes wiggle{0%{transform:translateX(0)}25%{transform:translateX(-2px)}75%{transform:translateX(2px)}100%{transform:translateX(0)}}`;
  document.head.appendChild(style);
})();
</script>

<!-- Password Criteria Logic -->
<script id="password-criteria-logic">
(function () {
  const form = document.getElementById('signupForm');
  const pw = document.getElementById('password');
  const conf = document.getElementById('confirm_password');
  const box = document.getElementById('pwCriteria');
  if (!form || !pw || !box) return;

  const critEls = {
    len:   box.querySelector('[data-crit="len"]'),
    upper: box.querySelector('[data-crit="upper"]'),
    lower: box.querySelector('[data-crit="lower"]'),
    num:   box.querySelector('[data-crit="num"]'),
    spec:  box.querySelector('[data-crit="spec"]'),
  };

  function setState(el, ok) {
    if (!el) return;
    el.classList.toggle('text-green-600', ok);
    el.classList.toggle('text-red-600', !ok);
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

  const update = () => check(pw.value || '');
  pw.addEventListener('input', update);
  pw.addEventListener('blur', update);
  document.addEventListener('DOMContentLoaded', update);

  form.addEventListener('submit', function (e) {
    const r = check(pw.value || '');
    const okAll = r.len && r.upper && r.lower && r.num && r.spec;
    if (!okAll) {
      e.preventDefault();
      if (typeof window.__htccc_batchValidate === 'function') window.__htccc_batchValidate();
      Swal.fire({
        icon: 'warning',
        title: 'Password does not meet criteria',
        html: 'Please follow the password rules shown for the password field.',
        confirmButtonColor: '#f59e0b'
      }).then(() => { pw.focus(); });
    } else if (conf && pw.value !== conf.value) {
      setTimeout(() => conf.focus(), 0);
    }
  }, true);
})();
</script>

<!-- Password criteria popover -->
<script id="password-criteria-popover">
(function () {
  const pw = document.getElementById('password');
  const box = document.getElementById('pwCriteria');
  if (!pw || !box) return;

  // Move the criteria box to <body> and style as opaque white popover
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
    borderRadius: '0.75rem',
    boxShadow: '0 10px 30px rgba(0,0,0,0.3)',
    border: '1px solid rgba(0,0,0,0.1)',
    padding: '12px'
  });
  body.appendChild(box);

  // White arrow
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
    const r = pw.getBoundingClientRect();
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

  // Only show when password field is focused/typed
  pw.addEventListener('focus', show);
  pw.addEventListener('input', show);

  // Hide when clicking elsewhere (allow clicks inside box)
  document.addEventListener('click', (e) => {
    if (e.target === pw || box.contains(e.target)) return;
    hide();
  });
  pw.addEventListener('blur', () => setTimeout(() => {
    const active = document.activeElement;
    if (active === pw || box.contains(active)) return;
    hide();
  }, 100));

  window.addEventListener('resize', () => { if (box.style.display === 'block') positionBox(); });
  window.addEventListener('scroll', () => { if (box.style.display === 'block') positionBox(); }, { passive: true });
})();
</script>

<!-- Submit-only controller -->
<script id="submit-only-controller">
(function () {
  const form = document.getElementById('signupForm');
  if (!form) return;

  const ensure = () => typeof window.__htccc_batchValidate === 'function';
  const hook = () => {
    const orig = window.__htccc_batchValidate;
    if (!orig) return;

    window.__htccc_onlyOnSubmit = true;
    window.__htccc_isSubmitting = false;

    const submitBtn = form.querySelector('button[type="submit"]');

    const armSubmitting = () => {
      window.__htccc_isSubmitting = true;
      setTimeout(() => { window.__htccc_isSubmitting = false; }, 2000);
    };

    if (submitBtn) {
      submitBtn.addEventListener('click', armSubmitting, { capture: true });
    }
    form.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') armSubmitting();
    }, true);
    form.addEventListener('submit', armSubmitting, { capture: true });

    window.__htccc_batchValidate_original = orig;
    window.__htccc_batchValidate = function () {
      if (window.__htccc_onlyOnSubmit && !window.__htccc_isSubmitting) {
        return false;
      }
      const anyInvalid = window.__htccc_batchValidate_original();

      // EXCLUDE text-like controls from red ring (optional design)
      const textTypes = ['text','email','password','number','date'];
      textTypes.forEach(t => {
        form.querySelectorAll(`input[type="${t}"][required]`).forEach(el => {
          el.classList.remove('ring-2','ring-red-500','ring-offset-2','outline','outline-2','outline-red-500');
        });
      });
      form.querySelectorAll('textarea[required]').forEach(el => {
        el.classList.remove('ring-2','ring-red-500','ring-offset-2','outline','outline-2','outline-red-500');
      });

      return anyInvalid;
    };
  };

  if (ensure()) {
    hook();
  } else {
    const i = setInterval(() => {
      if (ensure()) { clearInterval(i); hook(); }
    }, 50);
    setTimeout(() => clearInterval(i), 3000);
  }
})();
</script>

<!-- Exclude checkboxes from red -->
<script id="exclude-checkbox-red">
(function(){
  const form=document.getElementById('signupForm');
  if(!form)return;
  const wrap=() => {
    const orig=window.__htccc_batchValidate;
    if(typeof orig!=='function') return;
    window.__htccc_batchValidate=function(){
      const res=orig.apply(this,arguments);
      form.querySelectorAll('input[type="checkbox"]').forEach(cb=>{
        cb.classList.remove('ring-2','ring-red-500','ring-offset-2','outline','outline-2','outline-red-500');
      });
      return res;
    };
  };
  if(typeof window.__htccc_batchValidate==='function'){ wrap(); }
  else{
    const t=setInterval(()=>{ if(typeof window.__htccc_batchValidate==='function'){ clearInterval(t); wrap(); } },50);
    setTimeout(()=>clearInterval(t),3000);
  }
})();
</script>

<!-- Strict submit visual gate -->
<script id="strict-submit-visual-gate">
(function(){
  const form = document.getElementById('signupForm');
  if(!form) return;

  let allowVisual = false;

  const submitBtn = form.querySelector('button[type="submit"]');
  if (submitBtn) {
    submitBtn.addEventListener('click', function(){
      allowVisual = true;
      window.__htccc_isSubmitting = true;
      if (typeof window.__htccc_batchValidate === 'function') {
        window.__htccc_batchValidate();
      }
      setTimeout(()=>{ window.__htccc_isSubmitting = false; }, 1500);
    }, {capture:true});
  }

  const patch = () => {
    const orig = window.__htccc_batchValidate;
    if (typeof orig !== 'function') return;
    window.__htccc_batchValidate = function(){
      if (!allowVisual) {
        form.querySelectorAll('.ring-red-500, .outline-red-500').forEach(el=>{
          el.classList.remove('ring-2','ring-red-500','ring-offset-2','outline','outline-2','outline-red-500');
        });
        form.querySelectorAll('label .text-red-400').forEach(star=>star.classList.add('hidden'));
        return false;
      }
      return orig.apply(this, arguments);
    };
  };

  if (typeof window.__htccc_batchValidate === 'function') patch();
  else {
    const t = setInterval(()=>{ if (typeof window.__htccc_batchValidate === 'function'){ clearInterval(t); patch(); } }, 50);
    setTimeout(()=>clearInterval(t), 3000);
  }
})();
</script>

<!-- Form value persistence -->
<script id="form-persist-on-error">
(function(){
  const form = document.getElementById('signupForm');
  if(!form) return;
  const KEY = 'htccc_signup_cache_v1';

  function load(){
    try {
      const raw = sessionStorage.getItem(KEY);
      if(!raw) return;
      const data = JSON.parse(raw);
      Array.from(form.elements).forEach(el=>{
        if (!el.name) return;
        if (!(el.name in data)) return;
        if (el.type === 'file') return;
        if (el.type === 'checkbox' || el.type === 'radio') {
          el.checked = !!data[el.name];
        } else {
          el.value = data[el.name];
        }
      });
    } catch(_){}
  }

  function save(){
    const data = {};
    Array.from(form.elements).forEach(el=>{
      if (!el.name) return;
      if (el.type === 'file') return;
      if (el.type === 'checkbox' || el.type === 'radio') data[el.name] = el.checked;
      else data[el.name] = el.value;
    });
    try { sessionStorage.setItem(KEY, JSON.stringify(data)); } catch(_){}
  }

  function clear(){ try { sessionStorage.removeItem(KEY); } catch(_){} }

  load();
  form.addEventListener('input', save, true);
  form.addEventListener('change', save, true);

  form.addEventListener('submit', function(e){
    setTimeout(() => { clear(); }, 1000);
  }, true);
})();
</script>

<!-- Unified DPA modal for Privacy Policy link -->
<script id="dpa-unified-link-modal">
(function(){
  const SESSION_KEY = 'dpaAccepted';
  const link = document.querySelector('a[href="privacy-policy.php"]');
  if (!link) return;

  const cloned = link.cloneNode(true);
  link.parentNode.replaceChild(cloned, link);

  function atBottom(el){ return (el.scrollTop + el.clientHeight >= el.scrollHeight - 3); }

  function showUnifiedModal(){
    const alreadyAccepted = sessionStorage.getItem(SESSION_KEY) === 'yes';

    Swal.fire({
      title: 'Data Privacy Notice & Consent',
      width: Math.min(window.innerWidth - 32, 720),
      allowOutsideClick: false,
      allowEscapeKey: false,
      showCancelButton: true,
      cancelButtonText: 'I do not agree',
      confirmButtonText: 'I Agree',
      didOpen: () => {
        const html = `
          <div style="text-align:left">
            <div id="dpaScrollBoxUnified" style="max-height:320px; overflow:auto; padding-right:6px;">
              <p class="mb-2"><strong>HTCCC</strong> values your privacy. In accordance with the <strong>Data Privacy Act of 2012 (RA 10173)</strong>, we request your permission to collect and process your personal data when creating your account.</p>
              <p class="mt-3 font-semibold">What we collect</p>
              <ul class="list-disc ml-5">
                <li>Identity: name, birthday, gender</li>
                <li>Contact: phone, email, address</li>
                <li>Account: username and password (stored per current system setup)</li>
                <li>Verification files: valid ID, selfie with ID, baptismal certificate (if provided)</li>
              </ul>
              <p class="mt-3 font-semibold">Purpose</p>
              <ul class="list-disc ml-5">
                <li>Account creation and verification</li>
                <li>Scheduling, notifications, and church services administration</li>
                <li>Security, audit, and compliance</li>
              </ul>
              <p class="mt-3 font-semibold">Storage & Security</p>
              <p>Files are stored on our server. We apply reasonable safeguards against loss, misuse, and unauthorized access. Data is retained only as long as needed for the above purposes or as required by law.</p>
              <p class="mt-3 font-semibold">Your Rights</p>
              <ul class="list-disc ml-5">
                <li>Be informed, access, and correct your data</li>
                <li>Object to processing, withdraw consent, and request deletion where applicable</li>
              </ul>
              <hr class="my-3"/>
              <p class="text-sm">Please scroll to the bottom to enable the consent checkbox.</p>
            </div>
            <label class="mt-3 flex items-start gap-2">
              <input id="dpaCheckboxUnified" type="checkbox" disabled class="mt-1 h-4 w-4 rounded border-gray-300">
              <span class="text-sm">
                I have read and understood the Data Privacy Notice and I consent to the collection and processing of my personal data as described.
              </span>
            </label>
          </div>
        `;
        Swal.update({ html });

        const box = Swal.getHtmlContainer().querySelector('#dpaScrollBoxUnified');
        const chk = Swal.getHtmlContainer().querySelector('#dpaCheckboxUnified');
        const confirmBtn = Swal.getConfirmButton();

        confirmBtn.disabled = true;
        const maybeEnable = () => { if (atBottom(box)) chk.disabled = false; };
        box.addEventListener('scroll', maybeEnable);
        maybeEnable();

        if (alreadyAccepted) {
          chk.disabled = false;
          chk.checked = true;
          confirmBtn.disabled = false;
        }

        chk.addEventListener('change', () => { confirmBtn.disabled = !chk.checked; });
      }
    }).then(res => {
      if (res.isConfirmed) {
        sessionStorage.setItem(SESSION_KEY, 'yes');
        Swal.fire({ toast:true, position:'top-end', timer:1800, showConfirmButton:false, icon:'success', title:'Consent saved' });
      } else {
        if (sessionStorage.getItem(SESSION_KEY) !== 'yes') {
          Swal.fire({ icon:'info', title:'Consent not given', text:'You need to accept the Data Privacy Notice to continue.', confirmButtonText:'OK' });
        }
      }
    });
  }

  cloned.addEventListener('click', function(e){
    e.preventDefault();
    showUnifiedModal();
  }, false);
})();
</script>

<!-- Reflect DPA consent into the actual form checkbox (#privacy) -->
<script id="dpa-reflect-to-form">
(function(){
  const SESSION_KEY = 'dpaAccepted';
  function reflect(){
    const privacy = document.getElementById('privacy');
    if (!privacy) return;
    const accepted = sessionStorage.getItem(SESSION_KEY) === 'yes';
    if (accepted && !privacy.checked) {
      privacy.checked = true;
      privacy.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  reflect();

  window.addEventListener('storage', function(e){
    if (e.key === SESSION_KEY) reflect();
  });

  let last = sessionStorage.getItem(SESSION_KEY);
  setInterval(() => {
    const curr = sessionStorage.getItem(SESSION_KEY);
    if (curr !== last) {
      last = curr;
      reflect();
    }
  }, 300);
})();
</script>

<!-- Baptismal Certificate show/hide controller -->
<script id="bapcert-visibility-controller">
(function(){
  const form   = document.getElementById('signupForm');
  if (!form) return;

  const baptisedSelect = form.querySelector('select[name="individual_baptised"]');
  const bapInput       = form.querySelector('input[name="baptismal_cert"]');

  if (!baptisedSelect || !bapInput) return;

  // find the nearest section to hide/show cleanly
  const bapSection = bapInput.closest('section') || bapInput.closest('div');

  // guard: apply initial hidden/disabled state before any paints
  function applyState() {
    const val = (baptisedSelect.value || '').trim();
    const show = (val === 'Yes');

    // Toggle section visibility
    if (bapSection) {
      if (show) {
        bapSection.classList.remove('bapcert-hidden');
        bapSection.setAttribute('aria-hidden', 'false');
      } else {
        bapSection.classList.add('bapcert-hidden');
        bapSection.setAttribute('aria-hidden', 'true');
      }
    }

    // Toggle the input enablement and value
    bapInput.disabled = !show;
    if (!show) {
      // Clear any selected file to avoid accidental upload when hidden
      try { bapInput.value = ''; } catch(_){}
    }
  }

  // Initialize ASAP (in case of persisted values / back button)
  applyState();

  // React to user changes
  baptisedSelect.addEventListener('change', applyState, false);

  // Also re-apply after our form persistence loader might populate fields
  window.addEventListener('load', applyState, { once: true });

  // If any external script mutates the select, re-apply periodically (short window)
  let ticks = 0;
  const t = setInterval(() => {
    applyState();
    if (++ticks > 20) clearInterval(t); // ~1s if 50ms default
  }, 50);
})();
</script>

<!-- Baptised Church show/hide controller -->
<script id="bapchurch-visibility-controller">
(function(){
  const form = document.getElementById('signupForm');
  if (!form) return;

  const baptisedSelect = form.querySelector('select[name="individual_baptised"]');
  const block          = document.getElementById('bapChurchBlock');
  const otherWrap      = document.getElementById('bapChurchOtherWrap');
  const churchSelect   = form.querySelector('select[name="baptised_church_select"]');
  const churchOther    = form.querySelector('input[name="baptised_church_other"]');

  if (!baptisedSelect || !block || !churchSelect || !otherWrap || !churchOther) return;

  function toggle(el, show, hiddenClass){
    if (show) {
      el.classList.remove(hiddenClass);
      el.setAttribute('aria-hidden', 'false');
    } else {
      el.classList.add(hiddenClass);
      el.setAttribute('aria-hidden', 'true');
    }
  }

  function applyState(){
    const isYes = (baptisedSelect.value || '').trim() === 'Yes';
    toggle(block, isYes, 'bapchurch-hidden');

    // when hidden, clear inner values to avoid accidental submit
    if (!isYes) {
      churchSelect.value = '';
      churchOther.value  = '';
      toggle(otherWrap, false, 'bapchurch-hidden');
      return;
    }

    const showOther = (churchSelect.value || '') === 'Other';
    toggle(otherWrap, showOther, 'bapchurch-hidden');
    if (!showOther) {
      churchOther.value = '';
    }
  }

  // init
  applyState();

  // react to changes
  baptisedSelect.addEventListener('change', applyState, false);
  churchSelect.addEventListener('change', applyState, false);

  // ensure state after persist loader runs
  window.addEventListener('load', applyState, { once:true });

  // small safety refresher
  let ticks = 0;
  const t = setInterval(() => {
    applyState();
    if (++ticks > 20) clearInterval(t);
  }, 50);
})();
</script>

<!-- Digits-only controller for phone & zip -->
<script id="digits-only-phone-zip">
(function () {
  const phone = document.getElementById('phone');
  const zip   = document.getElementById('zipcode');
  if (!phone || !zip) return;

  function enforceDigits(e) {
    let v = e.target.value || '';

    // Remove everything that's not 0–9
    v = v.replace(/\D+/g, '');

    // Extra: enforce 11 digits max for phone
    if (e.target === phone && v.length > 11) {
      v = v.slice(0, 11);
    }

    e.target.value = v;
  }

  phone.addEventListener('input', enforceDigits);
  zip.addEventListener('input', enforceDigits);
})();
</script>

<!-- Field-level SweetAlert on blur for key error-trap inputs -->
<script id="field-level-swal-on-blur">
(function () {
  const form = document.getElementById('signupForm');
  if (!form || typeof Swal === 'undefined') return;

  const email    = form.querySelector('input[name="email"]');
  const phone    = document.getElementById('phone');
  const birthday = form.querySelector('input[name="birthday"]');
  const pass     = document.getElementById('password');
  const conf     = document.getElementById('confirm_password');

  // Email: basic format check on blur
  if (email) {
    email.addEventListener('blur', function () {
      const v = (this.value || '').trim();
      if (!v) return;
      const ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
      if (!ok) {
        Swal.fire({
          icon: 'warning',
          title: 'Invalid Email Format',
          text: 'Please enter a valid email address (e.g., name@example.com).',
          confirmButtonColor: '#f59e0b'
        }).then(() => this.focus());
      }
    });
  }

  // Phone: exactly 11 digits on blur
  if (phone) {
    phone.addEventListener('blur', function () {
      const v = (this.value || '').trim();
      if (!v) return;
      if (!/^\d{11}$/.test(v)) {
        Swal.fire({
          icon: 'warning',
          title: 'Invalid Phone Number',
          text: 'Phone number must be exactly 11 digits (e.g., 09XXXXXXXXX).',
          confirmButtonColor: '#f59e0b'
        }).then(() => this.focus());
      }
    });
  }

  // Birthday: age + future-date check on blur
  if (birthday) {
    birthday.addEventListener('blur', function () {
      const v = (this.value || '').trim();
      if (!v) return;

      const inputDate = new Date(v + 'T00:00:00');
      const today = new Date();
      today.setHours(0, 0, 0, 0);

      if (Number.isNaN(inputDate.getTime())) {
        Swal.fire({
          icon: 'warning',
          title: 'Invalid Birthday',
          text: 'Please enter a valid date.',
          confirmButtonColor: '#f59e0b'
        }).then(() => this.focus());
        return;
      }

      if (inputDate.getTime() > today.getTime()) {
        Swal.fire({
          icon: 'warning',
          title: 'Birthday in the Future',
          text: 'Birthday cannot be in the future.',
          confirmButtonColor: '#f59e0b'
        }).then(() => this.focus());
        return;
      }

      let age = today.getFullYear() - inputDate.getFullYear();
      const m = today.getMonth() - inputDate.getMonth();
      if (m < 0 || (m === 0 && today.getDate() < inputDate.getDate())) {
        age--;
      }

      if (age < 12) {
        Swal.fire({
          icon: 'warning',
          title: 'Too Young',
          text: 'You must be at least 12 years old to register.',
          confirmButtonColor: '#f59e0b'
        }).then(() => this.focus());
      }
    });
  }

  // Confirm password: mismatch on blur
  if (pass && conf) {
    conf.addEventListener('blur', function () {
      const p = (pass.value || '').trim();
      const c = (conf.value || '').trim();
      if (!p || !c) return;
      if (p !== c) {
        Swal.fire({
          icon: 'warning',
          title: 'Password Mismatch',
          text: 'Passwords do not match.',
          confirmButtonColor: '#f59e0b'
        }).then(() => this.focus());
      }
    });
  }
})();
</script>

</body>
</html>
