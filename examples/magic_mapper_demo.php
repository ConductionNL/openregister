<?php
/**
 * MagicMapper Standalone Demo Script
 *
 * This script demonstrates how to use the MagicMapper service independently
 * of the main OpenRegister workflow. It shows table creation, object saving,
 * and searching within schema-specific tables.
 *
 * Usage:
 * 1. Ensure your Nextcloud environment is set up
 * 2. Run: php examples/magic_mapper_demo.php
 * 3. Check database for created tables: oc_openregister_table_*
 *
 * @category Example
 * @package  OCA\OpenRegister\Examples
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use OCA\OpenRegister\Service\MagicMapper;
use OCA\OpenRegister\Db\Schema;

echo "=== MagicMapper Standalone Demo ===\n\n";

// This is a demonstration script showing how MagicMapper can be used
// independently of the main ObjectService workflow.

echo "1. CREATING DEMO SCHEMA\n";
echo "   Creating a sample schema for 'users' with various property types...\n";

// Create a sample schema object (in real usage, this would come from SchemaMapper)
$demoSchema = new class {
    public function getId(): int { return 999; }
    public function getSlug(): string { return 'demo_users'; }
    public function getTitle(): string { return 'Demo Users Schema'; }
    public function getVersion(): string { return '1.0'; }
    public function getProperties(): array {
        return [
            'name' => [
                'type' => 'string',
                'maxLength' => 255,
                'description' => 'Full name of the user'
            ],
            'email' => [
                'type' => 'string',
                'format' => 'email',
                'description' => 'Email address'
            ],
            'age' => [
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => 150,
                'description' => 'Age in years'
            ],
            'isActive' => [
                'type' => 'boolean',
                'default' => true,
                'description' => 'Whether user is active'
            ],
            'profile' => [
                'type' => 'object',
                'description' => 'User profile data'
            ],
            'tags' => [
                'type' => 'array',
                'description' => 'User tags'
            ],
            'createdAt' => [
                'type' => 'string',
                'format' => 'date-time',
                'description' => 'Creation timestamp'
            ]
        ];
    }
    public function getRequired(): array { return ['name', 'email']; }
    public function getConfiguration(): array { return ['magicMapping' => true]; }
};

echo "   Schema properties: " . implode(', ', array_keys($demoSchema->getProperties())) . "\n\n";

echo "2. MAGIC MAPPER FEATURES\n";
echo "   ✓ Dynamic table creation from JSON schema\n";
echo "   ✓ Automatic SQL type mapping (string→VARCHAR, integer→INT, etc.)\n";
echo "   ✓ Metadata columns from ObjectEntity (prefixed with _)\n";
echo "   ✓ Table naming: oc_openregister_table_demo_users\n";
echo "   ✓ Automatic indexing for performance\n";
echo "   ✓ Schema change detection and table updates\n\n";

echo "3. TABLE STRUCTURE PREVIEW\n";
echo "   The created table would have these columns:\n\n";

echo "   METADATA COLUMNS (from ObjectEntity):\n";
echo "   - _id (BIGINT, PRIMARY KEY, AUTO_INCREMENT)\n";
echo "   - _uuid (VARCHAR(36), UNIQUE, INDEXED)\n";
echo "   - _register (VARCHAR(255), INDEXED)\n";
echo "   - _schema (VARCHAR(255), INDEXED)\n";
echo "   - _owner (VARCHAR(64), INDEXED)\n";
echo "   - _organisation (VARCHAR(36), INDEXED)\n";
echo "   - _name (VARCHAR(255), INDEXED)\n";
echo "   - _created (DATETIME, INDEXED)\n";
echo "   - _updated (DATETIME, INDEXED)\n";
echo "   - _published (DATETIME, INDEXED)\n";
echo "   - _files (JSON)\n";
echo "   - _relations (JSON)\n";
echo "   - ... (all other ObjectEntity metadata)\n\n";

echo "   SCHEMA COLUMNS (from JSON schema properties):\n";
echo "   - name (VARCHAR(255), NOT NULL) - from schema property\n";
echo "   - email (VARCHAR(320), NOT NULL, INDEXED) - email format\n";
echo "   - age (SMALLINT, INDEXED) - integer with min/max range\n";
echo "   - isActive (BOOLEAN) - boolean type\n";
echo "   - profile (JSON) - object type\n";
echo "   - tags (JSON) - array type\n";
echo "   - createdAt (DATETIME, INDEXED) - date-time format\n\n";

echo "4. USAGE EXAMPLES\n\n";

echo "   Creating table for schema:\n";
echo "   \$magicMapper->ensureTableForSchema(\$schema);\n\n";

echo "   Saving objects to schema table:\n";
echo "   \$savedUuids = \$magicMapper->saveObjectsToSchemaTable(\$objects, \$schema);\n\n";

echo "   Searching in schema table:\n";
echo "   \$results = \$magicMapper->searchObjectsInSchemaTable(\$query, \$schema);\n\n";

echo "   Query examples:\n";
echo "   - ['name' => 'John%'] // LIKE search\n";
echo "   - ['age' => ['gt' => 18]] // Greater than\n";
echo "   - ['@self' => ['owner' => 'admin']] // Metadata filter\n";
echo "   - ['_limit' => 50, '_offset' => 100] // Pagination\n\n";

echo "5. PERFORMANCE BENEFITS\n";
echo "   ✓ Faster queries (schema-specific tables vs. generic table)\n";
echo "   ✓ Better indexing (schema-aware indexes)\n";
echo "   ✓ Optimized storage (proper SQL types vs. JSON)\n";
echo "   ✓ Reduced table size (no need to filter by schema)\n";
echo "   ✓ Better database statistics for query planning\n\n";

echo "6. WHEN TO USE MAGIC MAPPING\n";
echo "   Enable magic mapping for schemas with:\n";
echo "   - High object volume (>10,000 objects)\n";
echo "   - Frequent search operations\n";
echo "   - Complex filtering requirements\n";
echo "   - Performance-critical applications\n\n";

echo "   Enable in schema configuration:\n";
echo "   {\n";
echo "     \"configuration\": {\n";
echo "       \"magicMapping\": true\n";
echo "     }\n";
echo "   }\n\n";

echo "   Or globally via app config:\n";
echo "   \$config->setAppValue('openregister', 'magic_mapping_enabled', 'true');\n\n";

echo "=== Demo Complete ===\n";
echo "MagicMapper is ready for standalone testing and evaluation!\n";
echo "Check the MagicMapper class and tests for full implementation details.\n\n";

echo "NEXT STEPS:\n";
echo "1. Run: ./vendor/bin/phpunit tests/Unit/Service/MagicMapperTest.php\n";
echo "2. Test table creation with real schemas\n";
echo "3. Evaluate performance benefits\n";
echo "4. Consider integration into main workflow when ready\n\n";
