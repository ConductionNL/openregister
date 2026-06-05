<?php
define('PHPUNIT_RUN', 1);

// Load app's vendor autoloader (one level up from tests/).
require_once __DIR__ . '/../vendor/autoload.php';

// Load Nextcloud lib autoloader (for OCP classes).
if (file_exists('/srv/nextcloud/lib/composer/autoload.php')) {
    require_once '/srv/nextcloud/lib/composer/autoload.php';
}
