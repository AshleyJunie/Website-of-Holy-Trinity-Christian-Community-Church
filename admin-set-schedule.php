BEGIN FILE: admin-set-schedule.php
<?php
include 'db-connection.php';

/* ===== ADDED (match reference page behavior): charset + collation ===== */
@mysqli_set_charset($db_connection, 'utf8mb4');
@mysqli_query($db_connection, "SET collation_connection = 'utf8mb4_general_ci'");
/* ===================================================================== */

/* ========== SAFE JSON OUTPUT HANDLER (prevents HTML in JSON) ========== */
function jexit($arr){
  if (ob_get_length()) ob_clean();
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr);
  exit;
}
$is_ajax = isset($_GET['ajax']) || isset($_POST['ajax']);
if ($is_ajax) {
  ini_set('display_errors','0');
  set_error_handler(function($no,$str,$file,$line){
    jexit(['ok'=>false,'msg'=>"PHP error: $str in $file:$line"]);
  });
  register_shutdown_function(function(){
    $e = error_get_last();
    if ($e && in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])){
      jexit(['ok'=>false,'msg'=>"Fatal: {$e['message']} in {$e['file']}:{$e['line']}"]);
    }
  });
}

/* ---------- AJAX: unified calendar data (badges + day info) from ALL tables ---------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'getSchedules') {
  // We return rows: schedule_date, schedule_time, schedule_event, schedule_membername, schedule_status
  $sql = "
    SELECT service_date,
           CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci AS schedule_time,
           'Baptism' COLLATE utf8mb4_general_ci AS schedule_event,
           baptized_name COLLATE utf8mb4_general_ci AS schedule_membername,
           COALESCE(service_status, status) COLLATE utf8mb4_general_ci AS schedule_status
    FROM service_baptism
    WHERE service_date IS NOT NULL

    UNION ALL
    SELECT service_date,
           CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci,
           'Child Dedication' COLLATE utf8mb4_general_ci,
           child_full_name COLLATE utf8mb4_general_ci,
           COALESCE(service_status, status) COLLATE utf8mb4_general_ci
    FROM service_dedication
    WHERE service_date IS NOT NULL

    UNION ALL
    SELECT service_date,
           CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci,
           'Funeral Service' COLLATE utf8mb4_general_ci,
           deceased_name COLLATE utf8mb4_general_ci,
           COALESCE(service_status, status) COLLATE utf8mb4_general_ci
    FROM service_funeral
    WHERE service_date IS NOT NULL

    UNION ALL
    SELECT service_date,
           CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci,
           'House Blessing' COLLATE utf8mb4_general_ci,
           owner_full_name COLLATE utf8mb4_general_ci,
           COALESCE(service_status, status) COLLATE utf8mb4_general_ci
    FROM service_house
    WHERE service_date IS NOT NULL

    UNION ALL
    SELECT service_date,
           CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci,
           'Wedding' COLLATE utf8mb4_general_ci,
           CONCAT(groom_name,' & ',bride_name) COLLATE utf8mb4_general_ci,
           COALESCE(service_status, status) COLLATE utf8mb4_general_ci
    FROM service_wedding
    WHERE service_date IS NOT NULL

    ORDER BY service_date ASC, schedule_time ASC
  ";
  $rows = [];
  if ($res = mysqli_query($db_connection, $sql)) {
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
  }
  jexit($rows);
}

/* ============================== ADD BELOW THIS LINE (NEW) ==============================
   NEW AJAX: getSchedulesV2 — shows ACCOUNT full name from individual_table (via individual_id)
   Format used: "Lastname, Firstname Middlename"
   ====================================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'getSchedulesV2') {
  $sql = "
    SELECT b.service_date,
           CAST(b.service_time AS CHAR) COLLATE utf8mb4_general_ci AS schedule_time,
           'Baptism' COLLATE utf8mb4_general_ci AS schedule_event,
           CAST(TRIM(CONCAT_WS(' ',
                 CONCAT(COALESCE(i.individual_lastname,''), ','),
                 COALESCE(i.individual_firstname,''),
                 NULLIF(i.individual_middlename,'')
               )) AS CHAR) COLLATE utf8mb4_general_ci AS schedule_membername,
           CAST(COALESCE(b.service_status, b.status) AS CHAR) COLLATE utf8mb4_general_ci AS schedule_status
    FROM service_baptism b
    LEFT JOIN individual_table i ON i.individual_id = b.individual_id
    WHERE b.service_date IS NOT NULL

    UNION ALL
    SELECT d.service_date,
           CAST(d.service_time AS CHAR) COLLATE utf8mb4_general_ci,
           'Child Dedication' COLLATE utf8mb4_general_ci,
           CAST(TRIM(CONCAT_WS(' ',
                 CONCAT(COALESCE(i.individual_lastname,''), ','),
                 COALESCE(i.individual_firstname,''),
                 NULLIF(i.individual_middlename,'')
               )) AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(COALESCE(d.service_status, d.status) AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_dedication d
    LEFT JOIN individual_table i ON i.individual_id = d.individual_id
    WHERE d.service_date IS NOT NULL

    UNION ALL
    SELECT f.service_date,
           CAST(f.service_time AS CHAR) COLLATE utf8mb4_general_ci,
           'Funeral Service' COLLATE utf8mb4_general_ci,
           CAST(TRIM(CONCAT_WS(' ',
                 CONCAT(COALESCE(i.individual_lastname,''), ','),
                 COALESCE(i.individual_firstname,''),
                 NULLIF(i.individual_middlename,'')
               )) AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(COALESCE(f.service_status, f.status) AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_funeral f
    LEFT JOIN individual_table i ON i.individual_id = f.individual_id
    WHERE f.service_date IS NOT NULL

    UNION ALL
    SELECT h.service_date,
           CAST(h.service_time AS CHAR) COLLATE utf8mb4_general_ci,
           'House Blessing' COLLATE utf8mb4_general_ci,
           CAST(TRIM(CONCAT_WS(' ',
                 CONCAT(COALESCE(i.individual_lastname,''), ','),
                 COALESCE(i.individual_firstname,''),
                 NULLIF(i.individual_middlename,'')
               )) AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(COALESCE(h.service_status, h.status) AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_house h
    LEFT JOIN individual_table i ON i.individual_id = h.individual_id
    WHERE h.service_date IS NOT NULL

    UNION ALL
    SELECT w.service_date,
           CAST(w.service_time AS CHAR) COLLATE utf8mb4_general_ci,
           'Wedding' COLLATE utf8mb4_general_ci,
           CAST(TRIM(CONCAT_WS(' ',
                 CONCAT(COALESCE(i.individual_lastname,''), ','),
                 COALESCE(i.individual_firstname,''),
                 NULLIF(i.individual_middlename,'')
               )) AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(COALESCE(w.service_status, w.status) AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_wedding w
    LEFT JOIN individual_table i ON i.individual_id = w.individual_id
    WHERE w.service_date IS NOT NULL

    ORDER BY service_date ASC, schedule_time ASC
  ";

  $rows = [];
  if ($res = mysqli_query($db_connection, $sql)) {
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
  } else {
    jexit(['ok'=>false,'msg'=>mysqli_error($db_connection)]);
  }
  jexit(['ok'=>true,'rows'=>$rows]);
}
/* ============================ END ADD (getSchedulesV2) ============================ */

