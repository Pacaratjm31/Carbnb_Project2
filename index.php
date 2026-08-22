<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="theme-color"
        content="#0d6efd"
    >

    <title>Carbnb</title>

    <!-- PWA -->
    <link rel="manifest" href="/manifest.json">

    <script src="/pwa.js" defer></script>

    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Arial, sans-serif;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            background: #1e1e1e;
            color: #cfcfcf;
            min-height: 100vh;
        }

        /* =====================================================
           NAVBAR
        ===================================================== */

        nav {
            display: flex;
            justify-content: space-between;
            align-items: center;

            background-color: #2a2a2a;

            padding: 0 50px;

            height: 80px;

            position: fixed;
            top: 0;
            left: 0;

            width: 100%;

            z-index: 1000;

            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.25);
        }

        nav h2 {
            color: #ffffff;
            font-size: 28px;
        }

        .brand-logo {
            font-size: 28px;
            font-weight: 700;
        }

        .brand-logo .blue,
        .header-text .blue {
            color: #00bfff;
        }

        .brand-logo .orange,
        .header-text .orange {
            color: #ff8c00;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 25px;
        }

        .nav-links a {
            color: #cfcfcf;
            text-decoration: none;
            font-weight: bold;

            transition:
                color 0.3s ease,
                transform 0.2s ease;
        }

        .nav-links a:hover {
            color: #00bfff;
        }

        /* =====================================================
           DOWNLOAD APP BUTTON
        ===================================================== */

        /* =====================================================
           TITLE SECTION
        ===================================================== */

        .header-text {
            margin-top: 100px;

            text-align: center;

            padding: 0 20px;
        }

        .header-text h1 {
            font-size: 4rem;

            font-weight: bold;

            margin-bottom: 8px;
        }

        .header-text .blue {
            color: #00bfff;
        }

        .header-text .orange {
            color: #ff8c00;
        }

        .header-text p {
            font-size: 1.3rem;

            color: #dcdcdc;

            margin-top: 8px;

            line-height: 1.6;
        }

        /* =====================================================
           HERO SECTION
        ===================================================== */

        .hero {
            position: relative;

            height: 60vh;

            min-height: 400px;

            margin: 20px 20px 0;

            border-radius: 24px;

            overflow: hidden;

            background-size: cover;

            background-position: center;

            background-repeat: no-repeat;

            background-color: #0b233a;

            display: flex;

            align-items: center;

            justify-content: center;

            color: #ffffff;

            opacity: 1;

            transition: opacity 0.7s ease-in-out;

            box-shadow:
                0 10px 40px rgba(0, 0, 0, 0.30);
        }

        .hero::before {
            content: '';

            position: absolute;

            inset: 0;

            background:
                linear-gradient(
                    180deg,
                    rgba(11, 40, 66, 0.38) 0%,
                    rgba(11, 40, 66, 0.72) 100%
                );

            z-index: 1;

            pointer-events: none;
        }

        .hero-content {
            position: relative;

            z-index: 2;

            text-align: center;

            padding: 0 24px;

            max-width: 920px;
        }

        .hero-content h2 {
            font-size: 3rem;

            margin-bottom: 14px;

            color: #ffffff;

            text-shadow:
                0 3px 12px rgba(0, 0, 0, 0.55);
        }

        .hero-content p {
            font-size: 1.2rem;

            line-height: 1.6;

            color: #e2e8f0;

            margin-bottom: 0;

            text-shadow:
                0 2px 8px rgba(0, 0, 0, 0.55);
        }

        /* =====================================================
           ORIGINAL SHOWCASE CARDS
        ===================================================== */

        .showcase {
            display: grid;

            grid-template-columns:
                repeat(3, minmax(220px, 1fr));

            gap: 20px;

            padding: 40px 20px;

            max-width: 1200px;

            margin: auto;
        }

        .showcase-card {
            background: #1f2937;

            border-radius: 18px;

            overflow: hidden;

            box-shadow:
                0 8px 30px rgba(0, 0, 0, 0.2);
        }

        .showcase-card img {
            width: 100%;

            display: block;

            object-fit: cover;

            height: 220px;
        }

        .showcase-card-content {
            padding: 20px;
        }

        .showcase-card h3 {
            color: #ffd700;

            margin-bottom: 10px;
        }

        .showcase-card p {
            color: #d1d5db;

            line-height: 1.7;
        }

        /* =====================================================
           INTRODUCTION
        ===================================================== */

        .introduction {
            text-align: center;

            padding: 30px 20px 40px;

            max-width: 1000px;

            margin: 0 auto;
        }

        .introduction h2 {
            color: #ffd700;

            margin-bottom: 15px;

            font-size: 2rem;
        }

        .introduction p {
            max-width: 900px;

            margin: auto;

            line-height: 1.8;

            color: #cfcfcf;

            font-size: 1rem;
        }

        /* =====================================================
           FOOTER
        ===================================================== */

        footer {
            background-color: #2a2a2a;

            color: #ffd700;

            text-align: center;

            padding: 20px;

            margin-top: 20px;
        }

        /* =====================================================
           TABLET
        ===================================================== */

        @media (max-width: 900px) {

            nav {
                padding: 0 25px;
            }

            .nav-links {
                gap: 15px;
            }

            .nav-links a {
                font-size: 14px;
            }

            .header-text h1 {
                font-size: 3rem;
            }

            .hero {
                height: 50vh;
            }

            .hero-content h2 {
                font-size: 2.5rem;
            }

            .showcase {
                grid-template-columns:
                    repeat(2, minmax(180px, 1fr));
            }

        }

        /* =====================================================
           MOBILE
        ===================================================== */

        @media (max-width: 768px) {

            nav {
                padding: 0 16px;

                height: 64px;
            }

            .brand-logo {
                font-size: 23px;
            }

            .nav-links {
                gap: 10px;

                flex-wrap: wrap;

                justify-content: flex-end;
            }

            .nav-links a {
                font-size: 12px;
            }

            .header-text {
                margin-top: 85px;

                padding: 0 15px;
            }

            .header-text h1 {
                font-size: 2.4rem;
            }

            .header-text p {
                font-size: 1rem;
            }

            .hero {
                height: 45vh;

                min-height: 330px;

                margin: 20px 12px 0;

                border-radius: 18px;
            }

            .hero-content {
                padding: 0 20px;
            }

            .hero-content h2 {
                font-size: 2rem;

                line-height: 1.25;

                margin-bottom: 12px;
            }

            .hero-content p {
                font-size: 1rem;

                line-height: 1.6;
            }

            /* Keep original card images on mobile */

            .showcase {
                grid-template-columns:
                    repeat(2, minmax(180px, 1fr));

                gap: 16px;

                padding: 30px 16px;
            }

            .showcase-card img {
                height: 200px;
            }

            .introduction {
                padding: 40px 18px 30px;
            }

            .introduction h2 {
                font-size: 1.7rem;
            }

        }

        /* =====================================================
           SMALL MOBILE
        ===================================================== */

        @media (max-width: 576px) {

            nav {
                padding: 0 12px;

                height: 58px;
            }

            .brand-logo {
                font-size: 20px;
            }

            .nav-links {
                gap: 6px;
            }

            .nav-links a {
                font-size: 10px;
            }

            .header-text {
                margin-top: 78px;
            }

            .header-text h1 {
                font-size: 1.9rem;
            }

            .header-text p {
                font-size: 0.9rem;
            }

            .hero {
                height: 38vh;

                min-height: 280px;

                margin: 15px 10px 0;

                border-radius: 15px;
            }

            .hero-content h2 {
                font-size: 1.6rem;
            }

            .hero-content p {
                font-size: 0.9rem;

                line-height: 1.5;
            }

            /* Original cards remain on small mobile */

            .showcase {
                grid-template-columns: 1fr;

                padding: 24px 12px;
            }

            .showcase-card img {
                height: 180px;
            }

            .introduction {
                padding: 30px 15px;
            }

            .introduction h2 {
                font-size: 1.5rem;
            }

            .introduction p {
                font-size: 0.9rem;

                line-height: 1.7;
            }

            footer {
                font-size: 0.85rem;
            }

        }

    </style>

