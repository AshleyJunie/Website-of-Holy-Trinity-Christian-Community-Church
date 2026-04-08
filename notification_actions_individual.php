<?php
// /HTCCC-SYSTEM/notification_actions_individual.php
// Handles AJAX actions for individual notifications:
//   - mark_all_read : set status = 'read' for all UNREAD rows
//   - clear_all     : set status = 'clear' for all rows (read or unread)

if (session_status() === PHP_SESSION_NONE) { session_start(); }

header('Content-Type: application/json');

require_once $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/db-connection.php';

if (empty($db_connection)) {
    echo json_encode(['success' => false, 'error' => 'no_db_connection']);
    exit;
}

if (!isset($_SESSION['individual_id'])) {
    echo json_encode(['success' => false, 'error' => 'not_logged_in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'invalid_method']);
    exit;
}

$action = $_POST['action'] ?? '';
$iid    = (int) $_SESSION['individual_id'];

if ($action === 'mark_all_read') {

    $sql = "
        UPDATE notification_recipients
        SET status = 'read',
            read_at = NOW()
        WHERE user_type = 'individual'
          AND user_id   = ?
          AND status    = 'unread'
    ";

    if ($stmt = mysqli_prepare($db_connection, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $iid);
        mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        echo json_encode([
            'success' => true,
            'action'  => 'mark_all_read',
            'updated' => $affected
        ]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => 'stmt_failed']);
        exit;
    }

} elseif ($action === 'clear_all') {

    $sql = "
        UPDATE notification_recipients
        SET status = 'clear',
            read_at = IF(read_at IS NULL, NOW(), read_at)
        WHERE user_type = 'individual'
          AND user_id   = ?
          AND status   <> 'clear'
    ";

    if ($stmt = mysqli_prepare($db_connection, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $iid);
        mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        echo json_encode([
            'success' => true,
            'action'  => 'clear_all',
            'updated' => $affected
        ]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => 'stmt_failed']);
        exit;
    }

} else {
    echo json_encode(['success' => false, 'error' => 'unknown_action']);
    exit;
}
