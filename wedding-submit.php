<?php
// wedding-submit.php
session_start();

// --- DB connect (adjust credentials/DB name)
try {
  $pdo = new PDO(
    "mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4",
    "root",
    "",
    [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]
  );
} catch (Throwable $e) {
  http_response_code(500);
  exit("Database connection error.");
}

// --- Require login
$sessionId = $_SESSION['individual_id'] ?? null;
$postId    = isset($_POST['individual_id']) ? (int)$_POST['individual_id'] : null;
if (!$sessionId) {
  // not logged in: bounce to login and return here
  $returnTo = 'form-wedding.php';
  header('Location: all_log-in.php?return_to=' . rawurlencode($returnTo));
  exit;
}

// --- Helper: quick required fetch
function req($key) {
  if (!isset($_POST[$key]) || $_POST[$key] === '') {
    throw new RuntimeException("Missing required field: {$key}");
  }
  return trim($_POST[$key]);
}

// --- Helper: save upload (returns relative path)
function save_upload(string $field, string $baseDir, array $allowedExt = ['pdf','jpg','jpeg','png']): string {
  if (!isset($_FILES[$field]) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
    throw new RuntimeException("Missing required file: {$field}");
  }
  $file = $_FILES[$field];

  // Basic checks
  if ($file['error'] !== UPLOAD_ERR_OK) {
    throw new RuntimeException("Upload error ({$field}): code " . $file['error']);
  }

  // Validate extension
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, $allowedExt, true)) {
    throw new RuntimeException("Invalid file type for {$field}. Allowed: " . implode(', ', $allowedExt));
  }

  // Ensure target dir
  if (!is_dir($baseDir)) {
    if (!mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
      throw new RuntimeException("Failed to create directory: {$baseDir}");
    }
  }

  // Unique filename (keeps ext)
  $targetAbs = rtrim($baseDir, '/\\') . '/' . uniqid($field . '_', true) . '.' . $ext;

  if (!move_uploaded_file($file['tmp_name'], $targetAbs)) {
    throw new RuntimeException("Failed to move uploaded file for {$field}");
  }

  // Return relative path from web root (adjust if needed)
  $docRoot    = rtrim(str_replace('\\','/', $_SERVER['DOCUMENT_ROOT']), '/');
  $targetNorm = str_replace('\\','/', $targetAbs);

  if (strpos($targetNorm, $docRoot) === 0) {
    return ltrim(substr($targetNorm, strlen($docRoot)), '/');
  }
  return $targetNorm;
}