</head>

<body>

    <!-- =====================================================
         NAVBAR
    ===================================================== -->

    <nav>

        <h2 class="brand-logo">
            <span class="blue">Car</span><span class="orange">bnb</span>
        </h2>

        <div class="nav-links">

            <a href="about.php">
                About
            </a>

            <a href="contact.php">
                Contact
            </a>

            <a href="auth/login.php">
                Login
            </a>

            <a href="auth/register.php">
                Register
            </a>

        </div>

    </nav>


    <!-- =====================================================
         TITLE
    ===================================================== -->

    <section class="header-text">

        <h1>
            <span class="blue">Car</span><span class="orange">bnb</span>
        </h1>

        <p>
            A Self-Drive Rental Platform for Private Vehicle Owners
        </p>

    </section>


    <!-- =====================================================
         HERO
    ===================================================== -->

    <section
        class="hero"
        id="hero"
    >

        <div class="hero-content">

            <h2>
                Drive Luxury. Rent Easily.
            </h2>

            <p>
                Discover premium vehicles from trusted owners
                with effortless self-drive booking.
            </p>

        </div>

    </section>


    <!-- =====================================================
         ORIGINAL IMAGE CARDS
    ===================================================== -->

    <section class="showcase">

        <!-- CARD 1 -->

        <div class="showcase-card">

            <img
                src="https://scontent.fmnl4-3.fna.fbcdn.net/v/t1.15752-9/783012010_1076794038200669_6159157721938109983_n.png?_nc_cat=109&_nc_map=urlgen_bucketless&ccb=1-7&_nc_sid=0024fc&_nc_eui2=AeHlYmWsiFxjWdnWRzpajpxn2tvGFC_o9rPa28YUL-j2s81dMYB1af98tIoWSMO7D9Pa65JszVQ6aP8XssfurhIl&_nc_ohc=m1CGxP_4_gkQ7kNvwEuCYlO&_nc_oc=Adq-HHkfPq56dEgiXb5fj17oNeq7sDWyvQdqk_CqKNaLRaYThxYFCDGxJxnXeksYS2BsQrRLyN6N2w5U594BciTO&_nc_ad=z-m&_nc_cid=0&_nc_zt=23&_nc_ht=scontent.fmnl4-3.fna&_nc_ss=7a22e&oh=03_Q7cD6AEu_HEf65V9HvJE9u6zrgxZ7xEtjumwoRO85nTDmYEz9g&oe=6AB0BB73"
                alt="Luxury sports car"
                loading="lazy"
            >

            <div class="showcase-card-content">

                <h3>
                    Luxury Collection
                </h3>

                <p>
                    Browse exclusive premium cars for city cruising,
                    weekend escapes, and special events.
                </p>

            </div>

        </div>


        <!-- CARD 2 -->

        <div class="showcase-card">

            <img
                src="https://scontent.fmnl4-2.fna.fbcdn.net/v/t1.15752-9/774280896_2556767171505188_9176757109383549637_n.jpg?_nc_cat=108&_nc_map=urlgen_bucketless&ccb=1-7&_nc_sid=0024fc&_nc_eui2=AeEyhSNJ4q_TtPcqjpE1O9XOJCtfYKv05-okK19gq_Tn6jGExDFFcrkOggr4_J2SuZUbYBWVcc0zyhngdbWpieGw&_nc_ohc=XSAluzK2Op0Q7kNvwGTXYjf&_nc_oc=AdpB945MsIry1x18Z5uW2rmdcAU914cX6TrQpjxAvkspTVeOgDQ1i6SPYvmvqw5QJvLk9zDd1REpXreapAb24ty1&_nc_ad=z-m&_nc_cid=0&_nc_zt=23&_nc_ht=scontent.fmnl4-2.fna&_nc_ss=7a22e&oh=03_Q7cD6AEr703MCcFFX_x-79dVETD6xhxXPvFTVkbVUZ8JdnGXFg&oe=6AB0B6CE"
                alt="Modern SUV"
                loading="lazy"
            >

            <div class="showcase-card-content">

                <h3>
                    Spacious SUVs
                </h3>

                <p>
                    Choose roomy and comfortable SUVs designed
                    for family trips and long-distance travel.
                </p>

            </div>

        </div>


        <!-- CARD 3 -->

        <div class="showcase-card">

            <img
                src="https://scontent.fmnl4-7.fna.fbcdn.net/v/t1.15752-9/784424749_984270248003424_8978476463502668531_n.jpg?_nc_cat=101&_nc_map=urlgen_bucketless&ccb=1-7&_nc_sid=0024fc&_nc_eui2=AeF7hPkCv8TbL0QDlt0upGZ2DxFWoYRl2V8PEVahhGXZXyky_NLlN5i_CMekbDo9dQ0kug-Azf-WajvQcxpr3Lu4&_nc_ohc=fH2G6MjmIsgQ7kNvwHrUb0x&_nc_oc=Adr7zwvyfVlBgTj2y041f4tDamqBd_YGHOtUxTrwUV4_oXUSGBNRLBvELztXKCEdPUfNB1Vk5VrIj9sjFgUE92VQ&_nc_ad=z-m&_nc_cid=0&_nc_zt=23&_nc_ht=scontent.fmnl4-7.fna&_nc_ss=7a22e&oh=03_Q7cD6AHEYGV4g8wHacCri73-XRDtjBRCsHP5t-abnz1e_fTJYw&oe=6AB0CEAB"
                alt="Convertible car"
                loading="lazy"
            >

            <div class="showcase-card-content">

                <h3>
                    Sporty Rides
                </h3>

                <p>
                    Enjoy high-performance cars with sleek styling
                    and advanced driving dynamics.
                </p>

            </div>

        </div>

    </section>


    <!-- =====================================================
         INTRODUCTION
    ===================================================== -->

    <section class="introduction">

        <h2>
            Introduction
        </h2>

        <p>
            Carbnb is a web-based self-drive rental platform
            designed to modernize vehicle rental services.
            It allows users to browse vehicles, check availability,
            and book easily while helping owners manage cars
            and income efficiently.
        </p>

    </section>


    <!-- =====================================================
         FOOTER
    ===================================================== -->

    <footer>

        &copy; 2026 Carbnb. All rights reserved.

    </footer>


    <!-- =====================================================
         HERO IMAGE SLIDESHOW
         ORIGINAL SYSTEM
    ===================================================== -->

    <script>

        document.addEventListener("DOMContentLoaded", function () {

            const hero = document.getElementById("hero");

            if (!hero) {
                return;
            }

            /*
             * IMPORTANT:
             * These are REAL URLs.
             * Do NOT put [ ] or ( ) around them.
             */

            const images = [

                "https://scontent.fmnl4-3.fna.fbcdn.net/v/t1.15752-9/783012010_1076794038200669_6159157721938109983_n.png?_nc_cat=109&_nc_map=urlgen_bucketless&ccb=1-7&_nc_sid=0024fc&_nc_eui2=AeHlYmWsiFxjWdnWRzpajpxn2tvGFC_o9rPa28YUL-j2s81dMYB1af98tIoWSMO7D9Pa65JszVQ6aP8XssfurhIl&_nc_ohc=m1CGxP_4_gkQ7kNvwEuCYlO&_nc_oc=Adq-HHkfPq56dEgiXb5fj17oNeq7sDWyvQdqk_CqKNaLRaYThxYFCDGxJxnXeksYS2BsQrRLyN6N2w5U594BciTO&_nc_ad=z-m&_nc_cid=0&_nc_zt=23&_nc_ht=scontent.fmnl4-3.fna&_nc_ss=7a22e&oh=03_Q7cD6AEu_HEf65V9HvJE9u6zrgxZ7xEtjumwoRO85nTDmYEz9g&oe=6AB0BB73",

                "https://scontent.fmnl4-2.fna.fbcdn.net/v/t1.15752-9/774280896_2556767171505188_9176757109383549637_n.jpg?_nc_cat=108&_nc_map=urlgen_bucketless&ccb=1-7&_nc_sid=0024fc&_nc_eui2=AeEyhSNJ4q_TtPcqjpE1O9XOJCtfYKv05-okK19gq_Tn6jGExDFFcrkOggr4_J2SuZUbYBWVcc0zyhngdbWpieGw&_nc_ohc=XSAluzK2Op0Q7kNvwGTXYjf&_nc_oc=AdpB945MsIry1x18Z5uW2rmdcAU914cX6TrQpjxAvkspTVeOgDQ1i6SPYvmvqw5QJvLk9zDd1REpXreapAb24ty1&_nc_ad=z-m&_nc_cid=0&_nc_zt=23&_nc_ht=scontent.fmnl4-2.fna&_nc_ss=7a22e&oh=03_Q7cD6AEr703MCcFFX_x-79dVETD6xhxXPvFTVkbVUZ8JdnGXFg&oe=6AB0B6CE",

                "https://scontent.fmnl4-7.fna.fbcdn.net/v/t1.15752-9/784424749_984270248003424_8978476463502668531_n.jpg?_nc_cat=101&_nc_map=urlgen_bucketless&ccb=1-7&_nc_sid=0024fc&_nc_eui2=AeF7hPkCv8TbL0QDlt0upGZ2DxFWoYRl2V8PEVahhGXZXyky_NLlN5i_CMekbDo9dQ0kug-Azf-WajvQcxpr3Lu4&_nc_ohc=fH2G6MjmIsgQ7kNvwHrUb0x&_nc_oc=Adr7zwvyfVlBgTj2y041f4tDamqBd_YGHOtUxTrwUV4_oXUSGBNRLBvELztXKCEdPUfNB1Vk5VrIj9sjFgUE92VQ&_nc_ad=z-m&_nc_cid=0&_nc_zt=23&_nc_ht=scontent.fmnl4-7.fna&_nc_ss=7a22e&oh=03_Q7cD6AHEYGV4g8wHacCri73-XRDtjBRCsHP5t-abnz1e_fTJYw&oe=6AB0CEAB"

            ];

            let current = 0;

            function updateHeroImage(index) {

                hero.style.opacity = "0";

                setTimeout(function () {

                    hero.style.backgroundImage =
                        'url("' + images[index] + '")';

                    hero.style.opacity = "1";

                }, 300);

            }

            /*
             * Initial hero image
             */

            hero.style.backgroundImage =
                'url("' + images[0] + '")';

            hero.style.transition =
                "opacity 0.7s ease-in-out";


            /*
             * Start slideshow
             */

            setInterval(function () {

                current =
                    (current + 1) % images.length;

                updateHeroImage(current);

            }, 9000);

        });

    </script>

</body>

</html>