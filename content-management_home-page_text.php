<?php
// content-management_home-page_text.php (bulk editable)
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db-connection.php';
mysqli_set_charset($db_connection, 'utf8mb4');

$TABLE = 'content_management_table';

/* ---------- BULK SAVE (all visible textareas) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update']) && is_array($_POST['bulk'] ?? null)) {
    $updates = $_POST['bulk']; // [contentID => new_caption]
    $ok = true;

    if ($stmt = mysqli_prepare($db_connection, "UPDATE {$TABLE} SET img_caption=? WHERE contentID=? LIMIT 1")) {
        foreach ($updates as $id => $caption) {
            $id = (int)$id;
            $cap = trim((string)$caption);
            mysqli_stmt_bind_param($stmt, "si", $cap, $id);
            if (!mysqli_stmt_execute($stmt)) { $ok = false; break; }
        }
        mysqli_stmt_close($stmt);
    } else {
        $ok = false;
    }
    header("Location: ".$_SERVER['PHP_SELF']."?saved=".($ok ? "1" : "0"));
    exit;
}

/* ---------- Fetch helpers ---------- */
function fetchByType(mysqli $db, string $table, string $ctype): array {
    $rows = [];
    $sql = "SELECT contentID, img_caption, img_upload_at, status, content_type
            FROM {$table}
            WHERE LOWER(content_type)=LOWER(?) AND (status IS NULL OR LOWER(status) <> 'inactive')
            ORDER BY COALESCE(img_upload_at, '1970-01-01') ASC, contentID ASC";
    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, "s", $ctype);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        mysqli_stmt_close($stmt);
    }
    return $rows;
}

