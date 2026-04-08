<?php
/* includes/navigation.php
   Navigation header with:
   - Live Mass indicator
   - Profile dropdown
   - Notification SIDE SLIDE
   - Notifications are for INDIVIDUAL users only
*/

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/db-connection.php';

/* Live status badge for LIVE MASS */
$showLiveIcon = false;
if (!empty($db_connection)) {
    $sql = "SELECT live_status FROM multimedia ORDER BY livemassId DESC LIMIT 1";
    if ($result = mysqli_query($db_connection, $sql)) {
        if ($row = mysqli_fetch_assoc($result)) {
            if (strtolower(trim($row['live_status'])) === 'active') {
                $showLiveIcon = true;
            }
        }
        mysqli_free_result($result);
    }
}

/* ==============================
   Fetch SERVICES from DB
   Table: service_list
   Display in dropdown: service_name only
   ============================== */
$dbServices = [];
if (!empty($db_connection)) {
    $sqlServices = "SELECT service_id, service_name FROM service_list ORDER BY service_name ASC";
    if ($stmt = mysqli_prepare($db_connection, $sqlServices)) {
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $dbServices[] = $r;
            }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);
    }
}

/* ==============================
   Fetch MINISTRIES from DB  ✅ UPDATED
   Table: ministry_table
   Display in dropdown: ministry_name only
   Link must go to: new_ministry.php?ministry=...
   ============================== */
$dbMinistries = [];
if (!empty($db_connection)) {
    $sqlMinistries = "SELECT ministry_id, ministry_name FROM ministry_table ORDER BY ministry_name ASC";
    if ($stmt = mysqli_prepare($db_connection, $sqlMinistries)) {
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $dbMinistries[] = $r;
            }
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);
    }
}

/**
 * INDIVIDUAL notifications
 * Tables:
 *  - notifications
 *  - notification_recipients
 *
 * Rules:
 *  - notification_recipients.user_type = 'individual'
 *  - notification_recipients.user_id   = current individual_id
 *  - We SHOW all rows where status != 'clear'
 *  - We COUNT rows where status = 'unread'
 */
$individualNotifications = [];
$individualUnreadCount   = 0;

if (isset($_SESSION['individual_id']) && !empty($db_connection)) {
    $iid = (int) $_SESSION['individual_id'];

    // ---- unread count (for red pill badge) ----
    $sqlCount = "
        SELECT COUNT(*) AS cnt
        FROM notification_recipients
        WHERE user_type = 'individual'
          AND user_id   = ?
          AND status    = 'unread'
    ";
    if ($stmt = mysqli_prepare($db_connection, $sqlCount)) {
        mysqli_stmt_bind_param($stmt, "i", $iid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $cnt);
        if (mysqli_stmt_fetch($stmt)) {
            $individualUnreadCount = (int) $cnt;
        }
        mysqli_stmt_close($stmt);
    }

    // ---- latest notifications (read + unread, but not clear) ----
    $sql = "
        SELECT 
            n.id,
            n.title,
            n.body,
            n.created_at,
            nr.status
        FROM notifications AS n
        INNER JOIN notification_recipients AS nr
            ON nr.notification_id = n.id
        WHERE 
            nr.user_type = 'individual'
            AND nr.user_id = ?
            AND nr.status <> 'clear'
        ORDER BY n.created_at DESC
        LIMIT 20
    ";

    if ($stmt = mysqli_prepare($db_connection, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $iid);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $individualNotifications[] = $row;
            }
            mysqli_free_result($result);
        }
        mysqli_stmt_close($stmt);
    }
}

/* Fetch a friendly display name for the dropdown header (optional) */
$__fullName = null;
if (isset($_SESSION['individual_id']) && !empty($db_connection)) {
    $iid = (int) $_SESSION['individual_id'];
    if ($stmt = mysqli_prepare(
        $db_connection,
        "SELECT individual_firstname, individual_middlename, individual_lastname, individual_extensionname
         FROM individual_table WHERE individual_id = ? LIMIT 1"
    )) {
        mysqli_stmt_bind_param($stmt, "i", $iid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $fn, $mn, $ln, $ex);
        if (mysqli_stmt_fetch($stmt)) {
            $parts = array_filter([$fn, $mn, $ln, $ex], fn($v) => trim($v ?? '') !== '');
            $__fullName = implode(' ', $parts);
        }
        mysqli_stmt_close($stmt);
    }
}
if (!$__fullName) {
    $__fullName = htmlspecialchars($_SESSION['individual_username'] ?? 'My Account', ENT_QUOTES, 'UTF-8');
}

