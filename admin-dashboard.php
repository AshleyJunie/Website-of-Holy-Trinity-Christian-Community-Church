<?php
session_start(); // notifications "mark as read" memory

/* ----------------------- GLOBAL SETUP ----------------------- */
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Manila');

/* ----------------------- DB CONNECT ------------------------ */
try {
    $pdo = new PDO("mysql:host=localhost;dbname=htccc-data-base;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // keep collation consistent to avoid UNION issues
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
} catch (PDOException $e) {
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}

/* ---------------------- ADD: SCHEMA SAFETY ----------------- */
try {
    $pdo->exec("ALTER TABLE `announcement`
                ADD COLUMN IF NOT EXISTS `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
} catch (Throwable $e) {}

/* ===================== ADD: AUDIT TRIGGER ==================
   The trigger appends any connection-level @audit_notes_suffix
   to NEW.notes on insert into audit_trail.
   This lets us "add" change details WITHOUT touching existing
   audit_log() call sites.
   ========================================================== */
try {
    $exists = $pdo->query("
        SELECT COUNT(*) FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'audit_trail_bi'
    ")->fetchColumn();
    if ((int)$exists === 0) {
        $pdo->exec("
            CREATE TRIGGER audit_trail_bi
            BEFORE INSERT ON audit_trail
            FOR EACH ROW
            BEGIN
                IF @audit_notes_suffix IS NOT NULL AND CHAR_LENGTH(@audit_notes_suffix) > 0 THEN
                    IF NEW.notes IS NULL OR NEW.notes = '' THEN
                        SET NEW.notes = @audit_notes_suffix;
                    ELSE
                        SET NEW.notes = CONCAT(NEW.notes, ' — ', @audit_notes_suffix);
                    END IF;
                END IF;
            END
        ");
    }
} catch (Throwable $e) {
    // silently ignore if cannot create trigger (permissions, etc.)
}

/* ======================= ADD: HELPERS (suffix) ============== */
if (!function_exists('audit_set_suffix')) {
    /**
     * Push a short change summary into a connection-scoped variable.
     * The DB trigger (audit_trail_bi) will append this to NEW.notes.
     */
    function audit_set_suffix(PDO $pdo, ?string $suffix): void {
        try {
            if ($suffix === null || trim($suffix) === '') {
                $pdo->exec("SET @audit_notes_suffix := NULL");
            } else {
                // limit to ~900 chars to be safe
                $pdo->exec("SET @audit_notes_suffix := " . $pdo->quote(mb_substr($suffix, 0, 900)));
            }
        } catch (Throwable $e) { /* ignore */ }
    }
}
if (!function_exists('audit_clear_suffix')) {
    function audit_clear_suffix(PDO $pdo): void {
        audit_set_suffix($pdo, null);
    }
}
if (!function_exists('audit_change_phrase')) {
    /**
     * Build a readable change string like:
     *   Status: "New" → "Past"
     *   Message added: "Hello"
     *   Message deleted: "Old text"
     */
    function audit_change_phrase(string $field, $old, $new): string {
        $fmt = function($v) {
            if ($v === null || $v === '') return '""';
            // collapse whitespace, keep short and safe
            $v = preg_replace('/\s+/u', ' ', (string)$v);
            return '"' . mb_substr($v, 0, 300) . (mb_strlen($v) > 300 ? '…' : '') . '"';
        };
        if ($old === $new) return ''; // nothing changed
        if ($old === null || $old === '') return "{$field} added: " . $fmt($new);
        if ($new === null || $new === '') return "{$field} deleted: " . $fmt($old);
        return "{$field}: " . $fmt($old) . " \u2192 " . $fmt($new); // → arrow
    }
}

/* =======================
   ADD BELOW THIS LINE: AUDIT HELPER (PDO -> audit_trail)
   - uuidv4(), client_ip(), audit_log($pdo, $params)
   ======================= */
if (!function_exists('uuidv4')) {
    function uuidv4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
if (!function_exists('client_ip')) {
    function client_ip(): string {
        foreach (['HTTP_X_FORWARDED_FOR','HTTP_CLIENT_IP','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $v = trim((string)$_SERVER[$k]);
                if ($k === 'HTTP_X_FORWARDED_FOR') {
                    $v = explode(',', $v)[0] ?? $v;
                }
                return substr($v, 0, 45);
            }
        }
        return '';
    }
}
if (!function_exists('audit_log')) {
    /**
     * audit_log($pdo, [
     *   'actor_admin_id'=>int|null, 'actor_username'=>string|null, 'actor_email'=>string|null,
     *   'action'=>'INSERT'|'UPDATE'|'DELETE'|..., 'source_table'=>'announcement',
     *   'record_pk'=>string|null, 'form_name'=>'secretary_dashboard.php', 'notes'=>string|null
     * ])
     *
     * NOTE: A DB trigger may append a change summary (from @audit_notes_suffix)
     * to the final NEW.notes automatically.
     */
    function audit_log(PDO $pdo, array $params): void {
        $sql = "INSERT INTO audit_trail (
                    txn_id, actor_admin_id, actor_username, actor_email,
                    action, source_table, record_pk, form_name,
                    ip_address, user_agent, notes, details_before, details_after
                ) VALUES (
                    :txn_id, :actor_admin_id, :actor_username, :actor_email,
                    :action, :source_table, :record_pk, :form_name,
                    :ip_address, :user_agent, :notes, NULL, NULL
                )";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':txn_id'         => uuidv4(),
                ':actor_admin_id' => isset($params['actor_admin_id']) && $params['actor_admin_id'] !== '' ? (int)$params['actor_admin_id'] : null,
                ':actor_username' => $params['actor_username'] ?? null,
                ':actor_email'    => $params['actor_email'] ?? null,
                ':action'         => $params['action'] ?? 'UPDATE',
                ':source_table'   => $params['source_table'] ?? 'announcement',
                ':record_pk'      => $params['record_pk'] ?? null,
                ':form_name'      => $params['form_name'] ?? 'secretary_dashboard.php',
                ':ip_address'     => client_ip(),
                ':user_agent'     => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ':notes'          => $params['notes'] ?? null,
            ]);
        } catch (Throwable $e) {
            // no echo to avoid breaking API responses
        } finally {
            // always clear suffix after an audit insert
            audit_clear_suffix($pdo);
        }
    }
}
/* =======================
   ADD ABOVE THIS LINE: AUDIT HELPER (PDO -> audit_trail)
   ======================= */

/* ----------------- GLOBAL FILTER (Appointments tab) -------- */
$APPT_STATUS_CLAUSE = " AND (status='Approved' OR service_status='Approved') ";

/* ---------------------- AJAX API ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__action'])) {
    header('Content-Type: application/json; charset=utf-8');

    /* ----------- Announcements ----------- */
    if ($_POST['__action'] === 'fetch_latest_announcement') {
        try {
            $row = $pdo->query("SELECT announcementId, announcementMsg, Status, created_at
                                FROM announcement
                                ORDER BY announcementId DESC
                                LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true, 'latest'=>$row ?: null]);
        } catch (Throwable $e) {
            echo json_encode(['ok'=>false, 'error'=>'Failed to fetch latest.']);
        }
        exit;
    }

    if ($_POST['__action'] === 'update_announcement_status') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? 'New'));
            if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid id']); exit; }
            if ($status === '') { $status = 'New'; }

            /* ADD: CHANGE CAPTURE (Status before) */
            $oldStatus = null;
            try {
                $q = $pdo->prepare("SELECT Status FROM announcement WHERE announcementId=:id");
                $q->execute([':id'=>$id]);
                $oldStatus = $q->fetchColumn();
            } catch (Throwable $e) {}

            $stmt = $pdo->prepare("UPDATE announcement SET Status=:s WHERE announcementId=:id");
            $stmt->execute([':s'=>$status, ':id'=>$id]);

            /* ADD: SET SUFFIX -> "Status: old → new" */
            $suffix = audit_change_phrase('Status', $oldStatus, $status);
            audit_set_suffix($pdo, $suffix);

            /* ADD: AUDIT LOG (status change) */
            $actorId   = $_SESSION['admin_id']            ?? null;
            $actorUser = $_SESSION['admin_username']      ?? ($_SESSION['admin_user']  ?? null);
            $actorMail = $_SESSION['admin_emailaddress']  ?? ($_SESSION['admin_email'] ?? null);
            audit_log($pdo, [
                'actor_admin_id' => $actorId,
                'actor_username' => $actorUser,
                'actor_email'    => $actorMail,
                'action'         => 'UPDATE',
                'source_table'   => 'announcement',
                'record_pk'      => (string)$id,
                'form_name'      => 'secretary_dashboard.php',
                'notes'          => "Updated announcement status to '{$status}'"
            ]);

            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) {
            echo json_encode(['ok'=>false, 'error'=>'Failed to update status.']);
        }
        exit;
    }

    if ($_POST['__action'] === 'create_announcement_and_archive_prev') {
        try {
            $msg = trim((string)($_POST['announcementMsg'] ?? ''));
            if ($msg === '') { echo json_encode(['ok'=>false,'error'=>'Announcement message is required.']); exit; }

            $pdo->beginTransaction();

            $prev = $pdo->query("SELECT announcementId, Status FROM announcement ORDER BY announcementId DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($prev && isset($prev['announcementId'])) {
                $prevId = (int)$prev['announcementId'];
                $oldStatusPrev = $prev['Status'] ?? null;

                $upd = $pdo->prepare("UPDATE announcement SET Status='Past' WHERE announcementId=:id");
                $upd->execute([':id'=>$prevId]);

                /* ADD: SUFFIX for archive prev: Status old -> Past */
                audit_set_suffix($pdo, audit_change_phrase('Status', $oldStatusPrev, 'Past'));

                /* ADD: AUDIT LOG (archive previous) */
                $actorId   = $_SESSION['admin_id']            ?? null;
                $actorUser = $_SESSION['admin_username']      ?? ($_SESSION['admin_user']  ?? null);
                $actorMail = $_SESSION['admin_emailaddress']  ?? ($_SESSION['admin_email'] ?? null);
                audit_log($pdo, [
                    'actor_admin_id' => $actorId,
                    'actor_username' => $actorUser,
                    'actor_email'    => $actorMail,
                    'action'         => 'UPDATE',
                    'source_table'   => 'announcement',
                    'record_pk'      => (string)$prevId,
                    'form_name'      => 'secretary_dashboard.php',
                    'notes'          => 'Archived previous announcement (set to Past)'
                ]);
            }

            $ins = $pdo->prepare("INSERT INTO `announcement` (`announcementMsg`, `Status`) VALUES (:msg, 'New')");
            $ins->execute([':msg'=>$msg]);
            $newId = (int)$pdo->lastInsertId();

            /* ADD: SUFFIX for create: Message added */
            audit_set_suffix($pdo, audit_change_phrase('Message', null, $msg));

            /* ADD: AUDIT LOG (create new) */
            $actorId   = $_SESSION['admin_id']            ?? null;
            $actorUser = $_SESSION['admin_username']      ?? ($_SESSION['admin_user']  ?? null);
            $actorMail = $_SESSION['admin_emailaddress']  ?? ($_SESSION['admin_email'] ?? null);
            audit_log($pdo, [
                'actor_admin_id' => $actorId,
                'actor_username' => $actorUser,
                'actor_email'    => $actorMail,
                'action'         => 'INSERT',
                'source_table'   => 'announcement',
                'record_pk'      => (string)$newId,
                'form_name'      => 'secretary_dashboard.php',
                'notes'          => 'Created new announcement (New)'
            ]);

            $pdo->commit();
            echo json_encode(['ok'=>true, 'id'=>$newId]);
        } catch (Throwable $e) {
            try { $pdo->rollBack(); } catch(Throwable $ee) {}
            echo json_encode(['ok'=>false,'error'=>'Failed to add announcement.']);
        }
        exit;
    }

    if ($_POST['__action'] === 'fetch_all_announcements') {
        try {
            $rows = $pdo->query("SELECT announcementId, announcementMsg, Status, created_at
                                 FROM announcement
                                 ORDER BY announcementId DESC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true, 'items'=>$rows]);
        } catch (Throwable $e) {
            echo json_encode(['ok'=>false,'error'=>'Failed to fetch history.']);
        }
        exit;
    }

    if ($_POST['__action'] === 'create_announcement') {
        try {
            $msg = trim((string)($_POST['announcementMsg'] ?? ''));
            if ($msg === '') {
                echo json_encode(['ok' => false, 'error' => 'Announcement message is required.']);
                exit;
            }
            $stmt = $pdo->prepare("INSERT INTO `announcement` (`announcementMsg`, `Status`) VALUES (:msg, 'New')");
            $stmt->execute([':msg' => $msg]);
            $newId = (int)$pdo->lastInsertId();

            /* ADD: SUFFIX for create: Message added */
            audit_set_suffix($pdo, audit_change_phrase('Message', null, $msg));

            /* ADD: AUDIT LOG (simple create) */
            $actorId   = $_SESSION['admin_id']            ?? null;
            $actorUser = $_SESSION['admin_username']      ?? ($_SESSION['admin_user']  ?? null);
            $actorMail = $_SESSION['admin_emailaddress']  ?? ($_SESSION['admin_email'] ?? null);
            audit_log($pdo, [
                'actor_admin_id' => $actorId,
                'actor_username' => $actorUser,
                'actor_email'    => $actorMail,
                'action'         => 'INSERT',
                'source_table'   => 'announcement',
                'record_pk'      => (string)$newId,
                'form_name'      => 'secretary_dashboard.php',
                'notes'          => 'Created new announcement (New)'
            ]);

            echo json_encode(['ok' => true, 'id' => $newId]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Failed to save announcement.']);
        }
        exit;
    }

    if ($_POST['__action'] === 'update_latest_new_message') {
        try {
            $newMsg = trim((string)($_POST['announcementMsg'] ?? ''));
            if ($newMsg === '') { echo json_encode(['ok'=>false,'error'=>'Message cannot be empty.']); exit; }

            $row = $pdo->query("SELECT announcementId, announcementMsg FROM announcement WHERE Status='New' ORDER BY announcementId DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['ok'=>false,'error'=>'No announcement with status New to edit.']); exit; }

            $id = (int)$row['announcementId'];
            $oldMsg = $row['announcementMsg'] ?? null;

            $stmt = $pdo->prepare("UPDATE announcement SET announcementMsg=:msg WHERE announcementId=:id AND Status='New'");
            $stmt->execute([':msg'=>$newMsg, ':id'=>$id]);

            /* ADD: SUFFIX -> "Message: old → new" */
            audit_set_suffix($pdo, audit_change_phrase('Message', $oldMsg, $newMsg));

            /* ADD: AUDIT LOG (edit message of current New) */
            $actorId   = $_SESSION['admin_id']            ?? null;
            $actorUser = $_SESSION['admin_username']      ?? ($_SESSION['admin_user']  ?? null);
            $actorMail = $_SESSION['admin_emailaddress']  ?? ($_SESSION['admin_email'] ?? null);
            audit_log($pdo, [
                'actor_admin_id' => $actorId,
                'actor_username' => $actorUser,
                'actor_email'    => $actorMail,
                'action'         => 'UPDATE',
                'source_table'   => 'announcement',
                'record_pk'      => (string)$id,
                'form_name'      => 'secretary_dashboard.php',
                'notes'          => 'Edited latest New announcement message'
            ]);

            echo json_encode(['ok'=>true, 'id'=>$id]);
        } catch (Throwable $e) {
            echo json_encode(['ok'=>false, 'error'=>'Failed to update message.']);
        }
        exit;
    }

    if ($_POST['__action'] === 'archive_latest_new') {
        try {
            $row = $pdo->query("SELECT announcementId, Status FROM announcement WHERE Status='New' ORDER BY announcementId DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['ok'=>false,'error'=>'Walang nakitang announcement na “New” para i-archive.']); exit; }

            $id = (int)$row['announcementId'];
            $oldStatus = $row['Status'] ?? null;

            $stmt = $pdo->prepare("UPDATE announcement SET Status='Past' WHERE announcementId=:id AND Status='New'");
            $stmt->execute([':id'=>$id]);

            /* ADD: SUFFIX -> "Status: New → Past" */
            audit_set_suffix($pdo, audit_change_phrase('Status', $oldStatus, 'Past'));

            /* ADD: AUDIT LOG (archive New -> Past) */
            $actorId   = $_SESSION['admin_id']            ?? null;
            $actorUser = $_SESSION['admin_username']      ?? ($_SESSION['admin_user']  ?? null);
            $actorMail = $_SESSION['admin_emailaddress']  ?? ($_SESSION['admin_email'] ?? null);
            audit_log($pdo, [
                'actor_admin_id' => $actorId,
                'actor_username' => $actorUser,
                'actor_email'    => $actorMail,
                'action'         => 'UPDATE',
                'source_table'   => 'announcement',
                'record_pk'      => (string)$id,
                'form_name'      => 'secretary_dashboard.php',
                'notes'          => 'Archived latest New announcement (set to Past)'
            ]);

            echo json_encode(['ok'=>true, 'id'=>$id]);
        } catch (Throwable $e) {
            echo json_encode(['ok'=>false, 'error'=>'Failed to archive announcement.']);
        }
        exit;
    }

    /* ================== SERVICES DASHBOARD (service_* fields) ================= */
    if ($_POST['__action'] === 'fetch_scheduled_dashboard') {
        $mode = $_POST['mode'] ?? 'today';
        $today = date('Y-m-d');

        $tables = ['service_baptism','service_dedication','service_funeral','service_house','service_wedding'];

        $countToday = 0;
        foreach ($tables as $t) {
            try {
                $q = $pdo->prepare("SELECT COUNT(*) AS c FROM `$t` 
                                    WHERE service_status='Scheduled' AND DATE(service_date)=:d");
                $q->execute([':d'=>$today]);
                $countToday += (int)($q->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
            } catch (Throwable $e) {}
        }

        $countUpcoming = 0;
        foreach ($tables as $t) {
            try {
                $q = $pdo->query("SELECT COUNT(*) AS c FROM `$t` 
                                  WHERE service_status='Scheduled' AND service_date > NOW()");
                $countUpcoming += (int)($q->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
            } catch (Throwable $e) {}
        }

        $list = [];
        $next = null;

        try {
            $base = "
            SELECT t.individual_id, t.full_name, t.service, t.service_date, t.service_time
            FROM (
                /* BAPTISM */
                SELECT b.individual_id,
                       CASE
                         WHEN b.appointment_type='onsite' AND NULLIF(b.client_name,'') IS NOT NULL
                           THEN CONVERT(b.client_name USING utf8mb4) COLLATE utf8mb4_general_ci
                         ELSE COALESCE(
                           NULLIF(CONVERT(TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname))
                                  USING utf8mb4) COLLATE utf8mb4_general_ci,''),
                           'Unknown')
                       END AS full_name,
                       CAST('Baptism' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
                       b.service_date,
                       CONVERT(b.service_time USING utf8mb4) COLLATE utf8mb4_general_ci AS service_time
                FROM service_baptism b
                LEFT JOIN individual_table i ON i.individual_id=b.individual_id
                WHERE b.service_status='Scheduled' /**C1**/

                UNION ALL
                /* DEDICATION */
                SELECT d.individual_id,
                       CASE
                         WHEN d.appointment_type='onsite' AND NULLIF(d.client_name,'') IS NOT NULL
                           THEN CONVERT(d.client_name USING utf8mb4) COLLATE utf8mb4_general_ci
                         ELSE COALESCE(
                           NULLIF(CONVERT(TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname))
                                  USING utf8mb4) COLLATE utf8mb4_general_ci,''), 
                           'Unknown')
                       END AS full_name,
                       CAST('Dedication' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
                       d.service_date,
                       CONVERT(d.service_time USING utf8mb4) COLLATE utf8mb4_general_ci AS service_time
                FROM service_dedication d
                LEFT JOIN individual_table i ON i.individual_id=d.individual_id
                WHERE d.service_status='Scheduled' /**C2**/

                UNION ALL
                /* FUNERAL */
                SELECT f.individual_id,
                       CASE
                         WHEN f.appointment_type='onsite' AND NULLIF(f.client_name,'') IS NOT NULL
                           THEN CONVERT(f.client_name USING utf8mb4) COLLATE utf8mb4_general_ci
                         ELSE COALESCE(
                           NULLIF(CONVERT(TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname))
                                  USING utf8mb4) COLLATE utf8mb4_general_ci,''), 
                           'Unknown')
                       END AS full_name,
                       CAST('Funeral' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
                       f.service_date,
                       CONVERT(f.service_time USING utf8mb4) COLLATE utf8mb4_general_ci AS service_time
                FROM service_funeral f
                LEFT JOIN individual_table i ON i.individual_id=f.individual_id
                WHERE f.service_status='Scheduled' /**C3**/

                UNION ALL
                /* HOUSE BLESSING */
                SELECT h.individual_id,
                       CASE
                         WHEN h.appointment_type='onsite' AND COALESCE(NULLIF(h.client_name,''), NULLIF(h.owner_full_name,'')) IS NOT NULL
                           THEN CONVERT(COALESCE(NULLIF(h.client_name,''), h.owner_full_name) USING utf8mb4) COLLATE utf8mb4_general_ci
                         ELSE COALESCE(
                           NULLIF(CONVERT(TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname))
                                  USING utf8mb4) COLLATE utf8mb4_general_ci,''), 
                           'Unknown')
                       END AS full_name,
                       CAST('House Blessing' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
                       h.service_date,
                       CONVERT(h.service_time USING utf8mb4) COLLATE utf8mb4_general_ci AS service_time
                FROM service_house h
                LEFT JOIN individual_table i ON i.individual_id=h.individual_id
                WHERE h.service_status='Scheduled' /**C4**/

                UNION ALL
                /* WEDDING */
                SELECT w.individual_id,
                       CASE
                         WHEN w.appointment_type='onsite' AND NULLIF(w.client_name,'') IS NOT NULL
                           THEN CONVERT(w.client_name USING utf8mb4) COLLATE utf8mb4_general_ci
                         ELSE COALESCE(
                           NULLIF(CONVERT(TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname))
                                  USING utf8mb4) COLLATE utf8mb4_general_ci,''), 
                           'Unknown')
                       END AS full_name,
                       CAST('Wedding' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
                       w.service_date,
                       CONVERT(w.service_time USING utf8mb4) COLLATE utf8mb4_general_ci AS service_time
                FROM service_wedding w
                LEFT JOIN individual_table i ON i.individual_id=w.individual_id
                WHERE w.service_status='Scheduled' /**C5**/
            ) t
            /**ORDERLIMIT**/
            ";

            if ($mode === 'today') {
                $cond = " AND DATE(service_date)=CURDATE() ";
                $sql = str_replace(['/**C1**/','/**C2**/','/**C3**/','/**C4**/','/**C5**/'], array_fill(0,5,$cond), $base);
                $sql = str_replace('/**ORDERLIMIT**/', "ORDER BY t.service_date ASC, COALESCE(STR_TO_DATE(t.service_time,'%h:%i %p'), STR_TO_DATE(t.service_time,'%H:%i'),'9999-12-31 23:59:59') ASC LIMIT 50", $sql);
                $stmt = $pdo->query($sql);
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $r['time_fmt'] = !empty($r['service_time']) && strtotime($r['service_time']) ? date('g:i A', strtotime($r['service_time'])) : '';
                    $list[] = [
                        'individual_id'=>$r['individual_id'],
                        'full_name'=>$r['full_name'],
                        'service'=>$r['service'],
                        'appointment_date'=>$r['service_date'],
                        'appointment_time'=>$r['service_time'],
                        'time_fmt'=>$r['time_fmt']
                    ];
                }
            } elseif ($mode === 'upcoming') {
                $cond = " AND service_date > NOW() ";
                $sql = str_replace(['/**C1**/','/**C2**/','/**C3**/','/**C4**/','/**C5**/'], array_fill(0,5,$cond), $base);
                $sql = str_replace('/**ORDERLIMIT**/', "ORDER BY t.service_date ASC, COALESCE(STR_TO_DATE(t.service_time,'%h:%i %p'), STR_TO_DATE(t.service_time,'%H:%i'),'9999-12-31 23:59:59') ASC LIMIT 50", $sql);
                $stmt = $pdo->query($sql);
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $r['time_fmt'] = !empty($r['service_time']) && strtotime($r['service_time']) ? date('g:i A', strtotime($r['service_time'])) : '';
                    $list[] = [
                        'individual_id'=>$r['individual_id'],
                        'full_name'=>$r['full_name'],
                        'service'=>$r['service'],
                        'appointment_date'=>$r['service_date'],
                        'appointment_time'=>$r['service_time'],
                        'time_fmt'=>$r['time_fmt']
                    ];
                }
            } else { // next
                $cond = " AND service_date >= CURDATE() ";
                $sql = str_replace(['/**C1**/','/**C2**/','/**C3**/','/**C4**/','/**C5**/'], array_fill(0,5,$cond), $base);
                $sql = str_replace('/**ORDERLIMIT**/', "ORDER BY t.service_date ASC, COALESCE(STR_TO_DATE(t.service_time,'%h:%i %p'), STR_TO_DATE(t.service_time,'%H:%i'),'9999-12-31 23:59:59') ASC LIMIT 1", $sql);
                $stmt = $pdo->query($sql);
                $nx = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($nx) {
                    $nx['time_fmt'] = (!empty($nx['service_time']) && strtotime($nx['service_time'])) ? date('g:i A', strtotime($nx['service_time'])) : '';
                    $next = [
                        'individual_id'=>$nx['individual_id'],
                        'full_name'=>$nx['full_name'],
                        'service'=>$nx['service'],
                        'appointment_date'=>$nx['service_date'],
                        'appointment_time'=>$nx['service_time'],
                        'time_fmt'=>$nx['time_fmt']
                    ];
                }
            }
        } catch (Throwable $e) {}

        echo json_encode(['ok'=>true,'counts'=>['today'=>$countToday,'upcoming'=>$countUpcoming],'list'=>$list,'next'=>$next]);
        exit;
    }

    /* ================== APPOINTMENTS DASHBOARD (appointment_* fields) ================= */
    if ($_POST['__action'] === 'fetch_appointments_dashboard') {
        $today = date('Y-m-d');
        $tables = ['service_baptism','service_dedication','service_funeral','service_house','service_wedding'];

        $countToday = 0; $countUpcoming = 0;
        foreach ($tables as $t) {
            try {
                $q = $pdo->prepare("SELECT COUNT(*) AS c FROM `$t` WHERE DATE(appointment_date)=:d $APPT_STATUS_CLAUSE");
                $q->execute([':d'=>$today]);
                $countToday += (int)($q->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
            } catch (Throwable $e) {}
            try {
                $q = $pdo->query("SELECT COUNT(*) AS c FROM `$t` WHERE appointment_date > CURDATE() $APPT_STATUS_CLAUSE");
                $countUpcoming += (int)($q->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
            } catch (Throwable $e) {}
        }

        $list = [];
        try {
            $sql = "
              SELECT t.individual_id, t.full_name, t.service, t.appointment_date, t.appointment_time
              FROM (
                /* BAPTISM */
                SELECT b.individual_id,
                       CASE
                         WHEN b.appointment_type='onsite' AND NULLIF(b.client_name,'') IS NOT NULL
                           THEN CONVERT(b.client_name USING utf8mb4) COLLATE utf8mb4_general_ci
                         ELSE COALESCE(
                           NULLIF(CONVERT(TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname))
                                  USING utf8mb4) COLLATE utf8mb4_general_ci,''), 
                           'Unknown')
                       END AS full_name,
                       CAST('Baptism' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
                       b.appointment_date,
                       CONVERT(b.appointment_time USING utf8mb4) COLLATE utf8mb4_general_ci AS appointment_time
                FROM service_baptism b
                LEFT JOIN individual_table i ON i.individual_id=b.individual_id
                WHERE DATE(b.appointment_date)=:d1 $APPT_STATUS_CLAUSE

                UNION ALL
                /* DEDICATION */
                SELECT d.individual_id,
                       CASE
                         WHEN d.appointment_type='onsite' AND NULLIF(d.client_name,'') IS NOT NULL
                           THEN CONVERT(d.client_name USING utf8mb4) COLLATE utf8mb4_general_ci
                         ELSE COALESCE(
                           NULLIF(CONVERT(TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname))
                                  USING utf8mb4) COLLATE utf8mb4_general_ci,''), 
                           'Unknown')
                       END AS full_name,
                       CAST('Dedication' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
                       d.appointment_date,
                       CONVERT(d.appointment_time USING utf8mb4) COLLATE utf8mb4_general_ci AS appointment_time
                FROM service_dedication d
                LEFT JOIN individual_table i ON i.individual_id=d.individual_id
                WHERE DATE(d.appointment_date)=:d2 $APPT_STATUS_CLAUSE

                UNION ALL
                /* FUNERAL */
                SELECT f.individual_id,
                       CASE
                         WHEN f.appointment_type='onsite' AND NULLIF(f.client_name,'') IS NOT NULL
                           THEN CONVERT(f.client_name USING utf8mb4) COLLATE utf8mb4_general_ci
                         ELSE COALESCE(
                           NULLIF(CONVERT(TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname))
                                  USING utf8mb4) COLLATE utf8mb4_general_ci,''), 
                           'Unknown')
                       END AS full_name,
                       CAST('Funeral' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
                       f.appointment_date,
                       CONVERT(f.appointment_time USING utf8mb4) COLLATE utf8mb4_general_ci AS appointment_time
                FROM service_funeral f
                LEFT JOIN individual_table i ON i.individual_id=f.individual_id
                WHERE DATE(f.appointment_date)=:d3 $APPT_STATUS_CLAUSE

                UNION ALL
                /* HOUSE BLESSING */
                SELECT h.individual_id,
                       CASE
                         WHEN h.appointment_type='onsite' AND COALESCE(NULLIF(h.client_name,''), NULLIF(h.owner_full_name,'')) IS NOT NULL
                           THEN CONVERT(COALESCE(NULLIF(h.client_name,''), h.owner_full_name) USING utf8mb4) COLLATE utf8mb4_general_ci
                         ELSE COALESCE(
                           NULLIF(CONVERT(TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname))
                                  USING utf8mb4) COLLATE utf8mb4_general_ci,''), 
                           'Unknown')
                       END AS full_name,
                       CAST('House Blessing' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
                       h.appointment_date,
                       CONVERT(h.appointment_time USING utf8mb4) COLLATE utf8mb4_general_ci AS appointment_time
                FROM service_house h
                LEFT JOIN individual_table i ON i.individual_id=h.individual_id
                WHERE DATE(h.appointment_date)=:d4 $APPT_STATUS_CLAUSE

                UNION ALL
                /* WEDDING */
                SELECT w.individual_id,
                       CASE
                         WHEN w.appointment_type='onsite' AND NULLIF(w.client_name,'') IS NOT NULL
                           THEN CONVERT(w.client_name USING utf8mb4) COLLATE utf8mb4_general_ci
                         ELSE COALESCE(
                           NULLIF(CONVERT(TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname))
                                  USING utf8mb4) COLLATE utf8mb4_general_ci,''), 
                           'Unknown')
                       END AS full_name,
                       CAST('Wedding' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
                       w.appointment_date,
                       CONVERT(w.appointment_time USING utf8mb4) COLLATE utf8mb4_general_ci AS appointment_time
                FROM service_wedding w
                LEFT JOIN individual_table i ON i.individual_id=w.individual_id
                WHERE DATE(w.appointment_date)=:d5 $APPT_STATUS_CLAUSE
              ) t
              ORDER BY
                COALESCE(STR_TO_DATE(t.appointment_time,'%h:%i %p'),
                         STR_TO_DATE(t.appointment_time,'%H:%i'),
                         '9999-12-31 23:59:59') ASC
              LIMIT 50;
            ";
            $st = $pdo->prepare($sql);
            $st->execute([':d1'=>$today, ':d2'=>$today, ':d3'=>$today, ':d4'=>$today, ':d5'=>$today]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $r['time_fmt'] = !empty($r['appointment_time']) && strtotime($r['appointment_time']) ? date('g:i A', strtotime($r['appointment_time'])) : '';
                $list[] = $r;
            }
        } catch (Throwable $e) {}

        $next = $list[1] ?? null;

        echo json_encode(['ok'=>true,'counts'=>['today'=>$countToday,'upcoming'=>$countUpcoming],'list'=>$list,'next'=>$next]);
        exit;
    }

    echo json_encode(['ok'=>false, 'error'=>'Unknown action']);
    exit;
}

/* ----------------------- CSS VERSION ----------------------- */
$cssServerPath = $_SERVER['DOCUMENT_ROOT'] . '/HTCCC-SYSTEM/css/secretary_dashboard.css';
$cssVer = file_exists($cssServerPath) ? filemtime($cssServerPath) : time();

/* --------------------- SUMMARY COUNTERS -------------------- */
$totalIndividuals = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) AS total FROM individual_table");
    $totalIndividuals = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (Throwable $e) {}

$serviceTables = [
    "service_baptism",
    "service_dedication",
    "service_funeral",
    "service_house",
    "service_wedding"
];

$totalAppointmentsToday = 0;
foreach ($serviceTables as $t) {
    try {
        $row = $pdo->query("SELECT COUNT(*) AS c FROM `$t` WHERE DATE(appointment_date)=CURDATE() $APPT_STATUS_CLAUSE")->fetch(PDO::FETCH_ASSOC);
        $totalAppointmentsToday += (int)($row['c'] ?? 0);
    } catch (Throwable $e) {}
}

$totalUpcoming = 0;
foreach ($serviceTables as $t) {
    try {
        $row = $pdo->query("SELECT COUNT(*) AS c FROM `$t` WHERE appointment_date > CURDATE() $APPT_STATUS_CLAUSE")->fetch(PDO::FETCH_ASSOC);
        $totalUpcoming += (int)($row['c'] ?? 0);
    } catch (Throwable $e) {}
}

/* ------------------- TODAY’S APPOINTMENTS ------------------ */
$todaysAppointments = [];
$today = date('Y-m-d');

try {
    $sql = "
      SELECT t.individual_id, t.full_name, t.service, t.appointment_date, t.appointment_time
      FROM (
        /* BAPTISM */
        SELECT b.individual_id,
               CASE
                 WHEN b.appointment_type='onsite' AND NULLIF(b.client_name,'') IS NOT NULL
                   THEN CONVERT(b.client_name USING utf8mb4) COLLATE utf8mb4_general_ci
                 ELSE COALESCE(
                   NULLIF(CONVERT(TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname))
                          USING utf8mb4) COLLATE utf8mb4_general_ci,''), 
                   'Unknown')
               END AS full_name,
               CAST('Baptism' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
               b.appointment_date,
               CONVERT(b.appointment_time USING utf8mb4) COLLATE utf8mb4_general_ci AS appointment_time
        FROM service_baptism b
        LEFT JOIN individual_table i ON i.individual_id=b.individual_id
        WHERE DATE(b.appointment_date)=:d1 $APPT_STATUS_CLAUSE

        UNION ALL
        /* DEDICATION */
        SELECT d.individual_id,
               CASE
                 WHEN d.appointment_type='onsite' AND NULLIF(d.client_name,'') IS NOT NULL
                   THEN CONVERT(d.client_name USING utf8mb4) COLLATE utf8mb4_general_ci
                 ELSE COALESCE(
                   NULLIF(CONVERT(TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname))
                          USING utf8mb4) COLLATE utf8mb4_general_ci,''), 
                   'Unknown')
               END AS full_name,
               CAST('Dedication' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
               d.appointment_date,
               CONVERT(d.appointment_time USING utf8mb4) COLLATE utf8mb4_general_ci AS appointment_time
        FROM service_dedication d
        LEFT JOIN individual_table i ON i.individual_id=d.individual_id
        WHERE DATE(d.appointment_date)=:d2 $APPT_STATUS_CLAUSE

        UNION ALL
        /* FUNERAL */
        SELECT f.individual_id,
               CASE
                 WHEN f.appointment_type='onsite' AND NULLIF(f.client_name,'') IS NOT NULL
                   THEN CONVERT(f.client_name USING utf8mb4) COLLATE utf8mb4_general_ci
                 ELSE COALESCE(
                   NULLIF(CONVERT(TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname))
                          USING utf8mb4) COLLATE utf8mb4_general_ci,''), 
                   'Unknown')
               END AS full_name,
               CAST('Funeral' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
               f.appointment_date,
               CONVERT(f.appointment_time USING utf8mb4) COLLATE utf8mb4_general_ci AS appointment_time
        FROM service_funeral f
        LEFT JOIN individual_table i ON i.individual_id=f.individual_id
        WHERE DATE(f.appointment_date)=:d3 $APPT_STATUS_CLAUSE

        UNION ALL
        /* HOUSE BLESSING */
        SELECT h.individual_id,
               CASE
                 WHEN h.appointment_type='onsite' AND COALESCE(NULLIF(h.client_name,''), NULLIF(h.owner_full_name,'')) IS NOT NULL
                   THEN CONVERT(COALESCE(NULLIF(h.client_name,''), h.owner_full_name) USING utf8mb4) COLLATE utf8mb4_general_ci
                 ELSE COALESCE(
                   NULLIF(CONVERT(TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname))
                          USING utf8mb4) COLLATE utf8mb4_general_ci,''), 
                   'Unknown')
               END AS full_name,
               CAST('House Blessing' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
               h.appointment_date,
               CONVERT(h.appointment_time USING utf8mb4) COLLATE utf8mb4_general_ci AS appointment_time
        FROM service_house h
        LEFT JOIN individual_table i ON i.individual_id=h.individual_id
        WHERE DATE(h.appointment_date)=:d4 $APPT_STATUS_CLAUSE

        UNION ALL
        /* WEDDING */
        SELECT w.individual_id,
               CASE
                 WHEN w.appointment_type='onsite' AND NULLIF(w.client_name,'') IS NOT NULL
                   THEN CONVERT(w.client_name USING utf8mb4) COLLATE utf8mb4_general_ci
                 ELSE COALESCE(
                   NULLIF(CONVERT(TRIM(CONCAT_WS(' ', i.individual_firstname, i.individual_middlename, i.individual_lastname))
                          USING utf8mb4) COLLATE utf8mb4_general_ci,''), 
                   'Unknown')
               END AS full_name,
               CAST('Wedding' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
               w.appointment_date,
               CONVERT(w.appointment_time USING utf8mb4) COLLATE utf8mb4_general_ci AS appointment_time
        FROM service_wedding w
        LEFT JOIN individual_table i ON i.individual_id=w.individual_id
        WHERE DATE(w.appointment_date)=:d5 $APPT_STATUS_CLAUSE
      ) t
      ORDER BY
        COALESCE(STR_TO_DATE(t.appointment_time,'%h:%i %p'),
                 STR_TO_DATE(t.appointment_time,'%H:%i'),
                 '9999-12-31 23:59:59') ASC
      LIMIT 50;
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':d1'=>$today, ':d2'=>$today, ':d3'=>$today, ':d4'=>$today, ':d5'=>$today]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $r['time_fmt'] = '';
        if (!empty($r['appointment_time'])) {
            $ts = strtotime($r['appointment_time']);
            if ($ts !== false) $r['time_fmt'] = date('g:i A', $ts);
        }
        $todaysAppointments[] = $r;
    }
} catch (Throwable $e) {
    $todaysAppointments = [];
}

/* ---------------- NEXT APPOINTMENT (2nd of today) ---------- */
$secondAppt = $todaysAppointments[1] ?? null;
$secondDetails = null;
if ($secondAppt) {
    $contactNumber = '—';
    try {
        $s = $pdo->prepare("SELECT * FROM individual_table WHERE individual_id=:id LIMIT 1");
        $s->execute([':id'=>$secondAppt['individual_id']]);
        if ($ind = $s->fetch(PDO::FETCH_ASSOC)) {
            foreach (['contact_number','contact','mobile','phone','mobile_number','phone_number','individual_contact'] as $f) {
                if (!empty($ind[$f])) { $contactNumber = $ind[$f]; break; }
            }
        }
    } catch (Throwable $e) {}
    $secondDetails = [
        'name'   => $secondAppt['full_name'] ?? '',
        'service'=> $secondAppt['service'] ?? '',
        'date'   => !empty($secondAppt['appointment_date']) ? date('M d, Y', strtotime($secondAppt['appointment_date'])) : '',
        'time'   => ($secondAppt['time_fmt'] ?? '') ?: (!empty($secondAppt['appointment_time']) ? date('g:i A', strtotime($secondAppt['appointment_time'])) : ''),
        'contact'=> $contactNumber
    ];
}

/* -------------------- MONTH SUMMARY (pie) ------------------ */
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');
$monthLabel = date('F Y');

function countInRange(PDO $pdo, string $table, string $dateCol, string $start, string $end): int {
    $q = $pdo->prepare("SELECT COUNT(*) AS c FROM `$table` WHERE `$dateCol` BETWEEN :s AND :e");
    $q->execute([':s'=>$start, ':e'=>$end]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return (int)($row['c'] ?? 0);
}

$serviceCountsMonth = [
    'Funeral'  => countInRange($pdo, 'service_funeral',    'appointment_date', $monthStart, $monthEnd),
    'Baptism'  => countInRange($pdo, 'service_baptism',    'appointment_date', $monthStart, $monthEnd),
    'Others'   => countInRange($pdo, 'service_dedication', 'appointment_date', $monthStart, $monthEnd),
    'Blessing' => countInRange($pdo, 'service_house',      'appointment_date', $monthStart, $monthEnd),
    'Wedding'  => countInRange($pdo, 'service_wedding',    'appointment_date', $monthStart, $monthEnd),
];

/* =======================
   ADD BELOW THIS LINE: GRAPH "DONE" FILTER OVERRIDE
   - Recompute counts limited to service_status='Done'
   - Keeps original code intact; we simply override the values
   ======================= */
if (!function_exists('countInRangeByStatus')) {
    function countInRangeByStatus(PDO $pdo, string $table, string $dateCol, string $start, string $end, string $status = 'Done'): int {
        try {
            $sql = "SELECT COUNT(*) AS c
                    FROM `$table`
                    WHERE `$dateCol` BETWEEN :s AND :e
                      AND `service_status` = :st";
            $q = $pdo->prepare($sql);
            $q->execute([':s'=>$start, ':e'=>$end, ':st'=>$status]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            return (int)($row['c'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
}

/* Override the graph data to include only 'Done' services */
$__graphDoneCounts = [
    'Funeral'  => countInRangeByStatus($pdo, 'service_funeral',    'appointment_date', $monthStart, $monthEnd, 'Done'),
    'Baptism'  => countInRangeByStatus($pdo, 'service_baptism',    'appointment_date', $monthStart, $monthEnd, 'Done'),
    'Others'   => countInRangeByStatus($pdo, 'service_dedication', 'appointment_date', $monthStart, $monthEnd, 'Done'),
    'Blessing' => countInRangeByStatus($pdo, 'service_house',      'appointment_date', $monthStart, $monthEnd, 'Done'),
    'Wedding'  => countInRangeByStatus($pdo, 'service_wedding',    'appointment_date', $monthStart, $monthEnd, 'Done'),
];
$serviceCountsMonth = $__graphDoneCounts;
/* =======================
   ADD ABOVE THIS LINE: GRAPH "DONE" FILTER OVERRIDE
   ======================= */

/* ---------------------- NOTIFICATIONS ---------------------- */
$notifications = [];
try {
    $stmtN = $pdo->prepare(str_replace("LIMIT 50","LIMIT 20",$sql)); // same UNION as above
    $stmtN->execute([':d1'=>$today, ':d2'=>$today, ':d3'=>$today, ':d4'=>$today, ':d5'=>$today]);
    while ($n = $stmtN->fetch(PDO::FETCH_ASSOC)) {
        $n['time_fmt'] = '';
        if (!empty($n['appointment_time'])) {
            $ts = strtotime($n['appointment_time']);
            if ($ts !== false) $n['time_fmt'] = date('g:i A', $ts);
        }
        $n['date_fmt'] = !empty($n['appointment_date']) ? date('M d, Y', strtotime($n['appointment_date'])) : '';
        $notifications[] = $n;
    }
} catch (Throwable $e) {
    $notifications = [];
}

/* Clear/seen today? then hide list/badge */
$todayYmd = date('Y-m-d');
if (!empty($_SESSION['notif_seen_date']) && $_SESSION['notif_seen_date'] === $todayYmd) {
    $notifications = [];
}
$newAppointmentsCount = count($notifications);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Secretary Dashboard</title>

<link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link rel="stylesheet" href="/HTCCC-SYSTEM/css/secretary_dashboard.css?v=<?php echo $cssVer; ?>">

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
    <a class="navlink active" href="secretary_dashboard.php"><i class="fas fa-th-large"></i>Dashboard</a>

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
    <a class="navlink" href="pastor-audittrails.php">
      <i class="fa fa-file"></i> Audit Trails
    </a>
      <a class="navlink" href="pastor-admin-accounts.php">
      <i class="fas fa-user"></i>Admin Accounts
    </a>

    <div class="section-title">More</div>
    <a class="navlink logout" href="all_log-in.php">
      <img alt="Logout" class="icon" src="image/logo-logout.png" width="18" height="18" style="vertical-align:middle;margin-right:8px;">
      Log Out
    </a>
  </nav>
</aside>

<!-- ==================== MAIN CONTENT WRAP ================== -->
<div class="page">
  <header class="topbar">
    <h1>Secretary Dashboard</h1>

    <div class="top-actions">
      <button class="add-annc-btn" id="openAddAnnouncement" title="Announcement">
        <i class="fas fa-bullhorn"></i>
        <span>Announcement</span>
      </button>

      <button class="icon-btn" title="Messages"><i class="far fa-envelope"></i></button>

      <!-- NOTIFICATIONS -->
      <div class="notif-wrap">
        <button aria-label="Notifications" class="notif-btn" id="notifBell">
          <i class="far fa-bell"></i>
          <?php if ($newAppointmentsCount): ?>
            <span class="notif-badge" id="notifBadge"><?php echo (int)$newAppointmentsCount; ?></span>
          <?php endif; ?>
        </button>

        <div class="notif-dropdown" id="notifDropdown" aria-hidden="true" role="menu" aria-label="Notifications">
          <div class="notif-header">
            <span>Notifications</span>
            <button class="notif-markread" id="notifMarkRead" type="button">Mark all as read</button>
          </div>

          <?php if (empty($notifications)): ?>
            <div class="notif-empty">
              <img src="css/image/empty.png" alt="" aria-hidden="true">
              <div>No new notifications</div>
            </div>
          <?php else: ?>
            <ul class="notif-list">
              <?php foreach ($notifications as $n): ?>
                <li class="notif-item" role="menuitem" tabindex="0">
                  <div class="notif-icon">
                    <img src="image/logo-user.png" alt="">
                  </div>
                  <div class="notif-body">
                    <div class="notif-line">
                      <strong><?php echo htmlspecialchars($n['full_name']); ?></strong>
                      <span class="notif-muted">booked</span>
                      <span class="notif-service"><?php echo htmlspecialchars($n['service']); ?></span>
                    </div>
                    <div class="notif-meta">
                      <?php
                        $date = $n['date_fmt'] ?: (string)$n['appointment_date'];
                        $time = $n['time_fmt'] ?: (string)$n['appointment_time'];
                        echo htmlspecialchars(trim($date . ($time ? " • ".$time : "")));
                      ?>
                    </div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
            <div class="notif-footer"><a href="admin-schedule-table.php">View all appointments</a></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </header>

  <!-- BIG, NOTICEABLE SWITCH (Appointments <-> Services) -->
  <div class="mode-toggle" id="modeToggle" role="tablist" aria-label="Data mode">
    <button class="pill active" role="tab" aria-selected="true" aria-pressed="true" data-mode="appointments">
      <i class="far fa-calendar-check"></i> Appointments
    </button>
    <button class="pill secondary" role="tab" aria-selected="false" aria-pressed="false" data-mode="services">
      <i class="fas fa-clipboard-list"></i> Services
    </button>
  
    <a class="pill secondary" href="content-management_events.php" role="tab" aria-selected="false" aria-pressed="false" data-mode="manage-poster" id="btnManagePoster">
      <i class="fas fa-image"></i> Manage Event Poster
    </a>
    <a class="pill secondary" href="content-management_gallery.php" role="tab" aria-selected="false" aria-pressed="false" data-mode="manage-gallery" id="btnManageGallery">
      <i class="fas fa-images"></i> Manage Gallery
    </a>
  </div>

  <!-- STATS -->
  <section class="stats" id="statsSection">
    <article class="stat">
      <img src="image/logo-member.png" alt="">
      <div>
        <div class="stat-title">Total Individual</div>
        <div class="stat-value"><?php echo (int)$totalIndividuals; ?></div>
      </div>
    </article>

    <article class="stat">
      <img src="image/logo-schedule.png" alt="">
      <div>
        <div class="stat-title" id="statTitle1">Total Appointment</div>
        <div class="stat-value" id="statTotalToday"><?php echo (int)$totalAppointmentsToday; ?></div>
        <div class="stat-sub" id="statSub1">As of today</div>
      </div>
    </article>

    <article class="stat">
      <img src="image/logo-upcoming_sched.png" alt="">
      <div>
        <div class="stat-title" id="statTitle2">Upcoming Appointment</div>
        <div class="stat-value" id="statUpcoming"><?php echo (int)$totalUpcoming; ?></div>
      </div>
    </article>
  </section>

  <!-- CARDS -->
  <section class="cards">
    <!-- LIST CARD -->
    <article class="card">
      <h2 id="listTitle">Today's Appointment</h2>

      <div class="header-grid">
        <div>Client</div>
        <div>Name</div>
        <div>Service</div>
        <div class="text-right">Time</div>
      </div>

      <div class="clients" id="clientsList">
        <?php if (empty($todaysAppointments)): ?>
          <div class="empty">No records.</div>
        <?php else: ?>
          <?php foreach ($todaysAppointments as $row): ?>
            <div class="row">
              <div class="cell client">
                <img src="image/logo-user.png" width="40" height="40" alt="">
              </div>

              <div class="cell name" title="<?php echo htmlspecialchars($row['full_name']); ?>">
                <?php echo htmlspecialchars($row['full_name']); ?>
              </div>

              <div class="cell service">
                <?php echo htmlspecialchars($row['service']); ?>
              </div>

              <div class="cell time">
                <?php
                  $t = $row['time_fmt'] !== '' ? $row['time_fmt'] : (string)$row['appointment_time'];
                  echo htmlspecialchars($t);
                ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </article>

    <!-- NEXT DETAILS -->
    <article class="card">
      <h2 id="nextTitle">Next Appointment Details</h2>

      <div id="nextDetailsWrap">
      <?php if ($secondDetails): ?>
        <div class="next-head">
          <img src="image/logo-user.png" alt="">
          <div class="next-name"><?php echo htmlspecialchars($secondDetails['name']); ?></div>
        </div>

        <div class="kv"><span>Service</span><b><?php echo htmlspecialchars($secondDetails['service']); ?></b></div>
        <div class="kv"><span>Date / Exact Time</span><b><?php echo htmlspecialchars(trim(($secondDetails['date'] ?? '').' • '.($secondDetails['time'] ?? ''))); ?></b></div>
        <div class="kv"><span>Contact Number</span><b><?php echo htmlspecialchars($secondDetails['contact']); ?></b></div>
      <?php else: ?>
        <div class="empty">No data.</div>
      <?php endif; ?>
      </div>
    </article>

    <!-- PIE CHART -->
    <article class="card">
      <h2>Service Summary <?php echo htmlspecialchars($monthLabel); ?></h2>

      <canvas id="eventSummaryChart" width="320" height="280" role="img" aria-label="Event summary pie chart"></canvas>

      <div class="legend">
        <span><i class="dot dot-funeral"></i> Funeral: <?php echo (int)$serviceCountsMonth['Funeral']; ?></span>
        <span><i class="dot dot-baptism"></i> Baptism: <?php echo (int)$serviceCountsMonth['Baptism']; ?></span>
        <span><i class="dot dot-others"></i> Dedication: <?php echo (int)$serviceCountsMonth['Others']; ?></span>
        <span><i class="dot dot-blessing"></i> Blessing: <?php echo (int)$serviceCountsMonth['Blessing']; ?></span>
        <span><i class="dot dot-wedding"></i> Wedding: <?php echo (int)$serviceCountsMonth['Wedding']; ?></span>
      </div>
    </article>
  </section>
</div>

<!-- ====== Floating Modal (Announcement) ====== -->
<div class="floating-modal-overlay" id="announcementOverlay" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="fmTitle">
  <div class="floating-modal" role="document">
    <div class="fm-header">
      <div class="fm-title" id="fmTitle"><i class="fas fa-bullhorn"></i> Announcement</div>
      <button class="btn secondary" id="closeAnnouncement" type="button" aria-label="Close"><i class="fas fa-times"></i> Close</button>
    </div>

    <div class="fm-body">
      <!-- Latest -->
      <div class="section-card">
        <div class="section-title" style="color: black;">Latest Announcement</div>

        <div class="fm-field" id="latestDisplayWrap">
          <label class="fm-label">Message</label>
          <div id="latestMsg" class="muted">Loading…</div>
        </div>

        <div class="fm-field" id="latestEditWrap" style="display:none;">
          <label class="fm-label" for="latestEditMsg">Edit Message</label>
          <textarea class="fm-textarea" id="latestEditMsg" placeholder="Edit the announcement…"></textarea>
          <div class="edit-row">
            <button class="btn primary" id="saveLatestEdit"><i class="fas fa-save"></i> Save Changes</button>
            <button class="btn secondary" id="cancelLatestEdit">Cancel</button>
          </div>
        </div>

        <div class="fm-field">
          <label class="fm-label">Status</label>
          <div id="latestStatusBadge"><span class="status-pill status-New">New</span></div>
        </div>
        <div class="muted" id="latestMeta"></div>

        <div style="margin-top:10px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
          <button class="btn ghost" id="openHistory"><i class="fas fa-history"></i> Previous Announcement</button>
          <button class="btn primary" id="editLatestBtn" style="display:none;"><i class="fas fa-edit"></i> Edit</button>
          <button class="btn warn" id="archiveLatestBtn" style="display:none;"><i class="fas fa-box-archive"></i>Archive</button>
        </div>
      </div>

      <div class="section-card">
        <div class="section-title" style="color: black;">Add New Announcement</div>
        <div class="fm-field">
          <label class="fm-label" for="announcementMsg">Message</label>
          <textarea class="fm-textarea" id="announcementMsg" placeholder="Type the announcement message…"></textarea>
        </div>

        <div class="fm-field hide-soft">
          <label class="fm-label" for="status">Status (forced to New)</label>
          <select class="disabled-soft" id="status">
            <option value="New" selected>New</option>
            <option value="Active">Active</option>
          </select>
        </div>

        <button class="btn primary" id="saveAnnouncement"><i class="fas fa-plus-circle"></i> Add New Announcement</button>
    </div>

    <div class="fm-footer">
      <button class="btn secondary" type="button" id="cancelAnnouncement">Close</button>
    </div>

    <!-- Inner Modal: History -->
    <div class="inner-overlay" id="historyOverlay">
      <div class="inner-modal" role="dialog" aria-modal="true" aria-labelledby="historyTitle">
        <div class="inner-header">
          <div class="inner-title" id="historyTitle"><i class="fas fa-history"></i> Previous Announcements</div>
          <button class="btn secondary" id="closeHistory"><i class="fas fa-times"></i> Close</button>
        </div>
        <div class="inner-body">
          <div class="history-list" id="historyList"></div>
        </div>
      </div>
    </div>
    <!-- End Inner Modal -->
  </div>
</div>
<div class="toast" id="toast"></div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
/* ============ Notifications ============ */
(function(){
  const bell = document.getElementById('notifBell');
  const dropdown = document.getElementById('notifDropdown');
  const badge = document.getElementById('notifBadge');
  const markRead = document.getElementById('notifMarkRead');

  if (!bell || !dropdown) return;

  const openDD = ()=> dropdown.setAttribute('aria-hidden','false');
  const closeDD = ()=> dropdown.setAttribute('aria-hidden','true');
  const isOpen = ()=> dropdown.getAttribute('aria-hidden') === 'false';

  bell.addEventListener('click', (e)=>{
    e.stopPropagation();
    isOpen() ? closeDD() : openDD();
  });

  document.addEventListener('click', (e)=>{
    if (!dropdown.contains(e.target) && !bell.contains(e.target)) closeDD();
  });

  document.addEventListener('keydown', (e)=>{ if (e.key==='Escape') closeDD(); });

  if (markRead) {
    markRead.addEventListener('click', async ()=>{
      try { await fetch('clear_notifications.php', { method:'POST', headers:{'Accept':'application/json'} }); } catch(e) {}
      if (badge) badge.style.display='none';
      const list = dropdown.querySelector('.notif-list');
      if (list) list.remove();
      const empty = document.createElement('div');
      empty.className = 'notif-empty';
      empty.innerHTML = '<img src="css/image/empty.png" alt="" aria-hidden="true"><div>No new notifications</div>';
      const footer = dropdown.querySelector('.notif-footer');
      dropdown.insertBefore(empty, footer || null);
    });
  }
})();

/* ============ Pie Chart ============ */
(function() {
  const el = document.getElementById('eventSummaryChart');
  if (!el) return;

  if (window.__eventSummaryChart) { try { window.__eventSummaryChart.destroy(); } catch(e){} }

  const labels  = <?php echo json_encode(array_keys($serviceCountsMonth)); ?>;
  const dataVals= <?php echo json_encode(array_values($serviceCountsMonth)); ?>;
  const colors  = ['#F2A720','#C587F2','#8ECDF0','#54A460','#60CDF5'];

  const ctx = el.getContext('2d');
  window.__eventSummaryChart = new Chart(ctx, {
    type: 'pie',
    data: { labels, datasets: [{ data: dataVals, backgroundColor: colors, borderWidth: 0 }] },
    options: {
      responsive: false,
      animation: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (c) => {
              const label = c.label || '';
              const val = c.parsed || 0;
              const total = c.dataset.data.reduce((a,b)=>a+b,0) || 1;
              const pct = ((val/total)*100).toFixed(0);
              return `${label}: ${val} (${pct}%)`;
            }
          }
        }
      }
    }
  });
})();

/* ============ Announcement Modal + History ============ */
(function(){
  const overlay = document.getElementById('announcementOverlay');
  if (!overlay) return;

  const openBtn = document.getElementById('openAddAnnouncement');
  const closeBtn = document.getElementById('closeAnnouncement');
  const cancelBtn = document.getElementById('cancelAnnouncement');
  const saveBtn = document.getElementById('saveAnnouncement');

  const latestDisplayWrap = document.getElementById('latestDisplayWrap');
  const latestEditWrap = document.getElementById('latestEditWrap');
  const latestMsgEl = document.getElementById('latestMsg');
  const latestEditMsg = document.getElementById('latestEditMsg');
  const editLatestBtn = document.getElementById('editLatestBtn');
  const archiveLatestBtn = document.getElementById('archiveLatestBtn');

  const latestStatusBadge = document.getElementById('latestStatusBadge');
  const latestMetaEl = document.getElementById('latestMeta');

  const msgEl = document.getElementById('announcementMsg');
  const toast = document.getElementById('toast');

  const historyOverlay = document.getElementById('historyOverlay');
  const openHistory = document.getElementById('openHistory');
  const closeHistory = document.getElementById('closeHistory');
  const historyList = document.getElementById('historyList');

  function showToast(text){
    if (!toast) return;
    toast.textContent = text;
    toast.classList.add('show');
    setTimeout(()=>toast.classList.remove('show'), 2200);
  }

  function open(){
    overlay.setAttribute('aria-hidden','false');
    loadLatest();
  }
  function close(){
    overlay.setAttribute('aria-hidden','true');
    if (msgEl) msgEl.value = '';
    if (historyOverlay) {
      historyOverlay.setAttribute('data-open','false');
      if (historyList) historyList.innerHTML = '';
    }
    if (latestEditWrap && latestDisplayWrap){
      latestEditWrap.style.display = 'none';
      latestDisplayWrap.style.display = '';
    }
  }

  async function post(data){
    const form = new FormData();
    for (const [k,v] of Object.entries(data)) form.append(k, v);
    const res = await fetch(location.pathname, { method:'POST', body:form });
    return res.json();
  }

  function setBadge(status){
    const s = (status || 'New').replace(/\s+/g,'');
    latestStatusBadge.innerHTML = `<span class="status-pill status-${s}">${status||'New'}</span>`;
  }

  async function loadLatest(){
    latestMsgEl.textContent = 'Loading…';
    latestMetaEl.textContent = '';
    setBadge('New');
    editLatestBtn.style.display = 'none';
    archiveLatestBtn.style.display = 'none';
    try{
      const json = await post({__action:'fetch_latest_announcement'});
      if (json.ok && json.latest){
        const msg = json.latest.announcementMsg || '(empty)';
        const status = json.latest.Status || 'New';
        latestMsgEl.textContent = msg;
        setBadge(status);
        const dt = json.latest.created_at ? new Date(json.latest.created_at.replace(' ','T')) : null;
        latestMetaEl.textContent = dt ? `Created: ${dt.toLocaleString()}` : '';
        if (status === 'New') {
          editLatestBtn.style.display = '';
          archiveLatestBtn.style.display = '';
        }
      } else {
        latestMsgEl.textContent = 'No announcements yet.';
        setBadge('New');
      }
    }catch(e){
      latestMsgEl.textContent = 'Failed to load.';
    }
  }

  async function addNew(){
    const msg = (msgEl.value || '').trim();
    if (!msg){ showToast('Please enter a message.'); msgEl?.focus(); return; }
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    try{
      const json = await post({__action:'create_announcement_and_archive_prev', announcementMsg: msg});
      if (json.ok){
        showToast('Announcement added. Previous set to Past.');
        msgEl.value = '';
        await loadLatest();
      } else {
        showToast(json.error || 'Failed to add.');
      }
    }catch(e){ showToast('Network error.'); }
    finally{
      saveBtn.disabled = false;
      saveBtn.innerHTML = '<i class="fas fa-plus-circle"></i> Add New Announcement';
    }
  }

  async function loadHistory(){
    if (!historyList) return;
    historyList.innerHTML = '<div class="history-item muted">Loading…</div>';
    try{
      const json = await post({__action:'fetch_all_announcements'});
      if (json.ok){
        if (!json.items || !json.items.length){
          historyList.innerHTML = '<div class="history-item muted">No data.</div>';
          return;
        }
        const frag = document.createDocumentFragment();
        json.items.forEach(it=>{
          const dt = it.created_at ? new Date(it.created_at.replace(' ','T')) : null;
          const div = document.createElement('div');
          const statusKey = (it.Status||'New').replace(/\s+/g,'');
          div.className = 'history-item';
          div.innerHTML = `
            <div style="display:flex; justify-content:space-between; gap:8px; align-items:center;">
              <div><span class="status-pill status-${statusKey}">${it.Status||'New'}</span></div>
              <div class="muted">${dt ? dt.toLocaleString() : ''}</div>
            </div>
            <div style="margin-top:6px; white-space:pre-wrap;">${(it.announcementMsg||'').replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))}</div>
          `;
          frag.appendChild(div);
        });
        historyList.innerHTML = '';
        historyList.appendChild(frag);
      } else {
        historyList.innerHTML = '<div class="history-item muted">Failed to load history.</div>';
      }
    }catch(e){
      historyList.innerHTML = '<div class="history-item muted">Network error.</div>';
    }
  }

  function startEditLatest(){
    latestEditMsg.value = (latestMsgEl.textContent || '').trim();
    latestDisplayWrap.style.display = 'none';
    latestEditWrap.style.display = '';
    latestEditMsg.focus();
  }
  function cancelEditLatest(){
    latestEditWrap.style.display = 'none';
    latestDisplayWrap.style.display = '';
  }
  async function saveEditLatest(){
    const newText = (latestEditMsg.value || '').trim();
    if (!newText){ showToast('Message cannot be empty.'); latestEditMsg.focus(); return; }
    const btn = document.getElementById('saveLatestEdit');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    try{
      const json = await post({__action:'update_latest_new_message', announcementMsg:newText});
      if (json.ok){
        showToast('Announcement updated.');
        await loadLatest();
        cancelEditLatest();
      } else {
        showToast(json.error || 'Failed to update.');
      }
    }catch(e){
      showToast('Network error.');
    }finally{
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
    }
  }

  async function archiveLatest(){
    const btn=document.getElementById('archiveLatestBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Archiving…';
    try{
      const json = await post({__action:'archive_latest_new'});
      if (json.ok){
        showToast('Current announcement archived (Past).');
        await loadLatest();
      } else {
        showToast(json.error || 'Failed to archive.');
      }
    }catch(e){
      showToast('Network error.');
    }finally{
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-box-archive"></i>Archive';
    }
  }

  openBtn?.addEventListener('click', open);
  closeBtn?.addEventListener('click', close);
  cancelBtn?.addEventListener('click', close);
  document.getElementById('saveAnnouncement')?.addEventListener('click', addNew);

  const openHistoryBtn = document.getElementById('openHistory');
  const closeHistoryBtn = document.getElementById('closeHistory');
  openHistoryBtn?.addEventListener('click', ()=>{ historyOverlay?.setAttribute('data-open','true'); loadHistory(); });
  closeHistoryBtn?.addEventListener('click', ()=>{ historyOverlay?.setAttribute('data-open','false'); });

  document.getElementById('editLatestBtn')?.addEventListener('click', startEditLatest);
  document.getElementById('cancelLatestEdit')?.addEventListener('click', cancelEditLatest);
  document.getElementById('saveLatestEdit')?.addEventListener('click', saveEditLatest);
  document.getElementById('archiveLatestBtn')?.addEventListener('click', archiveLatest);

  overlay?.addEventListener('click', (e)=>{ if (e.target === overlay) close(); });
  document.addEventListener('keydown', (e)=>{ if (e.key==='Escape' && overlay.getAttribute('aria-hidden')==='false') close(); });
})();

/* -------------- Swap between Appointments and Services -------------- */
(function(){
  const toggle = document.getElementById('modeToggle');
  if (!toggle) return;

  const t1 = document.getElementById('statTitle1');
  const t2 = document.getElementById('statTitle2');
  const listTitle = document.getElementById('listTitle');
  const nextTitle = document.getElementById('nextTitle');
  const statToday = document.getElementById('statTotalToday');
  const statUpcoming = document.getElementById('statUpcoming');
  const listWrap = document.getElementById('clientsList');
  const nextWrap = document.getElementById('nextDetailsWrap');

  function renderList(items){
    if (!items || !items.length) return '<div class="empty">No records.</div>';
    return items.map(row=>`
      <div class="row">
        <div class="cell client"><img src="image/logo-user.png" width="40" height="40" alt=""></div>
        <div class="cell name" title="${(row.full_name||'').replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))}">${(row.full_name||'')}</div>
        <div class="cell service">${(row.service||'')}</div>
        <div class="cell time">${row.time_fmt ? row.time_fmt : (row.appointment_time||'')}</div>
      </div>`).join('');
  }

  function renderNext(n, servicesMode){
    if (!n) return '<div class="empty">No data.</div>';
    const dateStr = n.appointment_date ? new Date(n.appointment_date.replace(' ','T')).toLocaleDateString() : (n.appointment_date||'');
    const t = n.time_fmt || n.appointment_time || '';
    return `
      <div class="next-head">
        <img src="image/logo-user.png" alt="">
        <div class="next-name">${(n.full_name||n.name||'')}</div>
      </div>
      <div class="kv"><span>Service</span><b>${(n.service||'')}</b></div>
      <div class="kv"><span>Date / Exact Time</span><b>${dateStr}${t?' • '+t:''}</b></div>
      ${servicesMode ? '' : (n.contact?`<div class="kv"><span>Contact Number</span><b>${n.contact}</b></div>`:'')}
    `;
  }

  async function fetchDash(mode){
    const fd = new FormData();
    if (mode === 'services'){
      fd.append('__action','fetch_scheduled_dashboard');
      fd.append('mode','today');
    } else {
      fd.append('__action','fetch_appointments_dashboard');
    }
    const res = await fetch(location.pathname, { method:'POST', body:fd });
    return res.json();
  }

  async function activate(btn){
    toggle.querySelectorAll('.pill').forEach(b=>{
      b.classList.remove('active');
      b.classList.toggle('secondary', true);
      b.setAttribute('aria-selected','false');
      b.setAttribute('aria-pressed','false');
    });
    btn.classList.add('active');
    btn.classList.remove('secondary');
    btn.setAttribute('aria-selected','true');
    btn.setAttribute('aria-pressed','true');

    const mode = btn.dataset.mode; // appointments | services

    if (mode === 'services') {
      t1.textContent = 'Total Schedule';
      t2.textContent = 'Upcoming Schedule';
      listTitle.textContent = "TODAY'S SCHEDULE";
      nextTitle.textContent = 'NEXT SCHEDULE DETAILS';
    } else {
      t1.textContent = 'Total Appointment';
      t2.textContent = 'Upcoming Appointment';
      listTitle.textContent = "TODAY'S APPOINTMENT";
      nextTitle.textContent = 'NEXT APPOINTMENT DETAILS';
    }

    try{
      const j = await fetchDash(mode);
      if (!j.ok) throw new Error('load failed');

      statToday.textContent = j.counts?.today ?? 0;
      statUpcoming.textContent = j.counts?.upcoming ?? 0;
      listWrap.innerHTML = renderList(j.list || []);
      nextWrap.innerHTML = renderNext(j.next || null, mode==='services');
    }catch(e){
      listWrap.innerHTML = '<div class="empty">Failed to load.</div>';
    }
  }

  toggle.addEventListener('click', e=>{
    const btn = e.target.closest('.pill'); if (!btn) return;
    activate(btn);
  });
})();
</script>
</body>
</html>
