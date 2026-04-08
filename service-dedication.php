<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Service - Dedication</title>
  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/global-layout.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/global-layout.css'); ?>">
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/service-dedication.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/service-dedication.css'); ?>">

  <style>
    body {
      font-family: "Poppins", sans-serif;
      margin: 0;
      background-color: #fafafa;
      color: #333;
      overflow-x: hidden;
    }

    #service-dedication {
      padding: 60px 20px;
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
      align-items: flex-start;
      gap: 40px;
      max-width: 1100px;
      margin: 40px auto;
      background: #fff;
      border-radius: 15px;
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
      padding: 40px;
    }

    .service-text {
      flex: 1;
      min-width: 300px;
      text-align: left;
      animation: slideInLeft 1s ease;
      z-index: 2;
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
        text-align: center;
      }

      .service-text {
        text-align: center;
        margin-bottom: 20px;
      }

      .service-header-txt-preach {
        border-left: none;
        border-bottom: 3px solid #b28b45;
        padding-bottom: 10px;
        display: inline-block;
      }

      .background {
        justify-content: center;
      }

      .service-image-container img {
        width: 100%;
        height: auto;
        position: relative;
      }
    }

    @keyframes slideInLeft {
      from { opacity: 0; transform: translateX(-50px); }
      to { opacity: 1; transform: translateX(0); }
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

// Get all Dedication content
$stmt = $pdo->prepare("SELECT img_caption, img_file_path 
                      FROM content_management_table 
                      WHERE content_type = 'Dedication' 
                      AND img_file_path != '0'");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Separate captions & images
$captions = [];
$images = [];
foreach ($rows as $r) {
    if (!empty($r['img_file_path'])) $images[] = $r['img_file_path'];
    if (!empty($r['img_caption'])) $captions[] = $r['img_caption'];
}
?>

<section id="service-dedication">
  <h2 class="service-header">SERVICES</h2>

  <div class="service-container">
    <div class="service-text">
      <h3 class="service-header-txt-preach">DEDICATION</h3>

      <?php foreach ($captions as $cap): ?>
        <p class="service-description">
          <?php echo nl2br(htmlspecialchars($cap)); ?>
        </p>
      <?php endforeach; ?>

      <div class="background">
<a href="appoint-page.php?service=Dedication" class="read-more-btn-baptism">
  APPLY FOR DEDICATION
</a>
      </div>
    </div>

    <!-- Slideshow -->
    <div class="service-image-container">
      <?php foreach ($images as $index => $img): ?>
        <img src="<?php echo htmlspecialchars($img); ?>"
             alt="Dedication Image <?php echo $index + 1; ?>"
             class="<?php echo $index === 0 ? 'active' : ''; ?>">
      <?php endforeach; ?>
    </div>

  </div>
</section>

<footer>
  <?php include $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/includes/footer.php'; ?>
</footer>

<script>
// Image fade slideshow
const imgs = document.querySelectorAll('.service-image-container img');
let idx = 0;

function nextImg() {
  imgs[idx].classList.remove('active');
  idx = (idx + 1) % imgs.length;
  imgs[idx].classList.add('active');
}

if (imgs.length > 1) setInterval(nextImg, 4000);
</script>

</body>
</html>