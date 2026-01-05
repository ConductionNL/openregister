<?php
require_once '/var/www/html/lib/base.php';

$mapper = \OC::$server->get(\OCA\OpenRegister\Db\RegisterMapper::class);
$registers = $mapper->findAll();

echo "Found " . count($registers) . " registers\n";
foreach ($registers as $reg) {
    echo "  - " . $reg->getTitle() . " (ID: " . $reg->getId() . ", Slug: " . $reg->getSlug() . ")\n";
    
    $config = $reg->getConfiguration() ?? [];
    if (isset($config['enableMagicMapping'])) {
        echo "    ✨ Magic Mapping: " . ($config['enableMagicMapping'] ? 'ENABLED' : 'disabled') . "\n";
        if (!empty($config['magicMappingSchemas'])) {
            echo "    📊 Magic schemas: " . implode(', ', $config['magicMappingSchemas']) . "\n";
        }
    }
}

