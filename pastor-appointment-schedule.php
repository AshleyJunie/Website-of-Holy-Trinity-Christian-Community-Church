<?php
include 'db-connection.php';
mysqli_set_charset($db_connection, 'utf8mb4');
mysqli_query($db_connection, "SET collation_connection = 'utf8mb4_general_ci'");

/* =========================
   FILTER LOGIC
   ========================= */
$filter = isset($_GET['filter']) ? strtolower(trim($_GET['filter'])) : 'all';
if (!in_array($filter, ['all','today','previous','archived','done'], true)) $filter = 'all';

$activeWhere   = "COALESCE(status, service_status) NOT IN ('Archived','Done') OR COALESCE(status, service_status) IS NULL";
$archivedWhere = "COALESCE(status, service_status) = 'Archived'";
$doneWhere     = "COALESCE(status, service_status) = 'Done'";
$todayWhere    = "DATE(appointment_date) = CURDATE()";
$previousWhere = "DATE(appointment_date) < CURDATE()";

switch ($filter) {
  case 'today':    $whereClause = "WHERE $activeWhere AND $todayWhere"; break;
  case 'previous': $whereClause = "WHERE $activeWhere AND $previousWhere"; break;
  case 'archived': $whereClause = "WHERE $archivedWhere"; break;
  case 'done':     $whereClause = "WHERE $doneWhere"; break;
  default:         $whereClause = "WHERE $activeWhere"; break;
}

/* =========================
   AJAX: STATUS UPDATES
   ========================= */
if (isset($_GET['ajax'])) {
  header('Content-Type: application/json; charset=utf-8');
  $action = $_GET['ajax'];
  $src = $_POST['src'] ?? '';
  $id  = (int)($_POST['id'] ?? 0);

  if ($id <= 0) { echo json_encode(['ok'=>false,'message'=>'Invalid ID']); exit; }

  $map = [
    'baptism'    => ['table'=>'service_baptism',    'col'=>'baptism_id'],
    'dedication' => ['table'=>'service_dedication', 'col'=>'dedicationId'],
    'funeral'    => ['table'=>'service_funeral',    'col'=>'funeral_id'],
    'house'      => ['table'=>'service_house',      'col'=>'appointment_id'],
    'wedding'    => ['table'=>'service_wedding',    'col'=>'wedding_id']
  ];
  if (!isset($map[$src])) { echo json_encode(['ok'=>false,'message'=>'Invalid source']); exit; }

  $status = null;
  if ($action === 'archive') $status = 'Archived';
  if ($action === 'done')     $status = 'Done';
  if (!$status) { echo json_encode(['ok'=>false,'message'=>'Invalid action']); exit; }

  $sql = "UPDATE {$map[$src]['table']} SET status=? WHERE {$map[$src]['col']}=? LIMIT 1";
  $ok=false;$msg='';
  if ($stmt=mysqli_prepare($db_connection,$sql)) {
    mysqli_stmt_bind_param($stmt,'si',$status,$id);
    mysqli_stmt_execute($stmt);
    $ok=mysqli_stmt_affected_rows($stmt)>0;
    $msg=$ok?"Status set to $status.":"No changes made.";
    mysqli_stmt_close($stmt);
  }
  echo json_encode(['ok'=>$ok,'message'=>$msg,'newStatus'=>$status]);
  exit;
}

/* =========================
   MAIN UNION QUERY
   ========================= */
$sql = "
SELECT 
    appointment_date,
    CAST(COALESCE(appointment_time, service_time) AS CHAR) COLLATE utf8mb4_general_ci AS appointment_time,
    'Baptism' COLLATE utf8mb4_general_ci AS event_type,
    baptized_name COLLATE utf8mb4_general_ci AS fullname,
    email_address COLLATE utf8mb4_general_ci AS contact,
    COALESCE(status, service_status) COLLATE utf8mb4_general_ci AS status,
    'baptism' COLLATE utf8mb4_general_ci AS src,
    CAST(baptism_id AS UNSIGNED) AS pk
FROM service_baptism
{$whereClause}

UNION ALL
SELECT 
    appointment_date,
    CAST(COALESCE(appointment_time, service_time) AS CHAR),
    'Dedication',
    child_full_name,
    guardian_contact,
    COALESCE(status, service_status),
    'dedication',
    CAST(dedicationId AS UNSIGNED)
FROM service_dedication
{$whereClause}

