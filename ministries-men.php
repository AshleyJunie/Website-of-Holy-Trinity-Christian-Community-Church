<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Women’s Ministries</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Open+Sans&display=swap');

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      background: #fff;
      font-family: 'Open Sans', sans-serif;
      color: #1B1B1B;
    }
    ul, ol {
  list-style-type: none;     
}
    .container {
      max-width: 1200px;
      margin: 0 auto;
      padding: 40px 24px;
      display: flex;
      align-items: flex-start;
      gap: 40px;
    }

    .back-button {
      flex-shrink: 0;
      width: 40px;
      height: 40px;
      border: 2px solid #1B3A7A;
      border-radius: 50%;
      color: #1B3A7A;
      font-size: 24px;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: background-color 0.3s, color 0.3s;
      margin-right: 20px;
      margin-top: 4px;
    }

    .back-button:hover {
      background-color: #1B3A7A;
      color: white;
    }

    .content {
      max-width: 480px;
      flex: 1 1 480px;
      position: relative;
    }

    .title {
      font-family: 'Montserrat', sans-serif;
      font-weight: 700;
      font-size: 24px;
      color: #0B1446;
      margin: 100px 0 50px 100px;
      text-align: left;

    }

    @media (min-width: 768px) {
      .title {
        font-size: 28px;
      }
    }

    .description {
      font-size: 15px;
      line-height: 1.3;
      margin-right: 50px;
      padding-right: 20px;
      text-align: left;
    }

    @media (min-width: 768px) {
      .description {
        font-size: 15px;
      }
    }
p{
    font-size: 15px;
}
    .how-to-join {
      font-size: 30px;
      line-height: 1.3;
      max-width: 380px;
      margin: 0;
      text-align: left;
    }

    @media (min-width: 768px) {
      .how-to-join {
        font-size: 14px;
        max-width: none;
      }
    }

    .how-to-join strong {
      display: block;
      margin-bottom: 8px;
    }

    .cross-bg {
      position: absolute;
      top: -20px;
      right: -60px;
      width: 200px;
      height: 300px;
      opacity: 0.2;
      pointer-events: none;
      user-select: none;
      z-index: -1;
    }

    .image-container {
      flex: 1 1 600px;
      max-width: 500px;
      order: 2;
      margin-top: 50px;
    }

    .image-container img {
      width: 100%;
      height: auto;
      object-fit: cover;
      display: block;
    }

    @media (max-width: 640px) {
      .container {
        flex-direction: column;
        align-items: center;
      }
      .content, .image-container {
        max-width: 100%;
        flex: none;
      }
      .title, .description, .how-to-join {
        text-align: center;
      }
      .cross-bg {
        display: none;
      }
      .back-button {
        margin-right: 0;
        margin-bottom: 20px;
      }
      .image-container {
        order: 0;
      }
    }
    body {
        background-image: url("image/all-background.png");
        background-position: center;
        background-size: cover;
        background-repeat: no-repeat;
        background-attachment: fixed;

    }

    section, nav, div, h2 {
        background: transparent;
    }
    body::-webkit-scrollbar {
    display: none; 
  }

html, body {
    height: 100%;
}
html {
    scroll-behavior: smooth;
}
section {
    padding: 50px;
    height: 100vh;
}

header {
    background: #fff;
    padding: 15px 60px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    z-index: 1000;
}


.logo {
    display: flex;
    align-items: center;
}

.logo img {
    height: 60px;
}
nav {
    background-color: #0B1446;
    height: 100px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    padding: 0 20px;
}

.back-arrow {
    position: absolute;
    top: 10px;
    left: 20px;
    text-decoration: none;
}

.back-arrow-img {
    height: 50px;
    width: auto; 
    margin-top: 20px;
}

.nav-header {
    color: white;
    margin: 0;
    font-size: 24px;
}

.nav-logo {
    height: 60px;
    margin-left: 20px;
    border-radius: 50%;
    object-fit: cover;
}

  </style>
</head>
<nav>
    <a href="main-page.php" class="back-arrow">
        <img src="image/btn-back.png" alt="Back" class="back-arrow-img">
    </a>
    <h1 class="nav-header">HOLY TRINITY CHRISTIAN COMMUNITY CHURCH</h1>
    <img src="image/httc_main-logo.jpg" alt="Church Logo" class="nav-logo">
</nav>

    </header>
<body>
  <div class="container">
    <div class="content">
      <h1 class="title">Women’s Ministries</h1>
      <p class="description">
        The Women’s Ministry in our church empowers and supports women in their spiritual journey and daily lives. Through programs and events, it fosters spiritual growth, meaningful connections, and active service. It also promotes personal development and offers counseling for those facing challenges, helping women navigate life with faith and strength.
      </p>
      <div class="how-to-join">
        <strong>How to join?</strong>
        <p>
          To become part of the Women’s Ministry, simply start by attending church regularly and expressing your interest to one of the ministry leaders or the pastor. Staying involved and being consistent in your walk with Christ are key steps to growing with the ministry. Everyone is welcome—whether you’re new in faith or have been serving for years.
        </p>
      </div>
    </div>
    <div class="image-container">
      <img src="image/ministries-picture1.png" alt="Group of six women standing and sitting in a church room with Christmas decorations on the wall and windows letting in daylight" />
    </div>
  </div>
</body>
</html>