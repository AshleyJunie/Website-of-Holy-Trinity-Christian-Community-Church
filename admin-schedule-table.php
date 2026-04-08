BEGIN FILE: admin-schedule-table.php
<?php
include 'db-connection.php'; // must set $db_connection = new mysqli(...)

// Keep union stable across collations
mysqli_set_charset($db_connection, 'utf8mb4');
mysqli_query($db_connection, "SET collation_connection = 'utf8mb4_general_ci'");

/* =========================
   AJAX: VIEW DETAILS (JSON)
   ========================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'view') {
    header('Content-Type: application/json; charset=utf-8');

    $src = $_GET['src'] ?? '';
    $id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    $ok = false;
    $data = [];

    if ($id > 0) {
        switch ($src) {
            case 'baptism':
                $sql = "
                  SELECT sb.*,
                         it.individual_id AS ind_id,
                         it.individual_firstname   AS ind_first_name,
                         it.individual_middlename  AS ind_middle_name,
                         it.individual_lastname    AS ind_last_name,
                         it.individual_email_address  AS ind_email,
                         it.individual_phone_number   AS ind_phone,
                         CONCAT_WS(' ',
                           CONCAT(it.individual_lastname, ','),
                           it.individual_firstname,
                           NULLIF(it.individual_middlename, '')
                         ) AS individual_fullname
                  FROM service_baptism sb
                  LEFT JOIN individual_table it ON it.individual_id = sb.individual_id
                  WHERE sb.baptism_id = ?
                  LIMIT 1
                ";
                break;
            case 'dedication':
                $sql = "
                  SELECT sd.*,
                         it.individual_id AS ind_id,
                         it.individual_firstname   AS ind_first_name,
                         it.individual_middlename  AS ind_middle_name,
                         it.individual_lastname    AS ind_last_name,
                         it.individual_email_address  AS ind_email,
                         it.individual_phone_number   AS ind_phone,
                         CONCAT_WS(' ',
                           CONCAT(it.individual_lastname, ','),
                           it.individual_firstname,
                           NULLIF(it.individual_middlename, '')
                         ) AS individual_fullname
                  FROM service_dedication sd
                  LEFT JOIN individual_table it ON it.individual_id = sd.individual_id
                  WHERE sd.dedicationId = ?
                  LIMIT 1
                ";
                break;
            case 'funeral':
                $sql = "
                  SELECT sf.*,
                         it.individual_id AS ind_id,
                         it.individual_firstname   AS ind_first_name,
                         it.individual_middlename  AS ind_middle_name,
                         it.individual_lastname    AS ind_last_name,
                         it.individual_email_address  AS ind_email,
                         it.individual_phone_number   AS ind_phone,
                         CONCAT_WS(' ',
                           CONCAT(it.individual_lastname, ','),
                           it.individual_firstname,
                           NULLIF(it.individual_middlename, '')
                         ) AS individual_fullname
                  FROM service_funeral sf
                  LEFT JOIN individual_table it ON it.individual_id = sf.individual_id
                  WHERE sf.funeral_id = ?
                  LIMIT 1
                ";
                break;
            case 'house':
                $sql = "
                  SELECT sh.*,
                         it.individual_id AS ind_id,
                         it.individual_firstname   AS ind_first_name,
                         it.individual_middlename  AS ind_middle_name,
                         it.individual_lastname    AS ind_last_name,
                         it.individual_email_address  AS ind_email,
                         it.individual_phone_number   AS ind_phone,
                         CONCAT_WS(' ',
                           CONCAT(it.individual_lastname, ','),
                           it.individual_firstname,
                           NULLIF(it.individual_middlename, '')
                         ) AS individual_fullname
                  FROM service_house sh
                  LEFT JOIN individual_table it ON it.individual_id = sh.individual_id
                  WHERE sh.house_id = ?
                  LIMIT 1
                ";
                break;
            case 'wedding':
                $sql = "
                  SELECT sw.*,
                         it.individual_id AS ind_id,
                         it.individual_firstname   AS ind_first_name,
                         it.individual_middlename  AS ind_middle_name,
                         it.individual_lastname    AS ind_last_name,
                         it.individual_email_address  AS ind_email,
                         it.individual_phone_number   AS ind_phone,
                         CONCAT_WS(' ',
                           CONCAT(it.individual_lastname, ','),
                           it.individual_firstname,
                           NULLIF(it.individual_middlename, '')
                         ) AS individual_fullname
                  FROM service_wedding sw
                  LEFT JOIN individual_table it ON it.individual_id = sw.individual_id
                  WHERE sw.wedding_id = ?
                  LIMIT 1
                ";
                break;
            default:
                $sql = null;
        }

        if ($sql && ($stmt = mysqli_prepare($db_connection, $sql))) {
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);

            if ($row) {
                $ok = true;

                // Normalized keys (for modal schemas below)
                $row['individual_last_name']   = $row['ind_last_name']   ?? '';
                $row['individual_first_name']  = $row['ind_first_name']  ?? '';
                $row['individual_middle_name'] = $row['ind_middle_name'] ?? '';
                $row['ind_contact']            = $row['ind_phone']       ?? '';
                $row['email_address']          = $row['ind_email']       ?? ($row['email_address'] ?? '');
                $row['contact_number']         = $row['ind_phone']       ?? ($row['contact_number'] ?? $row['contact_info'] ?? '');

                $data = $row;
            }
        }
    }

    echo json_encode(['ok' => $ok, 'data' => $data]);
    exit;
}

/* =========================
   MAIN PAGE QUERY
   ========================= */
$sql = "
SELECT 
    sb.service_date,
    CAST(sb.service_time AS CHAR) COLLATE utf8mb4_general_ci AS service_time,
    'Baptism' COLLATE utf8mb4_general_ci AS event_type,
    CONCAT_WS(' ',
      CONCAT(it.individual_lastname, ','),
      it.individual_firstname,
      NULLIF(it.individual_middlename, '')
    ) COLLATE utf8mb4_general_ci AS fullname,
    COALESCE(
      NULLIF(CONCAT_WS(' / ',
        NULLIF(it.individual_phone_number,''),
        NULLIF(it.individual_email_address,'')
      ),''),
      sb.email_address,
      ''
    ) COLLATE utf8mb4_general_ci AS contact,
    sb.service_status COLLATE utf8mb4_general_ci AS service_status,
    'baptism' COLLATE utf8mb4_general_ci AS src,
    CAST(sb.baptism_id AS UNSIGNED) AS pk
FROM service_baptism sb
LEFT JOIN individual_table it ON it.individual_id = sb.individual_id
WHERE sb.service_status = 'Scheduled'

UNION ALL
SELECT 
    sd.service_date,
    CAST(sd.service_time AS CHAR) COLLATE utf8mb4_general_ci AS service_time,
    'Dedication' COLLATE utf8mb4_general_ci AS event_type,
    CONCAT_WS(' ',
      CONCAT(it.individual_lastname, ','),
      it.individual_firstname,
      NULLIF(it.individual_middlename, '')
    ) COLLATE utf8mb4_general_ci AS fullname,
    COALESCE(
      NULLIF(CONCAT_WS(' / ',
        NULLIF(it.individual_phone_number,''),
        NULLIF(it.individual_email_address,'')
      ),''),
      sd.guardian_contact,
      ''
    ) COLLATE utf8mb4_general_ci AS contact,
    sd.service_status COLLATE utf8mb4_general_ci AS service_status,
    'dedication' COLLATE utf8mb4_general_ci AS src,
    CAST(sd.dedicationId AS UNSIGNED) AS pk
