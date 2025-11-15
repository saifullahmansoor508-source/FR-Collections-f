<?php
session_start();

// Destroy admin session
unset($_SESSION['admin_email']);
session_destroy();

// Redirect to login page
header('Location: login.php');
exit;
?>
