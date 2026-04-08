<?php
// get_bookings.php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once 'db-connection.php'; // must provide $db_connection (mysqli)

$result = [];
try {
    // Fast path if you created the view:
    $sql = "SELECT appointment_date, appointment_time FROM service_all_bookings";
    $use_view = mysqli_query($db_connection, "SHOW FULL TABLES WHERE Table_type = 'VIEW' AND Tables_in_".mysqli_real_escape_string($db_connection, mysqli_fetch_row(mysqli_query($db_connection, "SELECT DATABASE()"))[0])." = 'service_all_bookings'");
    if (!$use_view || mysqli_num_rows($use_view) === 0) {
        // Fallback UNION ALL (works even without the view)
        $sql = "
          SELECT appointment_date, appointment_time FROM service_baptism  WHERE status <> 'Cancelled'
          UNION ALL
          SELECT appointment_date, appointment_time FROM service_dedication WHERE status <> 'Cancelled'
          UNION ALL
          SELECT appointment_date, appointment_time FROM service_funeral    WHERE status <> 'Cancelled'
          UNION ALL
          SELECT appointment_date, appointment_time FROM service_house      WHERE status <> 'Cancelled'
          UNION ALL
          SELECT appointment_date, appointment_time FROM service_wedding    WHERE status <> 'Cancelled'
        ";
    }

    if ($rs = mysqli_query($db_connection, $sql)) {
        while ($row = mysqli_fetch_assoc($rs)) {
            // Normalize formats to match your front-end
            $result[] = [
                'appointment_date' => $row['appointment_date'],   // 'YYYY-MM-DD'
                'appointment_time' => $row['appointment_time']    // '9:00 AM - 11:00 AM' etc.
            ];
        }
        mysqli_free_result($rs);
    }
} catch (Throwable $e) {
    // Optional: log error server-side
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