$beliefRows  = fetchByType($db_connection, $TABLE, 'belief');   // OUR BELIEFS
$believeRows = fetchByType($db_connection, $TABLE, 'believe');  // WE BELIEVE
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta content="width=device-width, initial-scale=1" name="viewport" />
  <title>Content Management - Home Page</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
  <style>
    nav{background:#fff;display:flex;justify-content:center;gap:48px;padding:16px 0;font-weight:800;font-size:16px;color:#0B0B3B;border-bottom:1px solid #ddd}
    nav a{text-decoration:none;color:#0B0B3B;white-space:nowrap}
    .dropdown{position:relative;display:inline-block}
    .active{background:#2c3e50;color:#fff;padding:2px 16px;border-radius:5px}
    .dropdown .dropdown-content{display:none;position:absolute;background:#f1f1f1;min-width:200px;z-index:1;border-radius:5px;box-shadow:0 8px 16px rgba(0,0,0,.2)}
    .dropdown .dropdown-content a{color:#2c3e50;padding:10px 16px;display:block;text-decoration:none}
    .dropdown .dropdown-content a:hover{background:#ddd}
    .dropdown:hover .dropdown-content{display:block}
    .card{border:1px solid #d1d5db;border-radius:12px;padding:12px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.03)}
    .ptext{font-weight:800;color:#1a1a4f;font-size:15px;line-height:1.35;white-space:pre-wrap}
    .editbox{width:100%;min-height:90px;border:1px solid #cbd5e1;border-radius:8px;padding:10px;font-size:14px}
    .btn{font-weight:800;border-radius:8px;padding:8px 12px;font-size:12px}
    .btn.primary{background:#0B3B8F;color:#fff}
    .btn.save{background:#065f46;color:#fff}
    .btn.cancel{background:#e5e7eb;color:#111827}
    .toast{position:sticky;top:0;margin:0 auto 12px;max-width:900px;padding:10px 12px;border-radius:10px;font-size:14px}
    .toast.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
    .toast.err{background:#fef2f2;color:#7f1d1d;border:1px solid #fecaca}
    .hidden{display:none;}
  </style>
</head>
<body>
<header style="background-color: #0A0E3F; display: flex; align-items: center; justify-content: space-between; padding: 10px 20px; color: white;">
  <a href="secretary_dashboard.php" style="display: inline-block; z-index: 10;">
    <img src="image/btn-back.png" alt="Back" style="width: 30px; height: 30px; cursor: pointer; display: block;">
  </a>
  <div style="display: flex; align-items: center; gap: 10px;">
    <img src="image/httc_main-logo.jpg" alt="Logo" style="width: 60px; height: 60px; border-radius: 50%; margin-right: 10px;">
    <h1 style="margin: 0; font-size: 25px;">CONTENT MANAGEMENT</h1>
  </div>
  <div style="width: 30px;"></div>
</header>

<nav>
  <div class="dropdown">
    <a href="content-management_home-page.php" class="active">HOME PAGE</a>
    <div class="dropdown-content">
      <a href="content-management_home-page.php">Carousel Image</a>
      <a href="content-management_home-page_text.php">Doctrinal Content</a>
      <a href="content-management_apostol.php">Apostol Creed</a>
    </div>
  </div>
  <a href="content-management_service.php">SERVICE</a>
  <a href="content-management_events.php">EVENTS</a>
  <a href="content-management_gallery.php">GALLERY</a>
  <a href="content-management_ministries.php">MINISTRIES</a>
  <a href="content-management_join-us.php">JOIN US</a>
  <a href="content-management_find-us.php">FIND US</a>
</nav>

<main class="max-w-5xl mx-auto px-4">
  <?php if (isset($_GET['saved'])): ?>
    <div class="toast <?php echo $_GET['saved']=='1'?'ok':'err'; ?>">
      <?php echo $_GET['saved']=='1' ? 'Changes saved.' : 'Save failed.'; ?>
    </div>
  <?php endif; ?>

  <form method="post" id="bulkForm">
    <input type="hidden" name="bulk_update" value="1"/>

    <!-- OUR BELIEFS -->
    <section class="mb-8" style="margin-top: 60px;">
      <div class="flex items-center justify-between mb-2">
        <h2 class="text-[#1a1a4f] font-extrabold text-xs tracking-wide select-none">EDIT OUR BELIEFS</h2>
        <span class="text-xs text-gray-500">content_type = <code>belief</code></span>
      </div>
      <div class="card">
        <?php if (empty($beliefRows)): ?>
          <p class="ptext"><em style="color:#6b7280">No content yet.</em></p>
        <?php else: ?>
          <?php foreach ($beliefRows as $row): ?>
            <?php $id = (int)$row['contentID']; $cap = trim((string)$row['img_caption']); ?>
            <div class="mb-3">
              <!-- View mode -->
              <p class="ptext view" id="view-<?php echo $id; ?>"><?php echo htmlspecialchars($cap ?: 'No content', ENT_QUOTES, 'UTF-8'); ?></p>
              <!-- Edit mode (hidden initially) -->
              <textarea class="editbox editor hidden" name="bulk[<?php echo $id; ?>]" id="ta-<?php echo $id; ?>"><?php echo htmlspecialchars($cap, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <!-- WE BELIEVE -->
    <section class="mb-10">
      <div class="flex items-center justify-between mb-2">
        <h2 class="text-[#1a1a4f] font-extrabold text-xs tracking-wide select-none">EDIT WE BELIEVE</h2>
        <span class="text-xs text-gray-500">content_type = <code>believe</code></span>
      </div>
      <div class="card">
        <?php if (empty($believeRows)): ?>
          <p class="ptext"><em style="color:#6b7280">No content yet.</em></p>
        <?php else: ?>
          <?php foreach ($believeRows as $row): ?>
            <?php $id = (int)$row['contentID']; $cap = trim((string)$row['img_caption']); ?>
            <div class="mb-3">
              <!-- View mode -->
              <p class="ptext view" id="view-<?php echo $id; ?>"><?php echo htmlspecialchars($cap ?: 'No content', ENT_QUOTES, 'UTF-8'); ?></p>
              <!-- Edit mode (hidden initially) -->
              <textarea class="editbox editor hidden" name="bulk[<?php echo $id; ?>]" id="ta-<?php echo $id; ?>"><?php echo htmlspecialchars($cap, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <!-- Controls -->
    <div class="flex items-center justify-end gap-3 mt-6">
      <button class="btn primary" type="button" id="enableEditBtn">Enable Editing</button>
      <button class="btn cancel hidden" type="button" id="cancelBtn">Cancel</button>
      <button class="btn save hidden" type="submit" id="saveBtn">Save Changes</button>
    </div>
  </form>
</main>

<script>
  const enableBtn = document.getElementById('enableEditBtn');
  const cancelBtn = document.getElementById('cancelBtn');
  const saveBtn   = document.getElementById('saveBtn');

  function setEditMode(on){
    document.querySelectorAll('.view').forEach(el => el.classList.toggle('hidden', on));
    document.querySelectorAll('.editor').forEach(el => el.classList.toggle('hidden', !on));
    enableBtn.classList.toggle('hidden', on);
    cancelBtn.classList.toggle('hidden', !on);
    saveBtn.classList.toggle('hidden', !on);
    if(on){
      const firstTA = document.querySelector('.editor');
      if(firstTA) firstTA.focus();
    }
  }

  enableBtn.addEventListener('click', () => setEditMode(true));
  cancelBtn.addEventListener('click', () => setEditMode(false));
</script>
</body>
</html>