try {
  // --- Gather required fields
  $appointment_date    = req('appointment_date');   // YYYY-MM-DD from hidden
  $appointment_time    = req('appointment_time');   // from hidden
  $appointment_service = $_POST['appointment_service'] ?? 'WEDDING';

  $groom_name      = req('groom_name');
  $groom_birthdate = req('groom_birthdate');        // YYYY-MM-DD
  $groom_church    = $_POST['groom_church'] ?? null;

  $bride_name      = req('bride_name');
  $bride_birthdate = req('bride_birthdate');        // YYYY-MM-DD
  $bride_church    = $_POST['bride_church'] ?? null;

  $contact_number  = req('contact_number');
  $special_request = $_POST['special_request'] ?? null;

  // --- File uploads target
  $stamp   = date('Ymd_His');
  $baseDir = __DIR__ . "/uploads/wedding/{$sessionId}/{$stamp}";

  // Required uploads
  $groom_birth_cert_path = save_upload('groom_birth_cert', $baseDir);
  $groom_valid_id_path   = save_upload('groom_valid_id',   $baseDir);
  $bride_birth_cert_path = save_upload('bride_birth_cert', $baseDir);
  $bride_valid_id_path   = save_upload('bride_valid_id',   $baseDir);
  $bride_cenomar_path    = save_upload('bride_cenomar',    $baseDir);
  $groom_cenomar_path    = save_upload('groom_cenomar',    $baseDir);

  // Optional uploads
  $groom_baptismal_cert_path = null;
  if (!empty($_FILES['groom_baptismal_cert']['name'])) {
    $groom_baptismal_cert_path = save_upload('groom_baptismal_cert', $baseDir);
  }
  $bride_baptismal_cert_path = null;
  if (!empty($_FILES['bride_baptismal_cert']['name'])) {
    $bride_baptismal_cert_path = save_upload('bride_baptismal_cert', $baseDir);
  }

  // --- Insert
  $sql = "INSERT INTO service_wedding (
            individual_id,
            appointment_date, appointment_time, appointment_service,
            groom_name, groom_birthdate, groom_church,
            bride_name, bride_birthdate, bride_church,
            contact_number, special_request,
            groom_birth_cert_path, groom_valid_id_path, groom_baptismal_cert_path,
            bride_birth_cert_path, bride_valid_id_path, bride_baptismal_cert_path,
            bride_cenomar_path, groom_cenomar_path,
            status
          ) VALUES (
            :individual_id,
            :appointment_date, :appointment_time, :appointment_service,
            :groom_name, :groom_birthdate, :groom_church,
            :bride_name, :bride_birthdate, :bride_church,
            :contact_number, :special_request,
            :groom_birth_cert_path, :groom_valid_id_path, :groom_baptismal_cert_path,
            :bride_birth_cert_path, :bride_valid_id_path, :bride_baptismal_cert_path,
            :bride_cenomar_path, :groom_cenomar_path,
            'Pending'
          )";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':individual_id'             => $sessionId, // trust session
    ':appointment_date'          => $appointment_date,
    ':appointment_time'          => $appointment_time,
    ':appointment_service'       => $appointment_service,
    ':groom_name'                => $groom_name,
    ':groom_birthdate'           => $groom_birthdate,
    ':groom_church'              => $groom_church,
    ':bride_name'                => $bride_name,
    ':bride_birthdate'           => $bride_birthdate,
    ':bride_church'              => $bride_church,
    ':contact_number'            => $contact_number,
    ':special_request'           => $special_request,
    ':groom_birth_cert_path'     => $groom_birth_cert_path,
    ':groom_valid_id_path'       => $groom_valid_id_path,
    ':groom_baptismal_cert_path' => $groom_baptismal_cert_path,
    ':bride_birth_cert_path'     => $bride_birth_cert_path,
    ':bride_valid_id_path'       => $bride_valid_id_path,
    ':bride_baptismal_cert_path' => $bride_baptismal_cert_path,
    ':bride_cenomar_path'        => $bride_cenomar_path,
    ':groom_cenomar_path'        => $groom_cenomar_path,
  ]);

  // ---- Success page with SweetAlert and redirect to main-page.php
  $safeDate = htmlspecialchars($appointment_date, ENT_QUOTES, 'UTF-8');
  $safeTime = htmlspecialchars($appointment_time, ENT_QUOTES, 'UTF-8');
  $safeSvc  = htmlspecialchars($appointment_service, ENT_QUOTES, 'UTF-8');

  ?>
  <!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Appointment Submitted</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
      html, body {
        height: 100%;
        margin: 0;
        font-family: Arial, Helvetica, sans-serif;
        background: transparent; /* transparent page background */
      }

      /* Blur and dim the entire overlay behind the modal */
      .swal2-container {
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        background: rgba(0, 0, 0, 0.25) !important; /* subtle dark overlay */
      }

      /* Glassmorphism-style popup */
      .swal2-popup {
        background: rgba(255, 255, 255, 0.8) !important; /* slightly transparent */
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border-radius: 18px !important;
        box-shadow: 0 18px 45px rgba(0, 0, 0, 0.25);
      }

      .swal2-title {
        font-weight: 700;
      }

      .swal2-html-container {
        font-size: 0.95rem;
      }

      .swal2-confirm {
        border-radius: 999px !important;
        padding: 0.55rem 1.8rem !important;
        font-weight: 600 !important;
      }
    </style>
  </head>
  <body>
    <script>
      Swal.fire({
        icon: 'success',
        title: 'Appointment Submitted',
        html: `
          <p>Your wedding appointment was submitted successfully.</p>
          <p>Please allow up to <b>1 business day</b> for approval.</p>
          <p>You will receive an email once your slot is confirmed.</p>
          <hr style="opacity:.2;">
          <p style="font-size:.9rem;margin-top:10px;text-align:left;">
            <b>Details</b><br>
            Date: <?= $safeDate ?><br>
            Time: <?= $safeTime ?><br>
            Service: <?= $safeSvc ?>
          </p>
        `,
        confirmButtonText: 'Okay',
        confirmButtonColor: '#7FC1FF',
        allowOutsideClick: false,
        allowEscapeKey: false
      }).then(() => {
        window.location.href = 'main-page.php';
      });
    </script>
  </body>
  </html>
  <?php
  exit;

} catch (Throwable $e) {
  // Basic error display (you can log $e->getMessage())
  http_response_code(400);
  echo "<h2 style='font-family:Arial'>Submission Error</h2>";
  echo "<p style='font-family:Arial'>".htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')."</p>";
  echo "<p><a href='javascript:history.back()'>Go back</a></p>";
}
