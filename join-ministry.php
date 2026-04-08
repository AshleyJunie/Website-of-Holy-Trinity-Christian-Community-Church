<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$next = 'ministry-form.php';

// Individual-only rule (exactly as requested)
if (isset($_SESSION['individual_id'])) {
    header('Location: '.$next);
    exit;
}

// Not logged in → go to login with next=ministry-form.php
$target = 'all_log-in.php?next=' . urlencode($next);
if (headers_sent()) {
    echo '<script>window.location.href = '.json_encode($target).';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url='.htmlspecialchars($target, ENT_QUOTES, 'UTF-8').'"></noscript>';
    exit;
}
header('Location: '.$target);
exit;
