<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Service - Preaching of God's Word</title>
  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/global-layout.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/global-layout.css'); ?>">
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/service-preach.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/service-preach.css'); ?>">

  <style>
    body {
      font-family: "Poppins", sans-serif;
      margin: 0;
      background-color: #fafafa;
      color: #333;
      overflow-x: hidden;
    }

    #service-preach {
      text-align: center;
    }

    .service-header {
      font-size: 2rem;
      color: #4b2e05;
      letter-spacing: 3px;
      margin-bottom: 10px;
      font-weight: 600;
    }

    .service-container {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      align-items: center;
      gap: 40px;
      max-width: 1100px;
      margin: 5px auto;
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
      font-size: 1rem;
      color: #444;
      line-height: 1.7;
      margin-bottom: 15px;
      text-align: justify;
      opacity: 0;
      animation: fadeUp 1s ease forwards;
      animation-delay: 0.4s;
    }

    .service-image-container {
      flex: 1;
      min-width: 300px;
      display: flex;
      justify-content: center;
      align-items: center;
      position: relative;
    }

    .service-image-container img {
      width: 100%;
      height: auto;
      border-radius: 15px;
      object-fit: cover;
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
      opacity: 0;
      position: absolute;
      transition: opacity 1.2s ease-in-out;
    }

    .service-image-container img.active {
      opacity: 1;
      position: relative;
    }

    @media (max-width: 992px) {
      .service-container {
        flex-direction: column;
        padding: 30px;
      }
      .service-text {
        text-align: center;
      }
      .service-header-txt-preach {
        border-left: none;
        border-bottom: 3px solid #b28b45;
        padding-bottom: 10px;
        display: inline-block;
      }
      .service-image-container {
        width: 100%;
      }
      .service-image-container img {
        width: 100%;
        height: auto;
        position: relative;
      }
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @keyframes slideInLeft {
      from { opacity: 0; transform: translateX(-50px); }
      to { opacity: 1; transform: translateX(0); }
    }

    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }
  </style>
</head>

<body>
<header>
  <?php include $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/includes/navigation.php'; ?>
</header>

<?php
// ✅ Database connection
$pdo = new PDO("mysql:host=localhost;dbname=htccc-data-base", "root", "");

// ✅ Get images
$stmtImage = $pdo->prepare("
  SELECT img_file_path 
  FROM content_management_table 
  WHERE content_type = 'Preach' AND img_file_path != '0'
");
$stmtImage->execute();
$imageRows = $stmtImage->fetchAll(PDO::FETCH_ASSOC);

// ✅ Get captions
$stmtText = $pdo->prepare("
  SELECT img_caption 
  FROM content_management_table 
  WHERE content_type = 'Preach' AND img_caption IS NOT NULL
");
$stmtText->execute();
$textRows = $stmtText->fetchAll(PDO::FETCH_ASSOC);
?>

<section id="service-preach">
  <h2 class="service-header">SERVICES</h2>

  <div class="service-container">
    <div class="service-text">
      <h3 class="service-header-txt-preach">PREACHING OF GOD'S WORD</h3>

      <?php foreach ($textRows as $row): ?>
        <p class="service-description">
          <?php echo nl2br(htmlspecialchars($row['img_caption'])); ?>
        </p>
      <?php endforeach; ?>
    </div>

    <div class="service-image-container">
      <?php foreach ($imageRows as $index => $image): ?>
        <img src="<?php echo htmlspecialchars($image['img_file_path']); ?>"
             alt="Preaching Service Image <?php echo $index + 1; ?>"
             class="<?php echo $index === 0 ? 'active' : ''; ?>">
      <?php endforeach; ?>
    </div>
  </div>
</section>

<footer>
  <?php include $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/includes/footer.php'; ?>
</footer>

<script>
  const images = document.querySelectorAll('.service-image-container img');
  let currentIndex = 0;

  function showNextImage() {
    if (images.length === 0) return;
    images[currentIndex].classList.remove('active');
    currentIndex = (currentIndex + 1) % images.length;
    images[currentIndex].classList.add('active');
  }

  if (images.length > 1) {
    setInterval(showNextImage, 4000);
  }
</script>

</body>
</html> 
