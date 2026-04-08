<?php
// audit_actor.php
if (session_status() === PHP_SESSION_NONE) session_start();

function set_db_actor_from_session(mysqli $db) {
    // default: clear muna
    $db->query("SET @actor_admin_id = NULL, @actor_username = NULL, @actor_email = NULL, @ip_address = NULL, @user_agent = NULL, @txn_id = UUID()");

    if (!empty($_SESSION['role']) && $_SESSION['role'] === 'admin' && !empty($_SESSION['admin_id'])) {
        $id  = (int)$_SESSION['admin_id'];
        $usr = $db->real_escape_string($_SESSION['admin_user'] ?? '');
        $em  = $db->real_escape_string($_SESSION['admin_email'] ?? '');

        $ip  = $db->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');
        $ua  = $db->real_escape_string($_SERVER['HTTP_USER_AGENT'] ?? '');

        $db->query("SET @actor_admin_id = {$id}");
        $db->query("SET @actor_username = '{$usr}'");
        $db->query("SET @actor_email    = '{$em}'");
        $db->query("SET @ip_address     = '{$ip}'");
        $db->query("SET @user_agent     = '{$ua}'");
        // @txn_id gawa na sa unang SET (UUID())
    }
}