FROM service_dedication sd
LEFT JOIN individual_table it ON it.individual_id = sd.individual_id
WHERE sd.service_status = 'Scheduled'

UNION ALL
SELECT 
    sf.service_date,
    CAST(sf.service_time AS CHAR) COLLATE utf8mb4_general_ci AS service_time,
    'Funeral' COLLATE utf8mb4_general_ci AS event_type,
    CONCAT_WS(' ',
      CONCAT(it.individual_lastname, ','),
      it.individual_firstname,
      NULLIF(it.individual_middlename, '')
    ) COLLATE utf8mb4_general_ci AS fullname,
    COALESCE(
      NULLIF(CONCAT_WS(' / ',
        NULLIF(it.individual_phone_number,''),
        NULLIF(it.individual_email_address,'')
      ),''),
      sf.email_address,
      ''
    ) COLLATE utf8mb4_general_ci AS contact,
    sf.service_status COLLATE utf8mb4_general_ci AS service_status,
    'funeral' COLLATE utf8mb4_general_ci AS src,
    CAST(sf.funeral_id AS UNSIGNED) AS pk
FROM service_funeral sf
LEFT JOIN individual_table it ON it.individual_id = sf.individual_id
WHERE sf.service_status = 'Scheduled'

UNION ALL
SELECT 
    sh.service_date,
    CAST(sh.service_time AS CHAR) COLLATE utf8mb4_general_ci AS service_time,
    'House Blessing' COLLATE utf8mb4_general_ci AS event_type,
    CONCAT_WS(' ',
      CONCAT(it.individual_lastname, ','),
      it.individual_firstname,
      NULLIF(it.individual_middlename, '')
    ) COLLATE utf8mb4_general_ci AS fullname,
    COALESCE(
      NULLIF(CONCAT_WS(' / ',
        NULLIF(it.individual_phone_number,''),
        NULLIF(it.individual_email_address,'')
      ),''),
      sh.contact_info,
      ''
    ) COLLATE utf8mb4_general_ci AS contact,
    sh.service_status COLLATE utf8mb4_general_ci AS service_status,
    'house' COLLATE utf8mb4_general_ci AS src,
    CAST(sh.house_id AS UNSIGNED) AS pk
FROM service_house sh
LEFT JOIN individual_table it ON it.individual_id = sh.individual_id
WHERE sh.service_status = 'Scheduled'

UNION ALL
SELECT 
    sw.service_date,
    CAST(sw.service_time AS CHAR) COLLATE utf8mb4_general_ci AS service_time,
    'Wedding' COLLATE utf8mb4_general_ci AS event_type,
    CONCAT_WS(' ',
      CONCAT(it.individual_lastname, ','),
      it.individual_firstname,
      NULLIF(it.individual_middlename, '')
    ) COLLATE utf8mb4_general_ci AS fullname,
    COALESCE(
      NULLIF(CONCAT_WS(' / ',
        NULLIF(it.individual_phone_number,''),
        NULLIF(it.individual_email_address,'')
      ),''),
      sw.contact_number,
      ''
    ) COLLATE utf8mb4_general_ci AS contact,
    sw.service_status COLLATE utf8mb4_general_ci AS service_status,
    'wedding' COLLATE utf8mb4_general_ci AS src,
    CAST(sw.wedding_id AS UNSIGNED) AS pk
FROM service_wedding sw
LEFT JOIN individual_table it ON it.individual_id = sw.individual_id
WHERE sw.service_status = 'Scheduled'

ORDER BY service_date DESC, service_time ASC
";
$result = mysqli_query($db_connection, $sql);