/* ---------- AJAX: approved list (original) across all service tables ---------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'getApproved') {
  $sql = "
    SELECT 'baptism' AS src,
           CAST(baptism_id AS UNSIGNED) AS pk,
           CAST(baptized_name AS CHAR) COLLATE utf8mb4_general_ci AS display_name,
           CAST('BAPTISM' AS CHAR) COLLATE utf8mb4_general_ci AS service_label,
           CAST(service_date AS CHAR) COLLATE utf8mb4_general_ci AS sdate,
           CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci AS stime
    FROM service_baptism
    WHERE status='Done'

    UNION ALL
    SELECT 'dedication',
           CAST(dedicationId AS UNSIGNED),
           CAST(child_full_name AS CHAR) COLLATE utf8mb4_general_ci,
           CAST('CHILD DEDICATION' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_dedication
    WHERE status='Done'

    UNION ALL
    SELECT 'funeral',
           CAST(funeral_id AS UNSIGNED),
           CAST(deceased_name AS CHAR) COLLATE utf8mb4_general_ci,
           CAST('FUNERAL SERVICE' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_funeral
    WHERE status='Done'

    UNION ALL
    SELECT 'house',
           CAST(appointment_id AS UNSIGNED),
           CAST(owner_full_name AS CHAR) COLLATE utf8mb4_general_ci,
           CAST('HOUSE BLESSING' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_house
    WHERE status='Done'

    UNION ALL
    SELECT 'wedding',
           CAST(wedding_id AS UNSIGNED),
           CAST(CONCAT(groom_name,' & ',bride_name) AS CHAR) COLLATE utf8mb4_general_ci,
           CAST('WEDDING' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_wedding
    WHERE status='Done'
    ORDER BY display_name ASC
  ";

  $rows = [];
  if ($res = mysqli_query($db_connection, $sql)) {
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
  } else {
    jexit(['ok'=>false,'msg'=>mysqli_error($db_connection)]);
  }
  jexit(['ok'=>true,'rows'=>$rows]);
}

/* ---------- AJAX: schedule selected approved record (update service_* only) ---------- */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'scheduleApproved') {
  $src  = $_POST['src'] ?? '';
  $id   = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  $date = $_POST['date'] ?? '';
  $time = $_POST['time'] ?? '';
  if (!$src || !$id || !$date || !$time) {
    jexit(['ok'=>false,'msg'=>'Missing fields']);
  }

  $ok = false;
  switch ($src) {
    case 'baptism':
      $stmt = mysqli_prepare($db_connection,
        "UPDATE service_baptism SET service_date=?, service_time=?, service_status='Scheduled' WHERE baptism_id=?");
      mysqli_stmt_bind_param($stmt, "ssi", $date, $time, $id);
      $ok = mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
      break;

    case 'dedication':
      $stmt = mysqli_prepare($db_connection,
        "UPDATE service_dedication SET service_date=?, service_time=?, service_status='Scheduled' WHERE dedicationId=?");
      mysqli_stmt_bind_param($stmt, "ssi", $date, $time, $id);
      $ok = mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
      break;

    case 'funeral':
      $stmt = mysqli_prepare($db_connection,
        "UPDATE service_funeral SET service_date=?, service_time=?, service_status='Scheduled' WHERE funeral_id=?");
      mysqli_stmt_bind_param($stmt, "ssi", $date, $time, $id);
      $ok = mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
      break;

    case 'house':
      $stmt = mysqli_prepare($db_connection,
        "UPDATE service_house SET service_date=?, service_time=?, service_status='Scheduled' WHERE appointment_id=?");
      mysqli_stmt_bind_param($stmt, "ssi", $date, $time, $id);
      $ok = mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
      break;

    case 'wedding':
      $stmt = mysqli_prepare($db_connection,
        "UPDATE service_wedding SET service_date=?, service_time=?, service_status='Scheduled' WHERE wedding_id=?");
      mysqli_stmt_bind_param($stmt, "ssi", $date, $time, $id);
      $ok = mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
      break;
  }

  jexit(['ok'=>$ok]);
}

/* ---------- AJAX: (optional) direct add — not supported without a source ---------- */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'setSchedule') {
  jexit(['ok'=>false,'msg'=>'Please pick from Approved Requests first.']);
}

