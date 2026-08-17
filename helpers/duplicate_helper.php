<?php
// Duplicate Helper Functions
// This file contains helper functions for form token generation and validation
// to prevent duplicate form submissions.

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate a unique form token for CSRF protection and duplicate prevention
function generate_form_token(string $formName): string
{
    $token = bin2hex(random_bytes(32));
    $_SESSION['form_tokens'][$formName] = [
        'token' => $token,
        'created_at' => time()
    ];
    return $token;
}

// Validate a form token to prevent duplicate submissions
function validate_form_token(string $formName, ?string $token): bool
{
    if (empty($token) || empty($_SESSION['form_tokens'][$formName])) {
        return false;
    }
    
    $stored = $_SESSION['form_tokens'][$formName];
    
    // Check if token matches
    if (!hash_equals($stored['token'], $token)) {
        return false;
    }
    
    // Token is valid - remove it to prevent reuse (one-time token)
    unset($_SESSION['form_tokens'][$formName]);
    
    return true;
}

// Generate a hidden token input field for forms
function form_token_input(string $formName): string
{
    $token = generate_form_token($formName);
    return '<input type="hidden" name="form_token" value="' . htmlspecialchars($token) . '">';
}

// Validate form token and return error message if invalid
function validate_form_token_or_error(string $formName): ?string
{
    $token = $_POST['form_token'] ?? null;
    
    if (!validate_form_token($formName, $token)) {
        return 'Invalid or expired form submission. Please try again.';
    }
    
    return null;
}

// Get the current PDO connection from global scope
function get_pdo_connection(): ?PDO
{
    return $GLOBALS['pdo'] ?? $GLOBALS['conn'] ?? null;
}
?>