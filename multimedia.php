<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HTCCC - Live Mass</title>
    <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/HTCCC-SYSTEM/css/multimedia.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/multimedia.css'); ?>">
    <link rel="stylesheet" href="/HTCCC-SYSTEM/css/global-layout.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/global-layout.css'); ?>">
</head>
<body>

<header>
  <?php include $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/includes/navigation.php'; ?>
    </header>

<?php
// Database connection
$servername = "localhost";  // or your DB host
$username   = "root";       // your DB username
$password   = "";           // your DB password
$dbname     = "htccc-data-base";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Query to get the latest active live mass link
$sql = "SELECT fb_link FROM multimedia WHERE live_status='Active' ORDER BY livemassId DESC LIMIT 1";
$result = $conn->query($sql);

$liveMassLink = "";
if ($result->num_rows > 0) {
  $row = $result->fetch_assoc();
  $liveMassLink = $row['fb_link'];
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Sunday Live Mass ehe</title>
</head>
<body>

<section id="live-mass">
  <div class="container">
    <h2 class="live-mass-txt">
      <span class="dot"></span>
      SUNDAY LIVE MASS
    </h2>
    <div class="square-box" id="live-mass-box">
      <?php if (!empty($liveMassLink)) { ?>
        <!-- Facebook Live Embed -->
        <iframe 
          src="<?php echo $liveMassLink; ?>" 
          width="1000px" 
          height="500px" 
          style="border:none;overflow:hidden" 
          scrolling="no" 
          frameborder="0" 
          allowfullscreen="true" 
          allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share">
        </iframe>
      <?php } else { ?>
        <!-- Show message if no live mass -->
        <p>LIVE MASS IS UNAVAILABLE</p>
      <?php } ?>
    </div>
  </div>
</section>

</body>
</html>



    <footer style="background-color: #1B1B4B; color: white; padding: 30px 40px;">
  <?php include $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/includes/footer.php'; ?>
</footer>

</body>
</html>