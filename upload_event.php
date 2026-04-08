<?php
// Database connection
$pdo = new PDO("mysql:host=localhost;dbname=htccc-data-base", "root", "");

// Resize function
function resizeImage($sourcePath, $destPath, $newWidth = 1000, $newHeight = 1500) {
    list($width, $height, $type) = getimagesize($sourcePath);

    // Create image resource based on file type
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
            return false; // unsupported format
    }

    // Create a blank canvas
    $dstImage = imagecreatetruecolor($newWidth, $newHeight);

    // Keep transparency for PNG/GIF
    if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
        imagecolortransparent($dstImage, imagecolorallocatealpha($dstImage, 0, 0, 0, 127));
        imagealphablending($dstImage, false);
        imagesavealpha($dstImage, true);
    }

    // Resize
    imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    // Save new file
    switch ($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($dstImage, $destPath, 90); // quality 90
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

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'] ?? '';
    $category = $_POST['category'] ?? '';
    $imgAlt = $_POST['imgAlt'] ?? '';
    $details = $_POST['details'] ?? '';

    // Handle file upload
    if (isset($_FILES['imgSrc']) && $_FILES['imgSrc']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = "uploads/events/"; // folder to save images
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true); // create folder if not exists
        }

        $fileTmp = $_FILES['imgSrc']['tmp_name'];
        $fileName = time() . "_" . basename($_FILES['imgSrc']['name']);
        $filePath = $uploadDir . $fileName;

        // Resize before saving
        if (resizeImage($fileTmp, $filePath)) {
            // Insert into DB
            $stmt = $pdo->prepare("INSERT INTO events_table (title, category, imgSrc, imgAlt, details) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$title, $category, $filePath, $imgAlt, $details]);

            echo "✅ Event uploaded successfully with resized image!";
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
  <title>Upload Event</title>
</head>
<body>
  <h2>Upload New Event</h2>
  <form action="upload_event.php" method="POST" enctype="multipart/form-data">
    <label>Title:</label><br>
    <input type="text" name="title" required><br><br>

    <label>Category:</label><br>
    <select name="category" required>
      <option value="upcoming">Upcoming</option>
      <option value="previous">Previous</option>
    </select><br><br>

    <label>Image (Poster):</label><br>
    <input type="file" name="imgSrc" accept="image/*" required><br><br>

    <label>Image Alt Text:</label><br>
    <input type="text" name="imgAlt" required><br><br>

    <label>Details:</label><br>
    <textarea name="details" rows="6" cols="40" required></textarea><br><br>

    <button type="submit">Upload Event</button>
  </form>
</body>
</html>