UNION ALL
SELECT 
    appointment_date,
    CAST(COALESCE(appointment_time, service_time) AS CHAR),
    'Funeral',
    deceased_name,
    email_address,
    COALESCE(status, service_status),
    'funeral',
    CAST(funeral_id AS UNSIGNED)
FROM service_funeral
{$whereClause}

UNION ALL
SELECT 
    appointment_date,
    CAST(COALESCE(appointment_time, service_time) AS CHAR),
    'House Blessing',
    owner_full_name,
    contact_info,
    COALESCE(status, service_status),
    'house',
    CAST(appointment_id AS UNSIGNED)
FROM service_house
{$whereClause}

UNION ALL
SELECT 
    appointment_date,
    CAST(COALESCE(appointment_time, service_time) AS CHAR),
    'Wedding',
    CONCAT(groom_name, ' & ', bride_name),
    contact_number,
    COALESCE(status, service_status),
    'wedding',
    CAST(wedding_id AS UNSIGNED)
FROM service_wedding
{$whereClause}

ORDER BY appointment_date DESC, appointment_time ASC
";
$result = mysqli_query($db_connection, $sql);
function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admin – Service Schedule</title>
<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link rel="stylesheet" href="/HTCCC-SYSTEM/css/admin-schedule-table.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/admin-schedule-table.css'); ?>">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
input.select-row{transform:scale(1.2);margin-right:6px;}
.btn-secondary{background:#6b7280;color:#fff;border:0;border-radius:12px;padding:9px 12px;cursor:pointer;margin-left:6px;}
.btn-secondary:hover{filter:brightness(.92);}
.archive-controls{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
#archiveSelected{display:none;opacity:0;transition:opacity 0.3s ease;}
#archiveSelected.show{display:inline-block;opacity:1;}
.btn-done{background:#3b82f6;color:#fff;border:0;border-radius:12px;padding:9px 12px;cursor:pointer;margin-left:6px;}
.btn-done:hover{filter:brightness(.95);}
.btn-onsite{background:#1B1B4B;color:#fff;border:none;border-radius:12px;padding:9px 14px;cursor:pointer;margin-left:6px;font-weight:500;}
.btn-onsite:hover{filter:brightness(1.1);}
</style>
</head>
<body>

<!-- Sidebar same as before -->
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
      <a class="navlink active" href="pastor-appointment-schedule.php">
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
    <a class="navlink" href="pastor-women-ministries.php">
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
<!-- Page -->
<div class="page">
  <header class="topbar"><h1>Appointment Schedule Table</h1></header>
  <div class="container">
    <div class="top-bar">
      <input id="searchBox" type="text" placeholder="Search..." class="search-box">
      <div class="actions">
        <select id="filterDropdown" class="select">
          <option value="all" <?php echo $filter==='all'?'selected':''; ?>>All</option>
          <option value="today" <?php echo $filter==='today'?'selected':''; ?>>Today</option>
          <option value="previous" <?php echo $filter==='previous'?'selected':''; ?>>Previous</option>
          <option value="done" <?php echo $filter==='done'?'selected':''; ?>>Done</option>
          <option value="archived" <?php echo $filter==='archived'?'selected':''; ?>>Archived</option>
        </select>
        <div class="archive-controls">
          <button id="selectAll" class="btn-secondary"><i class="fas fa-check-square"></i> Select All</button>
          <button id="archiveSelected" class="btn-secondary"><i class="fas fa-box-archive"></i> Archive Selected</button>
          <button class="btn-onsite" onclick="location.href='onsite_appointment.php'"><i class="fas fa-calendar-check"></i> Create a appointment</button>
        </div>
      </div>
    </div>

    <table id="schedTable">
      <thead><tr><th></th><th>Date</th><th>Event</th><th>Fullname</th><th>Contact</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      <?php
      if ($result && mysqli_num_rows($result) > 0) {
        while ($r = mysqli_fetch_assoc($result)) {
          $date = $r['appointment_date'] ? date('F j, Y', strtotime($r['appointment_date'])) : '—';
          $time = $r['appointment_time'] ? ' @ '.$r['appointment_time'] : '';
          $src  = h($r['src']); $id=(int)$r['pk']; $status=h($r['status']);
          echo "<tr data-status='".strtolower($status)."'>";
          echo "<td><input type='checkbox' class='select-row' data-src='{$src}' data-id='{$id}'></td>";
          echo "<td>{$date}{$time}</td><td><span class='chip chip--event'>".h($r['event_type'])."</span></td>";
          echo "<td>".h($r['fullname'])."</td><td>".h($r['contact'])."</td>";
          echo "<td><span class='badge status-badge'>{$status}</span></td><td>";
          echo "<button class='btn-view' data-src='{$src}' data-id='{$id}'><i class='fas fa-eye'></i> View</button>";
          if ($filter!=='archived' && $filter!=='done') {
            echo " <button class='btn-done' data-src='{$src}' data-id='{$id}'><i class='fas fa-check'></i> Done</button>";
            echo " <button class='btn-archive' data-src='{$src}' data-id='{$id}'><i class='fas fa-box-archive'></i> Archive</button>";
          }
          echo "</td></tr>";
        }
      } else {
        echo "<tr><td colspan='7' style='padding:18px;'>No appointments found.</td></tr>";
      }
      ?>
      </tbody>
    </table>
  </div>
</div>

<script>
document.getElementById('filterDropdown').addEventListener('change',()=>location.href='?filter='+encodeURIComponent(event.target.value));

async function sweetConfirm(title,text,icon='question'){
  const res=await Swal.fire({title,text,icon,showCancelButton:true,confirmButtonColor:'#1B1B4B',cancelButtonColor:'#6b7280'});
  return res.isConfirmed;
}

async function updateStatus(src,id,action,row){
  const fd=new FormData();fd.append('src',src);fd.append('id',id);
  const res=await fetch('?ajax='+action,{method:'POST',body:fd});
  const j=await res.json();if(!j.ok)throw new Error(j.message);
  const badge=row.querySelector('.status-badge');badge.textContent=j.newStatus;
  Swal.fire({title:j.newStatus+'!',text:'Status updated.',icon:'success',timer:1800,showConfirmButton:false});
  if(j.newStatus==='Archived')row.remove();
}

document.querySelectorAll('.btn-archive').forEach(btn=>btn.addEventListener('click',async e=>{
  const row=e.currentTarget.closest('tr'),src=e.currentTarget.dataset.src,id=e.currentTarget.dataset.id;
  if(await sweetConfirm('Archive this appointment?','This record will be moved to archive.','warning'))await updateStatus(src,id,'archive',row);
}));

document.querySelectorAll('.btn-done').forEach(btn=>btn.addEventListener('click',async e=>{
  const row=e.currentTarget.closest('tr'),src=e.currentTarget.dataset.src,id=e.currentTarget.dataset.id;
  if(await sweetConfirm('Mark as Done?','This will set the appointment status to Done.','info'))await updateStatus(src,id,'done',row);
}));

const selectAllBtn=document.getElementById('selectAll');
const archiveSelectedBtn=document.getElementById('archiveSelected');

function toggleArchiveSelected(){
  const anyChecked=document.querySelectorAll('.select-row:checked').length>0;
  if(anyChecked){
    archiveSelectedBtn.style.display='inline-block';
    requestAnimationFrame(()=>archiveSelectedBtn.classList.add('show'));
  }else{
    archiveSelectedBtn.classList.remove('show');
    setTimeout(()=>{if(!archiveSelectedBtn.classList.contains('show'))archiveSelectedBtn.style.display='none';},300);
  }
}

selectAllBtn.addEventListener('click',()=>{
  const checkboxes=document.querySelectorAll('.select-row');
  checkboxes.forEach(cb=>cb.checked=true);
  toggleArchiveSelected();
  Swal.fire({title:'All Selected',text:'All rows have been selected.',icon:'info',timer:1200,showConfirmButton:false});
});

document.querySelectorAll('.select-row').forEach(cb=>cb.addEventListener('change',toggleArchiveSelected));

archiveSelectedBtn.addEventListener('click',async()=>{
  const checked=document.querySelectorAll('.select-row:checked');
  if(!checked.length)return Swal.fire('No selection','Please select at least one row.','warning');
  const ok=await sweetConfirm('Archive selected?','All selected appointments will be archived.','warning');
  if(!ok)return;
  for(const cb of checked){await updateStatus(cb.dataset.src,cb.dataset.id,'archive',cb.closest('tr'));}
  toggleArchiveSelected();
});

document.getElementById('searchBox').addEventListener('input',e=>{
  const q=e.target.value.toLowerCase();
  document.querySelectorAll('#schedTable tbody tr').forEach(tr=>{
    tr.style.display=tr.innerText.toLowerCase().includes(q)?'':'none';
  });
});
</script>
</body>
</html>
