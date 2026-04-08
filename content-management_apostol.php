<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta content="width=device-width, initial-scale=1" name="viewport" />
  <title>Content Management - Apostol Creed</title>
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@700&display=swap"
    rel="stylesheet"
  />
  <script src="https://cdn.tailwindcss.com"></script>
  <link
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css"
    rel="stylesheet"
  />
  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
  <style>
    body,
    html {
      margin: 0;
      padding: 0;
      height: 100%;
      font-family: "Inter", sans-serif;
      background: url("image/all-background.png")
        no-repeat center center fixed;
      background-size: cover;
      position: relative;
      color: #0b0b3b;
      overflow-x: hidden;
    }
    body::before {
      content: "";
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background-color: white;
      opacity: 0.85;
      z-index: -1;
    }
    .back-button {
      display: inline-flex;
      align-items: center;
      background-color: rgb(0, 157, 241);
      color: white;
      padding: 4px 10px;
      border-radius: 6px;
      text-decoration: none;
      font-weight: bold;
      transition: background-color 0.3s ease;
      margin-right: 1100px;
      font-size: 12px;
      gap: 6px;
      user-select: none;
    }
    .back-button i {
      margin: 0;
      font-size: 14px;
      line-height: 1;
    }
    .back-button:hover {
      background-color: #1a252f;
    }
    header {
      background-color: #0b0b3b;
      height: 56px;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0 40px;
      position: relative;
      color: white;
      box-sizing: border-box;
    }
    header a.logo {
      color: white;
      font-weight: 800;
      font-size: 20px;
      display: flex;
      align-items: center;
      gap: 12px;
      text-decoration: none;
      position: absolute;
      left: 50%;
      transform: translateX(-50%);
      white-space: nowrap;
    }
    header a.logo img {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      object-fit: cover;
    }
    header a.undo {
      color: white;
      font-size: 24px;
      text-decoration: none;
      position: absolute;
      right: 40px;
      top: 50%;
      transform: translateY(-50%);
    }
    nav {
      background-color: white;
      display: flex;
      justify-content: center;
      gap: 48px;
      padding: 16px 0;
      font-weight: 800;
      font-size: 16px;
      color: #0b0b3b;
      box-sizing: border-box;
      border-bottom: 1px solid #ddd;
    }
    nav a {
      text-decoration: none;
      color: #0b0b3b;
      white-space: nowrap;
    }
    nav a.active {
      text-decoration: underline;
    }
    .dropdown {
      position: relative;
      display: inline-block;
    }
    .dropdown .active {
      background-color: #2c3e50;
      color: white;
      padding: 10px 16px;
      text-decoration: none;
      font-weight: bold;
      border-radius: 5px;
      cursor: pointer;
      user-select: none;
    }
    .dropdown .dropdown-content {
      display: none;
      position: absolute;
      background-color: #f1f1f1;
      min-width: 200px;
      z-index: 1;
      border-radius: 5px;
      box-shadow: 0px 8px 16px rgba(0, 0, 0, 0.2);
      margin-top: 4px;
    }
    .dropdown .dropdown-content a {
      color: #2c3e50;
      padding: 10px 16px;
      text-decoration: none;
      display: block;
      font-weight: 700;
      white-space: nowrap;
      cursor: pointer;
    }
    .dropdown .dropdown-content a:hover {
      background-color: #ddd;
    }
    .dropdown:hover .dropdown-content {
      display: block;
    }
    main {
      padding: 32px 80px;
      max-width: 1600px;
      margin: 0 auto;
      box-sizing: border-box;
      font-weight: 700;
      font-size: 11px;
      color: #0b0b3b;
    }
    main h2 {
      font-weight: 800;
      font-size: 15px;
      margin-bottom: 8px;
    }
    section.content-box {
      border: 1px solid #0b0b3b;
      padding: 16px 24px;
      margin-top: 8px;
      font-weight: 700;
      font-size: 13px;
      line-height: 1.3;
      color: #0b0b3b;
      max-width: 1200px;
      margin-left: auto;
      margin-right: auto;
      white-space: normal;
    }
    section.content-box p {
      margin-top: 0.5rem;
      margin-bottom: 0.5rem;
    }
    section.content-box p.center {
      text-align: center;
      margin-top: 0.5rem;
      margin-bottom: 0.5rem;
    }
    button.edit-btn {
      background-color: #0b0b3b;
      color: white;
      font-weight: 700;
      font-size: 15px;
      padding: 14px 12px;
      border-radius: 6px;
      border: none;
      cursor: pointer;
      margin-top: 10px;
      margin-left: auto;
      display: block;
      max-width: 1200px;
      margin-right: 24px;
    }
  </style>
