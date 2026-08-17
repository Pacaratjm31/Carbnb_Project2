<?php
session_start();

// Store the role before destroying session
$role = $_SESSION['role'] ?? 'user';

/*
|--------------------------------------------------------------------------
| Clear All Session Variables
|--------------------------------------------------------------------------
*/
$_SESSION = [];

/*
|--------------------------------------------------------------------------
| Destroy Session Cookie
|--------------------------------------------------------------------------
*/
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

/*
|--------------------------------------------------------------------------
| Destroy Session
|--------------------------------------------------------------------------
*/
session_destroy();

/*
|--------------------------------------------------------------------------
| Redirect To Appropriate Login Page Based On Role
|--------------------------------------------------------------------------
*/
if ($role === 'admin') {
    header("Location: ../admin/admin_login.php");
} else {
    header("Location: login.php");
}
exit();
?>