
<html lang="en">
 <head>
  <meta charset="utf-8"/>
  <meta content="width=device-width, initial-scale=1" name="viewport"/>
  <title>
   Admin - Baptized List
  </title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="admin-dashboard.css">
  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
 </head>
<style>
            nav {
            background-color: #F7F9FC;
            border-bottom: 1px solid #E5E7EB;
            padding: 1rem 4rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            }
            nav .left {
            display: flex;
            align-items: center;
            gap: 4rem;
            }
            nav .left img.profile {
            width: 40px;
            height: 40px;
            border-radius: 9999px;
            }
            nav ul {
            display: flex;
            gap: 3rem; /* increased gap for large screen */
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            list-style: none;
            margin: 0;
            padding: 0;
            }
            nav ul li {
            position: relative;
            cursor: pointer;
            color: #001B3A;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            }
            nav ul li.dashboard-btn {
            font-weight: 700;
            }
            nav ul li.request {
            position: relative;
            }
            nav ul li.request button {
            all: unset;
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            cursor: pointer;
            color: #001B3A;
            }
            nav ul li.request button:focus {
            outline: 2px solid #001B3A;
            outline-offset: 2px;
            }
            nav ul li.request ul.dropdown {
            position: absolute;
            top: 2.5rem;
            left: 0;
            background: white;
            border: 1px solid #E5E7EB;
            border-radius: 0.375rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease;
            width: 12rem;
            z-index: 10;
            padding: 0.25rem 0;
            }
            nav ul li.request:hover ul.dropdown,
            nav ul li.request:focus-within ul.dropdown {
            opacity: 1;
            visibility: visible;
            }
            nav ul li.request ul.dropdown li a {
            display: block;
            padding: 0.75rem 1.25rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: #001B3A;
            text-decoration: none;
            }
            nav ul li.request ul.dropdown li a:hover {
            background-color: #F0F4FA;
            }
            nav .right img.icon {
            width: 40px;
            height: 40px;
            cursor: pointer;
            display: block;
            }

            a{
        text-decoration: none;
        color: #001B3A;
    }
    .dropdown {
    position: relative;
}

.dropdown > a {
    display: flex;
    align-items: center;
    gap: 5px;
    cursor: pointer;
    color: white; 
    text-decoration: none;
    color: 001B3A;
}


.dropdown-menu {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    min-width: 180px;
    background-color: white;
    box-shadow: 0px 8px 16px rgba(0, 0, 0, 0.2);
    border-radius: 8px;
    z-index: 1000;
}


.dropdown-menu li a {
    display: block;
    padding: 10px 15px;
    color: black;
    text-decoration: none;
}

.dropdown-menu li a:hover {
    background-color: #f0f0f0;
}


.dropdown:hover .dropdown-menu {
    display: block;
}
.active {
    background-color: lightblue;
}

.active:hover {
    background-color: lightblue;
}
.container {
  max-width: 1400px;
  margin: auto;
  background: transparent;
  padding: 20px;
  border-radius: 10px;
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
}

h2 {
  font-size: 20px;
  font-weight: bold;
}

.top-bar {
  display: flex;
  justify-content: space-between;
  margin-bottom: 20px;
}

.search-box {
  padding: 8px 12px;
  border: 1px solid #ccc;
  border-radius: 20px;
  width: 250px;
}

.sort-button {
  background: #1a1a64;
  color: white;
  border: none;
  border-radius: 10px;
  padding: 8px 16px;
  cursor: pointer;
  font-weight: bold;
}

table {
  width: 100%;
  border-collapse: collapse;
  border: 2px solid #a881af;
}

thead {
  background-color: #1a1a64;
  color: white;
}

thead th {
  padding: 12px;
  text-align: left;
  font-size: 14px;
}

tbody td {
  padding: 12px;
  border-top: 1px solid #ccc;
  font-size: 14px;
}

.approve {
  background-color: #00c853;
  color: white;
  border: none;
  border-radius: 20px;
  padding: 6px 12px;
  margin-right: 5px;
  cursor: pointer;
}

.decline {
  background-color: #c62828;
  color: white;
  border: none;
  border-radius: 20px;
  padding: 6px 12px;
  cursor: pointer;
}

.approve:hover {
  background-color: #00b94f;
}

.decline:hover {
  background-color: #b71c1c;
}
.header{
    text-align: center;
    font-weight: bold;
}
</style>


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
                <li><a href="account-admin.php">Admin Accounts</a></li>
                <li><a href="account-baptized.php" style="color: skyblue;">Baptised Individual Accounts</a></li>
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
  <h2 class="header">BAPTIZED ACCOUNTS LIST</h2>

  <div class="top-bar">
  <input type="text" placeholder="🔍 Search" class="search-box">
  <button class="sort-button" style="margin-left: 850px;">Add +</button>
  <button class="sort-button"  style="background-color:skyblue">Sort by:</button>
</div>

  <?php
include 'db-connection.php';
?>

<?php
include 'db-connection.php';
?>

<table>
  <thead>
    <tr>
      <th>User ID</th>
      <th>Lastname</th>
      <th>Firstname</th>
      <th>Middlename</th>
      <th>Birthday</th>
      <th>Gender</th>
      <th>Username</th>
      <th>Password</th>
      <th>Phone Number</th>
      <th>Email</th>
      <th>Street</th>
      <th>City</th>
      <th>Zip Code</th>
      <th>Action</th>
    </tr>
  </thead>
  <tbody>
    <?php
    $query = "SELECT * FROM individual_table WHERE individual_baptised = 'Yes'";
    $result = mysqli_query($db_connection, $query);

    if ($result && mysqli_num_rows($result) > 0) {
      while ($row = mysqli_fetch_assoc($result)) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['individual_id']) . '</td>';
        echo '<td>' . htmlspecialchars($row['individual_lastname']) . '</td>';
        echo '<td>' . htmlspecialchars($row['individual_firstname']) . '</td>';
        echo '<td>' . htmlspecialchars($row['individual_middlename']) . '</td>';
        echo '<td>' . htmlspecialchars($row['individual_birthday']) . '</td>';
        echo '<td>' . htmlspecialchars($row['individual_gender']) . '</td>';
        echo '<td>' . htmlspecialchars($row['individual_username']) . '</td>';
        echo '<td>' . htmlspecialchars($row['individual_password']) . '</td>';
        echo '<td>' . htmlspecialchars($row['individual_phone_number']) . '</td>';
        echo '<td>' . htmlspecialchars($row['individual_email_address']) . '</td>';
        echo '<td>' . htmlspecialchars($row['individual_street']) . '</td>';
        echo '<td>' . htmlspecialchars($row['individual_city']) . '</td>';
        echo '<td>' . htmlspecialchars($row['individual_zip_code']) . '</td>';
        echo '<td>
                <button class="approve">Edit</button>
                <button class="decline">Remove</button>
              </td>';
        echo '</tr>';
      }
    } else {
      echo '<tr><td colspan="16">No data found.</td></tr>';
    }
    ?>
  </tbody>
</table>


</div>

  </body>