function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta content="width=device-width, initial-scale=1"/>
  <title>Admin – Service Schedule</title>

  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/admin-schedule-table.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/admin-schedule-table.css'); ?>">
   
  <!-- ========== ADDED: Smart Search helpers (non-breaking) ========== -->
  <style>
    .smart-help{position:relative;display:inline-block;margin-left:8px;cursor:pointer;font-size:12px;color:#334155}
    .smart-help .bubble{
      position:absolute;right:0;top:130%;
      background:#0f1720;color:#e5efff;border:1px solid rgba(255,255,255,.15);
      padding:10px 12px;border-radius:10px;width:min(440px,92vw);display:none;z-index:50;
      box-shadow:0 10px 30px rgba(0,0,0,.25)
    }
    .smart-help:hover .bubble{display:block}
    mark.smart-hit{background:#ffe08a;color:#111;padding:0 .12em;border-radius:4px}
  </style>
  <!-- ================================================================ -->

  <!-- =================== ADD BELOW THIS LINE: POLISHED MODAL DESIGN =================== -->
  <style>
    /* Header polish + layout */
    .modal-backdrop{background:rgba(17,24,39,.55)}
    #viewer .modal{
      max-width:min(880px,92vw);
      width:100%;
      border-radius:16px;
      border:1px solid #e5e7eb;
      box-shadow:0 20px 60px rgba(0,0,0,.35);
      overflow:hidden;
      background:#fff;
    }
    #viewer header{
      /* override earlier flex: switch to grid for better placement */
      display:grid;
      grid-template-columns: 1fr auto auto; /* title | attachments | close */
      align-items:center;
      gap:12px;
      padding:14px 16px;
      background:linear-gradient(180deg,#f8fafc,#f1f5f9);
      border-bottom:1px solid #e5e7eb
    }
    #viewer header h3{margin:0;font-size:18px;color:#0f172a}
    #viewer .close-btn{
      background:#0f172a;color:#fff;border:none;border-radius:12px;padding:8px 12px;cursor:pointer
    }
    #viewer .close-btn:hover{opacity:.9}
    #viewer .body{padding:16px 16px 18px}
    .btn{display:inline-flex;align-items:center;gap:8px;cursor:pointer;border:1px solid #e5e7eb;border-radius:999px;padding:8px 12px;background:#1a1a64}
    .btn i{font-size:14px}
    .btn-primary2{background:#0ea5e9;color:#fff;border-color:#0ea5e9}
    .btn-primary2:hover{filter:brightness(.97)}
    .btn-ghost{background:transparent}

    /* Field layout */
    .kv-grid{
      display:grid;grid-template-columns:220px 1fr;gap:10px 18px;align-items:start
    }
    .kv-grid .key{font-weight:600;color:#0f172a}
    .kv-grid .val{color:#111827;word-break:break-word}
    .kv-grid .val small.muted{color:#6b7280}

    /* NEW: Attachment strip for one-by-one buttons */
    .attach-area{display:flex;align-items:center;justify-content:center}
    .attach-strip{
      display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:center
    }
    .attach-btn{
      display:inline-flex;align-items:center;gap:6px;
      background:#1d4ed8;border:1px solid #1e3a8a;color:#fff;
      padding:6px 10px;border-radius:999px;cursor:pointer;font-size:13px
    }
    .attach-btn:hover{filter:brightness(1.05)}
    .attach-btn i{font-size:12px}

    /* Lightbox */
    #imageLightbox .modal{
      max-width:min(1000px,96vw);
      background:#000;border-radius:14px;overflow:hidden;border:1px solid #111;
    }
    #imageLightbox header{
      display:flex;justify-content:space-between;align-items:center;color:#fff;
      padding:10px 12px;background:rgba(0,0,0,.6);border-bottom:1px solid #111
    }
    #imageLightbox header .controls{display:flex;gap:8px}
    #imageLightbox .btn{color:#fff;background:#111827;border-color:#1f2937;border-radius:10px}
    #imageLightbox .btn:hover{filter:brightness(1.1)}
    #imageLightbox .img-wrap{display:flex;justify-content:center;align-items:center;background:#000}
    #imageLightbox img{max-width:100%;max-height:78vh;display:block}
  </style>
  <!-- =================== ADD ABOVE THIS LINE: POLISHED MODAL DESIGN =================== -->

  <!-- =================== ADD BELOW THIS LINE: MODAL POLISH v2 (VISUAL ONLY) =================== -->
  <style>
    /* Better app-like presentation without changing HTML */
    #viewer .modal{ backdrop-filter:saturate(1.1); }
    #viewer header{
      grid-template-columns: 1fr 1fr auto;      /* title | chips | close */
      grid-template-areas:
        "title chips close";
    }
    #viewer header h3{ grid-area:title; font-size:20px; font-weight:800; letter-spacing:.2px }
    #viewer .close-btn{ grid-area:close; justify-self:end; padding:8px 14px; border-radius:10px }
    #viewer .attach-area{ grid-area:chips; justify-content:flex-start }
    #attachStrip{
      justify-content:flex-start;
      gap:10px;
      max-width:100%;
    }
    .attach-btn{
      background:#e8efff;
      color:#0b306e;
      border:1px solid #c7d6ff;
      font-weight:600;
      border-radius:999px;
      padding:8px 12px;
      line-height:1;
      max-width: 260px;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .attach-btn i{ font-size:12px }
    .attach-btn:hover{ background:#dbe7ff }

    /* Make body scrollable & roomy */
    #viewer .body{
      max-height: calc(80vh - 88px);
      overflow:auto;
      background:#fff;
    }

    /* Key-Value rows with subtle separators */
    .kv-grid{ 
      grid-template-columns: 220px 1fr;
      row-gap:12px; 
    }
    .kv-grid .key{ color:#0b1b34 }
    .kv-grid .val{ color:#172033 }
    .kv-grid .key, .kv-grid .val{ padding:8px 0; }
    .kv-grid .key{ border-bottom:1px dashed #eef2f7 }
    .kv-grid .val{ border-bottom:1px dashed #f1f5fb }

    /* Mobile stacking */
    @media (max-width: 640px){
      .kv-grid{ grid-template-columns: 1fr; }
      .kv-grid .key{ opacity:.75; padding-top:14px }
      #viewer header{ grid-template-columns:1fr auto; grid-template-areas:"title close" "chips chips" }
      #attachStrip{ margin-top:6px }
    }

    /* Lightbox extras */
    #imageLightbox .img-wrap{ min-height: 40vh }
    #imageLightbox header .counter{
      opacity:.9; font-weight:600; margin-right:8px;
    }
  </style>
  <!-- =================== ADD ABOVE THIS LINE: MODAL POLISH v2 (VISUAL ONLY) =================== -->
</head>
<body>

<!-- ============== SIDEBAR ============== -->
<!-- ======================== SIDEBAR ======================== -->
<aside class="sidebar">
  <div class="brand">
    <img src="image/httc_main-logo.jpg" alt="" />
    <span>HTCCC SYSTEM</span>
  </div>

  <div class="user-card">
    <img src="css/image/profile.png" alt="user">
    <div>
      <div class="user-title">Secretary</div>
      <div class="user-sub">Dashboard</div>
    </div>
  </div>

  <nav class="nav">
    <div class="section-title">Main</div>
    <a class="navlink" href="secretary_dashboard.php"><i class="fas fa-th-large"></i>Dashboard</a>

    <div class="section-title">Online Requests</div>
    <a class="navlink" href="admin-schedule-request.php">
      <i class="far fa-calendar-plus"></i>Appointment Requests
    </a>
    <a class="navlink" href="admin-prayer-request.php">
      <i class="far fa-hand-paper"></i><span>Prayer Requests</span>
    </a>

    <div class="section-title">Schedule</div>
      <a class="navlink" href="admin-schedule-approved.php">
      <i class="far fa-calendar-alt"></i>Approved Appointments
    </a>
    <a class="navlink active" href="admin-schedule-table.php">
      <i class="fas fa-calendar-alt"></i>Service Schedule
    </a>

    <div class="section-title">Applications</div>
    <a class="navlink" href="application_ministry.php">
      <i class="fas fa-users"></i>Ministry Applications
    </a>

    <div class="section-title">Streaming</div>
    <a class="navlink" href="admin-multimedia.php">
      <i class="fas fa-video"></i>Streaming
    </a>
    <a class="navlink" href="admin-ministry-women.php">
      <i class="fas fa-female"></i>Handmaid's of the Lord
    </a>
    <a class="navlink" href="admin-ministry-men.php">
      <i class="fas fa-male"></i>Men's Ministry
    </a>
    <a class="navlink" href="admin-ministry-music.php">
      <i class="fas fa-music"></i>Music's Ministry
    </a>
    <a class="navlink" href="admin-ministry-usher.php">
      <i class="fas fa-hands-helping"></i>Usher &amp; Usherette
    </a>
    <a class="navlink" href="admin-ministry-junior.php">
      <i class="fas fa-child"></i>Junior Christ Ambassador
    </a>

    <div class="section-title">Reports</div>
    <a class="navlink" href="admin-reports.php">
      <i class="fas fa-file-alt"></i>Reports
    </a>

    <div class="section-title">Content</div>
    <a class="navlink" href="content-management_home-page.php">
      <i class="fas fa-edit"></i>Content Management
    </a>

    <div class="section-title">Certificates</div>
    <a class="navlink" href="certificate-table.php">
      <i class="fa fa-certificate"></i>Generate Certificate
    </a>
    <div class="section-title">Account</div>
    <a class="navlink" href="admin-account-settings.php">
      <i class="fas fa-user-cog"></i>Account Settings
    </a>
    <div class="section-title">More</div>
    <a class="navlink logout" href="all_log-in.php">
      <img alt="Logout" class="icon" src="image/logo-logout.png" width="18" height="18" style="vertical-align:middle;margin-right:8px;">
      Log Out
    </a>
  </nav>
</aside>

<!-- ============== MAIN PAGE CONTENT ============== -->
<div class="page">
  <header class="topbar">
    <h1>Service Schedule Table</h1>
  </header>

  <div class="container">
    <div class="top-bar">
      <input id="searchBox" type="text" placeholder="Search by any text…" class="search-box">
      <div class="actions">
        <a class="btn btn-primary" href="admin-set-schedule.php"><i class="far fa-calendar-plus"></i> Schedule a service</a>
        <select id="sortBy" class="select" aria-label="Sort schedules">
          <option value="date_desc">Newest date first</option>
          <option value="date_asc">Oldest date first</option>
          <option value="event_asc">Event A → Z</option>
          <option value="name_asc">Name A → Z</option>
          <option value="status_asc">Status A → Z</option>
        </select>
      </div>
    </div>

    <table id="schedTable">
      <thead>
        <tr>
          <th>Service Date</th>
          <th>Event Type</th>
          <th>Fullname (Appointing Person)</th>
          <th>Contact / Email</th>
          <th>Status</th>
          <th style="text-align:center;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php
        if ($result && mysqli_num_rows($result) > 0) {
          while ($row = mysqli_fetch_assoc($result)) {
            $rawDate = $row['service_date'] ?: '';
            $dPretty = $rawDate ? date('F j, Y', strtotime($rawDate)) : '—';
            $rawTime = trim((string)$row['service_time']);
            $tPretty = $rawTime ? ' @ '.h($rawTime) : '';
            $event = $row['event_type'];
            $name = $row['fullname']; // from individual_table
            $contact = $row['contact'] ?: '—';
            $status = $row['service_status'];

            // data-* for sorting + responsive labels
            echo "<tr data-date='".h($rawDate)."' data-time='".h($rawTime)."' data-event='".strtolower(h($event))."' data-name='".strtolower(h($name))."' data-status='".strtolower(h($status))."'>";

            echo "<td data-label='Service Date'>".$dPretty.$tPretty."</td>";

            // Event Chip
            echo "<td data-label='Event Type'><span class='chip chip--event'>".h($event)."</span></td>";

            echo "<td data-label='Fullname (Appointing Person)'>".h($name ?: '—')."</td>";
            echo "<td data-label='Contact / Email'>".h($contact)."</td>";
            echo "<td data-label='Status'><span class='badge'>".h($status)."</span></td>";

            echo "<td data-label='Action' style='text-align:center;'>
                    <button class='btn-view' data-src='".h($row['src'])."' data-id='".(int)$row['pk']."'>
                      <i class='fas fa-eye'></i> View
                    </button>
                  </td>";

            echo "</tr>";
          }
        } else {
          echo "<tr><td colspan='6' style='padding:18px;'>No scheduled services found.</td></tr>";
        }
        ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== Modal ===== -->
<div class="modal-backdrop" id="viewer">
  <div class="modal">
    <header>
      <h3 id="modalTitle">Service Details</h3>
      <!-- Existing single button (kept for compatibility; will auto-hide when strip exists) -->
      <div class="attach-area">
        <button id="btnViewImages" class="btn btn-primary2" style="display:none"><i class="fas fa-image"></i> View Attachment(s)</button>
        <!-- =================== ADD BELOW THIS LINE: MULTI-BUTTON STRIP (INJECTED VIA JS) =================== -->
        <div id="attachStrip" class="attach-strip" style="display:none"></div>
        <!-- =================== ADD ABOVE THIS LINE: MULTI-BUTTON STRIP =================== -->
      </div>
      <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i> Close</button>
    </header>
    <div class="body">
      <div class="kv kv-grid" id="kvBody"></div>
    </div>
  </div>
</div>

<!-- =================== ADD BELOW THIS LINE: IMAGE LIGHTBOX MODAL =================== -->
<div class="modal-backdrop" id="imageLightbox" style="display:none">
  <div class="modal">
    <header>
      <div>Attachment Viewer <span id="lbCounter" class="counter"></span></div>
      <div class="controls">
        <button id="imgPrev" class="btn"><i class="fas fa-chevron-left"></i> Prev</button>
        <button id="imgNext" class="btn">Next <i class="fas fa-chevron-right"></i></button>
        <button id="imgClose" class="btn"><i class="fas fa-times"></i> Close</button>
      </div>
    </header>
    <div class="img-wrap">
      <img id="lightboxImg" src="" alt="Attachment"/>
    </div>
  </div>
</div>
<!-- =================== ADD ABOVE THIS LINE: IMAGE LIGHTBOX MODAL =================== -->

<script>
// --- Search ---
const sb = document.getElementById('searchBox');
sb.addEventListener('input', () => {
  const q = sb.value.toLowerCase();
  document.querySelectorAll('#schedTable tbody tr').forEach(tr => {
    tr.style.display = tr.innerText.toLowerCase().includes(q) ? '' : 'none';
  });
});

// --- Modal helpers ---
function closeModal(){
  document.getElementById('viewer').style.display = 'none';
  document.getElementById('kvBody').innerHTML = '';
  const b = document.getElementById('btnViewImages');
  if (b) { b.style.display = 'none'; b.dataset.images = ''; b.dataset.index = '0'; }
  const strip = document.getElementById('attachStrip');
  if (strip){ strip.style.display='none'; strip.innerHTML=''; strip.dataset.images=''; }
}

// --- View button (AJAX) --- (ORIGINAL — left intact)
document.querySelectorAll('button.btn-view').forEach(btn => {
  btn.addEventListener('click', async (e) => {
    const src = e.currentTarget.dataset.src;
    const id  = e.currentTarget.dataset.id;

    try{
      const res = await fetch(`admin-schedule-table.php?ajax=view&src=${encodeURIComponent(src)}&id=${encodeURIComponent(id)}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const json = await res.json();
      if(!json.ok){ throw new Error('Details not found'); }

      const kv = document.getElementById('kvBody');
      const title = document.getElementById('modalTitle');

      title.textContent = (json.data.Event || json.data.event_type || 'Service') + ' Details';

      const rows = [];
      for (const [k,v] of Object.entries(json.data)) {
        rows.push(`<div>${k}</div><div>${v ? String(v) : '—'}</div>`);
      }
      kv.innerHTML = rows.join('');
      document.getElementById('viewer').style.display = 'flex';
    }catch(err){
      alert('Unable to load details. ' + err.message);
    }
  });
});

// --- Sorting ---
const sortSelect = document.getElementById('sortBy');
const tbody = document.querySelector('#schedTable tbody');

function sortRows(mode){
  const rows = Array.from(tbody.querySelectorAll('tr'));
  rows.sort((a,b)=>{
    const ad=a.dataset.date||'', bd=b.dataset.date||'';
    const at=a.dataset.time||'', bt=b.dataset.time||'';
    const ae=a.dataset.event||'', be=b.dataset.event||'';
    const an=a.dataset.name||'',  bn=b.dataset.name||'';
    const as=a.dataset.status||'', bs=b.dataset.status||'';
    switch(mode){
      case 'date_asc':  return ad.localeCompare(bd) || at.localeCompare(bt);
      case 'date_desc': return bd.localeCompare(ad) || bt.localeCompare(at);
      case 'event_asc': return ae.localeCompare(be);
      case 'name_asc':  return an.localeCompare(bn);
      case 'status_asc':return as.localeCompare(bs);
      default:          return bd.localeCompare(ad) || bt.localeCompare(at);
    }
  });
  rows.forEach(r=>tbody.appendChild(r));
}
sortRows(sortSelect.value);
sortSelect.addEventListener('change', ()=>sortRows(sortSelect.value));

/* ==========================================================
   ADDED (functions only): Limit #schedTable to 4 visible rows
========================================================== */
(function(){
  const TABLE_SELECTOR = '#schedTable';
  const WRAP_CLASS = 'js-table-scroll-wrap';

  function ensureWrapper(table) {
    if (table.parentElement && table.parentElement.classList.contains(WRAP_CLASS)) {
      return table.parentElement;
    }
    const wrap = document.createElement('div');
    wrap.className = WRAP_CLASS;
    wrap.style.overflowY = 'auto';
    wrap.style.width = '100%';
    table.parentElement.insertBefore(wrap, table);
    wrap.appendChild(table);
    return wrap;
  }

  function firstVisibleRow(tbody) {
    const rows = tbody ? Array.from(tbody.rows) : [];
    return rows.find(r => r && r.offsetParent !== null);
  }

  function setRowLimit(n) {
    const table = document.querySelector(TABLE_SELECTOR);
    if (!table) return;

    const wrap = ensureWrapper(table);
    const theadRow = table.tHead && table.tHead.rows[0] ? table.tHead.rows[0] : null;
    const tbody = table.tBodies && table.tBodies[0] ? table.tBodies[0] : null;
    if (!theadRow || !tbody) return;

    const headH = Math.ceil(theadRow.getBoundingClientRect().height || 44);
    const rowEl = firstVisibleRow(tbody) || tbody.rows[0];
    if (!rowEl) return;
    const rowH = Math.ceil(rowEl.getBoundingClientRect().height || 44);

    const total = headH + (rowH * n) + 2;
    wrap.style.maxHeight = total + 'px';
  }

  function applyLimit() { setRowLimit(8); }

  window.addEventListener('load', applyLimit);
  window.addEventListener('resize', applyLimit);
  window.addEventListener('load', () => {
    setTimeout(applyLimit, 120);
    setTimeout(applyLimit, 300);
  });
})();

/* ==========================================================
   ADDED: SMART SEARCH (Always ON, no checkbox)
========================================================== */

// Inject small help bubble beside the search box (UI add, no HTML edits)
(function injectSmartHelp(){
  const input = document.getElementById('searchBox');
  if (!input) return;
  const wrapper = input.parentElement || input;
  const tip = document.createElement('span');
  tip.className = 'smart-help';
  tip.innerHTML = `?
    <span class="bubble">
      <div style="font-weight:700;margin-bottom:6px">Smart search (always on)</div>
      <ul style="margin:0;padding-left:16px;line-height:1.5">
        <li><code>"exact phrase"</code>, <code>-exclude</code></li>
        <li><code>event:wedding|baptism</code>, <code>status:scheduled</code></li>
        <li><code>name:juan</code>, <code>contact:gmail.com</code>, <code>time:10:00</code></li>
        <li><code>date:2025-10-01</code> or <code>date:2025-10-01..2025-10-31</code></li>
        <li><code>healing~</code> (fuzzy)</li>
      </ul>
    </span>`;
  wrapper.appendChild(tip);
})();

// Utilities
function ss_norm(s){ return (s||'').toString().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase(); }
function ss_lev(a,b){
  a=ss_norm(a); b=ss_norm(b);
  const m=[]; for(let i=0;i<=b.length;i++){ m[i]=[i]; } for(let j=0;j<=a.length;j++){ m[0][j]=j; }
  for(let i=1;i<=b.length;i++){ for(let j=1;j<=a.length;j++){
    m[i][j]=Math.min(m[i-1][j]+1, m[i][j-1]+1, m[i-1][j-1]+(a[j-1]===b[i-1]?0:1));
  }} return m[b.length][a.length];
}
function ss_parseDateToTs(s){
  if(!s) return null;
  const m=s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if(m){ const d=new Date(+m[1],+m[2]-1,+m[3]); const t=d.getTime(); return isNaN(t)?null:Math.floor(t/1000); }
  const t=Date.parse(s); return isNaN(t)?null:Math.floor(t/1000);
}
function ss_cellDateToTs(cellText){
  if(!cellText) return null;
  const parts = String(cellText).split('@');
  const dateOnly = parts[0].trim();
  const t = Date.parse(dateOnly);
  return isNaN(t) ? null : Math.floor(t/1000);
}
function ss_clearHighlights(row){
  row.querySelectorAll('mark.smart-hit').forEach(el=>{
    const p=el.parentNode; p.replaceChild(document.createTextNode(el.textContent), el); p.normalize();
  });
}
function ss_highlightRow(row, needles){
  if(!needles||!needles.length) return;
  const walker=document.createTreeWalker(row, NodeFilter.SHOW_TEXT, {
    acceptNode(node){
      if(!node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
      if(node.parentElement && (node.parentElement.tagName==='BUTTON' || node.parentElement.closest('button'))) return NodeFilter.FILTER_REJECT;
      return NodeFilter.FILTER_ACCEPT;
    }
  });
  const nodes=[]; while(walker.nextNode()) nodes.push(walker.currentNode);
  for(const n of nodes){
    const txt=n.nodeValue, low=ss_norm(txt);
    let ranges=[];
    for(const needle of needles){
      const q=ss_norm(needle); if(!q) continue;
      let idx=0; while((idx=low.indexOf(q,idx))!==-1){ ranges.push([idx,idx+q.length]); idx+=q.length; }
    }
    if(!ranges.length) continue;
    ranges.sort((a,b)=>a[0]-b[0]);
    const merged=[]; let [s,e]=ranges[0];
    for(let i=1;i<ranges.length;i++){ const [ns,ne]=ranges[i]; if(ns<=e){ e=Math.max(e,ne); } else { merged.push([s,e]); [s,e]=[ns,ne]; } }
    merged.push([s,e]);
    const frag=document.createDocumentFragment(); let last=0;
    for(const [ms,me] of merged){
      if(last<ms) frag.appendChild(document.createTextNode(txt.slice(last,ms)));
      const mark=document.createElement('mark'); mark.className='smart-hit'; mark.textContent=txt.slice(ms,me);
      frag.appendChild(mark); last=me;
    }
    if(last<txt.length) frag.appendChild(document.createTextNode(txt.slice(last)));
    n.parentNode.replaceChild(frag,n);
  }
}
function ss_parseQuery(q){
  const tokens=[], neg=[], fields={name:[], event:[], status:[], contact:[], time:[], date:[], phrase:[]}, fuzzy=[];
  q=q.trim(); if(!q) return {tokens,neg,fields,fuzzy};
  const ph=[]; q=q.replace(/"([^"]+)"/g,(_,p)=>{ ph.push(p.trim()); return ' '; }); fields.phrase.push(...ph);
  const parts=q.split(/\s+/).filter(Boolean);
  for(const part of parts){
    if(/^-/.test(part)){ neg.push(part.slice(1)); continue; }
    const m=part.match(/^(\w+):(.*)$/);
    if(m){
      const k=m[1].toLowerCase(), v=m[2];
      if(k==='name') fields.name.push(v);
      else if(k==='event') fields.event.push(...v.split('|').map(s=>s.toLowerCase()));
      else if(k==='status') fields.status.push(...v.split('|').map(s=>s.toLowerCase()));
      else if(k==='contact') fields.contact.push(v);
      else if(k==='time') fields.time.push(v.toLowerCase());
      else if(k==='date') fields.date.push(v);
      else tokens.push(part);
    } else if(part.endsWith('~')){ fuzzy.push(part.slice(0,-1)); }
    else if(part==='~'){ /* ignore */ }
    else { tokens.push(part); }
  }
  return {tokens,neg,fields,fuzzy};
}

function ss_smartFilter(){
  const input = document.getElementById('searchBox');
  const tableBody = document.querySelector('#schedTable tbody');
  if(!input || !tableBody) return;

  const rows = Array.from(tableBody.querySelectorAll('tr'));
  rows.forEach(ss_clearHighlights);
  const q = input.value || '';
  const parsed = ss_parseQuery(q);
  const needles = [
    ...parsed.fields.phrase,
    ...parsed.tokens,
    ...parsed.fields.name,
    ...parsed.fields.contact,
    ...parsed.fields.event,
    ...parsed.fields.status,
    ...parsed.fields.time
  ];

  rows.forEach(row=>{
    if(row.style.display==='none'){ return; }

    const cDate = row.cells[0]?.innerText || '';
    const cEvent= row.cells[1]?.innerText || '';
    const cName = row.cells[2]?.innerText || '';
    const cCont = row.cells[3]?.innerText || '';
    const cStat = row.cells[4]?.innerText || '';
    const flat  = `${cDate} ${cEvent} ${cName} ${cCont} ${cStat}`;

    const whole = ss_norm(flat);

    if(parsed.fields.name.length){
      const ok = parsed.fields.name.some(n => ss_norm(cName).includes(ss_norm(n)));
      if(!ok){ row.style.display='none'; return; }
    }

    if(parsed.fields.event.length){
      const ev = ss_norm(cEvent);
      const ok = parsed.fields.event.some(e => ev===e || ev.includes(e));
      if(!ok){ row.style.display='none'; return; }
    }

    if(parsed.fields.status.length){
      const st = ss_norm(cStat);
      const ok = parsed.fields.status.some(s => st===s || st.includes(s));
      if(!ok){ row.style.display='none'; return; }
    }

    if(parsed.fields.contact.length){
      const cont = ss_norm(cCont);
      const ok = parsed.fields.contact.some(x => cont.includes(ss_norm(x)));
      if(!ok){ row.style.display='none'; return; }
    }

    if(parsed.fields.time.length){
      const ok = parsed.fields.time.some(t => ss_norm(cDate).includes(t));
      if(!ok){ row.style.display='none'; return; }
    }

    if(parsed.fields.date.length){
      const ts = ss_cellDateToTs(cDate);
      let dateOK=false;
      for(const d of parsed.fields.date){
        if(d.includes('..')){
          const [a,b]=d.split('..');
          const ta=ss_parseDateToTs(a), tb=ss_parseDateToTs(b);
          if(ts && ta && tb && ts>=ta && ts<=tb){ dateOK=true; break; }
        } else {
          const t=ss_parseDateToTs(d);
          if(ts && t && Math.abs(ts-t)<86400){ dateOK=true; break; }
        }
      }
      if(!dateOK){ row.style.display='none'; return; }
    }

    if(parsed.neg.length){
      const bad = parsed.neg.some(n => whole.includes(ss_norm(n)));
      if(bad){ row.style.display='none'; return; }
    }

    if(parsed.fields.phrase.length){
      const ok = parsed.fields.phrase.every(p => whole.includes(ss_norm(p)));
      if(!ok){ row.style.display='none'; return; }
    }

    if(parsed.tokens.length){
      const ok = parsed.tokens.every(t => whole.includes(ss_norm(t)));
      if(!ok){ row.style.display='none'; return; }
    }

    if(parsed.fuzzy.length){
      const ok = parsed.fuzzy.some(f => {
        if(whole.includes(ss_norm(f))) return true;
        if(f.length>=5) return ss_lev(flat, f) <= 2;
        return false;
      });
      if(!ok){ row.style.display='none'; return; }
    }

    ss_highlightRow(row, needles);
  });
}
['input','change','keyup'].forEach(ev=>{
  sb.addEventListener(ev, ss_smartFilter);
});
window.addEventListener('load', ss_smartFilter);

/* =================== ADD BELOW THIS LINE: CLEAN VIEW + IMAGE BUTTON FLOW =================== */
(function(){
  // Curated fields per source
  const VIEW_SCHEMAS = {
    baptism: {
      title: 'Baptism Details',
      fields: [
        ['service_date', 'Service Date'],
        ['service_time', 'Service Time'],
        ['service_status', 'Status'],

        // Individual breakdown (appointing person)
        ['individual_last_name', 'Appointer Last Name'],
        ['individual_first_name', 'Appointer First Name'],
        ['individual_middle_name', 'Appointer Middle Name'],
        ['individual_fullname', 'Appointer (Full)'],
        ['ind_email', 'Appointer Email'],
        ['ind_phone', 'Appointer Phone'],

        // Original form fields
        ['baptized_name', 'Baptized Name'],
        ['parents_name', 'Parents/Guardian'],
        ['place_of_baptism', 'Place'],
        ['officiating_minister', 'Officiating Minister'],
      ],
      images: ['image_path','photo','file_path','certificate_image','baptism_image']
    },
    dedication: {
      title: 'Dedication Details',
      fields: [
        ['service_date', 'Service Date'],
        ['service_time', 'Service Time'],
        ['service_status', 'Status'],

        ['individual_last_name', 'Appointer Last Name'],
        ['individual_first_name', 'Appointer First Name'],
        ['individual_middle_name', 'Appointer Middle Name'],
        ['individual_fullname', 'Appointer (Full)'],
        ['ind_email', 'Appointer Email'],
        ['ind_phone', 'Appointer Phone'],

        ['child_full_name', 'Child Full Name'],
        ['parents_name', 'Parents'],
        ['place_of_dedication', 'Place'],
        ['officiating_minister', 'Officiating Minister'],
      ],
      images: ['image_path','photo','file_path','certificate_image','dedication_image']
    },
    funeral: {
      title: 'Funeral Details',
      fields: [
        ['service_date', 'Service Date'],
        ['service_time', 'Service Time'],
        ['service_status', 'Status'],

        ['individual_last_name', 'Appointer Last Name'],
        ['individual_first_name', 'Appointer First Name'],
        ['individual_middle_name', 'Appointer Middle Name'],
        ['individual_fullname', 'Appointer (Full)'],
        ['ind_email', 'Appointer Email'],
        ['ind_phone', 'Appointer Phone'],

        ['deceased_name', 'Deceased Name'],
        ['wake_location', 'Wake Location'],
        ['burial_location', 'Burial Location'],
        ['officiating_minister', 'Officiating Minister'],
      ],
      images: ['image_path','photo','file_path','death_certificate','funeral_image']
    },
    house: {
      title: 'House Blessing Details',
      fields: [
        ['service_date', 'Service Date'],
        ['service_time', 'Service Time'],
        ['service_status', 'Status'],

        ['individual_last_name', 'Appointer Last Name'],
        ['individual_first_name', 'Appointer First Name'],
        ['individual_middle_name', 'Appointer Middle Name'],
        ['individual_fullname', 'Appointer (Full)'],
        ['ind_email', 'Appointer Email'],
        ['ind_phone', 'Appointer Phone'],

        ['owner_full_name', 'Owner Full Name'],
        ['address', 'Address'],
        ['officiating_minister', 'Officiating Minister'],
      ],
      images: ['image_path','photo','file_path','house_image']
    },
    wedding: {
      title: 'Wedding Details',
      fields: [
        ['service_date', 'Service Date'],
        ['service_time', 'Service Time'],
        ['service_status', 'Status'],

        ['individual_last_name', 'Appointer Last Name'],
        ['individual_first_name', 'Appointer First Name'],
        ['individual_middle_name', 'Appointer Middle Name'],
        ['individual_fullname', 'Appointer (Full)'],
        ['ind_email', 'Appointer Email'],
        ['ind_phone', 'Appointer Phone'],

        ['groom_name', 'Groom'],
        ['bride_name', 'Bride'],
        ['contact_number', 'Contact Number (Record)'],
        ['venue', 'Venue'],
        ['officiating_minister', 'Officiating Minister'],
      ],
      images: ['image_path','photo','file_path','marriage_license','wedding_image']
    }
  };

  function esc(s){
    const span=document.createElement('span');
    span.textContent = (s==null?'':String(s));
    return span.innerHTML;
  }

  function isImagePath(v){
    if(!v) return false;
    const s=String(v).trim();
    return /\.(png|jpe?g|gif|webp|bmp|svg)$/i.test(s);
  }

  function pickImages(obj, preferredKeys){
    const imgs=[];
    for(const k of preferredKeys){
      if(obj[k] && isImagePath(obj[k])) imgs.push(String(obj[k]).trim());
    }
    if(!imgs.length){
      Object.values(obj).forEach(v=>{
        if(typeof v==='string' && isImagePath(v)) imgs.push(v.trim());
      });
    }
    return Array.from(new Set(imgs));
  }

  async function fetchView(src,id){
    const res = await fetch(`admin-schedule-table.php?ajax=view&src=${encodeURIComponent(src)}&id=${encodeURIComponent(id)}`, {
      headers:{'X-Requested-With':'XMLHttpRequest'}
    });
    const json = await res.json();
    if(!json.ok) throw new Error('Details not found');
    return json.data || {};
  }

  function renderCleanView(src, data){
    const schema = VIEW_SCHEMAS[src] || {title:'Service Details', fields:[], images:[]};
    const titleEl = document.getElementById('modalTitle');
    const kv = document.getElementById('kvBody');
    const btnView = document.getElementById('btnViewImages');
    const strip = document.getElementById('attachStrip');

    titleEl.textContent = schema.title;

    // Build curated rows
    const rows = [];
    schema.fields.forEach(([key,label])=>{
      if(Object.prototype.hasOwnProperty.call(data,key)){
        const val = data[key];
        const display = (val===null || val==='' ? '<small class="muted">—</small>' : esc(val));
        rows.push(`<div class="key">${esc(label)}</div><div class="val">${display}</div>`);
      }
    });

    if(!rows.length){
      const COMMON = ['service_date','service_time','service_status','individual_fullname','ind_email','ind_phone'];
      COMMON.forEach(k=>{
        if(Object.prototype.hasOwnProperty.call(data,k)){
          rows.push(`<div class="key">${esc(k.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()))}</div><div class="val">${esc(data[k])}</div>`);
        }
      });
    }

    kv.innerHTML = rows.join('');

    // Image buttons logic
    const imgs = pickImages(data, schema.images || []);
    // Keep dataset for lightbox controls
    btnView.dataset.images = JSON.stringify(imgs);
    btnView.dataset.index = '0';

    if(imgs.length){
      // Hide legacy single button and show the strip of individual buttons
      btnView.style.display = 'none';
      strip.style.display = 'flex';
      strip.innerHTML = '';
      strip.dataset.images = JSON.stringify(imgs);

      imgs.forEach((_, idx)=>{
        const b = document.createElement('button');
        b.className = 'attach-btn';
        b.type = 'button';
        b.dataset.idx = String(idx);
        b.innerHTML = `<i class="fas fa-image"></i> Attachment ${idx+1}`;
        b.addEventListener('click', ()=>{
          openLightbox(imgs, idx);
        });
        strip.appendChild(b);
      });
    }else{
      strip.style.display='none';
      strip.innerHTML='';
      // fallback to single button if you ever want it visible when 1 file; currently we keep it hidden if none
      btnView.style.display='none';
    }

    document.getElementById('viewer').style.display = 'flex';
  }

  // Lightbox helpers
  function openLightbox(imgs, idx){
    const lb = document.getElementById('imageLightbox');
    lb.style.display='flex';
    setLightbox(imgs, idx);
  }
  function setLightbox(imgs, idx){
    const tag = document.getElementById('lightboxImg');
    if(!imgs.length) return;
    const i = ((idx%imgs.length)+imgs.length)%imgs.length;
    tag.src = imgs[i];
    tag.dataset.index = String(i);
    tag.dataset.total = String(imgs.length);
    const ctr = document.getElementById('lbCounter');
    if(ctr){ ctr.textContent = `(${i+1} / ${imgs.length})`; }
  }
  function closeLightbox(){
    const lb = document.getElementById('imageLightbox');
    document.getElementById('lightboxImg').src='';
    const ctr = document.getElementById('lbCounter'); if(ctr){ ctr.textContent='' }
    lb.style.display='none';
  }

  // Wire image lightbox controls
  document.getElementById('imgClose').addEventListener('click', closeLightbox);
  document.getElementById('imgPrev').addEventListener('click', ()=>{
    const tag=document.getElementById('lightboxImg');
    const total=+tag.dataset.total||1;
    const idx=(+tag.dataset.index||0)-1;
    const imgs=JSON.parse(document.getElementById('btnViewImages').dataset.images||'[]');
    setLightbox(imgs, (idx+total)%total);
  });
  document.getElementById('imgNext').addEventListener('click', ()=>{
    const tag=document.getElementById('lightboxImg');
    const total=+tag.dataset.total||1;
    const idx=(+tag.dataset.index||0)+1;
    const imgs=JSON.parse(document.getElementById('btnViewImages').dataset.images||'[]');
    setLightbox(imgs, (idx)%total);
  });

  // Keep single-button flow working (optional)
  document.getElementById('btnViewImages').addEventListener('click', ()=>{
    const imgs = JSON.parse(document.getElementById('btnViewImages').dataset.images||'[]');
    if(imgs.length){ openLightbox(imgs, 0); }
  });

  // CAPTURING handler to override the old generic viewer (no deletion)
  document.addEventListener('click', async function(e){
    const btn = e.target.closest('button.btn-view');
    if(!btn) return;
    e.preventDefault();
    e.stopImmediatePropagation();

    const src = btn.dataset.src;
    const id  = btn.dataset.id;
    try{
      const data = await fetchView(src,id);
      renderCleanView(src, data);
    }catch(err){
      alert('Unable to load details. ' + err.message);
    }
  }, true);
})();
/* =================== ADD ABOVE THIS LINE: CLEAN VIEW + IMAGE BUTTON FLOW =================== */


/* =====================================================================
   ADD BELOW THIS LINE: SPECIFIC ATTACHMENT BUTTON LABELS (NO CODE REMOVAL)
====================================================================== */
(function(){
  const strip = document.getElementById('attachStrip');
  if(!strip) return;

  function baseName(path){
    try{
      const qless = String(path).split('?')[0].split('#')[0];
      const parts = qless.split(/[\\/]/);
      return parts.pop() || qless;
    }catch(e){ return String(path) }
  }

  function extName(file){
    const m = String(file).match(/\.([a-z0-9]+)$/i);
    return m ? m[1] : '';
  }

  function titleCaseFromSlug(s){
    const withoutExt = s.replace(/\.[a-z0-9]+$/i,'');
    const words = withoutExt
      .replace(/(?:^|\s|[_-])([a-f0-9]{6,})(?:\.[0-9]+)?$/i,'')
      .replace(/[_-]+/g,' ')
      .replace(/\s+/g,' ')
      .trim()
      .split(' ')
      .filter(Boolean);
    if(!words.length) return s;
    return words.map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
  }

  function makeNiceLabelFromPath(p){
    const file = baseName(p);
    const pretty = titleCaseFromSlug(file);
    if(!pretty) return file || 'Attachment';
    return pretty;
  }

  function relabelButtons(){
    let imgs = [];
    try{ imgs = JSON.parse(strip.dataset.images || '[]'); }catch(e){ imgs = []; }

    const btns = strip.querySelectorAll('button.attach-btn');
    btns.forEach((b, i) => {
      const idx = Number(b.dataset.idx || i);
      const src = imgs[idx] || '';
      const label = src ? makeNiceLabelFromPath(src) : `Attachment ${i+1}`;
      b.innerHTML = `<i class="fas fa-image"></i> ${label}`;
      b.title = src || label;
      b.setAttribute('aria-label', `Open image: ${label}`);
    });
  }

  const mo = new MutationObserver(() => {
    if(strip.style.display !== 'none' && strip.children.length){
      relabelButtons();
    }
  });
  mo.observe(strip, { childList: true, attributes: true, subtree: false });

  window.addEventListener('load', relabelButtons);
})();
/* =====================================================================
   ADD ABOVE THIS LINE: SPECIFIC ATTACHMENT BUTTON LABELS
====================================================================== */


/* =================== ADD BELOW THIS LINE: NAME BREAKDOWN COLUMNS + HIDE FULLNAME =================== */
(function(){
  // 1) Helpers
  function findFullnameColIndex(headerRow){
    const ths = Array.from(headerRow.cells);
    let idx = ths.findIndex(th => /fullname/i.test(th.innerText));
    if (idx === -1) idx = 2; // fallback
    return idx;
  }
  function parseFullname(text){
    const raw = (text || '').trim();
    if (!raw || raw === '—') return { last:'', first:'', middle:'' };
    const m = raw.match(/^\s*([^,]+)\s*,\s*([^\s,]+)(?:\s+(.+))?\s*$/);
    if (m) return { last: m[1].trim(), first: m[2].trim(), middle: (m[3]||'').trim() };
    const parts = raw.split(/\s+/).filter(Boolean);
    if (parts.length === 1) return { last:'', first:parts[0], middle:'' };
    if (parts.length === 2) return { last:parts[1], first:parts[0], middle:'' };
    return { last:parts.at(-1), first:parts[0], middle:parts.slice(1,-1).join(' ') };
  }
  async function fetchIndividualNames(tr){
    // use existing JSON endpoint to pull individual_* names
    const btn = tr.querySelector('button.btn-view');
    if(!btn) return {last:'',first:'',middle:''};
    try{
      const res = await fetch(`admin-schedule-table.php?ajax=view&src=${encodeURIComponent(btn.dataset.src)}&id=${encodeURIComponent(btn.dataset.id)}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const json = await res.json();
      if(json && json.ok){
        const d = json.data || {};
        return {
          last: (d.ind_last_name || d.individual_last_name || '').trim(),
          first:(d.ind_first_name || d.individual_first_name || '').trim(),
          middle:(d.ind_middle_name || d.individual_middle_name || '').trim()
        };
      }
    }catch(e){ /* ignore */ }
    return {last:'',first:'',middle:''};
  }

  function insertNameHeaders(table, colIdx){
    const headerRow = table.tHead && table.tHead.rows[0];
    if (!headerRow) return;
    const titles = ['Last Name','First Name','Middle Name'];
    titles.forEach((title, offset) => {
      const th = document.createElement('th');
      th.textContent = title;
      const ref = headerRow.cells[colIdx + 1 + offset] ?? null;
      headerRow.insertBefore(th, ref);
    });
  }
  function hideFullnameColumn(table, colIdx){
    const head = table.tHead && table.tHead.rows[0] && table.tHead.rows[0].cells[colIdx];
    if (head){ head.style.display='none'; }
    const body = table.tBodies[0];
    if (!body) return;
    Array.from(body.rows).forEach(tr => {
      const td = tr.cells[colIdx];
      if (td){ td.style.display='none'; }
    });
  }

  function createNameCells(tr, colIdx, names){
    const lastTd   = tr.insertCell(colIdx + 1);
    lastTd.textContent = names.last || '—';
    lastTd.setAttribute('data-label','Last Name');

    const firstTd  = tr.insertCell(colIdx + 2);
    firstTd.textContent = names.first || '—';
    firstTd.setAttribute('data-label','First Name');

    const middleTd = tr.insertCell(colIdx + 3);
    middleTd.textContent = names.middle || '—';
    middleTd.setAttribute('data-label','Middle Name');
  }

  async function buildNameColumns(){
    const table = document.getElementById('schedTable');
    if (!table || !table.tHead || !table.tBodies.length) return;
    const headerRow = table.tHead.rows[0];
    const colIdx = findFullnameColIndex(headerRow);

    // Insert new headers
    insertNameHeaders(table, colIdx);

    const body = table.tBodies[0];
    const rows = Array.from(body.rows);

    // Fill rows: prefer existing fullname parse; if empty, fetch individual_* via AJAX
    for(const tr of rows){
      const fullCell = tr.cells[colIdx];
      const fullText = fullCell ? fullCell.innerText : '';
      let names = parseFullname(fullText);
      const noName = !names.last && !names.first && !names.middle;
      if (noName){
        // onsite (no online appoint) — pull directly from individual_* via JSON
        names = await fetchIndividualNames(tr);
      }
      createNameCells(tr, colIdx, names);
    }

    // Finally, visually remove the old Fullname column
    hideFullnameColumn(table, colIdx);
  }

  window.addEventListener('load', () => {
    buildNameColumns();
  });
})();
/* =================== ADD ABOVE THIS LINE: NAME BREAKDOWN COLUMNS + HIDE FULLNAME =================== */
</script>

</body>
</html>
END FILE
