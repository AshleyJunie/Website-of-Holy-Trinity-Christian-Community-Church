<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'db-connection.php';

$day = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid date']);
  exit;
}

$sql = "
  SELECT appointment_time FROM service_baptism  WHERE status <> 'Cancelled' AND appointment_date = ?
  UNION ALL
  SELECT appointment_time FROM service_dedication WHERE status <> 'Cancelled' AND appointment_date = ?
  UNION ALL
  SELECT appointment_time FROM service_funeral    WHERE status <> 'Cancelled' AND appointment_date = ?
  UNION ALL
  SELECT appointment_time FROM service_house      WHERE status <> 'Cancelled' AND appointment_date = ?
  UNION ALL
  SELECT appointment_time FROM service_wedding    WHERE status <> 'Cancelled' AND appointment_date = ?
";

$times = [];
if ($stmt = mysqli_prepare($db_connection, $sql)) {
  mysqli_stmt_bind_param($stmt, "sssss", $day, $day, $day, $day, $day);
  mysqli_stmt_execute($stmt);
  $rs = mysqli_stmt_get_result($stmt);
  while ($row = mysqli_fetch_assoc($rs)) {
    $times[] = trim($row['appointment_time']);
  }
  mysqli_stmt_close($stmt);
}

echo json_encode($times, JSON_UNESCAPED_UNICODE);
