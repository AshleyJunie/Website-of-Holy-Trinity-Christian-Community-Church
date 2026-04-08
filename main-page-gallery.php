<?php
// ---------------------------------------------------------
// Session (safe to call once per request)
// ---------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// ---------------------------------------------------------
// DB — main gallery/album data
// ---------------------------------------------------------
$pdo = new PDO(
  "mysql:host=localhost;dbname=htccc-data-base;charset=utf8",
  "root",
  "",
  [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]
);

// ===== Album covers (normalize empty album -> 'Unnamed Album') =====
$albumStmt = $pdo->query("
  SELECT lm.norm_album AS album_type,
         g1.imgSrc,
         g1.imgAlt,
         (SELECT COUNT(*) FROM gallery_table g3
          WHERE COALESCE(NULLIF(g3.album_type,''),'Unnamed Album') = lm.norm_album) AS photo_count
  FROM (
    SELECT COALESCE(NULLIF(album_type,''),'Unnamed Album') AS norm_album,
           MAX(created_at) AS latest
    FROM gallery_table
    GROUP BY norm_album
  ) lm
  JOIN gallery_table g1
    ON COALESCE(NULLIF(g1.album_type,''),'Unnamed Album') = lm.norm_album
   AND g1.created_at = lm.latest
  ORDER BY lm.norm_album ASC
");
$albums = $albumStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>HTCCC — Gallery</title>
  <link rel="icon" type="image/x-icon" href="image/httc_main-logo.jpg">

  <!-- Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <!-- Global layout (fixed nav + footer) -->
  <link rel="stylesheet"
        href="/HTCCC-SYSTEM/css/global-layout.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/global-layout.css'); ?>">

  <!-- Page-scoped CSS (reused) -->
  <link rel="stylesheet"
        href="/HTCCC-SYSTEM/css/main-page-gallery.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/css/main-page-gallery.css'); ?>">

  <style>
    /* ==== Bigger images + subtle shadow ==== */

    /* Album cards (cover images) */
    #albumsPrimaryGrid .album-card img {
      height: 260px;               /* was 180px */
      object-fit: cover;
      box-shadow: 0 8px 20px rgba(0,0,0,.08);
      border-radius: 0;
    }

    @media (min-width: 960px) {
      #albumsPrimaryGrid .album-card img { height: 300px; }
    }

    /* ==== Landscape-style Album Cards ==== */
    #albumsPrimaryGrid {
      display: grid;
      gap: 32px;
      grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
      justify-content: center;
      align-items: start;
    }

    #albumsPrimaryGrid .album-card {
      background: #fff;
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 6px 18px rgba(0,0,0,.08);
      transition: transform .15s ease, box-shadow .15s ease;
    }

    #albumsPrimaryGrid .album-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 28px rgba(0,0,0,.15);
    }

    #albumsPrimaryGrid .album-card img {
      width: 100%;
      height: 200px;
      object-fit: cover;
      display: block;
      box-shadow: 0 4px 12px rgba(0,0,0,.10);
    }

    /* ==== Make the gallery layout wider edge-to-edge ==== */
    #gallery {
      max-width: 95vw;
      margin: 0 auto;
    }

    #albumsPrimaryGrid {
      display: grid;
      gap: 32px;
      grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
      justify-content: center;
      align-items: start;
      width: 100%;
      padding: 0 1vw;
    }

    #albumsPrimaryGrid .album-card img {
      width: 105%;
      margin-left: -2.5%;
      height: 220px;
      object-fit: cover;
      box-shadow: 0 4px 12px rgba(0,0,0,.10);
    }

    #albumsPrimaryGrid .album-card {
      border-radius: 16px;
      overflow: hidden;
      background: #fff;
      box-shadow: 0 6px 18px rgba(0,0,0,.08);
      transition: transform .15s ease, box-shadow .15s ease;
      width: 100%;
    }

    @media (min-width: 1100px) {
      #albumsPrimaryGrid { grid-template-columns: repeat(auto-fill, minmax(460px, 1fr)); }
      #albumsPrimaryGrid .album-card img { height: 260px; }
    }

    @media (max-width: 600px) {
      #albumsPrimaryGrid {
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 20px;
      }
      #albumsPrimaryGrid .album-card img {
        width: 100%;
        margin-left: 0;
        height: 180px;
      }
    }

    /* ==== Show the WHOLE photo (no cropping) + wider cards ==== */
    #albumsPrimaryGrid {
      grid-template-columns: repeat(auto-fill, minmax(480px, 1fr));
      gap: 28px;
      max-width: 100vw;
      margin: 0 auto;
    }

    @media (min-width: 1280px) {
      #albumsPrimaryGrid { grid-template-columns: repeat(auto-fill, minmax(540px, 1fr)); }
    }

    @media (max-width: 640px) {
      #albumsPrimaryGrid { grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 18px; }
    }

    #albumsPrimaryGrid .album-card img {
      width: 100%;
      height: 440px;
      object-fit: contain;
      background: #f3f5f9;
      padding: 6px;
      border-radius: 14px;
      box-shadow: 0 6px 14px rgba(0,0,0,.08);
    }

    @media (min-width: 1100px) {
      #albumsPrimaryGrid .album-card img { height: 260px; }
    }

    #albumsPrimaryGrid .album-card h4 {
      font-size: 1.05rem;
      margin: 10px 14px 0;
      color: #1B1B4B;
      text-align: center;
      font-weight: 600;
    }

    #albumsPrimaryGrid .album-card p {
      text-align: center;
      margin: 4px 0 14px;
      color: #444;
      font-size: .9rem;
    }

    @media (min-width: 992px) {
      #albumsPrimaryGrid { grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); }
      #albumsPrimaryGrid .album-card img { height: 240px; }
    }

    @media (max-width: 600px) {
      #albumsPrimaryGrid { grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px; }
      #albumsPrimaryGrid .album-card img { height: 180px; }
    }

    /* Album viewer thumbnails */
    #albumImages .thumb img {
      height: 220px;
      box-shadow: 0 6px 16px rgba(0,0,0,.08);
      border-radius: 10px;
    }

    @media (min-width: 960px) {
      #albumImages .thumb img { height: 260px; }
    }

    /* Lightbox: soft halo around the big image */
    #lightboxImg { filter: drop-shadow(0 12px 28px rgba(0,0,0,.18)); }

    /* --- Minimal tweaks so albums look great as primary view --- */
    .gallery-toolbar {
      display:flex; justify-content:space-between; align-items:center; gap:12px;
      margin: 16px 0 20px;
    }
    .gallery-toolbar .toolbar-left,
    .gallery-toolbar .toolbar-right { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }

    #albumsPrimaryGrid {
      display:grid; gap:16px;
      grid-template-columns: repeat(auto-fill,minmax(220px,1fr));
    }
    .album-card {
      display:flex; flex-direction:column; gap:10px;
      background:#fff; border-radius:12px; box-shadow:0 6px 18px rgba(0,0,0,.06);
      overflow:hidden; cursor:pointer; transition: transform .12s ease, box-shadow .12s ease;
    }
    .album-card:hover {
      transform: translateY(-2px);
      box-shadow:0 10px 24px rgba(0,0,0,.10);
    }
    .album-card img {
      width:100%; height:180px; object-fit:cover; display:block;
      background:#f6f7fb;
    }
    .album-card h4 {
      margin:0; padding:0 12px; font-size:1rem; line-height:1.3; color:#1B1B4B;
      white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    }
    .album-card p {
      margin:0 0 12px; padding:0 12px; color:#555; font-size:.9rem;
    }

    /* Album viewer */
    #albumViewer { display:none; }
    #albumImages {
      display:grid; gap:10px;
      grid-template-columns: repeat(auto-fill,minmax(160px,1fr));
      margin-top:12px;
    }
    #albumImages .thumb img {
      width:100%; height:140px; object-fit:cover; border-radius:8px; cursor:pointer;
      box-shadow:0 4px 12px rgba(0,0,0,.06);
    }
    #albumsBreadcrumb { color:#1B1B4B; cursor:pointer; text-decoration:underline; }
    .av-header { display:flex; align-items:center; gap:8px; margin-top:10px; }

    /* Lightbox (retained) */
    #lightboxOverlay {
      position:fixed; inset:0; background:rgba(0,0,0,.85); display:none;
      align-items:center; justify-content:center; z-index:1000;
    }
    #lightboxOverlay.active { display:flex; }
    #lightboxImg { max-width:90vw; max-height:80vh; border-radius:10px; }
    #lightboxCaption { color:#fff; margin-top:12px; text-align:center; max-width:85vw; font-size: 0.95rem; line-height: 1.4; }
    .lb-close,.lb-prev,.lb-next {
      position:absolute; top:20px; background:#fff; border:none; border-radius:10px;
      padding:8px 12px; cursor:pointer; font-size:20px; box-shadow:0 4px 12px rgba(0,0,0,.25);
    }
    .lb-prev, .lb-next { top:50%; transform:translateY(-50%); }
    .lb-close { right:20px; }
    .lb-prev  { left:20px; }
    .lb-next  { right:20px; }

    /* Utility */
    .sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); border:0; }
    .btn--link { background:none; border:none; color:#1B1B4B; text-decoration:underline; cursor:pointer; padding:0; }

    .title{
      text-align: center;
      font-size: 30px;
      color: #1B1B4B;
    }

    /* ===== Pagination UI (formal) ===== */
    .albums-pagination {
      display:flex;
      justify-content:center;
      align-items:center;
      gap:10px;
      margin: 18px 0 6px;
      flex-wrap:wrap;
      user-select:none;
    }
    .albums-pagination button {
      border:1px solid rgba(27,27,75,.25);
      background:#fff;
      color:#1B1B4B;
      border-radius:10px;
      padding:8px 12px;
      cursor:pointer;
      box-shadow:0 4px 12px rgba(0,0,0,.06);
      transition: transform .12s ease, box-shadow .12s ease;
      font-weight: 600;
    }
    .albums-pagination button:hover:not(:disabled){
      transform: translateY(-1px);
      box-shadow:0 8px 18px rgba(0,0,0,.10);
    }
    .albums-pagination button:disabled{
      opacity:.45;
      cursor:not-allowed;
      box-shadow:none;
      transform:none;
    }

    .page-dots {
      display:flex;
      gap:6px;
      align-items:center;
      flex-wrap:wrap;
    }
    .page-dot {
      width:36px;
      height:36px;
      border-radius:10px;
      border:1px solid rgba(27,27,75,.25);
      display:flex;
      align-items:center;
      justify-content:center;
      background:#fff;
      cursor:pointer;
      font-weight:700;
      color:#1B1B4B;
      box-shadow:0 4px 12px rgba(0,0,0,.06);
      transition: transform .12s ease, box-shadow .12s ease;
    }
    .page-dot:hover{
      transform: translateY(-1px);
      box-shadow:0 8px 18px rgba(0,0,0,.10);
    }
    .page-dot.active{
      background:#1B1B4B;
      color:#fff;
      border-color:#1B1B4B;
      box-shadow:0 8px 18px rgba(0,0,0,.14);
    }

    .page-count {
      text-align:center;
      color:#2f2f2f;
      font-size:.93rem;
      margin-bottom: 8px;
    }
  </style>
</head>
<body>

  <!-- =========================================
       NAVIGATION (match reference logic exactly)
       ========================================= -->
  <header role="banner" id="siteHeader">
    <?php include $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/includes/navigation.php'; ?>
  </header>

  <main id="main-content">
    <section id="gallery" aria-label="Gallery">
      <h1 class="title">HTCCC GALLERY</h1>

      <!-- ===== Toolbar (Albums-first) ===== -->
      <div class="gallery-toolbar" role="group" aria-label="Album tools">
        <div class="toolbar-left">
          <label class="sr-only" for="albumsSortPrimary">Sort albums</label>
          <select id="albumsSortPrimary" title="Sort albums">
            <option value="az">Title A–Z</option>
            <option value="za">Title Z–A</option>
            <option value="most">Most photos</option>
            <option value="least">Least photos</option>
          </select>
        </div>
        <div class="toolbar-right">
          <input id="albumsSearchPrimary" type="search" placeholder="Search albums…" aria-label="Search albums">
        </div>
      </div>

      <!-- ===== Albums Grid (PRIMARY VIEW) ===== -->
      <div id="albumsPrimaryGrid" aria-live="polite">
        <?php foreach ($albums as $album): ?>
          <div class="album-card"
               data-album="<?= htmlspecialchars($album['album_type']) ?>"
               data-photos="<?= (int)$album['photo_count'] ?>"
               tabindex="0"
               role="button"
               aria-label="Open album <?= htmlspecialchars($album['album_type']) ?>">
            <img src="<?= htmlspecialchars($album['imgSrc']) ?>" alt="<?= htmlspecialchars($album['imgAlt']) ?>">
            <h4 title="<?= htmlspecialchars($album['album_type']) ?>">
              <?= htmlspecialchars($album['album_type']) ?>
            </h4>
            <p><?= (int)$album['photo_count'] ?> Photos</p>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- ===== Pagination controls (SHOW ONLY 5 PAGE BUTTONS TOTAL) ===== -->
      <div id="albumsPaginationWrap" style="display:none;">
        <div class="albums-pagination" role="navigation" aria-label="Albums pagination">
          <button id="albumsPrevBtn" type="button" aria-label="Previous page">Previous</button>
          <div id="albumsPageDots" class="page-dots" aria-label="Page numbers"></div>
          <button id="albumsNextBtn" type="button" aria-label="Next page">Next</button>
        </div>
        <!-- Formal caption -->
        <div id="albumsPageCount" class="page-count"></div>
      </div>

      <!-- ===== Album Viewer (drill-in) ===== -->
      <div id="albumViewer" aria-label="Album viewer">
        <div class="av-header">
          <button id="albumsBreadcrumb" class="btn--link" aria-label="Back to all albums">Albums</button>
          <span>/</span>
          <span id="albumViewerTitle"></span>
        </div>
        <div id="albumImages"></div>
      </div>

    </section>
  </main>

  <!-- =========================================
       FOOTER (match reference logic exactly)
       ========================================= -->
  <footer role="contentinfo" id="siteFooter" style="background-color:#1B1B4B; color:#fff; padding:30px 40px;">
    <?php include $_SERVER['DOCUMENT_ROOT'].'/HTCCC-SYSTEM/includes/footer.php'; ?>
  </footer>

  <!-- ===== Lightbox ===== -->
  <div id="lightboxOverlay" aria-hidden="true">
    <button class="lb-close" aria-label="Close">×</button>
    <button class="lb-prev"  aria-label="Previous image">←</button>
    <img id="lightboxImg" alt="">
    <div id="lightboxCaption"></div>
    <button class="lb-next"  aria-label="Next image">→</button>
  </div>

  <!-- Icons JS (optional) -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/js/all.min.js" defer></script>

  <!-- ===== PAGE JS (Albums-first behavior + PAGINATION: ONLY 5 DOTS TOTAL) ===== -->
  <script>
  // helpers
  const $  = s => document.querySelector(s);
  const $$ = s => Array.from(document.querySelectorAll(s));

  // ====== Primary albums grid controls ======
  const albumsGrid   = $('#albumsPrimaryGrid');
  const albumsSort   = $('#albumsSortPrimary');
  const albumsSearch = $('#albumsSearchPrimary');

  // ====== Pagination elements ======
  const pagWrap   = $('#albumsPaginationWrap');
  const prevBtn   = $('#albumsPrevBtn');
  const nextBtn   = $('#albumsNextBtn');
  const pageDots  = $('#albumsPageDots');
  const pageCount = $('#albumsPageCount');

  const PAGE_SIZE = 10;          // 10 albums per page
  const DOT_LIMIT = 5;           // ALWAYS display ONLY 5 page buttons (page-dot)

  let currentPage = 1;
  let lastVisibleSorted = [];

  function getFilteredSortedVisibleCards() {
    const q = (albumsSearch.value || '').trim().toLowerCase();
    const cards = $$('.album-card');

    // Filter
    cards.forEach(c => {
      const name = (c.dataset.album || '').toLowerCase();
      c.style.display = (q === '' || name.includes(q)) ? '' : 'none';
    });

    // Collect visible
    const visible = cards.filter(c => c.style.display !== 'none');

    // Sort visible
    const mode = albumsSort.value;
    visible.sort((a,b) => {
      if(mode === 'az')   return a.dataset.album.localeCompare(b.dataset.album, undefined, {sensitivity:'base'});
      if(mode === 'za')   return b.dataset.album.localeCompare(a.dataset.album, undefined, {sensitivity:'base'});
      if(mode === 'most') return (+b.dataset.photos) - (+a.dataset.photos);
      return (+a.dataset.photos) - (+b.dataset.photos); // least
    });

    // Re-append sorted visible to DOM to preserve order
    visible.forEach(c => c.parentNode.appendChild(c));

    return visible;
  }

  function computeTotalPages(totalItems) {
    return Math.max(1, Math.ceil(totalItems / PAGE_SIZE));
  }

  // Build exactly 5 page numbers (or less if totalPages < 5):
  // - If near start => 1..5
  // - If near end   => (totalPages-4)..totalPages
  // - Otherwise     => centered: page-2 .. page+2
  function buildFiveDots(totalPages, page) {
    const dots = [];
    const maxDots = Math.min(DOT_LIMIT, totalPages);

    if (totalPages <= maxDots) {
      for (let i = 1; i <= totalPages; i++) dots.push(i);
      return dots;
    }

    // centered range
    let start = page - 2;
    let end   = page + 2;

    // clamp to edges
    if (start < 1) { start = 1; end = start + (maxDots - 1); }
    if (end > totalPages) { end = totalPages; start = end - (maxDots - 1); }

    for (let p = start; p <= end; p++) dots.push(p);
    return dots;
  }

  function renderPagination(totalItems) {
    const totalPages = computeTotalPages(totalItems);

    // clamp page
    if (currentPage > totalPages) currentPage = totalPages;
    if (currentPage < 1) currentPage = 1;

    // show/hide pagination
    pagWrap.style.display = (totalItems > PAGE_SIZE) ? '' : 'none';

    // enable/disable prev/next
    prevBtn.disabled = (currentPage <= 1);
    nextBtn.disabled = (currentPage >= totalPages);

    // Render ONLY 5 dots total
    const dots = buildFiveDots(totalPages, currentPage);

    pageDots.innerHTML = '';
    dots.forEach(p => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'page-dot' + (p === currentPage ? ' active' : '');
      btn.textContent = p;
      btn.setAttribute('aria-label', `Go to page ${p}`);
      btn.addEventListener('click', () => {
        currentPage = p;
        applyPaginationView();
      });
      pageDots.appendChild(btn);
    });

    // Formal caption (no "1–5" range wording; professional)
    // Example: "Page 3 of 18"
    pageCount.textContent = `Page ${currentPage} of ${totalPages}`;
  }

  function applyPaginationView() {
    lastVisibleSorted = getFilteredSortedVisibleCards();

    const totalItems = lastVisibleSorted.length;
    const totalPages = computeTotalPages(totalItems);

    if (currentPage > totalPages) currentPage = totalPages;
    if (currentPage < 1) currentPage = 1;

    // Hide all visible then show the current page slice
    lastVisibleSorted.forEach(c => c.style.display = 'none');

    const startIdx = (currentPage - 1) * PAGE_SIZE;
    const endIdx = startIdx + PAGE_SIZE;
    lastVisibleSorted.slice(startIdx, endIdx).forEach(c => c.style.display = '');

    renderPagination(totalItems);
  }

  // Sort/search reset to page 1
  albumsSort.addEventListener('change', () => {
    currentPage = 1;
    applyPaginationView();
  });
  albumsSearch.addEventListener('input', () => {
    currentPage = 1;
    applyPaginationView();
  });

  prevBtn.addEventListener('click', () => {
    currentPage = Math.max(1, currentPage - 1);
    applyPaginationView();
  });
  nextBtn.addEventListener('click', () => {
    currentPage = currentPage + 1;
    applyPaginationView();
  });

  // initial
  applyPaginationView();

  // ====== Album viewer + lightbox ======
  const albumViewer      = $('#albumViewer');
  const albumImages      = $('#albumImages');
  const albumTitleEl     = $('#albumViewerTitle');
  const albumsBreadcrumb = $('#albumsBreadcrumb');

  let currentAlbumData = [];
  let currentLightboxIdx = 0;

  // click/Enter open album
  function attachAlbumOpenHandlers(){
    $$('.album-card').forEach(card=>{
      const open = async () => {
        const album = card.dataset.album;
        albumTitleEl.textContent = album;

        const res  = await fetch(`get_album.php?album=${encodeURIComponent(album)}`);
        const data = await res.json();
        currentAlbumData = Array.isArray(data) ? data : [];

        albumImages.innerHTML = currentAlbumData.length
          ? currentAlbumData.map((i,idx)=>`
              <figure class="thumb" style="margin:0">
                <img src="${i.imgSrc}" alt="${(i.imgAlt||'').replace(/"/g,'&quot;')}" data-index="${idx}">
              </figure>
            `).join('')
          : '<p style="padding:8px 0;">No images are available in this album.</p>';

        // Show viewer
        albumsGrid.style.display = 'none';
        pagWrap.style.display = 'none';
        albumViewer.style.display = 'block';
        window.scrollTo({ top: 0, behavior: 'smooth' });
      };

      card.addEventListener('click', open);
      card.addEventListener('keydown', (e)=>{ if(e.key==='Enter' || e.key===' ') { e.preventDefault(); open(); }});
    });
  }
  attachAlbumOpenHandlers();

  // back to albums list
  albumsBreadcrumb.addEventListener('click', (e)=>{
    e.preventDefault();
    albumViewer.style.display = 'none';
    albumsGrid.style.display = 'grid';
    currentAlbumData = [];
    albumImages.innerHTML = '';
    albumTitleEl.textContent = '';
    applyPaginationView();
  });

  // ===== Lightbox behavior (retained; captions made formal) =====
  const lb = {
    overlay:  $('#lightboxOverlay'),
    img:      $('#lightboxImg'),
    caption:  $('#lightboxCaption'),
    close:    document.querySelector('#lightboxOverlay .lb-close'),
    prev:     document.querySelector('#lightboxOverlay .lb-prev'),
    next:     document.querySelector('#lightboxOverlay .lb-next'),
  };

  albumImages.addEventListener('click', (e)=>{
    const img = e.target.closest('img[data-index]');
    if(!img) return;
    openLightbox(+img.dataset.index);
  });

  function openLightbox(index){
    if(!currentAlbumData.length) return;
    currentLightboxIdx = Math.max(0, Math.min(index, currentAlbumData.length-1));
    const item = currentAlbumData[currentLightboxIdx];

    lb.img.src = item.imgSrc;
    lb.img.alt = item.imgAlt || '';

    // Formal caption: "Image X of Y — <Title/Alt>"
    const label = (item.title || item.imgAlt || '').trim();
    const lead  = `Image ${currentLightboxIdx + 1} of ${currentAlbumData.length}`;
    lb.caption.textContent = label ? `${lead} — ${label}` : lead;

    lb.overlay.classList.add('active');
    lb.overlay.setAttribute('aria-hidden','false');
    document.body.style.overflow = 'hidden';
    updateLbArrows();
  }

  function closeLightbox(){
    lb.overlay.classList.remove('active');
    lb.overlay.setAttribute('aria-hidden','true');
    lb.img.src = '';
    document.body.style.overflow = '';
  }

  function stepLightbox(delta){
    if(!currentAlbumData.length) return;
    const next = currentLightboxIdx + delta;
    if(next < 0 || next > currentAlbumData.length-1) return;
    openLightbox(next);
  }

  function updateLbArrows(){
    lb.prev.disabled = (currentLightboxIdx <= 0);
    lb.next.disabled = (currentLightboxIdx >= currentAlbumData.length-1);
  }

  lb.close.addEventListener('click', closeLightbox);
  lb.prev .addEventListener('click', ()=> stepLightbox(-1));
  lb.next .addEventListener('click', ()=> stepLightbox(+1));
  lb.overlay.addEventListener('click', (e)=>{ if(e.target === lb.overlay) closeLightbox(); });
  document.addEventListener('keydown', (e)=>{
    if(!lb.overlay.classList.contains('active')) return;
    if(e.key === 'Escape') closeLightbox();
    if(e.key === 'ArrowLeft')  stepLightbox(-1);
    if(e.key === 'ArrowRight') stepLightbox(+1);
  });
  </script>

  <script>