/* Expose login flag if other scripts rely on it */
echo '<script>window.IS_LOGGED_IN = '.(isset($_SESSION['individual_id']) ? 'true' : 'false').';</script>';
?>
<header>
  <div class="logo">
    <a href="/HTCCC-SYSTEM/main-page.php">
      <img src="/HTCCC-SYSTEM/image/main-logo.png" alt="HTCC Logo">
    </a>
  </div>

  <nav class="nav-container">
    <ul class="nav-links">
      <li class="dropdown">
        <a href="#service">SERVICES ▾</a>
        <ul class="dropdown-menu">
          <!-- EXISTING STATIC SERVICES (DO NOT REMOVE) -->
          <li><a href="/HTCCC-SYSTEM/service-preach.php">Preach of God</a></li>
          <li><a href="/HTCCC-SYSTEM/service-baptism.php">Water Baptism</a></li>
          <li><a href="/HTCCC-SYSTEM/service-dedication.php">Dedication</a></li>
          <li><a href="/HTCCC-SYSTEM/service-wedding.php">Wedding</a></li>
          <li><a href="/HTCCC-SYSTEM/service-house.php">House Blessing</a></li>
          <li><a href="/HTCCC-SYSTEM/service-funeral.php">Funeral Service</a></li>
          <li><a href="/HTCCC-SYSTEM/service-prayer.php">Request Prayer</a></li>

          <!-- Dynamic services from DB (NO GAP / NO SEPARATOR) -->
          <?php if (!empty($dbServices)): ?>
            <?php foreach ($dbServices as $svc): ?>
              <?php
                $sid  = (int)($svc['service_id'] ?? 0);
                $name = htmlspecialchars($svc['service_name'] ?? '', ENT_QUOTES, 'UTF-8');

                // ✅ Direct to new_service.php using ?id= (matches your code)
                $href = "/HTCCC-SYSTEM/new_service.php?id=" . $sid;
              ?>
              <?php if ($name !== ''): ?>
                <li><a href="<?php echo $href; ?>"><?php echo $name; ?></a></li>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </li>

      <li><a href="/HTCCC-SYSTEM/appoint-page.php">BOOK A SERVICE</a></li>
      <li><a href="/HTCCC-SYSTEM/event-page.php">EVENTS</a></li>
      <li><a href="/HTCCC-SYSTEM/main-page-gallery.php">GALLERY</a></li>

      <li class="dropdown">
        <a href="#ministries">MINISTRIES ▾</a>
        <ul class="dropdown-menu">
          <li><a href="/HTCCC-SYSTEM/ministries-women-page.php">Handmaid's of the Lord</a></li>
          <li><a href="/HTCCC-SYSTEM/ministries-men-page.php">Men’s Ministry</a></li>
          <li><a href="/HTCCC-SYSTEM/ministries-music-page.php">Music's Ministry</a></li>
          <li><a href="/HTCCC-SYSTEM/ministries-usher-page.php">Usher &amp; Usherette</a></li>
          <li><a href="/HTCCC-SYSTEM/ministries-junior-page.php">Junior Christ Ambassador</a></li>

          <!-- ✅ Dynamic ministries from DB (NO GAP / NO SEPARATOR) -->
          <?php if (!empty($dbMinistries)): ?>
            <?php foreach ($dbMinistries as $min): ?>
              <?php
                $labelRaw = (string)($min['ministry_name'] ?? '');
                $label    = htmlspecialchars($labelRaw, ENT_QUOTES, 'UTF-8');

                // ✅ Must match your new_ministry.php routing: ?ministry=...
                $href = "/HTCCC-SYSTEM/new_ministry.php?ministry=" . urlencode($labelRaw);
              ?>
              <?php if (trim($labelRaw) !== ''): ?>
                <li><a href="<?php echo $href; ?>"><?php echo $label; ?></a></li>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </li>

      <!-- Always go to main-page.php#join-us -->
      <li><a href="/HTCCC-SYSTEM/main-page.php#join-us">JOIN US</a></li>

      <li>
        <a href="/HTCCC-SYSTEM/multimedia.php">
          <?php if ($showLiveIcon): ?><span class="live-icon">🔴</span><?php endif; ?>
          LIVE MASS
        </a>
      </li>

      <!-- Login / Profile dropdown -->
      <?php if (!isset($_SESSION['individual_id'])): ?>
        <li class="login-btn">
          <a href="/HTCCC-SYSTEM/all_log-in.php">
            <img src="/HTCCC-SYSTEM/image/btn-login.png" alt="Login Button">
          </a>
        </li>
      <?php else: ?>
        <li class="dropdown profile-dropdown" style="position:relative;">
          <a href="javascript:void(0)" style="display:flex;align-items:center;gap:8px;">
            <img src="/HTCCC-SYSTEM/image/avatar.png" alt="Profile"
                style="width:30px;height:30px;border-radius:50%;object-fit:cover;">
            <span style="color:#fff;font-size:12px;">
              <?php echo htmlspecialchars($_SESSION['individual_username'] ?? 'My Account'); ?> ▾
            </span>
          </a>
          <ul class="dropdown-menu" style="right:0; left:auto; min-width:220px;">
            <!-- Profile header -->
            <li class="profile-card">
              <div class="pc-wrap">
                <img class="pc-avatar" src="/HTCCC-SYSTEM/image/avatar.png" alt="Avatar">
                <div class="pc-meta">
                  <div class="pc-name"><?php echo htmlspecialchars($__fullName, ENT_QUOTES, 'UTF-8'); ?></div>
                  <div class="pc-role">Individual</div>
                </div>
              </div>
            </li>
            <li><a class="pc-link" href="/HTCCC-SYSTEM/user-profile.php">Profile</a></li>

            <!-- "Notifications" opens the SIDE SLIDE AND marks all as READ -->
            <li>
              <a class="pc-link"
                 href="javascript:void(0)"
                 onclick="handleOpenNotificationsClick()">
                <span>Notifications</span>
                <?php if ($individualUnreadCount > 0): ?>
                  <span class="np-pill-count"><?php echo $individualUnreadCount; ?></span>
                <?php endif; ?>
              </a>
            </li>

            <li><a class="pc-link" href="/HTCCC-SYSTEM/logout.php">Logout</a></li>
          </ul>
        </li>
      <?php endif; ?>
    </ul>

    <div class="hamburger" onclick="toggleMenu()">&#9776;</div>
  </nav>
