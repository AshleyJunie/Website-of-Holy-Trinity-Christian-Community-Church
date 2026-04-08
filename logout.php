<?php
session_start();
$_SESSION = [];
session_destroy();
header("Location: main-page.php"); // or your main page
exit;
