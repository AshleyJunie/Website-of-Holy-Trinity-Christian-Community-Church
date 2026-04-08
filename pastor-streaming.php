<?php
/** -----------------------------------------------------------
 *  pastor-streaming.php (rewritten)
 *  - Accepts any logged-in role (admin/pastor/secretary/user)
 *  - Correct login redirect: /HTCCC-SYSTEM/all_log-in.php
 *  - Stable sessions + CSRF on POST
 *  - Keeps your UI/JS features intact
 * ---------------------------------------------------------- */

// Session setup (root path cookie; keeps POST logged in)
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'domain'   => '',
  'secure'   => !empty($_SERVER['HTTPS']),
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();
require_once 'db-connection.php';

define('LOGIN_PAGE', '/HTCCC-SYSTEM/all_log-in.php'); // HY-PHEN and absolute path

// -------- Auth guard: accept any role ----------
$user_id = null; $user_role = null;
foreach (['admin_id','pastor_id','secretary_id','user_id'] as $k) {
  if (!empty($_SESSION[$k])) { $user_id = (int)$_SESSION[$k]; $user_role = $k; break; }
}
if (!$user_id) { header('Location: ' . LOGIN_PAGE); exit; }

// Keep $admin_id for DB compatibility (owner/actor id)
$admin_id = $user_id;

// -------- CSRF token (+ early POST check) ----------
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $_SESSION['flash_error'] = 'Your session expired. Please try again.';
    header('Location: ' . ($_SERVER['PHP_SELF'] ?? '/HTCCC-SYSTEM/pastor-streaming.php'));
    exit;
  }
}

// -------- Flash helpers ----------
function flash_set($type,$msg){ $_SESSION["flash_$type"]=$msg; }
function flash_get($type){ if(!empty($_SESSION["flash_$type"])){ $m=$_SESSION["flash_$type"]; unset($_SESSION["flash_$type"]); return $m;} return null; }

