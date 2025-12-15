<?php
require '/var/www/html/lib/base.php';

try {
    $app = \OC::$server->get(\OCA\OpenRegister\AppInfo\Application::class);
    echo "✅ Application class loads successfully\n";
    
    $settings = \OC::$server->get(\OCA\OpenRegister\Service\SettingsService::class);
    echo "✅ SettingsService instantiates successfully\n";
    echo "Settings Service class: " . get_class($settings) . "\n";
    
    // Test a delegated method
    $backend = $settings->getSearchBackendConfig();
    echo "✅ getSearchBackendConfig() works: " . json_encode($backend) . "\n";
    
    echo "\n🎉 All tests passed!\n";
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
