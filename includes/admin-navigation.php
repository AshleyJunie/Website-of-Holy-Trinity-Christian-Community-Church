<!-- ======================== admin-navigation.php ======================== -->
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
    <a class="navlink active" href="secretary_dashboard.php"><i class="fas fa-th-large"></i>Dashboard</a>

    <div class="section-title">Online Requests</div>
    <a class="navlink" href="admin-schedule-request.php">
      <i class="far fa-calendar-plus"></i>Appointment Requests
    </a>
    <a class="navlink" href="admin-prayer-request.php">
      <i class="far fa-hand-paper"></i><span>Prayer Requests</span>
    </a>

    <div class="section-title">Schedule</div>
      <a class="navlink" href="appointment-schedule.php">
      <i class="far fa-calendar-alt"></i>Appointment Schedule
    </a>
    <a class="navlink" href="admin-schedule-table.php">
      <i class="fas fa-calendar-alt"></i>Service Schedule
    </a>

    <div class="section-title">Applications</div>
    <a class="navlink" href="application_ministry.php">
      <i class="fas fa-users"></i>Ministry Applications
    </a>

    <div class="section-title">Streaming</div>
    <a class="navlink" href="admin-multimedia.php">
      <i class="fas fa-video"></i>Streaming
    </a>

    <div class="section-title">User Management</div>
    <a class="navlink" href="admin-ministry-women.php">
      <i class="fas fa-user"></i>User
    </a>

    <div class="section-title">Ministry Management</div>
    <a class="navlink" href="admin-ministry-women.php">
      <i class="fas fa-female"></i>Handmaid's of the Lord
    </a>
    <a class="navlink" href="admin-ministry-men.php">
      <i class="fas fa-male"></i>Men's Ministry
    </a>
    <a class="navlink" href="admin-ministry-music.php">
      <i class="fas fa-music"></i>Music's Ministry
    </a>
    <a class="navlink" href="admin-ministry-usher.php">
      <i class="fas fa-hands-helping"></i>Usher &amp; Usherette
    </a>
    <a class="navlink" href="admin-ministry-junior.php">
      <i class="fas fa-child"></i>Junior Christ Ambassador
    </a>

    <div class="section-title">Reports</div>
    <a class="navlink" href="admin-reports.php">
      <i class="fas fa-file-alt"></i>Reports
    </a>

    <div class="section-title">Content</div>
    <a class="navlink" href="content-management_home-page.php">
      <i class="fas fa-edit"></i>Content Management
    </a>

    <div class="section-title">Certificates</div>
    <a class="navlink" href="certificate-table.php">
      <i class="fa fa-certificate"></i>Generate Certificate
    </a>

    <div class="section-title">Account</div>
    <a class="navlink" href="admin-account-settings.php">
      <i class="fas fa-user-cog"></i>Account Settings
    </a>

    <div class="section-title">More</div>
    <a class="navlink logout" href="all_log-in.php">
      <img alt="Logout" class="icon" src="image/logo-logout.png" width="18" height="18" style="vertical-align:middle;margin-right:8px;">
      Log Out
    </a>
  </nav>
</aside>

<style>
/* ================= Sidebar & Navigation CSS ================= */
.sidebar {
  width: 250px;
  background-color: #02084b;
  color: #fff;
  position: fixed;
  top: 0;
  left: 0;
  height: 100vh;
  overflow-y: auto;
  padding-top: 20px;
  display: flex;
  flex-direction: column;
  box-shadow: 4px 0 10px rgba(0, 0, 0, 0.2);
}

.sidebar::-webkit-scrollbar {
  width: 6px;
}
.sidebar::-webkit-scrollbar-thumb {
  background-color: #444;
  border-radius: 10px;
}

.brand {
  text-align: center;
  margin-bottom: 20px;
}
.brand img {
  width: 70px;
  height: auto;
}
.brand span {
  display: block;
  font-weight: bold;
  margin-top: 10px;
  font-size: 16px;
}

.user-card {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 20px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.2);
  margin-bottom: 20px;
}
.user-card img {
  width: 45px;
  height: 45px;
  border-radius: 50%;
  border: 2px solid #fff;
}
.user-card .user-title {
  font-weight: bold;
  font-size: 14px;
}
.user-card .user-sub {
  font-size: 12px;
  color: #ccc;
}

.nav {
  display: flex;
  flex-direction: column;
  padding: 0 15px 30px;
}

.section-title {
  font-size: 13px;
  text-transform: uppercase;
  color: #a7b1c2;
  margin: 15px 0 8px 5px;
  letter-spacing: 1px;
}

.navlink {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 15px;
  color: white;
  text-decoration: none;
  border-radius: 6px;
  transition: background-color 0.3s ease, color 0.3s ease;
  font-size: 14px;
}

.navlink i {
  width: 20px;
  text-align: center;
}

.navlink:hover {
  background-color: #1a237e;
  color: #b3e5fc;
}

.navlink.active {
  background-color: #1e3a8a;
  font-weight: bold;
}

.navlink.logout {
  color: #ff8080;
  font-weight: bold;
  margin-top: auto;
  border-top: 1px solid rgba(255, 255, 255, 0.2);
}

.navlink.logout:hover {
  background-color: #d32f2f;
  color: white;
}

@media (max-width: 900px) {
  .sidebar {
    width: 220px;
  }
  .navlink {
    font-size: 13px;
  }
  .brand img {
    width: 60px;
  }
}
</style>
