<?php
// ================== PHP (top of file) ==================
if (session_status() === PHP_SESSION_NONE) session_start();

// Considered logged-in if ANY role is present (UI hint only; ministry-form.php must still guard server-side)
$isLoggedIn = (
  isset($_SESSION['individual_id']) ||
  isset($_SESSION['admin_id']) ||
  isset($_SESSION['pastor_id']) ||
  isset($_SESSION['secretary_id'])
);

// DB connect
$pdo = new PDO("mysql:host=localhost;dbname=htccc-data-base;charset=utf8", "root", "");

// Fetch all Junior Christ Ambassador data
$stmt = $pdo->prepare("SELECT img_caption, img_file_path, join_desc 
                       FROM content_management_table 
                       WHERE content_type = 'Junior' 
                       ORDER BY contentID ASC");
$stmt->execute();
$junior = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Default image
$image = 'image/ministries-junior.jpg';

// Collect captions + join_desc
$captions = [];
$joinDesc = '';
foreach ($junior as $row) {
    if (!empty($row['img_file_path']) && $row['img_file_path'] !== '0') {
        $image = $row['img_file_path']; // DB image if exists
    }
    if (!empty($row['img_caption'])) {
        $captions[] = $row['img_caption'];
    }
    if (!empty($row['join_desc'])) {
        $joinDesc = $row['join_desc']; // Take join description
    }
}

// Always include the ministry in the target
$nextTarget   = 'ministry-form.php?ministry=' . rawurlencode('Junior Christ Ambassador');
$fallbackHref = $isLoggedIn
  ? $nextTarget
  : ('all_log-in.php?next=' . urlencode($nextTarget));

/* get age from individual_table for Junior-only rule */
$userAge = null;

if (!empty($_SESSION['individual_id'])) {
    try {
        $stmtInd = $pdo->prepare("
            SELECT individual_birthday
            FROM individual_table
            WHERE individual_id = :id
            LIMIT 1
        ");
        $stmtInd->execute([':id' => $_SESSION['individual_id']]);
        $indRow = $stmtInd->fetch(PDO::FETCH_ASSOC);

        if ($indRow && !empty($indRow['individual_birthday'])) {
            // Trim to date part (handles YYYY-mm-dd or datetime)
            $birthRaw  = substr((string)$indRow['individual_birthday'], 0, 10);
            $birthDate = DateTime::createFromFormat('Y-m-d', $birthRaw);
            if ($birthDate) {
                $today   = new DateTime('today');
                $userAge = (int)$birthDate->diff($today)->y;
            }
        }
    } catch (Exception $e) {
        // Silent fail: if something goes wrong, we simply won't enforce age on client
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ministry - Junior Ambassador</title>
    <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/HTCCC-SYSTEM/css/global-layout.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/global-layout.css'); ?>">
    <link rel="stylesheet" href="/HTCCC-SYSTEM/css/ministries-junior-page.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/ministries-junior-page.css'); ?>">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
      const IS_LOGGED_IN = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
      const NEXT_URL     = <?php echo json_encode($nextTarget); ?>;
      const USER_AGE     = <?php echo ($userAge !== null ? (int)$userAge : 'null'); ?>;
    </script>

    <!-- Layout/style copied from service-preach design -->
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
        color: #02084b;
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

      .how-to-join {
        margin-top: 15px;
        font-size: 0.95rem;
        color: #444;
      }

      .how-to-join strong {
        display: inline-block;
        margin-bottom: 5px;
        color: #02084b;
      }

      .background {
        margin-top: 20px;
      }

      .service-image-container {
        flex: 1;
        min-width: 300px;
        display: flex;
        justify-content: center;
        align-items: center;
      }

      .service-image {
        width: 100%;
      }

      .service-image img {
        width: 100%;
        height: auto;
        border-radius: 15px;
        object-fit: cover;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
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

<section id="service-preach">
    <!-- Match preach pattern: big header + card + subheading -->
    <h2 class="service-header">MINISTRIES</h2>

    <div class="service-container">
        <div class="service-text">
            <h3 class="service-header-txt-preach">JUNIOR CHRIST AMBASSADOR</h3>

            <!-- MOBILE/TABLET IMAGE: directly under the header -->
            <div class="service-image-container mobile-only">
              <div class="service-image">
                <img src="<?php echo htmlspecialchars($image); ?>" alt="Junior Christ Ambassador Ministry">
              </div>
            </div>

            <?php foreach ($captions as $caption): ?>
                <p class="service-description">
                    <?php echo nl2br(htmlspecialchars($caption)); ?>
                </p>
            <?php endforeach; ?>

            <?php if (!empty($joinDesc)): ?>
            <div class="how-to-join">
                <strong>How to join?</strong>
                <p class="service-description">
                    <?php echo nl2br(htmlspecialchars($joinDesc)); ?>
                </p>
            </div>
            <?php endif; ?>

            <div class="background" id="background">
                <!-- Progressive fallback:
                     - If logged in: goes straight to the form
                     - If NOT logged in: goes to login with next=ministry-form.php?ministry=Junior%20Christ%20Ambassador -->
                <a href="<?php echo htmlspecialchars($fallbackHref); ?>"
                   class="read-more-btn-baptism"
                   id="joinNowBtn">
                   JOIN NOW!
                </a>
            </div>
        </div>

        <!-- DESKTOP IMAGE: right side -->
        <div class="service-image-container desktop-only">
            <div class="service-image">
                <img src="<?php echo htmlspecialchars($image); ?>" alt="Junior Christ Ambassador Ministry" id="service-img">
            </div>
        </div>
    </div>
</section>

<footer>
  <?php include $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/includes/footer.php'; ?>
</footer>

<!-- SweetAlert gating for JOIN NOW! (NOT LOGGED IN) -->
<script>
(function(){
  const btn = document.getElementById('joinNowBtn');
  if (!btn) return;

  btn.addEventListener('click', function(e){
    // If logged in → allow normal navigation (straight to form)
    if (IS_LOGGED_IN) return;

    e.preventDefault();
    Swal.fire({
      icon: 'info',
      title: 'Please sign in first',
      text: 'You need to log in before joining this ministry.',
      showCancelButton: true,
      confirmButtonText: 'Go to Login',
      cancelButtonText: 'Cancel'
    }).then((res) => {
      if (res.isConfirmed) {
        window.location.href = btn.getAttribute('href');
      }
    });
  });
})();
</script>

<!-- Junior-only age gating (LOGGED-IN ONLY) -->
<script>
(function(){
  const btn = document.getElementById('joinNowBtn');
  if (!btn) return;

  btn.addEventListener('click', function(e){
    // Only apply this logic to already-logged-in users
    if (!IS_LOGGED_IN) {
      return;
    }

    const ageParsed = (USER_AGE === null || USER_AGE === undefined)
      ? NaN
      : parseInt(USER_AGE, 10);

    // For Junior Christ Ambassador:
    // ✅ Allow minors (age < 18)
    // ⛔ Block adults (age >= 18)
    if (!Number.isNaN(ageParsed) && ageParsed >= 18) {
      e.preventDefault();
      Swal.fire({
        icon: 'warning',
        title: 'Junior Ministry Only',
        text: 'This ministry is for juniors only. Adults are not allowed to join this ministry.'
      });
      return;
    }
  });
})();
</script>

</body>
</html>