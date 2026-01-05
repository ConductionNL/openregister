<?php
/**
 * Direct import via ConfigurationService
 */

require_once '/var/www/html/lib/base.php';

echo "🔧 Starting direct configuration import...\n";

try {
    $configService = \OC::$server->get(\OCA\OpenRegister\Service\ConfigurationService::class);
    
    $json = file_get_contents('/tmp/config.json');
    $config = json_decode($json, true);
    
    echo "📦 Configuration loaded\n";
    echo "   - Title: " . ($config['info']['title'] ?? 'Unknown') . "\n";
    echo "   - Registers: " . count($config['components']['registers'] ?? []) . "\n";
    echo "   - Schemas: " . count($config['components']['schemas'] ?? []) . "\n";
    
    echo "\n🚀 Importing configuration (this may take a while)...\n";
    
    $result = $configService->importConfiguration($config);
    
    echo "\n✅ Import complete!\n";
    
    if (isset($result['registers'])) {
        echo "📋 Imported " . count($result['registers']) . " registers:\n";
        foreach ($result['registers'] as $register) {
            echo "   - {$register['title']} (ID: {$register['id']}, Slug: {$register['slug']})\n";
            
            $regConfig = $register['configuration'] ?? [];
            if (isset($regConfig['enableMagicMapping'])) {
                echo "     ✨ Magic Mapping: " . ($regConfig['enableMagicMapping'] ? 'ENABLED' : 'disabled') . "\n";
                if (!empty($regConfig['magicMappingSchemas'])) {
                    echo "     📊 Magic schemas: " . count($regConfig['magicMappingSchemas']) . " schemas\n";
                }
            }
        }
    }
    
    if (isset($result['schemas'])) {
        echo "\n📄 Imported " . count($result['schemas']) . " schemas\n";
    }
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n✅ Done!\n";

