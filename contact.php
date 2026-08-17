<?php
require_once __DIR__ . '/database/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$success = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');

    // VALIDATION
    if (empty($name) || empty($email) || empty($message)) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {

        try {
            $stmt = $conn->prepare("
                INSERT INTO contact_messages (name, email, message)
                VALUES (?, ?, ?)
            ");

            $stmt->execute([$name, $email, $message]);

            $success = "Message sent successfully!";

        } catch (PDOException $e) {
            $error = "Failed to send message.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Contact Us - Carbnb</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Segoe UI', Arial, sans-serif;
}

body {
    background-color: #1e1e1e;
    color: #cfcfcf;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}

/* NAVBAR */
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
}

nav h2 {
    color: #ffd700;
    font-size: 24px;
}

.nav-links a {
    color: #cfcfcf;
    text-decoration: none;
    margin-left: 20px;
    font-weight: bold;
}

.nav-links a:hover {
    color: #00bfff;
}

/* CONTAINER */
.container {
    margin-top: 100px;
    padding: 40px 10%;
    flex: 1;
    display: flex;
    justify-content: center;
}

/* FORM */
.contact-form {
    background-color: #2a2a2a;
    padding: 30px;
    border-radius: 12px;
    width: 100%;
    max-width: 500px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.6);
}

h1 {
    color: #ffd700;
    text-align: center;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 15px;
}

label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
    color: #dcdcdc;
}

input, textarea {
    width: 100%;
    padding: 10px;
    border-radius: 6px;
    border: 1px solid #555;
    background: #1e1e1e;
    color: #cfcfcf;
}

button {
    width: 100%;
    padding: 12px;
    background: #ffd700;
    color: #1e1e1e;
    border: none;
    border-radius: 6px;
    font-size: 1.1rem;
    cursor: pointer;
}

button:hover {
    background: #e6c200;
}

/* MESSAGES */
.success {
    background: #28a745;
    color: white;
    padding: 10px;
    border-radius: 6px;
    margin-bottom: 15px;
    text-align: center;
}

.error {
    background: #dc3545;
    color: white;
    padding: 10px;
    border-radius: 6px;
    margin-bottom: 15px;
    text-align: center;
}

/* FOOTER */
footer {
    background-color: #2a2a2a;
    color: #ffd700;
    text-align: center;
    padding: 20px;
}
</style>
</head>

<body>

<!-- NAVBAR -->
<nav>
    <h2>Carbnb</h2>
    <div class="nav-links">
        <a href="home.php">Home</a>
        <a href="about.php">About</a>
    </div>
</nav>

<!-- CONTACT FORM -->
<div class="container">

    <form class="contact-form" method="POST">

        <h1>Contact Us</h1>

        <?php if ($success): ?>
            <div class="success"><?= $success ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>

        <div class="form-group">
            <label>Name</label>
            <input type="text" name="name" required>
        </div>

        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" required>
        </div>

        <div class="form-group">
            <label>Message</label>
            <textarea name="message" rows="5" required></textarea>
        </div>

        <button type="submit">Send Message</button>

    </form>

</div>

<!-- FOOTER -->
<footer>
    &copy; 2026 Carbnb. All rights reserved.
</footer>

</body>
</html>