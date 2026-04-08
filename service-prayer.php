<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service - Prayer Request</title>
    <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/HTCCC-SYSTEM/css/service-prayer.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/service-prayer.css'); ?>">
    <link rel="stylesheet" href="/HTCCC-SYSTEM/css/global-layout.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/global-layout.css'); ?>">

    <style>
      /* Shared structure + footer fix */
      #service-preach {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        gap: 30px;
        padding: 80px 0 140px;
        height: auto;
      }

      #service-preach h2.service-header {
        color: #02084b;
        font-size: 60px;
      }

      .service-container {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        align-items: flex-start;
        gap: 40px;
        max-width: 1100px;
        margin: 40px auto;
        background: #fff;
        border-radius: 15px;
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
        padding: 40px;
        animation: fadeIn 1s ease-in-out;
      }

      .service-text {
        flex: 1;
        min-width: 300px;
        text-align: left;
        animation: slideInLeft 1s ease;
      }

      .service-header-txt-preach {
        font-size: 1.8rem;
        color: #654321;
        border-left: 5px solid #b28b45;
        padding-left: 15px;
        margin-bottom: 20px;
        font-weight: 600;
      }

      .service-description {
        text-align: justify;
        font-size: 1rem;
        color: #444;
        line-height: 1.7;
        margin-bottom: 15px;
        opacity: 0;
        animation: fadeUp 1s ease forwards;
        animation-delay: 0.4s;
      }

      .background {
        display: flex;
        justify-content: center;
        margin-top: 30px;
        width: 100%;
      }

      .read-more-btn-baptism {
        background: darkblue;
        color: white;
        padding: 12px 25px;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 600;
        transition: background 0.3s ease, transform 0.3s ease;
      }

      .read-more-btn-baptism:hover {
        background: blue;
        transform: scale(1.05);
      }

      .service-image-container {
        flex: 1;
        min-width: 300px;
        position: relative;
        display: flex;
        justify-content: center;
        align-items: center;
      }

      .service-image img {
        max-width: 100%;
        height: auto;
        border-radius: 15px;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
      }

      @media (max-width: 992px) {
        .service-container {
          flex-direction: column;
          text-align: center;
        }
      }

      /* 🔥 Animations */
      @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to   { opacity: 1; transform: translateY(0); }
      }

      @keyframes slideInLeft {
        from { opacity: 0; transform: translateX(-50px); }
        to   { opacity: 1; transform: translateX(0); }
      }

      @keyframes fadeUp {
        from { opacity: 0; transform: translateY(20px); }
        to   { opacity: 1; transform: translateY(0); }
      }
    </style>
</head>
<body>

<header>
  <?php include $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/includes/navigation.php'; ?>
</header>

<?php
// Connect to DB
$pdo = new PDO("mysql:host=localhost;dbname=htccc-data-base", "root", "");

// Get prayer services
$stmt = $pdo->prepare("
  SELECT img_caption, img_file_path
  FROM content_management_table
  WHERE content_type = 'Prayer'
  ORDER BY contentID ASC
");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Default image
$image = 'image/service-holder.jpg';

// Build captions + assign first valid image
$captions = [];

foreach ($rows as $row) {
    if (!empty($row['img_file_path']) && $row['img_file_path'] !== "0") {
        $image = $row['img_file_path'];
    }
    if (!empty($row['img_caption'])) {
        $captions[] = $row['img_caption'];
    }
}
?>

<section id="service-preach">
    <h2 class="service-header">SERVICES</h2>

    <div class="service-container">

        <div class="service-text">
            <h3 class="service-header-txt-preach">PRAYER REQUEST</h3>

            <?php foreach ($captions as $cap): ?>
                <p class="service-description">
                    <?php echo nl2br(htmlspecialchars($cap)); ?>
                </p>
            <?php endforeach; ?>

            <div class="background">
               <a href="form-prayer.php" class="read-more-btn-baptism">
                 REQUEST A PRAYER
               </a>
            </div>
        </div>

        <div class="service-image-container">
            <div class="service-image">
                <img src="<?php echo htmlspecialchars($image); ?>" alt="Prayer Request">
            </div>
        </div>

    </div>
</section>

<footer>
  <?php include $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/includes/footer.php'; ?>
</footer>

</body>
</html>