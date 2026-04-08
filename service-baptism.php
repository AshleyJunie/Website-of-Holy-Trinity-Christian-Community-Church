<?php
session_start();

/* =========================================================
  ✅ DB CONNECTION (do this BEFORE output)
========================================================= */
try {
  $pdo = new PDO("mysql:host=localhost;dbname=htccc-data-base", "root", "");
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
  die("Database connection failed.");
}

/* =========================================================
  ✅ GET IMAGES + CAPTIONS FOR BAPTISM (safe before output)
========================================================= */
$stmt = $pdo->prepare("SELECT img_caption, img_file_path
                      FROM content_management_table
                      WHERE content_type = 'Baptism'
                      AND img_file_path != '0'");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$captions = [];
$images = [];
foreach ($rows as $r) {
  if (!empty($r['img_file_path'])) $images[] = $r['img_file_path'];
  if (!empty($r['img_caption'])) $captions[] = $r['img_caption'];
}

/* =========================================================
  ✅ BAPTISM APPLY GATE
  - if not logged in: show swal, then redirect to login via JS
  - if logged in + allowed: header redirect to form (NOW SAFE)
========================================================= */
$swal = null;
$self = $_SERVER['PHP_SELF'];
$logged_in_individual_id = $_SESSION['individual_id'] ?? null;

if (isset($_GET['apply']) && $_GET['apply'] == '1') {

  // NOT LOGGED IN -> show swal then go login
  if (!$logged_in_individual_id) {

    $returnUrl = $self . '?apply=1';
    $loginUrl  = "/HTCCC-SYSTEM/all_log-in.php?next=" . urlencode($returnUrl);

    $swal = [
      "icon" => "warning",
      "title" => "Authentication Required",
      "text"  => "You must be logged in to your account in order to apply for baptism.",
      "redirect" => $loginUrl
    ];

  } else {

    // LOGGED IN -> check user status
    $check = $pdo->prepare("
      SELECT individual_baptised, baptism_verification
      FROM individual_table
      WHERE individual_id = :id
      LIMIT 1
    ");
    $check->execute([":id" => $logged_in_individual_id]);
    $user = $check->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
      $swal = [
        "icon" => "error",
        "title" => "Account Record Not Found",
        "text" => "We were unable to locate your account information. Please contact the church administrator for assistance."
      ];
    } else {
      $baptised = $user['individual_baptised'] ?? 'No';
      $verification = $user['baptism_verification'] ?? 'NonVerified';

      // Rule 1
      if ($baptised === 'Yes') {
        $swal = [
          "icon" => "info",
          "title" => "Baptism Record Confirmed",
          "text" => "Our records indicate that you have already received the Sacrament of Baptism. Submitting a new application is therefore not permitted."
        ];
      }
      // Rule 2
      else if ($verification === 'Verified') {
        $swal = [
          "icon" => "info",
          "title" => "Baptism Status Verified",
          "text" => "Your baptism record has already been verified in our system. No further application is required at this time."
        ];
      }
      // Rule 3
      else if ($verification === 'Pending') {
        $swal = [
          "icon" => "warning",
          "title" => "Application Under Review",
          "text" => "You have already submitted a baptism application. Please allow the church administration time to review and process your request."
        ];
      }
      // ✅ Allowed -> redirect to form (NOW SAFE because no output yet)
      else {
        header("Location: form-baptism.php");
        exit;
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Service - Baptism</title>
  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/global-layout.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/global-layout.css'); ?>">
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/service-baptism.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/service-baptism.css'); ?>">

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    body {
      font-family: "Poppins", sans-serif;
      margin: 0;
      background-color: #fafafa;
      color: #333;
      overflow-x: hidden;
    }

    #service-preach {
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
      margin-left: -100px;
      color: white;
      padding: 12px 25px;
      border-radius: 8px;
      text-decoration: none;
      font-weight: 600;
      transition: background 0.3s ease, transform 0.3s ease;
      cursor: pointer;
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
      .service-container { flex-direction: column; text-align: center; padding: 25px; }
      .service-text { text-align: center; margin-bottom: 20px; }
      .service-header-txt-preach { border-left: none; border-bottom: 3px solid #b28b45; padding-bottom: 10px; display: inline-block; }
      .background { justify-content: center; }
      .service-image-container img { width: 100%; height: auto; position: relative; }
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

<section id="service-preach">
  <h2 class="service-header">SERVICES</h2>

  <div class="service-container">
    <div class="service-text">
      <h3 class="service-header-txt-preach">BAPTISM</h3>

      <?php foreach ($captions as $cap): ?>
        <p class="service-description"><?php echo nl2br(htmlspecialchars($cap)); ?></p>
      <?php endforeach; ?>

      <div class="background" id="background">
        <a href="<?php echo htmlspecialchars($self); ?>?apply=1" class="read-more-btn-baptism">
          APPLY FOR BAPTISM
        </a>
      </div>
    </div>

    <div class="service-image-container">
      <?php foreach ($images as $index => $img): ?>
        <img src="<?php echo htmlspecialchars($img); ?>"
             alt="Baptism Image <?php echo $index + 1; ?>"
             class="<?php echo $index === 0 ? 'active' : ''; ?>">
      <?php endforeach; ?>
    </div>
  </div>
</section>

<footer>
  <?php include $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/includes/footer.php'; ?>
</footer>

<script>
const imgs = document.querySelectorAll('.service-image-container img');
let idx = 0;

function nextImg() {
  if (imgs.length === 0) return;
  imgs[idx].classList.remove('active');
  idx = (idx + 1) % imgs.length;
  imgs[idx].classList.add('active');
}
if (imgs.length > 1) setInterval(nextImg, 4000);
</script>

<?php if ($swal): ?>
<script>
Swal.fire({
  icon: <?php echo json_encode($swal['icon']); ?>,
  title: <?php echo json_encode($swal['title']); ?>,
  text: <?php echo json_encode($swal['text']); ?>,
  confirmButtonText: "Understood"
}).then(() => {
  const redirectUrl = <?php echo json_encode($swal['redirect'] ?? null); ?>;
  if (redirectUrl) {
    window.location.href = redirectUrl; // ✅ go to all_log-in.php after Understood
    return;
  }

  // optional: remove ?apply=1 if it was just a message
  if (window.history.replaceState) {
    window.history.replaceState({}, document.title, "<?php echo htmlspecialchars($self); ?>");
  }
});
</script>
<?php endif; ?>

</body>
</html>
