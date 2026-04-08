<?php
/* includes/footer.php */
require_once $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/db-connection.php';

/* Location embed */
$mapSrc = null;
if (!empty($db_connection)) {
  $stmt = mysqli_prepare($db_connection,
    "SELECT img_caption FROM content_management_table WHERE content_type='Location' ORDER BY contentID DESC LIMIT 1");
  mysqli_stmt_execute($stmt);
  mysqli_stmt_bind_result($stmt, $mapSrc);
  mysqli_stmt_fetch($stmt);
  mysqli_stmt_close($stmt);
}
$defaultMap = "httpas://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3541.1099581541675!2d120.90405737469509!3d14.48302168598947!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3397cd30bcaf4357%3A0x5e288e0b78b91077!2sHoly%20Trinity%20Christian%20Community%20Church!5e1!3m2!1sen!2sph!4v1745841851717!5m2!1sen!2sph";

/* Contact */
$address = $phone = $churchName = '';
if (!empty($db_connection)) {
  $contact = [];
  $rs = mysqli_query($db_connection,
    "SELECT img_caption FROM content_management_table WHERE content_type='Contact' ORDER BY contentID ASC");
  while ($r = mysqli_fetch_row($rs)) { $contact[] = $r[0]; }
  mysqli_free_result($rs);
  $address    = $contact[0] ?? '';
  $phone      = $contact[1] ?? '';
  $churchName = $contact[2] ?? '';
}

/* Social */
$facebook = $youtube = '#';
if (!empty($db_connection)) {
  $social = [];
  $rs2 = mysqli_query($db_connection,
    "SELECT img_caption FROM content_management_table WHERE content_type='Social' ORDER BY contentID ASC");
  while ($r = mysqli_fetch_row($rs2)) { $social[] = $r[0]; }
  mysqli_free_result($rs2);
  $facebook = $social[0] ?? '#';
  $youtube  = $social[1] ?? '#';
}
?>
<footer style="background-color: #1B1B4B; color: white; padding: 30px 40px;">
  <div style="display: flex; flex-wrap: wrap; justify-content: space-around; align-items: flex-start; text-align: center; gap: 10px;">

    <div style="flex: 1; min-width: 200px; padding: 0.5rem;">
      <h1 style="font-size: 1rem; margin-bottom: 0.5rem;">LOCATION</h1>
      <iframe
        src="<?php echo htmlspecialchars($mapSrc ?: $defaultMap); ?>"
        width="100%" height="200"
        style="border:0; border-radius: 8px;"
        allowfullscreen="" loading="lazy">
      </iframe>
    </div>

    <div style="flex: 1; min-width: 200px; padding: 0.5rem;" class="nav-footer">
      <h1 style="font-size: 14px; margin-bottom: 0.5rem;">NAVIGATION</h1>
      <ul style="list-style: none; padding: 0; margin: 0; font-size: 12px; line-height: 1.5;">
        <li><a href="#main-page">HOME</a></li>
        <li class="ministries-dropdown">
          <a href="#contact">SERVICE</a>
          <ul class="ministries-menu" style="margin-left: 10px;">
            <li><a href="#service-preach">Preach of God</a></li>
            <li><a href="#service-baptism">Baptism</a></li>
            <li><a href="#service-dedication">Dedication</a></li>
            <li><a href="#service-wedding">Wedding</a></li>
            <li><a href="#service-house">House Blessing</a></li>
            <li><a href="#service-funeral">Funeral Service</a></li>
          </ul>
        </li>
        <li><a href="/HTCCC-SYSTEM/appoint-page.php">APPOINTMENT</a></li>
        <li><a href="#events">EVENTS</a></li>
        <li><a href="#gallery">GALLERY</a></li>
        <li class="ministries-dropdown">
          <a href="#contact">MINISTRIES</a>
          <ul class="ministries-menu" style="margin-left: 10px;">
            <li><a href="/HTCCC-SYSTEM/ministries-women-page.php">Handmaid's of the Lord</a></li>
            <li><a href="/HTCCC-SYSTEM/ministries-men-page.php">Men's Ministries</a></li>
            <li><a href="/HTCCC-SYSTEM/ministries-music-page.php">Music Ministries</a></li>
            <li><a href="/HTCCC-SYSTEM/ministries-usher-page.php">Usher & Usherette</a></li>
          </ul>
        </li>
        <li><a href="#join-us">JOIN US</a></li>
        <li><a href="#live-mass">LIVE MASS</a></li>
      </ul>
    </div>

    <div style="flex: 1; min-width: 200px; padding: 2rem;">
      <h1 style="font-size: 14px; margin-bottom: 0.5rem;">CONTACT US!</h1>
      <ul style="list-style: none; padding: 0; margin: 0; font-size: 12px; text-align: left;">
        <li style="margin-bottom: 0.5rem; display: flex; align-items: center;">
          <i class="fas fa-map-marker-alt" style="margin-right: 8px;"></i>
          <?php echo htmlspecialchars($address); ?>
        </li>
        <li style="margin-bottom: 0.5rem; display: flex; align-items: center;">
          <i class="fas fa-phone-alt" style="margin-right: 8px;"></i>
          <?php echo htmlspecialchars($phone); ?>
        </li>
        <li style="margin-bottom: 0.5rem; font-weight: bold; display: flex; align-items: center;">
          <i class="fas fa-church" style="margin-right: 8px;"></i>
          <?php echo htmlspecialchars($churchName); ?>
        </li>
      </ul>

      <!-- FB & YouTube directly under Contact Us -->
      <div class="footer-social" style="margin-top:12px;">
        <a href="<?php echo htmlspecialchars($facebook); ?>" target="_blank" aria-label="Facebook"><i class="fab fa-facebook"></i></a>
        <a href="<?php echo htmlspecialchars($youtube); ?>" target="_blank" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
      </div>
    </div>

  </div>

  <div style="text-align: center; margin-top: 1rem; font-size: 10px;">
    © <?php echo date('Y'); ?> Holy Trinity Christian Community Church. All Rights Reserved.
  </div>
</footer>
