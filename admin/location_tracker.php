<?php
// ============================================================
// DATABASE CONNECTION
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/helpers/location_helper.php';

$pdo = $GLOBALS['pdo'] ?? null;

if (!$pdo) {
    error_log('location_tracker.php: Database connection failed');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed. Please check your configuration.'
        ]);
        exit;
    }
    die('Database connection error. Please contact administrator.');
}

$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');
$ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
$action = isset($_POST['action']) ? $_POST['action'] : '';

// ============================================================
// RENTER GPS POST - NO ADMIN AUTH REQUIRED
// ============================================================
if ($isPost && $action !== 'mark_read' && !$ajax) {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Not logged in. Please log in first.'
        ]);
        exit;
    }
    
    $user_id = (int) $_SESSION['user_id'];
    
    $latitude_raw = isset($_POST['latitude']) ? $_POST['latitude'] : null;
    $longitude_raw = isset($_POST['longitude']) ? $_POST['longitude'] : null;
    
    if ($latitude_raw === null || $longitude_raw === null) {
        echo json_encode([
            'success' => false,
            'message' => 'Missing latitude or longitude'
        ]);
        exit;
    }
    
    if (!is_numeric($latitude_raw) || !is_numeric($longitude_raw)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid coordinates: must be numeric values'
        ]);
        exit;
    }
    
    $latitude = (float) $latitude_raw;
    $longitude = (float) $longitude_raw;
    $accuracy = isset($_POST['accuracy']) ? (float) $_POST['accuracy'] : 0;
    $booking_id = isset($_POST['booking_id']) ? (int) $_POST['booking_id'] : null;
    $recorded_at = date('Y-m-d H:i:s');
    
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid coordinates'
        ]);
        exit;
    }
    
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'location_tracker'");
        if ($stmt->rowCount() == 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Location tracker table not found. Please run database setup.'
            ]);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE id = ? AND is_deleted = 0");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            echo json_encode([
                'success' => false,
                'message' => 'User not found or account is deleted.'
            ]);
            exit;
        }
        
        if ($booking_id !== null) {
            $activeBooking = verifyActiveBooking($pdo, $user_id, $booking_id);
            if (!$activeBooking) {
                echo json_encode([
                    'success' => false,
                    'message' => 'This booking is no longer active. GPS tracking stopped.',
                    'stop_tracking' => true
                ]);
                exit;
            }
        }
        
        if ($booking_id !== null) {
            $stmt = $pdo->prepare("
                INSERT INTO location_tracker (user_id, booking_id, latitude, longitude, accuracy, recorded_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$user_id, $booking_id, $latitude, $longitude, $accuracy, $recorded_at]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO location_tracker (user_id, latitude, longitude, accuracy, recorded_at)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$user_id, $latitude, $longitude, $accuracy, $recorded_at]);
        }
        
        $insertId = $pdo->lastInsertId();
        error_log("GPS Location saved: ID=$insertId, User=$user_id, Booking=$booking_id");
        
        echo json_encode([
            'success' => true,
            'message' => 'GPS location saved successfully!',
            'user_id' => $user_id,
            'user_name' => $user['full_name'],
            'booking_id' => $booking_id,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'recorded_at' => $recorded_at,
            'insert_id' => $insertId
        ]);
        
    } catch (PDOException $e) {
        error_log('location_tracker.php PDO Error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        error_log('location_tracker.php Error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
    exit;
}

// ============================================================
// ADMIN REQUESTS - LOAD ADMIN AUTH
// ============================================================
require_once __DIR__ . '/admin_auth.php';

// ============================================================
// POST: Mark notification as read (Admin)
// ============================================================
if ($isPost && $action === 'mark_read') {
    header('Content-Type: application/json');
    try {
        $id = isset($_POST['notification_id']) ? (int) $_POST['notification_id'] : 0;
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE admin_notifications SET is_read = 1 WHERE id = ?");
            $stmt->execute([$id]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log('location_tracker.php mark_read error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// GET: Admin fetches locations for ALL active bookings
// FIXED: Removed start_date restriction to show future bookings.
// ============================================================
if ($ajax && ($_GET['section'] ?? '') === 'locations') {
    header('Content-Type: application/json');
    
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'location_tracker'");
        if ($stmt->rowCount() == 0) {
            echo json_encode([
                'success' => true,
                'message' => 'No locations found yet',
                'points' => []
            ]);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                u.id AS user_id,
                u.full_name,
                b.id AS booking_id,
                b.status AS booking_status,
                b.start_date,
                b.end_date,
                lt.id AS location_id,
                lt.latitude,
                lt.longitude,
                lt.accuracy,
                lt.recorded_at
            FROM users u
            INNER JOIN bookings b ON b.renter_id = u.id
            LEFT JOIN location_tracker lt
                ON lt.id = (
                    SELECT lt2.id
                    FROM location_tracker lt2
                    WHERE lt2.user_id = u.id
                    AND lt2.booking_id = b.id
                    ORDER BY lt2.recorded_at DESC, lt2.id DESC
                    LIMIT 1
                )
            WHERE u.role = 'renter'
            AND u.is_deleted = 0
            AND b.status IN ('pending_location', 'pending', 'approved')
            AND b.end_date >= CURDATE()        -- Only bookings that haven't ended
            ORDER BY u.full_name ASC
        ");
        $stmt->execute();
        $renters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $points = [];
        foreach ($renters as $renter) {
            if ($renter['latitude'] !== null && $renter['longitude'] !== null) {
                $isRecent = false;
                if ($renter['recorded_at']) {
                    $lastUpdate = strtotime($renter['recorded_at']);
                    $secondsAgo = time() - $lastUpdate;
                    $isRecent = ($secondsAgo <= LOCATION_ACTIVE_THRESHOLD_SECONDS);
                }
                
                $points[] = [
                    'user_id' => $renter['user_id'],
                    'booking_id' => $renter['booking_id'],
                    'full_name' => $renter['full_name'] ?? 'Unknown Renter',
                    'latitude' => (float) $renter['latitude'],
                    'longitude' => (float) $renter['longitude'],
                    'accuracy' => (float) ($renter['accuracy'] ?? 0),
                    'recorded_at' => $renter['recorded_at'],
                    'is_recent' => $isRecent,
                    'status' => $isRecent ? 'active' : 'inactive',
                ];
            }
        }
        
        echo json_encode([
            'success' => true,
            'points' => $points,
            'count' => count($points),
            'message' => count($points) . ' active renter(s) found',
            'server_time' => date('Y-m-d H:i:s'),
            'threshold_seconds' => LOCATION_ACTIVE_THRESHOLD_SECONDS
        ]);
        
    } catch (Exception $e) {
        error_log('location_tracker.php GET locations error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Unable to load locations',
            'points' => []
        ]);
    }
    exit;
}

// ============================================================
// GET: Debug endpoint
// ============================================================
if ($ajax && ($_GET['section'] ?? '') === 'debug') {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'location_tracker'");
        $tableExists = $stmt->rowCount() > 0;
        
        $points = [];
        if ($tableExists) {
            $stmt = $pdo->query("
                SELECT 
                    lt.id,
                    lt.user_id,
                    lt.booking_id,
                    lt.latitude,
                    lt.longitude,
                    lt.accuracy,
                    lt.recorded_at,
                    lt.created_at,
                    u.full_name,
                    u.is_deleted
                FROM location_tracker lt
                LEFT JOIN users u ON lt.user_id = u.id
                ORDER BY lt.created_at DESC
                LIMIT 20
            ");
            $points = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $stmt = $pdo->query("SELECT NOW() AS server_time");
        $serverInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->query("SELECT COUNT(*) as user_count FROM users WHERE is_deleted = 0");
        $userCount = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'table_exists' => $tableExists,
            'server_time' => $serverInfo['server_time'] ?? 'unknown',
            'total_users' => (int) ($userCount['user_count'] ?? 0),
            'total_locations' => count($points),
            'points' => $points
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// ============================================================
// GET: Unread notification count
// ============================================================
if ($ajax && ($_GET['section'] ?? '') === 'notification-count') {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0");
        $count = (int) $stmt->fetchColumn();
        echo json_encode(['success' => true, 'count' => $count]);
    } catch (Exception $e) {
        error_log('location_tracker.php notification-count error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'count' => 0]);
    }
    exit;
}

// ============================================================
// GET: Notification list
// ============================================================
if ($ajax && ($_GET['section'] ?? '') === 'notifications') {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("
            SELECT n.id, n.notification_type, n.title, n.message, n.user_id, n.booking_id, n.is_read, n.created_at,
                   u.full_name as user_name
            FROM admin_notifications n
            LEFT JOIN users u ON n.user_id = u.id
            ORDER BY n.created_at DESC 
            LIMIT 20
        ");
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'notifications' => $notifications]);
    } catch (Exception $e) {
        error_log('location_tracker.php notifications error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'notifications' => []]);
    }
    exit;
}

// ============================================================
// HTML: Admin Map Page (unchanged UI)
// ============================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Renter Location Tracker | Carbnb Admin</title>
  <link rel="stylesheet" href="css/admin_style.css?v=20260702">
  <link rel="stylesheet" href="css/admin_style_backup.css?v=20260702">
  <link rel="stylesheet" href="css/admin_responsive.css?v=20260801">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" 
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" 
        crossorigin=""/>
  <style>
    #tracker-map { 
      height: 540px; 
      width: 100%; 
      border-radius: 12px; 
      border: 1px solid #d7dde7;
      background: #1a1a2e;
    }
    .tracker-panel { margin-top: 1rem; }
    .tracker-badge { 
      display: inline-block; 
      padding: .35rem .6rem; 
      border-radius: 999px; 
      background: #eaf7ee; 
      color: #19723d; 
      font-weight: 600; 
    }
    .tracker-status {
      margin-top: 12px;
      padding: 10px 14px;
      border-radius: 8px;
      background: rgba(255,255,255,0.05);
      border: 1px solid var(--border);
      color: var(--muted);
    }
    .tracker-status .count {
      color: var(--accent);
      font-weight: 700;
    }
    .tracker-status.error {
      background: rgba(248,113,113,0.1);
      border-color: rgba(248,113,113,0.2);
      color: var(--danger);
    }
    .tracker-status.success {
      background: rgba(110,231,183,0.1);
      border-color: rgba(110,231,183,0.2);
      color: var(--success);
    }
    .tracker-error {
      display: none;
      padding: 15px 20px;
      margin-bottom: 15px;
      background: rgba(248,113,113,0.15);
      border: 1px solid rgba(248,113,113,0.3);
      border-radius: 10px;
      color: var(--danger);
    }
    .tracker-error.show {
      display: block;
    }
    .loading-spinner {
      display: inline-block;
      width: 20px;
      height: 20px;
      border: 3px solid var(--border);
      border-radius: 50%;
      border-top-color: var(--accent);
      animation: spin 0.8s ease-in-out infinite;
      margin-right: 8px;
      vertical-align: middle;
    }
    @keyframes spin {
      to { transform: rotate(360deg); }
    }
    
    body.sidebar-open .leaflet-control-zoom,
    body.sidebar-open .leaflet-control-attribution {
      display: none !important;
    }
    
    @media (max-width: 767px) {
      #tracker-map { height: 320px; }
    }
  </style>
</head>
<body>
  <div class="overlay"></div>
  <aside class="sidebar">
    <div class="sidebar-header">
      <h2>Carbnb Admin</h2>
      <button class="sidebar-close" type="button" aria-label="Close sidebar"></button>
    </div>
    <nav class="sidebar-nav">
      <a href="dashboard.php">Dashboard</a>
      <a href="manage_users.php">Verify Users</a>
      <a href="verify_vehicles.php">Verify Vehicles</a>
      <a href="booking_records.php">Rental Records</a>
      <a href="account_control.php">Account Control</a>
      <a href="earnings.php">Earnings & Commission</a>
      <a href="contact_messages.php">Contact Messages</a>
      <a href="delete_user.php">Delete Users</a>
      <a href="trashbin.php">Trash Bin</a>
      <a href="location_tracker.php" class="active">Renter Tracker</a>
      <a href="../auth/logout.php" class="topbar-action" style="display:block; margin-top:1rem; text-align:center;">Logout</a>
    </nav>
  </aside>

  <div class="main-content">
    <header class="topbar">
      <button class="sidebar-toggle" type="button" aria-label="Open sidebar"></button>
      <h1>Renter Location Tracker</h1>
    </header>

    <main class="page">
      <section class="hero-card">
        <div>
          <h2>Live renter movement map</h2>
          <p>
            Showing <strong>last known positions</strong> for all active renters. 
            🟢 = recent (within <?= LOCATION_ACTIVE_THRESHOLD_SECONDS ?>s), 🟡 = stale (last known location).
          </p>
        </div>
        <div>
          <span class="tracker-badge">🔄 Auto-refresh every 10 seconds</span>
        </div>
      </section>

      <div id="trackerError" class="tracker-error">
        <strong>⚠️ Unable to load location data</strong>
        <p id="errorMessage">Please check your connection and try again.</p>
        <button onclick="refreshLocations()" class="action-btn-small approve" style="margin-top:8px;">
          Retry
        </button>
      </div>

      <section class="card">
        <div class="tracker-panel">
          <div id="tracker-map"></div>
          <div id="trackerStatus" class="tracker-status">
            <span class="loading-spinner"></span> Loading latest positions...
          </div>
        </div>
      </section>
    </main>
  </div>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" 
          integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" 
          crossorigin=""></script>
  
  <script>
    // ============================================================
    // CONFIGURATION
    // ============================================================
    const REFRESH_INTERVAL = 10000;
    let map = null;
    let markers = [];
    let polylines = [];
    let refreshTimer = null;
    let isFirstLoad = true;

    // ============================================================
    // DOM ELEMENTS
    // ============================================================
    const mapEl = document.getElementById('tracker-map');
    const statusEl = document.getElementById('trackerStatus');
    const errorEl = document.getElementById('trackerError');
    const errorMsgEl = document.getElementById('errorMessage');

    // ============================================================
    // SIDEBAR TOGGLE
    // ============================================================
    document.addEventListener('DOMContentLoaded', function () {
      const sidebar = document.querySelector('.sidebar');
      const overlay = document.querySelector('.overlay');
      const toggleBtn = document.querySelector('.sidebar-toggle');
      const closeBtn = document.querySelector('.sidebar-close');

      function openSidebar() {
        if (sidebar) sidebar.classList.add('open');
        if (overlay) overlay.classList.add('show');
        document.body.classList.add('sidebar-open');
      }

      function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('show');
        document.body.classList.remove('sidebar-open');
      }

      if (toggleBtn) {
        toggleBtn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          if (sidebar && sidebar.classList.contains('open')) {
            closeSidebar();
          } else {
            openSidebar();
          }
        });
      }

      if (closeBtn) {
        closeBtn.addEventListener('click', function (e) {
          e.preventDefault();
          closeSidebar();
        });
      }

      if (overlay) {
        overlay.addEventListener('click', function (e) {
          if (e.target === this) {
            closeSidebar();
          }
        });
      }

      document.querySelectorAll('.sidebar-nav a').forEach(function (link) {
        link.addEventListener('click', function () {
          if (window.innerWidth <= 992) {
            closeSidebar();
          }
        });
      });

      window.addEventListener('resize', function () {
        if (window.innerWidth > 992) {
          closeSidebar();
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          closeSidebar();
        }
      });
    });

    // ============================================================
    // ERROR HANDLING
    // ============================================================
    function showError(message) {
      errorMsgEl.textContent = message;
      errorEl.classList.add('show');
      statusEl.className = 'tracker-status error';
      statusEl.innerHTML = '❌ Error loading locations. Please try again.';
    }

    function hideError() {
      errorEl.classList.remove('show');
    }

    function setStatus(message, type, count) {
      statusEl.className = 'tracker-status';
      if (type === 'error') statusEl.classList.add('error');
      else if (type === 'success') statusEl.classList.add('success');
      
      if (count !== undefined && count > 0) {
        statusEl.innerHTML = message.replace('{count}', '<span class="count">' + count + '</span>');
      } else {
        statusEl.innerHTML = message;
      }
    }

    // ============================================================
    // MAP FUNCTIONS
    // ============================================================
    function initMap() {
      try {
        if (typeof L === 'undefined') {
          showError('Leaflet library failed to load.');
          return false;
        }

        map = L.map(mapEl).setView([14.5995, 120.9842], 12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          maxZoom: 19,
          attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        setTimeout(function() { if (map) map.invalidateSize(); }, 500);
        return true;
      } catch (e) {
        showError('Failed to initialize map: ' + e.message);
        return false;
      }
    }

    function clearMarkers() {
      markers.forEach(m => { try { m.remove(); } catch(e) {} });
      markers = [];
      polylines.forEach(l => { try { l.remove(); } catch(e) {} });
      polylines = [];
    }

    // ============================================================
    // RENDER POINTS - Shows all renters, green for recent, amber for stale
    // ============================================================
    function renderPoints(points) {
      if (!map) { if (!initMap()) return; }
      clearMarkers();

      if (!points || points.length === 0) {
        setStatus('📍 No active renters found with location data.', 'info');
        hideError();
        return;
      }

      const users = {};
      points.forEach(p => {
        const uid = p.user_id || 0;
        if (!users[uid]) {
          users[uid] = { full_name: p.full_name || 'Renter', points: [] };
        }
        users[uid].points.push(p);
      });

      const allBounds = [];
      let userCount = 0;

      Object.keys(users).forEach(uid => {
        const u = users[uid];
        u.points.sort((a, b) => new Date(a.recorded_at) - new Date(b.recorded_at));
        const coords = u.points
          .filter(pt => pt.latitude !== null && pt.latitude !== undefined && pt.longitude !== null && pt.longitude !== undefined)
          .map(pt => [parseFloat(pt.latitude), parseFloat(pt.longitude)]);
        if (!coords.length) return;

        const last = u.points[u.points.length - 1];
        const isRecent = last.is_recent === true;
        
        const color = isRecent ? '#22c55e' : '#f59e0b';
        const radius = isRecent ? 9 : 6;
        const dashArray = isRecent ? null : '5 5';

        if (coords.length > 1) {
          const poly = L.polyline(coords, { color: color, weight: 2, opacity: 0.5 }).addTo(map);
          polylines.push(poly);
        }

        const marker = L.circleMarker(
          [parseFloat(last.latitude), parseFloat(last.longitude)], 
          {
            radius: radius,
            fillColor: color,
            color: '#fff',
            weight: 2,
            fillOpacity: 0.9,
            dashArray: dashArray
          }
        ).addTo(map);

        const timeDisplay = last.recorded_at ? new Date(last.recorded_at).toLocaleString() : 'Unknown';
        const statusText = isRecent ? '🟢 Active (recent)' : '🟡 Last known (stale)';
        marker.bindPopup(`<strong>${u.full_name}</strong><br>${statusText}<br>Last update: ${timeDisplay}`);
        markers.push(marker);

        coords.forEach(c => allBounds.push(c));
        userCount++;
      });

      if (allBounds.length) {
        try { map.fitBounds(allBounds, { padding: [50, 50] }); } catch(e) {}
      }

      const recentCount = points.filter(p => p.is_recent === true).length;
      const staleCount = points.length - recentCount;
      
      let statusMsg = `📍 Showing {count} renter(s) with last known positions.`;
      if (recentCount > 0) statusMsg += ` 🟢 ${recentCount} recent`;
      if (staleCount > 0) statusMsg += ` 🟡 ${staleCount} stale`;
      
      setStatus(statusMsg, 'success', userCount);
      hideError();
    }

    // ============================================================
    // FETCH LOCATIONS
    // ============================================================
    function refreshLocations() {
      if (isFirstLoad) setStatus('🔄 Loading latest positions...', 'info');

      const url = window.location.pathname + '?ajax=1&section=locations';

      fetch(url, {
        headers: { 'Cache-Control': 'no-cache', 'Pragma': 'no-cache' }
      })
        .then(function(response) {
          if (!response.ok) throw new Error('HTTP ' + response.status);
          return response.json();
        })
        .then(function(data) {
          isFirstLoad = false;
          if (data.success) {
            renderPoints(data.points || []);
          } else {
            showError(data.message || 'Unable to load location data.');
            setStatus('❌ ' + (data.message || 'Unable to load locations'), 'error');
          }
        })
        .catch(function(error) {
          console.error('Location fetch error:', error);
          showError('Connection error: ' + error.message);
          setStatus('❌ Unable to load locations right now.', 'error');
        });
    }

    // ============================================================
    // INITIALIZE
    // ============================================================
    function initialize() {
      if (typeof L === 'undefined') {
        showError('Map library failed to load. Please refresh the page.');
        setStatus('❌ Map library failed to load', 'error');
        return;
      }
      initMap();
      refreshLocations();
      if (refreshTimer) clearInterval(refreshTimer);
      refreshTimer = setInterval(refreshLocations, REFRESH_INTERVAL);
    }

    window.addEventListener('beforeunload', function() {
      if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null; }
    });

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initialize);
    } else {
      initialize();
    }
  </script>
</body>
</html>