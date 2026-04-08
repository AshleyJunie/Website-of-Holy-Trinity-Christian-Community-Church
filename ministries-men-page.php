<?php
// ================== PHP (top of file) ==================
if (session_status() === PHP_SESSION_NONE) session_start();

// Considered logged-in if ANY role is present (for UI only; the form itself should still have its own guard)
$isLoggedIn = (
  isset($_SESSION['individual_id']) ||
  isset($_SESSION['admin_id']) ||
  isset($_SESSION['pastor_id']) ||
  isset($_SESSION['secretary_id'])
);

// --- FLASH: one-time success alert after login (optional UX) ---
$showLoginSuccess = !empty($_SESSION['just_logged_in']);
if ($showLoginSuccess) unset($_SESSION['just_logged_in']);

// DB for content
$pdo = new PDO("mysql:host=localhost;dbname=htccc-data-base;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// This page's ministry label
$pageMinistryLabel = "Men Ministry";

// Fetch all Men’s Ministry data
$stmt = $pdo->prepare("SELECT img_caption, img_file_path, join_desc 
                       FROM content_management_table 
                       WHERE content_type = 'Men' 
                       ORDER BY contentID ASC");
$stmt->execute();
$mens = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Default image
$image = 'image/ministries-men-latest.jpg';

// Collect captions + join_desc
$captions = [];
$joinDesc = '';
foreach ($mens as $row) {
    if (!empty($row['img_file_path']) && $row['img_file_path'] !== '0') {
        $image = $row['img_file_path']; // Use DB image if set
    }
    if (!empty($row['img_caption'])) {
        $captions[] = $row['img_caption'];
    }
    if (!empty($row['join_desc'])) {
        $joinDesc = $row['join_desc']; // Take join description
    }
}

// Always include the ministry in the target
$nextTarget   = 'ministry-form.php?ministry=' . rawurlencode($pageMinistryLabel);
$fallbackHref = $isLoggedIn
  ? $nextTarget
  : ('all_log-in.php?next=' . urlencode($nextTarget));

/* ================== Get gender & age from individual_table ================== */
$userGender = null;
$userAge    = null;

if (!empty($_SESSION['individual_id'])) {
    try {
        $stmtInd = $pdo->prepare("
            SELECT individual_gender, individual_birthday
            FROM individual_table
            WHERE individual_id = :id
            LIMIT 1
        ");
        $stmtInd->execute([':id' => $_SESSION['individual_id']]);
        $indRow = $stmtInd->fetch(PDO::FETCH_ASSOC);

        if ($indRow) {
            if (!empty($indRow['individual_gender'])) {
                $userGender = $indRow['individual_gender'];
            }

            if (!empty($indRow['individual_birthday'])) {
                $birthRaw  = substr((string)$indRow['individual_birthday'], 0, 10);
                $birthDate = DateTime::createFromFormat('Y-m-d', $birthRaw);
                if ($birthDate) {
                    $today   = new DateTime('today');
                    $userAge = (int)$birthDate->diff($today)->y;
                }
            }
        }
    } catch (Exception $e) {
        // Silent fail: if something goes wrong, we'll just skip gender/age checks on the client
    }
}

if (is_array($userGender) || is_object($userGender)) {
    $userGender = null;
}

/* ================== Fetch baptism_verification from individual_table ================== */
$userBaptismStatus = null;

if (!empty($_SESSION['individual_id'])) {
    try {
        $stmtBap = $pdo->prepare("
            SELECT baptism_verification
            FROM individual_table
            WHERE individual_id = :id
            LIMIT 1
        ");
        $stmtBap->execute([':id' => $_SESSION['individual_id']]);
        $bapRow = $stmtBap->fetch(PDO::FETCH_ASSOC);

        if ($bapRow && isset($bapRow['baptism_verification'])) {
            $userBaptismStatus = $bapRow['baptism_verification'];
        }
    } catch (Exception $e) {
        // Silent fail: baptism gating will just not run if something goes wrong.
    }
}

/* ================== Check existing Men Ministry membership ================== */
$isMenMinistryMember = false;

if (!empty($_SESSION['individual_id'])) {
    try {
        $stmtMin = $pdo->prepare("
            SELECT 1
            FROM ministries_table
            WHERE individual_id  = :id
              AND ministry_type  = :ministry_type
              AND archive_status = 'Active'
            LIMIT 1
        ");
        $stmtMin->execute([
            ':id'            => (int)$_SESSION['individual_id'],
            ':ministry_type' => $pageMinistryLabel,
        ]);
        $isMenMinistryMember = (bool)$stmtMin->fetchColumn();
    } catch (Exception $e) {
        // Fail silently; treat as not a member to avoid blocking accidentally
        $isMenMinistryMember = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ministry - Men's Ministries</title>
    <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/HTCCC-SYSTEM/css/global-layout.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/global-layout.css'); ?>">
    <link rel="stylesheet" href="/HTCCC-SYSTEM/css/ministries-men-page.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/ministries-men-page.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
      const IS_LOGGED_IN          = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
      const NEXT_URL              = <?php echo json_encode($nextTarget); ?>;
      const USER_GENDER           = <?php echo json_encode($userGender ?? ''); ?>;
      const USER_AGE              = <?php echo ($userAge !== null ? (int)$userAge : 'null'); ?>;
      const USER_BAPTISM_VER      = <?php echo json_encode($userBaptismStatus ?? ''); ?>;
      const IS_MEN_MINISTRY_MEMBER = <?php echo $isMenMinistryMember ? 'true' : 'false'; ?>;
    </script>

    <!-- Inline layout/style copied from Handmaid / service-preach design -->
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
    <!-- Match Handmaid/preach pattern: big header + card + subheading -->
    <h2 class="service-header">MINISTRIES</h2>

    <div class="service-container">
        <div class="service-text">
            <h3 class="service-header-txt-preach">MEN'S MINISTRIES</h3>

            <!-- MOBILE/TABLET IMAGE: directly under the header -->
            <div class="service-image-container mobile-only">
              <div class="service-image">
                <img src="<?php echo htmlspecialchars($image); ?>" alt="Men's Ministry">
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
                <img src="<?php echo htmlspecialchars($image); ?>" alt="Men's Ministry" id="service-img">
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

    // Not logged in → show alert FIRST, then redirect to login if confirmed
    e.preventDefault();
    Swal.fire({
      icon: 'info',
      title: 'Please sign in first',
      text: 'You need to log in before joining a ministry.',
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

<?php if ($showLoginSuccess): ?>
<script>
  Swal.fire({
    icon: 'success',
    title: 'Welcome back!',
    text: 'You’re now logged in. You can join the ministry.',
    confirmButtonText: 'Okay'
  });
</script>
<?php endif; ?>

<!-- Duplicate-membership gating for Men Ministry (LOGGED-IN ONLY) -->
<script>
(function(){
  const btn = document.getElementById('joinNowBtn');
  if (!btn) return;

  btn.addEventListener('click', function(e){
    if (!IS_LOGGED_IN) return;
    if (e.defaultPrevented) return;

    if (IS_MEN_MINISTRY_MEMBER) {
      e.preventDefault();
      Swal.fire({
        icon: 'info',
        title: 'You are already part of this ministry',
        text: 'Our records show that you are already a member of the Men Ministry.'
      });
      return;
    }
  });
})();
</script>

<!-- Auto SweetAlert when redirected back from ministry-form.php with already_member=1 -->
<script>
(function(){
  if (!IS_LOGGED_IN || !IS_MEN_MINISTRY_MEMBER) return;
  const params = new URLSearchParams(window.location.search);
  if (params.get('already_member') === '1') {
    Swal.fire({
      icon: 'info',
      title: 'You are already part of this ministry',
      text: 'Our records show that you are already a member of the Men Ministry.'
    });
  }
})();
</script>

<!-- Men-only + no minors gating (LOGGED-IN ONLY) -->
<script>
(function(){
  const btn = document.getElementById('joinNowBtn');
  if (!btn) return;

  btn.addEventListener('click', function(e){
    if (!IS_LOGGED_IN) {
      return;
    }
    if (e.defaultPrevented) return;

    const genderRaw = (USER_GENDER || '').toString().trim().toLowerCase();
    const ageParsed = (USER_AGE === null || USER_AGE === undefined)
      ? NaN
      : parseInt(USER_AGE, 10);

    // Block FEMALE accounts - Men’s Ministry only
    if (genderRaw === 'female' || genderRaw === 'f') {
      e.preventDefault();
      Swal.fire({
        icon: 'warning',
        title: 'Men’s Ministry Only',
        text: 'This ministry is exclusively for men. Female accounts are not allowed to join this ministry.'
      });
      return;
    }

    // Block MINORS (under 18)
    if (!Number.isNaN(ageParsed) && ageParsed < 18) {
      e.preventDefault();
      Swal.fire({
        icon: 'warning',
        title: 'Not Allowed for Minors',
        text: 'This ministry is not allowed for minors. Only members 18 years old and above may join.'
      });
      return;
    }
  });
})();
</script>

<!-- baptism_verification gating (LOGGED-IN ONLY) -->
<script>
(function(){
  const btn = document.getElementById('joinNowBtn');
  if (!btn) return;

  btn.addEventListener('click', function(e){
    if (!IS_LOGGED_IN) {
      return;
    }

    if (e.defaultPrevented) {
      return;
    }

    let statusRaw = (USER_BAPTISM_VER || '').toString().trim().toLowerCase();

    const isNonVerified =
      !statusRaw ||
      statusRaw === 'nonverified' ||
      statusRaw === 'not verified' ||
      statusRaw === 'non-verified';

    const isPending  = (statusRaw === 'pending');
    const isVerified = (statusRaw === 'verified');

    if (isNonVerified && !isVerified && !isPending) {
      e.preventDefault();
      Swal.fire({
        icon: 'info',
        title: 'Baptismal Verification Required',
        html: 'Our records show your baptismal status is <b>not yet verified</b>.<br><br>' +
              'Please upload your Baptismal Certificate in your profile so the admin can verify it.',
        showCancelButton: true,
        confirmButtonText: 'Upload Baptismal Certificate',
        cancelButtonText: 'Back'
      }).then((res) => {
        if (res.isConfirmed) {
          window.location.href = 'user_profile.php';
        }
      });
      return;
    }

    if (isPending) {
      e.preventDefault();
      Swal.fire({
        icon: 'info',
        title: 'Verification in Progress',
        text: 'Your Baptismal Certificate is currently pending admin verification. ' +
              'Please wait for the admin to complete the verification before proceeding to the form.'
      });
      return;
    }
  });
})();
</script>

<!-- baptism_verification ERROR trap (Reupload) -->
<script>
(function(){
  const btn = document.getElementById('joinNowBtn');
  if (!btn) return;

  btn.addEventListener('click', function(e){
    if (!IS_LOGGED_IN) {
      return;
    }

    if (e.defaultPrevented) {
      return;
    }

    let statusRaw = (USER_BAPTISM_VER || '').toString().trim().toLowerCase();

    const isErrorState =
      statusRaw === 'error'   ||
      statusRaw === 'invalid' ||
      statusRaw === 'reupload' ||
      statusRaw === 're-upload' ||
      statusRaw === 'rejected';

    if (!isErrorState) return;

    e.preventDefault();
    Swal.fire({
      icon: 'warning',
      title: 'Baptismal Verification Error',
      html: 'There was an issue with your Baptismal Certificate verification.<br><br>' +
            'Please <b>reupload</b> a clear and valid copy of your Baptismal Certificate ' +
            'in your profile so the admin can review it again.',
      showCancelButton: true,
      confirmButtonText: 'Reupload',
      cancelButtonText: 'Close'
    }).then((res) => {
      if (res.isConfirmed) {
        window.location.href = 'user_profile.php';
      }
    });
  });
})();
</script>

</body>
</html>
