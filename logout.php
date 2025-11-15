<?php
require_once 'config/config.php';

// Destroy session
session_destroy();

// Clear remember me cookies for auto-login
clearRememberMeCookie();

// Redirect to home page
redirectTo('index.php');
?>
