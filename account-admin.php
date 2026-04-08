
<html lang="en">
 <head>
  <meta charset="utf-8"/>
  <meta content="width=device-width, initial-scale=1" name="viewport"/>
  <title>
   Admin - Admin List
  </title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="admin-dashboard.css">
  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
          <link rel="stylesheet" href="/HTCCC-SYSTEM/css/account-admin.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/account-admin.css'); ?>">

 </head>
<body>

<nav style="padding: 25px;">
   <div class="left">
    <img alt="User profile icon with navy background and white user silhouette" class="profile" height="40" src="image/logo-profile.png" width="40"/>
    <ul>
     <li class="dashboard-btn" style="margin-left: 50px; font-weight: bold;"><a href="admin-dashboard.php">DASHBOARD</a></li>
     <li class="dropdown">
     <li class="dropdown">
            <a href="#service" style="color: skyblue;">ACCOUNTS ▾</a>
            <ul class="dropdown-menu">
                <li><a href="account-admin.php" style="color: skyblue;">Secretary Accounts</a></li>
                <li><a href="account-baptized.php">Baptised Individual Accounts</a></li>
                <li><a href="account-unbaptized.php">Non-Baptise Individual Accounts</a></li>
            </ul>
        </li>
            <a href="#service">REQUEST ▾</a>
            <ul class="dropdown-menu">
                <li><a href="admin-schedule-request.php">Appointment Request</a></li>
                <li><a href="admin-prayer-request.php">Prayer Request</a></li>
            </ul>
        </li>
        <li class="dropdown">
            <a href="#service">SCHEDULE ▾</a>
            <ul class="dropdown-menu">
                <li><a href="admin-schedule-table.php">Service Schedule</a></li>
            </ul>
        </li>
        <li class="dropdown">
            <a href="">APPLICATION ▾</a>
            <ul class="dropdown-menu">
                <li><a href="application_ministry.php">Ministries Application</a></li>
                <li><a href="">User Application</a></li>
            </ul>
        </li>
     <li><a href="admin-multimedia.php">STREAMING</a></li>
        <li class="dropdown">
            <a href="#service">MINISTRY LIST ▾</a>
            <ul class="dropdown-menu">
                <li><a href="admin-ministry-women.php">Handmaid's of the Lord</a></li>
                <li><a href="admin-ministry-men.php">Men's Ministry</a></li>
                <li><a href="admin-ministry-music.php">Music's Ministry</a></li>
                <li><a href="admin-ministry-usher.php">Usher & Usherette</a></li>
                <li><a href="admin-ministry-junior.php">Junior Christ Ambassador</a></li>
            </ul>
        </li>
     <li><a href="admin-audit.php">AUDIT LOGS</a></li>
     <li><a href="admin-reports.php">REPORTS</a></li>  
    </ul>
   </div>
   <div class="right">
    <a href="all_log-in.php" tabindex="0" aria-label="Navigate to your link">
     <img alt="Navy icon with white arrow and document shape" class="icon" height="40" src="image/logo-logout.png" width="40" style="display:block;"/>
    </a>
   </div>
  </nav>
  <div class="container">
  <h2 class="header">SECRETARY ACCOUNTS LIST</h2>

  <div class="top-bar">
  <input type="text" placeholder="🔍 Search" class="search-box">
  <a href="account-admin-register.php" class="sort-button">Add Secretary</a>
</div>


  
  <?php
include 'db-connection.php';
?>

<table>
  <thead>
    <tr>
      <th>Admin ID</th>
      <th>Lastname</th>
      <th>Fistname</th>
      <th>Middlename</th>
      <th>Contact Number</th>
      <th>Email Address</th>
      <th>Username</th>
      <th>Password</th>
      <th>Action</th>
    </tr>
  </thead>
  <tbody>
    <?php
    $query = "SELECT admin_id, admin_lastname, admin_firstname, admin_middlename, admin_contactnumber, admin_emailaddress,admin_username,admin_password   FROM admin_table";
    $result = mysqli_query($db_connection, $query);

    if ($result) {
      while ($row = mysqli_fetch_assoc($result)) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['admin_id']) . '</td>';
        echo '<td>' . htmlspecialchars($row['admin_lastname']) . '</td>';
        echo '<td>' . htmlspecialchars($row['admin_firstname']) . '</td>';
        echo '<td>' . htmlspecialchars($row['admin_middlename']) . '</td>';
        echo '<td>' . htmlspecialchars($row['admin_contactnumber']) . '</td>';
        echo '<td>' . htmlspecialchars($row['admin_emailaddress']) . '</td>';
        echo '<td>' . htmlspecialchars($row['admin_username']) . '</td>';
        echo '<td>' . htmlspecialchars($row['admin_password']) . '</td>';
        echo '<td>
                <button class="approve">Edit</button>
                <button class="decline">Remove</button>
              </td>';
        echo '</tr>';
      }
    } else {
      echo '<tr><td colspan="6">No data found.</td></tr>';
    }
    ?>
  </tbody>
</table>

</div>

  </body>