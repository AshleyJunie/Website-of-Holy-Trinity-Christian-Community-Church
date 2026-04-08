<?php
// -------------------------------------------------------
// Boot + DB helpers
// -------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/db-connection.php';

// Live Mass badge flag for shared nav (optional)
$showLiveIcon = false;
if (!empty($db_connection)) {
  $sql = "SELECT live_status FROM multimedia ORDER BY livemassId DESC LIMIT 1";
  if ($result = mysqli_query($db_connection, $sql)) {
    if ($row = mysqli_fetch_assoc($result)) {
      if (strtolower(trim($row['live_status'] ?? '')) === 'active') $showLiveIcon = true;
    }
    mysqli_free_result($result);
  }
}

// Expose flags to JS if other scripts rely on them
echo '<script>window.IS_LOGGED_IN = '.(isset($_SESSION['individual_id']) ? 'true' : 'false').';</script>';
echo '<script>window.SHOW_LIVE = '.($showLiveIcon ? 'true' : 'false').';</script>';

// Single PDO connection reused for content
$pdo = new PDO(
  "mysql:host=localhost;dbname=htccc-data-base;charset=utf8",
  "root",
  "",
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Website of Holy Trinity Christian Community Church</title>

  <link rel="icon" type="image/png" sizes="16x16" href="image/httc_main-logo.jpg">

  <!-- Main styles -->
  <link rel="stylesheet" href="main-page.css">
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/global-layout.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/global-layout.css'); ?>">
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/main-page.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/main-page.css'); ?>">

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <script defer src="main-page.js"></script>
  <script async defer src="https://maps.googleapis.com/maps/api/js?key=YOUR_API_KEY&callback=initMap"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <!-- ✅ FIXED + MODERN SCHEDULE DESIGN + MODAL (NO CIRCLE, NO MESSY CHIP, NO SCROLL JUMP)
       ✅ + ABOUT-US CAROUSEL CLICK-ZOOM MODAL (NEXT/PREV + FIXED DESIGN + ACTIVE SLIDE)
  -->
  <style>
    /* ------------------------------
      SCHEDULE THUMBNAIL FIXES
      - force NO circle/oval (even if global CSS tries)
      - fix giant day-chip pill
    ------------------------------ */
    .sched-card .sched-media,
    .sched-card .sched-img-btn,
    .sched-card .sched-img-btn img{
      border-radius: 16px !important; /* modern rounded rectangle */
    }

    .sched-card .sched-media{
      position: relative;
      overflow: hidden !important; /* crop nicely */
      background: #fff;
    }

    .sched-card .sched-img-btn{
      display:block;
      width:100%;
      border:0;
      padding:0;
      background:transparent;
      cursor:pointer;
      line-height:0;
      text-align:left;
      outline:none;
    }

    /* Modern fixed-height thumbnail (no oval) */
    .sched-card .sched-img-btn img{
      display:block;
      width:100%;
      height:220px;          /* change to 200/240 if you want */
      object-fit:cover;
      transform: translateZ(0);
      transition: transform .25s ease, filter .25s ease;
    }

    .sched-card .sched-img-btn:hover img,
    .sched-card .sched-img-btn:focus-visible img{
      transform: scale(1.03);
      filter: saturate(1.05);
    }

    /* ✅ FIX: day-chip was becoming a huge vertical pill. Force it small. */
    .sched-card .day-chip{
      position:absolute !important;
      top:12px !important;
      left:12px !important;

      width:auto !important;
      height:auto !important;
      min-height:unset !important;
      max-height:none !important;

      padding:8px 12px !important;
      border-radius:999px !important;
      background: rgba(255,255,255,0.92) !important;
      backdrop-filter: blur(10px);
      border: 1px solid rgba(15,23,42,0.10) !important;
      box-shadow: 0 10px 30px rgba(15,23,42,0.12);

      font-size: 14px !important;
      font-weight: 700 !important;
      color:#0f172a !important;

      display:inline-flex !important;
      align-items:center !important;
      justify-content:center !important;
      white-space:nowrap !important;
      line-height:1 !important;
      z-index: 2 !important;
    }

    /* ------------------------------
      MODAL (schedule)
      - centered
      - caption + close
      - NO SCROLL DOWN when opened
    ------------------------------ */
    .sched-modal{
      position: fixed;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      padding: 20px;
    }
    .sched-modal.is-open{ display:flex; }

    .sched-modal-backdrop{
      position:absolute;
      inset:0;
      background: rgba(2,6,23,.58);
      backdrop-filter: blur(8px);
    }

    .sched-modal-panel{
      position:relative;
      width:min(980px, calc(100vw - 32px));
      max-height: calc(100vh - 32px);
      border-radius: 18px;
      overflow:hidden;
      background: #0b1220;
      border: 1px solid rgba(255,255,255,0.10);
      box-shadow: 0 30px 90px rgba(0,0,0,0.45);
      z-index:1;
      display:flex;
      flex-direction:column;
    }

    .sched-modal-header{
      display:flex;
      align-items:center;
      justify-content:flex-end; /* title removed */
      gap:12px;
      padding: 14px 16px;
      background: rgba(255,255,255,0.04);
      border-bottom: 1px solid rgba(255,255,255,0.08);
    }

    .sched-modal-close{
      width:42px;
      height:42px;
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,0.12);
      background: rgba(255,255,255,0.06);
      color:#fff;
      font-size:24px;
      cursor:pointer;
      display:flex;
      align-items:center;
      justify-content:center;
      transition: background .18s ease, transform .18s ease;
    }
    .sched-modal-close:hover{
      background: rgba(255,255,255,0.10);
      transform: translateY(-1px);
    }

    .sched-modal-body{
      padding: 14px;
      background: #050b16;
      display:flex;
      align-items:center;
      justify-content:center;
      position: relative; /* ✅ for overlay controls */
    }

    #schedModalImg{
      width:100%;
      height:auto;
      max-height: calc(100vh - 220px);
      object-fit: contain;
      border-radius: 14px !important; /* still not circle */
      background:#000;
      border: 1px solid rgba(255,255,255,0.08);
    }

    .sched-modal-footer{
      padding: 12px 16px 16px;
      background: rgba(255,255,255,0.03);
      border-top: 1px solid rgba(255,255,255,0.08);
      color: rgba(255,255,255,0.85);
      font-size: 13px;
      line-height: 1.45;
    }
    #schedModalCaption{
      margin:0;
      color: white;
    }

    /* ✅ NO SCROLL-JUMP LOCK (keeps current scroll position) */
    body.sched-scroll-lock{
      position: fixed;
      width: 100%;
      overflow: hidden;
    }

    /* ---------------------------------------------------
      ✅ NEXT + PREVIOUS BUTTONS INSIDE IMAGE (schedule overlay)
    --------------------------------------------------- */
    .sched-nav-btn{
      position:absolute;
      top: 50%;
      transform: translateY(-50%);
      width: 54px;
      height: 54px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,0.22);
      background: rgba(0,0,0,0.35);
      color:#fff;
      display:flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      user-select:none;
      z-index: 3;
      transition: transform .15s ease, background .15s ease;
      backdrop-filter: blur(6px);
    }
    .sched-nav-btn:hover{
      background: rgba(0,0,0,0.50);
      transform: translateY(-50%) scale(1.03);
    }
    .sched-nav-btn:active{
      transform: translateY(-50%) scale(0.98);
    }
    .sched-nav-btn[disabled]{
      opacity:.45;
      cursor:not-allowed;
    }
    .sched-nav-btn .chev{
      font-size: 28px;
      line-height: 1;
    }

    #schedPrevBtn{ left: 26px; }
    #schedNextBtn{ right: 26px; }

    @media (max-width: 480px){
      .sched-nav-btn{
        width: 48px;
        height: 48px;
      }
      #schedPrevBtn{ left: 18px; }
      #schedNextBtn{ right: 18px; }
      .sched-nav-btn .chev{ font-size: 26px; }
    }

    /* =====================================================
       ✅ BELIEF CARD – TIGHT TEXT FIT FIX (NO EXTRA SPACE)
    ===================================================== */
    .about-beliefs-grid{
      align-items: flex-start;
    }

    .belief-card{
      height: auto !important;
      min-height: unset !important;
      padding: 18px 20px;
      line-height: 1.55;
    }

    .belief-card p{
      margin: 0 !important;
      line-height: 1.6;
    }

    .belief-card ul{
      margin: 8px 0 0 18px !important;
      padding: 0 !important;
    }

    .belief-card li{
      margin-bottom: 6px;
      line-height: 1.5;
    }

    .belief-card li:last-child{
      margin-bottom: 0;
    }

    /* =====================================================
       ✅ ABOUT META (3 CHIPS) – STRAIGHT ALIGNMENT FIX
    ===================================================== */
    .about-us-meta{
      display: grid !important;
      grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
      gap: 12px !important;
      align-items: stretch !important;
    }

    .about-us-meta span{
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      gap: 10px !important;
      min-width: 0 !important;
      text-align: center !important;

      padding: 10px 14px !important;
      border-radius: 999px !important;

      line-height: 1.1 !important;
      font-size: 14px !important;
      font-weight: 700 !important;

      white-space: normal !important;
    }

    .about-us-meta span i{
      flex: 0 0 auto !important;
    }

    @media (max-width: 720px){
      .about-us-meta{
        grid-template-columns: 1fr !important;
      }
      .about-us-meta span{
        justify-content: flex-start !important;
        text-align: left !important;
      }
    }

    /* =====================================================
       ✅ ABOUT-US CAROUSEL – LARGER + BETTER PROPORTIONS
    ===================================================== */
    @media (min-width: 992px){
      .about-us-inner{
        gap: 28px !important;
      }

      .about-us-carousel{
        flex: 0 0 min(620px, 46vw) !important;
        max-width: 620px !important;
        margin-top: 150px;
      }
    }

    .about-us-carousel{
      width: 100% !important;
      max-width: 620px;
      height: 400px;
      border-radius: 20px !important;
      overflow: hidden !important;
      cursor: zoom-in; /* ✅ entire carousel feels clickable */
    }

    .about-us-carousel .about-slide img{
      width: 100% !important;
      height: 420px !important;
      object-fit: cover !important;
      display: block !important;
      cursor: zoom-in;
    }

    .about-us-carousel .about-carousel-badge{
      top: 16px !important;
      left: 16px !important;
    }

    @media (max-width: 991px){
      .about-us-carousel{
        max-width: 100% !important;
      }
      .about-us-carousel .about-slide img{
        height: 340px !important;
      }
    }

    @media (max-width: 520px){
      .about-us-carousel .about-slide img{
        height: 260px !important;
      }
    }

    /* =====================================================
       ✅ ABOUT-US FLOATING MODAL (FIXED DESIGN) + NEXT/PREV
       - clean centered panel
       - proper image sizing (no ugly stretch)
       - overlay arrows inside image area
       - shows ACTIVE slide by default when you click the carousel
    ===================================================== */
    .about-modal{
      position: fixed;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 10000; /* above schedule modal */
      padding: 18px;
    }
    .about-modal.is-open{ display:flex; }

    .about-modal-backdrop{
      position:absolute;
      inset:0;
      background: rgba(2,6,23,.68);
      backdrop-filter: blur(10px);
    }

    .about-modal-panel{
      position:relative;
      width: min(1120px, calc(100vw - 32px));
      max-height: calc(100vh - 32px);
      border-radius: 18px;
      overflow: hidden;
      background: radial-gradient(1200px 500px at 50% 0%, rgba(255,255,255,0.08), rgba(255,255,255,0.02));
      border: 1px solid rgba(255,255,255,0.12);
      box-shadow: 0 30px 100px rgba(0,0,0,0.6);
      z-index:1;
      display:flex;
      flex-direction: column;
      transform: scale(0.96);
      opacity: 0;
      transition: transform .18s ease, opacity .18s ease;
    }
    .about-modal.is-open .about-modal-panel{
      transform: scale(1);
      opacity: 1;
    }

    .about-modal-header{
      display:flex;
      align-items:center;
      justify-content: space-between;
      gap: 12px;
      padding: 12px 14px;
      background: rgba(255,255,255,0.05);
      border-bottom: 1px solid rgba(255,255,255,0.10);
    }

    .about-modal-title{
      color: rgba(255,255,255,0.92);
      font-size: 14px;
      font-weight: 800;
      letter-spacing: .4px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      max-width: calc(100% - 52px);
    }

    .about-modal-close{
      width:42px;
      height:42px;
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,0.14);
      background: rgba(255,255,255,0.08);
      color:#fff;
      font-size:24px;
      cursor:pointer;
      display:flex;
      align-items:center;
      justify-content:center;
      transition: background .18s ease, transform .18s ease;
      flex: 0 0 auto;
    }
    .about-modal-close:hover{
      background: rgba(255,255,255,0.12);
      transform: translateY(-1px);
    }

    .about-modal-body{
      position: relative;
      padding: 14px;
      background: rgba(0,0,0,0.35);
      display:flex;
      align-items:center;
      justify-content:center;
    }

    #aboutModalImg{
      width: 100%;
      height: auto;
      max-height: calc(100vh - 230px);
      object-fit: contain;
      border-radius: 14px;
      background: #000;
      border: 1px solid rgba(255,255,255,0.10);
      box-shadow: 0 14px 50px rgba(0,0,0,0.5);
    }

    .about-modal-footer{
      padding: 10px 14px 14px;
      background: rgba(255,255,255,0.04);
      border-top: 1px solid rgba(255,255,255,0.10);
      color: rgba(255,255,255,0.85);
      font-size: 13px;
      line-height: 1.45;
    }
    #aboutModalCaption{
      margin: 0;
      color: transparent;

    }

    /* overlay nav buttons inside image area */
    .about-nav-btn{
      position:absolute;
      top: 50%;
      transform: translateY(-50%);
      width: 56px;
      height: 56px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,0.22);
      background: rgba(0,0,0,0.38);
      color:#fff;
      display:flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      user-select:none;
      z-index: 3;
      transition: transform .15s ease, background .15s ease;
      backdrop-filter: blur(6px);
    }
    .about-nav-btn:hover{
      background: rgba(0,0,0,0.55);
      transform: translateY(-50%) scale(1.03);
    }
    .about-nav-btn:active{
      transform: translateY(-50%) scale(0.98);
    }
    .about-nav-btn[disabled]{
      opacity:.45;
      cursor:not-allowed;
    }
    .about-nav-btn .chev{
      font-size: 30px;
      line-height: 1;
    }
    #aboutPrevBtn{ left: 22px; }
    #aboutNextBtn{ right: 22px; }

    @media (max-width: 520px){
      .about-nav-btn{ width: 48px; height: 48px; }
      #aboutPrevBtn{ left: 14px; }
      #aboutNextBtn{ right: 14px; }
      .about-nav-btn .chev{ font-size: 26px; }
      #aboutModalImg{ max-height: calc(100vh - 260px); }
    }
  </style>