/* ===============================================================
   V2: requesters from record fields (kept for compatibility)
   =============================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'getApprovedV2') {
  $sql = "
    SELECT 'baptism' AS src,
           CAST(baptism_id AS UNSIGNED) AS pk,
           CAST(
             COALESCE(
               NULLIF(TRIM(CONCAT_WS(' ', individual_firstname, individual_middlename, individual_lastname)), ''),
               baptized_name
             ) AS CHAR
           ) COLLATE utf8mb4_general_ci AS display_name,
           CAST('BAPTISM' AS CHAR) COLLATE utf8mb4_general_ci AS service_label,
           CAST(service_date AS CHAR) COLLATE utf8mb4_general_ci AS sdate,
           CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci AS stime
    FROM service_baptism
    WHERE status='Done'

    UNION ALL
    SELECT 'dedication',
           CAST(dedicationId AS UNSIGNED),
           CAST(
             COALESCE(
               NULLIF(TRIM(CONCAT_WS(' ', individual_firstname, individual_middlename, individual_lastname)), ''),
               child_full_name
             ) AS CHAR
           ) COLLATE utf8mb4_general_ci,
           CAST('CHILD DEDICATION' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_dedication
    WHERE status='Done'

    UNION ALL
    SELECT 'funeral',
           CAST(funeral_id AS UNSIGNED),
           CAST(
             COALESCE(
               NULLIF(TRIM(CONCAT_WS(' ', individual_firstname, individual_middlename, individual_lastname)), ''),
               deceased_name
             ) AS CHAR
           ) COLLATE utf8mb4_general_ci,
           CAST('FUNERAL SERVICE' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_funeral
    WHERE status='Done'

    UNION ALL
    SELECT 'house',
           CAST(appointment_id AS UNSIGNED),
           CAST(
             COALESCE(
               NULLIF(TRIM(CONCAT_WS(' ', individual_firstname, individual_middlename, individual_lastname)), ''),
               owner_full_name
             ) AS CHAR
           ) COLLATE utf8mb4_general_ci,
           CAST('HOUSE BLESSING' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_house
    WHERE status='Done'

    UNION ALL
    SELECT 'wedding',
           CAST(wedding_id AS UNSIGNED),
           CAST(
             COALESCE(
               NULLIF(TRIM(CONCAT_WS(' ', individual_firstname, individual_middlename, individual_lastname)), ''),
               CONCAT(groom_name,' & ',bride_name)
             ) AS CHAR
           ) COLLATE utf8mb4_general_ci,
           CAST('WEDDING' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_wedding
    WHERE status='Done'
    ORDER BY display_name ASC
  ";
  $rows = [];
  if ($res = mysqli_query($db_connection, $sql)) {
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
  } else {
    jexit(['ok'=>false,'msg'=>mysqli_error($db_connection)]);
  }
  jexit(['ok'=>true,'rows'=>$rows]);
}

/* ===============================================================
   V3: use individual table via individual_id (kept)
   =============================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'getApprovedV3') {
  $sql = "
    SELECT 'baptism' AS src,
           CAST(b.baptism_id AS UNSIGNED) AS pk,
           CAST(
             NULLIF(TRIM(CONCAT_WS(' ',
               CONCAT(i.individual_lastname, ','),
               i.individual_firstname,
               NULLIF(i.individual_middlename,'')
             )), '') AS CHAR
           ) COLLATE utf8mb4_general_ci AS display_name,
           CAST('BAPTISM' AS CHAR) COLLATE utf8mb4_general_ci AS service_label,
           CAST(b.service_date AS CHAR) COLLATE utf8mb4_general_ci AS sdate,
           CAST(b.service_time AS CHAR) COLLATE utf8mb4_general_ci AS stime
    FROM service_baptism b
    LEFT JOIN individual_table i ON i.individual_id = b.individual_id
    WHERE b.status='Done'

    UNION ALL
    SELECT 'dedication',
           CAST(d.dedicationId AS UNSIGNED),
           CAST(
             NULLIF(TRIM(CONCAT_WS(' ',
               CONCAT(i.individual_lastname, ','),
               i.individual_firstname,
               NULLIF(i.individual_middlename,'')
             )), '') AS CHAR
           ) COLLATE utf8mb4_general_ci,
           CAST('CHILD DEDICATION' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(d.service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(d.service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_dedication d
    LEFT JOIN individual_table i ON i.individual_id = d.individual_id
    WHERE d.status='Done'

    UNION ALL
    SELECT 'funeral',
           CAST(f.funeral_id AS UNSIGNED),
           CAST(
             NULLIF(TRIM(CONCAT_WS(' ',
               CONCAT(i.individual_lastname, ','),
               i.individual_firstname,
               NULLIF(i.individual_middlename,'')
             )), '') AS CHAR
           ) COLLATE utf8mb4_general_ci,
           CAST('FUNERAL SERVICE' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(f.service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(f.service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_funeral f
    LEFT JOIN individual_table i ON i.individual_id = f.individual_id
    WHERE f.status='Done'

    UNION ALL
    SELECT 'house',
           CAST(h.appointment_id AS UNSIGNED),
           CAST(
             NULLIF(TRIM(CONCAT_WS(' ',
               CONCAT(i.individual_lastname, ','),
               i.individual_firstname,
               NULLIF(i.individual_middlename,'')
             )), '') AS CHAR
           ) COLLATE utf8mb4_general_ci,
           CAST('HOUSE BLESSING' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(h.service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(h.service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_house h
    LEFT JOIN individual_table i ON i.individual_id = h.individual_id
    WHERE h.status='Done'

    UNION ALL
    SELECT 'wedding',
           CAST(w.wedding_id AS UNSIGNED),
           CAST(
             NULLIF(TRIM(CONCAT_WS(' ',
               CONCAT(i.individual_lastname, ','),
               i.individual_firstname,
               NULLIF(i.individual_middlename,'')
             )), '') AS CHAR
           ) COLLATE utf8mb4_general_ci,
           CAST('WEDDING' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(w.service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(w.service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_wedding w
    LEFT JOIN individual_table i ON i.individual_id = w.individual_id
    WHERE w.status='Done'

    ORDER BY display_name ASC
  ";
  $rows = [];
  if ($res = mysqli_query($db_connection, $sql)) {
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
  } else {
    jexit(['ok'=>false,'msg'=>mysqli_error($db_connection)]);
  }
  jexit(['ok'=>true,'rows'=>$rows]);
}

/* ===============================================================
   V4: individual table + ONLY Approved (kept)
   =============================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'getApprovedV4') {
  $sql = "
    SELECT 'baptism' AS src,
           CAST(b.baptism_id AS UNSIGNED) AS pk,
           CAST(
             NULLIF(TRIM(CONCAT_WS(' ',
               CONCAT(i.individual_lastname, ','),
               i.individual_firstname,
               NULLIF(i.individual_middlename,'')
             )), '') AS CHAR
           ) COLLATE utf8mb4_general_ci AS display_name,
           CAST('BAPTISM' AS CHAR) COLLATE utf8mb4_general_ci AS service_label,
           CAST(b.service_date AS CHAR) COLLATE utf8mb4_general_ci AS sdate,
           CAST(b.service_time AS CHAR) COLLATE utf8mb4_general_ci AS stime
    FROM service_baptism b
    LEFT JOIN individual_table i ON i.individual_id = b.individual_id
    WHERE COALESCE(b.service_status, b.status) = 'Approved'

    UNION ALL
    SELECT 'dedication',
           CAST(d.dedicationId AS UNSIGNED),
           CAST(
             NULLIF(TRIM(CONCAT_WS(' ',
               CONCAT(i.individual_lastname, ','),
               i.individual_firstname,
               NULLIF(i.individual_middlename,'')
             )), '') AS CHAR
           ) COLLATE utf8mb4_general_ci,
           CAST('CHILD DEDICATION' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(d.service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(d.service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_dedication d
    LEFT JOIN individual_table i ON i.individual_id = d.individual_id
    WHERE COALESCE(d.service_status, d.status) = 'Approved'

    UNION ALL
    SELECT 'funeral',
           CAST(f.funeral_id AS UNSIGNED),
           CAST(
             NULLIF(TRIM(CONCAT_WS(' ',
               CONCAT(i.individual_lastname, ','),
               i.individual_firstname,
               NULLIF(i.individual_middlename,'')
             )), '') AS CHAR
           ) COLLATE utf8mb4_general_ci,
           CAST('FUNERAL SERVICE' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(f.service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(f.service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_funeral f
    LEFT JOIN individual_table i ON i.individual_id = f.individual_id
    WHERE COALESCE(f.service_status, f.status) = 'Approved'

    UNION ALL
    SELECT 'house',
           CAST(h.appointment_id AS UNSIGNED),
           CAST(
             NULLIF(TRIM(CONCAT_WS(' ',
               CONCAT(i.individual_lastname, ','),
               i.individual_firstname,
               NULLIF(i.individual_middlename,'')
             )), '') AS CHAR
           ) COLLATE utf8mb4_general_ci,
           CAST('HOUSE BLESSING' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(h.service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(h.service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_house h
    LEFT JOIN individual_table i ON i.individual_id = h.individual_id
    WHERE COALESCE(h.service_status, h.status) = 'Approved'

    UNION ALL
    SELECT 'wedding',
           CAST(w.wedding_id AS UNSIGNED),
           CAST(
             NULLIF(TRIM(CONCAT_WS(' ',
               CONCAT(i.individual_lastname, ','),
               i.individual_firstname,
               NULLIF(i.individual_middlename,'')
             )), '') AS CHAR
           ) COLLATE utf8mb4_general_ci,
           CAST('WEDDING' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(w.service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(w.service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_wedding w
    LEFT JOIN individual_table i ON i.individual_id = w.individual_id
    WHERE COALESCE(w.service_status, w.status) = 'Approved'

    ORDER BY display_name ASC
  ";
  $rows = [];
  if ($res = mysqli_query($db_connection, $sql)) {
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
  } else {
    jexit(['ok'=>false,'msg'=>mysqli_error($db_connection)]);
  }
  jexit(['ok'=>true,'rows'=>$rows]);
}

/* ===============================================================
   V5: same as V4 with extra COLLATE (kept)
   =============================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'getApprovedV5') {
  $sql = "
    SELECT 'baptism' COLLATE utf8mb4_general_ci AS src,
           CAST(b.baptism_id AS UNSIGNED) AS pk,
           CAST(
             NULLIF(TRIM(CONCAT_WS(' ',
               CONCAT(i.individual_lastname, ','),
               i.individual_firstname,
               NULLIF(i.individual_middlename,'')
             )), '') AS CHAR
           ) COLLATE utf8mb4_general_ci AS display_name,
           CAST('BAPTISM' AS CHAR) COLLATE utf8mb4_general_ci AS service_label,
           CAST(b.service_date AS CHAR) COLLATE utf8mb4_general_ci AS sdate,
           CAST(b.service_time AS CHAR) COLLATE utf8mb4_general_ci AS stime
    FROM service_baptism b
    LEFT JOIN individual_table i ON i.individual_id = b.individual_id
    WHERE COALESCE(b.service_status, b.status) = 'Approved'

    UNION ALL
    SELECT 'dedication' COLLATE utf8mb4_general_ci,
           CAST(d.dedicationId AS UNSIGNED),
           CAST(
             NULLIF(TRIM(CONCAT_WS(' ',
               CONCAT(i.individual_lastname, ','),
               i.individual_firstname,
               NULLIF(i.individual_middlename,'')
             )), '') AS CHAR
           ) COLLATE utf8mb4_general_ci,
           CAST('CHILD DEDICATION' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(d.service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(d.service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_dedication d
    LEFT JOIN individual_table i ON i.individual_id = d.individual_id
    WHERE COALESCE(d.service_status, d.status) = 'Approved'

    UNION ALL
    SELECT 'funeral' COLLATE utf8mb4_general_ci,
           CAST(f.funeral_id AS UNSIGNED),
           CAST(
             NULLIF(TRIM(CONCAT_WS(' ',
               CONCAT(i.individual_lastname, ','),
               i.individual_firstname,
               NULLIF(i.individual_middlename,'')
             )), '') AS CHAR
           ) COLLATE utf8mb4_general_ci,
           CAST('FUNERAL SERVICE' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(f.service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(f.service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_funeral f
    LEFT JOIN individual_table i ON i.individual_id = f.individual_id
    WHERE COALESCE(f.service_status, f.status) = 'Approved'

    UNION ALL
    SELECT 'house' COLLATE utf8mb4_general_ci,
           CAST(h.appointment_id AS UNSIGNED),
           CAST(
             NULLIF(TRIM(CONCAT_WS(' ',
               CONCAT(i.individual_lastname, ','),
               i.individual_firstname,
               NULLIF(i.individual_middlename,'')
             )), '') AS CHAR
           ) COLLATE utf8mb4_general_ci,
           CAST('HOUSE BLESSING' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(h.service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(h.service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_house h
    LEFT JOIN individual_table i ON i.individual_id = h.individual_id
    WHERE COALESCE(h.service_status, h.status) = 'Approved'

    UNION ALL
    SELECT 'wedding' COLLATE utf8mb4_general_ci,
      CAST(w.wedding_id AS UNSIGNED),
      CAST(
        NULLIF(TRIM(CONCAT_WS(' ',
          CONCAT(i.individual_lastname, ','),
          i.individual_firstname,
          NULLIF(i.individual_middlename,'')
        )), '') AS CHAR
      ) COLLATE utf8mb4_general_ci,
      CAST('WEDDING' AS CHAR) COLLATE utf8mb4_general_ci,
      CAST(w.service_date AS CHAR) COLLATE utf8mb4_general_ci,
      CAST(w.service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_wedding w
    LEFT JOIN individual_table i ON i.individual_id = w.individual_id
    WHERE COALESCE(w.service_status, w.status) = 'Approved'

    ORDER BY display_name ASC
  ";
  $rows = [];
  if ($res = mysqli_query($db_connection, $sql)) {
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
  } else {
    jexit(['ok'=>false,'msg'=>mysqli_error($db_connection)]);
  }
  jexit(['ok'=>true,'rows'=>$rows]);
}

/* ===============================================================
   V6 (FINAL): STRICT individual full name ONLY + Approved
   - Uses ONLY individual_table fields for name
   - Format: Lastname, Firstname Middlename
   - No fallback to baptized_name / child_full_name / etc.
   =============================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'getApprovedV6') {
  $sql = "
    SELECT 'baptism' COLLATE utf8mb4_general_ci AS src,
           CAST(b.baptism_id AS UNSIGNED) AS pk,
           CAST(
             TRIM(CONCAT_WS(' ',
               CONCAT(COALESCE(i.individual_lastname,''), ','),
               COALESCE(i.individual_firstname,''),
               NULLIF(i.individual_middlename,'')
             )) AS CHAR
           ) COLLATE utf8mb4_general_ci AS display_name,
           CAST('BAPTISM' AS CHAR) COLLATE utf8mb4_general_ci AS service_label,
           CAST(b.service_date AS CHAR) COLLATE utf8mb4_general_ci AS sdate,
           CAST(b.service_time AS CHAR) COLLATE utf8mb4_general_ci AS stime
    FROM service_baptism b
    LEFT JOIN individual_table i ON i.individual_id = b.individual_id
    WHERE COALESCE(b.service_status, b.status) = 'Approved'

    UNION ALL
    SELECT 'dedication' COLLATE utf8mb4_general_ci,
           CAST(d.dedicationId AS UNSIGNED),
           CAST(
             TRIM(CONCAT_WS(' ',
               CONCAT(COALESCE(i.individual_lastname,''), ','),
               COALESCE(i.individual_firstname,''),
               NULLIF(i.individual_middlename,'')
             )) AS CHAR
           ) COLLATE utf8mb4_general_ci,
           CAST('CHILD DEDICATION' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(d.service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(d.service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_dedication d
    LEFT JOIN individual_table i ON i.individual_id = d.individual_id
    WHERE COALESCE(d.service_status, d.status) = 'Approved'

    UNION ALL
    SELECT 'funeral' COLLATE utf8mb4_general_ci,
           CAST(f.funeral_id AS UNSIGNED),
           CAST(
             TRIM(CONCAT_WS(' ',
               CONCAT(COALESCE(i.individual_lastname,''), ','),
               COALESCE(i.individual_firstname,''),
               NULLIF(i.individual_middlename,'')
             )) AS CHAR
           ) COLLATE utf8mb4_general_ci,
           CAST('FUNERAL SERVICE' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(f.service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(f.service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_funeral f
    LEFT JOIN individual_table i ON i.individual_id = f.individual_id
    WHERE COALESCE(f.service_status, f.status) = 'Approved'

    UNION ALL
    SELECT 'house' COLLATE utf8mb4_general_ci,
           CAST(h.appointment_id AS UNSIGNED),
           CAST(
             TRIM(CONCAT_WS(' ',
               CONCAT(COALESCE(i.individual_lastname,''), ','),
               COALESCE(i.individual_firstname,''),
               NULLIF(i.individual_middlename,'')
             )) AS CHAR
           ) COLLATE utf8mb4_general_ci,
           CAST('HOUSE BLESSING' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(h.service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(h.service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_house h
    LEFT JOIN individual_table i ON i.individual_id = h.individual_id
    WHERE COALESCE(h.service_status, h.status) = 'Approved'

    UNION ALL
    SELECT 'wedding' COLLATE utf8mb4_general_ci,
           CAST(w.wedding_id AS UNSIGNED),
           CAST(
             TRIM(CONCAT_WS(' ',
               CONCAT(COALESCE(i.individual_lastname,''), ','),
               COALESCE(i.individual_firstname,''),
               NULLIF(i.individual_middlename,'')
             )) AS CHAR
           ) COLLATE utf8mb4_general_ci,
           CAST('WEDDING' AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(w.service_date AS CHAR) COLLATE utf8mb4_general_ci,
           CAST(w.service_time AS CHAR) COLLATE utf8mb4_general_ci
    FROM service_wedding w
    LEFT JOIN individual_table i ON i.individual_id = w.individual_id
    WHERE COALESCE(w.service_status, w.status) = 'Approved'

    ORDER BY display_name ASC
  ";

  $rows = [];
  if ($res = mysqli_query($db_connection, $sql)) {
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
  } else {
    jexit(['ok'=>false,'msg'=>mysqli_error($db_connection)]);
  }
  jexit(['ok'=>true,'rows'=>$rows]);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>Set Schedule</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&display=swap" rel="stylesheet"/>
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet"/>
<link rel="stylesheet" href="/HTCCC-SYSTEM/css/admin-set-schedule.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/admin-set-schedule.css'); ?>">
</head>
<body>

<nav>
  <a href="admin-schedule-table.php" style="color:#fff;text-decoration:none"><i class="fas fa-arrow-left"></i></a>
  <div style="display:flex;align-items:center;gap:10px">
    <img src="image/httc_main-logo.jpg" width="42" height="42" style="border-radius:50%" alt="">
    <h1 style="margin:0;font-size:20px">SET SERVICE SCHEDULE</h1>
  </div>
  <div style="width:24px"></div>
</nav>

<main>
  <section class="left">
    <h3 id="nameField">NAME: —</h3>
    <h3 id="eventField">SERVICE: —</h3>

    <h2 style="margin-top: 30px;">Available Dates:</h2>
    <div class="calendar-container">
      <div class="calendar-header">
        <button id="prevMonth">«</button>
        <span id="monthYearLabel">Month Year</span>
        <button id="nextMonth">»</button>
      </div>
      <table>
        <thead>
          <tr><th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th></tr>
        </thead>
        <tbody id="calendarBody"></tbody>
      </table>
      <div class="hint"><span style="color:#b42318">●</span> badge = number of services that day. Click a day to view; free future days can be selected.</div>
    </div>
  </section>

  <section class="right">
    <h3>SELECT AVAILABLE TIME</h3>
    <button id="timeSelect" class="time-select">SELECT TIME <i class="fas fa-chevron-down"></i></button>
    <div id="timeDropdown" class="dropdown-list">
      <div class="dropdown-item">9:00 AM – 11:00 AM</div>
      <div class="dropdown-item">11:00 AM – 1:00 PM</div>
      <div class="dropdown-item">1:00 PM – 3:00 PM</div>
      <div class="dropdown-item">3:00 PM – 5:00 PM</div>
      <div class="dropdown-item">5:00 PM – 7:00 PM</div>
    </div>

    <button id="openApproved" class="btn secondary">View Approved Requests</button>

    <button id="setBtn" class="btn">SET</button>

    <!-- hidden fields to store selected record -->
    <input type="hidden" id="selSrc">
    <input type="hidden" id="selId">
  </section>
</main>

<!-- Approved Modal -->
<div id="approvedModal" class="modal-backdrop">
  <div class="modal">
    <header>
      <h3>Approved Requests</h3>
      <button class="close" onclick="closeApproved()">Close</button>
    </header>
    <div class="body">
      <table class="approved-table">
        <thead>
          <tr><th>Service</th><th>Name</th><th>Current Service Date</th><th>Current Service Time</th><th>Action</th></tr>
        </thead>
        <tbody id="approvedBody"><tr><td colspan="5">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<!-- Day Info Modal -->
<div id="dayInfoModal" class="modal-backdrop">
  <div class="modal">
    <header>
      <h3 id="dayInfoTitle">Schedules</h3>
      <button class="close" onclick="document.getElementById('dayInfoModal').style.display='none'">Close</button>
    </header>
    <div class="body">
      <table class="approved-table">
        <thead><tr><th>Time</th><th>Event</th><th>Name</th><th>Status</th></tr></thead>
        <tbody id="dayInfoBody"></tbody>
      </table>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function(){
  // Robust JSON fetch that shows HTML errors in a modal (never throws Unexpected '<')
  async function safeFetchJSON(url, options){
    const res = await fetch(url, options);
    const raw = await res.text();
    try { return JSON.parse(raw); }
    catch(e){
      // FIX: make the HTML a real template string, escaping raw server HTML safely
      await Swal.fire({
        icon:'error',
        title:'Server Error (not JSON)',
        html: `<div style="max-height:50vh;overflow:auto;text-align:left"><pre>${raw.replace(/[<>&]/g, s => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[s]))}</pre></div>`
      });
      return { ok:false, msg:'Non-JSON response' };
    }
  }

  const TIME_SLOTS = ["9:00 AM – 11:00 AM","11:00 AM – 1:00 PM","1:00 PM – 3:00 PM","3:00 PM – 5:00 PM","5:00 PM – 7:00 PM"];
  const today = new Date();
  let currentYear = today.getFullYear(), currentMonth = today.getMonth();
  let selectedDateISO = null, selectedTime = null;

  let schedules = [], schedulesByDate = {};

  const monthYearLabel = document.getElementById('monthYearLabel');
  const calendarBody   = document.getElementById('calendarBody');
  const prevBtn        = document.getElementById('prevMonth');
  const nextBtn        = document.getElementById('nextMonth');

  const timeBtn        = document.getElementById('timeSelect');
  const dd             = document.getElementById('timeDropdown');
  const ddItems        = [...dd.querySelectorAll('.dropdown-item')];

  const openApprovedBtn= document.getElementById('openApproved');
  const approvedModal  = document.getElementById('approvedModal');
  const approvedBody   = document.getElementById('approvedBody');

  const nameField      = document.getElementById('nameField');
  const eventField     = document.getElementById('eventField');
  const selSrc         = document.getElementById('selSrc');
  const selId          = document.getElementById('selId');

  const dayInfoModal   = document.getElementById('dayInfoModal');
  const dayInfoTitle   = document.getElementById('dayInfoTitle');
  const dayInfoBody    = document.getElementById('dayInfoBody');

  const pad2 = n => n<10 ? '0'+n : ''+n;
  const toISO = (y,m,d) => `${y}-${pad2(m+1)}-${pad2(d)}`; // FIX: add backticks
  const isPastISO = iso => {
    const [Y,M,D] = iso.split('-').map(Number);
    const dt = new Date(Y,M-1,D);
    const t0 = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    return dt < t0;
  };

  function groupSchedules(){
    schedulesByDate = {};
    schedules.forEach(s => {
      const k = s.schedule_date;
      if (!schedulesByDate[k]) schedulesByDate[k] = [];
      schedulesByDate[k].push(s);
    });
  }

  function daysInMonth(y,m){ return new Date(y,m+1,0).getDate(); }
  function firstDow(y,m){ return new Date(y,m,1).getDay(); }

  function renderCalendar(y,m){
    const months = ["January","February","March","April","May","June","July","August","September","October","November","December"];
    monthYearLabel.textContent = `${months[m]} ${y}`; // FIX: add backticks
    calendarBody.innerHTML = '';
    const first = firstDow(y,m), total = daysInMonth(y,m);
    let d = 1;
    for (let r=0; r<6; r++){
      const tr = document.createElement('tr');
      for (let c=0; c<7; c++){
        const td = document.createElement('td'); td.classList.add('day');
        if ((r===0 && c<first) || d>total){ td.classList.add('empty'); td.textContent=''; }
        else {
          const iso = toISO(y,m,d); td.textContent = d;
          if (isPastISO(iso)) td.classList.add('past'); // past not clickable for selection
          if (schedulesByDate[iso]){
            td.classList.add('taken');
            const b=document.createElement('span'); b.className='badge'; b.textContent=schedulesByDate[iso].length; td.appendChild(b);
          }
          td.addEventListener('click',()=>{
            if (schedulesByDate[iso]) { openDayInfo(iso); }
            if (!isPastISO(iso) && !schedulesByDate[iso]) selectDate(iso, td);
          });
          d++;
        }
        tr.appendChild(td);
      }
      calendarBody.appendChild(tr);
    }
  }

  function selectDate(iso, td){
    document.querySelectorAll('#calendarBody td.day').forEach(x=>x.classList.remove('selected'));
    td.classList.add('selected');
    selectedDateISO = iso;
    selectedTime = null;
    timeBtn.innerHTML = 'SELECT TIME <i class="fas fa-chevron-down"></i>';
    markTakenTimes(iso);
  }

  function markTakenTimes(iso){
    const taken = new Set((schedulesByDate[iso]||[]).map(s => (s.schedule_time||'').trim()));
    ddItems.forEach(item=>{
      const t = item.textContent.trim();
      if (taken.has(t)) item.classList.add('disabled'); else item.classList.remove('disabled');
      item.setAttribute('aria-selected','false');
    });
  }

  function openDayInfo(iso){
    dayInfoTitle.textContent = 'Schedules for ' + iso;
    const list = schedulesByDate[iso] || [];
    // FIX: make the rows HTML a template string
    dayInfoBody.innerHTML = list.map(s => `<tr><td>${s.schedule_time||'—'}</td><td>${s.schedule_event||'—'}</td><td>${s.schedule_membername||'—'}</td><td>${s.schedule_status||'—'}</td></tr>`).join('');
    dayInfoModal.style.display = 'flex';
  }

  prevBtn.onclick = ()=>{ currentMonth--; if(currentMonth<0){currentMonth=11;currentYear--; } renderCalendar(currentYear,currentMonth); };
  nextBtn.onclick = ()=>{ currentMonth++; if(currentMonth>11){currentMonth=0;currentYear++; } renderCalendar(currentYear,currentMonth); };

  timeBtn.addEventListener('click', ()=>{
    if (!selectedDateISO){ Swal.fire('Select a date first','','info'); return; }
    dd.classList.toggle('show');
  });
  document.addEventListener('click', e=>{
    if (!timeBtn.contains(e.target) && !dd.contains(e.target)) dd.classList.remove('show');
  });
  ddItems.forEach(i=> i.addEventListener('click', ()=>{
    if (i.classList.contains('disabled')) return;
    ddItems.forEach(x=>x.setAttribute('aria-selected','false'));
    i.setAttribute('aria-selected','true');
    selectedTime = i.textContent.trim();
    timeBtn.innerHTML = selectedTime + ' <i class="fas fa-chevron-down"></i>';
    dd.classList.remove('show');
  }));

  async function fetchSchedules(){
    // NOTE: this call will be routed to getSchedulesV2 by our shim below
    const j = await safeFetchJSON('admin-set-schedule.php?ajax=getSchedules');
    schedules = Array.isArray(j.rows) ? j.rows : (Array.isArray(j) ? j : []);
    groupSchedules();
    renderCalendar(currentYear, currentMonth);
  }

  // Approved modal
  window.closeApproved = ()=> approvedModal.style.display='none';
  openApprovedBtn.addEventListener('click', async ()=>{
    approvedModal.style.display='flex';
    approvedBody.innerHTML='<tr><td colspan="5">Loading…</td></tr>';
    const j = await safeFetchJSON('admin-set-schedule.php?ajax=getApproved');
    if (!j.ok){ approvedBody.innerHTML='<tr><td colspan="5">Error loading approved list.</td></tr>'; return; }
    if (!j.rows || j.rows.length===0){
      approvedBody.innerHTML='<tr><td colspan="5">No approved items.</td></tr>';
      return;
    }
    // FIX: template rows should be strings
    approvedBody.innerHTML = j.rows.map(row=>
      `<tr>
        <td>${row.service_label}</td>
        <td>${row.display_name}</td>
        <td>${row.sdate||'—'}</td>
        <td>${row.stime||'—'}</td>
        <td><button class="pick" data-src="${row.src}" data-id="${row.pk}" data-name="${row.display_name}" data-service="${row.service_label}">Select</button></td>
      </tr>`
    ).join('');
  });

  approvedBody.addEventListener('click', e=>{
    const btn = e.target.closest('button.pick'); if(!btn) return;
    document.getElementById('selSrc').value = btn.dataset.src;
    document.getElementById('selId').value  = btn.dataset.id;
    nameField.textContent  = 'NAME: ' + btn.dataset.name;
    eventField.textContent = 'SERVICE: ' + btn.dataset.service;
    approvedModal.style.display='none';
    Swal.fire({icon:'success', title:'Selected!', text:'Record loaded. Choose a date & time then press SET.'});
  });

  // SET: update correct service table
  document.getElementById('setBtn').addEventListener('click', async ()=>{
    const src = selSrc.value.trim();
    const id  = selId.value.trim();
    if (!src || !id){ Swal.fire('Select a request first','','info'); return; }
    if (!selectedDateISO || !selectedTime){ Swal.fire('Select date and time','','info'); return; }

    const fd = new FormData();
    fd.append('ajax', 'scheduleApproved');
    fd.append('src', src);
    fd.append('id', id);
    fd.append('date',  selectedDateISO);
    fd.append('time',  selectedTime);

    const j = await safeFetchJSON('admin-set-schedule.php', { method:'POST', body:fd });
    if (j.ok){
      Swal.fire({icon:'success', title:'Scheduled!', text:'Service date & time updated.'});
      await fetchSchedules();
      markTakenTimes(selectedDateISO);
    } else {
      Swal.fire({icon:'error', title:'Failed', text:j.msg||'Unable to schedule.'});
    }
  });

  // Initial render then load data (fixes the “next first” bug)
  renderCalendar(currentYear, currentMonth);
  fetchSchedules();
})();
</script>

<!-- ===============================================================
     UI Tweaks (hide date/time cols) + Fetch shim
     =============================================================== -->
<style>
  /* Hide the 3rd and 4th columns (Current Service Date / Time) in the Approved modal */
  .approved-table thead th:nth-child(3),
  .approved-table thead th:nth-child(4),
  .approved-table tbody td:nth-child(3),
  .approved-table tbody td:nth-child(4) { display: none; }
