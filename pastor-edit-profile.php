<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Administrator Registration</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@700&display=swap');

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      background-color: white;
      font-family: 'Inter', sans-serif;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    header {
      background-color: #12153D;
      display: flex;
      align-items: center;
      gap: 16px;
      padding: 24px 48px;
      flex-shrink: 0;
    }

    header button {
      background: none;
      border: none;
      color: white;
      font-size: 22px;
      cursor: pointer;
    }

    header img {
      width: 40px;
      height: 40px;
    }

    header h1 {
      color: white;
      font-weight: 800;
      font-size: 18px;
      margin: 0;
      white-space: nowrap;
    }

    main {
      background-color: #020B4B;
      max-width: 1400px;
      margin: 48px auto 48px auto;
      padding: 64px 96px;
      border-radius: 4px;
      flex-grow: 1;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    main h2 {
      color: white;
      font-weight: 800;
      font-size: 18px;
      margin: 0 0 48px 0;
      text-align: center;
    }

    form {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 32px 96px;
      max-width: 1200px;
      margin: 0 auto;
      max-height: 600px;
      overflow-y: auto;
    }

    label {
      display: block;
      color: white;
      font-weight: 800;
      font-size: 14px;
      margin-bottom: 12px;
    }

    input {
      width: 100%;
      max-width: 400px;
      padding: 12px 16px;
      border: none;
      background-color: white;
      font-size: 16px;
      font-family: inherit;
      border-radius: 2px;
    }

    .button-wrapper {
      grid-column: 1 / 3;
      display: flex;
      justify-content: center;
      align-items: center;
      margin-top: 16px;
    }

    button.save-btn {
      background-color: #3AB0F3;
      border: 1px solid #7AB9F8;
      color: black;
      font-weight: 800;
      font-size: 16px;
      padding: 12px 48px;
      cursor: pointer;
      max-width: 200px;
      width: 100%;
      border-radius: 4px;
      display: block;
    }
  </style>
</head>
<body>
<nav style="background-color: #0A0E3F; display: flex; align-items: center; justify-content: space-between; padding: 10px 20px; color: white;">
  <!-- Back Button -->
  <a href="account-admin.php" style="text-decoration: none;">
    <img src="image/btn-back.png" alt="Back" style="width: 30px; height: 30px; cursor: pointer;">
  </a>

  <!-- Centered logo + title -->
  <div style="display: flex; align-items: center; gap: 10px;">
    <img src="image/httc_main-logo.jpg" alt="Wedding logo" style="width: 50px; height: 50px; border-radius: 50%;">
    <h1 style="margin: 0; font-size: 20px;">PASTOR EDIT INFORMATION</h1>
  </div>

  <!-- Right spacer (to balance layout) -->
  <div style="width: 30px;"></div>
</nav>
  <main>
    <h2>EDIT PASTOR PROFILE</h2>
    <form>
      <div>
        <label for="firstName">FIRST NAME</label>
        <input type="text" id="firstName" name="firstName" />
      </div>
      <div>
        <label for="username">USERNAME</label>
        <input type="text" id="username" name="username" />
      </div>
      <div>
        <label for="middleName">MIDDLE NAME</label>
        <input type="text" id="middleName" name="middleName" />
      </div>
      <div>
        <label for="password">PASSWORD</label>
        <input type="password" id="password" name="password" />
      </div>
      <div>
        <label for="lastName">LAST NAME</label>
        <input type="text" id="lastName" name="lastName" />
      </div>
      <div>
        <label for="confirmPassword">CONFIRM PASSWORD</label>
        <input type="password" id="confirmPassword" name="confirmPassword" />
      </div>
      <div>
        <label for="email">EMAIL ADDRESS</label>
        <input type="email" id="email" name="email" />
      </div>
      <div>
        <label for="contactNumber">CONTACT NUMBER</label>
        <input type="tel" id="contactNumber" name="contactNumber" />
      </div>
      <div class="button-wrapper">
        <button type="submit" class="save-btn" style="">SAVE</button>
      </div>
    </form>
  </main>
</body>
</html>