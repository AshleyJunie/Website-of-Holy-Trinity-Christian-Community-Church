<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta content="width=device-width, initial-scale=1" name="viewport" />
  <title>HTCCC - Events</title>

  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/event-page.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/event-page.css'); ?>">
  <link rel="stylesheet" href="/HTCCC-SYSTEM/css/global-layout.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/global-layout.css'); ?>">

  <!-- ✅ MODAL + CLICKABLE CARD STYLES (add here OR move to your CSS file) -->
  <style>
    .event-card { cursor: pointer; }

    /* ✅ Hide details text in cards (outside modal) */
    .event-card-body .eventDetails { display: none; }

    .event-modal {
      position: fixed;
      inset: 0;
      z-index: 9999;
      display: none;
    }
    .event-modal.is-open { display: block; }

    .event-modal-backdrop {
      position: absolute;
      inset: 0;
      background: rgba(2, 6, 23, 0.55);
      backdrop-filter: blur(6px);
    }

    .event-modal-content {
      position: relative;
      max-width: 920px;
      width: calc(100% - 28px);
      margin: 6vh auto;
      background: #fff;
      border-radius: 18px;
      overflow: hidden;
      box-shadow: 0 30px 80px rgba(0,0,0,0.35);
    }

    .event-modal-close {
      position: absolute;
      top: 12px;
      right: 12px;
      width: 42px;
      height: 42px;
      border-radius: 999px;
      border: none;
      background: rgba(255,255,255,0.95);
      font-size: 26px;
      font-weight: 900;
      cursor: pointer;
      z-index: 3;
    }

    .event-modal-grid {
      display: grid;
      grid-template-columns: 1.25fr 1fr;
      min-height: 420px;
    }

    .event-modal-imageWrap {
      background: linear-gradient(135deg, rgba(76,99,255,0.18), rgba(2,6,23,0.05));
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 18px;
    }

    .event-modal-imageWrap img {
      width: 100%;
      height: 100%;
      max-height: 460px;
      object-fit: cover;
      border-radius: 14px;
      display: block;
    }

    .event-modal-body {
      padding: 22px;
    }

    .event-modal-body h2 {
      margin: 0 0 10px;
      font-size: 1.7rem;
      font-weight: 900;
      color: #0b1446;
    }

    .event-modal-date {
      margin: 0 0 12px;
      font-weight: 800;
      color: rgba(2,6,23,0.62);
      font-size: 0.95rem;
    }

    .event-modal-desc {
      margin: 0 0 18px;
      color: rgba(2,6,23,0.75);
      line-height: 1.6;
      font-size: 0.98rem;
    }

    .event-modal-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .event-modal-actions .btn-solid,
    .event-modal-actions .btn-outline {
      padding: 10px 14px;
      border-radius: 12px;
      font-weight: 900;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: none;
    }

    .event-modal-actions .btn-solid {
      background: #4c63ff;
      color: #fff;
    }

    .event-modal-actions .btn-outline {
      background: #fff;
      border: 1px solid rgba(15,23,42,0.18);
      color: #4c63ff;
    }

    @media (max-width: 820px){
      .event-modal-grid { grid-template-columns: 1fr; }
      .event-modal-content { margin: 4vh auto; }
    }
  </style>
</head>

<body>
<header>
  <?php include $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/includes/navigation.php'; ?>
</header>

