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

  /* ---------------------- SMALL DB HELPERS ------------------- */
  if (!function_exists('table_has_column')) {
      function table_has_column(PDO $pdo, string $table, string $column): bool {
          try {
              $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
              $q->execute([':t'=>$table, ':c'=>$column]);
              return (int)$q->fetchColumn() > 0;
          } catch (Throwable $e) { return false; }
      }
  }

  /* Detect which column holds “Scheduled” for service_wedding */
  $WEDDING_STATUS_COL = 'service_status';
  try {
      if (table_has_column($pdo, 'service_wedding', 'service_status')) {
          $WEDDING_STATUS_COL = 'service_status';
      } elseif (table_has_column($pdo, 'service_wedding', 'status')) {
          $WEDDING_STATUS_COL = 'status';
      }
  } catch (Throwable $e) {}

  /* ---------------------- SQL HELPERS (DATE NORMALIZATION) ----------------- */
  if (!function_exists('sql_date_expr')) {
      function sql_date_expr(string $col): string {
          // Normalize common formats to DATE
          return "(CASE
              WHEN $col IS NULL OR TRIM($col)='' THEN NULL
              WHEN $col REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}' THEN DATE($col)
              WHEN $col REGEXP '^[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}' THEN STR_TO_DATE($col,'%m/%d/%Y')
              WHEN $col REGEXP '^[A-Za-z]{3,9} [0-9]{1,2}, [0-9]{4}' THEN STR_TO_DATE($col,'%M %e, %Y')
              ELSE DATE($col)
          END)";
      }
  }
  if (!function_exists('sql_date_fmt_ymd')) {
      function sql_date_fmt_ymd(string $col): string {
          return "DATE_FORMAT(" . sql_date_expr($col) . ", '%Y-%m-%d')";
      }
  }

  /* --------------- Common WHERE for Scheduled status (case/space safe) --------------- */
  if (!function_exists('sql_scheduled_where')) {
      function sql_scheduled_where(?string $alias = null): string {
          $prefix = $alias ? rtrim($alias, '.') . '.' : '';
          return "LOWER(TRIM({$prefix}service_status))='scheduled'";
      }
  }
  if (!function_exists('sql_scheduled_where_col')) {
      function sql_scheduled_where_col(?string $alias = null, string $col = 'service_status'): string {
          $prefix = $alias ? rtrim($alias, '.') . '.' : '';
          $col = trim($col, '`');
          return "LOWER(TRIM({$prefix}`{$col}`))='scheduled'";
      }
  }

  /* ---------------------- ADD: SCHEMA SAFETY ----------------- */
  try {
      $pdo->exec("ALTER TABLE `announcement`
                  ADD COLUMN IF NOT EXISTS `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
  } catch (Throwable $e) {}

  /* ===================== ADD: AUDIT TRIGGER ================== */
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
      // silently ignore
  }

  /* ======================= ADD: HELPERS (suffix) ============== */
  if (!function_exists('audit_set_suffix')) {
      function audit_set_suffix(PDO $pdo, ?string $suffix): void {
          try {
              if ($suffix === null || trim($suffix) === '') {
                  $pdo->exec("SET @audit_notes_suffix := NULL");
              } else {
                  $pdo->exec("SET @audit_notes_suffix := " . $pdo->quote(mb_substr($suffix, 0, 900)));
              }
          } catch (Throwable $e) { /* ignore */ }
      }
  }
  if (!function_exists('audit_clear_suffix')) {
      function audit_clear_suffix(PDO $pdo): void { audit_set_suffix($pdo, null); }
  }
  if (!function_exists('audit_change_phrase')) {
      function audit_change_phrase(string $field, $old, $new): string {
          $fmt = function($v) {
              if ($v === null || $v === '') return '""';
              $v = preg_replace('/\s+/u', ' ', (string)$v);
              return '"' . mb_substr($v, 0, 300) . (mb_strlen($v) > 300 ? '…' : '') . '"';
          };
          if ($old === $new) return '';
          if ($old === null || $old === '') return "{$field} added: " . $fmt($new);
          if ($new === null || $new === '') return "{$field} deleted: " . $fmt($old);
          return "{$field}: " . $fmt($old) . " \u2192 " . $fmt($new);
      }
  }

  /* ======================= AUDIT HELPER (PDO -> audit_trail) ============== */
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
                  if ($k === 'HTTP_X_FORWARDED_FOR') $v = explode(',', $v)[0] ?? $v;
                  return substr($v, 0, 45);
              }
          }
          return '';
      }
  }
  if (!function_exists('audit_log')) {
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
                  ':user_agent'     => substr((string)$_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                  ':notes'          => $params['notes'] ?? null,
              ]);
          } catch (Throwable $e) {
              // avoid echoing
          } finally {
              audit_clear_suffix($pdo);
          }
      }
  }

  /* ------------------- WEEK HELPERS -------------------------- */
  function week_bounds(string $refDate = 'today'): array {
      $ts = strtotime($refDate);
      $dow = (int)date('N', $ts);
      $monday = strtotime("-" . ($dow - 1) . " days", $ts);
      $sunday = strtotime("+" . (7 - $dow) . " days", $ts);
      return [date('Y-m-d', $monday), date('Y-m-d', $sunday)];
  }
  function next_week_bounds(): array {
      [$m,$s] = week_bounds('today');
      $start = date('Y-m-d', strtotime($m.' +7 days'));
      $end   = date('Y-m-d', strtotime($s.' +7 days'));
      return [$start,$end];
  }

  /* ================== NOTIFICATIONS HELPERS (DB-backed) ================== */
  $CURRENT_USER_TYPE = 'admin';
  $CURRENT_USER_ID   = (int)($_SESSION['admin_id'] ?? 0);

  if (!function_exists('text_snippet')) {
      function text_snippet(?string $txt, int $limit = 110): string {
          $s = trim(preg_replace('/\s+/u', ' ', (string)$txt));
          if ($s === '') return '';
          if (mb_strlen($s) <= $limit) return $s;
          return mb_substr($s, 0, $limit - 1) . '…';
      }
  }

  /* NEW: Extract the Submitter name from notifications.body */
  if (!function_exists('parse_submitter_from_body')) {
      function parse_submitter_from_body(?string $body): string {
          if (!$body) return '';
          if (preg_match('/Submitter\s*:\s*([^\r\n|]+)/i', $body, $m)) {
              return trim($m[1]);
          }
          if (preg_match('/Submitted\s+by\s*:\s*([^\r\n|]+)/i', $body, $m2)) {
              return trim($m2[1]);
          }
          return '';
      }
  }

  if (!function_exists('notif_unread_count')) {
      function notif_unread_count(PDO $pdo, string $userType, int $userId): int {
          try {
              $q = $pdo->prepare("SELECT COUNT(*) FROM notification_recipients 
                                  WHERE user_type = :t AND user_id = :id AND status = 'unread'");
              $q->execute([':t'=>$userType, ':id'=>$userId]);
              return (int)$q->fetchColumn();
          } catch (Throwable $e) { return 0; }
      }
  }

  if (!function_exists('notif_fetch_latest')) {
      function notif_fetch_latest(PDO $pdo, string $userType, int $userId, int $limit = 20): array {
          try {
              $sql = "SELECT 
                          nr.id             AS recipient_id,
                          nr.status         AS recipient_status,
                          nr.delivered_at   AS delivered_at,
                          nr.read_at        AS read_at,
                          n.id              AS notification_id,
                          n.title           AS title,
                          n.body            AS body,
                          n.created_by_type AS created_by_type,
                          n.created_by_id   AS created_by_id,
                          n.created_at      AS created_at
                      FROM notification_recipients nr
                      INNER JOIN notifications n ON n.id = nr.notification_id
                      WHERE nr.user_type = :t AND nr.user_id = :id
                        AND nr.status <> 'clear'
                      ORDER BY COALESCE(nr.delivered_at, n.created_at) DESC
                      LIMIT :lim";
              $stmt = $pdo->prepare($sql);
              $stmt->bindValue(':t',  $userType, PDO::PARAM_STR);
              $stmt->bindValue(':id', $userId,   PDO::PARAM_INT);
              $stmt->bindValue(':lim', $limit,   PDO::PARAM_INT);
              $stmt->execute();
              $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
              foreach ($rows as &$r) {
                  $ts = $r['delivered_at'] ?: $r['created_at'];
                  $r['dt_fmt'] = $ts ? date('M d, Y • g:i A', strtotime($ts)) : '';
                  $r['is_unread'] = strtolower($r['recipient_status'] ?? '') === 'unread';

                  // concise fields for display
                  $r['title_short'] = text_snippet($r['title'] ?? '', 100);
                  $r['body_short']  = text_snippet($r['body']  ?? '', 160);

                  // NEW: parse submitter from body, fallback to created_by_type label
                  $submitter = parse_submitter_from_body($r['body'] ?? '');
                  $r['submitter'] = $submitter !== '' ? $submitter : (($r['created_by_type'] ?? '') ? ucfirst((string)$r['created_by_type']) : 'System');
              }
              return $rows;
          } catch (Throwable $e) { return []; }
      }
  }

  /* ---------------------- AJAX API ---------------------- */
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__action'])) {
      header('Content-Type: application/json; charset=utf-8');

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

              $oldStatus = null;
              try {
                  $q = $pdo->prepare("SELECT Status FROM announcement WHERE announcementId=:id");
                  $q->execute([':id'=>$id]);
                  $oldStatus = $q->fetchColumn();
              } catch (Throwable $e) {}

              $stmt = $pdo->prepare("UPDATE announcement SET Status=:s WHERE announcementId=:id");
              $stmt->execute([':s'=>$status, ':id'=>$id]);

              $suffix = audit_change_phrase('Status', $oldStatus, $status);
              audit_set_suffix($pdo, $suffix);

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



if (!function_exists('send_announcement_email_broadcast')) {
    /**
     * Send the latest announcement message to all registered email addresses.
     *
     * NOTE:
     * - This implementation now uses `individual_table.individual_email_address` for recipients.
     * - Adjust the query below if your email addresses live in a different table/column.
     */
    function send_announcement_email_broadcast(PDO $pdo, string $message): void
    {
        try {
            // Collect all unique, non-empty email addresses
            $sql = "SELECT DISTINCT `individual_email_address` 
                    FROM `individual_table` 
                    WHERE `individual_email_address` IS NOT NULL 
                      AND TRIM(`individual_email_address`) <> ''";
            $stmt = $pdo->query($sql);
            $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            // If we cannot fetch emails, just skip sending
            return;
        }

        if (!$emails || !is_array($emails)) {
            return;
        }

        // Build email content
        $subject = 'New Announcement from HTCCC';
        $body    = $message . "\n\nThis is an automated announcement, please do not reply directly to this email.";

        // Try to use the currently logged-in admin as the sender; otherwise fall back to a generic address
        $fromAddress = $_SESSION['admin_emailaddress'] ?? ($_SESSION['admin_email'] ?? 'no-reply@htccc.local');
        $fromName    = $_SESSION['admin_username']     ?? ($_SESSION['admin_user']  ?? 'HTCCC Admin');

        // Basic headers for plain‑text email
        $headers  = 'From: ' . $fromName . ' <' . $fromAddress . '>' . "\r\n";
        $headers .= 'Reply-To: ' . $fromAddress . "\r\n";
        $headers .= 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";

        foreach ($emails as $to) {
            if (!is_string($to) || trim($to) === '') {
                continue;
            }

            // Attempt to send; if it fails, write to the PHP error log so you can debug mail configuration problems
            $ok = mail($to, $subject, $body, $headers);
            if (!$ok) {
                $err = error_get_last();
                error_log('[HTCCC Announcement Mail] Failed sending to ' . $to . ' - ' . ($err['message'] ?? 'unknown error'));
            }
        }
    }
}

if ($_POST['__action'] === 'create_announcement') {
    try {
        $msg = trim((string)($_POST['announcementMsg'] ?? ''));
        if ($msg === '') {
            echo json_encode(['ok' => false, 'error' => 'Announcement message is required.']);
            exit;
        }

        // BEGIN: harden against duplicates
        $pdo->beginTransaction();

        // Archive any existing "New" to avoid duplicate "New" rows
        try {
            $stmtArch = $pdo->prepare("UPDATE `announcement` SET `Status`='Past' WHERE LOWER(TRIM(`Status`))='new'");
            $stmtArch->execute();
        } catch (Throwable $e) { /* ignore archiving failure; continue */ }

        // Insert the new record
        $stmt = $pdo->prepare("INSERT INTO `announcement` (`announcementMsg`, `Status`) VALUES (:msg, 'New')");
        $stmt->execute([':msg' => $msg]);
        $newId = (int)$pdo->lastInsertId();

        // Audit trail stays the same
        audit_set_suffix($pdo, audit_change_phrase('Message', null, $msg));
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

        // Send this announcement to all registered email addresses via email
        send_announcement_email_broadcast($pdo, $msg);


        echo json_encode(['ok' => true, 'id' => $newId]);
    } catch (Throwable $e) {
        try { $pdo->rollBack(); } catch (Throwable $ee) {}
        echo json_encode(['ok' => false, 'error' => 'Failed to save announcement.']);
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

              audit_set_suffix($pdo, audit_change_phrase('Message', null, $msg));

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

              audit_set_suffix($pdo, audit_change_phrase('Message', $oldMsg, $newMsg));

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

              audit_set_suffix($pdo, audit_change_phrase('Status', $oldStatus, 'Past'));

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

      /* ========== NEW: FETCH INDIVIDUALS BY GENDER (for modal) ========== */
      if ($_POST['__action'] === 'fetch_individuals_by_gender') {
          $gender = strtolower(trim((string)$_POST['gender'] ?? ''));
          if ($gender !== 'male' && $gender !== 'female') {
              echo json_encode(['ok'=>false,'error'=>'Invalid gender.']);
              exit;
          }

          $in = $gender === 'male'
              ? "'male','man','men','m'"
              : "'female','woman','women','f'";

          try {
              $sql = "
                  SELECT 
                      individual_id,
                      TRIM(CONCAT_WS(' ', individual_firstname, individual_middlename, individual_lastname)) AS full_name,
                      individual_gender
                  FROM individual_table
                  WHERE LOWER(TRIM(individual_gender)) IN ($in)
                  ORDER BY individual_lastname, individual_firstname
                  LIMIT 300
              ";
              $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
              echo json_encode(['ok'=>true,'items'=>$rows]);
          } catch (Throwable $e) {
              echo json_encode(['ok'=>false,'error'=>'Failed to fetch individuals.']);
          }
          exit;
      }

      /* ================== NEW: WEEKLY SERVICES API ================= */
      if (isset($_POST['__action']) && $_POST['__action'] === 'fetch_weekly_services') {
          $mode = $_POST['mode'] ?? 'this_week';
          if ($mode === 'next_week') {
              [$start,$end] = next_week_bounds();
          } else {
              [$start,$end] = week_bounds('today');
          }

          $tables = [
              'Baptism'        => ['table'=>'service_baptism',   'pk'=>'baptism_id',  'date_col'=>'service_date', 'time_col'=>'service_time'],
              'Dedication'     => ['table'=>'service_dedication','pk'=>'dedicationId','date_col'=>'service_date', 'time_col'=>'service_time'],
              'Funeral'        => ['table'=>'service_funeral',   'pk'=>'funeral_id',  'date_col'=>'service_date', 'time_col'=>'service_time'],
              'House Blessing' => ['table'=>'service_house',     'pk'=>'house_id',    'date_col'=>'service_date', 'time_col'=>'service_time'],
              'Wedding'        => ['table'=>'service_wedding',   'pk'=>'wedding_id',  'date_col'=>'service_date', 'time_col'=>'service_time'],
          ];

          $counts = [];
          $items  = [];
          $bySvc  = [];

          foreach ($tables as $svc => $meta) {
              $tbl = $meta['table'];
              $dateCol = $meta['date_col'];
              $timeCol = $meta['time_col'];
              $pk  = $meta['pk'];

              $dateExpr = sql_date_expr($dateCol);
              $dateYmd  = "DATE_FORMAT(" . sql_date_expr($dateCol) . ", '%Y-%m-%d')";

              if ($tbl === 'service_baptism') {
                  $sql = "SELECT $pk AS pk, 
                                TRIM(CONCAT_WS(' ', baptized_firstname, baptized_middlename, baptized_lastname, baptized_ext)) AS full_name,
                                guardian_contactnum AS contact,
                                $dateYmd AS sdate, $timeCol AS stime, '$tbl' AS tbl
                          FROM $tbl
                          WHERE $dateExpr BETWEEN :s AND :e
                            AND ".sql_scheduled_where()."";
              } elseif ($tbl === 'service_dedication') {
                  $sql = "SELECT $pk AS pk,
                                TRIM(CONCAT_WS(' ', child_firstname, child_middlename, child_lastname, child_ext)) AS full_name,
                                contact_number AS contact,
                                $dateYmd AS sdate, $timeCol AS stime, '$tbl' AS tbl
                          FROM $tbl
                          WHERE $dateExpr BETWEEN :s AND :e
                            AND ".sql_scheduled_where()."";
              } elseif ($tbl === 'service_funeral') {
                  $sql = "SELECT $pk AS pk,
                                TRIM(CONCAT_WS(' ', deceased_firstname, deceased_middlename, deceased_lastname, deceased_ext)) AS full_name,
                                contact_number AS contact,
                                $dateYmd AS sdate, $timeCol AS stime, '$tbl' AS tbl
                          FROM $tbl
                          WHERE $dateExpr BETWEEN :s AND :e
                            AND ".sql_scheduled_where()."";
              } elseif ($tbl === 'service_house') {
                  $sql = "SELECT $pk AS pk,
                                TRIM(CONCAT_WS(' ', owner_firstname, owner_middlename, owner_lastname, owner_ext)) AS full_name,
                                contact_number AS contact,
                                $dateYmd AS sdate, $timeCol AS stime, '$tbl' AS tbl
                          FROM $tbl
                          WHERE $dateExpr BETWEEN :s AND :e
                            AND ".sql_scheduled_where()."";
              } else { // wedding
                  $sql = "SELECT $pk AS pk,
                                TRIM(CONCAT_WS(' ', client_firstname, client_middlename, client_lastname, client_ext)) AS full_name,
                                contact_number AS contact,
                                $dateYmd AS sdate, $timeCol AS stime, '$tbl' AS tbl
                          FROM $tbl
                          WHERE $dateExpr BETWEEN :s AND :e
                            AND ".sql_scheduled_where_col(null, $WEDDING_STATUS_COL)."";
              }

              try {
                  $q = $pdo->prepare($sql);
                  $q->execute([':s'=>$start, ':e'=>$end]);
                  $rows = $q->fetchAll(PDO::FETCH_ASSOC);
              } catch (Throwable $e) {
                  $rows = [];
              }

              $norm = [];
              foreach ($rows as $r) {
                  $date = $r['sdate'];
                  $time = $r['stime'];
                  $dtSort = trim($date . ' ' . ($time !== '' ? $time : '23:59'));
                  $norm[] = [
                      'service'   => $svc,
                      'table'     => $tbl,
                      'pk'        => $r['pk'],
                      'full_name' => trim((string)$r['full_name']) ?: 'Unknown',
                      'contact'   => trim((string)($r['contact'] ?? '')) ?: '—',
                      'date'      => $date,
                      'time'      => $time,
                      'sort_key'  => $dtSort
                  ];
              }

              usort($norm, fn($a,$b)=> strcmp($a['sort_key'], $b['sort_key']));

              $counts[$svc] = count($norm);
              $items = array_merge($items, $norm);
              $bySvc[$svc] = $norm;
          }

          usort($items, fn($a,$b)=> strcmp($a['sort_key'], $b['sort_key']));

          $total = array_sum($counts);
          echo json_encode(['ok'=>true, 'range'=>['start'=>$start,'end'=>$end], 'counts'=>$counts, 'total'=>$total, 'items'=>$items, 'by_service'=>$bySvc]);
          exit;
      }

      /* ========== NEW: FETCH SERVICE DETAIL FOR WEEKLY/TODAY MODALS ========== */
      if ($_POST['__action'] === 'fetch_service_detail') {
          $table = $_POST['table'] ?? '';
          $id    = (int)($_POST['id'] ?? 0);

          if ($id <= 0) {
              echo json_encode(['ok'=>false,'error'=>'Invalid ID.']);
              exit;
          }

          try {
              $data = null;

              if ($table === 'service_dedication') {
                  $stmt = $pdo->prepare("
                      SELECT 
                          dedicationId,
                          child_firstname,
                          child_lastname,
                          child_middlename,
                          child_ext,
                          guardian_lastname,
                          guardian_firstname,
                          guardian_middlename,
                          guardian_ext,
                          contact_number,
                          email_address,
                          service_date,
                          service_time
                      FROM service_dedication
                      WHERE dedicationId = :id
                      LIMIT 1
                  ");
                  $stmt->execute([':id'=>$id]);
                  $data = $stmt->fetch(PDO::FETCH_ASSOC);
              } elseif ($table === 'service_baptism') {
                  $stmt = $pdo->prepare("
                      SELECT 
                          baptism_id,
                          baptized_lastname,
                          baptized_firstname,
                          baptized_middlename,
                          baptized_ext,
                          guardian_lastname,
                          guardian_firstname,
                          guardian_middlename,
                          guardian_ext,
                          guardian_contactnum,
                          contact_number,
                          email_address,
                          service_date,
                          service_time
                      FROM service_baptism
                      WHERE baptism_id = :id
                      LIMIT 1
                  ");
                  $stmt->execute([':id'=>$id]);
                  $data = $stmt->fetch(PDO::FETCH_ASSOC);
              } elseif ($table === 'service_funeral') {
                  $stmt = $pdo->prepare("
                      SELECT 
                          f.funeral_id,
                          f.deceased_lastname,
                          f.deceased_firstname,
                          f.deceased_middlename,
                          f.deceased_ext,
                          f.home_address,
                          f.funeral_date,
                          f.service_date,
                          f.service_time,
                          i.individual_lastname,
                          i.individual_firstname,
                          i.individual_middlename,
                          i.individual_ext,
                          f.contact_number,
                          f.email_address
                      FROM service_funeral f
                      LEFT JOIN individual_table i ON i.individual_id = f.individual_id
                      WHERE f.funeral_id = :id
                      LIMIT 1
                  ");
                  $stmt->execute([':id'=>$id]);
                  $data = $stmt->fetch(PDO::FETCH_ASSOC);
              } elseif ($table === 'service_house') {
                  $stmt = $pdo->prepare("
                      SELECT 
                          house_id,
                          owner_lastname,
                          owner_firstname,
                          owner_middlename,
                          owner_ext,
                          contact_number,
                          email_address,
                          home_address,
                          service_date,
                          service_time
                      FROM service_house
                      WHERE house_id = :id
                      LIMIT 1
                  ");
                  $stmt->execute([':id'=>$id]);
                  $data = $stmt->fetch(PDO::FETCH_ASSOC);
              } elseif ($table === 'service_wedding') {
                  // Respect the detected wedding status column
                  $col = preg_replace('/[^A-Za-z0-9_`]/', '', $WEDDING_STATUS_COL);
                  $stmt = $pdo->prepare("
                      SELECT 
                          wedding_id,
                          bride_lastname,
                          bride_firstname,
                          bride_middlename,
                          bride_extension,
                          contact_number,
                          email_address,
                          groom_lastname,
                          groom_firstname,
                          groom_middlename,
                          groom_extension,
                          appointment_date,
                          appointment_time,
                          service_date,
                          service_time,
                          `$col` AS status
                      FROM service_wedding
                      WHERE wedding_id = :id
                      LIMIT 1
                  ");
                  $stmt->execute([':id'=>$id]);
                  $data = $stmt->fetch(PDO::FETCH_ASSOC);
              } else {
                  echo json_encode(['ok'=>false,'error'=>'Unknown service table.']);
                  exit;
              }

              if (!$data) {
                  echo json_encode(['ok'=>false,'error'=>'Record not found.']);
                  exit;
              }

              echo json_encode(['ok'=>true,'data'=>$data]);
          } catch (Throwable $e) {
              echo json_encode(['ok'=>false,'error'=>'Failed to fetch details.']);
          }
          exit;
      }

      /* ================== SERVICES DASHBOARD (today/upcoming/next) ================= */
      if ($_POST['__action'] === 'fetch_scheduled_dashboard') {
          $mode  = $_POST['mode'] ?? 'today';
          $today = date('Y-m-d');

          $tables = ['service_baptism','service_dedication','service_funeral','service_house','service_wedding'];

          $countToday = 0;
          foreach ($tables as $t) {
              try {
                  $dateExpr = sql_date_expr('service_date');
                  $q = $pdo->prepare("SELECT COUNT(*) AS c FROM `$t` 
                                      WHERE ".($t==='service_wedding'
                                              ? sql_scheduled_where_col(null, $WEDDING_STATUS_COL)
                                              : sql_scheduled_where())." AND $dateExpr = :d");
                  $q->execute([':d'=>$today]);
                  $countToday += (int)($q->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
              } catch (Throwable $e) {}
          }

          $countUpcoming = 0;
          foreach ($tables as $t) {
              try {
                  $dateExpr = sql_date_expr('service_date');
                  $q = $pdo->prepare("SELECT COUNT(*) AS c FROM `$t` 
                                      WHERE ".($t==='service_wedding'
                                              ? sql_scheduled_where_col(null, $WEDDING_STATUS_COL)
                                              : sql_scheduled_where())." AND $dateExpr > :d");
                  $q->execute([':d'=>$today]);
                  $countUpcoming += (int)($q->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
              } catch (Throwable $e) {}
          }

          $list = [];
          $next = null;

          try {
              $base = "
              SELECT t.individual_id, t.full_name, t.service, t.service_date, t.service_time
              FROM (
                  SELECT b.individual_id,
                        TRIM(CONCAT_WS(' ',
                            b.baptized_firstname,
                            b.baptized_middlename,
                            b.baptized_lastname,
                            b.baptized_ext
                        )) AS full_name,
                        CAST('Baptism' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
                        b.service_date,
                        CONVERT(b.service_time USING utf8mb4) COLLATE utf8mb4_general_ci AS service_time
                  FROM service_baptism b
                  WHERE ".sql_scheduled_where('b')."

                  UNION ALL
                  SELECT d.individual_id,
                        TRIM(CONCAT_WS(' ',
                            d.child_firstname,
                            d.child_middlename,
                            d.child_lastname,
                            d.child_ext
                        )) AS full_name,
                        CAST('Dedication' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
                        d.service_date,
                        CONVERT(d.service_time USING utf8mb4) COLLATE utf8mb4_general_ci AS service_time
                  FROM service_dedication d
                  WHERE ".sql_scheduled_where('d')."

                  UNION ALL
                  SELECT f.individual_id,
                        TRIM(CONCAT_WS(' ',
                            f.deceased_firstname,
                            f.deceased_middlename,
                            f.deceased_lastname,
                            f.deceased_ext
                        )) AS full_name,
                        CAST('Funeral' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
                        f.service_date,
                        CONVERT(f.service_time USING utf8mb4) COLLATE utf8mb4_general_ci AS service_time
                  FROM service_funeral f
                  WHERE ".sql_scheduled_where('f')."

                  UNION ALL
                  SELECT h.individual_id,
                        TRIM(CONCAT_WS(' ',
                            h.owner_firstname,
                            h.owner_middlename,
                            h.owner_lastname,
                            h.owner_ext
                        )) AS full_name,
                        CAST('House Blessing' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
                        h.service_date,
                        CONVERT(h.service_time USING utf8mb4) COLLATE utf8mb4_general_ci AS service_time
                  FROM service_house h
                  WHERE ".sql_scheduled_where('h')."

                  UNION ALL
                  SELECT w.individual_id,
                        TRIM(CONCAT_WS(' ', w.client_firstname, w.client_middlename, w.client_lastname, w.client_ext)) AS full_name,
                        CAST('Wedding' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS service,
                        w.service_date,
                        CONVERT(w.service_time USING utf8mb4) COLLATE utf8mb4_general_ci AS service_time
                  FROM service_wedding w
                  WHERE ".sql_scheduled_where_col('w', $WEDDING_STATUS_COL)."
              ) t
              /**ORDERLIMIT**/
              ";

              if ($mode === 'today') {
                  $cond = " AND " . sql_date_expr('service_date') . " = :today ";
                  $sql = str_replace(['WHERE '.sql_scheduled_where('b'),
                                      'WHERE '.sql_scheduled_where('d'),
                                      'WHERE '.sql_scheduled_where('f'),
                                      'WHERE '.sql_scheduled_where('h'),
                                      'WHERE '.sql_scheduled_where_col('w', $WEDDING_STATUS_COL)],
                                    [ "WHERE ".sql_scheduled_where('b').$cond,
                                      "WHERE ".sql_scheduled_where('d').$cond,
                                      "WHERE ".sql_scheduled_where('f').$cond,
                                      "WHERE ".sql_scheduled_where('h').$cond,
                                      "WHERE ".sql_scheduled_where_col('w', $WEDDING_STATUS_COL).$cond ], $base);
                  $sql = str_replace('/**ORDERLIMIT**/', "ORDER BY t.service_date ASC, COALESCE(STR_TO_DATE(t.service_time,'%h:%i %p'), STR_TO_DATE(t.service_time,'%H:%i'),'9999-12-31 23:59:59') ASC LIMIT 50", $sql);
                  $stmt = $pdo->prepare($sql);
                  $stmt->execute([':today'=>$today]);
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
                  $cond = " AND " . sql_date_expr('service_date') . " > :today ";
                  $sql = str_replace(['WHERE '.sql_scheduled_where('b'),
                                      'WHERE '.sql_scheduled_where('d'),
                                      'WHERE '.sql_scheduled_where('f'),
                                      'WHERE '.sql_scheduled_where('h'),
                                      'WHERE '.sql_scheduled_where_col('w', $WEDDING_STATUS_COL)],
                                    [ "WHERE ".sql_scheduled_where('b').$cond,
                                      "WHERE ".sql_scheduled_where('d').$cond,
                                      "WHERE ".sql_scheduled_where('f').$cond,
                                      "WHERE ".sql_scheduled_where('h').$cond,
                                      "WHERE ".sql_scheduled_where_col('w', $WEDDING_STATUS_COL).$cond ], $base);
                  $sql = str_replace('/**ORDERLIMIT**/', "ORDER BY t.service_date ASC, COALESCE(STR_TO_DATE(t.service_time,'%h:%i %p'), STR_TO_DATE(t.service_time,'%H:%i'),'9999-12-31 23:59:59') ASC LIMIT 50", $sql);
                  $stmt = $pdo->prepare($sql);
                  $stmt->execute([':today'=>$today]);
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
              } else {
                  $cond = " AND " . sql_date_expr('service_date') . " >= :today ";
                  $sql = str_replace(['WHERE '.sql_scheduled_where('b'),
                                      'WHERE '.sql_scheduled_where('d'),
                                      'WHERE '.sql_scheduled_where('f'),
                                      'WHERE '.sql_scheduled_where('h'),
                                      'WHERE '.sql_scheduled_where_col('w', $WEDDING_STATUS_COL)],
                                    [ "WHERE ".sql_scheduled_where('b').$cond,
                                      "WHERE ".sql_scheduled_where('d').$cond,
                                      "WHERE ".sql_scheduled_where('f').$cond,
                                      "WHERE ".sql_scheduled_where('h').$cond,
                                      "WHERE ".sql_scheduled_where_col('w', $WEDDING_STATUS_COL).$cond ], $base);
                  $sql = str_replace('/**ORDERLIMIT**/', "ORDER BY t.service_date ASC, COALESCE(STR_TO_DATE(t.service_time,'%h:%i %p'), STR_TO_DATE(t.service_time,'%H:%i'),'9999-12-31 23:59:59') ASC LIMIT 1", $sql);
                  $stmt = $pdo->prepare($sql);
                  $stmt->execute([':today'=>$today]);
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

      /* =================== NOTIFICATIONS API (new) =================== */
      if ($_POST['__action'] === 'notif_mark_all_read') {
          try {
              $t = $CURRENT_USER_TYPE;
              $i = $CURRENT_USER_ID;
              if ($i <= 0) { echo json_encode(['ok'=>false,'error'=>'No user context.']); exit; }
              $stmt = $pdo->prepare("UPDATE notification_recipients 
                                    SET status='read', read_at = COALESCE(read_at, NOW())
                                    WHERE user_type = :t AND user_id = :id AND status='unread'");
              $stmt->execute([':t'=>$t, ':id'=>$i]);
              echo json_encode(['ok'=>true, 'updated'=>$stmt->rowCount()]);
          } catch (Throwable $e) {
              echo json_encode(['ok'=>false, 'error'=>'Failed to mark as read.']);
          }
          exit;
      }

      /* NEW: CLEAR ALL NOTIFICATIONS for current user */
      if ($_POST['__action'] === 'notif_clear_all') {
          try {
              $t = $CURRENT_USER_TYPE;
              $i = $CURRENT_USER_ID;
              if ($i <= 0) { echo json_encode(['ok'=>false,'error'=>'No user context.']); exit; }
              $stmt = $pdo->prepare("UPDATE notification_recipients
                                    SET status='clear', read_at = COALESCE(read_at, NOW())
                                    WHERE user_type = :t AND user_id = :id AND status <> 'clear'");
              $stmt->execute([':t'=>$t, ':id'=>$i]);
              echo json_encode(['ok'=>true, 'updated'=>$stmt->rowCount()]);
          } catch (Throwable $e) {
              echo json_encode(['ok'=>false, 'error'=>'Failed to clear notifications.']);
          }
          exit;
      }

      if ($_POST['__action'] === 'notif_fetch') {
          try {
              $t = $CURRENT_USER_TYPE;
              $i = $CURRENT_USER_ID;
              if ($i <= 0) { echo json_encode(['ok'=>false,'error'=>'No user context.']); exit; }
              $items = notif_fetch_latest($pdo, $t, $i, 20);
              $unread = notif_unread_count($pdo, $t, $i);
              echo json_encode(['ok'=>true, 'items'=>$items, 'unread_count'=>$unread]);
          } catch (Throwable $e) {
              echo json_encode(['ok'=>false, 'error'=>'Failed to load notifications.']);
          }
          exit;
      }

      echo json_encode(['ok'=>false, 'error'=>'Unknown action']);
      exit;
  }

  /* ----------------------- CSS VERSION ----------------------- */
  $cssServerPath = $_SERVER['DOCUMENT_ROOT'] . '/HTCCC-SYSTEM/css/secretary_dashboard.css';
  $cssVer = file_exists($cssServerPath) ? filemtime($cssServerPath) : time();

  /* --------------------- SUMMARY COUNTERS (kept) ------------- */
  $totalIndividuals = 0; $totalMale = 0; $totalFemale = 0;
  try {
      $stmt = $pdo->query("
          SELECT LOWER(TRIM(individual_gender)) AS g, COUNT(*) AS c
          FROM individual_table
          GROUP BY LOWER(TRIM(individual_gender))
      ");
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
          $g = $row['g']; $c = (int)($row['c'] ?? 0);
          if (in_array($g, ['male','man','men'], true)) $totalMale += $c;
          elseif (in_array($g, ['female','woman','women'], true)) $totalFemale += $c;
      }
      $totalIndividuals = $totalMale + $totalFemale;
  } catch (Throwable $e) {
      try {
          $stmt = $pdo->query("SELECT COUNT(*) AS total FROM individual_table");
          $totalIndividuals = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
      } catch (Throwable $e2) {}
  }

  /* -------------------- TODAY LISTS -------------------- */
  $today = date('Y-m-d');

  /* 1) Today Service Schedule (All Services) */
  $todaysServiceList = [];
  try {
      $defs = [
          'Baptism' => [
              'table'         => 'service_baptism',
              'pk'            => 'baptism_id',
              'date_col'      => 'service_date',
              'time_col'      => 'service_time',
              'name_sql'      => "TRIM(CONCAT_WS(' ', baptized_firstname, baptized_middlename, baptized_lastname, baptized_ext))",
              'individual_col'=> 'individual_id',
              'status_col'    => 'service_status'
          ],
          'Dedication' => [
              'table'         => 'service_dedication',
              'pk'            => 'dedicationId',
              'date_col'      => 'service_date',
              'time_col'      => 'service_time',
              'name_sql'      => "TRIM(CONCAT_WS(' ', child_firstname, child_middlename, child_lastname, child_ext))",
              'individual_col'=> 'individual_id',
              'status_col'    => 'service_status'
          ],
          'Funeral' => [
              'table'         => 'service_funeral',
              'pk'            => 'funeral_id',
              'date_col'      => 'service_date',
              'time_col'      => 'service_time',
              'name_sql'      => "TRIM(CONCAT_WS(' ', deceased_firstname, deceased_middlename, deceased_lastname, deceased_ext))",
              'individual_col'=> 'individual_id',
              'status_col'    => 'service_status'
          ],
          'House Blessing' => [
              'table'         => 'service_house',
              'pk'            => 'house_id',
              'date_col'      => 'service_date',
              'time_col'      => 'service_time',
              'name_sql'      => "TRIM(CONCAT_WS(' ', owner_firstname, owner_middlename, owner_lastname, owner_ext))",
              'individual_col'=> 'individual_id',
              'status_col'    => 'service_status'
          ],
          'Wedding' => [
              'table'         => 'service_wedding',
              'pk'            => 'wedding_id',
              'date_col'      => 'service_date',
              'time_col'      => 'service_time',
              'name_sql'      => "TRIM(CONCAT_WS(' ', client_firstname, client_middlename, client_lastname, client_ext))",
              'individual_col'=> 'individual_id',
              'status_col'    => $WEDDING_STATUS_COL
          ],
      ];

      $rowsAll = [];

      foreach ($defs as $svc => $d) {
          $tbl   = $d['table'];
          $pk    = $d['pk'];
          $dcol  = $d['date_col'];
          $tcol  = $d['time_col'];
          $nameE = $d['name_sql'];
          $icol  = $d['individual_col'] ?? null;

          $dateExpr = sql_date_expr($dcol);
          $selInd   = $icol ? "$icol AS individual_id," : "NULL AS individual_id,";
          $scheduledClause = ($tbl === 'service_wedding')
              ? sql_scheduled_where_col(null, $WEDDING_STATUS_COL)
              : sql_scheduled_where();

          $sql = "
              SELECT
                  $selInd
                  $pk AS pk,
                  $nameE AS full_name,
                  $dcol AS service_date,
                  $tcol AS service_time,
                  '{$svc}' AS service,
                  '{$tbl}' AS tbl_name
              FROM {$tbl}
              WHERE {$dateExpr} = :today
                AND {$scheduledClause}
          ";
          try {
              $st = $pdo->prepare($sql);
              $st->execute([':today' => $today]);
              $rows = $st->fetchAll(PDO::FETCH_ASSOC);
          } catch (Throwable $e) {
              $rows = [];
          }

          foreach ($rows as $r) {
              $timeFmt = '';
              if (!empty($r['service_time'])) {
                  $ts = strtotime($r['service_time']);
                  if ($ts !== false) $timeFmt = date('g:i A', $ts);
              }
              $rowsAll[] = [
                  'individual_id' => $r['individual_id'] ?? null,
                  'full_name'     => $r['full_name'] ?? '',
                  'service'       => $r['service'] ?? $svc,
                  'service_date'  => $r['service_date'] ?? '',
                  'service_time'  => $r['service_time'] ?? '',
                  'time_fmt'      => $timeFmt,
                  'table'         => $r['tbl_name'] ?? $tbl,
                  'pk'            => $r['pk'] ?? null,
                  'sort_key'      => ($r['service_date'] ?? $today) . ' ' . (($r['service_time'] ?? '') !== '' ? $r['service_time'] : '23:59'),
              ];
          }
      }

      usort($rowsAll, fn($a,$b) => strcmp($a['sort_key'], $b['sort_key']));
      $todaysServiceList = $rowsAll;
  } catch (Throwable $e) {
      $todaysServiceList = [];
  }

  /* 2) Today Appointment Schedule — WEDDING ONLY */
  $todaysWeddingAppointments = [];
  try {
      $adExpr = sql_date_expr('appointment_date');
      $weddingStatusWhere = sql_scheduled_where_col(null, $WEDDING_STATUS_COL);

      $sqlW = "
          SELECT 
              wedding_id AS pk,
              TRIM(CONCAT_WS(' ', client_firstname, client_middlename, client_lastname, client_ext)) AS full_name,
              appointment_date,
              appointment_time
          FROM service_wedding
          WHERE $adExpr = :t
            AND {$weddingStatusWhere}
          ORDER BY 
            COALESCE(STR_TO_DATE(appointment_time,'%h:%i %p'),
                    STR_TO_DATE(appointment_time,'%H:%i'),
                    '9999-12-31 23:59:59') ASC
          LIMIT 50
      ";
      $sw = $pdo->prepare($sqlW);
      $sw->execute([':t'=>$today]);
      while ($w = $sw->fetch(PDO::FETCH_ASSOC)) {
          $tf = '';
          if (!empty($w['appointment_time'])) {
              $ts = strtotime($w['appointment_time']);
              if ($ts !== false) $tf = date('g:i A', $ts);
          }
          $todaysWeddingAppointments[] = [
              'pk'                => $w['pk'],
              'full_name'         => $w['full_name'] ?? '',
              'appointment_date'  => $w['appointment_date'] ?? '',
              'appointment_time'  => $w['appointment_time'] ?? '',
              'time_fmt'          => $tf,
              'table'             => 'service_wedding',
              'service'           => 'Wedding Appointment'
          ];
      }
  } catch (Throwable $e) {
      $todaysWeddingAppointments = [];
  }

  /* ---------------- NEXT APPOINTMENT (kept) ------------------ */
  $todaysAppointments = $todaysServiceList;
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
          'date'   => !empty($secondAppt['service_date']) ? date('M d, Y', strtotime($secondAppt['service_date'])) : '',
          'time'   => ($secondAppt['time_fmt'] ?? '') ?: (!empty($secondAppt['service_time']) ? date('g:i A', strtotime($secondAppt['service_time'])) : ''),
          'contact'=> $contactNumber
      ];
  }

  /* -------------------- MONTH SUMMARY (pie) ------------------ */
  $monthStart = date('Y-m-01'); $monthEnd = date('Y-m-t'); $monthLabel = date('F Y');
  if (!function_exists('countInRangeByStatus')) {
      function countInRangeByStatus(PDO $pdo, string $table, string $dateCol, string $start, string $end, string $status = 'Done'): int {
          try {
              $dateExpr = sql_date_expr("`$dateCol`");
              $sql = "SELECT COUNT(*) AS c
                      FROM `$table`
                      WHERE $dateExpr BETWEEN :s AND :e
                        AND LOWER(TRIM(`service_status`)) = LOWER(:st)";
              $q = $pdo->prepare($sql);
              $q->execute([':s'=>$start, ':e'=>$end, ':st'=>$status]);
              $row = $q->fetch(PDO::FETCH_ASSOC);
              return (int)($row['c'] ?? 0);
          } catch (Throwable $e) { return 0; }
      }
  }
  $serviceCountsMonth = [
      'Funeral'  => countInRangeByStatus($pdo, 'service_funeral',    'service_date', $monthStart, $monthEnd, 'Done'),
      'Baptism'  => countInRangeByStatus($pdo, 'service_baptism',    'service_date', $monthStart, $monthEnd, 'Done'),
      'Others'   => countInRangeByStatus($pdo, 'service_dedication', 'service_date', $monthStart, $monthEnd, 'Done'),
      'Blessing' => countInRangeByStatus($pdo, 'service_house',      'service_date', $monthStart, $monthEnd, 'Done'),
      'Wedding'  => countInRangeByStatus($pdo, 'service_wedding',    'service_date', $monthStart, $monthEnd, 'Done'),
  ];

  /* ---------------------- NOTIFICATIONS (DB-backed) --------------- */
  $notifItems = notif_fetch_latest($pdo, $CURRENT_USER_TYPE, $CURRENT_USER_ID, 20);
  $notifUnreadCount = notif_unread_count($pdo, $CURRENT_USER_TYPE, $CURRENT_USER_ID);

  /* -------------------- TODAY'S EVENTS (NEW CARD DATA) -------------------- */
  $todaysEvents = [];
  try {
      $edExpr = sql_date_expr('event_date');
      $q = $pdo->prepare("
          SELECT eventId, title, imgSrc, imgAlt, details, status, event_date
          FROM events_table
          WHERE $edExpr = :t
            AND LOWER(TRIM(status)) = 'active'
          ORDER BY event_date ASC
      ");
      $q->execute([':t' => $today]);
      while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
          $ts = !empty($r['event_date']) ? strtotime($r['event_date']) : false;
          $todaysEvents[] = [
              'id'       => (int)($r['eventId'] ?? 0),
              'title'    => (string)($r['title'] ?? ''),
              'imgSrc'   => (string)($r['imgSrc'] ?? ''),
              'imgAlt'   => (string)($r['imgAlt'] ?? ''),
              'date_fmt' => $ts ? date('M d, Y', $ts) : '',
              'time_fmt' => $ts ? date('g:i A', $ts) : '',
          ];
      }
  } catch (Throwable $e) {
      $todaysEvents = [];
  }
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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>


  <style>
    /* Subtle modern tweaks without changing layout structure */
    :root{
      --card-bg:#fff;
      --card-br:16px;
      --soft-border:#e5e7eb;
      --muted:#6b7280;
      --text:#111827;
      --accent:#0b214b;
      --accent-2:#eef2ff;
      --shadow:0 10px 26px rgba(15,23,42,.08);
      --sched-cols: 56px 1fr 190px;
    }

    body{background:#f6f7fb; font-synthesis-weight:none;}
    .card{background:var(--card-bg); border:1px solid var(--soft-border); border-radius:var(--card-br); padding:16px; box-shadow:var(--shadow);}
    .card h2{margin:0 0 12px; font-weight:900; letter-spacing:.2px; color:var(--text)}

    .split-card { display:flex; flex-direction:column; gap:14px; }
    .subcard { border:1px solid var(--soft-border); border-radius:14px; padding:12px; background:#fff; }
    .subcard h3 { margin:0 0 10px; font-size:16px; font-weight:800; color:var(--text); cursor:pointer; }

    .mode-toggle{display:flex;gap:10px;align-items:center;margin:6px 0 18px}
    .mode-toggle .pill{
      appearance:none;border:none;cursor:pointer;padding:10px 18px;border-radius:999px;
      background:var(--accent);color:#fff;font-weight:800;letter-spacing:.2px;
      box-shadow:0 6px 14px rgba(11,33,75,.22), inset 0 -2px 0 rgba(255,255,255,.12);
      display:inline-flex;align-items:center;gap:8px;transition:.18s transform ease,.18s opacity ease, .2s background ease;
      text-decoration:none;
    }
    .mode-toggle .pill.secondary{background:var(--accent-2);color:var(--accent);border:1px solid #c7d2fe}
    .mode-toggle .pill:hover{transform:translateY(-1px)}
    @media (max-width:640px){ .mode-toggle{flex-wrap:wrap} }

    /* Individuals card */
    .stat-individuals{ display:flex; align-items:stretch; }
    .stat-individual-card{ display:flex; flex-direction:column; width:100%; }
    .stat-individual-header{ display:flex; align-items:center; gap:14px; margin-bottom:10px; }
    .stat-individual-logo-wrap{ flex-shrink:0; display:flex; align-items:center; justify-content:center; }
    .stat-individual-logo-bg{ width:86px; height:86px; border-radius:24px; display:grid; place-items:center; background:#f3f4f6; }
    .stat-individual-logo-bg img{ max-width:60px; max-height:60px; object-fit:contain; display:block; }
    .stat-individual-main{ display:flex; flex-direction:column; justify-content:center; flex:1; }
    .stat-individual-label{ font-size:11px; text-transform:uppercase; letter-spacing:.12em; font-weight:700; color:var(--muted); margin-bottom:4px; }
    .stat-individual-total{ font-size:32px; font-weight:800; color:var(--text); line-height:1.1; }
    .stat-individual-sub{ font-size:12px; color:#4b5563; margin-top:2px; }

    .stat-individual-gender-row{ display:flex; gap:10px; margin-top:10px; }
    .gender-box{ flex:1; background:#eef2ff; border-radius:12px; padding:10px 12px; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; cursor:pointer; position:relative; transition:box-shadow .18s ease, transform .18s ease, background .18s ease; border:1px solid #dbeafe;}
    .gender-box.male{border-top:4px solid #3b82f6;}
    .gender-box.female{border-top:4px solid #3b82f6;}
    .gender-box i{ font-size:22px; opacity:.9; margin-bottom:4px; }
    .gender-label{ font-size:11px; text-transform:uppercase; letter-spacing:.08em; color:#4b5563; margin-bottom:2px; }
    .gender-count{ font-size:22px; font-weight:800; color:var(--text); line-height:1.1; }
    .gender-box:hover{ box-shadow:0 10px 26px rgba(15,23,42,.18); transform:translateY(-2px); background:#e0e7ff; }

    /* weekly stat cards */
    .stat-weekly { display:flex; flex-direction:column; }
    .stat-weekly-header{ display:flex; align-items:center; gap:14px; margin-bottom:10px; }
    .stat-weekly-icon{ width:52px; height:52px; border-radius:16px; object-fit:contain; flex-shrink:0; background:#f3f4f6; }

    .mini-tiles{ display:grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap:8px; margin-top:4px; }
    @media (max-width:700px){ .mini-tiles{ grid-template-columns: repeat(2,minmax(0,1fr)); } }
    .mini-tile{ position:relative; border:1px solid var(--soft-border); border-radius:12px; padding:10px 12px; display:flex; align-items:center; justify-content:space-between; cursor:pointer; background:#fff; position:relative; transition:box-shadow .18s ease, transform .18s ease, border-color .18s ease; }
    .mini-tile:hover{ box-shadow:0 8px 18px rgba(15,23,42,.12); transform:translateY(-2px); border-color:#c7d2fe; }
    .mini-ico{ width:40px; height:40px; border-radius:10px; display:grid; place-items:center; font-size:18px; }
    .mini-count{ font-size:24px; font-weight:800; color:var(--text); margin-left:12px; }
    .mini-tooltip{
      position:absolute;
      left:50%;
      top:-8px;
      transform:translate(-50%,-130%);
      background:rgba(15,23,42,.96);
      color:#f9fafb;
      padding:6px 12px;
      border-radius:999px;
      font-size:12px;
      font-weight:500;
      white-space:nowrap;
      box-shadow:0 10px 25px rgba(15,23,42,.35);
      pointer-events:none;
      opacity:0;
      transition:opacity .18s ease, transform .18s ease;
      z-index:30;
    }
    .mini-tooltip::after{
      content:'';
      position:absolute;
      top:100%;
      left:50%;
      transform:translateX(-50%);
      border-width:6px 6px 0 6px;
      border-style:solid;
      border-color:rgba(15,23,42,.96) transparent transparent transparent;
    }
    .mini-tile:hover .mini-tooltip{
      opacity:1;
      transform:translate(-50%,-150%);
    }
    
    .ico-baptism{ background:#8ecdf0; color:#0b214b; }
    .ico-dedication{ background:#c587f2; color:#3a0b4b; }
    .ico-funeral{ background:#111827; color:#fff; }
    .ico-house{ background:#54a460; color:#fff; }
    .ico-wedding{ background:#f2a720; color:#000; }

    /* Modals, tables, etc. */
    .modal-overlay{ position:fixed; inset:0; background:rgba(17,24,39,.5); display:none; align-items:center; justify-content:center; z-index:1000; }
    .modal-overlay[data-open="true"]{ display:flex; }
    .modal{ background:#fff; width:min(980px, 96vw); max-height:88vh; border-radius:16px; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 24px 55px rgba(15,23,42,.4); }
    .modal-header{ padding:14px 16px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid var(--soft-border); gap:10px; }
    .modal-title{ font-weight:800; }
    .pill-filter{ display:flex; gap:6px; flex-wrap:wrap; }
    .btn{ padding:6px 10px; border-radius:10px; border:1px solid var(--soft-border); background:#f9fafb; cursor:pointer; display:inline-flex; align-items:center; gap:4px; font-size:12px; }
    .btn.active{ background:#0b214b; color:#fff; border-color:#0b214b; }
    .btn.primary{ background:#111827; color:#fff; border-color:#111827; }
    .btn.ghost{ background:transparent; border:none; color:#6b7280; padding:4px 6px; }
    .btn.ghost:hover{ color:#111827; }
    .modal-body{ display:grid; grid-template-columns: 1.2fr .8fr; gap:0; min-height:360px; }
    @media (max-width:900px){ .modal-body{ grid-template-columns: 1fr; } }
    .modal-col{ padding:12px 16px; overflow:auto; }
    .list-item{ display:flex; gap:10px; align-items:center; padding:10px 8px; border-bottom:1px dashed var(--soft-border); cursor:pointer; transition:background .15s ease; }
    .list-item:hover{ background:#f9fafb; }
    .weekday{ font-size:11px; font-weight:800; padding:4px 8px; background:#eef2ff; color:#111827; border-radius:999px; }
    .name{ font-weight:700; color:#111827; }
    .date-time{ font-size:12px; color:#374151; }
    .contact{ font-size:12px; color:#6b7280; }
    .weekly-icon{ width:42px;height:42px;border-radius:10px;display:grid;place-items:center;font-size:18px; }

    /* Column-aligned table */
    .sched-table{ width:100%; }
    .sched-table .table-header, .sched-table .table-row{
      display:grid; grid-template-columns: var(--sched-cols); align-items:center; gap:12px;
    }
    .sched-table .table-header{ padding:6px 0; font-size:12px; font-weight:700; color:#6b7280; border-bottom:1px solid var(--soft-border); }
    .sched-table .table-body .table-row{ padding:10px 0; border-bottom:1px dashed var(--soft-border); transition:background .15s ease; }
    .sched-table .table-body .table-row:hover{ background:#f9fafb; }
    .sched-table .cell.client{ display:flex; align-items:center; justify-content:center; }
    .sched-table .cell.client img{ width:40px;height:40px;object-fit:cover;border-radius:8px; }
    .sched-table .cell.name{ min-width:0; }
    .sched-table .cell.name .title{ font-weight:700;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
    .sched-table .cell.name .sub{ font-size:12px;color:#6b7280; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .sched-table .cell.time{ text-align:right; font-variant-numeric: tabular-nums; white-space:nowrap; }
    .sched-table .empty{ padding:16px 0;color:#6b7280;font-size:13px;text-align:center; }
    #servicesList.scrollable{ max-height:260px; overflow:auto; }
    #weddingApptList.scrollable{ max-height:220px; overflow:auto; }
    @media (max-width: 820px){
      :root{ --sched-cols: 48px 1fr; }
      .sched-table .table-header .time{ display:none; }
      .sched-table .table-body .cell.time{ display:none; }
      .sched-table .cell.name .title{ white-space:normal; }
    }

    /* ======= TODAY'S EVENTS ======= */
    #eventsTodayWrap .poster-wrap {
      border: 1px solid var(--soft-border) !important;
      border-radius: 12px !important;
      background: #f9fafb !important;
      overflow: hidden !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      padding: 8px !important;
    }
    #eventsTodayWrap .poster {
      display: block !important;
      width: 100% !important;
      height: auto !important;
      object-fit: contain !important;
      max-height: clamp(260px, 38vh, 420px) !important;
      border-radius: 8px !important;
    }
    @media (max-width: 640px) {
      #eventsTodayWrap .poster { max-height: clamp(180px, 32vh, 320px) !important; }
    }

    #eventsTodayWrap .event-card{ display:flex; flex-direction:column; gap:8px; margin-bottom:12px; }
    #eventsTodayWrap .event-meta{ display:flex; flex-direction:column; padding:6px 4px 0; }
    #eventsTodayWrap .event-meta .dt{ font-size:12px; color:#374151; }
    #eventsTodayWrap .event-meta .ttl{ font-weight:800; color:#111827; margin-top:2px; }

    /* ------- Notifications (DISPLAY UPDATED) ------- */
    .notif-item{ display:flex; gap:10px; padding:10px 12px; border-bottom:1px solid #f3f4f6; }
    .notif-item.unread{ background:linear-gradient(180deg,#f8fafc,transparent); }
    .notif-icon{ width:32px; height:32px; border-radius:8px; display:grid; place-items:center; flex-shrink:0; }
    .notif-icon.bg-system{ background:#111827; color:#fff; }
    .notif-icon.bg-user{ background:#0b214b; color:#fff; }
    .notif-icon i{ font-size:14px; }
    .notif-body{ display:flex; flex-direction:column; gap:4px; min-width:0; width:100%; }
    .notif-hdr{ display:flex; align-items:center; justify-content:space-between; gap:8px; }
    .notif-title{ font-size:13px; font-weight:800; color:#111827; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .notif-meta{ font-size:11px; color:#6b7280; white-space:nowrap; }
    .notif-from{ font-size:11px; color:#4b5563; background:#eef2ff; padding:2px 8px; border-radius:999px; border:1px solid #c7d2fe; display:inline-flex; align-items:center; gap:6px; max-width:100%; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

    /* ===== Detail Card (right column in modals) ===== */
    .detail-card{ border:1px solid var(--soft-border); border-radius:12px; overflow:hidden; background:#fff; }
    .detail-header{ display:flex; align-items:center; gap:10px; padding:10px 12px; border-bottom:1px solid var(--soft-border); background:linear-gradient(90deg,#f3e8ff,#eef2ff); }
    .detail-header .ico{ width:36px; height:36px; border-radius:10px; display:grid; place-items:center; }
    .detail-header .title{ font-weight:800; color:#111827; flex:1; }
    .detail-header .chip{ font-size:11px; background:#e0e7ff; border:1px solid #c7d2fe; padding:4px 8px; border-radius:999px; }
    .detail-section{ padding:12px; }
    .detail-section + .detail-section{ border-top:1px dashed var(--soft-border); }
    .detail-section-title{ font-weight:800; display:flex; align-items:center; gap:8px; margin-bottom:8px; color:#111827; }
    .detail-kvgrid{ display:grid; grid-template-columns: 1fr 1fr; gap:8px 14px; }
    .detail-kvgrid .k{ font-size:12px; color:#6b7280; }
    .detail-kvgrid .v{ font-weight:700; color:#111827; }
    @media (max-width:900px){ .detail-kvgrid{ grid-template-columns:1fr; } }

    /* ===== Gender Modal (fixed UI) ===== */
    .modal--gender{ width:min(760px,96vw); }
    .modal-header.gender{ background:#f8fafc; border-bottom:1px solid var(--soft-border); }
    .gender-modal-body{ padding:12px 16px; display:flex; flex-direction:column; gap:10px; }
    .gender-modal-toolbar{ display:flex; align-items:center; gap:10px; }
    .gender-pill{ display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:999px; background:#eef2ff; color:#1e3a8a; border:1px solid #c7d2fe; font-weight:800; }
    .gender-pill .dot{ width:8px; height:8px; border-radius:999px; background:#3b82f6; display:inline-block; }
    .gender-pill.male .dot{ background:#3b82f6; }
    .gender-pill.female .dot{ background:#e11d48; }
    .gender-search{ flex:1; display:flex; align-items:center; gap:8px; border:1px solid var(--soft-border); border-radius:10px; padding:6px 10px; background:#fff; }
    .gender-search i{ color:#6b7280; }
    .gender-search input{ border:none; outline:none; width:100%; font-size:14px; }
    .gender-modal-list{ margin-top:4px; border-top:1px dashed var(--soft-border); max-height:60vh; overflow:auto; }
    .gender-row{ display:flex; align-items:center; gap:12px; padding:10px 4px; border-bottom:1px dashed var(--soft-border); }
    .gender-avatar{ width:36px; height:36px; border-radius:999px; display:grid; place-items:center; }
    .gender-avatar.male{ background:#dbeafe; color:#1e3a8a; }
    .gender-avatar.female{ background:#fde7f3; color:#9d174d; }
    .gender-row-main{ flex:1; min-width:0; }
    .gender-row-name{ font-weight:800; color:#111827; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .gender-row-sub{ font-size:0px; color:#6b7280; }
    .gender-row-tag{ font-size:11px; background:#f3f4f6; color:#374151; padding:4px 8px; border-radius:999px; border:1px solid var(--soft-border); white-space:nowrap; }
    .gender-empty{ padding:16px; color:#6b7280; text-align:center; }
        /* ===== Announcement Modal (floating) ===== */
    .floating-modal-overlay{
      position:fixed;
      inset:0;
      background:rgba(15,23,42,.55);
      display:none;
      align-items:center;
      justify-content:center;
      z-index:1600;
    }
    .floating-modal-overlay[data-open="true"]{
      display:flex;
    }
    .floating-modal{
      background:#fff;
      border-radius:18px;
      width:min(960px,96vw);
      max-height:90vh;
      box-shadow:0 24px 60px rgba(15,23,42,.45);
      display:flex;
      flex-direction:column;
      overflow:hidden;
    }
    .fm-header{
      padding:14px 18px;
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
      border-bottom:1px solid var(--soft-border);
      background:linear-gradient(90deg,#eef2ff,#f5f3ff);
    }
    .fm-header-main h2{
      margin:0;
      font-size:18px;
      font-weight:800;
      color:var(--text);
    }
    .fm-header-main p{
      margin:2px 0 0;
      font-size:12px;
      color:#4b5563;
    }
    .fm-close{
      border:none;
      background:transparent;
      cursor:pointer;
      padding:4px;
      border-radius:999px;
      color:#6b7280;
    }
    .fm-close:hover{
      background:rgba(15,23,42,.06);
      color:#111827;
    }
    .fm-body{
      padding:12px 16px 16px;
      display:grid;
      grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);
      gap:10px;
    }
    @media (max-width:900px){
      .fm-body{ grid-template-columns:1fr; max-height:calc(90vh - 64px); overflow:auto; }
    }
    .fm-column{ padding:4px 6px; }
    .fm-section-title{
      font-size:13px;
      font-weight:800;
      color:#111827;
      margin:0 0 6px;
    }
    .fm-label{
      display:block;
      font-size:12px;
      font-weight:600;
      color:#374151;
      margin-bottom:4px;
    }
    .fm-textarea{
      width:100%;
      border-radius:10px;
      border:1px solid var(--soft-border);
      padding:8px 10px;
      font-family:inherit;
      font-size:14px;
      min-height:80px;
      resize:vertical;
    }
    .fm-textarea:focus{
      outline:none;
      border-color:#4f46e5;
      box-shadow:0 0 0 1px rgba(79,70,229,.4);
    }
    .fm-actions{
      display:flex;
      flex-wrap:wrap;
      gap:8px;
      margin-top:8px;
    }
    .fm-actions .btn{
      font-size:12px;
      padding:8px 12px;
    }
    .fm-helper{
      margin-top:6px;
      font-size:11px;
      color:#6b7280;
    }

    .fm-current{
      border-radius:12px;
      border:1px dashed var(--soft-border);
      padding:10px 10px 8px;
      min-height:60px;
      background:#f9fafb;
    }
    .fm-current-empty{
      font-size:13px;
      color:#6b7280;
    }
    .fm-current-card{
      display:flex;
      flex-direction:column;
      gap:4px;
    }
    .fm-current-status{
      font-size:10px;
      font-weight:700;
      text-transform:uppercase;
      letter-spacing:.12em;
      display:inline-flex;
      align-items:center;
      padding:3px 8px;
      border-radius:999px;
      border:1px solid var(--soft-border);
      background:#eef2ff;
      max-width:max-content;
    }
    .fm-current-status.fm-tag-new{
      background:#ecfdf3;
      border-color:#bbf7d0;
      color:#166534;
    }
    .fm-current-status.fm-tag-past{
      background:#f9fafb;
      border-color:#e5e7eb;
      color:#4b5563;
    }
    .fm-current-msg{
      font-size:14px;
      color:#111827;
      white-space:pre-wrap;
    }
    .fm-current-meta{
      font-size:11px;
      color:#6b7280;
    }

    .fm-history{
      border-radius:12px;
      border:1px solid var(--soft-border);
      background:#f9fafb;
      max-height:340px;
      overflow:auto;
    }
    .fm-history-empty{
      padding:10px;
      font-size:13px;
      color:#6b7280;
      text-align:center;
    }
    .fm-history-row{
      padding:8px 10px;
      border-bottom:1px dashed var(--soft-border);
      font-size:12px;
      display:flex;
      flex-direction:column;
      gap:4px;
    }
    .fm-history-row:last-child{
      border-bottom:none;
    }
    .fm-history-status{
      font-size:10px;
      text-transform:uppercase;
      letter-spacing:.12em;
      font-weight:700;
      display:inline-flex;
      align-items:center;
      padding:2px 7px;
      border-radius:999px;
      border:1px solid var(--soft-border);
      background:#fff;
      max-width:max-content;
    }
    .fm-history-status.fm-tag-new{
      background:#ecfdf3;
      border-color:#bbf7d0;
      color:#166534;
    }
    .fm-history-status.fm-tag-past{
      background:#f9fafb;
      border-color:#e5e7eb;
      color:#4b5563;
    }
    .fm-history-msg{
      color:#111827;
      white-space:pre-wrap;
    }
    .fm-history-meta{
      font-size:11px;
      color:#6b7280;
    }

  
    /* Modern pill-style Announcement button */
    .add-annc-btn{
      position:relative;
      padding:0.8rem 3rem;
      border-radius:999px;
      border:none;
      font-weight:700;
      letter-spacing:0.08em;
      text-transform:uppercase;
      font-size:0.9rem;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:0.5rem;
      background:linear-gradient(90deg,#8fd3ff,#3b82ff);
      color:#020617;
      box-shadow:0 14px 30px rgba(15,23,42,0.45);
      cursor:pointer;
      transition:transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
    }
    .add-annc-btn:hover{
      transform:translateY(-1px);
      box-shadow:0 18px 38px rgba(15,23,42,0.55);
      filter:brightness(1.04);
    }
    .add-annc-btn:active{
      transform:translateY(0);
      box-shadow:0 10px 22px rgba(15,23,42,0.4);
      filter:brightness(0.97);
    }
    .add-annc-btn i{
      font-size:1rem;
    }
    .add-annc-btn span{
      font-size:0.9rem;
    }

    /* Ensure SweetAlert overlays above custom modals */
    .swal2-container{
      z-index:13000 !important;
    }
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
        <div class="user-title">Secretary</div>
        <div class="user-sub">Dashboard</div>
      </div>
    </div>
    <nav class="nav">
      <div class="section-title">Main</div>
      <a class="navlink active" href="secretary_dashboard.php"><i class="fas fa-home"></i>Dashboard</a>
      <div class="section-title">Online Requests</div>
      <a class="navlink" href="admin-schedule-request.php"><i class="fas fa-calendar-plus"></i>Schedule Requests</a>
      <a class="navlink" href="admin-prayer-request.php"><i class="fas fa-praying-hands"></i><span>Prayer Requests</span></a>
      <div class="section-title">Online Applications</div>
      <a class="navlink" href="baptismal_application.php"><i class="fas fa-water"></i>Baptismal Applications</a>
      <a class="navlink" href="admin-application.php"><i class="fas fa-user-cog"></i>Baptismal Account Verification</a>
      <a class="navlink" href="application_ministry.php"><i class="fas fa-users"></i>Ministry Applications</a>
      <div class="section-title">Schedule</div>
      <a class="navlink" href="appointment-schedule.php"><i class="fas fa-calendar-check"></i>Service Schedule</a>
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
        <div class="notif-wrap">
          <button aria-label="Notifications" class="notif-btn" id="notifBell">
            <i class="far fa-bell"></i>
            <?php if ($notifUnreadCount): ?>
              <span class="notif-badge" id="notifBadge"><?php echo (int)$notifUnreadCount; ?></span>
            <?php endif; ?>
          </button>
          <div class="notif-dropdown" id="notifDropdown" aria-hidden="true" role="menu" aria-label="Notifications">
            <div class="notif-header">
              <span>Notifications</span>
              <button class="notif-markread" id="notifMarkRead" type="button">Mark all as read</button>
            </div>
            <?php if (empty($notifItems)): ?>
              <div class="notif-empty">
                <img src="css/image/empty.png" alt="" aria-hidden="true">
                <div>No notifications</div>
              </div>
            <?php else: ?>
              <ul class="notif-list">
                <?php foreach ($notifItems as $n): 
                  $isSystem = strtolower($n['created_by_type'] ?? '') === 'system';
                  $iconClass = $isSystem ? 'bg-system fas fa-cog' : 'bg-user fas fa-user';
                  $submitterChip = $n['submitter'] ?: 'System';
                  $dtLabel = $n['dt_fmt'] ?? '';
                ?>
                  <li class="notif-item <?php echo $n['is_unread'] ? 'unread' : ''; ?>" role="menuitem" tabindex="0">
                    <div class="notif-icon <?php echo $isSystem ? 'bg-system' : 'bg-user'; ?>">
                      <i class="<?php echo $iconClass; ?>"></i>
                    </div>
                    <div class="notif-body">
                      <div class="notif-hdr">
                        <div class="notif-title" title="<?php echo htmlspecialchars($n['title'] ?? 'Notification'); ?>">
                          <?php echo htmlspecialchars($n['title'] ?: 'Notification'); ?>
                        </div>
                        <div class="notif-meta"><?php echo htmlspecialchars($dtLabel); ?></div>
                      </div>
                      <div class="notif-from" title="<?php echo htmlspecialchars($submitterChip); ?>">
                        <i class="fas fa-paper-plane"></i>
                        <span><?php echo htmlspecialchars($submitterChip); ?></span>
                      </div>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
              <div class="notif-footer">
                <button class="btn primary" id="notifClearAll" type="button">
                  <i class="fas fa-trash-alt"></i> Clear all notifications
                </button>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </header>

    <!-- QUICK LINKS -->
    <div class="mode-toggle" id="modeToggle" aria-label="Quick links" style="margin-left: 250px;">
      <a class="pill secondary" href="content-management_events.php" id="btnManagePoster">
        <i class="fas fa-image"></i> Manage Event Poster
      </a>
      <a class="pill secondary" href="content-management_gallery.php" id="btnManageGallery">
        <i class="fas fa-images"></i> Manage Gallery
      </a>
      <a class="pill secondary" href="onsite_appointment.php" id="btnManageGallery">
        <i class="fas fa-calendar"></i> Schedule a Service
      </a>
    </div>

    <!-- ================== STATS ================== -->
    <section class="stats" id="statsSection">
      <!-- Individuals -->
      <article class="stat stat-individuals">
        <div class="stat-individual-card">
          <div class="stat-individual-header">
            <div class="stat-individual-logo-wrap">
              <div class="stat-individual-logo-bg">
                <img src="image/logo-user.png" alt="Individuals">
              </div>
            </div>
            <div class="stat-individual-main">
              <div class="stat-individual-label">Total Individuals</div>
              <div class="stat-individual-total"><?php echo (int)$totalIndividuals; ?></div>
              <div class="stat-individual-sub">Registered in the system</div>
            </div>
          </div>

          <div class="stat-individual-gender-row">
            <div class="gender-box male" id="genderBoxMale">
              <i class="fas fa-male"></i>
              <div class="gender-label">Male</div>
              <div class="gender-count"><strong><?php echo (int)$totalMale; ?></strong></div>
            </div>
            <div class="gender-box female" id="genderBoxFemale">
              <i class="fas fa-female"></i>
              <div class="gender-label">Female</div>
              <div class="gender-count"><strong><?php echo (int)$totalFemale; ?></strong></div>
            </div>
          </div>
        </div>
      </article>

      <!-- This Week -->
      <article class="stat stat-weekly">
        <div class="stat-weekly-header">
          <img src="image/logo-schedule.png" alt="" class="stat-weekly-icon">
          <div style="width:100%;">
            <div class="stat-title" id="statTitle1">Total Service of this Week</div>
            <div class="stat-value" id="thisWeekTotal">0</div>
            <div class="stat-sub" id="thisWeekRange">Loading…</div>
          </div>
        </div>
        <div class="mini-tiles" id="thisWeekTiles"></div>
      </article>

      <!-- Next Week -->
      <article class="stat stat-weekly">
        <div class="stat-weekly-header">
          <img src="image/logo-upcoming_sched.png" alt="" class="stat-weekly-icon">
          <div style="width:100%;">
            <div class="stat-title" id="statTitle2">Upcoming Schedule — Next Week</div>
            <div class="stat-value" id="nextWeekTotal">0</div>
            <div class="stat-sub" id="nextWeekRange">Loading…</div>
          </div>
        </div>
        <div class="mini-tiles" id="nextWeekTiles"></div>
      </article>
    </section>

    <!-- CARDS -->
    <section class="cards">
      <!-- Today -->
      <article class="card">
        <h2>Today's Schedule</h2>
        <div class="split-card">
          <div class="subcard">
            <h3 id="serviceListTitle" title="Click to view details">Today Service Schedule (All Services)</h3>
            <div class="sched-table" aria-label="Today Service Schedule table">
              <div class="table-header">
                <div class="cell client">Client</div>
                <div class="cell name">Name / Service</div>
                <div class="cell time">Date / Time</div>
              </div>
              <div class="table-body<?php echo (count($todaysServiceList) >= 2 ? ' scrollable' : ''); ?>" id="servicesList">
                <?php if (empty($todaysServiceList)): ?>
                  <div class="empty">No scheduled services for today.</div>
                <?php else: ?>
                  <?php foreach ($todaysServiceList as $row): ?>
                    <div class="table-row" data-table="<?php echo htmlspecialchars($row['table']); ?>" data-id="<?php echo (int)$row['pk']; ?>">
                      <div class="cell client"><img src="image/logo-user.png" alt="Client"></div>
                      <div class="cell name">
                        <div class="title" title="<?php echo htmlspecialchars($row['full_name']); ?>"><?php echo htmlspecialchars($row['full_name']); ?></div>
                        <div class="sub"><?php echo htmlspecialchars($row['service']); ?></div>
                      </div>
                      <div class="cell time">
                        <?php
                          $d = $row['service_date'] ?? '';
                          $t = $row['time_fmt'] ?? '';
                          $datePart = $d ? date('M d, Y', strtotime($d)) : '';
                          echo htmlspecialchars(trim($datePart . ($t ? ' • ' . $t : '')));
                        ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="subcard">
            <h3 id="apptListTitle" title="Click to view details">Today Appointment Schedule (Wedding)</h3>
            <div class="sched-table" aria-label="Today Wedding Appointments table">
              <div class="table-header">
                <div class="cell client">Client</div>
                <div class="cell name">Name / Service</div>
                <div class="cell time">Time</div>
              </div>
              <div class="table-body<?php echo (count($todaysWeddingAppointments) >= 2 ? ' scrollable' : ''); ?>" id="weddingApptList">
                <?php if (empty($todaysWeddingAppointments)): ?>
                  <div class="empty">No wedding appointments for today.</div>
                <?php else: ?>
                  <?php foreach ($todaysWeddingAppointments as $w): ?>
                    <div class="table-row" data-table="service_wedding" data-id="<?php echo (int)$w['pk']; ?>">
                      <div class="cell client"><img src="image/logo-user.png" alt="Client"></div>
                      <div class="cell name">
                        <div class="title" title="<?php echo htmlspecialchars($w['full_name']); ?>"><?php echo htmlspecialchars($w['full_name']); ?></div>
                        <div class="sub">Wedding Appointment</div>
                      </div>
                      <div class="cell time">
                        <?php echo htmlspecialchars($w['time_fmt'] ?: ($w['appointment_time'] ? date('g:i A', strtotime($w['appointment_time'])) : '')); ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </article>

      <!-- Today's Event -->
      <article class="card">
        <h2 id="eventsTitle">Today's Event</h2>
        <div id="eventsTodayWrap">
          <?php if (empty($todaysEvents)): ?>
            <div class="empty">No events scheduled for today.</div>
          <?php else: ?>
            <?php foreach ($todaysEvents as $ev): ?>
              <div class="event-card">
                <div class="event-meta">
                  <div class="dt"><?php echo htmlspecialchars(trim($ev['date_fmt'] . ($ev['time_fmt'] ? ' • ' . $ev['time_fmt'] : ''))); ?></div>
                  <div class="ttl"><?php echo htmlspecialchars($ev['title']); ?></div>
                </div>
                <div class="poster-wrap">
                  <img class="poster" src="<?php echo htmlspecialchars($ev['imgSrc']); ?>"
                      alt="<?php echo htmlspecialchars($ev['imgAlt'] ?: $ev['title']); ?>"
                      onerror="this.src='image/httc_main-logo.jpg'; this.style.objectFit='contain';">
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </article>

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

  <!-- Announcement Modal placeholder -->
<!-- Announcement Modal placeholder -->
<!-- Announcement Modal -->
<div class="floating-modal-overlay" id="announcementOverlay" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="fmTitle">
  <div class="floating-modal" role="document">
    <div class="fm-header">
      <div class="fm-header-main">
        <h2 id="fmTitle">Announcements</h2>
        <p>Manage the latest announcement and archive previous ones.</p>
      </div>
      <button type="button" class="fm-close" id="announcementCloseBtn" aria-label="Close announcement dialog">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="fm-body">
      <!-- LEFT: current + editor -->
      <div class="fm-column">
        <h3 class="fm-section-title">Current Announcement</h3>
        <div class="fm-current" id="announcementCurrent">
          <div class="fm-current-empty">Loading…</div>
        </div>

        <h3 class="fm-section-title" style="margin-top:14px;">Create / Edit Announcement</h3>
        <label class="fm-label" for="announcementMsg">Message</label>
        <textarea id="announcementMsg" class="fm-textarea" rows="4" placeholder="Type the announcement message here…"></textarea>

        <div class="fm-actions">
          <button type="button" class="btn primary" id="announcementSaveBtn">
            <i class="fas fa-save"></i> Save as New
          </button>
          <button type="button" class="btn" id="announcementSaveArchiveBtn">
            <i class="fas fa-archive"></i> Save &amp; Archive Previous
          </button>
          <button type="button" class="btn" id="announcementUpdateBtn" style="display:none;">
            <i class="fas fa-pen"></i> Update Latest “New”
          </button>
          <button type="button" class="btn" id="announcementArchiveLatestBtn" style="display:none;">
            <i class="fas fa-box"></i> Archive Latest “New”
          </button>
        </div>
        <div class="fm-helper" id="announcementHelper"></div>
      </div>

      <!-- RIGHT: history -->
      <div class="fm-column">
        <h3 class="fm-section-title">Announcement History</h3>
        <div class="fm-history" id="announcementHistory">
          <div class="fm-history-empty">Loading…</div>
        </div>
      </div>
    </div>
  </div>
</div>
  <div class="toast" id="toast"></div>

  <!-- Weekly Modal -->
  <div class="modal-overlay" id="weeklyModal">
    <div class="modal">
      <div class="modal-header">
        <div class="modal-title" id="weeklyModalTitle">This Week — Service</div>
        <div class="pill-filter" id="weeklyFilters"></div>
        <button class="btn" id="weeklyModalClose"><i class="fas fa-times"></i> Close</button>
      </div>
      <div class="modal-body">
        <div class="modal-col" id="weeklyListCol"></div>
        <div class="modal-col" id="weeklyDetailCol">
          <div class="detail-title" style="font-weight:800;margin-bottom:6px;">Details</div>
          <div class="detail-kv"><span>Pick an item from the list →</span><span></span></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Today Services Modal -->
  <div class="modal-overlay" id="todayServicesModal">
    <div class="modal">
      <div class="modal-header">
        <div class="modal-title">Today — All Services</div>
        <button class="btn" id="todayServicesModalClose"><i class="fas fa-times"></i> Close</button>
      </div>
      <div class="modal-body">
        <div class="modal-col" id="todayServicesListCol"></div>
        <div class="modal-col" id="todayServicesDetailCol">
          <div class="detail-title" style="font-weight:800;margin-bottom:6px;">Details</div>
          <div class="detail-kv"><span>Select an item →</span><span></span></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Today Wedding Modal -->
  <div class="modal-overlay" id="todayWeddingModal">
    <div class="modal">
      <div class="modal-header">
        <div class="modal-title">Today — Wedding Appointments</div>
        <button class="btn" id="todayWeddingModalClose"><i class="fas fa-times"></i> Close</button>
      </div>
      <div class="modal-body">
        <div class="modal-col" id="todayWeddingListCol"></div>
        <div class="modal-col" id="todayWeddingDetailCol">
          <div class="detail-title" style="font-weight:800;margin-bottom:6px;">Details</div>
          <div class="detail-kv"><span>Select an item →</span><span></span></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Gender Modal -->
  <div class="modal-overlay" id="genderModal" aria-hidden="true">
    <div class="modal modal--gender">
      <div class="modal-header gender">
        <div class="modal-title" id="genderModalTitle">Male Individuals</div>
        <div class="gender-modal-meta" id="genderModalMeta">0 records</div>
        <button class="btn ghost" id="genderModalClose" type="button"><i class="fas fa-times"></i></button>
      </div>
      <div class="gender-modal-body">
        <div class="gender-modal-toolbar">
          <div class="gender-pill male" id="genderModalPill">
            <span class="dot"></span>
            <span id="genderModalPillText">MALE</span>
          </div>
          <div class="gender-search">
            <i class="fas fa-search"></i>
            <input type="search" id="genderModalSearch" placeholder="Search by name...">
          </div>
        </div>
        <div class="gender-modal-list" id="genderModalList">
          <div class="gender-empty">Loading...</div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

  <script>
  /* Expose today's datasets for modals */
  window.__TODAY_SERVICES__ = <?php echo json_encode($todaysServiceList, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
  window.__TODAY_WEDDING_APPTS__ = <?php echo json_encode($todaysWeddingAppointments, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;

  /* Utilities */
  function escapeHtml(str){ return (''+(str??'')).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
  function formatDate(s){
    if(!s) return '';
    const d = new Date(s+'T00:00:00'); if(isNaN(d)) return s;
    return d.toLocaleDateString(undefined, {month:'short', day:'2-digit', year:'numeric'});
  }
  /* Robust time parser: supports '13:05', '1:05 PM', '01:05 pm' */
  function parseTimeToDate(s){
    if(!s) return null;
    const str = String(s).trim();
    // 24-hour HH:mm
    let m = str.match(/^([01]?\d|2[0-3]):([0-5]\d)$/);
    if(m){
      const dt = new Date();
      dt.setHours(parseInt(m[1],10), parseInt(m[2],10), 0, 0);
      return dt;
    }
    // 12-hour h:mm AM/PM
    m = str.match(/^(\d{1,2}):([0-5]\d)\s*([AaPp][Mm])$/);
    if(m){
      let h = parseInt(m[1],10) % 12;
      const mins = parseInt(m[2],10);
      const ampm = m[3].toUpperCase();
      if(ampm==='PM') h += 12;
      const dt = new Date();
      dt.setHours(h, mins, 0, 0);
      return dt;
    }
    return null;
  }
  function formatTime(s){
    const dt = parseTimeToDate(s);
    if(!dt) return (s||'');
    return dt.toLocaleTimeString([], {hour:'numeric', minute:'2-digit'});
  }
    function formatDateTimeLocal(s){
    if(!s) return '';
    // turn "YYYY-MM-DD HH:MM:SS" into a local Date
    const iso = String(s).replace(' ','T');
    const d = new Date(iso);
    if(isNaN(d)) return s;
    return d.toLocaleString(undefined,{
      month:'short',
      day:'2-digit',
      year:'numeric',
      hour:'numeric',
      minute:'2-digit'
    });
  }

  /* Icons map — removed 'All' */
  const svcIcons = {
    Baptism:    { icon: 'fas fa-tint',        class: 'ico-baptism' },
    Dedication: { icon: 'fas fa-baby',        class: 'ico-dedication' },
    Funeral:    { icon: 'fas fa-cross',       class: 'ico-funeral' },
    'House Blessing': { icon: 'fas fa-home',  class: 'ico-house' },
    Wedding:    { icon: 'fas fa-ring',        class: 'ico-wedding' },
  };
  const weekdayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  /* ---------------- Announcement Modal ---------------- */
  (function(){
    const overlay  = document.getElementById('announcementOverlay');
    const openBtn  = document.getElementById('openAddAnnouncement');
    const closeBtn = document.getElementById('announcementCloseBtn');
    if (!overlay || !openBtn) return;

    const currentBox   = document.getElementById('announcementCurrent');
    const historyBox   = document.getElementById('announcementHistory');
    const msgInput     = document.getElementById('announcementMsg');
    const helperText   = document.getElementById('announcementHelper');
    const saveBtn      = document.getElementById('announcementSaveBtn');
    const saveArchBtn  = document.getElementById('announcementSaveArchiveBtn');
    const updateBtn    = document.getElementById('announcementUpdateBtn');
    const archiveBtn   = document.getElementById('announcementArchiveLatestBtn');

    function setOverlayVisible(flag){
      overlay.setAttribute('data-open', flag ? 'true' : 'false');
      overlay.setAttribute('aria-hidden', flag ? 'false' : 'true');
      if (flag && msgInput){
        setTimeout(()=>{ msgInput.focus(); }, 60);
      }
    }
// ADD THIS: prevent duplicate submits
let __annBusy = false;
function annSetBusy(b){
  __annBusy = !!b;
  [saveBtn, saveArchBtn, updateBtn, archiveBtn].forEach(btn=>{
    if (!btn) return;
    btn.disabled = __annBusy;
    btn.style.opacity = __annBusy ? '0.6' : '';
    btn.style.pointerEvents = __annBusy ? 'none' : '';
  });
}

    
    function confirmThen(callback){
      if (typeof Swal !== 'undefined'){
        Swal.fire({
          title: 'Are you sure?',
          text: 'Please confirm your action.',
          icon: 'warning',
          showCancelButton: true,
        }).then((res)=>{ if(res.isConfirmed) callback(); });
      } else {
        if (window.confirm('Are you sure?')) callback();
      }
    }

    function showAlert(msg){
      window.alert(msg);
    }

    async function loadLatest(){
      if (currentBox) currentBox.innerHTML = '<div class="fm-current-empty">Loading…</div>';
      try{
        const res = await postJson({__action:'fetch_latest_announcement'});
        if (!res || !res.ok || !res.latest){
          currentBox.innerHTML = '<div class="fm-current-empty">No announcement yet.</div>';
          return;
        }
        const a = res.latest;
        const status = (a.Status || '').toString();
        const statusLower = status.toLowerCase();
        const statusClass = statusLower === 'new' ? 'fm-tag-new' :
                            statusLower === 'past' ? 'fm-tag-past' : '';
        const created = formatDateTimeLocal(a.created_at || '');
        currentBox.innerHTML = `
          <div class="fm-current-card">
            <div class="fm-current-status ${statusClass}">
              ${escapeHtml(status || 'Unknown')}
            </div>
            <div class="fm-current-msg">${escapeHtml(a.announcementMsg || '')}</div>
            <div class="fm-current-meta">
              ${created ? 'Created: '+escapeHtml(created) : ''}
            </div>
          </div>
        `;
      }catch(e){
        currentBox.innerHTML = '<div class="fm-current-empty">Failed to load current announcement.</div>';
      }
    }

    async function loadHistoryAndButtons(){
      if (historyBox) historyBox.innerHTML = '<div class="fm-history-empty">Loading…</div>';
      let items = [];
      try{
        const res = await postJson({__action:'fetch_all_announcements'});
        if (!res || !res.ok || !Array.isArray(res.items) || !res.items.length){
          historyBox.innerHTML = '<div class="fm-history-empty">No announcement history yet.</div>';
          if (helperText) helperText.textContent = 'Create a new announcement to get started.';
          if (updateBtn) updateBtn.style.display = 'none';
          if (archiveBtn) archiveBtn.style.display = 'none';
          if (msgInput) msgInput.value = '';
          return;
        }
        items = res.items;
      }catch(e){
        historyBox.innerHTML = '<div class="fm-history-empty">Failed to load history.</div>';
        return;
      }

      const rows = items.map(row=>{
        const status = (row.Status || '').toString();
        const sLower = status.toLowerCase();
        const sClass = sLower === 'new' ? 'fm-tag-new' :
                       sLower === 'past' ? 'fm-tag-past' : '';
        const created = formatDateTimeLocal(row.created_at || '');
        return `
          <div class="fm-history-row">
            <div class="fm-history-status ${sClass}">
              ${escapeHtml(status)}
            </div>
            <div class="fm-history-msg">${escapeHtml(row.announcementMsg || '')}</div>
            <div class="fm-history-meta">${created || ''}</div>
          </div>
        `;
      }).join('');
      historyBox.innerHTML = rows;

      // find latest "New" announcement (items already sorted DESC by id)
      const latestNew = items.find(r => (r.Status || '').toString().toLowerCase() === 'new') || null;
      if (latestNew){
        if (helperText){
          helperText.textContent = 'Editing latest “New” announcement. Use "Update Latest New" to save changes or "Archive Latest New" to mark it as Past.';
        }
        if (updateBtn) updateBtn.style.display = 'inline-flex';
        if (archiveBtn) archiveBtn.style.display = 'inline-flex';
        if (msgInput)   msgInput.value = latestNew.announcementMsg || '';
      }else{
        if (helperText){
          helperText.textContent = 'No active “New” announcement. Saving will create a fresh New record.';
        }
        if (updateBtn) updateBtn.style.display = 'none';
        if (archiveBtn) archiveBtn.style.display = 'none';
        // leave textarea as-is so user doesn’t lose typed text
      }
    }

    async function refreshAll(){
      await Promise.all([loadLatest(), loadHistoryAndButtons()]);
    }

async function handleCreate(action){
  if (__annBusy) return;
  const text = (msgInput?.value || '').trim();
  if (!text){
    showAlert('Please enter an announcement message.');
    if (msgInput) msgInput.focus();
    return;
  }
  const payload = {__action: action, announcementMsg: text};
  try{
    annSetBusy(true);
    const res = await postJson(payload);
    if (!res || !res.ok){
      showAlert(res && res.error ? res.error : 'Failed to save announcement.');
      return;
    }
    showAlert(action === 'create_announcement_and_archive_prev'
      ? 'Announcement saved and previous one archived.'
      : 'Announcement saved as "New".');
    msgInput.value = '';
    await refreshAll();
  }catch(e){
    showAlert('Network error while saving announcement.');
  }finally{
    annSetBusy(false);
  }
}


async function handleUpdateLatest(){
  if (__annBusy) return;
  const text = (msgInput?.value || '').trim();
  if (!text){
    showAlert('Message cannot be empty when updating the latest “New” announcement.');
    if (msgInput) msgInput.focus();
    return;
  }
  try{
    annSetBusy(true);
    const res = await postJson({
      __action:'update_latest_new_message',
      announcementMsg: text
    });
    if (!res || !res.ok){
      showAlert(res && res.error ? res.error : 'Failed to update the latest "New" announcement.');
      return;
    }
    showAlert('Latest “New” announcement updated.');
    await refreshAll();
  }catch(e){
    showAlert('Network error while updating announcement.');
  }finally{
    annSetBusy(false);
  }
}


async function handleArchiveLatest(){
  // Before:
  // if (!window.confirm('Archive the latest “New” announcement and mark it as Past?')) return;

  const ok = await swalConfirm({
    title: 'Archive latest “New”?',
    text: 'This will mark the latest New announcement as Past.',
    confirmText: 'Archive',
    cancelText: 'Cancel',
    icon: 'warning'
  });
  if (!ok) return;

  try {
    const res = await postJson({__action:'archive_latest_new'});
    if (!res || !res.ok){
      showAlert(res && res.error ? res.error : 'Failed to archive the latest "New" announcement.', 'error');
      return;
    }
    swalToast('Latest “New” archived.', 'success');
    await refreshAll();
  } catch(e) {
    showAlert('Network error while archiving announcement.', 'error');
  }
}



    // open / close / background click
    openBtn.addEventListener('click', (e)=>{
      e.preventDefault();
      setOverlayVisible(true);
      refreshAll();
    });
    if (closeBtn){
      closeBtn.addEventListener('click', ()=> setOverlayVisible(false));
    }
    overlay.addEventListener('click', (e)=>{
      if (e.target === overlay){
        setOverlayVisible(false);
      }
    });

    // buttons
    if (saveBtn){
      saveBtn.addEventListener('click', ()=> confirmThen(()=> handleCreate('create_announcement')));
    }
    if (saveArchBtn){
      saveArchBtn.addEventListener('click', ()=> confirmThen(()=> handleCreate('create_announcement_and_archive_prev')));
    }
    if (updateBtn){
      updateBtn.addEventListener('click', ()=> confirmThen(()=> handleUpdateLatest()));
    }
    if (archiveBtn){
      archiveBtn.addEventListener('click', ()=> confirmThen(()=> handleArchiveLatest()));
    }

  })();

  /* ---------------- Notifications ---------------- */
  (function(){
    const bell = document.getElementById('notifBell');
    const dropdown = document.getElementById('notifDropdown');
    const badge = document.getElementById('notifBadge');
    const markRead = document.getElementById('notifMarkRead');
    const clearAll = document.getElementById('notifClearAll');
    if (!bell || !dropdown) return;
    const openDD = ()=> dropdown.setAttribute('aria-hidden','false');
    const closeDD = ()=> dropdown.setAttribute('aria-hidden','true');
    const isOpen = ()=> dropdown.getAttribute('aria-hidden') === 'false';
    bell.addEventListener('click', (e)=>{ e.stopPropagation(); isOpen() ? closeDD() : openDD(); });
    document.addEventListener('click', (e)=>{ if (!dropdown.contains(e.target) && !bell.contains(e.target)) closeDD(); });
    document.addEventListener('keydown', (e)=>{ if (e.key==='Escape') closeDD(); });

    if (markRead) {
      markRead.addEventListener('click', async ()=>{
        try { 
          const fd = new FormData(); 
          fd.append('__action','notif_mark_all_read');
          const r = await fetch(location.pathname, { method:'POST', body:fd });
          await r.json().catch(()=>({}));
        } catch(e) {}
        if (badge) badge.style.display='none';
        dropdown.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
      });
    }

    if (clearAll) {
      clearAll.addEventListener('click', async ()=>{
        try {
          const fd = new FormData();
          fd.append('__action','notif_clear_all');
          const r = await fetch(location.pathname, { method:'POST', body:fd });
          await r.json().catch(()=>({}));
        } catch(e){}
        if (badge) badge.style.display='none';
        const list = dropdown.querySelector('.notif-list');
        if (list) list.innerHTML = '';
        const empty = dropdown.querySelector('.notif-empty');
        if (!empty) {
          const div = document.createElement('div');
          div.className = 'notif-empty';
          div.innerHTML = '<img src="css/image/empty.png" alt="" aria-hidden="true"><div>No notifications</div>';
          dropdown.appendChild(div);
        }
      });
    }
  })();

  /* ---------------- Pie Chart ---------------- */
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
        responsive: false, animation: false,
        plugins: { legend: { display: false },
          tooltip: { callbacks: { label: (c) => {
            const label = c.label || ''; const val = c.parsed || 0;
            const total = c.dataset.data.reduce((a,b)=>a+b,0) || 1;
            const pct = ((val/total)*100).toFixed(0);
            return `${label}: ${val} (${pct}%)`;
          }}}
        }
      }
    });
  })();

  /* ---------------- Titles ---------------- */
  (function(){
    const serviceListTitle = document.getElementById('serviceListTitle');
    const apptListTitle    = document.getElementById('apptListTitle');
    const eventsTitle      = document.getElementById('eventsTitle');
    if (serviceListTitle) serviceListTitle.textContent = "Today Service Schedule (All Services)";
    if (apptListTitle)    apptListTitle.textContent    = "Today Appointment Schedule (Wedding)";
    if (eventsTitle)      eventsTitle.textContent      = "Today's Event";
  })();

  /* ---------------- Weekly modal & detail ---------------- */
  const weeklyModal = document.getElementById('weeklyModal');
  const modalTitle  = document.getElementById('weeklyModalTitle');
  const listCol     = document.getElementById('weeklyListCol');
  const detailCol   = document.getElementById('weeklyDetailCol');
  const modalClose  = document.getElementById('weeklyModalClose');
  const filtersBox  = document.getElementById('weeklyFilters');
  let cache = { this_week:null, next_week:null };
  let currentMode = 'this_week';
  let currentFilter = 'Baptism'; // removed "All"

  function postJson(data){
    const fd = new FormData(); Object.entries(data).forEach(([k,v])=>fd.append(k,v));
    return fetch(location.pathname, { method:'POST', body: fd }).then(r=>r.json());
  }

  function buildDetailCard(table, data){
    const svcLabel = ({
      'service_baptism':'Baptism',
      'service_dedication':'Dedication',
      'service_funeral':'Funeral',
      'service_house':'House Blessing',
      'service_wedding':'Wedding'
    })[table] || 'Service';

    const meta = (svcIcons[svcLabel] || {icon:'fas fa-layer-group', class:''});
    const header = `
      <div class="detail-header">
        <div class="ico ${meta.class}"><i class="${meta.icon}"></i></div>
        <div class="title">${escapeHtml(svcLabel)} Information</div>
        <span class="chip">Scheduled</span>
      </div>
    `;

    const kv = (k,v)=>`<div class="k">${escapeHtml(k)}</div><div class="v">${escapeHtml(v||'')}</div>`;
    let body = '';

    if (table==='service_dedication'){
      body += `
        <div class="detail-section">
          <div class="detail-section-title"><i class="fas fa-baby"></i><span>Child</span></div>
          <div class="detail-kvgrid">
            ${kv('First Name', data.child_firstname)}
            ${kv('Middle Name', data.child_middlename)}
            ${kv('Last Name', data.child_lastname)}
            ${kv('Extension', data.child_ext)}
          </div>
        </div>
        <div class="detail-section">
          <div class="detail-section-title"><i class="far fa-user"></i><span>Guardian</span></div>
          <div class="detail-kvgrid">
            ${kv('First Name', data.guardian_firstname)}
            ${kv('Middle Name', data.guardian_middlename)}
            ${kv('Last Name', data.guardian_lastname)}
            ${kv('Extension', data.guardian_ext)}
            ${kv('Contact Number', data.contact_number)}
          </div>
        </div>
        <div class="detail-section">
          <div class="detail-section-title"><i class="far fa-calendar-alt"></i><span>Service</span></div>
          <div class="detail-kvgrid">
            ${kv('Service Date', formatDate(data.service_date))}
            ${kv('Service Time', formatTime(data.service_time))}
          </div>
        </div>`;
    }

    if (table==='service_baptism'){
      body += `
        <div class="detail-section">
          <div class="detail-section-title"><i class="far fa-user"></i><span>Baptized</span></div>
          <div class="detail-kvgrid">
            ${kv('First Name', data.baptized_firstname)}
            ${kv('Middle Name', data.baptized_middlename)}
            ${kv('Last Name', data.baptized_lastname)}
            ${kv('Extension', data.baptized_ext)}
          </div>
        </div>
        <div class="detail-section">
          <div class="detail-section-title"><i class="far fa-user"></i><span>Guardian</span></div>
          <div class="detail-kvgrid">
            ${kv('First Name', data.guardian_firstname)}
            ${kv('Middle Name', data.guardian_middlename)}
            ${kv('Last Name', data.guardian_lastname)}
            ${kv('Extension', data.guardian_ext)}
            ${kv('Contact Number', data.guardian_contactnum)}
          </div>
        </div>
        <div class="detail-section">
          <div class="detail-section-title"><i class="far fa-calendar-alt"></i><span>Service</span></div>
          <div class="detail-kvgrid">
            ${kv('Service Date', formatDate(data.service_date))}
            ${kv('Service Time', formatTime(data.service_time))}
          </div>
        </div>`;
    }

    if (table==='service_funeral'){
      body += `
        <div class="detail-section">
          <div class="detail-section-title"><i class="fas fa-cross"></i><span>Deceased</span></div>
          <div class="detail-kvgrid">
            ${kv('First Name', data.deceased_firstname)}
            ${kv('Middle Name', data.deceased_middlename)}
            ${kv('Last Name', data.deceased_lastname)}
            ${kv('Extension', data.deceased_ext)}
            ${kv('Home Address', data.home_address)}
            ${kv('Funeral Date', formatDate(data.funeral_date))}
          </div>
        </div>
        <div class="detail-section">
          <div class="detail-section-title"><i class="far fa-calendar-alt"></i><span>Service</span></div>
          <div class="detail-kvgrid">
            ${kv('Service Date', formatDate(data.service_date))}
            ${kv('Service Time', formatTime(data.service_time))}
          </div>
        </div>
        <div class="detail-section">
          <div class="detail-section-title"><i class="far fa-user"></i><span>Requester</span></div>
          <div class="detail-kvgrid">
            ${kv('First Name', data.individual_firstname)}
            ${kv('Middle Name', data.individual_middlename)}
            ${kv('Last Name', data.individual_lastname)}
            ${kv('Extension', data.individual_ext)}
            ${kv('Contact Number', data.contact_number)}
          </div>
        </div>`;
    }

    if (table==='service_house'){
      body += `
        <div class="detail-section">
          <div class="detail-section-title"><i class="far fa-user"></i><span>Owner</span></div>
          <div class="detail-kvgrid">
            ${kv('First Name', data.owner_firstname)}
            ${kv('Middle Name', data.owner_middlename)}
            ${kv('Last Name', data.owner_lastname)}
            ${kv('Extension', data.owner_ext)}
            ${kv('Contact Number', data.contact_number)}
            ${kv('Home Address', data.home_address)}
          </div>
        </div>
        <div class="detail-section">
          <div class="detail-section-title"><i class="far fa-calendar-alt"></i><span>Service</span></div>
          <div class="detail-kvgrid">
            ${kv('Service Date', formatDate(data.service_date))}
            ${kv('Service Time', formatTime(data.service_time))}
          </div>
        </div>`;
    }

    if (table==='service_wedding'){
      body += `
        <div class="detail-section">
          <div class="detail-section-title"><i class="fas fa-female"></i><span>Bride</span></div>
          <div class="detail-kvgrid">
            ${kv('First Name', data.bride_firstname)}
            ${kv('Middle Name', data.bride_middlename)}
            ${kv('Last Name', data.bride_lastname)}
            ${kv('Extension', data.bride_extension)}
            ${kv('Contact Number', data.contact_number)}
          </div>
        </div>
        <div class="detail-section">
          <div class="detail-section-title"><i class="fas fa-male"></i><span>Groom</span></div>
          <div class="detail-kvgrid">
            ${kv('First Name', data.groom_firstname)}
            ${kv('Middle Name', data.groom_middlename)}
            ${kv('Last Name', data.groom_lastname)}
            ${kv('Extension', data.groom_extension)}
          </div>
        </div>
        <div class="detail-section">
          <div class="detail-section-title"><i class="far fa-calendar-check"></i><span>Appointment</span></div>
          <div class="detail-kvgrid">
            ${kv('Date', formatDate(data.appointment_date))}
            ${kv('Time', formatTime(data.appointment_time))}
            ${kv('Status', data.status)}
          </div>
        </div>
        <div class="detail-section">
          <div class="detail-section-title"><i class="far fa-calendar-alt"></i><span>Service</span></div>
          <div class="detail-kvgrid">
            ${kv('Service Date', formatDate(data.service_date))}
            ${kv('Service Time', formatTime(data.service_time))}
          </div>
        </div>`;
    }

    // Generic contact info section (if available in the service row)
    body += `
      <div class="detail-section">
        <div class="detail-section-title"><i class="fas fa-address-book"></i><span>Contact</span></div>
        <div class="detail-kvgrid">
          ${kv('Contact Number', data.contact_number)}
          ${kv('Email Address', data.email_address)}
        </div>
      </div>`;

    return `<div class="detail-card">${header}${body}</div>`;
  }

  async function loadDetailInto(targetEl, table, id){
    if (!targetEl) return;
    targetEl.innerHTML = '<div class="detail-title" style="font-weight:800;margin-bottom:6px;">Details</div><div class="detail-kv"><span>Loading…</span><span></span></div>';
    try {
      const j = await postJson({__action:'fetch_service_detail', table, id});
      if (!j || !j.ok || !j.data){
        targetEl.innerHTML = '<div class="detail-title" style="font-weight:800;margin-bottom:6px;">Details</div><div class="detail-kv"><span>Failed to load.</span><span></span></div>';
        return;
      }
      targetEl.innerHTML = buildDetailCard(table, j.data);
    } catch(e){
      targetEl.innerHTML = '<div class="detail-title" style="font-weight:800;margin-bottom:6px;">Details</div><div class="detail-kv"><span>Network error.</span><span></span></div>';
    }
  }
  async function loadDetail(table, id){ return loadDetailInto(detailCol, table, id); }

  function renderMiniTile(label, count, mode, targetEl){
    const meta = svcIcons[label] || {icon:'fas fa-layer-group', class:''};
    const tile = document.createElement('div');
    tile.className = 'mini-tile';
    tile.setAttribute('data-mode', mode);
    tile.setAttribute('data-svc', label);
    const plural = count === 1 ? 'service' : 'services';
    const hoverText = `${label}: ${count} ${plural}`;
    tile.setAttribute('data-hover', hoverText);
    tile.innerHTML = `
      <div class="mini-ico ${meta.class}"><i class="${meta.icon}"></i></div>
      <div class="mini-count">${count}</div>
      <div class="mini-tooltip">${hoverText}</div>
    `;
    tile.addEventListener('click', ()=> openWeeklyModal(mode, label));
    targetEl.appendChild(tile);
  }

  function renderTilesInto(el, data, mode){
    el.innerHTML = '';
    // Removed the "All" tile — show only specific services
    renderMiniTile('Baptism', data.counts?.Baptism||0, mode, el);
    renderMiniTile('Dedication', data.counts?.Dedication||0, mode, el);
    renderMiniTile('Funeral', data.counts?.Funeral||0, mode, el);
    renderMiniTile('House Blessing', data.counts?.['House Blessing']||0, mode, el);
    renderMiniTile('Wedding', data.counts?.Wedding||0, mode, el);
  }

  function sortByDate(items){ return [...items].sort((a,b)=> (a.sort_key||'').localeCompare(b.sort_key||''));}
  function weekdayLbl(dateStr){
    const d = new Date(dateStr+'T00:00:00');
    const wd = isNaN(d) ? '' : weekdayNames[d.getDay()];
    return wd || '';
  }
  function renderListInModal(dataset){
    const source = dataset.by_service?.[currentFilter] || [];
    const items = sortByDate(source);
    if (!items.length){
      listCol.innerHTML = '<div class="list-item" style="justify-content:center;">No data for selected service.</div>';
      detailCol.innerHTML = '<div class="detail-title" style="font-weight:800;margin-bottom:6px;">Details</div><div class="detail-kv"><span>—</span><span></span></div>';
      return;
    }
    listCol.innerHTML = items.map(it=>{
      const dstr = formatDate(it.date || '');
      const wd = weekdayLbl(it.date);
      const t = formatTime(it.time||'');
      return `
        <div class="list-item" data-table="${escapeHtml(it.table)}" data-id="${escapeHtml(it.pk)}">
          <div class="weekly-icon ${(svcIcons[it.service]?.class)||''}"><i class="${(svcIcons[it.service]?.icon)||'fas fa-layer-group'}"></i></div>
          <div style="flex:1;min-width:0;">
            <div class="name" title="${escapeHtml(it.full_name)}">${escapeHtml(it.full_name)}</div>
            <div class="date-time">${escapeHtml(dstr)}${t? ' • '+escapeHtml(t): ''}</div>
            <div class="contact">Contact: ${escapeHtml(it.contact||'—')}</div>
          </div>
          <div class="weekday">${wd ? wd+' Schedule' : ''}</div>
        </div>
      `;
    }).join('');
    listCol.querySelectorAll('.list-item').forEach(li=>{
      li.addEventListener('click', ()=> loadDetail(li.getAttribute('data-table'), li.getAttribute('data-id')));
    });
    const first = listCol.querySelector('.list-item'); if (first) first.click();
  }
  function openWeeklyModal(mode, svc='Baptism'){
    if (!cache[mode] || !cache[mode].ok) return;
    currentMode = mode; currentFilter = svc;
    weeklyModal.setAttribute('data-open','true');
    const title = mode==='this_week' ? 'This Week' : 'Next Week';
    modalTitle.textContent = `${title} — ${svc}`;
    filtersBox.innerHTML = '';
    ['Baptism','Dedication','Funeral','House Blessing','Wedding'].forEach(k=>{
      const b = document.createElement('button');
      b.className = 'btn' + (k===svc?' active':''); b.textContent = k;
      b.addEventListener('click', ()=>{ currentFilter=k; modalTitle.textContent = `${title} — ${k}`; renderListInModal(cache[mode]); });
      filtersBox.appendChild(b);
    });
    renderListInModal(cache[mode]);
  }
  modalClose.addEventListener('click', ()=> weeklyModal.setAttribute('data-open','false'));
  weeklyModal.addEventListener('click', (e)=>{ if (e.target===weeklyModal) weeklyModal.setAttribute('data-open','false'); });

  /* Fill weekly stats */
  (async function hydrateWeeklyIntoStats(){
    const thisWeekTotal = document.getElementById('thisWeekTotal');
    const thisWeekRange = document.getElementById('thisWeekRange');
    const thisWeekTiles = document.getElementById('thisWeekTiles');

    const nextWeekTotal = document.getElementById('nextWeekTotal');
    const nextWeekRange = document.getElementById('nextWeekRange');
    const nextWeekTiles = document.getElementById('nextWeekTiles');

    function safeFillRange(el, s,e){
      if (el) el.textContent = `${formatDate(s)} – ${formatDate(e)}`;
    }

    try {
      const w = await postJson({__action:'fetch_weekly_services', mode:'this_week'});
      if (w && w.ok){
        cache.this_week = w;
        if (thisWeekTotal) thisWeekTotal.textContent = w.total ?? 0;
        if (w.range) safeFillRange(thisWeekRange, w.range.start, w.range.end);
        if (thisWeekTiles) renderTilesInto(thisWeekTiles, w, 'this_week');
      } else {
        if (thisWeekRange) thisWeekRange.textContent = 'No data';
        if (thisWeekTiles) thisWeekTiles.innerHTML = '<div class="mini-tile"><div class="mini-count">No data</div></div>';
      }
    } catch (e) {
      if (thisWeekRange) thisWeekRange.textContent = 'Failed to load';
      if (thisWeekTiles) thisWeekTiles.innerHTML = '<div class="mini-tile"><div class="mini-count">Error</div></div>';
    }

    try {
      const n = await postJson({__action:'fetch_weekly_services', mode:'next_week'});
      if (n && n.ok){
        cache.next_week = n;
        if (nextWeekTotal) nextWeekTotal.textContent = n.total ?? 0;
        if (n.range) safeFillRange(nextWeekRange, n.range.start, n.range.end);
        if (nextWeekTiles) renderTilesInto(nextWeekTiles, n, 'next_week');
      } else {
        if (nextWeekRange) nextWeekRange.textContent = 'No data';
        if (nextWeekTiles) nextWeekTiles.innerHTML = '<div class="mini-tile"><div class="mini-count">No data</div></div>';
      }
    } catch (e) {
      if (nextWeekRange) nextWeekRange.textContent = 'Failed to load';
      if (nextWeekTiles) nextWeekTiles.innerHTML = '<div class="mini-tile"><div class="mini-count">Error</div></div>';
    }
  })();

  /* Today modals */
  const serviceListTitle = document.getElementById('serviceListTitle');
  const apptListTitle    = document.getElementById('apptListTitle');

  const tsmOverlay  = document.getElementById('todayServicesModal');
  const tsmClose    = document.getElementById('todayServicesModalClose');
  const tsmListCol  = document.getElementById('todayServicesListCol');
  const tsmDetailCol= document.getElementById('todayServicesDetailCol');

  function openTodayServicesModal(){
    const items = Array.isArray(window.__TODAY_SERVICES__)? window.__TODAY_SERVICES__ : [];
    tsmOverlay.setAttribute('data-open','true');
    if (!items.length){
      tsmListCol.innerHTML = '<div class="list-item" style="justify-content:center;">No scheduled services for today.</div>';
      tsmDetailCol.innerHTML = '<div class="detail-title" style="font-weight:800;margin-bottom:6px;">Details</div><div class="detail-kv"><span>—</span><span></span></div>';
      return;
    }
    tsmListCol.innerHTML = items.map(it => `
      <div class="list-item" data-table="${escapeHtml(it.table)}" data-id="${escapeHtml(it.pk)}">
        <div class="weekly-icon ${(svcIcons[it.service]?.class)||''}"><i class="${(svcIcons[it.service]?.icon)||'fas fa-layer-group'}"></i></div>
        <div style="flex:1;min-width:0;">
          <div class="name" title="${escapeHtml(it.full_name)}">${escapeHtml(it.full_name)}</div>
          <div class="date-time">${formatDate(it.service_date || '')}${(it.time_fmt ? ' • '+escapeHtml(it.time_fmt) : (it.service_time ? ' • '+escapeHtml(formatTime(it.service_time)) : ''))}</div>
        </div>
        <div class="weekday">Today</div>
      </div>
    `).join('');
    tsmListCol.querySelectorAll('.list-item').forEach(li=>{
      li.addEventListener('click', ()=> loadDetailInto(tsmDetailCol, li.getAttribute('data-table'), li.getAttribute('data-id')));
    });
    const first = tsmListCol.querySelector('.list-item'); if (first) first.click();
  }
  tsmClose.addEventListener('click', ()=> tsmOverlay.setAttribute('data-open','false'));
  tsmOverlay.addEventListener('click', (e)=>{ if (e.target===tsmOverlay) tsmOverlay.setAttribute('data-open','false'); });

  const twmOverlay  = document.getElementById('todayWeddingModal');
  const twmClose    = document.getElementById('todayWeddingModalClose');
  const twmListCol  = document.getElementById('todayWeddingListCol');
  const twmDetailCol= document.getElementById('todayWeddingDetailCol');

  function openTodayWeddingModal(){
    const items = Array.isArray(window.__TODAY_WEDDING_APPTS__)? window.__TODAY_WEDDING_APPTS__ : [];
    twmOverlay.setAttribute('data-open','true');
    if (!items.length){
      twmListCol.innerHTML = '<div class="list-item" style="justify-content:center;">No wedding appointments for today.</div>';
      twmDetailCol.innerHTML = '<div class="detail-title" style="font-weight:800;margin-bottom:6px;">Details</div><div class="detail-kv"><span>—</span><span></span></div>';
      return;
    }
    twmListCol.innerHTML = items.map(it => `
      <div class="list-item" data-table="service_wedding" data-id="${escapeHtml(it.pk)}">
        <div class="weekly-icon ${svcIcons['Wedding'].class}"><i class="${svcIcons['Wedding'].icon}"></i></div>
        <div style="flex:1;min-width:0;">
          <div class="name" title="${escapeHtml(it.full_name)}">${escapeHtml(it.full_name)}</div>
          <div class="date-time">Today • ${escapeHtml(it.time_fmt || formatTime(it.appointment_time) || '')}</div>
        </div>
        <div class="weekday">Appointment</div>
      </div>
    `).join('');
    twmListCol.querySelectorAll('.list-item').forEach(li=>{
      li.addEventListener('click', ()=> loadDetailInto(twmDetailCol, 'service_wedding', li.getAttribute('data-id')));
    });
    const first = twmListCol.querySelector('.list-item'); if (first) first.click();
  }
  twmClose.addEventListener('click', ()=> twmOverlay.setAttribute('data-open','false'));
  twmOverlay.addEventListener('click', (e)=>{ if (e.target===twmOverlay) twmOverlay.setAttribute('data-open','false'); });

  if (serviceListTitle) serviceListTitle.addEventListener('click', openTodayServicesModal);
  if (apptListTitle)    apptListTitle.addEventListener('click', openTodayWeddingModal);

  /* Gender modal logic */
  const genderModal = document.getElementById('genderModal');
  const genderModalTitle = document.getElementById('genderModalTitle');
  const genderModalMeta  = document.getElementById('genderModalMeta');
  const genderModalClose = document.getElementById('genderModalClose');
  const genderModalList  = document.getElementById('genderModalList');
  const genderModalPill  = document.getElementById('genderModalPill');
  const genderModalPillText = document.getElementById('genderModalPillText');
  const genderModalSearch = document.getElementById('genderModalSearch');
  const genderBoxMale   = document.getElementById('genderBoxMale');
  const genderBoxFemale = document.getElementById('genderBoxFemale');

  let genderCache = { male:null, female:null };
  let genderCurrent = 'male';
  let genderCurrentList = [];

  function genderLabel(g){ return g === 'female' ? 'Female' : 'Male'; }
  function genderIcon(g){ return g === 'female' ? '<i class="fas fa-female"></i>' : '<i class="fas fa-male"></i>'; }
  function renderGenderList(items, term){
    if (!items || !items.length){
      genderModalList.innerHTML = '<div class="gender-empty">No individuals found for this gender.</div>';
      genderModalMeta.textContent = '0 records';
      return;
    }
    const q = (term||'').trim().toLowerCase();
    const filtered = q ? items.filter(it => (it.full_name||'').toLowerCase().includes(q)) : items;
    genderCurrentList = filtered;
    genderModalMeta.textContent = `${filtered.length} record${filtered.length !== 1 ? 's' : ''}`;
    if (!filtered.length){
      genderModalList.innerHTML = '<div class="gender-empty">No match for your search.</div>';
      return;
    }
    genderModalList.innerHTML = filtered.map(it=>{
      const name = escapeHtml(it.full_name || 'Unknown');
      const gRaw = (it.individual_gender || '').toLowerCase();
      const gNorm = gRaw.startsWith('f') ? 'female' : (gRaw.startsWith('m') ? 'male' : genderCurrent);
      return `
        <div class="gender-row">
          <div class="gender-avatar ${gNorm}">
            ${genderIcon(gNorm)}
          </div>
          <div class="gender-row-main">
            <div class="gender-row-name">${name}</div>
            <div class="gender-row-sub">ID: ${escapeHtml(it.individual_id || '')}</div>
          </div>
          <div class="gender-row-tag">${genderLabel(gNorm)}</div>
        </div>
      `;
    }).join('');
  }

  async function openGenderModal(gender){
    genderCurrent = gender === 'female' ? 'female' : 'male';
    genderModal.setAttribute('data-open','true');
    genderModalSearch.value = '';
    const label = genderLabel(genderCurrent);
    genderModalTitle.textContent = `${label} Individuals`;
    genderModalPillText.textContent = label.toUpperCase();
    genderModalPill.classList.toggle('male', genderCurrent === 'male');
    genderModalPill.classList.toggle('female', genderCurrent === 'female');

    genderModalList.innerHTML = '<div class="gender-empty">Loading...</div>';
    try {
      if (!genderCache[genderCurrent]){
        const res = await postJson({__action:'fetch_individuals_by_gender', gender: genderCurrent});
        if (!res || !res.ok){
          genderModalList.innerHTML = '<div class="gender-empty">Failed to load data.</div>';
          genderModalMeta.textContent = '';
          return;
        }
        genderCache[genderCurrent] = res.items || [];
      }
      renderGenderList(genderCache[genderCurrent], '');
    } catch(e){
      genderModalList.innerHTML = '<div class="gender-empty">Network error while loading data.</div>';
      genderModalMeta.textContent = '';
    }
  }

  if (genderBoxMale)  genderBoxMale.addEventListener('click', ()=> openGenderModal('male'));
  if (genderBoxFemale)genderBoxFemale.addEventListener('click', ()=> openGenderModal('female'));

  genderModalClose.addEventListener('click', ()=> genderModal.setAttribute('data-open','false'));
  genderModal.addEventListener('click', (e)=>{ if (e.target===genderModal) genderModal.setAttribute('data-open','false'); });
  if (genderModalSearch){
    genderModalSearch.addEventListener('input', (e)=>{
      const cacheList = genderCache[genderCurrent] || [];
      renderGenderList(cacheList, e.target.value);
    });
  }

  /* ESC to close modals */
  document.addEventListener('keydown', (e)=>{
    if (e.key === 'Escape'){
      weeklyModal.setAttribute('data-open','false');
      document.getElementById('todayServicesModal').setAttribute('data-open','false');
      document.getElementById('todayWeddingModal').setAttribute('data-open','false');
      genderModal.setAttribute('data-open','false');
    }
  });

  /* Row click opens the corresponding modal */
  document.getElementById('servicesList')?.addEventListener('click', (e)=>{
    const row = e.target.closest('.table-row');
    if (row) openTodayServicesModal();
  });
  document.getElementById('weddingApptList')?.addEventListener('click', (e)=>{
    const row = e.target.closest('.table-row');
    if (row) openTodayWeddingModal();
  });
  /* ========= SweetAlert2 helpers ========= */

/** Quick top-right toast (success/info/error/warning/question) */
function swalToast(title = 'Saved', icon = 'success') {
  return Swal.fire({
    title,
    icon,                 // 'success' | 'info' | 'error' | 'warning' | 'question'
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 2200,
    timerProgressBar: true,
  });
}

/** Center modal alert (replaces window.alert) */
function swalAlert(message = 'Done', icon = 'info', title = '') {
  return Swal.fire({
    title: title || undefined,
    text: message,
    icon,
    confirmButtonText: 'OK',
    confirmButtonColor: '#0b214b',
  });
}

/** Confirm dialog that returns a boolean Promise (replaces window.confirm) */
async function swalConfirm({
  title = 'Are you sure?',
  text = '',
  confirmText = 'Yes',
  cancelText = 'Cancel',
  icon = 'question'
} = {}) {
  const res = await Swal.fire({
    title,
    text,
    icon,
    showCancelButton: true,
    confirmButtonText: confirmText,
    cancelButtonText: cancelText,
    reverseButtons: true,
    confirmButtonColor: '#0b214b',
  });
  return !!res.isConfirmed;
}

/* Optional: keep your old name but route to SweetAlert */
function showAlert(msg, icon = 'info') {
  return swalAlert(msg, icon);
}
  </script>
  </body>
  </html>
