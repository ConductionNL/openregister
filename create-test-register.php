<?php
/**
 * Create simple register + schema for CSV import testing
 */

require_once '/var/www/html/lib/base.php';

echo "🔧 Creating register and schema...\n";

try {
    $registerMapper = \OC::$server->get(\OCA\OpenRegister\Db\RegisterMapper::class);
    $schemaMapper = \OC::$server->get(\OCA\OpenRegister\Db\SchemaMapper::class);
    
    // Create register
    $register = new \OCA\OpenRegister\Db\Register();
    $register->setUuid(\Symfony\Component\Uid\Uuid::v4()->toRfc4122());
    $register->setTitle('Voorzieningen');
    $register->setSlug('voorzieningen');
    $register->setDescription('Register voor voorzieningen uit de softwarecatalogus');
    $register->setConfiguration([
        'enableMagicMapping' => true,
        'magicMappingSchemas' => ['organisatie']
    ]);
    $register = $registerMapper->insert($register);
    
    echo "✅ Register created: ID {$register->getId()}, Slug: {$register->getSlug()}\n";
    
    // Create organisatie schema
    $schema = new \OCA\OpenRegister\Db\Schema();
    $schema->setUuid(\Symfony\Component\Uid\Uuid::v4()->toRfc4122());
    $schema->setTitle('Organisatie');
    $schema->setSlug('organisatie');
    $schema->setDescription('Schema voor organisaties');
    $schema->setProperties([
        'naam' => ['type' => 'string', 'description' => 'Naam van de organisatie'],
        'type' => ['type' => 'string', 'description' => 'Type organisatie'],
        'organisatieType' => ['type' => 'string', 'description' => 'Organisatie type'],
        'cbsCode' => ['type' => 'string', 'description' => 'CBS code'],
        'website' => ['type' => 'string', 'format' => 'url', 'description' => 'Website'],
        'e-mailadres' => ['type' => 'string', 'format' => 'email'],
        'telefoonnummer' => ['type' => 'string']
    ]);
    $schema = $schemaMapper->insert($schema);
    
    echo "✅ Schema created: ID {$schema->getId()}, Slug: {$schema->getSlug()}\n";
    echo "\n📋 Register configuration:\n";
    echo json_encode($register->getConfiguration(), JSON_PRETTY_PRINT) . "\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✅ Ready for CSV import!\n";

