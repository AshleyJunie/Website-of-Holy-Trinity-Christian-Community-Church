<?php
// baptism-submit.php
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
if (!$sessionId) {
  $returnTo = 'form-baptism.php';
  header('Location: all_log-in.php?return_to=' . rawurlencode($returnTo));
  exit;
}

// --- Helpers
function req($key) {
  if (!isset($_POST[$key]) || $_POST[$key] === '') {
    throw new RuntimeException("Missing required field: {$key}");
  }
  return trim($_POST[$key]);
}

function save_upload(string $field, string $baseDir, array $allowedExt = ['pdf','jpg','jpeg','png']): string {
  if (!isset($_FILES[$field]) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
    throw new RuntimeException("Missing required file: {$field}");
  }
  $file = $_FILES[$field];

  if ($file['error'] !== UPLOAD_ERR_OK) {
    throw new RuntimeException("Upload error ({$field}): code " . $file['error']);
  }

  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, $allowedExt, true)) {
    throw new RuntimeException("Invalid file type for {$field}. Allowed: " . implode(', ', $allowedExt));
  }

  if (!is_dir($baseDir)) {
    if (!mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
      throw new RuntimeException("Failed to create directory: {$baseDir}");
    }
  }

  $targetAbs = rtrim($baseDir, '/\\') . '/' . uniqid($field . '_', true) . '.' . $ext;

  if (!move_uploaded_file($file['tmp_name'], $targetAbs)) {
    throw new RuntimeException("Failed to move uploaded file for {$field}");
  }

  // Return a web-relative path if inside docroot; otherwise return saved path
  $docRoot = rtrim(str_replace('\\','/', $_SERVER['DOCUMENT_ROOT']), '/');
  $targetNorm = str_replace('\\','/', $targetAbs);
  if (strpos($targetNorm, $docRoot) === 0) {
    return ltrim(substr($targetNorm, strlen($docRoot)), '/');
  }
  return $targetNorm;
}

try {
  // --- Gather required fields
  $appointment_date    = req('appointment_date');   // YYYY-MM-DD (hidden)
  $appointment_time    = req('appointment_time');   // string (hidden)
  $appointment_service = $_POST['appointment_service'] ?? 'BAPTISM';

  $baptized_name       = req('baptized_name');
  $baptized_birthdate  = req('baptized_birthdate'); // YYYY-MM-DD
  $baptismal_method    = $_POST['baptismal_method'] ?? null; // optional

  $guardian_name       = $_POST['guardian_name'] ?? null;     // optional
  $contact_number      = req('contact_number');
  $email_address       = isset($_POST['email_address']) ? trim($_POST['email_address']) : null;
  $special_request     = $_POST['special_request'] ?? null;

  // Basic email sanity check (optional)
  if ($email_address !== null && $email_address !== '' && !filter_var($email_address, FILTER_VALIDATE_EMAIL)) {
    throw new RuntimeException("Please provide a valid email address.");
  }

  // --- File uploads target
  $stamp   = date('Ymd_His');
  $baseDir = __DIR__ . "/uploads/baptism/{$sessionId}/{$stamp}";

  // Required upload: Baptizand's Birth Certificate
  $baptismal_cert_path = save_upload('baptismal_cert', $baseDir);

  // --- Insert
  $sql = "INSERT INTO service_baptism (
            individual_id,
            appointment_date, appointment_time, appointment_service,
            baptized_name, baptized_birthdate, baptismal_method,
            guardian_name, contact_number, email_address, special_request,
            baptismal_cert_path, status
          ) VALUES (
            :individual_id,
            :appointment_date, :appointment_time, :appointment_service,
            :baptized_name, :baptized_birthdate, :baptismal_method,
            :guardian_name, :contact_number, :email_address, :special_request,
            :baptismal_cert_path, 'Pending'
          )";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':individual_id'       => $sessionId,
    ':appointment_date'    => $appointment_date,
    ':appointment_time'    => $appointment_time,
    ':appointment_service' => $appointment_service,
    ':baptized_name'       => $baptized_name,
    ':baptized_birthdate'  => $baptized_birthdate,
    ':baptismal_method'    => $baptismal_method,
    ':guardian_name'       => $guardian_name,
    ':contact_number'      => $contact_number,
    ':email_address'       => $email_address,
    ':special_request'     => $special_request,
    ':baptismal_cert_path' => $baptismal_cert_path,
  ]);

  // Redirect back to the form so SweetAlert can show (already wired there)
  header('Location: form-baptism.php?success=1'
    . '&date=' . rawurlencode($appointment_date)
    . '&time=' . rawurlencode($appointment_time)
    . '&service=' . rawurlencode($appointment_service)
  );
  exit;

} catch (Throwable $e) {
  http_response_code(400);
  echo "<h2 style='font-family:Arial'>Submission Error</h2>";
  echo "<p style='font-family:Arial'>".htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')."</p>";
  echo "<p><a href='javascript:history.back()'>Go back</a></p>";
}
