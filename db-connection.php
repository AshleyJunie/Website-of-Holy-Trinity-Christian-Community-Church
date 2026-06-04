<?php
// Database connection using environment variables for production readiness.
$db_server = getenv('DB_HOST') ?: 'localhost';
$db_username = getenv('DB_USERNAME') ?: 'root';
$db_password = getenv('DB_PASSWORD') ?: '';
$db_name = getenv('DB_NAME') ?: 'htccc-data-base';

$db_connection = mysqli_connect($db_server, $db_username, $db_password, $db_name);

if (!$db_connection) {
    // Log the error for the server logs and return a generic message to users
    error_log('Database connection failed: ' . mysqli_connect_error());
    // Do not expose DB details in production output
    die('Database connection error.');
}

// Use `$db_connection` in your application. Close the connection when done.
?>