</style>
<script>
// Redirect any calls to ?ajax=getApproved → ?ajax=getApprovedV2 (no edits to original JS)
(function(){
  const _orig = window.safeFetchJSON;
  if (typeof _orig === 'function') {
    window.safeFetchJSON = function(url, options){
      try {
        if (typeof url === 'string' && url.indexOf('ajax=getApproved') !== -1) {
          url = url.replace('ajax=getApproved','ajax=getApprovedV2');
        }
      } catch(e) {}
      return _orig(url, options);
    };
  }
})();
</script>

<!-- Prefer V3 -->
<script>
(function(){
  const _prev = window.safeFetchJSON;
  if (typeof _prev === 'function') {
    window.safeFetchJSON = function(url, options){
      try {
        if (typeof url === 'string') {
          if (url.indexOf('ajax=getApprovedV2') !== -1) {
            url = url.replace('ajax=getApprovedV2','ajax=getApprovedV3');
          } else if (url.indexOf('ajax=getApproved') !== -1) {
            url = url.replace('ajax=getApproved','ajax=getApprovedV3');
          }
        }
      } catch(e) {}
      return _prev(url, options);
    };
  }
})();
</script>

<!-- Route everything to V4 -->
<script>
(function(){
  const _prev = window.safeFetchJSON;
  if (typeof _prev === 'function') {
    window.safeFetchJSON = function(url, options){
      try {
        if (typeof url === 'string') {
          if (url.includes('ajax=getApprovedV3')) {
            url = url.replace('ajax=getApprovedV3','ajax=getApprovedV4');
          } else if (url.includes('ajax=getApprovedV2')) {
            url = url.replace('ajax=getApprovedV2','ajax=getApprovedV4');
          } else if (url.includes('ajax=getApproved')) {
            url = url.replace('ajax=getApproved','ajax=getApprovedV4');
          }
        }
      } catch(e) {}
      return _prev(url, options);
    };
  }
})();
</script>

