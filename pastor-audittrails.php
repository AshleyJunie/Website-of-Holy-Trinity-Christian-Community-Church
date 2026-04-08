<?php
session_start();
include 'db-connection.php'; // ✅ connects to htccc-data-base

// fetch non-sensitive audit data
$audit_query = "
  SELECT event_time, action, source_table, record_pk, actor_username, form_name, notes
  FROM audit_trail
  ORDER BY event_time DESC
";
$audit_result = mysqli_query($db_connection, $audit_query);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Audit Trail Records</title>

<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link rel="stylesheet" href="/HTCCC-SYSTEM/css/secretary_dashboard.css?v=<?php echo time(); ?>">

<style>
  .mode-toggle .pill { text-decoration: none; }
  .mode-toggle{display:flex;gap:10px;align-items:center;margin:6px 0 18px}
  .mode-toggle .pill{
    appearance:none;border:none;cursor:pointer;padding:10px 18px;border-radius:999px;
    background:#0b214b;color:#fff;font-weight:800;letter-spacing:.2px;
    box-shadow:0 6px 14px rgba(11,33,75,.22), inset 0 -2px 0 rgba(255,255,255,.12);
    display:inline-flex;align-items:center;gap:8px;transition:.18s transform ease,.18s opacity ease;
  }
  .mode-toggle .pill.secondary{background:#eef2ff;color:#0b214b;border:1px solid #c7d2fe}
  .mode-toggle .pill.active{background:#111827;color:#fff;border:1px solid #111827}
  @media (max-width:640px){ .mode-toggle{flex-wrap:wrap} }

  .audit-container{margin-left:260px;padding:25px;}
  h1{font-size:24px;margin-bottom:10px}
  p.subtitle{margin-bottom:16px;color:#4b5563}

  .audit-container > h1,
  .audit-container > .subtitle,
  .audit-container > .table-wrapper:first-of-type{display:none !important;}

  .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 6px 18px rgba(2,8,23,0.06);padding:18px}
  .card-title{font-size:24px;font-weight:800;color:#0b214b;margin:6px 0 6px}
  .card-subtitle{margin:0 0 14px;color:#4b5563;font:500 14px system-ui}
  .toolbar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
  .tb-search{flex:1;position:relative}
  .tb-search input{width:100%;height:40px;border:1px solid #d1d5db;border-radius:999px;padding:0 40px 0 42px;font:14px system-ui}
  .tb-search i{position:absolute;left:14px;top:50%;transform:translateY(-50%);opacity:.6}
  .tb-select, .tb-btn, .tb-check{height:40px;display:inline-flex;align-items:center;gap:8px;border-radius:10px;padding:0 12px;border:1px solid #d1d5db;background:#f8fafc;font-weight:600}
  .tb-select select, .tb-select input{border:none;background:transparent;outline:none;height:38px}
  .audit-table-v2{width:100%;border-collapse:separate;border-spacing:0}
  .audit-table-v2 thead th{
    background:#0b1030;color:#fff;font-weight:700;
    padding:14px 12px;position:sticky;top:0;z-index:2;
    font-size:16px;
  }
  .audit-table-v2 tbody td{
    padding:14px 12px;border-bottom:1px solid #e5e7eb;vertical-align:middle;background:#fff;
    font-size:15px;
  }
  .audit-table-v2 .chk-cell{width:44px;text-align:center}
  .audit-table-v2 .pill{display:inline-block;padding:6px 12px;border-radius:999px;font-weight:700;background:#eef2ff;color:#1e3a8a;font-size:12px}
  .audit-table-v2 .status-INSERT{background:#e0f2fe;color:#075985}
  .audit-table-v2 .status-UPDATE{background:#e8f5e9;color:#1b5e20}
  .audit-table-v2 .status-DELETE{background:#fdecea;color:#b71c1c}
  .audit-table-v2 .status-APPROVE{background:#e8f5e9;color:#1b5e20}
  .audit-table-v2 .status-REJECT{background:#fff3e0;color:#e65100}
  .table-shell{max-height:720px;overflow:auto;border:1px solid #e5e7eb;border-radius:12px}
  .row-actions,.tb-check,.tb-btn,#selectAllHeader{display:none !important;}
</style>

<!-- FLEX FILL -->
<style>
  html, body { height: 100%; }
  body { min-height: 100vh; }
  .audit-container{min-height:100vh;display:flex;flex-direction:column;}
  .audit-container .card{flex:1 1 auto;display:flex;flex-direction:column;min-height:0;}
  .audit-container .toolbar{flex:0 0 auto;}
  .audit-container .table-shell{flex:1 1 auto;max-height:none !important;min-height:0;overflow:auto;}
  .audit-container .audit-table-v2 thead th{top:0;}
</style>

<!-- ✅ FROM–TO DATE PICKER + SMART SORT -->
<style>
  .tb-daterange{display:flex;gap:8px;align-items:center;height:40px;border:1px solid #d1d5db;background:#f8fafc;border-radius:10px;padding:0 10px}
  .tb-daterange label{font:600 12px/1 system-ui;color:#374151;opacity:.85}
  .tb-daterange input[type="date"]{border:none;background:transparent;outline:none;height:38px}
  .tb-sortchips{display:flex;gap:8px}
  .chip-btn{
    appearance:none;border:1px solid #d1d5db;background:#f8fafc;border-radius:999px;
    padding:8px 12px;height:40px;font-weight:700;cursor:pointer
  }
  .chip-btn.active{background:#111827;color:#fff;border-color:#111827}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="brand">
    <img src="image/httc_main-logo.jpg" alt="" />
    <span>HTCCC SYSTEM</span>
  </div>
  <div class="user-card">
    <img src="css/image/profile.png" alt="user">
    <div><div class="user-title">Pastor</div><div class="user-sub">Dashboard</div></div>
  </div>
  <nav class="nav">
    <div class="section-title">Main</div>
    <a class="navlink" href="secretary_dashboard.php"><i class="fas fa-th-large"></i>Dashboard</a>
    <div class="section-title">Requests</div>
    <a class="navlink" href="pastor-admin-request.php"><i class="far fa-calendar-plus"></i>Appointment Request</a>
    <a class="navlink" href="pastor-prayer-request.php"><i class="far fa-hand-paper"></i>Prayer Request</a>
    <div class="section-title">Schedule</div>
    <a class="navlink" href="pastor-appointment-schedule.php"><i class="far fa-calendar-alt"></i>Appointment Schedule</a>
    <a class="navlink" href="pastor-service-schedule.php"><i class="fas fa-calendar-alt"></i>Service Schedule</a>
    <div class="section-title">Application</div>
    <a class="navlink" href="pastor-ministries-application.php"><i class="fas fa-users"></i>Ministries Application</a>
    <a class="navlink" href="pastor-user-application.php"><i class="far fa-user"></i>User Application</a>
    <div class="section-title">Streaming</div>
    <a class="navlink" href="pastor-streaming.php"><i class="fas fa-video"></i>Streaming</a>
    <div class="section-title">Ministry List</div>
    <a class="navlink" href="pastor-women-ministries.php"><i class="fas fa-female"></i>Handmaid's of the Lord</a>
    <a class="navlink" href="pastor-men-ministries.php"><i class="fas fa-male"></i>Men's Ministry</a>
    <a class="navlink" href="pastor-music-ministries.php"><i class="fas fa-music"></i>Music's Ministry</a>
    <a class="navlink" href="pastor-usher-ministries.php"><i class="fas fa-hands-helping"></i>Usher &amp; Usherette</a>
    <a class="navlink" href="pastor-junior-ministries.php"><i class="fas fa-child"></i>Junior Christ Ambassador</a>
    <div class="section-title">Reports</div>
    <a class="navlink" href="pastor-report.php"><i class="fas fa-file-alt"></i>Reports</a>
    <div class="section-title">Content</div>
    <a class="navlink" href="pastor-content management.php"><i class="fas fa-edit"></i>Content Management</a>
    <div class="section-title">Management</div>
    <a class="navlink active" href="pastor-audittrails.php"><i class="fa fa-file"></i> Audit Trails</a>
    <a class="navlink" href="pastor-admin-accounts.php"><i class="fas fa-user"></i>Admin Accounts</a>
    <div class="section-title">More</div>
    <a class="navlink logout" href="all_log-in.php">
      <img alt="Logout" class="icon" src="image/logo-logout.png" width="18" height="18" style="vertical-align:middle;margin-right:8px;"> Log Out
    </a>
  </nav>
</aside>

<!-- MAIN CONTENT -->
<main class="audit-container">
  <?php
  $audit_query = "
    SELECT event_time, action, source_table, record_pk, actor_username, form_name, notes
    FROM audit_trail
    ORDER BY event_time DESC
  ";
  $audit_result2 = mysqli_query($db_connection, $audit_query);
  ?>
  <section class="card">
    <div class="card-title">Audit Trail Records</div>
    <p class="card-subtitle">
      Review system activities and changes recorded in the audit trail. Use the search, filters, and date range to refine results.
    </p>

    <div class="toolbar">
      <div class="tb-search" style="flex:2">
        <i class="fas fa-search"></i>
        <input
          id="auditSearch"
          type="text"
          placeholder="Search records by date, action, object, actor, form, or notes..."
          aria-label="Search audit trail records"
        >
      </div>

      <div class="tb-select" title="Filter results by action type">
        <i class="fas fa-filter" aria-hidden="true"></i>
        <select id="actionFilter" aria-label="Filter by action type">
          <option value="">All Action Types</option>
          <option>INSERT</option>
          <option>UPDATE</option>
          <option>DELETE</option>
          <option>SUBMIT</option>
          <option>APPROVE</option>
          <option>REJECT</option>
        </select>
      </div>
    </div>

    <div class="table-shell">
      <table class="audit-table-v2" id="auditTable">
        <thead>
          <tr>
            <th class="chk-cell"></th>
            <th>Date and Time</th>
            <th>Action</th>
            <th>Source Object</th>
            <th>Record Key</th>
            <th>Performed By</th>
            <th>Form / Module</th>
            <th>Remarks</th>
          </tr>
        </thead>
        <tbody>
          <?php
          if ($audit_result2 && mysqli_num_rows($audit_result2) > 0) {
            while ($r = mysqli_fetch_assoc($audit_result2)) {
              $action = htmlspecialchars($r['action']);
              $badgeClass = 'status-' . preg_replace('/[^A-Z]/','',$action);
              $formattedDate = date('F j, Y \@ g:i A', strtotime($r['event_time']));
              echo '<tr>';
              echo '<td></td>';
              echo '<td>'.$formattedDate.'</td>';
              echo '<td><span class="pill '.$badgeClass.'">'.$action.'</span></td>';
              echo '<td>'.htmlspecialchars($r['source_table']).'</td>';
              echo '<td>'.htmlspecialchars($r['record_pk']).'</td>';
              echo '<td>'.htmlspecialchars($r['actor_username']).'</td>';
              echo '<td>'.htmlspecialchars($r['form_name']).'</td>';
              echo '<td>'.htmlspecialchars($r['notes']).'</td>';
              echo '</tr>';
            }
          } else {
            echo "<tr><td colspan='8' style='text-align:center;padding:18px;color:#4b5563;'>
                    No audit trail records are currently available.
                  </td></tr>";
          }
          ?>
        </tbody>
      </table>
    </div>
  </section>
</main>

<script>
(function(){
  const search=document.getElementById('auditSearch');
  const action=document.getElementById('actionFilter');
  const tbody=document.querySelector('#auditTable tbody');
  const rows=[...tbody.rows];

  function filter(){
    const q=(search.value||'').toLowerCase();
    const act=action.value||'';
    rows.forEach(tr=>{
      const txt=tr.innerText.toLowerCase();
      const actTxt=tr.cells[2].innerText.trim();
      const ok=!q||txt.includes(q);
      const ok2=!act||actTxt===act;
      tr.style.display=(ok&&ok2)?'':'none';
    });
  }
  search.addEventListener('input',filter);
  action.addEventListener('change',filter);
})();
</script>

<!-- ✅ FROM–TO DATE RANGE + SMART SORT -->
<script>
(function(){
  const toolbar=document.querySelector('.toolbar');
  const tbody=document.querySelector('#auditTable tbody');
  if(!toolbar||!tbody)return;

  const range=document.createElement('div');
  range.className='tb-daterange';
  range.innerHTML=`<i class="far fa-calendar-check" aria-hidden="true"></i>
    <label for="fromDate">From Date</label><input id="fromDate" type="date" aria-label="Filter from date">
    <label for="toDate">To Date</label><input id="toDate" type="date" aria-label="Filter to date">`;

  const chips=document.createElement('div');
  chips.className='tb-sortchips';
  chips.innerHTML=`
    <button id="sortNewest" class="chip-btn" type="button" title="Sort by most recent first">
      <i class="fas fa-sort-amount-down-alt" aria-hidden="true"></i>&nbsp;Most Recent
    </button>
    <button id="sortOldest" class="chip-btn" type="button" title="Sort by oldest first">
      <i class="fas fa-sort-amount-up" aria-hidden="true"></i>&nbsp;Oldest
    </button>
    <button id="sortClear" class="chip-btn" type="button" title="Clear sorting selection">
      <i class="fas fa-times" aria-hidden="true"></i>&nbsp;Clear Sorting
    </button>`;

  toolbar.append(range,chips);

  const from=document.getElementById('fromDate');
  const to=document.getElementById('toDate');
  const sortN=document.getElementById('sortNewest');
  const sortO=document.getElementById('sortOldest');
  const sortC=document.getElementById('sortClear');

  function parseDate(txt){
    txt=txt.replace('@','').trim();
    let t=Date.parse(txt);
    if(isNaN(t)){
      const m=txt.match(/^([A-Za-z]+)\s+(\d{1,2}),\s*(\d{4})\s+(\d{1,2}:\d{2})\s*([AP]M)$/i);
      if(m)t=Date.parse(`${m[1]} ${m[2]}, ${m[3]} ${m[4]} ${m[5]}`);
    }
    return isNaN(t)?null:t;
  }

  function inRange(ts){
    const f=from.value?Date.parse(from.value+'T00:00:00'):null;
    const t2=to.value?Date.parse(to.value+'T23:59:59'):null;
    if(f&&ts<f)return false;
    if(t2&&ts>t2)return false;
    return true;
  }

  function apply(){
    const rows=[...tbody.rows];
    rows.forEach(tr=>{
      const shown=tr.style.display===''||tr.style.display==='table-row';
      if(!shown)return;
      const ts=parseDate(tr.cells[1].innerText);
      tr.style.display=inRange(ts)?'':'none';
    });
  }

  from.addEventListener('change',apply);
  to.addEventListener('change',apply);

  function sortRows(dir){
    const rows=[...tbody.rows];
    const vis=rows.filter(r=>r.style.display===''||r.style.display==='table-row');
    const hid=rows.filter(r=>r.style.display==='none');

    vis.sort((a,b)=>{
      const ta=parseDate(a.cells[1].innerText);
      const tb=parseDate(b.cells[1].innerText);
      if(ta===tb)return 0;
      return dir==='asc'?ta-tb:tb-ta;
    });

    const frag=document.createDocumentFragment();
    vis.forEach(r=>frag.appendChild(r));
    hid.forEach(r=>frag.appendChild(r));
    tbody.appendChild(frag);
  }

  function clearAct(){
    [sortN,sortO].forEach(b=>b.classList.remove('active'));
  }

  sortN.onclick=()=>{clearAct();sortN.classList.add('active');sortRows('desc');};
  sortO.onclick=()=>{clearAct();sortO.classList.add('active');sortRows('asc');};
  sortC.onclick=()=>clearAct();
})();
</script>

</body>
</html>
