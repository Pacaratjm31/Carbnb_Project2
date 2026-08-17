<?php
// ============================================
// PERMISSION FIX FOR INFINITYFREE
// Run this file once to fix 403 errors
// ============================================

echo "<h1>🔧 InfinityFree Permission Fix</h1>";
echo "<hr>";

// ============================================
// 1. CHECK IF FILES EXIST
// ============================================
echo "<h2>📁 Step 1: Checking Files</h2>";

$files_to_check = [
    'location_tracker.php',
    'admin_auth.php',
    '../database/db.php'
];

foreach ($files_to_check as $file) {
    if (file_exists($file)) {
        echo "✅ $file - <span style='color:green;'>FOUND</span><br>";
    } else {
        echo "❌ $file - <span style='color:red;'>NOT FOUND</span><br>";
    }
}

echo "<hr>";

// ============================================
// 2. CREATE .HTACCESS FILE
// ============================================
echo "<h2>📄 Step 2: Creating .htaccess</h2>";

$htaccess_content = <<<HTACCESS
# ============================================
# INFINITYFREE PERMISSION FIX
# Allows access to location_tracker.php
# ============================================

# Allow all PHP files in this folder
<FilesMatch "\.php$">
    Order Allow,Deny
    Allow from all
    Require all granted
</FilesMatch>

# Specifically allow location_tracker.php
<Files "location_tracker.php">
    Order Allow,Deny
    Allow from all
    Require all granted
</Files>

# Allow POST requests (for renter location sharing)
<Limit POST>
    Order Allow,Deny
    Allow from all
</Limit>

# Allow GET requests (for admin map)
<Limit GET>
    Order Allow,Deny
    Allow from all
</Limit>

# Enable CORS for API calls
Header set Access-Control-Allow-Origin "*"
Header set Access-Control-Allow-Methods "GET, POST, OPTIONS"
Header set Access-Control-Allow-Headers "Content-Type"

# Disable directory listing
Options -Indexes

# Enable error reporting for debugging
php_flag display_errors on
php_value error_reporting 2039

# Increase memory limit (if needed)
php_value memory_limit 128M
php_value max_execution_time 60

HTACCESS;

// Write .htaccess file
if (file_put_contents('.htaccess', $htaccess_content)) {
    echo "✅ .htaccess file created successfully!<br>";
} else {
    echo "❌ Failed to create .htaccess file. Please create it manually.<br>";
}

echo "<hr>";

// ============================================
// 3. CREATE TEST FILE
// ============================================
echo "<h2>🧪 Step 3: Creating Test File</h2>";

$test_content = <<<'TESTPHP'
<?php
echo "✅ Admin folder is working!<br>";
echo "PHP Version: " . phpversion() . "<br>";
echo "Server: " . $_SERVER['SERVER_SOFTWARE'] . "<br>";
?>
TESTPHP;

if (file_put_contents('test.php', $test_content)) {
    echo "✅ test.php created successfully!<br>";
    echo "🔗 <a href='test.php' target='_blank'>Click here to test</a><br>";
} else {
    echo "❌ Failed to create test.php<br>";
}

echo "<hr>";

// ============================================
// 4. SET FILE PERMISSIONS
// ============================================
echo "<h2>🔒 Step 4: Setting File Permissions</h2>";

$files_to_chmod = [
    'location_tracker.php' => 0644,
    'admin_auth.php' => 0644,
    '.htaccess' => 0644,
    'test.php' => 0644,
];

foreach ($files_to_chmod as $file => $perms) {
    if (file_exists($file)) {
        if (chmod($file, $perms)) {
            echo "✅ $file - Permissions set to " . decoct($perms) . "<br>";
        } else {
            echo "❌ $file - Failed to set permissions (may need manual fix)<br>";
        }
    } else {
        echo "⚠️ $file - Not found<br>";
    }
}

echo "<hr>";

// ============================================
// 5. SET FOLDER PERMISSIONS
// ============================================
echo "<h2>📂 Step 5: Setting Folder Permissions</h2>";

// Try to set parent folder permissions
$folders = ['..', '.'];

foreach ($folders as $folder) {
    if (is_dir($folder)) {
        if (chmod($folder, 0755)) {
            echo "✅ $folder - Permissions set to 755<br>";
        } else {
            echo "❌ $folder - Failed to set permissions<br>";
        }
    }
}

echo "<hr>";

// ============================================
// 6. CHECK DATABASE CONNECTION
// ============================================
echo "<h2>🗄️ Step 6: Testing Database Connection</h2>";

try {
    require_once '../database/db.php';
    $pdo = $GLOBALS['pdo'] ?? null;
    
    if ($pdo) {
        echo "✅ Database connection: <span style='color:green;'>SUCCESS</span><br>";
        
        // Check if location_tracker table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'location_tracker'");
        if ($stmt->rowCount() > 0) {
            echo "✅ location_tracker table: <span style='color:green;'>EXISTS</span><br>";
            
            // Count records
            $stmt = $pdo->query("SELECT COUNT(*) FROM location_tracker");
            $count = $stmt->fetchColumn();
            echo "📊 Total records: $count<br>";
        } else {
            echo "❌ location_tracker table: <span style='color:red;'>NOT FOUND</span><br>";
            echo "⚠️ Please run the SQL to create the table.<br>";
        }
    } else {
        echo "❌ Database connection: <span style='color:red;'>FAILED</span><br>";
        echo "⚠️ Check your database credentials in db.php<br>";
    }
} catch (Exception $e) {
    echo "❌ Database error: " . $e->getMessage() . "<br>";
}

echo "<hr>";

// ============================================
// 7. FINAL INSTRUCTIONS
// ============================================
echo "<h2>✅ Step 7: Done!</h2>";
echo "<div style='background:#d4edda;padding:15px;border-radius:8px;border:1px solid #c3e6cb;'>";
echo "<h3 style='color:#155724;'>What to do next:</h3>";
echo "<ol style='color:#155724;'>";
echo "<li><strong>Test the map:</strong> <a href='location_tracker.php' target='_blank'>Click here to open location_tracker.php</a></li>";
echo "<li><strong>Test the API:</strong> <a href='location_tracker.php?ajax=1&section=locations' target='_blank'>Click here to test API endpoint</a></li>";
echo "<li><strong>Test PHP:</strong> <a href='test.php' target='_blank'>Click here to test test.php</a></li>";
echo "</ol>";
echo "</div>";

echo "<hr>";

// ============================================
// 8. SERVER INFO
// ============================================
echo "<h2>🖥️ Server Information</h2>";
echo "PHP Version: " . phpversion() . "<br>";
echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "<br>";
echo "Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "<br>";
echo "Script Path: " . __FILE__ . "<br>";

echo "<hr>";
echo "<p>✨ <strong>Done!</strong> You can delete this file after running it.</p>";
?>