</head>

<body>
  <!-- Shared, single source of truth for navigation -->
  <header>
    <?php include $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/includes/navigation.php'; ?>
  </header>

  <?php
  // -------------------------------------------------------
  // Slider
  // -------------------------------------------------------
  $stmtSlider = $pdo->query("
      SELECT *
      FROM content_management_table
      WHERE content_type = 'Slider'
      ORDER BY img_upload_at DESC
  ");
  ?>
  <section class="slider" id="main-page">
    <?php $first = true; while ($row = $stmtSlider->fetch(PDO::FETCH_ASSOC)): ?>
      <div class="slide <?php echo $first ? 'active' : ''; ?>"
           style="background-image:url('<?php echo htmlspecialchars($row['img_file_path']); ?>');">
        <div class="caption">
          <h2><?php echo htmlspecialchars($row['img_caption']); ?></h2>
        </div>
      </div>
      <?php $first = false; endwhile; ?>
  </section>

  <?php
  // -------------------------------------------------------
  // ABOUT US + OUR BELIEFS + WE BELIEVE (combined section)
  // -------------------------------------------------------

  // About images & history
  $stmtAboutImages = $pdo->prepare("
      SELECT img_file_path, img_caption
      FROM content_management_table
      WHERE content_type = 'AboutUsImage'
      ORDER BY contentID DESC
      LIMIT 5
  ");
  $stmtAboutImages->execute();
  $aboutImages = $stmtAboutImages->fetchAll(PDO::FETCH_ASSOC);

  $stmtAboutHistory = $pdo->prepare("
      SELECT img_caption
      FROM content_management_table
      WHERE content_type = 'AboutUsHistory'
      ORDER BY contentID DESC
      LIMIT 1
  ");
  $stmtAboutHistory->execute();
  $aboutHistory = $stmtAboutHistory->fetch(PDO::FETCH_ASSOC);

  $defaultHistory = "Holy Trinity Christian Community Church began as a small gathering of believers
  in the early 2000s with a simple desire: to worship Jesus, build genuine relationships,
  and serve the community. Over the years, God has grown HTCCC into a vibrant spiritual family
  where people of all ages and backgrounds can encounter His grace, discover their calling,
  and be sent out to make a difference in the world.";

  // Our Beliefs / We Believe text
  $stmtBelief  = $pdo->query("SELECT img_caption FROM content_management_table WHERE content_type = 'Belief'  LIMIT 1");
  $belief      = $stmtBelief->fetch(PDO::FETCH_ASSOC);
  $stmtBelieve = $pdo->query("SELECT img_caption FROM content_management_table WHERE content_type = 'Believe'");
  $believeItems= $stmtBelieve->fetchAll(PDO::FETCH_ASSOC);
  ?>

  <section id="about-us-section">
    <!-- Top row: About Us + Carousel -->
    <div class="about-us-inner">
      <div class="about-us-copy">
        <div class="about-us-kicker">ABOUT OUR CHURCH </div>
        <h2 class="about-us-title">A growing community centered on Christ.</h2>
        <p class="about-us-sub">
          Get to know the story behind Holy Trinity Christian Community Church and how God continues
          to work in and through our church family.
        </p>
        <div class="about-us-history">
          <p>
            <?= nl2br(htmlspecialchars($aboutHistory['img_caption'] ?? $defaultHistory)); ?>
          </p>
        </div>
        <div class="about-us-meta">
          <span><i class="fa-solid fa-church"></i> Christ-centered worship</span>
          <span><i class="fa-solid fa-people-group"></i> Family-like community</span>
          <span><i class="fa-solid fa-location-dot"></i> Serving our neighborhood</span>
        </div>
      </div>

      <?php if (!empty($aboutImages)): ?>
        <div class="about-us-carousel" aria-label="About HTCCC image gallery">
          <div class="about-carousel-badge">
            <i class="fa-solid fa-circle"></i>
            Moments at HTCCC
          </div>

          <?php foreach ($aboutImages as $index => $imgRow): ?>
            <div class="about-slide <?= $index === 0 ? 'active' : ''; ?>">
              <img
                src="<?= htmlspecialchars($imgRow['img_file_path']); ?>"
                alt="<?= htmlspecialchars($imgRow['img_caption'] ?: 'HTCCC community photo'); ?>"
                data-full="<?= htmlspecialchars($imgRow['img_file_path']); ?>"
                data-caption="<?= htmlspecialchars($imgRow['img_caption'] ?: 'HTCCC community photo'); ?>"
              >
              <?php if (!empty($imgRow['img_caption'])): ?>
                <div class="about-slide-overlay">
                  <div class="about-slide-caption">
                    <?= htmlspecialchars($imgRow['img_caption']); ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <div class="about-dots" role="tablist">
            <?php foreach ($aboutImages as $index => $imgRow): ?>
              <button
                type="button"
                class="about-dot <?= $index === 0 ? 'active' : ''; ?>"
                aria-label="Show slide <?= $index + 1; ?>"
              ></button>
            <?php endforeach; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="about-us-carousel">
          <div class="about-carousel-badge">
            <i class="fa-solid fa-circle"></i>
            Moments at HTCCC
          </div>
          <div class="about-slide active">
            <img src="image/default-aboutus.jpg" alt="HTCCC community" data-full="image/default-aboutus.jpg" data-caption="HTCCC community">
            <div class="about-slide-overlay">
              <div class="about-slide-caption">
                Add your AboutUsImage records in the Content Management module to showcase photos here.
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Bottom row: Our Beliefs + We Believe side by side -->
    <?php if ($belief || $believeItems): ?>
      <div class="about-beliefs-wrapper">
        <div class="about-beliefs-header">
          <span class="about-beliefs-kicker">WHAT WE BELIEVE</span>
        </div>

        <div class="about-beliefs-grid">
          <?php if ($belief): ?>
            <article class="belief-card">
              <h3>Our Beliefs</h3>
              <p><?= nl2br(htmlspecialchars($belief['img_caption'])); ?></p>
            </article>
          <?php endif; ?>

          <?php if ($believeItems): ?>
            <article class="belief-card">
              <h3>We Believe</h3>
              <ul>
                <?php foreach ($believeItems as $item): ?>
                  <li><?= htmlspecialchars($item['img_caption']); ?></li>
                <?php endforeach; ?>
              </ul>
            </article>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <?php
  // -------------------------------------------------------
  // Announcements
  // -------------------------------------------------------
  $stmtAnn = $pdo->query("
      SELECT announcementMsg
      FROM announcement
      WHERE status = 'New'
      ORDER BY announcementId ASC
  ");
  $announcements = $stmtAnn->fetchAll(PDO::FETCH_ASSOC);
  ?>

  <section class="schedule-section">
    <div class="schedule-announcement">
      <div class="schedule-announcement-header">
        <i class="schedule-announcement-icon fas fa-bullhorn" aria-hidden="true"></i>
        <h2 class="schedule-announcement-title">HTCCC Announcement</h2>
      </div>
      <div class="schedule-announcement-body">
        <div class="announcement-image">
          <img src="image/megaphone.png" alt="Important Announcement" />
        </div>
        <div class="announcement-text">
          <h3>Important Announcement</h3>
          <?php if ($announcements): ?>
            <?php foreach ($announcements as $ann): ?>
              <p><?php echo htmlspecialchars($ann['announcementMsg']); ?></p>
            <?php endforeach; ?>
          <?php else: ?>
            <p>No announcements at the moment.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <?php
  // -------------------------------------------------------
  // Regular Schedules
  // -------------------------------------------------------
  $stmtSched = $pdo->query("
      SELECT img_caption, img_file_path
      FROM content_management_table
      WHERE content_type = 'Schedule'
      ORDER BY contentID ASC
  ");
  $schedules = $stmtSched->fetchAll(PDO::FETCH_ASSOC);

  $pdoImg = $pdo;
  $specific = $pdoImg->prepare("
      SELECT img_file_path
      FROM content_management_table
      WHERE content_type = ?
      ORDER BY contentID DESC
      LIMIT 1
  ");

  function slugify_ct($t){
    $t = strtolower(trim(preg_replace('~[^a-z0-9]+~','_',$t)));
    return $t ? "schedule_".$t : null;
  }

  $__schedImageTypeKeywords = [
    'handmaid'          => 'schedule_handmaid',
    'handmaids'         => 'schedule_handmaid',
    "handmaid’s"        => 'schedule_handmaid',
    "handmaid's"        => 'schedule_handmaid',
    'men'               => 'schedule_men',
    "men’s"             => 'schedule_men',
    "men's"             => 'schedule_men',
    'music'             => 'schedule_music',
    'choir'             => 'schedule_music',
    'sunday schooling'  => 'schedule_sunday_school',
    'sunday school'     => 'schedule_sunday_school',
    'schooling'         => 'schedule_sunday_school'
  ];

  function htccc_heal_schedule_img(?string $imgPath): string {
    $resolved = trim((string)$imgPath);
    if ($resolved === '') return 'image/default-schedule.jpg';

    $hasExt = (bool)preg_match('~\.(png|jpe?g|gif|webp|svg)$~i', $resolved);
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');

    $candidates = [];
    $candidates[] = $resolved;

    if (!$hasExt) {
      foreach (['.png','.jpg','.jpeg','.webp'] as $ext) {
        $candidates[] = $resolved.$ext;
      }
    }

    $baseVariants = [$resolved, '/HTCCC-SYSTEM/'.ltrim($resolved,'/')];
    foreach ($baseVariants as $bv) {
      $candidates[] = $bv;
      if (!$hasExt) {
        foreach (['.png','.jpg','.jpeg','.webp'] as $ext) {
          $candidates[] = $bv.$ext;
        }
      }
    }

    foreach ($candidates as $cand) {
      $fs = $docRoot.'/'.ltrim($cand,'/');
      if ($docRoot && @file_exists($fs)) return $cand;
    }
    return 'image/default-schedule.jpg';
  }
  ?>

  <div class="container">
    <div class="section-head">
      <div class="ico"><i class="fa-regular fa-calendar"></i></div>
      <h3 class="schedule-ministries-title">Regular Schedules of the Church</h3>
    </div>

    <div class="sched-grid">
      <?php foreach ($schedules as $row):
        $parts = explode('||', (string)$row['img_caption']);
        $day  = trim($parts[0] ?? '—');
        $act  = trim($parts[1] ?? '—');
        $time = trim($parts[2] ?? '');
        $img  = trim($row['img_file_path'] ?? '');

        $ct   = slugify_ct($act);
        if ($ct){
          $specific->execute([$ct]);
          if ($got = $specific->fetch(PDO::FETCH_ASSOC)){
            if (!empty($got['img_file_path'])) $img = $got['img_file_path'];
          }
        }

        try {
          $lc = mb_strtolower($act, 'UTF-8');
          $ctCandidates = [];
          global $__schedImageTypeKeywords;
          foreach ($__schedImageTypeKeywords as $kw => $mappedCt) {
            if (mb_strpos($lc, $kw) !== false) {
              $ctCandidates[] = $mappedCt;
            }
          }
          if ($ct) $ctCandidates[] = $ct;
          $ctCandidates[] = 'schedule_default';

          foreach ($ctCandidates as $candCt) {
            $specific->execute([$candCt]);
            $rowCt = $specific->fetch(PDO::FETCH_ASSOC);
            if ($rowCt && !empty($rowCt['img_file_path'])) {
              $img = $rowCt['img_file_path'];
              break;
            }
          }
        } catch (Throwable $e) {}

        if ($img === '') $img = 'image/default-schedule.jpg';
        $img = htccc_heal_schedule_img($img);

        $modalCaption = $act
          . ($day ? " • $day" : '')
          . ($time ? " • $time" : '');
      ?>
        <article class="sched-card" role="group" aria-label="<?= htmlspecialchars($act) ?>">
          <div class="sched-media">

            <!-- CLICKABLE IMAGE -->
            <button type="button"
                    class="sched-img-btn"
                    data-full="<?= htmlspecialchars($img) ?>"
                    data-caption="<?= htmlspecialchars($modalCaption) ?>"
                    aria-label="View <?= htmlspecialchars($act) ?> image">
              <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($act) ?> image">
            </button>

            <span class="day-chip"><?= htmlspecialchars($day) ?></span>
          </div>

          <div class="sched-body">
            <div class="sched-title"><?= htmlspecialchars($act) ?></div>
            <div class="sched-time"><?= $time ? htmlspecialchars($time) : 'Time to be announced' ?></div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>

  <?php
  // -------------------------------------------------------
  // Join Us
  // -------------------------------------------------------
  $stmtJoin = $pdo->prepare("
      SELECT img_file_path, img_caption
      FROM content_management_table
      WHERE content_type = 'JoinUs'
      ORDER BY contentID DESC
      LIMIT 1
  ");
  $stmtJoin->execute();
  $joinUs = $stmtJoin->fetch(PDO::FETCH_ASSOC);
  ?>

  <section id="join-us">
    <div class="image-container">
      <?php if (!empty($joinUs['img_file_path'])): ?>
        <img src="<?php echo htmlspecialchars($joinUs['img_file_path']); ?>" alt="Community Image">
      <?php else: ?>
        <img src="image/default-joinus.png" alt="Community Image">
      <?php endif; ?>
    </div>
    <div class="content">
      <h2>JOIN OUR COMMUNITY</h2>
      <p>
        <?php echo !empty($joinUs['img_caption'])
          ? htmlspecialchars($joinUs['img_caption'])
          : '"A church is not just a building; it’s a family, a place where love is shared, faith is strengthened, and lives are transformed in community."'; ?>
      </p>
      <a href="individual_sign-up.php" class="join-button">JOIN US!</a>
    </div>
  </section>

  <footer>
    <?php include $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/includes/footer.php'; ?>
  </footer>

  <?php
  // -------------------------------------------------------
  // Live Mini Player
  // -------------------------------------------------------
  $liveActive = false;
  $liveWatchUrl = '';
  $embedSrc = '';
  $liveId = '';

  if ($db_connection) {
    $sql = "SELECT livemassId, fb_link, live_status FROM multimedia ORDER BY livemassId DESC LIMIT 1";
    if ($res = mysqli_query($db_connection, $sql)) {
      if ($row = mysqli_fetch_assoc($res)) {
        $liveId = (string)($row['livemassId'] ?? '');
        $liveActive = (strtolower(trim($row['live_status'] ?? '')) === 'active');
        $liveWatchUrl = trim($row['fb_link'] ?? '');
        if ($liveActive && $liveWatchUrl !== '') {
          if (strpos($liveWatchUrl, '/plugins/video.php') !== false) {
            $embedSrc = $liveWatchUrl;
          } elseif (strpos($liveWatchUrl,'facebook.com') !== false) {
            $encoded = rawurlencode($liveWatchUrl);
            $embedSrc = "https://www.facebook.com/plugins/video.php?href={$encoded}&show_text=false&autoplay=1";
          } elseif (preg_match('~youtube\.com/watch\?v=([^&]+)~',$liveWatchUrl,$m)) {
            $embedSrc = "https://www.youtube.com/embed/".htmlspecialchars($m[1])."?autoplay=1&mute=1";
          } elseif (preg_match('~youtu\.be/([^?&/]+)~',$liveWatchUrl,$m)) {
            $embedSrc = "https://www.youtube.com/embed/".htmlspecialchars($m[1])."?autoplay=1&mute=1";
          } else {
            $embedSrc = $liveWatchUrl;
          }
        }
      }
      mysqli_free_result($res);
    }
  }
  ?>

  <div id="live-mini" aria-live="polite" aria-atomic="true" style="display:none">
    <div class="hdr">
      <div class="dot" aria-hidden="true"></div><span>LIVE MASS</span>
      <button class="close" id="live-mini-close" aria-label="Close">×</button>
    </div>
    <div class="body">
      <div class="frame-wrap">
        <?php if ($liveActive && $embedSrc): ?>
          <iframe src="<?= htmlspecialchars($embedSrc) ?>" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen title="Live stream"></iframe>
        <?php else: ?>
          <div style="display:flex;align-items:center;justify-content:center;height:100%;color:#94a3b8;">Live preview unavailable.</div>
        <?php endif; ?>
      </div>
      <div class="cta">
        <span>Click to watch in full view.</span>
        <a href="multimedia.php">Open Live Page</a>
      </div>
    </div>
  </div>

  <!-- ✅ SCHEDULE MODAL -->
  <div id="schedModal" class="sched-modal" aria-hidden="true" role="dialog" aria-label="Schedule image preview">
    <div class="sched-modal-backdrop" data-close="true"></div>

    <div class="sched-modal-panel" role="document" aria-modal="true">
      <div class="sched-modal-header">
        <button type="button" class="sched-modal-close" id="schedModalClose" aria-label="Close">×</button>
      </div>

      <div class="sched-modal-body">
        <img id="schedModalImg" src="" alt="Schedule preview">

        <button type="button" class="sched-nav-btn" id="schedPrevBtn" aria-label="Previous schedule">
          <span class="chev">‹</span>
        </button>

        <button type="button" class="sched-nav-btn" id="schedNextBtn" aria-label="Next schedule">
          <span class="chev">›</span>
        </button>
      </div>

      <div class="sched-modal-footer">
        <p id="schedModalCaption"></p>
      </div>
    </div>
  </div>

  <!-- ✅ ABOUT-US MODAL (FIXED DESIGN + NEXT/PREV + ACTIVE SLIDE DISPLAY) -->
  <div id="aboutModal" class="about-modal" aria-hidden="true" role="dialog" aria-label="About image preview">
    <div class="about-modal-backdrop" data-close="true"></div>

    <div class="about-modal-panel" role="document" aria-modal="true">
      <div class="about-modal-header">
        <div class="about-modal-title" id="aboutModalTitle">Moments at HTCCC</div>
        <button type="button" class="about-modal-close" id="aboutModalClose" aria-label="Close">×</button>
      </div>

      <div class="about-modal-body">
        <img id="aboutModalImg" src="" alt="About image preview">

        <!-- ✅ PREVIOUS -->
        <button type="button" class="about-nav-btn" id="aboutPrevBtn" aria-label="Previous image">
          <span class="chev">‹</span>
        </button>

        <!-- ✅ NEXT -->
        <button type="button" class="about-nav-btn" id="aboutNextBtn" aria-label="Next image">
          <span class="chev">›</span>
        </button>
      </div>

      <div class="about-modal-footer">
        <p id="aboutModalCaption"></p>
      </div>
    </div>
  </div>

  <!-- GLOBAL SMALL HELPERS -->
  <script>
    function openModal(imageSrc, captionText){
      document.getElementById('modal').style.display='flex';
      document.querySelector('.modal-image').src=imageSrc;
      document.querySelector('.modal-caption').innerText=captionText;
    }
    function closeModal(){
      document.getElementById('modal').style.display='none';
    }

    document.addEventListener("DOMContentLoaded",function(){
      const readMoreBtn=document.getElementById("readMoreBtn");
      const floatingForm=document.getElementById("floatingForm");
      const closeFormBtn=document.getElementById("closeFormBtn");
      if(readMoreBtn){readMoreBtn.addEventListener("click",()=>floatingForm.style.display="flex");}
      if(closeFormBtn){closeFormBtn.addEventListener("click",()=>floatingForm.style.display="none");}
    });

    function startCarousel(){
      const slides=document.querySelectorAll('#main-page .slide');
      let currentIndex=0;
      function showSlide(i){slides.forEach((s,idx)=>s.classList.toggle('active',idx===i));}
      function nextSlide(){currentIndex=(currentIndex+1)%slides.length;showSlide(currentIndex);}
      showSlide(currentIndex);
      setInterval(nextSlide,3000);
    }
    document.addEventListener('DOMContentLoaded', startCarousel);
  </script>

  <!-- Prevent sign-up when already logged in -->
  <script>
    (function(){
      document.addEventListener('click', function(e){
        const a = e.target.closest('a[href$="individual_sign-up.php"]');
        if (a && window.IS_LOGGED_IN) {
          e.preventDefault();
          Swal.fire({
            icon:'info',
            title:'You are already a member',
            text:"You're currently logged in — no need to sign up again.",
            confirmButtonColor:'#001B3A'
          });
        }
      });
    })();
  </script>

  <!-- Live mini show/hide remember -->
  <script>
    document.addEventListener('DOMContentLoaded', function(){
      const IS_LIVE = <?php echo $liveActive ? 'true' : 'false'; ?>;
      const LIVE_ID = <?php echo json_encode($liveId ?: ''); ?>;
      const mini = document.getElementById('live-mini');
      const closeBtn = document.getElementById('live-mini-close');
      if (!IS_LIVE || !LIVE_ID || !mini) return;
      const last = localStorage.getItem('liveMini:lastDismissedId');
      if (last === String(LIVE_ID)) return;
      mini.style.display = 'block';
      closeBtn?.addEventListener('click', ()=>{
        mini.style.display='none';
        localStorage.setItem('liveMini:lastDismissedId', String(LIVE_ID));
      });
    });
  </script>

  <!-- ABOUT US 5-IMAGE CAROUSEL SCRIPT -->
  <script>
    (function () {
      document.addEventListener('DOMContentLoaded', function () {
        var slides = document.querySelectorAll('.about-us-carousel .about-slide');
        var dots   = document.querySelectorAll('.about-us-carousel .about-dot');
        if (!slides.length) return;

        var current = 0;
        var timerId = null;

        function showSlide(index) {
          slides.forEach(function (s, i) { s.classList.toggle('active', i === index); });
          dots.forEach(function (d, i) { d.classList.toggle('active', i === index); });
          current = index;
        }

        function nextSlide() {
          var next = (current + 1) % slides.length;
          showSlide(next);
        }

        timerId = setInterval(nextSlide, 4500);

        dots.forEach(function (dot, idx) {
          dot.addEventListener('click', function () {
            showSlide(idx);
            if (timerId) {
              clearInterval(timerId);
              timerId = setInterval(nextSlide, 4500);
            }
          });
        });

        showSlide(0);
      });
    })();
  </script>

  <!-- (Your duplicate carousel script kept as-is) -->
  <script>
    (function () {
      document.addEventListener('DOMContentLoaded', function () {
        var slides = document.querySelectorAll('.about-us-carousel .about-slide');
        var dots   = document.querySelectorAll('.about-us-carousel .about-dot');
        if (!slides.length) return;

        var current = 0;
        var timerId = null;

        function showSlide(index) {
          slides.forEach(function (s, i) { s.classList.toggle('active', i === index); });
          dots.forEach(function (d, i) { d.classList.toggle('active', i === index); });
          current = index;
        }

        function nextSlide() {
          var next = (current + 1) % slides.length;
          showSlide(next);
        }

        timerId = setInterval(nextSlide, 4500);

        dots.forEach(function (dot, idx) {
          dot.addEventListener('click', function () {
            showSlide(idx);
            if (timerId) {
              clearInterval(timerId);
              timerId = setInterval(nextSlide, 4500);
            }
          });
        });

        showSlide(0);
      });
    })();
  </script>

  <!-- ✅ ABOUT-US MODAL JS (NEXT/PREV + ACTIVE SLIDE DISPLAY + NO SCROLL JUMP) -->
  <script>
  (function(){
    function qs(sel, root){ return (root || document).querySelector(sel); }
    function qsa(sel, root){ return Array.from((root || document).querySelectorAll(sel)); }

    const modal      = qs('#aboutModal');
    const modalImg   = qs('#aboutModalImg');
    const modalCap   = qs('#aboutModalCaption');
    const modalTitle = qs('#aboutModalTitle');
    const closeBtn   = qs('#aboutModalClose');
    const nextBtn    = qs('#aboutNextBtn');
    const prevBtn    = qs('#aboutPrevBtn');

    if (!modal || !modalImg) return;

    let _scrollY = 0;
    let currentIndex = -1;

    // Build items list from slides (ensures correct order & captions)
    const slides = qsa('.about-us-carousel .about-slide');
    const items = slides.map(slide => {
      const img = slide.querySelector('img');
      return {
        src: img?.getAttribute('data-full') || img?.src || '',
        caption: img?.getAttribute('data-caption') || img?.alt || ''
      };
    }).filter(x => x.src);

    function lockScroll(){
      _scrollY = window.scrollY || window.pageYOffset || 0;
      document.body.classList.add('sched-scroll-lock'); // reuse existing lock class
      document.body.style.top = `-${_scrollY}px`;
    }

    function unlockScroll(){
      document.body.classList.remove('sched-scroll-lock');
      const top = document.body.style.top;
      document.body.style.top = '';
      const y = top ? Math.abs(parseInt(top, 10)) : _scrollY;
      window.scrollTo(0, y || 0);
    }

    function renderAt(index){
      if (!items.length) return;
      currentIndex = ((index % items.length) + items.length) % items.length;

      const item = items[currentIndex];
      modalImg.src = item.src || '';
      if (modalCap) modalCap.textContent = item.caption || '';
      if (modalTitle) modalTitle.textContent = 'Moments at HTCCC';

      const disabled = (items.length <= 1);
      if (nextBtn) nextBtn.disabled = disabled;
      if (prevBtn) prevBtn.disabled = disabled;
    }

    function openAboutModal(index){
      if (!items.length) return;
      lockScroll();
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden','false');
      renderAt(index);
    }

    function closeAboutModal(){
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden','true');
      modalImg.src = '';
      if (modalCap) modalCap.textContent = '';
      currentIndex = -1;
      unlockScroll();
    }

    // ✅ SPECIFIC: open ACTIVE carousel image when clicking the carousel container (not dots)
    const carousel = qs('.about-us-carousel');
    if (carousel) {
      carousel.addEventListener('click', function(e){
        // don't open when clicking dots
        if (e.target.closest('.about-dots') || e.target.closest('.about-dot')) return;

        // if clicked an image, open that exact slide index
        const clickedImg = e.target.closest('.about-slide img');
        if (clickedImg) {
          const clickedSlide = clickedImg.closest('.about-slide');
          const idx = slides.indexOf(clickedSlide);
          openAboutModal(idx >= 0 ? idx : 0);
          return;
        }

        // otherwise open currently ACTIVE slide
        const activeSlide = carousel.querySelector('.about-slide.active');
        const idx = activeSlide ? slides.indexOf(activeSlide) : 0;
        openAboutModal(idx >= 0 ? idx : 0);
      });
    }

    // ✅ NEXT / PREV buttons inside modal
    nextBtn?.addEventListener('click', function(e){
      e.preventDefault();
      if (!modal.classList.contains('is-open')) return;
      if (items.length <= 1) return;
      renderAt(currentIndex + 1);
    });

    prevBtn?.addEventListener('click', function(e){
      e.preventDefault();
      if (!modal.classList.contains('is-open')) return;
      if (items.length <= 1) return;
      renderAt(currentIndex - 1);
    });

    closeBtn?.addEventListener('click', closeAboutModal);

    // backdrop click closes
    modal.addEventListener('click', function(e){
      if (e.target && e.target.getAttribute && e.target.getAttribute('data-close') === 'true') {
        closeAboutModal();
      }
    });

    // ESC + arrows
    document.addEventListener('keydown', function(e){
      if (!modal.classList.contains('is-open')) return;
      if (e.key === 'Escape') closeAboutModal();
      if (e.key === 'ArrowRight' && items.length > 1) renderAt(currentIndex + 1);
      if (e.key === 'ArrowLeft'  && items.length > 1) renderAt(currentIndex - 1);
    });
  })();
  </script>

  <!-- MOBILE NAV HAMBURGER TOGGLE SCRIPT -->
  <script>
    (function(){
      document.addEventListener('DOMContentLoaded', function(){
        var navContainer = document.querySelector('.nav-container');
        if (!navContainer) return;

        var toggle = navContainer.querySelector(
          '.nav-toggle, .hamburger, .menu-toggle, .nav-burger, button[aria-label*="menu"], button[aria-label*="Menu"]'
        );
        if (!toggle) return;

        var navLinks = navContainer.querySelector('.nav-links');

        function setExpanded(on) {
          navContainer.classList.toggle('open', !!on);
          try { toggle.setAttribute('aria-expanded', on ? 'true' : 'false'); } catch (e) {}
        }

        toggle.addEventListener('click', function(e){
          e.preventDefault();
          var isOpen = navContainer.classList.contains('open');
          setExpanded(!isOpen);
        });

        toggle.addEventListener('keydown', function(e){
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            var isOpen = navContainer.classList.contains('open');
            setExpanded(!isOpen);
          }
        });

        if (navLinks) {
          navLinks.addEventListener('click', function(e){
            if (e.target.closest('a') && window.innerWidth <= 768) {
              setExpanded(false);
            }
          });
        }
      });
    })();
  </script>

  <!-- ✅ SCHEDULE MODAL JS (NO SCROLL DOWN) + ✅ NEXT/PREV LOGIC -->
  <script>
  (function(){
    function qs(sel, root){ return (root || document).querySelector(sel); }
    function qsa(sel, root){ return Array.from((root || document).querySelectorAll(sel)); }

    const modal    = qs('#schedModal');
    const modalImg = qs('#schedModalImg');
    const modalCap = qs('#schedModalCaption');
    const closeBtn = qs('#schedModalClose');
    const nextBtn  = qs('#schedNextBtn');
    const prevBtn  = qs('#schedPrevBtn');

    if (!modal || !modalImg || !modalCap) return;

    let _scrollY = 0;

    // ✅ collect all schedule items (src + caption)
    const scheduleBtns = qsa('.sched-media .sched-img-btn');
    const scheduleItems = scheduleBtns.map(btn => ({
      src: btn.getAttribute('data-full') || '',
      caption: btn.getAttribute('data-caption') || btn.querySelector('img')?.alt || ''
    }));

    let currentIndex = -1;

    function lockScroll(){
      _scrollY = window.scrollY || window.pageYOffset || 0;
      document.body.classList.add('sched-scroll-lock');
      document.body.style.top = `-${_scrollY}px`;
    }

    function unlockScroll(){
      document.body.classList.remove('sched-scroll-lock');
      const top = document.body.style.top;
      document.body.style.top = '';
      const y = top ? Math.abs(parseInt(top, 10)) : _scrollY;
      window.scrollTo(0, y || 0);
    }

    function renderAt(index){
      if (!scheduleItems.length) return;
      currentIndex = ((index % scheduleItems.length) + scheduleItems.length) % scheduleItems.length;

      const item = scheduleItems[currentIndex];
      modalImg.src = item.src || '';
      modalCap.textContent = item.caption || '';

      // If only 1 item, disable nav
      const disabled = (scheduleItems.length <= 1);
      if (nextBtn) nextBtn.disabled = disabled;
      if (prevBtn) prevBtn.disabled = disabled;
    }

    function openSchedModal(index){
      lockScroll();
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden','false');
      renderAt(index);
    }

    function closeSchedModal(){
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden','true');
      modalImg.src = '';
      modalCap.textContent = '';
      currentIndex = -1;
      unlockScroll();
    }

    // open from card click (and set correct index)
    scheduleBtns.forEach((btn, idx) => {
      btn.addEventListener('click', function(){
        openSchedModal(idx);
      });
    });

    // ✅ next / prev buttons
    nextBtn?.addEventListener('click', function(e){
      e.preventDefault();
      if (!modal.classList.contains('is-open')) return;
      if (scheduleItems.length <= 1) return;
      renderAt(currentIndex + 1);
    });

    prevBtn?.addEventListener('click', function(e){
      e.preventDefault();
      if (!modal.classList.contains('is-open')) return;
      if (scheduleItems.length <= 1) return;
      renderAt(currentIndex - 1);
    });

    closeBtn?.addEventListener('click', closeSchedModal);

    modal.addEventListener('click', function(e){
      if (e.target && e.target.getAttribute && e.target.getAttribute('data-close') === 'true') {
        closeSchedModal();
      }
    });

    // Optional: keyboard support
    document.addEventListener('keydown', function(e){
      if (!modal.classList.contains('is-open')) return;
      if (e.key === 'Escape') closeSchedModal();
      if (e.key === 'ArrowRight' && scheduleItems.length > 1) renderAt(currentIndex + 1);
      if (e.key === 'ArrowLeft'  && scheduleItems.length > 1) renderAt(currentIndex - 1);
    });
  })();
  </script>

</body>
</html>
