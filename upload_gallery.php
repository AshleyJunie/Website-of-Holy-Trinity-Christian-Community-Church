<?php
// Database connection
$pdo = new PDO("mysql:host=localhost;dbname=htccc-data-base", "root", "");

// Resize function
function resizeImage($sourcePath, $destPath, $newWidth = 800, $newHeight = 600) {
    list($width, $height, $type) = getimagesize($sourcePath);

    // Create image resource based on type
    switch ($type) {
        case IMAGETYPE_JPEG:
            $srcImage = imagecreatefromjpeg($sourcePath);
            break;
        case IMAGETYPE_PNG:
            $srcImage = imagecreatefrompng($sourcePath);
            break;
        case IMAGETYPE_GIF:
            $srcImage = imagecreatefromgif($sourcePath);
            break;
        default:
            return false; // unsupported type
    }

    // Create a new canvas
    $dstImage = imagecreatetruecolor($newWidth, $newHeight);

    // Keep transparency for PNG/GIF
    if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
        imagecolortransparent($dstImage, imagecolorallocatealpha($dstImage, 0, 0, 0, 127));
        imagealphablending($dstImage, false);
        imagesavealpha($dstImage, true);
    }

    // Resize
    imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    // Save image
    switch ($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($dstImage, $destPath, 90);
            break;
        case IMAGETYPE_PNG:
            imagepng($dstImage, $destPath, 8);
            break;
        case IMAGETYPE_GIF:
            imagegif($dstImage, $destPath);
            break;
    }

    // Free memory
    imagedestroy($srcImage);
    imagedestroy($dstImage);

    return true;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'] ?? '';
    $month = $_POST['month'] ?? '';
    $imgAlt = $_POST['imgAlt'] ?? '';
    $details = $_POST['details'] ?? '';
    $albumType = $_POST['album_type'] ?? ''; // ✅ new field

    if (isset($_FILES['imgSrc']) && $_FILES['imgSrc']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = "uploads/gallery/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileTmp = $_FILES['imgSrc']['tmp_name'];
        $fileName = time() . "_" . basename($_FILES['imgSrc']['name']);
        $filePath = $uploadDir . $fileName; 

        // Resize before saving
        if (resizeImage($fileTmp, $filePath)) {
            $stmt = $pdo->prepare("INSERT INTO gallery_table (title, month, album_type, imgSrc, imgAlt, details, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$title, $month, $albumType, $filePath, $imgAlt, $details]);

            echo "✅ Gallery item uploaded successfully with album type!";
        } else {
            echo "❌ Unsupported image format.";
        }
    } else {
        echo "❌ No file uploaded or upload error.";
    }
}
?>

<!-- HTML Form -->
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Upload Gallery Item</title>
</head>
<body>
<h2>Upload New Gallery Item</h2>
<form action="upload_gallery.php" method="POST" enctype="multipart/form-data">
    <label>Title:</label><br>
    <input type="text" name="title" required><br><br>

    <label>Month:</label><br>
    <select name="month" required>
    <option value="January">January</option>
    <option value="February">February</option>
    <option value="March">March</option>
    <option value="April">April</option>
    <option value="May">May</option>
    <option value="June">June</option>
    <option value="July">July</option>
    <option value="August">August</option>
    <option value="September">September</option>
    <option value="October">October</option>
    <option value="November">November</option>
    <option value="December">December</option>
    </select><br><br>

    <!-- ✅ New Album Type Field -->
    <label>Album Type:</label><br>
    <input type="text" name="album_type" placeholder="e.g. VBS, Sportsfest, Graduation" required><br><br>

    <label>Image (Photo):</label><br>
    <input type="file" name="imgSrc" accept="image/*" required><br><br>

    <label>Image Alt Text:</label><br>
    <input type="text" name="imgAlt" required><br><br>

    <label>Details:</label><br>
    <textarea name="details" rows="6" cols="40" required></textarea><br><br>

    <button type="submit">Upload Gallery Item</button>
</form>
</body>
</html>
