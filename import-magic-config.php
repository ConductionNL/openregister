<?php
/**
 * Import Software Catalog Magic Mapper Configuration
 * 
 * This script imports the software catalog configuration with magic mapping enabled.
 */

require_once '/var/www/html/lib/base.php';

echo "🔧 Importing Software Catalog Configuration (Magic Mapper)...\n";

try {
    $configService = \OC::$server->get(\OCA\OpenRegister\Service\ConfigurationService::class);
    
    $json = file_get_contents('/tmp/config.json');
    if ($json === false) {
        throw new Exception("Failed to read config file");
    }
    
    $config = json_decode($json, true);
    if ($config === null) {
        throw new Exception("Failed to parse JSON: " . json_last_error_msg());
    }
    
    echo "📦 Configuration loaded, starting import...\n";
    
    $result = $configService->importConfiguration($config);
    
    echo "✅ Import complete!\n";
    echo "📊 Result summary:\n";
    echo "   - Registers: " . count($result['registers'] ?? []) . "\n";
    echo "   - Schemas: " . count($result['schemas'] ?? []) . "\n";
    
    // Show register details
    foreach ($result['registers'] ?? [] as $register) {
        echo "\n📋 Register: {$register['title']} (ID: {$register['id']})\n";
        $regConfig = $register['configuration'] ?? [];
        if (isset($regConfig['enableMagicMapping'])) {
            echo "   ✨ Magic Mapping: " . ($regConfig['enableMagicMapping'] ? 'ENABLED' : 'disabled') . "\n";
            if (!empty($regConfig['magicMappingSchemas'])) {
                echo "   📊 Magic schemas: " . implode(', ', $regConfig['magicMappingSchemas']) . "\n";
            }
        }
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "   Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n✅ All done!\n";

