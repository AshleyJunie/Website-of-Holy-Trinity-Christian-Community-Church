<?php
session_start();
date_default_timezone_set('Asia/Manila');
$_SESSION['notif_seen_date'] = date('Y-m-d');
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'seen_date' => $_SESSION['notif_seen_date']]);