</header>

<?php if (isset($_SESSION['individual_id'])): ?>
  <!-- Notification side slide + overlay (INDIVIDUAL only) -->
  <div id="notification-overlay" class="notification-overlay" onclick="closeNotificationPanel()"></div>

  <aside id="notification-panel" class="notification-panel" aria-hidden="true">
    <div class="np-header">
      <div class="np-header-left">
        <h2>Notifications</h2>
      </div>
      <div class="np-header-right">
        <?php if ($individualUnreadCount > 0): ?>
          <button type="button"
                  class="np-mark-read"
                  onclick="markAllNotificationsRead(true)">
            Mark all as read
          </button>
        <?php endif; ?>
        <button type="button" class="np-close" onclick="closeNotificationPanel()">✕</button>
      </div>
    </div>

    <div class="np-body">
      <?php if (empty($individualNotifications)): ?>
        <p class="np-empty">You have no notifications yet.</p>
      <?php else: ?>
        <ul class="np-list">
          <?php foreach ($individualNotifications as $note): ?>
            <?php
              $isUnread  = ($note['status'] === 'unread');
              $createdAt = $note['created_at']
                            ? date('M d, Y • h:i A', strtotime($note['created_at']))
                            : '';
              $title     = htmlspecialchars($note['title'] ?? 'Notification', ENT_QUOTES, 'UTF-8');

              // ===== PARSE RAW BODY INTO FIELDS =====
              $rawBody = $note['body'] ?? '';

              $submitter        = null;
              $serviceType      = null;
              $serviceDateTime  = null; // seminar / appointment time

              if ($rawBody !== '') {
                  $lines = preg_split('/\r\n|\r|\n/', $rawBody);

                  foreach ($lines as $line) {
                      $trimLine = trim($line);

                      // Submitter: Jhobert ...
                      if (stripos($trimLine, 'Submitter:') === 0) {
                          $submitter = trim(substr($trimLine, strlen('Submitter:')));
                      }

                      // Type: Baptism Application   OR   Service: Baptism Application
                      if (stripos($trimLine, 'Type:') === 0) {
                          $serviceType = trim(substr($trimLine, strlen('Type:')));
                      } elseif (stripos($trimLine, 'Service:') === 0) {
                          $serviceType = trim(substr($trimLine, strlen('Service:')));
                      }

                      // Summary line may contain Appt and/or Service Date
                      if (stripos($trimLine, 'Summary:') === 0) {
                          if (preg_match('/Appt:\s*([^|]+)/i', $trimLine, $mAppt)) {
                              $serviceDateTime = trim($mAppt[1]);
                          } elseif (preg_match('/Service Date:\s*([^|]+)/i', $trimLine, $mSvc)) {
                              $serviceDateTime = trim($mSvc[1]);
                          }
                      }
                  }

                  // Fallback: search Service Date anywhere in body
                  if (!$serviceDateTime &&
                      preg_match('/Service Date:\s*([0-9]{4}-[0-9]{2}-[0-9]{2}(?:\s+[0-9:APMapm]+)?)/i', $rawBody, $mAny)) {
                      $serviceDateTime = trim($mAny[1]);
                  }
              }

              // Format date/time nicely for all messages
              $serviceDateTimeDisplay = null;
              if ($serviceDateTime) {
                  $ts = strtotime($serviceDateTime);
                  if ($ts !== false) {
                      $serviceDateTimeDisplay = date('F d, Y \a\t g:iA', $ts);
                  } else {
                      $serviceDateTimeDisplay = $serviceDateTime;
                  }
              }

              // ===== BUILD FRIENDLY MESSAGE BY SERVICE TYPE =====
              $friendlyBody        = '';
              $hasParsedSomething  = ($submitter || $serviceType || $serviceDateTimeDisplay);
              $namePart            = $submitter ?: 'there';
              $greeting            = "Hello {$namePart},\n";

              if ($hasParsedSomething && $serviceType) {
                  $serviceKey = strtolower($serviceType);

                  if (strpos($serviceKey, 'baptism') !== false) {
                      $friendlyBody  = $greeting;
                      $friendlyBody .= "Your baptismal application has been approved.";
                      if ($serviceDateTimeDisplay) {
                          $friendlyBody .= " Please attend the Seminar on {$serviceDateTimeDisplay}.";
                      }

                  } elseif (strpos($serviceKey, 'wedding') !== false) {
                      $friendlyBody  = $greeting;
                      if ($serviceDateTimeDisplay) {
                          $friendlyBody .= "Your wedding appointment has been set on {$serviceDateTimeDisplay}, please arrive on time.";
                      } else {
                          $friendlyBody .= "Your wedding appointment has been set, please arrive on time.";
                      }

                  } else {
                      $typeLabel    = $serviceType ?: 'Service';
                      $friendlyBody = $greeting;
                      if ($serviceDateTimeDisplay) {
                          $friendlyBody .= "Your Service Request has been approved ({$typeLabel}). Your schedule is on {$serviceDateTimeDisplay}. Please keep your line open for furthermore communication and details.";
                      } else {
                          $friendlyBody .= "Your Service Request has been approved ({$typeLabel}). Please keep your line open for furthermore communication and details.";
                      }
                  }
              } else {
                  $friendlyBody = $rawBody;
              }

              $body = htmlspecialchars($friendlyBody ?? '', ENT_QUOTES, 'UTF-8');
            ?>
            <li class="np-item <?php echo $isUnread ? 'np-item-unread' : ''; ?>">
              <div class="np-item-main">
                <div class="np-item-icon">
                  <span class="np-icon-person">👤</span>
                </div>
                <div class="np-item-content">
                  <div class="np-item-header">
                    <span class="np-title"><?php echo $title; ?></span>
                    <?php if ($createdAt): ?>
                      <span class="np-date"><?php echo $createdAt; ?></span>
                    <?php endif; ?>
                  </div>

                  <?php if ($body !== ''): ?>
                    <div class="np-chip">
                      <span class="np-chip-icon">✈</span>
                      <span class="np-chip-text"><?php echo nl2br($body); ?></span>
                    </div>
                  <?php endif; ?>

                  <?php if ($isUnread): ?>
                    <span class="np-badge">New</span>
                  <?php endif; ?>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <div class="np-footer">
      <?php if (!empty($individualNotifications)): ?>
        <button type="button"
                class="np-clear-btn"
                onclick="clearAllNotifications()">
          <span class="np-clear-icon">🗑</span>
          Clear all notifications
        </button>
      <?php else: ?>
        <span class="np-footer-text">Notifications for: Individual</span>
      <?php endif; ?>
    </div>
  </aside>