<div class="event-container">

  <div class="event-header">
    <h1 class="event-page-title">Upcoming <span>Events</span></h1>
    <p class="event-page-subtitle">Join us for worship, fellowship, and community outreach.</p>
  </div>

  <div class="event-layout">

    <section class="event-main">

      <div class="event-hero">
        <div class="event-hero-overlay">
          <!-- ✅ HERO will show TODAY's event only; otherwise shows "No event today" indicator -->
          <span class="event-pill" id="heroPill">Event Today</span>
          <h2 class="event-hero-title" id="heroTitle"></h2>
          <p class="event-hero-meta" id="heroMeta"></p>
          <p class="event-hero-desc" id="heroDesc"></p>
          <div class="event-hero-actions">
            <a href="#" class="event-hero-btn primary" id="heroLearnMoreBtn">Learn More</a>
          </div>
        </div>
      </div>

      <div class="event-controls-row">
        <div class="event-tabs">
          <button class="event-tab is-active" data-filter="all" type="button">All Events</button>
          <button class="event-tab" data-filter="upcoming" type="button">Upcoming</button>
          <button class="event-tab" data-filter="previous" type="button">Past Events</button>
        </div>

        <div class="event-search-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input id="eventSearchInput" type="text" placeholder="Search events..." />
        </div>
      </div>

      <div class="event-carousel-wrapper">
        <button id="eventPrevBtn" class="event-arrow-btn" aria-label="Previous">‹</button>

        <div class="event-carousel-track" id="eventCarouselTrack">

          <!-- Card 1 -->
          <div class="event-card event-card-slot" data-slot="0">
            <div class="event-card-top">
              <div class="event-date-badge">
                <span class="month">APR</span>
                <span class="day">12</span>
              </div>
              <div class="event-thumb">
                <img class="eventImage" src="" alt="" />
              </div>
            </div>

            <div class="event-card-body">
              <h3 class="eventTitle"></h3>
              <p class="eventTime"></p>

              <!-- ✅ Keep element (optional), but we won't fill it + it's hidden in CSS -->
              <p class="eventDetails"></p>

              <div class="event-card-actions eventCta">
                <a class="eventLink btn-outline" href="#" target="_blank" rel="noopener noreferrer">View Details</a>
                <a class="eventLink2 btn-solid" href="#" target="_blank" rel="noopener noreferrer">Register</a>
              </div>
            </div>
          </div>

          <!-- Card 2 -->
          <div class="event-card event-card-slot" data-slot="1">
            <div class="event-card-top">
              <div class="event-date-badge">
                <span class="month">APR</span>
                <span class="day">14</span>
              </div>
              <div class="event-thumb">
                <img class="eventImage" src="" alt="" />
              </div>
            </div>

            <div class="event-card-body">
              <h3 class="eventTitle"></h3>
              <p class="eventTime"></p>

              <!-- ✅ Keep element (optional), but we won't fill it + it's hidden in CSS -->
              <p class="eventDetails"></p>

              <div class="event-card-actions eventCta">
                <a class="eventLink btn-outline" href="#" target="_blank" rel="noopener noreferrer">View Details</a>
                <a class="eventLink2 btn-solid" href="#" target="_blank" rel="noopener noreferrer">Register</a>
              </div>
            </div>
          </div>

        </div>

        <button id="eventNextBtn" class="event-arrow-btn" aria-label="Next">›</button>
      </div>
    </section>

    <aside class="event-sidebar">
      <div class="side-card">
        <h3>Featured Event</h3>
        <div class="featured-card" id="featuredCard">
          <div class="featured-img" id="featuredImg"></div>
          <div class="featured-body">
            <h4 id="featuredTitle">Loading...</h4>
            <p class="muted" id="featuredMeta"></p>
            <p class="small" id="featuredDesc"></p>
            <!-- ✅ This will be set to the featured event's event_link (Google Form) -->
            <a class="btn-solid full" id="featuredRegisterBtn" href="#" target="_blank" rel="noopener noreferrer">Register Now</a>
          </div>
        </div>
      </div>
    </aside>

  </div>
</div>

<footer style="background-color: #1B1B4B; color: white; padding: 30px 40px;">
  <?php include $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/includes/footer.php'; ?>
</footer>

<!-- ✅ EVENT MODAL -->
<!-- ✅ EVENT MODAL -->
<div id="eventModal" class="event-modal" aria-hidden="true">
  <div class="event-modal-backdrop" id="eventModalBackdrop"></div>

  <div class="event-modal-content" role="dialog" aria-modal="true" aria-label="Event details">
    <button class="event-modal-close" id="eventModalClose" aria-label="Close modal">×</button>

    <div class="event-modal-grid">
      <!-- Left: Image -->
      <div class="event-modal-media">
        <div class="event-modal-mediaBox">
          <img id="eventModalImg" src="" alt="" />
        </div>
      </div>

      <!-- Right: Content -->
      <div class="event-modal-body">
        <h2 id="eventModalTitle"></h2>
        <p id="eventModalDate" class="event-modal-date"></p>
        <p id="eventModalDesc" class="event-modal-desc"></p>

        <div class="event-modal-actions">
          <a id="eventModalLink" class="btn-solid" href="#" target="_blank" rel="noopener noreferrer">
            Open Link
          </a>
          <button class="btn-outline" id="eventModalClose2" type="button">Close</button>
        </div>
      </div>
    </div>
  </div>
