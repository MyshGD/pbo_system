<?php
// Temporary seed runner - access at http://localhost/bpo-systemUp/run-seed.php
// This file should be deleted after running

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// Skip auth middleware for seeding
define('SKIP_AUTH_CHECK', true);

require_once __DIR__ . '/database/seed.php';

?>

