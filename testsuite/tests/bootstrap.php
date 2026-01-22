<?php
// Load the composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Manually require the base classes that aren't in Composer
require_once __DIR__ . '/BaseTestCase.php';
require_once __DIR__ . '/AuthenticatedClient.php';

//xxx
echo "DEBUG: Bootstrap loaded successfully.\n";