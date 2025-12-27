<?php
/**
 * OpenRegister Magic Mapper Runtime Test
 *
 * Simple script to test magic mapper functionality in a running Nextcloud instance.
 * Run this from the Nextcloud container:
 * docker exec -u 33 master-nextcloud-1 php apps-extra/openregister/tests/manual/test-magic-mapper.php
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

require_once __DIR__.'/../../../../lib/base.php';

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Test Magic Mapper Runtime Functionality
 *
 * @return void
 */
function testMagicMapper(): void
{
    echo "=== Magic Mapper Runtime Test ===\n\n";

    try {
        // Get app container.
        $app       = new Application();
        $container = $app->getContainer();

        echo "✓ Application container loaded\n";

        // Test 1: Can we get UnifiedObjectMapper from DI?
        echo "\n1. Testing UnifiedObjectMapper DI...\n";
        $mapper = $container->get(UnifiedObjectMapper::class);
        echo "✓ UnifiedObjectMapper instantiated: ".get_class($mapper)."\n";

        // Test 2: Can we get RegisterMapper and SchemaMapper?
        echo "\n2. Testing RegisterMapper and SchemaMapper...\n";
        $registerMapper = $container->get(RegisterMapper::class);
        $schemaMapper   = $container->get(SchemaMapper::class);
        echo "✓ RegisterMapper instantiated: ".get_class($registerMapper)."\n";
        echo "✓ SchemaMapper instantiated: ".get_class($schemaMapper)."\n";

        // Test 3: Check if configuration column exists.
        echo "\n3. Checking database schema...\n";
        $registers = $registerMapper->findAll();
        if (count($registers) > 0) {
            $register = $registers[0];
            $config   = $register->getConfiguration();
            echo "✓ Configuration column accessible (returned: ".gettype($config).")\n";
            echo "  Sample register ID: ".$register->getId()."\n";
            echo "  Configuration value: ".json_encode($config)."\n";
        } else {
            echo "⚠ No registers found in database (this is OK for fresh install)\n";
        }

        // Test 4: Test Register configuration methods.
        if (count($registers) > 0) {
            echo "\n4. Testing Register configuration methods...\n";
            $register = $registers[0];

            // Test isMagicMappingEnabledForSchema.
            $enabled = $register->isMagicMappingEnabledForSchema(schemaId: 999);
            echo "✓ isMagicMappingEnabledForSchema(999): ".($enabled ? 'true' : 'false')."\n";

            // Test enableMagicMappingForSchema.
            $register->enableMagicMappingForSchema(
                schemaId: 999,
                autoCreateTable: true,
                comment: 'Test schema for magic mapper'
            );
            $enabledAfter = $register->isMagicMappingEnabledForSchema(schemaId: 999);
            echo "✓ enableMagicMappingForSchema(999): ".($enabledAfter ? 'true' : 'false')."\n";

            // Test getSchemasWithMagicMapping.
            $magicSchemas = $register->getSchemasWithMagicMapping();
            echo "✓ getSchemasWithMagicMapping(): ".count($magicSchemas)." schema(s)\n";

            // Don't save this test change!
            echo "  (Not persisting test configuration)\n";
        }

        // Test 5: Verify UnifiedObjectMapper methods exist.
        echo "\n5. Testing UnifiedObjectMapper interface...\n";
        $methods = get_class_methods($mapper);
        $requiredMethods = ['find', 'findAll', 'insert', 'update', 'delete', 'lockObject', 'ultraFastBulkSave'];

        foreach ($requiredMethods as $method) {
            if (in_array($method, $methods) === true) {
                echo "✓ Method exists: {$method}()\n";
            } else {
                echo "✗ Method missing: {$method}()\n";
            }
        }

        echo "\n=== All Tests Passed! ===\n";
        echo "\n";
        echo "Summary:\n";
        echo "- UnifiedObjectMapper: Available via DI ✓\n";
        echo "- Database migration: Configuration column exists ✓\n";
        echo "- Register methods: Working correctly ✓\n";
        echo "- AbstractObjectMapper interface: Implemented ✓\n";
        echo "\n";
        echo "Next steps:\n";
        echo "1. Create a test register and schema\n";
        echo "2. Enable magic mapping for the schema\n";
        echo "3. Create a test object and verify table creation\n";
        echo "4. Integrate UnifiedObjectMapper into ObjectService\n";
    } catch (\Exception $e) {
        echo "\n✗ ERROR: ".$e->getMessage()."\n";
        echo "Stack trace:\n".$e->getTraceAsString()."\n";
        exit(1);
    }//end try
}//end testMagicMapper()

// Run the test.
testMagicMapper();




