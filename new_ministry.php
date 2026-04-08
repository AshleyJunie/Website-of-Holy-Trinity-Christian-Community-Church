<?php
// ================== PHP (top of file) ==================
if (session_status() === PHP_SESSION_NONE) session_start();

// Considered logged-in if ANY role is present (UI hint only)
$isLoggedIn = (
  isset($_SESSION['individual_id']) ||
  isset($_SESSION['admin_id']) ||
  isset($_SESSION['pastor_id']) ||
  isset($_SESSION['secretary_id'])
);

// DB connect
$pdo = new PDO("mysql:host=localhost;dbname=htccc-data-base;charset=utf8", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ================== READ SELECTED MINISTRY FROM URL ==================
// Recommended: pass ministry_id from navigation links
$selectedMinistryId = isset($_GET['ministry_id']) ? (int)$_GET['ministry_id'] : 0;

// Optional fallback: pass ministry name ?ministry=...
$selectedMinistryName = isset($_GET['ministry']) ? trim((string)$_GET['ministry']) : "";

// Default image fallback
$defaultImage = "image/ministries-women-last.png";

// ================== FETCH MINISTRY FROM ministry_table ==================
$ministry = null;

if ($selectedMinistryId > 0) {
  $stmt = $pdo->prepare("
    SELECT ministry_id, ministry_name, ministry_description, join_description, ministry_image
    FROM ministry_table
    WHERE ministry_id = :id
    LIMIT 1
  ");
  $stmt->execute([":id" => $selectedMinistryId]);
  $ministry = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Fallback by ministry_name if ministry_id not provided or not found
if (!$ministry && $selectedMinistryName !== "") {
  $stmt = $pdo->prepare("
    SELECT ministry_id, ministry_name, ministry_description, join_description, ministry_image
    FROM ministry_table
    WHERE ministry_name = :name
    LIMIT 1
  ");
  $stmt->execute([":name" => $selectedMinistryName]);
  $ministry = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Final fallback: show the first ministry if still not found
if (!$ministry) {
  $stmt = $pdo->query("
    SELECT ministry_id, ministry_name, ministry_description, join_description, ministry_image
    FROM ministry_table
    ORDER BY ministry_id ASC
    LIMIT 1
  ");
  $ministry = $stmt->fetch(PDO::FETCH_ASSOC);
}

// If table is empty, prevent errors
if (!$ministry) {
  $ministry = [
    "ministry_id" => 0,
    "ministry_name" => "Ministry",
    "ministry_description" => "No ministry records found in ministry_table.",
    "join_description" => "",
    "ministry_image" => ""
  ];
}

// ================== MAP DB FIELDS TO YOUR EXISTING TEMPLATE VARIABLES ==================
$pageMinistryLabel = $ministry["ministry_name"] ?: "Ministry";
$pageTitle = "Ministry - " . $pageMinistryLabel;

// Image
$image = $defaultImage;
if (!empty($ministry["ministry_image"]) && $ministry["ministry_image"] !== "0") {
  $image = $ministry["ministry_image"];
}

// Description (your template expects captions array)
$captions = [];
if (!empty($ministry["ministry_description"])) {
  $captions[] = $ministry["ministry_description"];
}

// Join description
$joinDesc = !empty($ministry["join_description"]) ? $ministry["join_description"] : "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($pageTitle); ?></title>
  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/global-layout.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/global-layout.css'); ?>">
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/ministries-women-page.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/ministries-women-page.css'); ?>">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <script>
    // Keep the same JS constants pattern (in case you want to reuse it later)
    const IS_LOGGED_IN       = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
    const PAGE_MINISTRY      = <?php echo json_encode($pageMinistryLabel); ?>;

    // These 2 are not used anymore but kept so nothing breaks if may JS ka later
    const PARENT_MINISTRY    = <?php echo json_encode(""); ?>;
    const CONTENT_TYPE       = <?php echo json_encode(""); ?>;
  </script>

  <!-- Inline design copied from service-preach layout -->
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

    /* If your main CSS already has these classes, this is safe.
       If not, this enables responsive behavior. */
    .mobile-only { display: none; }
    .desktop-only { display: block; }
    @media (max-width: 900px) {
      .mobile-only { display: block; }
      .desktop-only { display: none; }
      .service-container { padding: 25px; }
    }
  </style>
</head>
<body>

<header>
  <?php include $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/includes/navigation.php'; ?>
</header>

<section id="service-preach">
  <h2 class="service-header">MINISTRIES</h2>

  <div class="service-container">
    <div class="service-text">
      <h3 class="service-header-txt-preach"><?php echo htmlspecialchars($pageMinistryLabel); ?></h3>

      <!-- MOBILE/TABLET IMAGE: directly under the header -->
      <div class="service-image-container mobile-only">
        <div class="service-image">
          <img src="<?php echo htmlspecialchars($image); ?>" alt="<?php echo htmlspecialchars($pageMinistryLabel); ?>">
        </div>
      </div>

      <?php if (!empty($captions)): ?>
        <?php foreach ($captions as $caption): ?>
          <p class="service-description">
            <?php echo nl2br(htmlspecialchars($caption)); ?>
          </p>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="service-description">
          No content available yet for this ministry.
        </p>
      <?php endif; ?>

      <?php if (!empty($joinDesc)): ?>
        <div class="how-to-join">
          <strong>How to join?</strong>
          <p class="service-description"><?php echo nl2br(htmlspecialchars($joinDesc)); ?></p>
        </div>
      <?php endif; ?>

      <!-- ✅ BUTTON REMOVED AS REQUESTED -->
      <!-- <div class="background" id="background"> ... </div> -->
    </div>

    <!-- DESKTOP IMAGE: right side -->
    <div class="service-image-container desktop-only">
      <div class="service-image">
        <img src="<?php echo htmlspecialchars($image); ?>" alt="<?php echo htmlspecialchars($pageMinistryLabel); ?>" id="service-img">
      </div>
    </div>
  </div>
</section>

<footer1>
  <?php include $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/includes/footer.php'; ?>
</footer1>

</body>
</html>
