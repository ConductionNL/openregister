<?php
require '/var/www/html/lib/base.php';

echo "Testing DI container...\n";

try {
    $mapper = \OC::$server->get('OCA\OpenRegister\Db\OrganisationMapper');
    echo "✓ OrganisationMapper created successfully\n";
} catch (\Exception $e) {
    echo "✗ OrganisationMapper ERROR: " . $e->getMessage() . "\n";
}

try {
    $mapper = \OC::$server->get('OCA\OpenRegister\Db\RegisterMapper');
    echo "✓ RegisterMapper created successfully\n";
} catch (\Exception $e) {
    echo "✗ RegisterMapper ERROR: " . $e->getMessage() . "\n";
}

try {
    $handler = \OC::$server->get('OCA\OpenRegister\Service\Configuration\PreviewHandler');
    echo "✓ PreviewHandler created successfully\n";
} catch (\Exception $e) {
    echo "✗ PreviewHandler ERROR: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";

