<?php
session_start();
require 'database.php';

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$user = null;
if ($userId > 0) {
    $stmt = mysqli_prepare($dbc, 'SELECT id, username, role FROM users WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}
$dashboard = [
    'customer' => 'customer_dashboard.php',
    'staff' => 'staff_dashboard.php',
    'admin' => 'admin_dashboard.php'
];

// Catalogue status is different from availability for specific rental dates.
$cars = mysqli_query($dbc,
    "SELECT c.id, c.brand, c.model, c.production_year, c.registration_number,
            c.price_per_day, c.status,
            (SELECT ci.image_path
             FROM car_images ci
             WHERE ci.car_id = c.id
             ORDER BY ci.is_primary DESC, ci.sort_order ASC, ci.id ASC
             LIMIT 1) AS photo_path
     FROM cars c
     ORDER BY CASE WHEN c.status = 'available' THEN 0 ELSE 1 END,
              c.brand, c.model, c.id");
if (!$cars) {
    http_response_code(500);
    exit('Unable to load the car catalogue.');
}
$vehicleList = mysqli_fetch_all($cars, MYSQLI_ASSOC);
$availableCount = count(array_filter($vehicleList, fn($car) => $car['status'] === 'available'));

// Public fleet search and filters. These operate on the catalogue only;
// availability for specific rental dates is still checked by rental staff.
$searchQuery = trim((string)($_GET['q'] ?? ''));
$searchQuery = substr($searchQuery, 0, 80);
$statusFilter = (string)($_GET['status'] ?? 'all');
$allowedStatuses = ['all', 'available', 'maintenance', 'inactive'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}
$maxPriceInput = trim((string)($_GET['max_price'] ?? ''));
$maxPrice = null;
$priceFilterError = '';
if ($maxPriceInput !== '') {
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $maxPriceInput) || (float)$maxPriceInput > 99999999.99) {
        $priceFilterError = 'Enter a valid maximum daily price (for example 50 or 50.00).';
    } else {
        $maxPrice = (float)$maxPriceInput;
    }
}
$sortFilter = (string)($_GET['sort'] ?? 'default');
$allowedSorts = ['default', 'price_asc', 'price_desc', 'year_desc'];
if (!in_array($sortFilter, $allowedSorts, true)) {
    $sortFilter = 'default';
}

$filteredCars = array_values(array_filter($vehicleList, static function ($car) use ($searchQuery, $statusFilter, $maxPrice) {
    $searchableText = $car['brand'] . ' ' . $car['model'] . ' ' . $car['registration_number'];
    if ($searchQuery !== '' && stripos($searchableText, $searchQuery) === false) {
        return false;
    }
    if ($statusFilter !== 'all' && $car['status'] !== $statusFilter) {
        return false;
    }
    if ($maxPrice !== null && (float)$car['price_per_day'] > $maxPrice) {
        return false;
    }
    return true;
}));

if ($sortFilter === 'price_asc') {
    usort($filteredCars, static fn($a, $b) => (float)$a['price_per_day'] <=> (float)$b['price_per_day']);
} elseif ($sortFilter === 'price_desc') {
    usort($filteredCars, static fn($a, $b) => (float)$b['price_per_day'] <=> (float)$a['price_per_day']);
} elseif ($sortFilter === 'year_desc') {
    usort($filteredCars, static fn($a, $b) => (int)$b['production_year'] <=> (int)$a['production_year']);
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Explore our car rental fleet, compare prices and request a rental online.">
<title>DriveLane | Find Your Next Drive</title>
<style>
:root { --ink:#162337; --muted:#657285; --navy:#102039; --brand:#1769e0; --soft:#f5f7fa; --line:#e1e7ef; --green:#16734b; }
* {box-sizing:border-box}
html {scroll-behavior:smooth}
body {margin:0; font-family:Inter,Segoe UI,Arial,sans-serif; color:var(--ink); background:#fff}
a {color:inherit; text-decoration:none}
.container {width:min(1180px,calc(100% - 40px));margin:auto}
.site-header {background:#fff; border-bottom:1px solid var(--line);position:sticky;top:0;z-index:10}
.navbar {min-height:78px;display:flex;align-items:center;justify-content:space-between;gap:20px}
.logo {font-size:25px;letter-spacing:-1px;font-weight:800;color:#142841;white-space:nowrap}
.logo span {color:var(--brand)}
.navlinks {display:flex;align-items:center;gap:22px;flex-wrap:wrap}
.navlinks>a:not(.btn) {font-size:14px;font-weight:600;color:#3d4b5d}
.btn {display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 18px;border-radius:10px;font-size:14px;font-weight:750;transition:transform .15s,background .15s;text-align:center}
.btn:hover {transform:translateY(-2px)}
.btn-primary {background:var(--brand);color:#fff}
.btn-primary:hover {background:#105ac6}
.btn-ghost {background:#eef4fd;color:#1757a6}
.btn-outline {background:#fff;color:var(--brand);border:1px solid #c7d9ef}
.btn-muted {background:#e8ebf0;color:#707b8a;cursor:not-allowed}
.hero {background:linear-gradient(110deg,rgba(12,30,54,.96),rgba(18,51,88,.91)),radial-gradient(ellipse at 85% 30%,#3c87b4,#162b47);color:#fff;overflow:hidden}
.hero-inner {padding:86px 0 100px;display:grid;grid-template-columns:1.2fr .8fr;align-items:center;gap:38px}
.eyebrow {font-size:12px;letter-spacing:2px;font-weight:800;text-transform:uppercase;color:#90c0ff}
.hero h1 {font-size:clamp(40px,5vw,67px);letter-spacing:-2.5px;line-height:1.08;margin:17px 0 20px;max-width:710px}
.hero p {font-size:17px;line-height:1.7;color:#d3dfec;max-width:560px;margin:0 0 30px}
.hero-actions {display:flex;gap:12px;flex-wrap:wrap}
.hero .btn-outline {background:transparent;color:#fff;border-color:#69819a}
.hero-visual {position:relative;min-height:300px;border:1px solid #6485aa55;border-radius:28px;background:linear-gradient(140deg,#ffffff13,#ffffff04);padding:35px;display:flex;align-items:center;justify-content:center;overflow:hidden}
.hero-visual svg {width:100%;max-width:440px;filter:drop-shadow(0 26px 22px #08192b90)}
.hero-label {position:absolute;bottom:20px;left:24px;font-size:13px;color:#dbe9fa}
.highlights {margin-top:-35px;position:relative;z-index:1;display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.highlight {padding:22px 24px;border:1px solid var(--line);background:white;box-shadow:0 15px 40px #1b314412;border-radius:16px;display:flex;align-items:center;gap:15px}
.highlight-icon {width:42px;height:42px;border-radius:12px;background:#eaf2ff;color:#1769e0;display:grid;place-items:center;font-size:24px;flex-shrink:0}
.highlight strong {display:block;font-size:15px;margin-bottom:4px}
.highlight small {color:var(--muted);font-size:12px;line-height:1.5}
.section {padding:75px 0}
.section-head {display:flex;align-items:end;justify-content:space-between;gap:20px;margin-bottom:28px}
.section h2 {font-size:clamp(27px,3vw,38px);letter-spacing:-1.2px;margin:8px 0}
.section-sub {margin:0;color:var(--muted);line-height:1.6}
.pill {display:inline-flex;padding:7px 11px;border-radius:30px;background:#e9f1fc;color:#165fbf;font-size:12px;font-weight:750}
/* Search controls use the same DriveLane visual language. */
.fleet-filter-form {display:grid;grid-template-columns:minmax(200px,2fr) minmax(150px,1fr) minmax(155px,1fr) minmax(150px,1fr) auto;gap:12px;align-items:end;padding:20px;margin:0 0 22px;border:1px solid var(--line);border-radius:16px;background:#f8fafd}
.fleet-field {min-width:0}
.fleet-field label {display:block;font-weight:750;font-size:12px;color:#42546b;margin-bottom:7px}
.fleet-field input,.fleet-field select {width:100%;height:44px;padding:9px 11px;border:1px solid #cad6e5;border-radius:9px;background:#fff;color:var(--ink);font:inherit;font-size:14px}
.fleet-field input:focus,.fleet-field select:focus {outline:2px solid #83b7f8;outline-offset:1px}
.fleet-filter-actions {display:flex;gap:8px;align-items:center;white-space:nowrap}
.fleet-filter-actions .btn {height:44px;border:0;cursor:pointer}
.fleet-filter-actions .btn-outline {border:1px solid #c7d9ef}
.fleet-filter-info {font-size:13px;color:var(--muted);margin:0 0 18px}
.fleet-error {background:#fff0f0;color:#a01b25;border:1px solid #ffd0d0;padding:11px 14px;border-radius:10px;margin-bottom:12px;font-size:14px}
@media(max-width:1080px) {.fleet-filter-form{grid-template-columns:repeat(2,minmax(0,1fr))}.fleet-filter-actions{grid-column:1/-1}}
@media(max-width:580px) {.fleet-filter-form{grid-template-columns:1fr}.fleet-filter-actions{grid-column:auto;flex-wrap:wrap}}
/* Horizontal fleet carousel: same cards and database data. */
.cars-grid {display:grid;grid-auto-flow:column;grid-auto-columns:calc((100% - 44px)/3);gap:22px;overflow-x:auto;overflow-y:hidden;scroll-snap-type:x mandatory;scroll-behavior:smooth;overscroll-behavior-inline:contain;padding:6px 3px 24px;scrollbar-width:thin;scrollbar-color:#aebdd0 #edf2f8}
.cars-grid::-webkit-scrollbar {height:9px}
.cars-grid::-webkit-scrollbar-thumb {background:#aebdd0;border-radius:20px}
.cars-grid::-webkit-scrollbar-track {background:#edf2f8;border-radius:20px}
.cars-grid .car-card {min-width:0;scroll-snap-align:start}
.fleet-controls {display:flex;align-items:center;gap:10px;margin:16px 0 0}
.fleet-arrow {display:inline-flex;align-items:center;justify-content:center;width:43px;height:43px;border:1px solid #c9d8ea;border-radius:50%;background:#fff;color:#1557b3;cursor:pointer;font-size:22px;font-weight:700}
.fleet-arrow:hover:not(:disabled) {background:#eef4fd}
.fleet-arrow:disabled {opacity:.38;cursor:default}
.fleet-help {color:var(--muted);font-size:13px}
.car-card {border:1px solid var(--line);border-radius:18px;overflow:hidden;background:#fff;box-shadow:0 8px 24px #15243709;transition:transform .15s,box-shadow .15s}
.car-card:hover {transform:translateY(-3px);box-shadow:0 16px 34px #15243714}
.car-photo {height:215px;background:linear-gradient(145deg,#eaf1f8,#dae3ed);position:relative;overflow:hidden;display:grid;place-items:center}
.car-photo img {width:100%;height:100%;object-fit:cover}
.car-photo .placeholder {font-size:63px;color:#8798aa}
.status {position:absolute;top:15px;left:15px;background:#e6f6ed;color:#17673e;font-size:12px;font-weight:800;padding:8px 11px;border-radius:30px}
.status.unavailable {background:#fff2df;color:#985b09}
.car-body {padding:23px}
.car-body h3 {font-size:22px;letter-spacing:-.5px;margin:0 0 6px}
.car-spec {font-size:13px;color:var(--muted);margin-bottom:19px}
.car-foot {border-top:1px solid var(--line);padding-top:17px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.price strong {font-size:25px;letter-spacing:-.6px}
.price small {color:var(--muted);font-size:12px}
.empty {padding:45px;text-align:center;background:#f7f9fc;border:1px dashed #c9d5e3;border-radius:18px;color:#526279}
.how {background:var(--soft)}
.steps {display:grid;grid-template-columns:repeat(3,1fr);gap:22px;margin-top:28px}
.step {background:#fff;padding:28px;border-radius:16px;border:1px solid var(--line)}
.step-number {font-size:12px;color:var(--brand);font-weight:900;letter-spacing:1px}
.step h3 {font-size:19px;margin:14px 0 10px}
.step p {font-size:14px;line-height:1.7;color:var(--muted);margin:0}
.cta {background:#112744;color:white;border-radius:24px;padding:40px;display:flex;align-items:center;justify-content:space-between;gap:24px}
.cta h2 {font-size:29px;margin:0 0 9px}
.cta p {color:#d3e0ed;margin:0;line-height:1.5}
footer {background:#0e1e33;color:#d1ddeb;padding:26px 0;font-size:13px}
.footer-inner {display:flex;justify-content:space-between;gap:18px;flex-wrap:wrap}
footer a {color:#fff}
@media(max-width:940px) {.cars-grid{grid-auto-columns:calc((100% - 22px)/2)}.hero-inner{grid-template-columns:1fr}.hero-visual{min-height:240px}.navlinks{gap:12px}}
@media(max-width:640px) {.container{width:min(100% - 28px,1180px)}.navbar{padding:15px 0;align-items:flex-start;flex-direction:column}.hero-inner{padding:53px 0 83px}.hero h1{letter-spacing:-1.3px}.hero-visual{display:none}.highlights,.steps{grid-template-columns:1fr}.cars-grid{grid-auto-columns:minmax(0,88%)}.section{padding:50px 0}.section-head{align-items:flex-start;flex-direction:column}.cta{flex-direction:column;align-items:start;padding:28px}}
</style>
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>

<main>
<section class="hero">
 <div class="container hero-inner">
  <div>
   <div class="eyebrow">Easy car rental, every day</div>
   <h1>Your next journey starts here.</h1>
   <p>Browse our rental fleet, compare daily prices and send a booking request in a few simple steps. Find a car that fits your plans.</p>
   <div class="hero-actions">
    <a href="#fleet" class="btn btn-primary">Explore Available Cars →</a>
    <?php if (!$user): ?><a href="register.php" class="btn btn-outline">Join DriveLane</a><?php endif; ?>
   </div>
  </div>
  <div class="hero-visual" aria-hidden="true">
   <svg viewBox="0 0 500 255" xmlns="http://www.w3.org/2000/svg" fill="none">
    <ellipse cx="256" cy="210" rx="210" ry="26" fill="#061527" opacity=".65"/>
    <path d="M51 159L84 145L130 81Q141 62 169 62H319Q351 62 369 91L406 139L450 151Q470 157 475 178L478 194Q475 205 455 205H68Q44 205 42 188L45 173Z" fill="#f2f7fc"/>
    <path d="M149 86Q155 73 173 73H314Q336 73 350 94L378 133H111Z" fill="#294b6c"/>
    <path d="M253 74V135" stroke="#d8e7f5" strokeWidth="7"/>
    <path d="M72 151H441" stroke="#cbd9e6" strokeWidth="5"/>
    <path d="M57 174H106" stroke="#8ab5e6" strokeWidth="12" strokeLinecap="round"/>
    <path d="M420 169H459" stroke="#f8d7a3" strokeWidth="10" strokeLinecap="round"/>
    <circle cx="142" cy="201" r="37" fill="#0d2139"/><circle cx="142" cy="201" r="20" fill="#b7c7d5"/><circle cx="142" cy="201" r="8" fill="#536a7d"/>
    <circle cx="385" cy="201" r="37" fill="#0d2139"/><circle cx="385" cy="201" r="20" fill="#b7c7d5"/><circle cx="385" cy="201" r="8" fill="#536a7d"/>
    <path d="M104 141H402" stroke="#9caec0" strokeWidth="2"/>
   </svg>
   <span class="hero-label">Browse • Request • Drive</span>
  </div>
 </div>
</section>
<div class="container highlights">
 <div class="highlight"><span class="highlight-icon">✓</span><div><strong>Simple booking requests</strong><small>Request your dates online</small></div></div>
 <div class="highlight"><span class="highlight-icon">€</span><div><strong>Clear daily prices</strong><small>Know the rate before requesting</small></div></div>
 <div class="highlight"><span class="highlight-icon">↗</span><div><strong>Track your booking</strong><small>See updates in your account</small></div></div>
</div>
<section class="section" id="fleet">
 <div class="container">
  <div class="section-head">
   <div><span class="pill">OUR FLEET</span><h2>Find your perfect ride</h2><p class="section-sub">Explore <?= count($vehicleList) ?> cars in our catalogue — <?= $availableCount ?> currently listed as available.</p></div>
   <?php if ($user && $user['role'] === 'customer'): ?><a class="btn btn-outline" href="my_bookings.php">View My Bookings</a><?php endif; ?>
  </div>
  <form class="fleet-filter-form" method="get" action="index.php#fleet" aria-label="Search and filter rental cars">
   <div class="fleet-field">
    <label for="fleetSearch">Search cars</label>
    <input id="fleetSearch" type="search" name="q" maxlength="80" value="<?= e($searchQuery) ?>" placeholder="Brand, model or registration">
   </div>
   <div class="fleet-field">
    <label for="fleetStatus">Availability status</label>
    <select id="fleetStatus" name="status">
     <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses</option>
     <option value="available" <?= $statusFilter === 'available' ? 'selected' : '' ?>>Available</option>
     <option value="maintenance" <?= $statusFilter === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
     <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
   </div>
   <div class="fleet-field">
    <label for="fleetMaxPrice">Max price per day (€)</label>
    <input id="fleetMaxPrice" type="number" name="max_price" min="0" max="99999999.99" step="0.01" value="<?= e($maxPriceInput) ?>" placeholder="Any price">
   </div>
   <div class="fleet-field">
    <label for="fleetSort">Sort by</label>
    <select id="fleetSort" name="sort">
     <option value="default" <?= $sortFilter === 'default' ? 'selected' : '' ?>>Recommended</option>
     <option value="price_asc" <?= $sortFilter === 'price_asc' ? 'selected' : '' ?>>Price: low to high</option>
     <option value="price_desc" <?= $sortFilter === 'price_desc' ? 'selected' : '' ?>>Price: high to low</option>
     <option value="year_desc" <?= $sortFilter === 'year_desc' ? 'selected' : '' ?>>Newest model first</option>
    </select>
   </div>
   <div class="fleet-filter-actions">
    <button type="submit" class="btn btn-primary">Apply</button>
    <a class="btn btn-outline" href="index.php#fleet">Reset</a>
   </div>
  </form>
  <?php if ($priceFilterError !== ''): ?><div class="fleet-error" role="alert"><?= e($priceFilterError) ?></div><?php endif; ?>
  <p class="fleet-filter-info" role="status">Showing <strong><?= count($filteredCars) ?></strong> of <strong><?= count($vehicleList) ?></strong> cars. Choose a filter and click Apply.</p>
  <?php if (!$vehicleList): ?>
   <div class="empty">We're preparing our fleet. Please check back soon.</div>
  <?php elseif (!$filteredCars): ?>
   <div class="empty">No vehicles match your filters. <a href="index.php#fleet" style="color:#1769e0;text-decoration:underline">Clear filters to see all cars</a>.</div>
  <?php else: ?>
   <div class="cars-grid" id="fleetCarousel" aria-label="Cars matching your search" tabindex="0">
    <?php foreach ($filteredCars as $car): ?>
     <?php $isAvailable = $car['status'] === 'available'; $photo = $car['photo_path']; ?>
     <article class="car-card">
      <div class="car-photo">
       <?php if ($photo): ?>
        <img src="<?= e($photo) ?>" alt="Uploaded photo of <?= e($car['brand'] . ' ' . $car['model']) ?>" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
        <span class="placeholder" style="display:none" aria-hidden="true">🚘</span>
       <?php else: ?><span class="placeholder" aria-hidden="true">🚘</span><?php endif; ?>
       <span class="status <?= $isAvailable ? '' : 'unavailable' ?>"><?= e(ucfirst($car['status'])) ?></span>
      </div>
      <div class="car-body">
       <h3><?= e($car['brand']) ?> <?= e($car['model']) ?></h3>
       <div class="car-spec"><?= (int)$car['production_year'] ?> model · <?= e($car['registration_number']) ?></div>
       <p style="margin:0 0 16px"><a class="btn btn-ghost" href="car_details.php?id=<?= (int)$car['id'] ?>">View Details &rarr;</a></p>
       <div class="car-foot">
        <div class="price"><strong>€<?= number_format((float)$car['price_per_day'], 2) ?></strong><small> / day</small></div>
        <?php if (!$isAvailable): ?>
         <span class="btn btn-muted" aria-disabled="true">Unavailable</span>
        <?php elseif (!$user): ?>
         <a class="btn btn-primary" href="login.php">Login to Book →</a>
        <?php elseif ($user['role'] === 'customer'): ?>
         <a class="btn btn-primary" href="book_car.php?id=<?= (int)$car['id'] ?>">Request Rental →</a>
        <?php else: ?>
         <a class="btn btn-ghost" href="<?= e($dashboard[$user['role']]) ?>">Dashboard</a>
        <?php endif; ?>
       </div>
      </div>
     </article>
    <?php endforeach; ?>
   </div>
   <?php if (count($filteredCars) > 1): ?>
   <div class="fleet-controls" aria-label="Fleet carousel controls">
    <button class="fleet-arrow" id="fleetPrevious" type="button" aria-label="Scroll to previous car">&#8592;</button>
    <button class="fleet-arrow" id="fleetNext" type="button" aria-label="Scroll to next car">&#8594;</button>
    <span class="fleet-help">Use arrows or scroll sideways to explore cars.</span>
   </div>
   <?php endif; ?>
  <?php endif; ?>
  <p style="margin-top:20px;color:#6b7888;font-size:12px">Car photos are uploaded by rental staff. Catalogue availability does not guarantee your selected rental dates; staff confirms requests.</p>
 </div>
</section>
<section class="section how" id="how-it-works">
 <div class="container"><span class="pill">HOW IT WORKS</span><h2>Three steps to your next drive</h2>
  <div class="steps">
   <div class="step"><span class="step-number">STEP 01</span><h3>Choose your car</h3><p>Explore cars, view daily rates and find a model that suits your trip.</p></div>
   <div class="step"><span class="step-number">STEP 02</span><h3>Request your dates</h3><p>Create an account, choose your rental period and submit a booking request.</p></div>
   <div class="step"><span class="step-number">STEP 03</span><h3>Get confirmation</h3><p>Rental staff review availability and approve or reject the request. Track the status in your dashboard.</p></div>
  </div>
 </div>
</section>
<section class="section"><div class="container"><div class="cta">
 <div><h2>Ready to get moving?</h2><p>Explore the fleet and make your first rental request.</p></div>
 <a class="btn btn-primary" href="#fleet">Explore Cars →</a>
</div></div></section>
</main>
<script>
(function () {
  const carousel = document.getElementById('fleetCarousel');
  const previous = document.getElementById('fleetPrevious');
  const next = document.getElementById('fleetNext');
  if (!carousel || !previous || !next) return;

  function step() {
    const card = carousel.querySelector('.car-card');
    if (!card) return carousel.clientWidth;
    const gap = parseFloat(getComputedStyle(carousel).columnGap) || 0;
    return card.getBoundingClientRect().width + gap;
  }
  function updateButtons() {
    const last = carousel.scrollWidth - carousel.clientWidth;
    previous.disabled = carousel.scrollLeft <= 2;
    next.disabled = carousel.scrollLeft >= last - 2;
  }
  previous.addEventListener('click', () => carousel.scrollBy({left: -step(), behavior: 'smooth'}));
  next.addEventListener('click', () => carousel.scrollBy({left: step(), behavior: 'smooth'}));
  carousel.addEventListener('scroll', updateButtons, {passive: true});
  window.addEventListener('resize', updateButtons);
  updateButtons();
})();
</script>
<footer><div class="container footer-inner"><div><strong>DriveLane.</strong> &nbsp;Car Rental Management System · Project 2</div><div><a href="#fleet">Our Fleet</a> &nbsp;·&nbsp; <a href="#how-it-works">How It Works</a></div></div></footer>
</body>
</html>
<?php mysqli_close($dbc); ?>