<?php endif; ?>

<!-- Helper JS for hamburger, dropdowns, and notification side slide -->
<script>
  function toggleMenu() {
    const nav = document.querySelector('.nav-container');
    nav.classList.toggle('open');
  }

  (function () {
    const nav = document.querySelector('.nav-container');

    const _origToggle = window.toggleMenu;
    window.toggleMenu = function () {
      _origToggle ? _origToggle() : nav.classList.toggle('open');
      document.body.classList.toggle('nav-open', nav.classList.contains('open'));
    };

    document.addEventListener('click', (e) => {
      if (!nav.classList.contains('open')) return;
      const insidePanel = e.target.closest('.nav-container .nav-links');
      const isBurger = e.target.closest('.hamburger');
      if (!insidePanel && !isBurger) {
        nav.classList.remove('open');
        document.body.classList.remove('nav-open');
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        nav.classList.remove('open');
        document.body.classList.remove('nav-open');
      }
    });
  })();

  (function () {
    const isSmall = () => window.matchMedia('(max-width:1180px)').matches;
    const nav = document.querySelector('.nav-container');
    if (!nav) return;

    let openLi = null;

    document.querySelectorAll('.nav-links > li.dropdown > a').forEach(a => {
      a.addEventListener('click', (e) => {
        if (!isSmall()) return;
        e.preventDefault();

        const li = a.parentElement;
        if (openLi && openLi !== li) openLi.classList.remove('open');
        li.classList.toggle('open');
        openLi = li.classList.contains('open') ? li : null;
      });
    });

    document.addEventListener('click', (e) => {
      if (!isSmall()) return;
      if (!nav.contains(e.target) && openLi) {
        openLi.classList.remove('open');
        openLi = null;
      }
    });

    document.querySelectorAll('.dropdown .dropdown-menu a').forEach(link => {
      link.addEventListener('click', () => {
        if (!isSmall()) return;
        if (openLi) { openLi.classList.remove('open'); openLi = null; }
        document.body.classList.remove('nav-open');
        nav.classList.remove('open');
      });
    });

    document.addEventListener('keydown', (e) => {
      if (!isSmall()) return;
      if (e.key === 'Escape' && openLi) {
        openLi.classList.remove('open');
        openLi = null;
      }
    });
  })();

  function openNotificationPanel() {
    const panel = document.getElementById('notification-panel');
    const overlay = document.getElementById('notification-overlay');
    if (!panel || !overlay) return;

    panel.classList.add('open');
    overlay.classList.add('open');
    document.body.classList.add('np-open');
  }

  function closeNotificationPanel() {
    const panel = document.getElementById('notification-panel');
    const overlay = document.getElementById('notification-overlay');
    if (!panel || !overlay) return;

    panel.classList.remove('open');
    overlay.classList.remove('open');
    document.body.classList.remove('np-open');
  }

  function handleOpenNotificationsClick() {
    openNotificationPanel();
    markAllNotificationsRead(false);
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeNotificationPanel();
    }
  });

  async function markAllNotificationsRead(shouldReload = true) {
    try {
      const res = await fetch('/HTCCC-SYSTEM/notification_actions_individual.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=mark_all_read'
      });
      if (!res.ok) return;

      if (shouldReload) {
        location.reload();
      } else {
        document.querySelectorAll('.np-item-unread').forEach(el => {
          el.classList.remove('np-item-unread');
        });
        document.querySelectorAll('.np-badge').forEach(el => el.remove());

        const pill = document.querySelector('.np-pill-count');
        if (pill) pill.remove();

        const markBtn = document.querySelector('.np-mark-read');
        if (markBtn) markBtn.remove();
      }
    } catch (err) {
      console.error('Failed to mark all as read', err);
    }
  }

  async function clearAllNotifications() {
    if (!confirm('Clear all notifications?')) return;
    try {
      const res = await fetch('/HTCCC-SYSTEM/notification_actions_individual.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=clear_all'
      });
      if (!res.ok) {
        console.error('Server returned error when clearing notifications');
        return;
      }

      const list = document.querySelector('.np-list');
      if (list) list.innerHTML = '';

      const body = document.querySelector('.np-body');
      if (body) {
        const oldEmpty = body.querySelector('.np-empty');
        if (oldEmpty) oldEmpty.remove();

        const p = document.createElement('p');
        p.className = 'np-empty';
        p.textContent = 'You have no notifications yet.';
        body.appendChild(p);
      }

      document.querySelectorAll('.np-item-unread').forEach(el => el.classList.remove('np-item-unread'));
      document.querySelectorAll('.np-badge').forEach(el => el.remove());

      const pill = document.querySelector('.np-pill-count');
      if (pill) pill.remove();

      const markBtn = document.querySelector('.np-mark-read');
      if (markBtn) markBtn.remove();

    } catch (err) {
      console.error('Failed to clear notifications', err);
    }
  }