</head>
<body>
<header style="background-color: #0A0E3F; display: flex; align-items: center; justify-content: space-between; padding: 10px 20px; color: white;">
  <!-- Back Button -->
  <a href="secretary_dashboard.php" style="display: inline-block; z-index: 10;">
    <img src="image/btn-back.png" alt="Back" style="width: 30px; height: 30px; cursor: pointer; display: block;">
  </a>

  <!-- Centered logo and title together -->
  <div style="display: flex; align-items: center; gap: 10px;">
    <img src="image/httc_main-logo.jpg" alt="Logo" style="width: 60px; height: 60px; border-radius: 50%; margin-right: 10px;">
    <h1 style="margin: 0; font-size: 25px;">CONTENT MANAGEMENT</h1>
  </div>

  <!-- Spacer to balance layout (same size as back button) -->
  <div style="width: 30px;"></div>
</header>
  <nav>
<div class="dropdown">
  <a href="content-management_home-page.php" class="active">HOME PAGE</a>
  <div class="dropdown-content">
    <a href="content-management_home-page.php">Carousel Image</a>
    <a href="content-management_home-page_text.php">Doctrinal Content</a>
    <a href="content-management_apostol.php">Apostol Creed</a>
  </div>
</div>
    <a href="content-management_service.php">SERVICE</a>
    <a href="content-management_events.php">EVENTS</a>
    <a href="content-management_gallery.php">GALLERY</a>
    <a href="content-management_ministries.php">MINISTRIES</a>
    <a href="content-management_join-us.php">JOIN US</a>
    <a href="content-management_find-us.php">FIND US</a>
  </nav>
  <main>
    <h2>EDIT THE APOSTLE'S CREED</h2>
    <section class="content-box" aria-label="Apostle's Creed content">
      <p>
        I believe in God the Father almighty; Maker of heaven and ear h. And in
        Jesus Christ his only begotten Son our Lord; who was conceived by the
        Holy Ghost, born of the virgin Mary; suffered under Pontius Pilate, was
        crucified, died, and buried; the third day he rose from the dead; he
        ascended into heaven; and sitteth at the right hand of God the Father
        almighty; from thence he shall come to judge the quick and dead.
      </p>
      <p>
        I believe in the Holy Ghost; the holy catholic church; the communion of
        saints; the forgiveness of sins; the resurrection of the body; and life
        everlasting.
      </p>
      <p>
        People for works of service so that the body of Christ may be built up
        (Ephesians 4:11-12).
      </p>
      <p>
        Each of you should look not only to your own interests, but also to the
        interests of others. Your attitude should be the same as (Pat of Christ
        Jesus, Who.. (took on) the very nature of a servant (Philippians
        4:11-12).
      </p>
      <p class="center">4. I will support the testimony of my church</p>
      <p class="center">a. By attending faithfully</p>
      <p class="center">b. By living a godly life</p>
      <p class="center">c. By giving regularly</p>
      <p>
        Let us not give up meeting together... but let us encourage one another
        (Hebrews 10:25).
      </p>
      <p>
        Whatever happens, make sure that your everyday life is worthy of the
        gospel of Christ (Philippians 1:27 Phillips).
      </p>
      <p>
        Each one of you, on the first day of each week, should set aside a
        specific sum of money in proportion to what you have earned and use it
        for the offering (1 corinthians 16:2LP).
      </p>
      <p class="center">
        A tenth of (all your) produce..is the Lord's, and is holy (Leviticus
        27:30 NCV).
      </p>
      <p class="center">WHO WE ARE:</p>
      <p class="center">
        We are a church affiliated with the Assemblies of God, with 54 million
        worshippers worldwide.
      </p>
    </section>
    <button class="edit-btn" type="button">Edit Changes</button>
  </main>
</body>
</html>