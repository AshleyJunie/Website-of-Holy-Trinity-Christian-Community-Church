<?php
include 'db-connection.php';
session_start();

/* ---------- ADD DATA HANDLER (EXISTING) ---------- */
$add_success = false;
$error_msg   = '';
$old = [
  'ministry_position'      => '',
  'ministry_lastname'      => '',
  'ministry_firstname'     => '',
  'ministry_middlename'    => '',
  'ministry_extensionname' => '',
  'date_join'              => '',
];

/* Positions list used to render the dropdown and for repopulation (EXISTING) */
$POSITION_OPTIONS = [
  "Coordinator",
  "Assistant Coordinator",
  "Secretary",
  "Treasurer",
  "Worship Leader",
  "Prayer Warrior",
  "Event Coordinator",
  "Outreach Coordinator",
  "Member"
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__action']) && $_POST['__action'] === 'add_ministry') {
  $ministry_type = 'Women Ministry';

  // We still read from the same name "ministry_position" (populated by JS before submit)
  $position      = trim($_POST['ministry_position'] ?? '');
  $lastname      = trim($_POST['ministry_lastname'] ?? '');
  $firstname     = trim($_POST['ministry_firstname'] ?? '');
  $middlename    = trim($_POST['ministry_middlename'] ?? '');
  $extension     = trim($_POST['ministry_extensionname'] ?? '');
  $date_join     = trim($_POST['date_join'] ?? '');

  $old = [
    'ministry_position'      => $position,
    'ministry_lastname'      => $lastname,
    'ministry_firstname'     => $firstname,
    'ministry_middlename'    => $middlename,
    'ministry_extensionname' => $extension,
    'date_join'              => $date_join,
  ];

  if ($position === '' || $lastname === '' || $firstname === '' || $date_join === '') {
    $error_msg = 'Please complete all required fields.';
  } else {
    $d = date_create_from_format('Y-m-d', $date_join);
    if (!$d) {
      $error_msg = 'Invalid date format.';
    } else {
      $sql = "INSERT INTO ministries_table 
              (ministry_type, ministry_position, ministry_lastname, ministry_firstname, ministry_middlename, ministry_extensionname, date_join, archive_status)
              VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')";
      if ($stmt = mysqli_prepare($db_connection, $sql)) {
        mysqli_stmt_bind_param($stmt, "sssssss", $ministry_type, $position, $lastname, $firstname, $middlename, $extension, $date_join);
        if (mysqli_stmt_execute($stmt)) {
          $add_success = true;
        } else {
          $error_msg = 'Database error while adding.';
        }
        mysqli_stmt_close($stmt);
      } else {
        $error_msg = 'Failed to prepare statement.';
      }
    }
  }
}

/* ============================================================
   ADDED: SIMPLE HELPERS (no changes to existing logic above)
============================================================ */
function w_h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/* ============================================================
   ADDED: AJAX ENDPOINTS FOR EDIT FEATURE (ADD-ONLY)
   - Fetch full rows list (to map table rows to IDs safely)
   - Fetch one row by ID
   - Update (edit) a row by ID
   These do not change any existing routes/handlers.
============================================================ */

