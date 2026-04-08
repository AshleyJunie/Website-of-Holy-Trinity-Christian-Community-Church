<?php
// funeral-submit.php
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
  $returnTo = 'form-funeral.php';
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

try {
  // --- Gather required fields from hidden + inputs
  $appointment_date    = req('appointment_date');   // YYYY-MM-DD
  $appointment_time    = req('appointment_time');   // string
  $appointment_service = $_POST['appointment_service'] ?? 'FUNERAL SERVICE';

  $deceased_name       = req('deceased_name');
  $deceased_birthdate  = req('deceased_birthdate'); // YYYY-MM-DD
  $home_address        = req('home_address');

  $contact_person      = req('contact_person');
  $contact_number      = req('contact_number');
  $email_address       = isset($_POST['email_address']) ? trim($_POST['email_address']) : null;
  $funeral_date        = req('funeral_date');       // YYYY-MM-DD
  $remarks             = isset($_POST['remarks']) ? trim($_POST['remarks']) : null;

  // Basic email sanity check (optional)
  if ($email_address !== null && $email_address !== '' && !filter_var($email_address, FILTER_VALIDATE_EMAIL)) {
    throw new RuntimeException("Please provide a valid email address.");
  }

  // --- Insert
  $sql = "INSERT INTO service_funeral (
            individual_id,
            appointment_date, appointment_time, appointment_service,
            deceased_name, deceased_birthdate, home_address,
            contact_person, contact_number, email_address,
            funeral_date, remarks, status
          ) VALUES (
            :individual_id,
            :appointment_date, :appointment_time, :appointment_service,
            :deceased_name, :deceased_birthdate, :home_address,
            :contact_person, :contact_number, :email_address,
            :funeral_date, :remarks, 'Pending'
          )";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':individual_id'       => $sessionId,
    ':appointment_date'    => $appointment_date,
    ':appointment_time'    => $appointment_time,
    ':appointment_service' => $appointment_service,
    ':deceased_name'       => $deceased_name,
    ':deceased_birthdate'  => $deceased_birthdate,
    ':home_address'        => $home_address,
    ':contact_person'      => $contact_person,
    ':contact_number'      => $contact_number,
    ':email_address'       => $email_address,
    ':funeral_date'        => $funeral_date,
    ':remarks'             => $remarks,
  ]);

  // Redirect back so the form can show the SweetAlert2 success popup
  header('Location: form-funeral.php?success=1'
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
