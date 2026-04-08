<?php
// --- DB (PDO for upload logic, as in your reference) ---
$pdo = new PDO("mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4", "root", "", [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// --- Resize helper (same idea as your reference) ---
function resizeImage($sourcePath, $destPath, $newWidth = 1000, $newHeight = 1500) {
    [$width, $height, $type] = getimagesize($sourcePath);
    switch ($type) {
        case IMAGETYPE_JPEG: $src = imagecreatefromjpeg($sourcePath); break;
        case IMAGETYPE_PNG:  $src = imagecreatefrompng($sourcePath);  break;
        case IMAGETYPE_GIF:  $src = imagecreatefromgif($sourcePath);  break;
        default: return false;
    }
    $dst = imagecreatetruecolor($newWidth, $newHeight);
    if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
        imagecolortransparent($dst, imagecolorallocatealpha($dst, 0,0,0,127));
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    imagecopyresampled($dst, $src, 0,0,0,0, $newWidth,$newHeight, $width,$height);
    switch ($type) {
        case IMAGETYPE_JPEG: imagejpeg($dst, $destPath, 90); break;
        case IMAGETYPE_PNG:  imagepng($dst, $destPath, 8);   break;
        case IMAGETYPE_GIF:  imagegif($dst, $destPath);       break;
    }
    imagedestroy($src); imagedestroy($dst);
    return true;
}

function redirect_with($ok, $msg) {
    $param = $ok ? 'toast' : 'err';
    header("Location: content-management_events.php?$param=".urlencode($msg));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect_with(false, 'Invalid request.');

$title    = trim($_POST['title']    ?? '');
$category = trim($_POST['category'] ?? '');
$imgAlt   = trim($_POST['imgAlt']   ?? '');
$details  = trim($_POST['details']  ?? '');

if ($title === '' || $category === '' || $imgAlt === '' || $details === '') {
    redirect_with(false, 'Please complete all fields.');
}

if (!isset($_FILES['imgSrc']) || $_FILES['imgSrc']['error'] !== UPLOAD_ERR_OK) {
    redirect_with(false, 'No image uploaded.');
}

$uploadDir = __DIR__ . "/uploads/events/";
$publicDir = "uploads/events/"; // path saved to DB (relative for <img src>)
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

$tmp      = $_FILES['imgSrc']['tmp_name'];
$ext      = pathinfo($_FILES['imgSrc']['name'], PATHINFO_EXTENSION);
$fname    = time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
$destFs   = $uploadDir . $fname;   // filesystem path
$destWeb  = $publicDir . $fname;   // path stored in DB

if (!resizeImage($tmp, $destFs)) {
    redirect_with(false, 'Unsupported or corrupted image.');
}

$stmt = $pdo->prepare("INSERT INTO events_table (title, category, imgSrc, imgAlt, details) VALUES (?, ?, ?, ?, ?)");
$stmt->execute([$title, $category, $destWeb, $imgAlt, $details]);

redirect_with(true, 'Event added successfully.');