/* --- Get list for mapping (id + essentials) --- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'wm_rows') {
  header('Content-Type: application/json; charset=utf-8');
  $rows = [];
  $q = "
    SELECT ministry_id, ministry_position, ministry_lastname, ministry_firstname, ministry_middlename,
           ministry_extensionname, date_join, sex, birthday, ministry_email
    FROM ministries_table
    WHERE ministry_type='Women Ministry' AND archive_status='Active'
    ORDER BY date_join DESC, ministry_lastname, ministry_firstname
  ";
  if ($rs = mysqli_query($db_connection, $q)) {
    while ($r = mysqli_fetch_assoc($rs)) $rows[] = $r;
  }
  echo json_encode(['ok'=>true,'rows'=>$rows]);
  exit;
}

/* --- Get one row by id --- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'wm_row' && isset($_GET['id'])) {
  header('Content-Type: application/json; charset=utf-8');
  $id = (int)$_GET['id'];
  $row = null;
  if ($id > 0 && ($st = mysqli_prepare($db_connection, "
      SELECT ministry_id, ministry_position, ministry_lastname, ministry_firstname, ministry_middlename,
             ministry_extensionname, date_join, sex, birthday, ministry_email
      FROM ministries_table
      WHERE ministry_id=? LIMIT 1
  "))) {
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);
  }
  echo json_encode(['ok'=> (bool)$row, 'row'=>$row]);
  exit;
}

/* --- Update (edit) an existing record by ID --- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['__action']) && $_POST['__action']==='edit_ministry') {
  header('Content-Type: application/json; charset=utf-8');

  $id         = isset($_POST['ministry_id']) ? (int)$_POST['ministry_id'] : 0;
  $position   = trim($_POST['ministry_position'] ?? '');
  $lastname   = trim($_POST['ministry_lastname'] ?? '');
  $firstname  = trim($_POST['ministry_firstname'] ?? '');
  $middlename = trim($_POST['ministry_middlename'] ?? '');
  $extension  = trim($_POST['ministry_extensionname'] ?? '');
  $date_join  = trim($_POST['date_join'] ?? '');
  $sex        = trim($_POST['sex'] ?? '');
  $birthday   = trim($_POST['birthday'] ?? '');
  $email      = trim($_POST['ministry_email'] ?? '');

  if (!$id || $position==='' || $lastname==='' || $firstname==='' || $date_join==='') {
    echo json_encode(['ok'=>false,'msg'=>'Please complete required fields.']); exit;
  }

  /* Keep it conservative: only update selected fields (add-only behavior) */
  $sql = "UPDATE ministries_table
          SET ministry_position=?, ministry_lastname=?, ministry_firstname=?, ministry_middlename=?,
              ministry_extensionname=?, date_join=?, sex=?, birthday=?, ministry_email=?
          WHERE ministry_id=? LIMIT 1";
  if ($st = mysqli_prepare($db_connection, $sql)) {
    mysqli_stmt_bind_param(
      $st, 'sssssssssi',
      $position, $lastname, $firstname, $middlename, $extension, $date_join, $sex, $birthday, $email, $id
    );
    $ok = mysqli_stmt_execute($st);
    $err= $ok ? null : mysqli_error($db_connection);
    mysqli_stmt_close($st);
    echo json_encode(['ok'=> (bool)$ok, 'msg'=> $ok ? 'Updated.' : ('DB error: '.$err)]);
  } else {
    echo json_encode(['ok'=>false,'msg'=>'Prepare failed.']);
  }
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1"/>
<title>Admin – Women’s Ministry</title>