<!-- Final hop to V6 (STRICT individual fullname only) -->
<script>
(function(){
  const _prev = window.safeFetchJSON;
  if (typeof _prev === 'function') {
    window.safeFetchJSON = function(url, options){
      try {
        if (typeof url === 'string') {
          if (url.includes('ajax=getApprovedV6')) {
            // ok
          } else if (url.includes('ajax=getApprovedV5')) {
            url = url.replace('ajax=getApprovedV5','ajax=getApprovedV6');
          } else if (url.includes('ajax=getApprovedV4')) {
            url = url.replace('ajax=getApprovedV4','ajax=getApprovedV6');
          } else if (url.includes('ajax=getApprovedV3')) {
            url = url.replace('ajax=getApprovedV3','ajax=getApprovedV6');
          } else if (url.includes('ajax=getApprovedV2')) {
            url = url.replace('ajax=getApprovedV2','ajax=getApprovedV6');
          } else if (url.includes('ajax=getApproved')) {
            url = url.replace('ajax=getApproved','ajax=getApprovedV6');
          }
        }
      } catch(e) {}
      return _prev(url, options);
    };
  }
})();
</script>

<!-- ============================ ADD BELOW THIS LINE (NEW) ============================
     Route calendar fetches to the new getSchedulesV2 (account fullname)
     =================================================================== -->
<script>
(function(){
  const _prev = window.safeFetchJSON;
  if (typeof _prev === 'function') {
    window.safeFetchJSON = function(url, options){
      try {
        if (typeof url === 'string' && url.includes('ajax=getSchedules')) {
          url = url.replace('ajax=getSchedules','ajax=getSchedulesV2');
        }
      } catch(e) {}
      return _prev(url, options);
    };
  }
})();
</script>
<!-- ========================== END ADD (route to getSchedulesV2) ===================== -->

</body>
</html>
END FILE