</script>

<!-- Styles for notification side slide (move to CSS file if preferred) -->
<style>
  .notification-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.35);
    opacity: 0;
    visibility: hidden;
    transition: opacity .25s ease, visibility .25s ease;
    z-index: 998;
  }
  .notification-overlay.open {
    opacity: 1;
    visibility: visible;
  }

  .notification-panel {
    position: fixed;
    top: 0;
    right: -400px;
    width: 380px;
    max-width: 100vw;
    height: 100vh;
    background: #ffffff;
    box-shadow: -2px 0 10px rgba(0,0,0,0.25);
    z-index: 999;
    display: flex;
    flex-direction: column;
    transition: right .25s ease;
  }
  .notification-panel.open {
    right: 0;
  }

  .np-header {
    padding: 12px 18px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
  }
  .np-header-left h2 {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
    color: #111827;
  }
  .np-header-right {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .np-mark-read {
    border: none;
    background: transparent;
    color: #2563eb;
    font-size: 13px;
    cursor: pointer;
    padding: 4px 6px;
  }
  .np-mark-read:hover {
    text-decoration: underline;
  }
  .np-close {
    border: none;
    background: transparent;
    font-size: 18px;
    cursor: pointer;
    color: #4b5563;
  }

  .np-body {
    padding: 10px 18px 18px;
    overflow-y: auto;
    flex: 1;
    background: #f3f4f6;
  }
  .np-empty {
    font-size: 14px;
    color: #6b7280;
    margin-top: 12px;
  }

  .np-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .np-item {
    background: #ffffff;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    padding: 10px 12px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.04);
  }
  .np-item-unread {
    border-color: #2563eb;
    box-shadow: 0 0 0 1px rgba(37,99,235,0.2);
  }

  .np-item-main {
    display: flex;
    gap: 10px;
  }

  .np-item-icon {
    flex: 0 0 32px;
    height: 32px;
    border-radius: 999px;
    background: #eff6ff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
  }

  .np-item-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .np-item-header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 6px;
    margin-bottom: 2px;
  }
  .np-title {
    font-weight: 600;
    font-size: 14px;
    color: #111827;
  }
  .np-date {
    font-size: 11px;
    color: #6b7280;
    white-space: nowrap;
  }

  .np-chip {
    display: flex;
    align-items: flex-start;
    gap: 6px;
    border-radius: 12px;
    background: #f9fafb;
    padding: 8px 10px;
    border: 1px solid #e5e7eb;
    font-size: 12px;
    color: #374151;
    max-width: 100%;
  }
  .np-chip-icon {
    font-size: 13px;
    margin-top: 2px;
  }
  .np-chip-text {
    white-space: normal;
    word-wrap: break-word;
  }

  .np-badge {
    margin-top: 4px;
    align-self: flex-start;
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 999px;
    background: #2563eb;
    color: #fff;
  }

  .np-footer {
    padding: 12px 18px 14px;
    border-top: 1px solid #e5e7eb;
    background: #ffffff;
    display: flex;
    justify-content: center;
    align-items: center;
  }
  .np-footer-text {
    font-size: 12px;
    color: #4b5563;
  }
  .np-clear-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border-radius: 999px;
    border: none;
    padding: 8px 18px;
    background: #111827;
    color: #f9fafb;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
  }
  .np-clear-icon {
    font-size: 14px;
  }
  .np-clear-btn:hover {
    filter: brightness(1.08);
  }

  body.np-open {
    overflow: hidden;
  }

  .np-pill-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-left: 6px;
    min-width: 18px;
    height: 18px;
    padding: 0 6px;
    border-radius: 999px;
    background: #ef4444;
    color: #fff;
    font-size: 11px;
    font-weight: 600;
  }

  @media (max-width: 600px) {
    .notification-panel {
      width: 100%;
      max-width: 100%;
    }
    .np-header {
      padding-inline: 14px;
    }
    .np-body {
      padding-inline: 12px;
    }
    .np-item {
      padding-inline: 10px;
    }
    .np-item-header {
      flex-direction: column;
      align-items: flex-start;
    }
    .np-date {
      white-space: normal;
    }
  }
</style>
