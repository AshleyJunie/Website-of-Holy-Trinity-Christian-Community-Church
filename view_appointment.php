<?php
require __DIR__ . '/db-connection.php';
mysqli_set_charset($db_connection,'utf8mb4');

$src=$_GET['src']??''; $pk=(int)($_GET['pk']??0);
if(!$pk){die("Invalid ID");}

switch($src){
  case 'baptism':
    $sql="SELECT * FROM service_baptism WHERE baptism_id=?";
    break;
  case 'dedication':
    $sql="SELECT * FROM service_dedication WHERE dedicationId=?";
    break;
  case 'funeral':
    $sql="SELECT * FROM service_funeral WHERE funeral_id=?";
    break;
  case 'house':
    $sql="SELECT * FROM service_house WHERE appointment_id=?";
    break;
  case 'wedding':
    $sql="SELECT * FROM service_wedding WHERE wedding_id=?";
    break;
  default: die("Invalid source");
}

$stmt=mysqli_prepare($db_connection,$sql);
mysqli_stmt_bind_param($stmt,"i",$pk);
mysqli_stmt_execute($stmt);
$res=mysqli_stmt_get_result($stmt);
$row=mysqli_fetch_assoc($res);

if(!$row){die("No record found");}

echo "<h3>Appointment Details</h3><table>";
foreach($row as $k=>$v){
  echo "<tr><th>".htmlspecialchars($k)."</th><td>".htmlspecialchars($v)."</td></tr>";
}
echo "</table>";
