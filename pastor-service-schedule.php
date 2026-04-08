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
                $sql = "SELECT * FROM service_baptism WHERE baptism_id = ? LIMIT 1"; break;
            case 'dedication':
                $sql = "SELECT * FROM service_dedication WHERE dedicationId = ? LIMIT 1"; break;
            case 'funeral':
                $sql = "SELECT * FROM service_funeral WHERE funeral_id = ? LIMIT 1"; break;
            case 'house':
                $sql = "SELECT * FROM service_house WHERE appointment_id = ? LIMIT 1"; break;
            case 'wedding':
                $sql = "SELECT * FROM service_wedding WHERE wedding_id = ? LIMIT 1"; break;
            default: $sql = null;
        }

        if ($sql && ($stmt = mysqli_prepare($db_connection, $sql))) {
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);

            if ($row) { $ok = true; $data = $row; }
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
    service_date,
    CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci AS service_time,
    'Baptism' COLLATE utf8mb4_general_ci AS event_type,
    baptized_name COLLATE utf8mb4_general_ci AS fullname,
    email_address COLLATE utf8mb4_general_ci AS contact,
    service_status COLLATE utf8mb4_general_ci AS service_status,
    'baptism' COLLATE utf8mb4_general_ci AS src,
    CAST(baptism_id AS UNSIGNED) AS pk
FROM service_baptism
WHERE service_status = 'Scheduled'

UNION ALL
SELECT 
    service_date,
    CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci AS service_time,
    'Dedication' COLLATE utf8mb4_general_ci AS event_type,
    child_full_name COLLATE utf8mb4_general_ci AS fullname,
    guardian_contact COLLATE utf8mb4_general_ci AS contact,
    service_status COLLATE utf8mb4_general_ci AS service_status,
    'dedication' COLLATE utf8mb4_general_ci AS src,
    CAST(dedicationId AS UNSIGNED) AS pk
FROM service_dedication
WHERE service_status = 'Scheduled'

UNION ALL
SELECT 
    service_date,
    CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci AS service_time,
    'Funeral' COLLATE utf8mb4_general_ci AS event_type,
    deceased_name COLLATE utf8mb4_general_ci AS fullname,
    email_address COLLATE utf8mb4_general_ci AS contact,
    service_status COLLATE utf8mb4_general_ci AS service_status,
    'funeral' COLLATE utf8mb4_general_ci AS src,
    CAST(funeral_id AS UNSIGNED) AS pk
FROM service_funeral
WHERE service_status = 'Scheduled'

UNION ALL
SELECT 
    service_date,
    CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci AS service_time,
    'House Blessing' COLLATE utf8mb4_general_ci AS event_type,
    owner_full_name COLLATE utf8mb4_general_ci AS fullname,
    contact_info COLLATE utf8mb4_general_ci AS contact,
    service_status COLLATE utf8mb4_general_ci AS service_status,
    'house' COLLATE utf8mb4_general_ci AS src,
    CAST(appointment_id AS UNSIGNED) AS pk
FROM service_house
WHERE service_status = 'Scheduled'

UNION ALL
SELECT 
    service_date,
    CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci AS service_time,
    'Wedding' COLLATE utf8mb4_general_ci AS event_type,
    CONCAT(groom_name, ' & ', bride_name) COLLATE utf8mb4_general_ci AS fullname,
    contact_number COLLATE utf8mb4_general_ci AS contact,
    service_status COLLATE utf8mb4_general_ci AS service_status,
    'wedding' COLLATE utf8mb4_general_ci AS src,
    CAST(wedding_id AS UNSIGNED) AS pk
FROM service_wedding
WHERE service_status = 'Scheduled'

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
</head>
<body>

<!-- ============== SIDEBAR ============== -->
<!-- ======================== SIDEBAR ======================== -->
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
    <a class="navlink active" href="pastor-service-schedule.php">
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
          <th>Fullname</th>
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
            $name = $row['fullname'];
            $contact = $row['contact'] ?: '—';
            $status = $row['service_status'];

            // data-* for sorting + responsive labels
            echo "<tr data-date='".h($rawDate)."' data-time='".h($rawTime)."' data-event='".strtolower(h($event))."' data-name='".strtolower(h($name))."' data-status='".strtolower(h($status))."'>";

            echo "<td data-label='Service Date'>".$dPretty.$tPretty."</td>";

            // Event Chip
            echo "<td data-label='Event Type'><span class='chip chip--event'>".h($event)."</span></td>";

            echo "<td data-label='Fullname'>".h($name)."</td>";
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
      <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i> Close</button>
    </header>
    <div class="body">
      <div class="kv" id="kvBody"></div>
    </div>
  </div>
</div>

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
}