</div>


<script>
<?php
  $pdo = new PDO("mysql:host=localhost;dbname=htccc-data-base", "root", "");
  $stmt = $pdo->prepare("
    SELECT eventId, title, category, imgSrc, imgAlt, details, event_date, event_link
    FROM events_table
    ORDER BY event_date DESC, eventId DESC
  ");
  $stmt->execute();
  $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
const events = <?php echo json_encode($events, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;

function toDateOnly(d){
  const x = new Date(d);
  if (isNaN(x.getTime())) return null;
  x.setHours(0,0,0,0);
  return x;
}
const today = new Date(); today.setHours(0,0,0,0);

function isSameDay(a,b){
  return a && b && a.getFullYear()===b.getFullYear() && a.getMonth()===b.getMonth() && a.getDate()===b.getDate();
}

function formatMonthDay(dateStr){
  const d = new Date(dateStr);
  if (isNaN(d.getTime())) return {month:"", day:""};
  return {
    month: d.toLocaleString("en-US",{month:"short"}).toUpperCase(),
    day: String(d.getDate()).padStart(2,"0")
  };
}

function formatPrettyDate(dateStr){
  const d = new Date(dateStr);
  if (isNaN(d.getTime())) return "";
  return d.toDateString();
}

/* =========================
   ✅ HERO: EVENT TODAY ONLY
   ========================= */
const hero = document.querySelector(".event-hero");
const heroPill = document.getElementById("heroPill");
const heroTitle = document.getElementById("heroTitle");
const heroMeta = document.getElementById("heroMeta");
const heroDesc = document.getElementById("heroDesc");
const heroLearnMoreBtn = document.getElementById("heroLearnMoreBtn");

function getTodayEvent(){
  if (!Array.isArray(events) || events.length === 0) return null;
  return events.find(e => isSameDay(toDateOnly(e.event_date), today)) || null;
}

function setHeroNoEventToday(){
  heroPill.textContent = "No Event Today";
  heroTitle.textContent = "There’s no event scheduled for today.";
  heroMeta.textContent = "";
  heroDesc.textContent = "Check back later or view upcoming events below.";
  heroLearnMoreBtn.style.display = "none";

  if (hero) {
    hero.style.backgroundImage = "";
    hero.style.backgroundSize = "";
    hero.style.backgroundPosition = "";
  }
}

function setHeroFromTodayEvent(ev){
  heroPill.textContent = "Event Today";
  heroTitle.textContent = ev.title || "";
  heroDesc.textContent = ev.details || "";

  const prettyDate = ev.event_date ? formatPrettyDate(ev.event_date) : "";
  const cat = (ev.category || "").trim();
  heroMeta.textContent = cat ? `${prettyDate} • ${cat}` : prettyDate;

  const img = (ev.imgSrc || "").trim();
  if (img && hero) {
    hero.style.backgroundImage = `url('${img}')`;
    hero.style.backgroundSize = "cover";
    hero.style.backgroundPosition = "center";
  }

  heroLearnMoreBtn.style.display = "inline-flex";
  heroLearnMoreBtn.onclick = (e) => {
    e.preventDefault();
    openModal(ev);
  };
}

const todayEvent = getTodayEvent();
if (todayEvent) setHeroFromTodayEvent(todayEvent);
else setHeroNoEventToday();

/* =========================
   ✅ SIDEBAR: FEATURED EVENT
   - Finds category == "featured"
   - Shows its image inside .featured-img
   - Sets the "Register Now" button to event_link (Google Form)
   ========================= */
const featuredImg = document.getElementById("featuredImg");
const featuredTitle = document.getElementById("featuredTitle");
const featuredMeta = document.getElementById("featuredMeta");
const featuredDesc = document.getElementById("featuredDesc");
const featuredRegisterBtn = document.getElementById("featuredRegisterBtn");

function getFeaturedEvent(){
  if (!Array.isArray(events) || events.length === 0) return null;
  return events.find(e => (e.category || "").trim().toLowerCase() === "featured") || null;
}

function setNoFeaturedEvent(){
  featuredTitle.textContent = "No Featured Event";
  featuredMeta.textContent = "";
  featuredDesc.textContent = "Please add an event with category = \"featured\".";
  featuredRegisterBtn.style.display = "none";
  if (featuredImg) featuredImg.style.backgroundImage = "";
}

function setFeaturedEvent(ev){
  featuredTitle.textContent = ev.title || "Featured Event";
  featuredMeta.textContent = ev.event_date ? formatPrettyDate(ev.event_date) : "";
  featuredDesc.textContent = ev.details || "";

  // Show featured image using background (keeps your existing HTML structure)
  const img = (ev.imgSrc || "").trim();
  if (featuredImg) {
    if (img) {
      featuredImg.style.backgroundImage = `url('${img}')`;
      featuredImg.style.backgroundSize = "cover";
      featuredImg.style.backgroundPosition = "center";
      featuredImg.style.borderRadius = "12px";
      featuredImg.style.minHeight = "140px";
    } else {
      featuredImg.style.backgroundImage = "";
      featuredImg.style.minHeight = "140px";
    }
  }

  // Set the button to event_link (Google Form link)
  const link = (ev.event_link || "").trim();
  if (link) {
    featuredRegisterBtn.href = link;
    featuredRegisterBtn.style.display = "block";
  } else {
    featuredRegisterBtn.style.display = "none";
  }
}

const featuredEvent = getFeaturedEvent();
if (featuredEvent) setFeaturedEvent(featuredEvent);
else setNoFeaturedEvent();

/* =========================
   CAROUSEL STATE
   ========================= */
let filteredEvents = [...events];
let currentPage = 0;

const track = document.getElementById("eventCarouselTrack");
const prevBtn = document.getElementById("eventPrevBtn");
const nextBtn = document.getElementById("eventNextBtn");
const searchInput = document.getElementById("eventSearchInput");

/* ✅ Cards per page */
function cardsPerPage(){ return window.matchMedia("(max-width: 767.98px)").matches ? 1 : 2; }
function totalPages(){ return Math.max(1, Math.ceil(filteredEvents.length / cardsPerPage())); }

/* =========================
   MODAL LOGIC
   ========================= */
const modal = document.getElementById("eventModal");
const modalBackdrop = document.getElementById("eventModalBackdrop");
const modalClose = document.getElementById("eventModalClose");
const modalClose2 = document.getElementById("eventModalClose2");

const modalImg = document.getElementById("eventModalImg");
const modalTitle = document.getElementById("eventModalTitle");
const modalDate = document.getElementById("eventModalDate");
const modalDesc = document.getElementById("eventModalDesc");
const modalLink = document.getElementById("eventModalLink");

function openModal(ev){
  modalImg.src = ev.imgSrc || "";
  modalImg.alt = ev.imgAlt || ev.title || "Event image";
  modalTitle.textContent = ev.title || "";
  modalDate.textContent = ev.event_date ? formatPrettyDate(ev.event_date) : "";

  /* ✅ Details ONLY in modal */
  modalDesc.textContent = ev.details || "";

  const link = (ev.event_link || "").trim();
  if (link){
    modalLink.href = link;
    modalLink.style.display = "inline-flex";
  } else {
    modalLink.style.display = "none";
  }

  modal.classList.add("is-open");
  modal.setAttribute("aria-hidden", "false");
  document.body.style.overflow = "hidden";
}

function closeModal(){
  modal.classList.remove("is-open");
  modal.setAttribute("aria-hidden", "true");
  document.body.style.overflow = "";
}

modalBackdrop.addEventListener("click", closeModal);
modalClose.addEventListener("click", closeModal);
modalClose2.addEventListener("click", closeModal);

document.addEventListener("keydown", (e)=>{
  if (e.key === "Escape" && modal.classList.contains("is-open")) closeModal();
});

function bindCardClicks(){
  const per = cardsPerPage();
  const start = currentPage * per;
  const cards = track.querySelectorAll(".event-card-slot");

  for (let slot=0; slot<per; slot++){
    const card = cards[slot];
    const ev = filteredEvents[start + slot];
    if (!card) continue;

    card.onclick = null;

    if (!ev){
      card.style.cursor = "default";
      continue;
    }

    card.style.cursor = "pointer";
    card.onclick = () => openModal(ev);

    const links = card.querySelectorAll("a");
    links.forEach(a => {
      a.addEventListener("click", (e) => {
        e.stopPropagation();
      });
    });
  }
}

/* =========================
   RENDER / FILTER
   ========================= */
function renderPage(){
  const per = cardsPerPage();
  const start = currentPage * per;
  const cards = track.querySelectorAll(".event-card-slot");

  cards.forEach((card, idx) => {
    card.style.display = (per === 1 && idx === 1) ? "none" : "";
  });

  for (let slot=0; slot<per; slot++){
    const ev = filteredEvents[start + slot];
    const card = cards[slot];

    if (!ev){
      card.style.visibility = "hidden";
      continue;
    }
    card.style.visibility = "visible";

    const img = card.querySelector(".eventImage");
    img.src = ev.imgSrc || "";
    img.alt = ev.imgAlt || ev.title || "";

    card.querySelector(".eventTitle").textContent = ev.title || "";

    /* ✅ DO NOT show details on card */
    // card.querySelector(".eventDetails").textContent = ev.details || "";

    const bd = formatMonthDay(ev.event_date);
    const monthEl = card.querySelector(".event-date-badge .month");
    const dayEl = card.querySelector(".event-date-badge .day");
    if (monthEl) monthEl.textContent = bd.month;
    if (dayEl) dayEl.textContent = bd.day;

    const link = (ev.event_link || "").trim();
    const a1 = card.querySelector(".eventLink");
    const a2 = card.querySelector(".eventLink2");

    if (link) {
      a1.href = link;
      a2.href = link;
      card.querySelector(".eventCta").style.display = "flex";
    } else {
      card.querySelector(".eventCta").style.display = "none";
    }
  }

  const show = totalPages() > 1;
  prevBtn.style.display = show ? "" : "none";
  nextBtn.style.display = show ? "" : "none";

  bindCardClicks();
}

function changePage(delta){
  const pages = totalPages();
  currentPage = (currentPage + delta + pages) % pages;
  renderPage();
}

function applySearchAndFilter(mode){
  const normalized = (mode || "all").toLowerCase();
  const q = (searchInput?.value || "").trim().toLowerCase();

  let base = [...events];

  if (normalized === "today"){
    base = base.filter(e => isSameDay(toDateOnly(e.event_date), today));
  } else if (normalized === "upcoming"){
    base = base.filter(e => {
      const d = toDateOnly(e.event_date);
      return d && d.getTime() >= today.getTime();
    });
  } else if (normalized === "previous"){
    base = base.filter(e => {
      const d = toDateOnly(e.event_date);
      return d && d.getTime() < today.getTime();
    });
  }

  if (q){
    base = base.filter(e =>
      (e.title || "").toLowerCase().includes(q) ||
      (e.details || "").toLowerCase().includes(q) ||
      (e.category || "").toLowerCase().includes(q)
    );
  }

  filteredEvents = base;
  currentPage = 0;
  renderPage();
}

/* Tabs */
document.querySelectorAll(".event-tab").forEach(tab => {
  tab.addEventListener("click", () => {
    document.querySelectorAll(".event-tab").forEach(t => t.classList.remove("is-active"));
    tab.classList.add("is-active");
    applySearchAndFilter(tab.dataset.filter);
  });
});

/* Search */
searchInput?.addEventListener("input", () => {
  const active = document.querySelector(".event-tab.is-active");
  applySearchAndFilter(active?.dataset.filter || "all");
});

/* Arrows */
prevBtn.addEventListener("click", () => changePage(-1));
nextBtn.addEventListener("click", () => changePage(1));

/* Resize */
window.addEventListener("resize", () => {
  const pages = totalPages();
  if (currentPage >= pages) currentPage = pages - 1;
  renderPage();
});

/* Default */
applySearchAndFilter("all");
</script> 

</body>
</html>
