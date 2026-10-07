<?php
require_once __DIR__ . '/../config/functions.php';

session_unset();
session_destroy();

// Redirect ke homepage
header('Location: ' . url());
exit;
?>