<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link rel="stylesheet" href="/HTCCC-SYSTEM/css/admin-ministry-women.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/admin-ministry-women.css'); ?>">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: none; align-items: center; justify-content: center; z-index: 1000; }
.modal { background: #fff; width: min(680px, 92vw); border-radius: 14px; padding: 20px; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
.modal header { display:flex; align-items:center; justify-content:space-between; }
.modal h3 { margin:0; font-size: 1.15rem; }
.modal .close { background:transparent; border:0; font-size:1.2rem; cursor:pointer; }
.grid-2 { display:grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.grid-3 { display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
.field { display:flex; flex-direction:column; }
.field label { font-size:.9rem; margin-bottom:6px; color:#19324e; }
.field input, .field select { border:1px solid #e2e8f0; border-radius:10px; padding:10px 12px; outline:none; }
.actions { display:flex; gap:10px; justify-content:flex-end; margin-top:14px; }
.btn { border:0; border-radius:10px; padding:10px 14px; cursor:pointer; }
.btn.primary { background:#6B5AE3; color:#fff; }
.btn.ghost { background:#eef2ff; color:#1B1B4B; }

/* ADDED: small style for inline Edit button (keeps your design) */
button.inline-edit{background:#10b981;color:#fff;border:0;border-radius:8px;padding:6px 10px;margin-right:6px}
@media (max-width:640px){ .grid-2,.grid-3{grid-template-columns:1fr;} }
</style>
</head>
<body>

<!-- ======================== SIDEBAR ======================== -->
<aside class="sidebar">
  <div class="brand">
    <img src="image/httc_main-logo.jpg" alt="" />
    <span>HTCCC SYSTEM</span>
  </div>

  <div class="user-card">
    <img src="css/image/profile.png" alt="user">
    <div>
      <div class="user-title">Pastor</div>
      <div class="user-sub">Dashboard</div>
    </div>
  </div>

  <nav class="nav">
    <div class="section-title">Main</div>
    <a class="navlink" href="secretary_dashboard.php"><i class="fas fa-th-large"></i>Dashboard</a>

    <div class="section-title">Requests</div>
    <a class="navlink" href="pastor-admin-request.php">
      <i class="far fa-calendar-plus"></i>Appointment Request
    </a>
    <a class="navlink" href="pastor-prayer-request.php">
      <i class="far fa-hand-paper"></i><span>Prayer Request</span>
    </a>

    <div class="section-title">Schedule</div>
      <a class="navlink" href="pastor-appointment-schedule.php">
      <i class="far fa-calendar-alt"></i>Appointment Schedule
    </a>
    <a class="navlink" href="pastor-service-schedule.php">
      <i class="fas fa-calendar-alt"></i>Service Schedule
    </a>

    <div class="section-title">Application</div>
    <a class="navlink" href="pastor-ministries-application.php">
      <i class="fas fa-users"></i>Ministries Application
    </a>
    <a class="navlink" href="pastor-user-application.php">
      <i class="far fa-user"></i>User Application
    </a>

    <div class="section-title">Streaming</div>
    <a class="navlink" href="pastor-streaming.php">
      <i class="fas fa-video"></i>Streaming
    </a>

    <div class="section-title">Ministry List</div>
    <a class="navlink active" href="pastor-women-ministries.php">
      <i class="fas fa-female"></i>Handmaid's of the Lord
    </a>
    <a class="navlink" href="pastor-men-ministries.php">
      <i class="fas fa-male"></i>Men's Ministry
    </a>
    <a class="navlink" href="pastor-music-ministries.php">
      <i class="fas fa-music"></i>Music's Ministry
    </a>
    <a class="navlink" href="pastor-usher-ministries.php">
      <i class="fas fa-hands-helping"></i>Usher &amp; Usherette
    </a>
    <a class="navlink" href="pastor-junior-ministries.php">
      <i class="fas fa-child"></i>Junior Christ Ambassador
    </a>

    <div class="section-title">Reports</div>
    <a class="navlink" href="pastor-report.php">
      <i class="fas fa-file-alt"></i>Reports
    </a>

    <div class="section-title">Content</div>
    <a class="navlink" href="pastor-content management.php">
      <i class="fas fa-edit"></i>Content Management
    </a>

     <div class="section-title">Management</div>
    <a class="navlink" href="content-management_home-page.php">
      <i class="fas fa-edit"></i> Audit Trails
    </a>

    <div class="section-title">More</div>
    <a class="navlink logout" href="all_log-in.php">
      <img alt="Logout" class="icon" src="image/logo-logout.png" width="18" height="18" style="vertical-align:middle;margin-right:8px;">
      Log Out
    </a>
  </nav>
</aside>

<!-- MAIN CONTENT -->
<div class="page">
  <header class="topbar"><h1>Handmaid's of the Lord</h1></header>

  <div class="container">
    <div class="top-bar">
      <input type="text" placeholder="🔍 Search" class="search-box" aria-label="Search">
      <div class="btn-group">
        <button class="sort-button" id="openAddModal" type="button">Add +</button>
        <button class="sort-button sky" type="button">Sort by:</button>
      </div>
    </div>

    <table>
      <thead>
        <tr>
          <th>Date Joined</th>
          <th>Position</th>
          <th>Lastname</th>
          <th>Firstname</th>
          <th>Middlename</th>
          <th>Extension</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php
        /* ADDED ONLY: include ministry_id in SELECT so Edit buttons can carry the exact row id. */
        $query = "SELECT ministry_id, date_join, ministry_position, ministry_lastname, ministry_firstname, ministry_middlename, ministry_extensionname 
                  FROM ministries_table 
                  WHERE ministry_type='Women Ministry' AND archive_status='Active'
                  ORDER BY date_join DESC";
        $res = mysqli_query($db_connection, $query);
        if ($res && mysqli_num_rows($res) > 0) {
          while ($r = mysqli_fetch_assoc($res)) {
            $date_disp = $r['date_join'] ? date('F j, Y', strtotime($r['date_join'])) : '';
            $mid = (int)$r['ministry_id'];
            echo "<tr>
              <td>".htmlspecialchars($date_disp)."</td>
              <td>".htmlspecialchars($r['ministry_position'])."</td>
              <td>".htmlspecialchars($r['ministry_lastname'])."</td>
              <td>".htmlspecialchars($r['ministry_firstname'])."</td>
              <td>".htmlspecialchars($r['ministry_middlename'])."</td>
              <td>".htmlspecialchars($r['ministry_extensionname'])."</td>
              <td>
                <!-- ADDED: data-mid carries the exact DB id (invisible to users; no design change) -->
                <button type='button' class='inline-edit js-edit-row' data-mid='{$mid}' title='Edit this record'><i class=\"fas fa-pen\"></i> Edit</button>
                <button class='decline' type='button' disabled title='Coming soon'>Archive</button>
              </td>
            </tr>";
          }
        } else {
          echo "<tr><td colspan='7'>No data found.</td></tr>";
        }
        ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ADD MODAL (EXISTING, unchanged) -->
<div class="modal-backdrop" id="addModal" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="addModalTitle">
    <header>
      <h3 id="addModalTitle">Add Women’s Ministry Member</h3>
      <button class="close" id="closeAdd" type="button" aria-label="Close">&times;</button>
    </header>

    <form method="post" id="addForm" autocomplete="off">
      <input type="hidden" name="__action" value="add_ministry">

      <div class="grid-2">
        <div class="field">
          <label for="date_join">Date Joined</label>
          <input type="date" id="date_join" name="date_join" required value="<?php echo htmlspecialchars($old['date_join']); ?>">
        </div>

        <!-- POSITION DROPDOWN + OTHER -->
        <div class="field">
          <label for="ministry_position_select">Ministry Position</label>

          <!-- This hidden input is what PHP reads (keeps your original logic name) -->
          <input type="hidden" id="ministry_position" name="ministry_position" value="<?php echo htmlspecialchars($old['ministry_position']); ?>">

          <?php
            $old_pos   = $old['ministry_position'];
            $is_known  = in_array($old_pos, $POSITION_OPTIONS, true);
            $selectVal = $is_known ? $old_pos : '__other__';
            $otherVal  = $is_known ? '' : $old_pos;
          ?>
          <select id="ministry_position_select">
            <option value="" disabled <?php echo $selectVal==='' ? 'selected' : '' ?>>-- Select position --</option>
            <?php foreach($POSITION_OPTIONS as $opt): ?>
              <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $selectVal===$opt ? 'selected' : '' ?>>
                <?php echo htmlspecialchars($opt); ?>
              </option>
            <?php endforeach; ?>
            <option value="__other__" <?php echo $selectVal==='__other__' ? 'selected' : '' ?>>Other…</option>
          </select>

          <input type="text"
                 id="ministry_position_other"
                 placeholder="Type the position"
                 style="margin-top:8px; display: <?php echo $selectVal==='__other__' ? 'block' : 'none'; ?>;"
                 value="<?php echo htmlspecialchars($otherVal); ?>">
        </div>
      </div>

      <div class="grid-3" style="margin-top:12px;">
        <div class="field">
          <label for="ministry_lastname">Lastname</label>
          <input type="text" id="ministry_lastname" name="ministry_lastname" required value="<?php echo htmlspecialchars($old['ministry_lastname']); ?>">
        </div>
        <div class="field">
          <label for="ministry_firstname">Firstname</label>
          <input type="text" id="ministry_firstname" name="ministry_firstname" required value="<?php echo htmlspecialchars($old['ministry_firstname']); ?>">
        </div>
        <div class="field">
          <label for="ministry_middlename">Middlename</label>
          <input type="text" id="ministry_middlename" name="ministry_middlename" value="<?php echo htmlspecialchars($old['ministry_middlename']); ?>">
        </div>
      </div>

      <div class="grid-2" style="margin-top:12px;">
        <div class="field">
          <label for="ministry_extensionname">Extension</label>
          <input type="text" id="ministry_extensionname" name="ministry_extensionname" value="<?php echo htmlspecialchars($old['ministry_extensionname']); ?>">
        </div>
        <div class="field">
          <label>&nbsp;</label><small>Saved under <b>Women Ministry</b></small>
        </div>
      </div>

      <div class="actions">
        <button type="button" class="btn ghost" id="cancelAdd">Cancel</button>
        <button type="button" class="btn primary" id="confirmAdd">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- =========================================================
     ADDED: EDIT MODAL (new; does not alter existing UI)
========================================================= -->
<div class="modal-backdrop" id="editModal" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
    <header>
      <h3 id="editModalTitle">Edit Women’s Ministry Member</h3>
      <button class="close" id="closeEdit" type="button" aria-label="Close">&times;</button>
    </header>

    <form id="editForm" autocomplete="off">
      <input type="hidden" name="__action" value="edit_ministry">
      <input type="hidden" name="ministry_id" id="edit_ministry_id">

      <div class="grid-2">
        <div class="field">
          <label for="edit_date_join">Date Joined</label>
          <input type="date" id="edit_date_join" name="date_join" required>
        </div>

        <div class="field">
          <label for="edit_ministry_position">Ministry Position</label>
          <input type="text" id="edit_ministry_position" name="ministry_position" required>
        </div>
      </div>

      <div class="grid-3" style="margin-top:12px;">
        <div class="field">
          <label for="edit_ministry_lastname">Lastname</label>
          <input type="text" id="edit_ministry_lastname" name="ministry_lastname" required>
        </div>
        <div class="field">
          <label for="edit_ministry_firstname">Firstname</label>
          <input type="text" id="edit_ministry_firstname" name="ministry_firstname" required>
        </div>
        <div class="field">
          <label for="edit_ministry_middlename">Middlename</label>
          <input type="text" id="edit_ministry_middlename" name="ministry_middlename">
        </div>
      </div>

      <div class="grid-3" style="margin-top:12px;">
        <div class="field">
          <label for="edit_ministry_extensionname">Extension</label>
          <input type="text" id="edit_ministry_extensionname" name="ministry_extensionname">
        </div>
        <div class="field">
          <label for="edit_sex">Sex</label>
          <select id="edit_sex" name="sex">
            <option value="">—</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
          </select>
        </div>
        <div class="field">
          <label for="edit_birthday">Birthday</label>
          <input type="date" id="edit_birthday" name="birthday">
        </div>
      </div>

      <div class="grid-2" style="margin-top:12px;">
        <div class="field">
          <label for="edit_ministry_email">Email</label>
          <input type="email" id="edit_ministry_email" name="ministry_email">
        </div>
        <div class="field">
          <label>&nbsp;</label><small>Only the selected row will be updated.</small>
        </div>
      </div>

      <div class="actions">
        <button type="button" class="btn ghost" id="cancelEdit">Cancel</button>
        <button type="submit" class="btn primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
/* ---------- Modal Controls (EXISTING ADD) ---------- */
const modal    = document.getElementById('addModal');
const openBtn  = document.getElementById('openAddModal');
const closeBtn = document.getElementById('closeAdd');
const cancelBtn= document.getElementById('cancelAdd');
const form     = document.getElementById('addForm');

function showModal(){ modal.style.display='flex'; modal.setAttribute('aria-hidden','false'); }
function hideModal(){ modal.style.display='none'; modal.setAttribute('aria-hidden','true'); }

openBtn && (openBtn.onclick = showModal);
closeBtn && (closeBtn.onclick = hideModal);
cancelBtn && (cancelBtn.onclick = hideModal);
modal && (modal.onclick = (e)=>{ if(e.target===modal) hideModal(); });

/* ---------- Position dropdown + Other handling (EXISTING) ---------- */
const selectPos   = document.getElementById('ministry_position_select');
const otherPos    = document.getElementById('ministry_position_other');
const finalPosInp = document.getElementById('ministry_position');

function syncPositionValue(){
  const val = selectPos.value;
  if (val === '__other__') {
    otherPos.style.display = 'block';
    finalPosInp.value = otherPos.value.trim();
  } else {
    otherPos.style.display = 'none';
    finalPosInp.value = val;
  }
}
selectPos.addEventListener('change', syncPositionValue);
otherPos.addEventListener('input', syncPositionValue);
// initial sync on load
syncPositionValue();

/* ---------- SweetAlert Confirm Before Submit (EXISTING) ---------- */
document.getElementById('confirmAdd')?.addEventListener('click', function(){
  // ensure position is set (handle 'Other')
  syncPositionValue();
  if (!finalPosInp.value) {
    Swal.fire({ title: "Missing position", text: "Please select or type a ministry position.", icon: "warning" });
    if (selectPos.value === '__other__') otherPos.focus(); else selectPos.focus();
    return;
  }

  Swal.fire({
    title: "Confirm Add?",
    text: "Are you sure you want to add this new member?",
    icon: "question",
    showCancelButton: true,
    confirmButtonColor: "#6B5AE3",
    cancelButtonColor: "#d33",
    confirmButtonText: "Yes, Add"
  }).then((result) => {
    if(result.isConfirmed){
      form.submit();
    }
  });
});

/* ---------- After server processing: success/error alerts (EXISTING) ---------- */
<?php if ($add_success): ?>
Swal.fire({
  title: "Added Successfully!",
  text: "New member has been added to Women’s Ministry.",
  icon: "success",
  confirmButtonColor: "#6B5AE3"
}).then(()=>{ window.location = 'admin-ministry-women.php'; });
<?php elseif ($error_msg): ?>
showModal();
Swal.fire({
  title: "Error",
  text: "<?php echo addslashes($error_msg); ?>",
  icon: "error",
  confirmButtonColor: "#6B5AE3"
});
<?php endif; ?>

/* ==========================================================
   ADDED FUNCTION ONLY: Limit the table to 4 rows (scrollable)
========================================================== */
(function(){
  const WRAP_CLASS = 'js-table-scroll-wrap';

  function getMainTable(){
    // The first table inside the main .container
    const container = document.querySelector('.page .container');
    if (!container) return null;
    return container.querySelector('table');
  }

  function ensureWrapper(table){
    if (table.parentElement && table.parentElement.classList && table.parentElement.classList.contains(WRAP_CLASS)) {
      return table.parentElement;
    }
    const wrap = document.createElement('div');
    wrap.className = WRAP_CLASS;
    wrap.style.overflowY = 'auto';
    wrap.style.width = '100%';
    // insert wrapper before table, then move table inside
    table.parentElement.insertBefore(wrap, table);
    wrap.appendChild(table);
    return wrap;
  }

  function firstVisibleRow(tbody){
    const rows = tbody ? Array.from(tbody.rows) : [];
    return rows.find(r => r && r.offsetParent !== null) || rows[0] || null;
  }

  function setRowLimit(n){
    const table = getMainTable();
    if (!table) return;

    const wrap = ensureWrapper(table);
    const theadRow = table.tHead && table.tHead.rows[0] ? table.tHead.rows[0] : null;
    const tbody = table.tBodies && table.tBodies[0] ? table.tBodies[0] : null;
    if (!theadRow || !tbody) return;

    const headH = Math.ceil(theadRow.getBoundingClientRect().height || 44);
    const rowEl = firstVisibleRow(tbody);
    if (!rowEl) return;
    const rowH = Math.ceil(rowEl.getBoundingClientRect().height || 44);

    wrap.style.maxHeight = (headH + (rowH * n) + 2) + 'px';
  }

  function apply(){ setRowLimit(9); }

  window.addEventListener('load', apply);
  window.addEventListener('resize', apply);
  // account for layout shifts/fonts
  window.addEventListener('load', ()=>{
    setTimeout(apply, 120);
    setTimeout(apply, 300);
  });
})();

/* ==========================================================
   ADDED: SMART SEARCH (client-side, non-destructive)
========================================================== */
(function(){
  const sb = document.querySelector('.search-box');
  const tbody = document.querySelector('.container table tbody');
  if (!sb || !tbody) return;
  const rows = Array.from(tbody.rows);

  function normalize(s){ return (s||'').toString().toLowerCase(); }

  sb.addEventListener('input', () => {
    const q = normalize(sb.value);
    const parts = q.split(/\s+/).filter(Boolean);
    rows.forEach(tr => {
      const text = normalize(tr.innerText);
      const ok = parts.every(p => text.includes(p));
      tr.style.display = ok ? '' : 'none';
    });
  });
})();

/* ==========================================================
   ADDED: EDIT FEATURE (buttons + modal + ajax)
   - Uses data-mid attribute first (exact id), then fallback
     matcher for legacy rows.
========================================================== */
(function(){
  // Elements
  const editBackdrop = document.getElementById('editModal');
  const closeEdit    = document.getElementById('closeEdit');
  const cancelEdit   = document.getElementById('cancelEdit');
  const editForm     = document.getElementById('editForm');

  function openEdit(){ editBackdrop.style.display='flex'; editBackdrop.setAttribute('aria-hidden','false'); }
  function closeEditFn(){ editBackdrop.style.display='none'; editBackdrop.setAttribute('aria-hidden','true'); }

  closeEdit && closeEdit.addEventListener('click', closeEditFn);
  cancelEdit && cancelEdit.addEventListener('click', closeEditFn);
  editBackdrop && editBackdrop.addEventListener('click', (e)=>{ if(e.target===editBackdrop) closeEditFn(); });

  // Helper: date normalization to YYYY-MM-DD
  function normalizeDateOnly(d) {
    if (!d) return '';
    if (/^\d{4}-\d{2}-\d{2}/.test(d)) return d.substring(0,10);
    const parsed = Date.parse(d);
    if (!isNaN(parsed)) {
      const dt = new Date(parsed);
      return dt.toISOString().substring(0,10);
    }
    return '';
  }

  // Fallback row matcher
  function findRecordMatch(jsonRows, tLast, tFirst, tDate) {
    const dateOnly = normalizeDateOnly(tDate);
    return jsonRows.find(r => {
      const dbDate = normalizeDateOnly(r.date_join || '');
      return (
        (r.ministry_lastname||'').trim()  === tLast &&
        (r.ministry_firstname||'').trim() === tFirst &&
        dbDate === dateOnly
      );
    });
  }

  // Cache of DB rows
  let DB_ROWS = null;

  async function ensureDbRows(){
    if (DB_ROWS) return DB_ROWS;
    const res = await fetch('admin-ministry-women.php?ajax=wm_rows', { headers: { 'X-Requested-With': 'XMLHttpRequest' }});
    const j = await res.json();
    DB_ROWS = (j && j.ok) ? j.rows : [];
    return DB_ROWS;
  }

  // Attach Edit handlers to each displayed row
  async function attachEditButtons(){
    const tbody = document.querySelector('.container table tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.rows);
    const dbrows = await ensureDbRows();

    rows.forEach(tr => {
      const btn = tr.querySelector('.js-edit-row');
      if (!btn) return;

      btn.addEventListener('click', async ()=>{
        let id = btn.getAttribute('data-mid'); // primary path: exact id from markup
        if (!id) {
          // Legacy fallback matching (kept as addition)
          const tDate  = (tr.cells[0]?.innerText || '').trim();
          const tLast  = (tr.cells[2]?.innerText || '').trim();
          const tFirst = (tr.cells[3]?.innerText || '').trim();
          const match = findRecordMatch(dbrows, tLast, tFirst, tDate);
          if (match) id = match.ministry_id;
        }

        if (!id) {
          Swal.fire('Record ID not found', 'Could not locate the record for editing.', 'warning');
          return;
        }

        // Load full row by ID
        try{
          const res = await fetch('admin-ministry-women.php?ajax=wm_row&id=' + encodeURIComponent(id));
          const j = await res.json();
          if (!j || !j.ok || !j.row) { throw new Error('Row not found'); }

          // Fill edit form
          document.getElementById('edit_ministry_id').value            = j.row.ministry_id;
          document.getElementById('edit_date_join').value              = normalizeDateOnly(j.row.date_join || '');
          document.getElementById('edit_ministry_position').value      = j.row.ministry_position || '';
          document.getElementById('edit_ministry_lastname').value      = j.row.ministry_lastname || '';
          document.getElementById('edit_ministry_firstname').value     = j.row.ministry_firstname || '';
          document.getElementById('edit_ministry_middlename').value    = j.row.ministry_middlename || '';
          document.getElementById('edit_ministry_extensionname').value = j.row.ministry_extensionname || '';
          document.getElementById('edit_sex').value                    = j.row.sex || '';
          document.getElementById('edit_birthday').value               = normalizeDateOnly(j.row.birthday || '');
          document.getElementById('edit_ministry_email').value         = j.row.ministry_email || '';

          openEdit();
        }catch(e){
          Swal.fire('Error', 'Unable to load selected record.', 'error');
        }
      });
    });
  }

  // Submit edit
  editForm?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const fd = new FormData(editForm);

    Swal.fire({ title:'Saving...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });

    try{
      const res = await fetch('admin-ministry-women.php', { method:'POST', body: fd });
      const j = await res.json();
      if (j && j.ok) {
        Swal.fire({ icon:'success', title:'Updated', timer:1100, showConfirmButton:false })
          .then(()=> { window.location.reload(); });
      } else {
        Swal.fire('Error', (j&&j.msg)||'Update failed', 'error');
      }
    }catch(err){
      Swal.fire('Network error', 'Please try again.', 'error');
    }
  });

  // Initialize after content ready
  window.addEventListener('load', attachEditButtons);
})();
</script>

</body>
</html>
