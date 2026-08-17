<?php
/**
 * carbnb_install.php
 *
 * Entry point for the "Download App" button on the Carbnb website.
 * The actual APK is NOT hosted on InfinityFree (their servers block
 * .apk uploads). Instead, the APK is built automatically by GitHub
 * Actions and hosted on GitHub Releases, which never blocks it.
 *
 * This file:
 *   1. Shows a simple "Download Carbnb" landing page (not a blank
 *      redirect) so the user sees what they're downloading first.
 *   2. Logs each download attempt to a local text file, so you can
 *      show a basic download count during your defense if asked.
 *   3. Provides a direct download link/button to the latest APK
 *      built from your mobileapp/ GitHub Actions workflow.
 */

// Always points to the newest successful build - GitHub keeps this
// URL pointing at whatever APK is tagged "latest-apk" in Releases.
$apkUrl = "https://github.com/Pacaratjm31/Project_Carbnb2_2/releases/latest/download/Carbnb.apk";

// --- Simple download logging (optional, safe to remove if not needed) ---
function logDownloadAttempt(): void
{
    $logFile = __DIR__ . '/download_log.txt';

    $entry = sprintf(
        "%s | IP: %s | UA: %s\n",
        date('Y-m-d H:i:s'),
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    );

    // Fail silently if the log file can't be written (e.g. permissions
    // on shared hosting) - this should never block the download.
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

// If the button/link is visited with ?go=1, skip the landing page and
// redirect straight to the APK. Otherwise show the landing page below.
$directDownload = isset($_GET['go']);

if ($directDownload) {
    logDownloadAttempt();
    header("Location: " . $apkUrl);
    exit;
}

logDownloadAttempt();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Download Carbnb App</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Arial, sans-serif;
        }

        body {
            background: #1e1e1e;
            color: #cfcfcf;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .install-card {
            background: #2a2a2a;
            border-radius: 20px;
            padding: 40px 32px;
            max-width: 440px;
            width: 100%;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.35);
        }

        .app-icon {
            width: 84px;
            height: 84px;
            margin: 0 auto 20px;
            border-radius: 20px;
            background: linear-gradient(135deg, #00bfff, #ff8c00);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
        }

        h1 {
            font-size: 1.6rem;
            margin-bottom: 6px;
        }

        h1 .blue { color: #00bfff; }
        h1 .orange { color: #ff8c00; }

        p.subtitle {
            color: #9ca3af;
            margin-bottom: 28px;
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .download-btn {
            display: block;
            width: 100%;
            padding: 14px;
            background: #ff8c00;
            color: #1a1a1a;
            font-weight: 700;
            font-size: 1rem;
            border: none;
            border-radius: 12px;
            text-decoration: none;
            transition: all 0.2s ease;
            margin-bottom: 16px;
        }

        .download-btn:hover {
            background: #e07b00;
            transform: translateY(-1px);
        }

        .notice {
            background: #1f2937;
            border-radius: 10px;
            padding: 14px 16px;
            text-align: left;
            font-size: 0.85rem;
            color: #d1d5db;
            line-height: 1.6;
        }

        .notice strong {
            color: #ffd700;
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #6b7280;
            font-size: 0.85rem;
            text-decoration: none;
        }

        .back-link:hover {
            color: #9ca3af;
        }
    </style>
</head>
<body>

    <div class="install-card">

        <div class="app-icon">🚗</div>

        <h1><span class="blue">Car</span><span class="orange">bnb</span> Mobile App</h1>

        <p class="subtitle">
            Book, manage, and track your rentals on the go.
            Download the Android app below.
        </p>

        <a href="?go=1" class="download-btn">
            ⬇ Download Carbnb.apk
        </a>

        <div class="notice">
            <strong>Before installing:</strong> Android may warn that this
            app is from an "unknown source" since it isn't on the Play
            Store. This is expected for this project - tap
            <strong>Settings</strong> in that prompt, allow installs from
            your browser, then continue installing.
        </div>

        <a href="index.php" class="back-link">← Back to Carbnb</a>

    </div>

</body>
</html>