// -------- Actions ----------
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['stop_live'])) {
  $ok = mysqli_query($db_connection, "UPDATE multimedia SET live_status='Inactive' WHERE live_status='Active'");
  $ok ? flash_set('success','All active live streams have been set to Inactive.')
      : flash_set('error','Failed to stop live streams: '.mysqli_error($db_connection));
  header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['liveStreamUrl'])) {
  $fb_link = trim($_POST['liveStreamUrl'] ?? '');
  if ($fb_link==='') {
    flash_set('error','Please enter a valid link.');
  } else {
    mysqli_query($db_connection, "UPDATE multimedia SET live_status='Inactive'");
    $sql="INSERT INTO multimedia (fb_link, live_status, admin_id) VALUES (?, 'Active', ?)";
    if ($stmt=mysqli_prepare($db_connection,$sql)) {
      mysqli_stmt_bind_param($stmt,"si",$fb_link,$admin_id);
      if (mysqli_stmt_execute($stmt)) flash_set('success','Live stream link saved successfully!');
      else flash_set('error','Error saving link: '.mysqli_error($db_connection));
      mysqli_stmt_close($stmt);
    } else {
      flash_set('error','Database prepare failed.');
    }
  }
  header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

// -------- Data for view ----------
$history=[]; $res=mysqli_query($db_connection,"SELECT livemassId, fb_link, live_status, admin_id, date_uploaded FROM multimedia ORDER BY livemassId DESC");
if($res) while($row=mysqli_fetch_assoc($res)) $history[]=$row;

$active_iframe_src=null;
$q=mysqli_query($db_connection,"SELECT fb_link FROM multimedia WHERE live_status='Active' ORDER BY livemassId DESC LIMIT 1");
if($q && ($r=mysqli_fetch_assoc($q))) $active_iframe_src=$r['fb_link'];

$flash_success=flash_get('success'); $flash_error=flash_get('error');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta content="width=device-width, initial-scale=1"/>
  <title>Admin - Multimedia</title>

  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/admin-multimedia.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/admin-multimedia.css'); ?>">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    .panel-body label[for="liveStreamUrl"]{display:block;font-weight:600;color:#1B1B4B;margin:0 0 8px 2px;}
    .stream-form{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
    .input-wrap{position:relative;flex:1 1 520px;min-width:260px;}
    .input-wrap .icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:14px;color:#6B5AE3;pointer-events:none;}
    .stream-input{width:100%;height:44px;border:1.5px solid #e2e7ff;border-radius:12px;background:#fff;color:#1B1B4B;padding:10px 14px 10px 36px;outline:none;box-shadow:inset 0 1px 0 rgba(0,0,0,.02);transition:.15s;}
    .stream-input::placeholder{color:#9aa3b2;}
    .stream-input:focus{border-color:#6B5AE3;box-shadow:0 0 0 4px rgba(107,90,227,.12);}
    .btn{display:inline-flex;align-items:center;gap:8px;border:0;cursor:pointer;border-radius:12px;padding:10px 16px;font-size:.95rem}
    .btn.primary{height:44px;background:#6B5AE3;color:#fff;font-weight:700;}
    .btn.secondary{height:38px;background:#eef;color:#1B1B4B}
    .btn.ghost{background:#f4f6f8;color:#333}
    @media (max-width:640px){.stream-form{gap:10px}.input-wrap{flex:1 1 100%}.btn.primary{width:100%}}

    .table-wrap{width:100%;overflow:auto;}
    .tbl{width:100%;border-collapse:separate;border-spacing:0;background:#fff;border:1px solid #e7e9f1;border-radius:12px;overflow:hidden;font-size:14px;}
    .tbl th{background:#f7f8fc;color:#28304a;text-align:left;padding:12px 14px;border-bottom:1px solid #e7e9f1;font-weight:600;}
    .tbl td{padding:10px 14px;border-bottom:1px solid #f0f2f7;}
    .tbl tbody tr:nth-child(even){background:#fbfcfe;}
    .badge{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;border:1px solid transparent;}
    .badge.active{background:#ecfdf3;color:#067647;border-color:#b7f0d0;}
    .badge.inactive{background:#fff1f2;color:#b42318;border-color:#ffccd0;}

    .lf-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:1000}
    .lf-modal{background:#fff;border-radius:14px;max-width:720px;width:92%;box-shadow:0 10px 30px rgba(0,0,0,.25)}
    .lf-modal header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #eee}
    .lf-modal .lf-close{background:none;border:0;font-size:20px;cursor:pointer;opacity:.7}
    .lf-modal .body{padding:18px 20px}
    .lf-modal textarea,.lf-modal input[type="text"]{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:10px;font-size:.95rem}
    .lf-modal textarea{min-height:120px;resize:vertical}
    .lf-modal .actions{display:flex;gap:10px;justify-content:flex-end;padding:14px 20px;border-top:1px solid #eee}

    .hist-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:6px 0 10px}
    .hist-tools .hist-input{height:36px;border:1px solid #e2e7ff;border-radius:10px;padding:6px 12px;min-width:240px;outline:none;transition:.15s;background:#fff;color:#1B1B4B;}
    .hist-tools .hist-input:focus{border-color:#6B5AE3;box-shadow:0 0 0 3px rgba(107,90,227,.12)}

    .tbl th.sortable{cursor:pointer;user-select:none;position:relative;padding-right:28px}
    .tbl th.sortable:after{content:"";position:absolute;right:10px;top:50%;transform:translateY(-50%);border:5px solid transparent;border-top-color:#9aa3b2;opacity:.8}
    .tbl th.sortable.sort-asc:after{border:5px solid transparent;border-bottom-color:#6B5AE3;top:45%}
    .tbl th.sortable.sort-desc:after{border:5px solid transparent;border-top-color:#6B5AE3;top:55%}
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
    <a class="navlink" href="pastor-admin-request.php"><i class="far fa-calendar-plus"></i>Appointment Request</a>
    <a class="navlink" href="pastor-prayer-request.php"><i class="far fa-hand-paper"></i><span>Prayer Request</span></a>

    <div class="section-title">Schedule</div>
    <a class="navlink" href="pastor-appointment-schedule.php"><i class="far fa-calendar-alt"></i>Appointment Schedule</a>
    <a class="navlink" href="pastor-service-schedule.php"><i class="fas fa-calendar-alt"></i>Service Schedule</a>

    <div class="section-title">Application</div>
    <a class="navlink" href="pastor-ministries-application.php"><i class="fas fa-users"></i>Ministries Application</a>
    <a class="navlink" href="pastor-user-application.php"><i class="far fa-user"></i>User Application</a>

    <div class="section-title">Streaming</div>
    <a class="navlink active" href="pastor-streaming.php"><i class="fas fa-video"></i>Streaming</a>

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
    <a class="navlink" href="content-management_home-page.php"><i class="fas fa-edit"></i> Audit Trails</a>

    <div class="section-title">More</div>
    <a class="navlink logout" href="all_log-in.php">
      <img alt="Logout" class="icon" src="image/logo-logout.png" width="18" height="18" style="vertical-align:middle;margin-right:8px;"> Log Out
    </a>
  </nav>
</aside>

<!-- ============== PAGE CONTENT ============== -->
<div class="page">
  <header class="topbar"><h1>Multimedia</h1></header>

  <main class="content">
    <!-- Post Live Stream -->
    <section class="panel">
      <div class="panel-head" style="display:flex;align-items:center;justify-content:space-between;">
        <h2>Post Live Stream</h2>
        <button type="button" class="btn secondary" id="openLinkFixer"><i class="fas fa-link"></i> Link Fixer</button>
      </div>
      <div class="panel-body">
        <form class="stream-form" method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES); ?>">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf'], ENT_QUOTES); ?>">
          <div style="flex:1 1 100%;"><label for="liveStreamUrl">Live stream link URL</label></div>
          <div class="input-wrap">
            <i class="fas fa-link icon"></i>
            <input type="text" id="liveStreamUrl" name="liveStreamUrl" class="stream-input" placeholder="    https://…" required>
          </div>
          <button type="submit" class="btn primary">Post</button>
        </form>
        <p class="small muted" style="margin-top:8px;color:#667085;">Tip: Use <strong>Link Fixer</strong> to extract only the <code>src</code> URL from a full Facebook embed.</p>
      </div>
    </section>

    <!-- Live Stream History + On Streaming -->
    <section class="grid">
      <article class="panel">
        <div class="panel-head"><h2>Live Stream History</h2></div>
        <div class="panel-body">
          <div class="table-wrap">
            <table class="tbl" id="historyTable">
              <thead>
                <tr><th>ID</th><th>Date Uploaded</th><th>Status</th><th>Admin ID</th></tr>
              </thead>
              <tbody>
              <?php if ($history): foreach ($history as $h): ?>
                <tr>
                  <td><?= htmlspecialchars($h['livemassId']) ?></td>
                  <td><?= htmlspecialchars(!empty($h['date_uploaded']) ? date("F j, Y — g:i A", strtotime($h['date_uploaded'])) : '—') ?></td>
                  <td><span class="badge <?= strtolower($h['live_status'])==='active'?'active':'inactive' ?>"><?= htmlspecialchars($h['live_status']) ?></span></td>
                  <td><?= htmlspecialchars($h['admin_id']) ?></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="4" class="muted">No records yet.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </article>

      <article class="panel">
        <div class="panel-head"><h2>On Streaming</h2></div>
        <div class="panel-body" style="text-align:center;">
          <?php if ($active_iframe_src): ?>
            <iframe src="<?= htmlspecialchars($active_iframe_src) ?>" width="560" height="314"
                    style="border:none;overflow:hidden;max-width:100%;border-radius:10px"
                    frameborder="0" allowfullscreen="true"
                    allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share"></iframe>

            <form method="post" id="stopLiveForm" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES); ?>" style="margin-top:16px;">
              <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf'], ENT_QUOTES); ?>">
              <input type="hidden" name="stop_live" value="1">
              <button type="submit" class="btn secondary" id="stopLiveBtn"><i class="fas fa-stop-circle"></i> End Live Stream</button>
            </form>
          <?php else: ?>
            <div class="no-stream">NO STREAM AVAILABLE</div>
          <?php endif; ?>
        </div>
      </article>
    </section>
  </main>
</div>

<!-- ============== LINK FIXER MODAL ============== -->
<div class="lf-modal-backdrop" id="lfModalBackdrop" aria-hidden="true">
  <div class="lf-modal" role="dialog" aria-modal="true" aria-labelledby="lfTitle">
    <header>
      <h3 id="lfTitle">Link Fixer</h3>
      <button class="lf-close" id="lfCloseBtn" aria-label="Close">&times;</button>
    </header>
    <div class="body">
      <label for="lfInput">Paste full Facebook embed code</label>
      <textarea id="lfInput" placeholder='Example: &lt;iframe src="https://www.facebook.com/plugins/video.php?..."&gt;&lt;/iframe&gt;'></textarea>
      <label for="lfOutput" style="margin-top:10px;">Fixed Link</label>
      <input type="text" id="lfOutput" readonly placeholder="Result will appear here">
    </div>
    <div class="actions">
      <button class="btn ghost" id="lfClearBtn"><i class="fas fa-eraser"></i> Clear</button>
      <button class="btn secondary" id="lfCopyBtn"><i class="fas fa-copy"></i> Copy</button>
      <button class="btn primary" id="lfExtractBtn"><i class="fas fa-magic"></i> Fix Link</button>
    </div>
  </div>
</div>

<!-- JS: Modal + Link Fixer + Stop Confirm -->
<script>
(function(){
  const openBtn=document.getElementById('openLinkFixer');
  const backdrop=document.getElementById('lfModalBackdrop');
  const closeBtn=document.getElementById('lfCloseBtn');
  const inputEl=document.getElementById('lfInput');
  const outputEl=document.getElementById('lfOutput');
  const extractBtn=document.getElementById('lfExtractBtn');
  const copyBtn=document.getElementById('lfCopyBtn');
  const clearBtn=document.getElementById('lfClearBtn');
  const mainInput=document.getElementById('liveStreamUrl');

  function openModal(){backdrop.style.display='flex';backdrop.setAttribute('aria-hidden','false');setTimeout(()=>inputEl&&inputEl.focus(),0);}
  function closeModal(){backdrop.style.display='none';backdrop.setAttribute('aria-hidden','true');}
  if (openBtn) openBtn.addEventListener('click', openModal);
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  if (backdrop) backdrop.addEventListener('click', e=>{ if(e.target===backdrop) closeModal(); });
  document.addEventListener('keydown', e=>{ if(e.key==='Escape' && backdrop && backdrop.style.display==='flex') closeModal(); });

  function extractSrc(raw){
    if(!raw) return '';
    const s=raw.trim();
    if(/^https?:\/\//i.test(s)) return s;
    const m=s.match(/\s(?:src)\s*=\s*(['"])(.*?)\1/i);
    if(m && m[2]) return m[2].trim();
    try { const d=document.createElement('div'); d.innerHTML=s; const ifr=d.querySelector('iframe'); if(ifr && ifr.getAttribute('src')) return ifr.getAttribute('src').trim(); } catch(e){}
    return '';
  }

  if (extractBtn) extractBtn.addEventListener('click', ()=>{
    const src=extractSrc(inputEl.value);
    outputEl.value = src || '';
    if(!src){ outputEl.placeholder='No src found. Paste a full iframe embed or a direct URL.'; return; }
    if(mainInput) mainInput.value=src;
    Swal.fire({icon:'success',title:'Link fixed!',text:'The link has been extracted and added to the input.'});
    closeModal();
  });

  if (copyBtn) copyBtn.addEventListener('click', async ()=>{
    const val=(outputEl.value||'').trim(); if(!val) return;
    try{ await navigator.clipboard.writeText(val); copyBtn.innerHTML='<i class="fas fa-check"></i> Copied'; setTimeout(()=>copyBtn.innerHTML='<i class="fas fa-copy"></i> Copy',1200); }
    catch{ outputEl.select(); document.execCommand('copy'); copyBtn.innerHTML='<i class="fas fa-check"></i> Copied'; setTimeout(()=>copyBtn.innerHTML='<i class="fas fa-copy"></i> Copy',1200); }
  });

  if (clearBtn) clearBtn.addEventListener('click', ()=>{ inputEl.value=''; outputEl.value=''; inputEl.focus(); });

  // Stop confirmation
  document.addEventListener('DOMContentLoaded', ()=>{
    const stopBtn=document.getElementById('stopLiveBtn');
    const stopForm=document.getElementById('stopLiveForm');
    if (stopBtn && stopForm) {
      stopBtn.addEventListener('click', e=>{
        e.preventDefault();
        Swal.fire({title:'Stop Live Stream?',text:'This will end the current live broadcast.',icon:'warning',showCancelButton:true,confirmButtonColor:'#6B5AE3',cancelButtonColor:'#aaa',confirmButtonText:'Yes, Stop it'})
          .then(res=>{ if(res.isConfirmed){ if(stopForm.requestSubmit) stopForm.requestSubmit(stopBtn); else stopForm.submit(); }});
      });
    }
  });
})();
</script>

<!-- Limit Live Stream History visible rows -->
<script>
(function(){
  const WRAP_CLASS='js-table-scroll-wrap';
  function getTable(){ const grid=document.querySelector('section.grid'); return grid?grid.querySelector('article.panel .panel-body .table-wrap .tbl'):null; }
  function ensureWrap(table){ if(table.parentElement.classList.contains(WRAP_CLASS)) return table.parentElement; const w=document.createElement('div'); w.className=WRAP_CLASS; w.style.overflowY='auto'; w.style.width='100%'; table.parentElement.insertBefore(w,table); w.appendChild(table); return w; }
  function firstVisible(tb){ const rows=tb?Array.from(tb.rows):[]; return rows.find(r=>r && r.offsetParent!==null); }
  function setLimit(n){
    const table=getTable(); if(!table) return;
    const wrap=ensureWrap(table);
    const thead=table.tHead && table.tHead.rows[0]?table.tHead.rows[0]:null;
    const tbody=table.tBodies && table.tBodies[0]?table.tBodies[0]:null;
    if(!thead || !tbody) return;
    const headH=Math.ceil(thead.getBoundingClientRect().height||44);
    const rowEl=firstVisible(tbody)||tbody.rows[0]; if(!rowEl) return;
    const rowH=Math.ceil(rowEl.getBoundingClientRect().height||44);
    wrap.style.maxHeight=(headH+(rowH*n)+2)+'px';
  }
  function apply(){ setLimit(5); }
  window.addEventListener('load', apply);
  window.addEventListener('resize', apply);
  window.addEventListener('load', ()=>{ setTimeout(apply,120); setTimeout(apply,300); });
})();
</script>

<!-- Search + Sort for history table -->
<script>
(function(){
  function insertSearch(){
    const grid=document.querySelector('section.grid'); if(!grid) return;
    const body=grid.querySelector('article.panel .panel-body'); const table=document.getElementById('historyTable'); if(!body||!table) return;
    const tools=document.createElement('div'); tools.className='hist-tools';
    const input=document.createElement('input'); input.type='text'; input.className='hist-input'; input.placeholder='Search history… (type to filter)'; input.id='histSearchInput';
    tools.appendChild(input);
    const wrap=body.querySelector('.table-wrap'); if(wrap) body.insertBefore(tools,wrap); else body.appendChild(tools);
    const rows=Array.from(table.tBodies[0]?.rows||[]);
    const norm=s=> (s||'').toString().toLowerCase();
    input.addEventListener('input', ()=>{ const q=norm(input.value); rows.forEach(tr=>{ tr.style.display = norm(tr.innerText).includes(q) ? '' : 'none'; }); });
  }

  function enableSort(){
    const table=document.getElementById('historyTable'); if(!table || !table.tHead) return;
    const ths=Array.from(table.tHead.rows[0].cells);
    ths.forEach((th,i)=>{ th.classList.add('sortable'); th.addEventListener('click', ()=>toggle(i,th)); });
    Array.from(table.tBodies[0].rows).forEach((tr,i)=> tr.dataset._order=i);

    function toggle(col,th){
      const cur=th.dataset.dir||''; const next=(cur==='asc'?'desc':'asc');
      ths.forEach(h=>{h.classList.remove('sort-asc','sort-desc'); h.dataset.dir='';});
      th.dataset.dir=next; th.classList.add(next==='asc'?'sort-asc':'sort-desc');
      sort(col,next==='asc');
    }
    function sort(col,asc=true){
      const tbody=table.tBodies[0]; const rows=Array.from(tbody.rows);
      const type=(col===0||col===3)?'number':(col===1?'date':'text');
      rows.sort((a,b)=>{
        const av=val(a,col,type), bv=val(b,col,type);
        let cmp=0; if(type==='number'||type==='date') cmp=av-bv; else cmp=String(av).localeCompare(String(bv));
        if(cmp===0) cmp=(parseInt(a.dataset._order||'0',10)-parseInt(b.dataset._order||'0',10));
        return asc?cmp:-cmp;
      });
      rows.forEach(r=>tbody.appendChild(r));
    }
    function val(tr,idx,type){
      const td=tr.cells[idx]; if(!td) return (type!=='text'?0:'');
      let raw=td.innerText.trim();
      if(type==='number'){ const n=parseFloat(raw.replace(/[^\d.-]/g,'')); return isNaN(n)?0:n; }
      if(type==='date'){ const norm=raw.replace(/\s*—\s*/,' ').replace(/\s+/g,' ').trim(); const ts=Date.parse(norm); return isNaN(ts)?0:ts; }
      return raw.toLowerCase();
    }
  }

  window.addEventListener('load', insertSearch);
  window.addEventListener('load', enableSort);
})();
</script>

<?php if($flash_success): ?>
<script>Swal.fire({icon:'success',title:'Success',text:<?= json_encode($flash_success) ?>});</script>
<?php elseif($flash_error): ?>
<script>Swal.fire({icon:'error',title:'Error',text:<?= json_encode($flash_error) ?>});</script>
<?php endif; ?>

</body>
</html>
