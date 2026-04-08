<?php
require 'db-connection.php';
mysqli_set_charset($db_connection,'utf8mb4');

function back($ok,$msg){
  header('Location: content-management_gallery.php?'.($ok?'toast=':'err=').urlencode($msg));
  exit;
}
if ($_SERVER['REQUEST_METHOD']!=='POST') back(false,'Invalid request.');

$id = isset($_POST['galleryId']) ? (int)$_POST['galleryId'] : 0;
$details = trim($_POST['details'] ?? '');
if ($id<=0 || $details==='') back(false,'Missing fields.');

$stmt = mysqli_prepare($db_connection, "UPDATE gallery_table SET details=? WHERE galleryId=? LIMIT 1");
mysqli_stmt_bind_param($stmt,'si',$details,$id);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

back($ok, $ok ? 'Details updated.' : 'Update failed.');
