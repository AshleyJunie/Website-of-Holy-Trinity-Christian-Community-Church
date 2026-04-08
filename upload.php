<?php
// Database connection
$host = "localhost";
$user = "root";
$pass = "";
$db   = "htccc-data-base"; 

$pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pass);

// Upload folder
$targetDir = "uploads/";
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true);
}

// If form submitted
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["image"])) {
    $fileName = basename($_FILES["image"]["name"]);
    $targetFile = $targetDir . $fileName;
    $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));

    // Allow only images
    $allowedTypes = ["jpg", "jpeg", "png", "gif"];
    if (!in_array($fileType, $allowedTypes)) {
        die("❌ Only JPG, JPEG, PNG & GIF files are allowed.");
    }

    // Prevent overwriting existing file
    if (file_exists($targetFile)) {
        $fileName = time() . "_" . $fileName;
        $targetFile = $targetDir . $fileName;
    }

    // Move file and save to DB
    if (move_uploaded_file($_FILES["image"]["tmp_name"], $targetFile)) {
        $stmt = $pdo->prepare("INSERT INTO content_management_table (img_file_name, img_file_path) VALUES (?, ?)");
        $stmt->execute([$fileName, $targetFile]);

        echo "✅ Image uploaded successfully! <a href='slider.php'>View Slider</a>";
    } else {
        echo "❌ Error uploading file.";
    }
}
?>

<!-- Upload Form -->
<h2>Upload Image for Slider</h2>
<form action="upload.php" method="post" enctype="multipart/form-data">
    <input type="file" name="image" required>
    <button type="submit">Upload</button>
</form>
