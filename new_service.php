<?php
// service.php
// ✅ Dynamic service page based on service_list table

// ini_set('display_errors', 1);
// error_reporting(E_ALL);

// ✅ Get service id from URL (recommended)
$serviceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ✅ Database connection
$pdo = new PDO(
  "mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4",
  "root",
  "",
  [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]
);

// ✅ If no id provided, load the first service (fallback)
if ($serviceId <= 0) {
  $stmtFirst = $pdo->query("SELECT service_id FROM service_list ORDER BY service_id ASC LIMIT 1");
  $firstRow = $stmtFirst->fetch();
  $serviceId = $firstRow ? (int)$firstRow['service_id'] : 0;
}

// ✅ Fetch service details
$service = null;
if ($serviceId > 0) {
  $stmt = $pdo->prepare("
    SELECT service_id, service_image, service_name, service_description
    FROM service_list
    WHERE service_id = :id
    LIMIT 1
  ");
  $stmt->execute([':id' => $serviceId]);
  $service = $stmt->fetch();
}

// ✅ Handle not found
if (!$service) {
  $service = [
    'service_id' => 0,
    'service_image' => '',
    'service_name' => 'SERVICE NOT FOUND',
    'service_description' => 'No data available for this service.'
  ];
}

/**
 * ✅ Support single OR multiple images:
 * - If your service_image stores ONE path: "uploads/img1.jpg"
 * - If you want slider: store multiple paths separated by "|" like:
 *   "uploads/img1.jpg|uploads/img2.jpg|uploads/img3.jpg"
 */
$images = [];
if (!empty($service['service_image'])) {
  $raw = trim($service['service_image']);

  // Split by "|" if multiple; otherwise single
  if (strpos($raw, '|') !== false) {
    $images = array_values(array_filter(array_map('trim', explode('|', $raw))));
  } else {
    $images = [$raw];
  }
}

// Page title
$pageTitle = $service['service_name'] ?: 'Service';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Service - <?php echo htmlspecialchars($pageTitle); ?></title>

  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/global-layout.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/global-layout.css'); ?>">

  <style>
    body {
      font-family: "Poppins", sans-serif;
      margin: 0;
      background-color: #fafafa;
      color: #333;
      overflow-x: hidden;
    }

    #service-page {
      text-align: center;
      padding: 0 16px;
    }

    .service-header {
      font-size: 2rem;
      color: #4b2e05;
      letter-spacing: 3px;
      margin: 20px 0 10px;
      font-weight: 600;
    }

    .service-container {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      align-items: center;
      gap: 40px;
      max-width: 1100px;
      margin: 5px auto 40px;
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

    .service-header-txt {
      font-size: 1.8rem;
      color: #654321;
      border-left: 5px solid #b28b45;
      padding-left: 15px;
      margin-bottom: 20px;
      font-weight: 600;
      text-transform: uppercase;
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
      white-space: pre-line;
    }

    .service-image-container {
      flex: 1;
      min-width: 300px;
      display: flex;
      justify-content: center;
      align-items: center;
      position: relative;
      min-height: 280px;
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

    .service-fallback {
      font-size: 1rem;
      color: #777;
      line-height: 1.6;
      margin-top: 10px;
      text-align: center;
    }

    @media (max-width: 992px) {
      .service-container {
        flex-direction: column;
        padding: 30px;
      }
      .service-text {
        text-align: center;
      }
      .service-header-txt {
        border-left: none;
        border-bottom: 3px solid #b28b45;
        padding-bottom: 10px;
        display: inline-block;
      }
      .service-image-container {
        width: 100%;
        min-height: unset;
      }
      .service-image-container img {
        width: 100%;
        height: auto;
        position: relative;
      }
    }

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

<section id="service-page">
  <h2 class="service-header">SERVICES</h2>

  <div class="service-container">
    <div class="service-text">
      <h3 class="service-header-txt"><?php echo htmlspecialchars($service['service_name']); ?></h3>

      <?php if (!empty($service['service_description'])): ?>
        <p class="service-description"><?php echo htmlspecialchars($service['service_description']); ?></p>
      <?php else: ?>
        <p class="service-fallback">No description available for this service yet.</p>
      <?php endif; ?>
    </div>

    <div class="service-image-container">
      <?php if (!empty($images)): ?>
        <?php foreach ($images as $idx => $imgPath): ?>
          <img
            src="<?php echo htmlspecialchars($imgPath); ?>"
            alt="<?php echo htmlspecialchars($service['service_name']); ?> image <?php echo $idx + 1; ?>"
            class="<?php echo $idx === 0 ? 'active' : ''; ?>"
          >
        <?php endforeach; ?>
      <?php else: ?>
        <!-- Optional placeholder -->
        <img
          src="/HTCCC-SYSTEM/image/placeholder-service.jpg"
          alt="No image available"
          class="active"
        >
      <?php endif; ?>
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

  // Rotate only if multiple images
  if (images.length > 1) {
    setInterval(showNextImage, 4000);
  }
</script>

</body>
</html>   