/*
  Footer dropdown builder (page-only):
  - Hahanapin ang column na may heading "NAVIGATION"
  - Gagawing submenu ang mga items sa ilalim ng "SERVICE" at "MINISTRIES"
  - Walang edit sa PHP; DOM lang ang ginagalaw sa runtime
*/
(function () {
  const footer = document.getElementById('siteFooter');
  if (!footer) return;

  const navCol = Array.from(footer.querySelectorAll('h3, h2, .footer-title')).find(h =>
    (h.textContent || '').trim().toUpperCase() === 'NAVIGATION'
  );
  if (!navCol) return;

  let ul = navCol.nextElementSibling;
  while (ul && ul.tagName !== 'UL') ul = ul.nextElementSibling;
  if (!ul) return;
  ul.classList.add('main-nav');

  function makeSubmenu(triggerLi, stopWords) {
    const submenu = document.createElement('ul');
    submenu.className = 'submenu';

    let cur = triggerLi.nextElementSibling;
    while (cur && cur.tagName === 'LI') {
      const txt = (cur.textContent || '').trim().toUpperCase();
      if (stopWords.has(txt)) break;

      const next = cur.nextElementSibling;
      submenu.appendChild(cur);
      cur = next;
    }

    if (submenu.children.length) triggerLi.appendChild(submenu);
  }

  const STOPS_AFTER_SERVICE   = new Set(['APPOINTMENT','EVENTS','GALLERY','MINISTRIES','JOIN US','LIVE MASS']);
  const STOPS_AFTER_MINISTRY  = new Set(['JOIN US','LIVE MASS']);

  const lis = Array.from(ul.children).filter(el => el.tagName === 'LI');
  const byText = t => lis.find(li => (li.textContent || '').trim().toUpperCase() === t);

  const liService   = byText('SERVICE');
  const liMinistries= byText('MINISTRIES');

  if (liService)    makeSubmenu(liService, STOPS_AFTER_SERVICE);
  if (liMinistries) makeSubmenu(liMinistries, STOPS_AFTER_MINISTRY);
})();
</script>

</body>
</html>