// --- View button (AJAX) ---
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

      // Title fallback if your row doesn’t include 'Event'
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
   - No changes to your existing HTML/CSS/design.
   - We dynamically wrap the table in a scrollable container
     and set max-height = THEAD + (ROW * 4) + small buffer.
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
    // purely functional styles (no design changes)
    wrap.style.overflowY = 'auto';
    wrap.style.width = '100%';
    // insert wrapper and move table inside
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

    const total = headH + (rowH * n) + 2; // small buffer for borders
    wrap.style.maxHeight = total + 'px';
  }

  function applyLimit() { setRowLimit(8); }

  window.addEventListener('load', applyLimit);
  window.addEventListener('resize', applyLimit);
  // extra delayed runs to handle font/layout shifts
  window.addEventListener('load', () => {
    setTimeout(applyLimit, 120);
    setTimeout(applyLimit, 300);
  });
})();

/* ==========================================================
   ADDED: SMART SEARCH (Always ON, no checkbox)
   - Enhances your existing search. Your original listener stays.
   - Query features (examples):
       "exact phrase"     -> match phrase anywhere
       -word              -> exclude token
       name:juan          -> Fullname column filter
       event:wedding|baptism|funeral|house|dedication  (OR)
       status:scheduled|pending|done|declined          (OR / case-insensitive)
       contact:gmail.com  -> Contact/Email contains
       time:10:00         -> match time within date cell
       date:2025-10-01    -> specific date (Service Date)
       date:2025-10-01..2025-10-31  -> date range (inclusive)
       rodriguez~         -> fuzzy token (allows small typos; length>=5)
   - Highlights matches with <mark class="smart-hit">
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
  // cell looks like "October 12, 2025 @ 10:00 AM"
  if(!cellText) return null;
  const parts = String(cellText).split('@');
  const dateOnly = parts[0].trim(); // "October 12, 2025"
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
  // phrases
  const ph=[]; q=q.replace(/"([^"]+)"/g,(_,p)=>{ ph.push(p.trim()); return ' '; }); fields.phrase.push(...ph);
  // parts
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

  // clear previous highlights
  rows.forEach(ss_clearHighlights);

  const q = input.value || '';
  const parsed = ss_parseQuery(q);

  // needles for highlight
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
    // respect base filter: only refine visible rows
    if(row.style.display==='none'){ return; }

    const cDate = row.cells[0]?.innerText || '';
    const cEvent= row.cells[1]?.innerText || '';
    const cName = row.cells[2]?.innerText || '';
    const cCont = row.cells[3]?.innerText || '';
    const cStat = row.cells[4]?.innerText || '';
    const flat  = `${cDate} ${cEvent} ${cName} ${cCont} ${cStat}`;

    const whole = ss_norm(flat);

    // name:
    if(parsed.fields.name.length){
      const ok = parsed.fields.name.some(n => ss_norm(cName).includes(ss_norm(n)));
      if(!ok){ row.style.display='none'; return; }
    }

    // event:
    if(parsed.fields.event.length){
      const ev = ss_norm(cEvent);
      const ok = parsed.fields.event.some(e => ev===e || ev.includes(e));
      if(!ok){ row.style.display='none'; return; }
    }

    // status:
    if(parsed.fields.status.length){
      const st = ss_norm(cStat);
      const ok = parsed.fields.status.some(s => st===s || st.includes(s));
      if(!ok){ row.style.display='none'; return; }
    }

    // contact/email:
    if(parsed.fields.contact.length){
      const cont = ss_norm(cCont);
      const ok = parsed.fields.contact.some(x => cont.includes(ss_norm(x)));
      if(!ok){ row.style.display='none'; return; }
    }

    // time:
    if(parsed.fields.time.length){
      const ok = parsed.fields.time.some(t => ss_norm(cDate).includes(t));
      if(!ok){ row.style.display='none'; return; }
    }

    // date:
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

    // negative tokens
    if(parsed.neg.length){
      const bad = parsed.neg.some(n => whole.includes(ss_norm(n)));
      if(bad){ row.style.display='none'; return; }
    }

    // phrases must ALL match
    if(parsed.fields.phrase.length){
      const ok = parsed.fields.phrase.every(p => whole.includes(ss_norm(p)));
      if(!ok){ row.style.display='none'; return; }
    }

    // plain tokens: AND
    if(parsed.tokens.length){
      const ok = parsed.tokens.every(t => whole.includes(ss_norm(t)));
      if(!ok){ row.style.display='none'; return; }
    }

    // fuzzy tokens: ANY may match (distance<=2 for len>=5)
    if(parsed.fuzzy.length){
      const ok = parsed.fuzzy.some(f => {
        if(whole.includes(ss_norm(f))) return true;
        if(f.length>=5) return ss_lev(flat, f) <= 2;
        return false;
      });
      if(!ok){ row.style.display='none'; return; }
    }

    // highlight after passing all checks
    ss_highlightRow(row, needles);
  });
}

// Hook AFTER your base listener (default-on, no checkbox)
['input','change','keyup'].forEach(ev=>{
  sb.addEventListener(ev, ss_smartFilter);
});
window.addEventListener('load', ss_smartFilter);
</script>

</body>
</html>
