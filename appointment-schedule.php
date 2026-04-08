<?php
/* =======================================================================
   Admin – Approved Appointments
   - Lists only service_status='Scheduled' across service_* tables
   - Columns show REQUESTER info (Last, First, Middle, Email)
   - View = show full appointment/service details in a modal
   - Done = set service_status='Done' (row disappears from this list)
   - Wedding Done ALSO sets individual_table.civil_status='Married'
   - “Create a appointment” button restored (onsite_appointment.php)
   ======================================================================= */

/* ---------------- Dev error panel (disable via DISPLAY_ERRORS=false) --- */
if (!defined('DEV_ERROR_PANEL')) {
  define('DEV_ERROR_PANEL', 1);
  $SHOW = getenv('DISPLAY_ERRORS') !== 'false';
  if ($SHOW) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('html_errors', '0');
    ini_set('log_errors', '1');
  }
  function _dev_is_ajax_like() {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) return true;
    if (isset($_GET['view']) && $_GET['view'] == '1') return true;
    if (isset($_POST['action']) && $_POST['action'] === 'updateStatus') return true;
    $ct = $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
    return stripos($ct, 'application/json') !== false;
  }
  function _dev_render_html_box($title,$message,$file,$line){
    $e = fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    echo '<style>.dev-error-box{all:initial;font-family:Inter,Arial,sans-serif;display:block;box-sizing:border-box}
.dev-error-wrap{position:relative;margin:10px;border-radius:12px;overflow:hidden;border:1px solid #5a1b1b;box-shadow:0 10px 30px rgba(0,0,0,.25)}
.dev-error-head{background:#8b1e1e;color:#fff;padding:10px 14px;font-weight:700}
.dev-error-body{background:#1a1a1a;color:#ffdede;padding:12px 14px;line-height:1.5}
.dev-error-body code{background:#2a2a2a;color:#fff;padding:2px 6px;border-radius:6px}
.dev-error-meta{margin-top:8px;font-size:12px;opacity:.85}</style>
<div class="dev-error-box"><div class="dev-error-wrap"><div class="dev-error-head">'.$e($title).'</div><div class="dev-error-body"><div>'.
      $e($message).'</div><div class="dev-error-meta">File: <code>'.$e($file).'</code> &nbsp; Line: <code>'.$e($line).
      '</code></div></div></div></div>';
  }
  set_error_handler(function($errno,$errstr,$errfile,$errline){
    if (!(error_reporting() & $errno)) return false;
    if (_dev_is_ajax_like()) {
      if (!headers_sent()) @header('Content-Type: application/json');
      echo json_encode(['ok'=>false,'type'=>'php_error','errno'=>$errno,'message'=>$errstr,'file'=>$errfile,'line'=>$errline], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    } else { _dev_render_html_box('PHP Error',$errstr,$errfile,$errline); }
    return true;
  });
  register_shutdown_function(function(){
    $e = error_get_last(); if(!$e) return;
    if (!in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) return;
    if (_dev_is_ajax_like()) {
      if (!headers_sent()) @header('Content-Type: application/json');
      echo json_encode(['ok'=>false,'type'=>'php_fatal','message'=>$e['message']??'','file'=>$e['file']??'','line'=>$e['line']??0], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    } else { _dev_render_html_box('PHP Fatal Error',$e['message']??'',$e['file']??'',$e['line']??0); }
  });
}

/* ---------------- DB & session ---------------- */
include 'db-connection.php';
@session_start();
@mysqli_set_charset($db_connection, 'utf8mb4');
@mysqli_query($db_connection, "SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

/* ---------------- Helpers ---------------- */
function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function is_image_path($v) {
  if (!$v) return false;
  $ext = strtolower(pathinfo($v, PATHINFO_EXTENSION));
  return in_array($ext, ['jpg','jpeg','png','gif','webp','bmp']);
}
function to_url($v) {
  if (!$v) return '';
  if (preg_match('~^https?://~i', $v)) return $v;
  return '/' . ltrim($v, '/');
}
function table_has_column($mysqli, $table, $column) {
  $db = @mysqli_fetch_row(@mysqli_query($mysqli, "SELECT DATABASE()"))[0] ?? null;
  if (!$db) return false;
  $stmt = @mysqli_prepare($mysqli, "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
  if (!$stmt) return false;
  @mysqli_stmt_bind_param($stmt,'sss',$db,$table,$column);
  @mysqli_stmt_execute($stmt);
  $res=@mysqli_stmt_get_result($stmt);
  $row=$res?@mysqli_fetch_row($res):null;
  return !empty($row[0]);
}

/* ---------------- AJAX: View details (modal) ---------------- */
if (isset($_GET['view']) && isset($_GET['svc']) && isset($_GET['id'])) {
  $svc = $_GET['svc']; $id  = $_GET['id'];

  switch ($svc) {
    case 'dedication':
      $sql = "SELECT d.*,
                     COALESCE(i.individual_lastname, d.child_lastname)   AS last_name,
                     COALESCE(i.individual_firstname, d.child_firstname) AS first_name,
                     COALESCE(i.individual_middlename, d.child_middlename) AS middle_name,
                     TRIM(CONCAT_WS(' ',
                       COALESCE(i.individual_firstname, d.child_firstname),
                       COALESCE(i.individual_middlename, d.child_middlename),
                       COALESCE(i.individual_lastname, d.child_lastname),
                       d.child_ext
                     )) AS full_name,
                     COALESCE(i.individual_email_address, d.email_address) AS individual_email_address
              FROM service_dedication d
              LEFT JOIN individual_table i ON i.individual_id=d.individual_id
              WHERE d.dedicationId=?";
      $title = 'Dedication'; break;

    case 'funeral':
      $sql = "SELECT f.*,
                     COALESCE(i.individual_lastname, f.deceased_lastname)   AS last_name,
                     COALESCE(i.individual_firstname, f.deceased_firstname) AS first_name,
                     COALESCE(i.individual_middlename, f.deceased_middlename) AS middle_name,
                     TRIM(CONCAT_WS(' ',
                       COALESCE(i.individual_firstname, f.deceased_firstname),
                       COALESCE(i.individual_middlename, f.deceased_middlename),
                       COALESCE(i.individual_lastname, f.deceased_lastname),
                       f.deceased_ext
                     )) AS full_name,
                     COALESCE(i.individual_email_address, f.email_address) AS individual_email_address
              FROM service_funeral f
              LEFT JOIN individual_table i ON i.individual_id=f.individual_id
              WHERE f.funeral_id=?";
      $title = 'Funeral'; break;

    case 'house':
      $sql = "SELECT h.*,
                     COALESCE(i.individual_lastname, h.owner_lastname)     AS last_name,
                     COALESCE(i.individual_firstname, h.owner_firstname)   AS first_name,
                     COALESCE(i.individual_middlename, h.owner_middlename) AS middle_name,
                     TRIM(CONCAT_WS(' ',
                       COALESCE(i.individual_firstname, h.owner_firstname),
                       COALESCE(i.individual_middlename, h.owner_middlename),
                       COALESCE(i.individual_lastname, h.owner_lastname),
                       h.owner_ext
                     )) AS full_name,
                     COALESCE(i.individual_email_address, h.email_address) AS individual_email_address
              FROM service_house h
              LEFT JOIN individual_table i ON i.individual_id=h.individual_id
              WHERE h.house_id=?";
      $title = 'House Blessing'; break;

    case 'wedding':
      $sql = "SELECT w.*,
                     TRIM(CONCAT_WS(' ',
                       w.groom_firstname,
                       w.groom_middlename,
                       w.groom_lastname,
                       w.groom_extension
                     )) AS groom_full_name,
                     TRIM(CONCAT_WS(' ',
                       w.bride_firstname,
                       w.bride_middlename,
                       w.bride_lastname,
                       w.bride_extension
                     )) AS bride_full_name,
                     COALESCE(i.individual_lastname,
                              CONCAT_WS(' & ',
                                TRIM(CONCAT_WS(' ', w.groom_firstname, w.groom_middlename, w.groom_lastname, w.groom_extension)),
                                TRIM(CONCAT_WS(' ', w.bride_firstname, w.bride_middlename, w.bride_lastname, w.bride_extension))
                              )
                     ) AS last_name,
                     i.individual_firstname AS first_name,
                     i.individual_middlename AS middle_name,
                     TRIM(CONCAT_WS(' ',
                       i.individual_firstname,
                       i.individual_middlename,
                       COALESCE(i.individual_lastname,
                                CONCAT_WS(' & ',
                                  TRIM(CONCAT_WS(' ', w.groom_firstname, w.groom_middlename, w.groom_lastname, w.groom_extension)),
                                  TRIM(CONCAT_WS(' ', w.bride_firstname, w.bride_middlename, w.bride_lastname, w.bride_extension))
                                )
                       )
                     )) AS full_name,
                     COALESCE(i.individual_email_address, w.email_address) AS individual_email_address
              FROM service_wedding w
              LEFT JOIN individual_table i ON i.individual_id=w.individual_id
              WHERE w.wedding_id=?";
      $title = 'Wedding'; break;

    default:
      http_response_code(400); echo 'Unknown service'; exit;
  }

  $stmt = mysqli_prepare($db_connection, $sql);
  mysqli_stmt_bind_param($stmt, 's', $id);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $row = $res ? mysqli_fetch_assoc($res) : null;

  if (!$row) { echo '<div class="modal-body">Record not found.</div>'; exit; }

  $kv = function($label, $value) {
    echo '<div class="kv"><span>'.h($label).'</span><b>'.h(($value === '' || $value === null) ? '—' : $value).'</b></div>';
  };
  $kvFile = function($label, $path) {
    echo '<div class="kv"><span>'.h($label).'</span>';
    if (!$path) { echo '<b>—</b>'; }
    else if (is_image_path($path)) {
      echo '<button class="btn tiny view-image" data-img="'.h(to_url($path)).'">View image</button>';
    } else {
      echo '<a class="btn tiny link" href="'.h(to_url($path)).'" target="_blank" rel="noopener">Open file</a>';
    }
    echo '</div>';
  };

  ob_start(); ?>
  <div class="modal-header">
    <h3><?=h($title)?> Appointment Details</h3>
    <button class="modal-close" onclick="closeModal()">×</button>
  </div>
  <div class="modal-body">
    <div class="modal-section">
      <div class="section-heading">Appointment Information</div>
      <div class="kv-group">
        <?php
          if ($svc === 'wedding') {
            $apptDate = !empty($row['appointment_date']) ? date('M d, Y', strtotime($row['appointment_date'])) : '—';
            $apptTime = $row['appointment_time'] ?? '—';
            $kv('Appointment Date', $apptDate);
            $kv('Appointment Time', $apptTime);
          }

          $svcDate = !empty($row['service_date']) ? date('M d, Y', strtotime($row['service_date'])) : '—';
          $svcTime = !empty($row['service_time']) ? $row['service_time'] : '—';
          $kv('Service Date', $svcDate);
          $kv('Service Time', $svcTime);
        ?>
      </div>
    </div>

    <div class="modal-section">
      <div class="section-heading">Requester Information</div>
      <div class="kv-group">
        <?php
          $kv('Last Name',   $row['last_name']   ?? '—');
          $kv('First Name',  $row['first_name']  ?? '—');
          $kv('Middle Name', $row['middle_name'] ?? '—');
          $kv('Email',       $row['individual_email_address'] ?? ($row['email_address'] ?? '—'));
        ?>
      </div>
    </div>

    <div class="modal-section">
      <div class="section-heading">
        <?php
          if ($svc === 'wedding')      echo 'Wedding Details';
          elseif ($svc === 'dedication') echo 'Child / Guardian Details';
          elseif ($svc === 'funeral')    echo 'Funeral Details';
          elseif ($svc === 'house')      echo 'House Blessing Details';
          else                           echo 'Additional Details';
        ?>
      </div>
      <div class="kv-group">
        <?php
          if ($svc==='dedication') {
            $kv('Child Name',        $row['full_name'] ?? null);
            $kv('Guardian',          $row['guardian_fullname'] ?? null);
            $kv('Guardian Contact',  $row['guardian_contact'] ?? null);
            $kvFile('Baptismal Certificate', $row['baptismal_cert_path'] ?? null);
            $kvFile('Parent Valid ID',       $row['parent_valid_id_path'] ?? null);
          } elseif ($svc==='funeral') {
            $kv('Deceased',       $row['full_name'] ?? null);
            $kv('Contact Person', $row['contact_person'] ?? null);
            $kv('Contact #',      $row['contact_number'] ?? null);
            $kv('Address',        $row['home_address'] ?? null);
          } elseif ($svc==='house') {
            $kv('Owner',      $row['full_name'] ?? null);
            $kv('Contact',    $row['contact_info'] ?? null);
            $kv('Address',    $row['home_address'] ?? null);
            $kv('Attendees',  $row['attendees_count'] ?? null);
          } elseif ($svc==='wedding') {
            $kv('Groom',      $row['groom_full_name'] ?? null);
            $kv('Bride',      $row['bride_full_name'] ?? null);
            $kv('Contact #',  $row['contact_number'] ?? null);
            $kvFile('Groom Birth Cert',        $row['groom_birth_cert_path'] ?? null);
            $kvFile('Groom Valid ID',          $row['groom_valid_id_path'] ?? null);
            $kvFile('Groom Baptismal Cert',    $row['groom_baptismal_cert_path'] ?? null);
            $kvFile('Bride Birth Cert',        $row['bride_birth_cert_path'] ?? null);
            $kvFile('Bride Valid ID',          $row['bride_valid_id_path'] ?? null);
            $kvFile('Bride Baptismal Cert',    $row['bride_baptismal_cert_path'] ?? null);
            $kvFile('Bride CENOMAR',           $row['bride_cenomar_path'] ?? null);
            $kvFile('Groom CENOMAR',           $row['groom_cenomar_path'] ?? null);
          }
        ?>
      </div>
    </div>

    <div class="modal-section">
      <div class="section-heading">Status</div>
      <div class="kv-group">
        <?php
          $status = $row['service_status'] ?? ($row['status'] ?? '—');
          echo '<div class="kv"><span>Current Status</span><b><span class="status-pill">'.h($status).'</span></b></div>';
        ?>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <div class="grow"></div>
    <button class="btn secondary" onclick="closeModal()">Close</button>
  </div>
  <?php
  echo ob_get_clean();
  exit;
}

/* ---------------- AJAX: update status to Done ---------------- */
if (isset($_POST['action']) && $_POST['action'] === 'updateStatus') {
  header('Content-Type: application/json');
  $svc = $_POST['svc'] ?? '';
  $id  = $_POST['id']  ?? '';
  $status = $_POST['status'] ?? '';
  if ($status !== 'Done') { echo json_encode(['ok'=>false,'msg'=>'Invalid status']); exit; }

  $map = [
    'dedication'=> ['table'=>'service_dedication','idcol'=>'dedicationId'],
    'funeral'   => ['table'=>'service_funeral',   'idcol'=>'funeral_id'],
    'house'     => ['table'=>'service_house',     'idcol'=>'house_id'],
    'wedding'   => ['table'=>'service_wedding',   'idcol'=>'wedding_id'],
  ];
  if (!isset($map[$svc])) { echo json_encode(['ok'=>false,'msg'=>'Unknown service']); exit; }

  $tbl  = $map[$svc]['table'];
  $idc  = $map[$svc]['idcol'];
  $hasUpdatedAt = table_has_column($db_connection, $tbl, 'updated_at');

  // Start transaction so status + civil_status update is atomic.
  @mysqli_begin_transaction($db_connection);

  try {
    // 1) Mark service as Done
    $sql  = $hasUpdatedAt
      ? "UPDATE {$tbl} SET service_status='Done', updated_at=NOW() WHERE {$idc}=? LIMIT 1"
      : "UPDATE {$tbl} SET service_status='Done' WHERE {$idc}=? LIMIT 1";

    $st = mysqli_prepare($db_connection, $sql);
    if (!$st) throw new Exception('Prepare failed: '.mysqli_error($db_connection));
    mysqli_stmt_bind_param($st, 's', $id);
    $ok = mysqli_stmt_execute($st);
    if (!$ok) throw new Exception('Execute failed: '.mysqli_error($db_connection));

    // 2) If Wedding: set civil_status='Married' for linked individual(s)
    if ($svc === 'wedding') {
      // Make sure individual_table has civil_status column (it should)
      if (table_has_column($db_connection, 'individual_table', 'civil_status')) {

        // Pull linked individual_id from the wedding record
        $q = mysqli_prepare($db_connection, "SELECT individual_id FROM service_wedding WHERE wedding_id=? LIMIT 1");
        if ($q) {
          mysqli_stmt_bind_param($q, 's', $id);
          mysqli_stmt_execute($q);
          $rs = mysqli_stmt_get_result($q);
          $wr = $rs ? mysqli_fetch_assoc($rs) : null;

          $linkedId = $wr['individual_id'] ?? null;
          if ($linkedId !== null && $linkedId !== '') {
            $u = mysqli_prepare($db_connection, "UPDATE individual_table SET civil_status='Married' WHERE individual_id=? LIMIT 1");
            if (!$u) throw new Exception('Prepare failed (civil_status): '.mysqli_error($db_connection));
            mysqli_stmt_bind_param($u, 's', $linkedId);
            $ok2 = mysqli_stmt_execute($u);
            if (!$ok2) throw new Exception('Execute failed (civil_status): '.mysqli_error($db_connection));
          }

          // OPTIONAL: if your service_wedding has groom_individual_id / bride_individual_id columns,
          // update them too (won't error if columns don't exist).
          $extraCols = ['groom_individual_id','bride_individual_id'];
          foreach ($extraCols as $col) {
            if (table_has_column($db_connection, 'service_wedding', $col)) {
              $q2 = mysqli_prepare($db_connection, "SELECT {$col} AS xid FROM service_wedding WHERE wedding_id=? LIMIT 1");
              if ($q2) {
                mysqli_stmt_bind_param($q2, 's', $id);
                mysqli_stmt_execute($q2);
                $rs2 = mysqli_stmt_get_result($q2);
                $wr2 = $rs2 ? mysqli_fetch_assoc($rs2) : null;
                $xid = $wr2['xid'] ?? null;
                if ($xid !== null && $xid !== '') {
                  $u2 = mysqli_prepare($db_connection, "UPDATE individual_table SET civil_status='Married' WHERE individual_id=? LIMIT 1");
                  if (!$u2) throw new Exception('Prepare failed (civil_status extra): '.mysqli_error($db_connection));
                  mysqli_stmt_bind_param($u2, 's', $xid);
                  $ok3 = mysqli_stmt_execute($u2);
                  if (!$ok3) throw new Exception('Execute failed (civil_status extra): '.mysqli_error($db_connection));
                }
              }
            }
          }
        }
      }
    }

    @mysqli_commit($db_connection);
    echo json_encode(['ok'=>true, 'newStatus'=>'Done']);
    exit;

  } catch (Throwable $e) {
    @mysqli_rollback($db_connection);
    echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    exit;
  }
}

/* ---------------- Page filters (UI state only) ---------------- */
$service = $_GET['service'] ?? 'all';
$sort    = $_GET['sort'] ?? 'new';

/* ---------------- UNION (ONLY Scheduled; house uses owner_* fields) ----- */
/* NOTE: we CONVERT all text columns to utf8mb4 in each SELECT to avoid
   "Illegal mix of collations" when doing UNION. */
$sqlApproved = "
SELECT * FROM (
  SELECT 
    CONVERT('dedication' USING utf8mb4) AS service_key,
    CONVERT('Dedication' USING utf8mb4) AS service_label,
    d.dedicationId AS service_id,
    COALESCE(d.created_at, d.service_date) AS requested_at,
    d.service_date,
    CONVERT(COALESCE(d.service_time, '') USING utf8mb4) AS service_time,
    CONVERT(COALESCE(i.individual_lastname, d.child_lastname, '') USING utf8mb4) AS last_name,
    CONVERT(COALESCE(i.individual_firstname, d.child_firstname, '') USING utf8mb4) AS first_name,
    CONVERT(COALESCE(i.individual_middlename, d.child_middlename,'') USING utf8mb4) AS middle_name,
    CONVERT(
      TRIM(CONCAT_WS(' ',
        COALESCE(i.individual_firstname, d.child_firstname,''),
        COALESCE(i.individual_middlename, d.child_middlename,''),
        COALESCE(i.individual_lastname, d.child_lastname,''),
        d.child_ext
      )) USING utf8mb4
    ) AS requester_name,
    CONVERT(COALESCE(i.individual_email_address, d.email_address, '') USING utf8mb4) AS requester_email
  FROM service_dedication d
  LEFT JOIN individual_table i ON i.individual_id=d.individual_id
  WHERE TRIM(COALESCE(d.service_status,''))='Scheduled'

  UNION ALL

  SELECT 
    CONVERT('funeral' USING utf8mb4) AS service_key,
    CONVERT('Funeral' USING utf8mb4) AS service_label,
    f.funeral_id AS service_id,
    COALESCE(f.created_at, f.service_date) AS requested_at,
    f.service_date,
    CONVERT(COALESCE(f.service_time, '') USING utf8mb4) AS service_time,
    CONVERT(COALESCE(i.individual_lastname, f.deceased_lastname, '') USING utf8mb4) AS last_name,
    CONVERT(COALESCE(i.individual_firstname, f.deceased_firstname, '') USING utf8mb4) AS first_name,
    CONVERT(COALESCE(i.individual_middlename, f.deceased_middlename,'') USING utf8mb4) AS middle_name,
    CONVERT(
      TRIM(CONCAT_WS(' ',
        COALESCE(i.individual_firstname, f.deceased_firstname,''),
        COALESCE(i.individual_middlename, f.deceased_middlename,''),
        COALESCE(i.individual_lastname, f.deceased_lastname,''),
        f.deceased_ext
      )) USING utf8mb4
    ) AS requester_name,
    CONVERT(COALESCE(i.individual_email_address, f.email_address, '') USING utf8mb4) AS requester_email
  FROM service_funeral f
  LEFT JOIN individual_table i ON i.individual_id=f.individual_id
  WHERE TRIM(COALESCE(f.service_status,''))='Scheduled'

  UNION ALL

  SELECT 
    CONVERT('house' USING utf8mb4) AS service_key,
    CONVERT('House Blessing' USING utf8mb4) AS service_label,
    h.house_id AS service_id,
    COALESCE(h.created_at, h.service_date) AS requested_at,
    h.service_date,
    CONVERT(COALESCE(h.service_time, '') USING utf8mb4) AS service_time,
    CONVERT(COALESCE(i.individual_lastname, h.owner_lastname, '') USING utf8mb4) AS last_name,
    CONVERT(COALESCE(i.individual_firstname, h.owner_firstname, '') USING utf8mb4) AS first_name,
    CONVERT(COALESCE(i.individual_middlename, h.owner_middlename,'') USING utf8mb4) AS middle_name,
    CONVERT(
      TRIM(CONCAT_WS(' ',
        COALESCE(i.individual_firstname, h.owner_firstname,''),
        COALESCE(i.individual_middlename, h.owner_middlename,''),
        COALESCE(i.individual_lastname, h.owner_lastname,''),
        h.owner_ext
      )) USING utf8mb4
    ) AS requester_name,
    CONVERT(COALESCE(i.individual_email_address, h.email_address, '') USING utf8mb4) AS requester_email
  FROM service_house h
  LEFT JOIN individual_table i ON i.individual_id=h.individual_id
  WHERE TRIM(COALESCE(h.service_status,''))='Scheduled'

  UNION ALL

  SELECT 
    CONVERT('wedding' USING utf8mb4) AS service_key,
    CONVERT('Wedding' USING utf8mb4) AS service_label,
    w.wedding_id AS service_id,
    COALESCE(w.created_at, w.service_date) AS requested_at,
    w.service_date,
    CONVERT(COALESCE(w.service_time, '') USING utf8mb4) AS service_time,
    CONVERT(
      COALESCE(
        i.individual_lastname,
        CONCAT_WS(' & ',
          TRIM(CONCAT_WS(' ', w.groom_firstname, w.groom_middlename, w.groom_lastname, w.groom_extension)),
          TRIM(CONCAT_WS(' ', w.bride_firstname, w.bride_middlename, w.bride_lastname, w.bride_extension))
        ),
        ''
      ) USING utf8mb4
    ) AS last_name,
    CONVERT(COALESCE(i.individual_firstname, '') USING utf8mb4) AS first_name,
    CONVERT(COALESCE(i.individual_middlename,'') USING utf8mb4) AS middle_name,
    CONVERT(
      TRIM(CONCAT_WS(' ',
        COALESCE(i.individual_firstname,''),
        COALESCE(i.individual_middlename,''),
        COALESCE(i.individual_lastname,
          CONCAT_WS(' & ',
            TRIM(CONCAT_WS(' ', w.groom_firstname, w.groom_middlename, w.groom_lastname, w.groom_extension)),
            TRIM(CONCAT_WS(' ', w.bride_firstname, w.bride_middlename, w.bride_lastname, w.bride_extension))
          ), ''
        )
      )) USING utf8mb4
    ) AS requester_name,
    CONVERT(COALESCE(i.individual_email_address, w.email_address, '') USING utf8mb4) AS requester_email
  FROM service_wedding w
  LEFT JOIN individual_table i ON i.individual_id=w.individual_id
  WHERE TRIM(COALESCE(w.service_status,''))='Scheduled'
) t
ORDER BY t.requested_at DESC
";

$resApproved = mysqli_query($db_connection, $sqlApproved);

$self_path = $_SERVER['PHP_SELF'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Admin – Approved Schedule</title>

  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/admin-schedule-request.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/admin-schedule-request.css'); ?>">
  <style>
    button, .btn, .modal-close, .imgbox-close, .top-actions .cta, .toast .t-close {
      font-family: Arial, sans-serif !important;
    }
    .top-actions .cta {
      display:inline-flex;align-items:center;gap:8px;
      background:#1B1B4B;color:#fff;border:0;border-radius:10px;
      padding:8px 12px;font-weight:600; cursor:pointer; text-decoration:none;
    }
    .btn-row { display:flex; gap:6px; justify-content:flex-end; }
    .btn.tiny.ghost i{ font-style:normal; }
    .btn.done { background:#3b82f6; color:#fff; }
  </style>
</head>
<body data-endpoint="<?php echo htmlspecialchars($self_path, ENT_QUOTES, 'UTF-8'); ?>">

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
      <a class="navlink" href="secretary_dashboard.php"><i class="fas fa-home"></i>Dashboard</a>
      <div class="section-title">Online Requests</div>
      <a class="navlink" href="admin-schedule-request.php"><i class="fas fa-calendar-plus"></i>Schedule Requests</a>
      <a class="navlink" href="admin-prayer-request.php"><i class="fas fa-praying-hands"></i><span>Prayer Requests</span></a>
      <div class="section-title">Online Applications</div>
      <a class="navlink" href="baptismal_application.php"><i class="fas fa-water"></i>Baptismal Applications</a>
      <a class="navlink" href="admin-application.php"><i class="fas fa-user-cog"></i>Baptismal Account Verification</a>
      <a class="navlink" href="application_ministry.php"><i class="fas fa-users"></i>Ministry Applications</a>
      <div class="section-title">Schedule</div>
      <a class="navlink active" href="appointment-schedule.php"><i class="fas fa-calendar-check"></i>Service Schedule</a>
      <div class="section-title">All Done Services</div>
      <a class="navlink" href="done-service-wedding.php"><i class="fas fa-ring"></i>Wedding Service</a>
      <a class="navlink" href="done-service-dedication.php"><i class="fas fa-baby"></i>Child Dedication</a>
      <a class="navlink" href="done-service-funeral.php"><i class="fas fa-cross"></i>Funeral Service</a>
      <a class="navlink" href="done-service-house.php"><i class="fas fa-home"></i>House Blessing</a>
      <a class="navlink" href="done-service-baptism.php"><i class="fas fa-tint"></i>Water Baptism</a>
      <div class="section-title">Streaming</div>
      <a class="navlink" href="admin-multimedia.php"><i class="fas fa-broadcast-tower"></i>Streaming</a>
       <div class="section-title">Individual Management</div>
      <a class="navlink" href="admin-individual_list.php"><i class="fas fa-user"></i>Individual List</a>
      <div class="section-title">Ministry Management</div>
      <a class="navlink" href="admin-ministry-women.php"><i class="fas fa-female"></i>Handmaid of the Lord</a>
      <a class="navlink" href="admin-ministry-men.php"><i class="fas fa-male"></i>Men Ministry</a>
      <a class="navlink" href="admin-ministry-music.php"><i class="fas fa-music"></i>Music Ministry</a>
      <a class="navlink" href="admin-ministry-usher.php"><i class="fas fa-hands-helping"></i>Usher &amp; Usherette</a>
      <div class="section-title">Reports</div>
      <a class="navlink" href="admin-reports.php"><i class="fas fa-file-alt"></i>Reports</a>
      <div class="section-title">Content</div>
      <a class="navlink" href="content-management_home-page.php"><i class="fas fa-edit"></i>Content Management</a>
      <div class="section-title">Certificates</div>
      <a class="navlink" href="certificate-table.php"><i class="fas fa-award"></i>Generate Certificate</a>
      <div class="section-title">Account</div>
      <a class="navlink" href="admin-account-settings.php"><i class="fas fa-user-shield"></i>Account Settings</a>
      <div class="section-title">More</div>
      <a class="navlink logout" href="all_log-in.php"><i class="fas fa-sign-out-alt"></i>Log Out</a>
    </nav>
  </aside>

<!-- PAGE -->
<div class="page">
  <header class="topbar">
    <h1>SCHEDULED SERVICE</h1>
    <div class="top-actions" style="gap:10px; display:flex; align-items:center;">
      <div class="search">
        <i class="fas fa-search"></i>
        <input id="searchInput" type="text" placeholder="Search…">
      </div>
      <div class="filters">
        <label><span>Service</span>
          <select id="svcFilter">
            <option value="all">All</option>
            <option value="dedication">Dedication</option>
            <option value="funeral">Funeral</option>
            <option value="house">House Blessing</option>
            <option value="wedding">Wedding</option>
          </select>
        </label>
        <label><span>Sort</span>
          <select id="sortFilter">
            <option value="new" selected>New → Old</option>
            <option value="old">Old → New</option>
            <option value="lname_az">Last name A → Z</option>
            <option value="lname_za">Last name Z → A</option>
          </select>
        </label>
      </div>

      <a class="cta" href="onsite_appointment.php"><i class="fas fa-calendar-check"></i> Book a Service</a>
    </div>
  </header>

  <section class="panel">
    <div class="table-wrap">
      <table id="apptTable">
        <thead>
          <tr>
            <th>Date Requested</th>
            <th>Event Type</th>
            <th>Last Name</th>
            <th>First Name</th>
            <th>Middle Name</th>
            <th>Individual's Email Address</th>
            <th>Service Date &amp; Time</th>
            <th class="text-right">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php
          if ($resApproved && mysqli_num_rows($resApproved)>0):
            while ($r = mysqli_fetch_assoc($resApproved)):
              $reqDate = !empty($r['requested_at']) ? date('M d, Y', strtotime($r['requested_at'])) : '—';
              $svcDate = !empty($r['service_date']) ? date('M d, Y', strtotime($r['service_date'])) : '—';
              $svcTime = !empty($r['service_time']) ? $r['service_time'] : '';
              $when    = trim($svcDate.' '.($svcTime ? '• '.$svcTime : ''));
              $reqTs   = !empty($r['requested_at']) ? strtotime($r['requested_at']) : 0;
          ?>
          <tr
            data-service="<?=h($r['service_key'])?>"
            data-id="<?=h($r['service_id'])?>"
            data-requested="<?=$reqTs?>"
            data-last="<?=h($r['last_name'] ?? '')?>"
            data-first="<?=h($r['first_name'] ?? '')?>"
            data-middle="<?=h($r['middle_name'] ?? '')?>"
          >
            <td><?=h($reqDate)?></td>
            <td><?=h($r['service_label'])?></td>
            <td><?=h($r['last_name'] ?? '')?></td>
            <td><?=h($r['first_name'] ?? '')?></td>
            <td><?=h($r['middle_name'] ?? '')?></td>
            <td><?=h($r['requester_email'])?></td>
            <td><?=h($when)?></td>
            <td class="text-right">
              <div class="btn-row">
                <button class="btn tiny ghost btn-view"   title="View"><i class="fas fa-eye"> VIEW</i></button>
                <button class="btn tiny done  btn-done"   title="Done"><i class="fas fa-check"> DONE</i></button>
              </div>
            </td>
          </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="8" class="empty">No approved appointments.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<!-- Modal -->
<div id="modal" class="modal" aria-hidden="true">
  <div class="modal-dialog">
    <div id="modalContent" class="modal-content">Loading…</div>
  </div>
</div>

<!-- Image Lightbox -->
<div id="imgBox" class="imgbox" aria-hidden="true">
  <div class="imgbox-inner">
    <button class="imgbox-close" onclick="closeImgBox()">×</button>
    <img id="imgBoxImg" alt="Document image">
  </div>
</div>

<!-- Toast utility -->
<script>
(function(){
  function ensureWrap(){
    let w = document.querySelector('.toast-wrap');
    if(!w){ w = document.createElement('div'); w.className = 'toast-wrap'; document.body.appendChild(w); }
    return w;
  }
  window.toast = function(message, type='info', opts={}){
    const {duration=3500} = opts;
    const wrap = ensureWrap();
    while (wrap.children.length >= 5) wrap.firstChild.remove();

    const el = document.createElement('div');
    el.className = 'toast ' + (type||'info');

    const icon = document.createElement('div');
    icon.className = 't-icon';
    icon.innerHTML = ({success:'✅', error:'⛔', warn:'⚠️', info:'ℹ️'})[type] || 'ℹ️';

    const body = document.createElement('div');
    body.className = 't-body';
    body.textContent = String(message || '');

    const close = document.createElement('button');
    close.className = 't-close'; close.type = 'button'; close.setAttribute('aria-label','Close'); close.innerHTML = '&times;';
    close.onclick = () => dismiss();

    el.appendChild(icon); el.appendChild(body); el.appendChild(close);
    wrap.appendChild(el);

    let timer = null;
    function dismiss(){ el.style.animation = 'toast-out .15s ease forwards'; clearTimeout(timer); setTimeout(()=> el.remove(), 180); }
    if (duration > 0){ timer = setTimeout(dismiss, duration); el.addEventListener('mouseenter', ()=> clearTimeout(timer)); el.addEventListener('mouseleave', ()=> timer = setTimeout(dismiss, 1200)); }
    return {dismiss, el};
  };
})();
</script>

<script>
/* ===== Filters, sort, search ===== */
const searchInput = document.getElementById('searchInput');
const svcFilter   = document.getElementById('svcFilter');
const sortFilter  = document.getElementById('sortFilter');
const tbody       = document.querySelector('#apptTable tbody');
const allRows     = [...tbody.querySelectorAll('tr')];

function sortVisibleRows(mode){
  const dir = mode || sortFilter.value;
  const visible = allRows.filter(r => r.style.display !== 'none');
  const collator = new Intl.Collator(undefined, { sensitivity: 'base', numeric: true });

  function byRequested(a, b) {
    const ta = parseInt(a.dataset.requested || '0', 10);
    const tb = parseInt(b.dataset.requested || '0', 10);
    return ta - tb; // ascending
  }
  function byName(a, b) {
    const la = (a.dataset.last || ''), lb = (b.dataset.last || '');
    const lf = collator.compare(la, lb);
    if (lf !== 0) return lf;
    const fa = (a.dataset.first || ''), fb = (b.dataset.first || '');
    const ff = collator.compare(fa, fb);
    if (ff !== 0) return ff;
    const ma = (a.dataset.middle || ''), mb = (b.dataset.middle || '');
    const mf = collator.compare(ma, mb);
    if (mf !== 0) return mf;
    return parseInt(b.dataset.requested || '0', 10) - parseInt(a.dataset.requested || '0', 10);
  }

  visible.sort((a, b) => {
    switch (dir) {
      case 'old':       return byRequested(a, b);
      case 'new':       return byRequested(b, a);
      case 'lname_az':  return byName(a, b);
      case 'lname_za':  return byName(b, a);
      default:          return byRequested(b, a);
    }
  });
  visible.forEach(r => tbody.appendChild(r));
}

function applyFilters() {
  const q   = (searchInput.value || '').toLowerCase();
  const svc = svcFilter.value;

  allRows.forEach(row => {
    const matchSvc = (svc === 'all') || (row.dataset.service === svc);
    const text     = row.innerText.toLowerCase();
    const match    = text.includes(q);
    row.style.display = (matchSvc && match) ? '' : 'none';
  });
  sortVisibleRows(sortFilter.value);
}
searchInput.addEventListener('input', applyFilters);
svcFilter.addEventListener('change', applyFilters);
sortFilter.addEventListener('change', applyFilters);
applyFilters();

/* ===== View + Done actions ===== */

const ENDPOINT = document.body.dataset.endpoint || location.pathname;

function openModal(){ document.getElementById('modal').setAttribute('aria-hidden','false'); }
function closeModal(){ document.getElementById('modal').setAttribute('aria-hidden','true'); }
window.closeModal = closeModal;

function viewDetails(svc, id) {
  openModal();
  const box = document.getElementById('modalContent');
  box.innerHTML = 'Loading…';
  const url = `${ENDPOINT}?view=1&svc=${encodeURIComponent(svc)}&id=${encodeURIComponent(id)}`;

  fetch(url)
    .then(async r => {
      if (!r.ok) {
        const raw = await r.text().catch(()=> '');
        throw new Error(`HTTP ${r.status} ${r.statusText} – ${raw.slice(0,200)}`);
      }
      return r.text();
    })
    .then(html => {
      box.innerHTML = html;
    })
    .catch(err => {
      box.innerHTML = '<div class="modal-body">Failed to load.</div>';
      toast('Failed to load details. ' + err.message, 'error', {duration: 6000});
    });
}

function markDone(svc, id, row){
  const fd = new FormData();
  fd.append('action','updateStatus');
  fd.append('svc', svc);
  fd.append('id', id);
  fd.append('status','Done');

  fetch(ENDPOINT, { method:'POST', body: fd })
    .then(async r => {
      const raw = await r.text();
      if (!r.ok) throw new Error(`HTTP ${r.status} ${r.statusText}: ${raw.slice(0,300)}`);
      let j = null; try { j = JSON.parse(raw); } catch(e){ throw new Error('Server error: ' + raw.slice(0,300)); }
      if (!j.ok) throw new Error(j.msg || 'Unknown error');
      toast('Marked as Done', 'success', {duration:2000});
      row.remove();
    })
    .catch(e => toast('Update failed: ' + e.message, 'error', {duration:6000}));
}

/* Delegated clicks for View/Done */
document.addEventListener('click', (e) => {
  const viewBtn = e.target.closest('.btn-view');
  if (viewBtn){
    const tr = viewBtn.closest('tr');
    viewDetails(tr.dataset.service, tr.dataset.id);
    return;
  }
  const doneBtn = e.target.closest('.btn-done');
  if (doneBtn){
    const tr = doneBtn.closest('tr');
    if (!tr) return;
    if (confirm('Mark this appointment as Done?')){
      markDone(tr.dataset.service, tr.dataset.id, tr);
    }
  }
});

/* ===== Image lightbox ===== */
document.addEventListener('click', (e) => {
  const btn = e.target.closest('.view-image[data-img]');
  if (!btn) return;
  e.preventDefault();
  openImgBox(btn.getAttribute('data-img'));
});
function openImgBox(src){
  const box = document.getElementById('imgBox');
  const img = document.getElementById('imgBoxImg');
  img.src = src;
  box.setAttribute('aria-hidden','false');
}
function closeImgBox(){
  const box = document.getElementById('imgBox');
  const img = document.getElementById('imgBoxImg');
  img.src = '';
  box.setAttribute('aria-hidden','true');
}

/* ===== Scroll height cap (10 rows) ===== */
function setScrollableLimit() {
  const wrap  = document.querySelector('.table-wrap');
  const table = document.getElementById('apptTable');
  if (!wrap || !table || !table.tBodies[0]) return;
  const headerRow = table.tHead ? table.tHead.rows[0] : null;
  const firstVisible = [...table.tBodies[0].rows].find(r => r.style.display !== 'none');
  if (!headerRow || !firstVisible) return;
  const headH = Math.ceil(headerRow.getBoundingClientRect().height || 44);
  const rowH  = Math.ceil(firstVisible.getBoundingClientRect().height || 44);
  const maxH  = headH + (rowH * 10);
  wrap.style.maxHeight = maxH + 'px';
}
window.addEventListener('load', setScrollableLimit);
window.addEventListener('resize', setScrollableLimit);
['input','change'].forEach(evt=>{
  document.getElementById('searchInput').addEventListener(evt, setScrollableLimit);
  document.getElementById('svcFilter').addEventListener(evt, setScrollableLimit);
  document.getElementById('sortFilter').addEventListener(evt, setScrollableLimit);
});
setScrollableLimit();
</script>

<style>
/* ===== Modal base – light UI like your screenshot ===== */
.modal{
  position:fixed;
  inset:0;
  display:none;
  align-items:center;
  justify-content:center;
  background:rgba(0,0,0,.45);
  z-index:2000;
  padding:16px;
}
.modal[aria-hidden="false"]{display:flex;}
.modal-dialog{
  max-width:min(960px,96vw);
  width:100%;
}
.modal-content{
  background:#ffffff;
  color:#111827;
  border-radius:6px;
  overflow:hidden;
  border:1px solid #e5e7eb;
  max-height:80vh;
  display:flex;
  flex-direction:column;
}

/* Header & footer */
.modal-header{
  background:#191b58;
  color:#ffffff;
  padding:14px 24px;
  display:flex;
  align-items:center;
}
.modal-header h3{
  margin:0;
  font-size:20px;
  font-weight:600;
}
.modal-close{
  margin-left:auto;
  background:transparent;
  border:0;
  color:#e5e7eb;
  font-size:20px;
  cursor:pointer;
}
.modal-close:hover{color:#ffffff;}

.modal-body{
  padding:18px 28px 20px;
  background:#ffffff;
  overflow-y:auto;
}
.modal-footer{
  padding:12px 24px;
  background:#f9fafb;
  border-top:1px solid #e5e7eb;
  display:flex;
  align-items:center;
}
.modal-footer .grow{flex:1;}

/* Sections */
.modal-section{
  margin-bottom:16px;
}
.section-heading{
  font-size:15px;
  font-weight:600;
  color:#111827;
  margin-bottom:10px;
  padding-left:10px;
  border-left:4px solid #1e40af;
}

/* Key/value rows */
.kv-group{
  border-top:1px solid #f3f4f6;
}
.kv{
  display:flex;
  align-items:flex-start;
  padding:6px 0;
  border-bottom:1px solid #f3f4f6;
}
.kv span{
  width:190px;
  min-width:150px;
  font-size:14px;
  color:#374151;
}
.kv span::after{
  content:':';
  margin-left:2px;
}
.kv b{
  flex:1;
  font-size:14px;
  font-weight:500;
  color:#111827;
}
.kv .btn,
.kv .link{
  flex:0;
}

/* Status pill */
.status-pill{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:2px 10px;
  border-radius:999px;
  font-size:11px;
  text-transform:uppercase;
  letter-spacing:.08em;
  background:#dcfce7;
  color:#15803d;
  border:1px solid #bbf7d0;
}

/* Buttons */
.btn{
  border-radius:999px;
  border:1px solid transparent;
  padding:6px 12px;
  cursor:pointer;
  font-size:13px;
}
.btn.secondary{
  background:#ffffff;
  color:#111827;
  border-color:#d1d5db;
}
.btn.secondary:hover{
  background:#f3f4f6;
}
.btn.tiny{
  font-size:12px;
  padding:4px 10px;
}
.btn.link{
  background:transparent;
  border:0;
  padding:0;
  color:#1d4ed8;
  text-decoration:underline;
  border-radius:0;
}

/* Toast */
.toast-wrap{
  position:fixed;
  right:10px;
  top:10px;
  z-index:10000;
  display:flex;
  flex-direction:column;
  gap:8px;
}
.toast{
  display:flex;
  align-items:center;
  gap:8px;
  background:#111827;
  color:#f9fafb;
  border-radius:8px;
  padding:8px 10px;
  max-width:480px;
  box-shadow:0 10px 30px rgba(0,0,0,.4);
}
.toast .t-body{font-size:13px;}
.toast .t-close{
  margin-left:auto;
  background:transparent;
  border:0;
  color:#e5e7eb;
  font-size:18px;
  cursor:pointer;
}

/* Image lightbox */
.imgbox{
  position:fixed;
  inset:0;
  display:none;
  align-items:center;
  justify-content:center;
  background:rgba(0,0,0,.6);
  z-index:3000;
  padding:16px;
}
.imgbox[aria-hidden="false"]{display:flex;}
.imgbox-inner{
  position:relative;
  max-width:min(1000px, 96vw);
  max-height:90vh;
  background:#ffffff;
  border-radius:8px;
  box-shadow:0 30px 80px rgba(0,0,0,.6);
  overflow:hidden;
}
.imgbox-inner img{
  display:block;
  max-width:100%;
  max-height:90vh;
}
.imgbox-close{
  position:absolute;
  top:8px;
  right:8px;
  border:0;
  background:#111827;
  color:#ffffff;
  border-radius:999px;
  padding:6px 10px;
  cursor:pointer;
  z-index:2;
}

/* Misc */
.text-right{text-align:right;}

@media (max-width: 640px){
  .modal-dialog{max-width:100%;}

  .modal-body{
    padding:14px 16px 16px;
  }
  .kv{
    flex-direction:column;
  }
  .kv span{
    width:auto;
    margin-bottom:2px;
  }
}
</style>
</body>
</html>
