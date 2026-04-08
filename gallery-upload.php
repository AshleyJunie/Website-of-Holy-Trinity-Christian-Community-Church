<?php
// PDO (same as your events uploader style)
$pdo = new PDO("mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4","root","",[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);

function resizeImage($sourcePath, $destPath, $newWidth = 1200, $newHeight = 1200) {
  [$w,$h,$type] = getimagesize($sourcePath);
  switch ($type) {
    case IMAGETYPE_JPEG: $src=imagecreatefromjpeg($sourcePath); break;
    case IMAGETYPE_PNG:  $src=imagecreatefrompng($sourcePath);  break;
    case IMAGETYPE_GIF:  $src=imagecreatefromgif($sourcePath);  break;
    default: return false;
  }
  $dst=imagecreatetruecolor($newWidth,$newHeight);
  if ($type==IMAGETYPE_PNG || $type==IMAGETYPE_GIF) {
    imagecolortransparent($dst, imagecolorallocatealpha($dst,0,0,0,127));
    imagealphablending($dst,false); imagesavealpha($dst,true);
  }
  imagecopyresampled($dst,$src,0,0,0,0,$newWidth,$newHeight,$w,$h);
  switch($type){
    case IMAGETYPE_JPEG: imagejpeg($dst,$destPath,90); break;
    case IMAGETYPE_PNG:  imagepng($dst,$destPath,8);   break;
    case IMAGETYPE_GIF:  imagegif($dst,$destPath);     break;
  }
  imagedestroy($src); imagedestroy($dst);
  return true;
}

function back($ok,$msg){
  header('Location: content-management_gallery.php?'.($ok?'toast=':'err=').urlencode($msg));
  exit;
}

if ($_SERVER['REQUEST_METHOD']!=='POST') back(false,'Invalid request.');

$title      = trim($_POST['title'] ?? '');
$month      = trim($_POST['month'] ?? '');
$album_type = trim($_POST['album_type'] ?? '');
$imgAlt     = trim($_POST['imgAlt'] ?? '');
$details    = trim($_POST['details'] ?? '');

if ($title==='' || $month==='' || $album_type==='' || $imgAlt==='' || $details==='') {
  back(false,'Please complete all fields.');
}

if (!isset($_FILES['imgSrc']) || $_FILES['imgSrc']['error']!==UPLOAD_ERR_OK) {
  back(false,'No image uploaded.');
}

$uploadFs = __DIR__."/uploads/gallery/";
$uploadWeb= "uploads/gallery/";
if (!is_dir($uploadFs)) mkdir($uploadFs,0777,true);

$tmp = $_FILES['imgSrc']['tmp_name'];
$ext = strtolower(pathinfo($_FILES['imgSrc']['name'], PATHINFO_EXTENSION));
$fname = time().'_'.bin2hex(random_bytes(4)).'.'.$ext;

if (!resizeImage($tmp, $uploadFs.$fname)) {
  back(false,'Unsupported image format.');
}

$stmt=$pdo->prepare("INSERT INTO gallery_table (title, month, imgSrc, imgAlt, details, album_type) VALUES (?,?,?,?,?,?)");
$stmt->execute([$title, $month, $uploadWeb.$fname, $imgAlt, $details, $album_type]);

back(true,'Gallery item added.');
