<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="individual-appointment.css" />
  <script src="individual-appointment.js" defer></script>
  <title>Appointment Page</title>
</head>
<body>
  <header>
    <img src="image/main-logo.png" alt="Logo" class="logo" />
    <nav>
      <a href="individual-appointment.html" style="text-decoration: underline;">BOOK AN APPOINTMENT</a>
      <a href="individual-request.html">REQUEST PRAYER</a>
      <a href="individual-acc-history.html">ACCOUNT HISTORY</a>
    </nav>
    <div class="icons">
      <span class="bell-icon">🔔</span>
      <span class="logout-icon">⏏</span>
    </div>
  </header>

  <main>
    <h1>Make an Appointment</h1>
    <section class="appointment-section">
      <div class="calendar-container">
        <h3>Select Date</h3>
        <div id="calendar"></div>
      </div>

      <div class="form-container">
        <label>Purpose
          <select id="purpose">
            <option>Wedding</option>
            <option>Baptism</option>
            <option>Counseling</option>
            <option>Memorial Service</option>
            <option>House Blessing</option>
          </select>
        </label>

        <label>Other Information
          <input type="text" placeholder="Other Information" />
        </label>

        <label>Preferred Time
          <input type="time" />
        </label>

        <button id="book-btn">Book an Appointment</button>
      </div>
    </section>
  </main>
</body>
</html>
