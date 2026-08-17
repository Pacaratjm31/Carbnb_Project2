<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us - Carbnb</title>
    <style>
        /* ===== DARK/METALLIC THEME ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Arial, sans-serif; }

        body {
            background:#1e1e1e;
            color: #cfcfcf;            /* Light text */
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* ===== NAVBAR ===== */
        nav { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            background-color: #2a2a2a; 
            padding: 0 50px; 
            height: 70px; 
            position: fixed; 
            top: 0; 
            width: 100%; 
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.5);
        }
        nav h2 a { 
            color: #ffd700; 
            text-decoration: none; 
            font-size: 24px;
        }
        .nav-links a { 
            color: #cfcfcf; 
            text-decoration: none; 
            margin-left: 20px; 
            font-weight: bold; 
            transition: 0.3s;
        }
        .nav-links a:hover { color: #00bfff; }

        /* ===== CONTENT ===== */
        .container { 
            margin-top: 100px; 
            padding: 40px 10%; 
            flex: 1; 
        }
        .about-card { 
            background-color: #2a2a2a; /* Dark metallic card */
            padding: 40px; 
            border-radius: 12px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.6); 
            line-height: 1.6;
        }
        h1 { color: #ffd700; margin-bottom: 20px; }
        p { margin-bottom: 15px; color: #dcdcdc; font-size: 1.1rem; }

        /* ===== FOOTER ===== */
        footer { 
            background-color: #2a2a2a; 
            color: #ffd700; 
            text-align: center; 
            padding: 20px; 
            margin-top: 40px; 
            box-shadow: 0 -2px 10px rgba(0,0,0,0.5);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            nav { padding: 0 20px; }
            .container { padding: 20px 5%; }
            .about-card { padding: 20px; }
            .nav-links { display: none; }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav>
        <h2><a href="home.php">Carbnb</a></h2>
        <div class="nav-links">
            <a href="index.php">Home</a>
            <a href="contact.php">Contact</a>
        </div>
    </nav>

    <!-- About Content -->
    <div class="container">
        <div class="about-card">
            <h1>About Carbnb</h1>
            <p>Welcome to <strong>CarBnb </strong> a web-based self-drive rental platform established in 2025, located in Novaliches, Quezon City. </p>
            <p>It connects private vehicle owners with individuals in need of convenient and affordable transportation.</p>
            <p>The system provides an easy-to-use platform for browsing, booking, and managing vehicle rentals while promoting accessibility, efficiency, and resource sharing.</p>
            <br><br>
            <p>For inquiries, you may contact us at </p>
            <p>09927243253.</p>
        </div>
    </div>

    <!-- Footer -->
    <footer>&copy; 2026 Carbnb. All rights reserved.</footer>
</body>
</html>