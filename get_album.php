<?php
// No spaces or HTML above!
$pdo = new PDO("mysql:host=localhost;dbname=htccc-data-base;charset=utf8", "root", "");
$album = $_GET['album'] ?? '';

if ($album === 'Unnamed Album' || $album === '' || $album === null) {
    // Treat empty/NULL as "Unnamed Album"
    $stmt = $pdo->prepare("
        SELECT imgSrc, imgAlt
        FROM gallery_table
        WHERE (album_type IS NULL OR album_type = '')
        ORDER BY created_at DESC
    ");
    $stmt->execute();
} else {
    $stmt = $pdo->prepare("
        SELECT imgSrc, imgAlt
        FROM gallery_table
        WHERE album_type = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$album]);
}

$images = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($images);
exit;
