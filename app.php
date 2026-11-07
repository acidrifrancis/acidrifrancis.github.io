<?php $user=current_user(); ?>
<!doctype html>
<html lang="en"><head>
<?php $route = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: 'home'; ?>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=htmlspecialchars(APP_NAME)?></title>
<meta name="description" content="Discover local products, services and businesses. Connect, discover, trade and grow across Northern Uganda.">
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/styles.css?v=marketlayout3">
</head><body id="top" data-page="<?=htmlspecialchars($route, ENT_QUOTES)?>">
<header class="topbar"><a class="brand" href="/"><span class="brand-mark">N</span><span>NOTHERN UGANDA <b>MARKET CONNECT</b></span></a>
<button class="mobile-menu" id="mobileMenu">☰</button><nav id="nav"><a href="/marketplace">Marketplace</a><a href="/how-it-works">How it works</a><a href="/categories">Categories</a>
<?php if($user): ?><button class="btn btn-light" id="dashboardBtn">My dashboard</button><button class="btn btn-outline" id="logoutBtn">Sign out</button>
<?php else: ?><button class="btn btn-light" data-open="login">Log in</button><button class="btn btn-primary" data-open="register">Create account</button><?php endif; ?></nav></header>
<main>
<section class="hero hero-compact"><div class="hero-copy"><span class="eyebrow"><i></i> NORTHERN UGANDA'S BUSINESS CONNECTION</span><h1>Connect. Discover. <em>Trade. Grow.</em></h1><p>Find products, services and businesses near you. A better way to connect local sellers with customers.</p></div><div class="hero-edge"><div class="edge-note"><b>✦ Local opportunities</b><small>All in one place</small></div><div class="edge-categories"><span>🌽 Fresh produce</span><span>📱 Electronics</span><span>🏡 Property</span><span>🛠️ Services</span></div><div class="edge-note edge-grow"><b>↗ Grow your reach</b><small>Connect with buyers</small></div></div></section>
<section class="search-wrap search-wrap-top"><div class="search-box"><span>⌕</span><input id="searchInput" placeholder="What are you looking for today?"><select id="categoryFilter"><option value="">All categories</option></select><button class="btn btn-primary" id="searchBtn">Search</button></div><div class="quick-search"><span>Popular:</span><button data-search="land">Land</button><button data-search="maize">Maize</button><button data-search="phones">Phones</button><button data-search="motorcycle">Motorcycles</button></div></section>
<section class="section market-section" id="market"><div class="market-main"><div class="section-heading"><div><span class="eyebrow">DISCOVER SOMETHING GREAT</span><h2>Explore the marketplace</h2><p>Find what you need from businesses and people around you.</p></div><span class="live-pill"><i></i> Fresh listings</span></div><div id="listingGrid" class="listing-grid"><div class="loading">Loading marketplace…</div></div><div class="center"><button class="btn btn-outline" id="refreshListings">Refresh listings ↗</button></div></div><aside class="promotion-rail" aria-label="Sponsored promotions"><div class="promotion-rail-head"><span>FEATURED</span><small>Sponsored</small></div><div id="promotionStage" class="promotion-stage"><div class="promotion-placeholder"><b>Local businesses</b><span>Featured offers will appear here.</span></div></div><div class="promotion-progress"><span id="promotionProgress"></span></div><small class="promotion-rotation">Offers rotate every 20 seconds</small></aside></section>
<section class="category-section" id="categories"><div class="section-heading"><div><span class="eyebrow">BROWSE BY INTEREST</span><h2>Popular categories</h2><p>Whatever you're looking for, start here.</p></div></div><div class="category-grid" id="categoryGrid"></div></section>
<section class="how-section" id="how"><div class="how-intro"><span class="eyebrow">SIMPLE BY DESIGN</span><h2>Your next connection<br>is just <em>three steps away.</em></h2><p>Whether you're selling or searching, getting started is easy.</p><button class="btn btn-primary" id="howPost">Start selling today ↗</button></div><div class="steps"><article><span>01</span><div class="step-icon">👤</div><h3>Create your account</h3><p>Join our growing community of local businesses, traders and customers.</p></article><article><span>02</span><div class="step-icon">📣</div><h3>Post your advert</h3><p>Choose your advert duration, upload photos and pay securely through Pesapal.</p></article><article><span>03</span><div class="step-icon">🤝</div><h3>Connect and grow</h3><p>Customers discover your advert and message you directly to do business.</p></article></div></section>
<section class="cta"><div><span class="eyebrow">YOUR BUSINESS DESERVES TO BE SEEN</span><h2>Make your next customer<br><em>closer than you think.</em></h2><p>Put your products and services in front of people ready to connect.</p></div><button class="btn btn-white btn-large" id="ctaPost">Post your first advert ↗</button></section>
</main>
<footer class="site-footer">
  <div class="footer-main">
    <div class="footer-brand-column">
      <a class="brand footer-brand" href="/"><span class="brand-mark">N</span><span>NORTHERN UGANDA <b>MARKET CONNECT</b></span></a>
      <p class="footer-description">A trusted place to discover local products, services and businesses. Bringing Northern Uganda's buyers and sellers closer together.</p>
      <span class="footer-location"><span aria-hidden="true">✦</span> Connecting communities. Growing businesses.</span>
    </div>
    <div class="footer-link-column">
      <h3>Explore</h3>
      <a href="/marketplace">Marketplace</a>
      <a href="/categories">Browse categories</a>
      <a href="/how-it-works">How it works</a>
      <a href="/policies/privacy.html">Privacy policy</a>
      <a href="/policies/terms.html">Terms &amp; conditions</a>
      <a href="/policies/buyers.html">Buyer policy</a>
      <a href="/policies/sellers.html">Seller policy</a>
      <a href="/policies/refunds.html">Refunds &amp; cancellations</a>
    </div>
    <div class="footer-link-column">
      <h3>Get started</h3>
      <a href="/marketplace">Discover products</a>
      <a href="#how">How to sell</a>
      <?php if($user): ?><a href="#" id="footerDashboard">My dashboard</a><?php else: ?><a href="#" data-open="register">Create an account</a><a href="#" data-open="login">Log in</a><?php endif; ?>
    </div>
    <div class="footer-cta-column">
      <span class="footer-kicker">LOCAL CONNECTIONS, REAL OPPORTUNITIES</span>
      <h3>Ready to trade and grow?</h3>
      <p>Discover what's nearby or bring your business to more customers.</p>
      <a class="footer-cta-link" href="/marketplace">Explore marketplace <span aria-hidden="true">↗</span></a>
    </div>
  </div>
  <div class="footer-bottom">
    <small>© <?=date('Y')?> Northern Uganda Market Connect. All rights reserved.</small>
    <span>Built to connect local trade across Northern Uganda.</span>
    <a href="https://pinnacore-company-limited.onrender.com/" target="_blank" rel="noopener noreferrer" class="footer-company-link">pinnacore-company-limited</a>
    <a href="#top" class="footer-top">Back to top ↑</a>
  </div>
</footer>
<div class="modal-backdrop" id="modal"><div class="modal"><button class="modal-close" id="modalClose">×</button><div id="modalContent"></div></div></div>
<div class="toast" id="toast"></div>
<script>window.APP_USER=<?=json_encode($user?['id'=>$user['id'],'name'=>$user['full_name'],'role'=>$user['role']]:null)?>;window.APP_CSRF=<?=json_encode($_SESSION['csrf_token'])?>;</script><script src="/assets/app.js?v=admin-support-owner-centre-fix2-20261106"></script>
</body></html>
