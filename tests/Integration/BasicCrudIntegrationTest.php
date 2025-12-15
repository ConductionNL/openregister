<?php

/**
 * Basic CRUD Integration Test
 *
 * This integration test verifies the complete CRUD lifecycle of OpenRegister:
 * 1. Create schema
 * 2. Create register with schema
 * 3. Create objects
 * 4. Update objects
 * 5. Delete objects
 * 6. Update register
 * 7. Update schema
 * 8. Delete register
 * 9. Delete schema
 *
 * This test runs against actual API endpoints to ensure core functionality works.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Integration;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

/**
 * Basic CRUD Integration Test
 */
class BasicCrudIntegrationTest extends TestCase
{
    /**
     * HTTP client for API requests.
     *
     * @var Client
     */
    private Client $client;

    /**
     * Base URL for API requests.
     *
     * @var string
     */
    private string $baseUrl;

    /**
     * Admin credentials for authentication.
     *
     * @var array<string, string>
     */
    private array $auth;

    /**
     * Created register ID for cleanup.
     *
     * @var int|null
     */
    private ?int $registerId = null;

    /**
     * Created schema ID for cleanup.
     *
     * @var int|null
     */
    private ?int $schemaId = null;

    /**
     * Created object UUIDs for cleanup.
     *
     * @var array<string>
     */
    private array $objectUuids = [];

    /**
     * Unique test identifier for this run.
     *
     * @var string
     */
    private string $testId;

    /**
     * Set up test environment before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->testId = 'crud-' . time() . '-' . substr(md5(random_bytes(8)), 0, 8);
        
        $this->baseUrl = getenv('NEXTCLOUD_URL') ?: 'http://localhost';
        $this->auth = [
            getenv('NEXTCLOUD_USER') ?: 'admin',
            getenv('NEXTCLOUD_PASSWORD') ?: 'admin'
        ];

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'auth' => $this->auth,
            'http_errors' => false,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]
        ]);
    }

    /**
     * Clean up test data after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up created objects.
        foreach ($this->objectUuids as $uuid) {
            try {
                $this->client->delete("/index.php/apps/openregister/api/objects/{$uuid}");
            } catch (\Exception $e) {
                // Ignore cleanup errors.
            }
        }

        // Clean up created schema.
        if ($this->schemaId !== null) {
            try {
                $this->client->delete("/index.php/apps/openregister/api/schemas/{$this->schemaId}");
            } catch (\Exception $e) {
                // Ignore cleanup errors.
            }
        }

        // Clean up created register.
        if ($this->registerId !== null) {
            try {
                $this->client->delete("/index.php/apps/openregister/api/registers/{$this->registerId}");
            } catch (\Exception $e) {
                // Ignore cleanup errors.
            }
        }

        parent::tearDown();
    }

    /**
     * Test complete CRUD lifecycle: create, read, update, delete.
     *
     * This test verifies that all core CRUD operations work correctly
     * by performing them in sequence on register, schema, and objects.
     *
     * @return void
     */
    public function testCompleteCrudLifecycle(): void
    {
        // Step 1: Create a register.
        echo "\n📝 Step 1: Creating register...\n";
        $registerResponse = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'slug' => "test-register-{$this->testId}",
                'title' => 'Test Register for CRUD',
                'description' => 'This register tests basic CRUD operations.',
            ]
        ]);

        $this->assertEquals(201, $registerResponse->getStatusCode(), 'Register creation should return 201');
        $registerData = json_decode($registerResponse->getBody()->getContents(), true);
        $this->assertIsArray($registerData, 'Register response should be an array');
        $this->assertArrayHasKey('id', $registerData, 'Register should have an id');
        $this->registerId = $registerData['id'];
        echo "✅ Register created with ID: {$this->registerId}\n";

        // Step 2: Create a schema.
        echo "\n📝 Step 2: Creating schema...\n";
        $schemaResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $this->registerId,
                'slug' => "test-schema-{$this->testId}",
                'title' => 'Test Schema for CRUD',
                'description' => 'This schema tests basic CRUD operations.',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Name of the entity.',
                    ],
                    'age' => [
                        'type' => 'integer',
                        'description' => 'Age of the entity.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Whether the entity is active.',
                    ],
                ],
                'required' => ['name']
            ]
        ]);

        $this->assertEquals(201, $schemaResponse->getStatusCode(), 'Schema creation should return 201');
        $schemaData = json_decode($schemaResponse->getBody()->getContents(), true);
        $this->assertIsArray($schemaData, 'Schema response should be an array');
        $this->assertArrayHasKey('id', $schemaData, 'Schema should have an id');
        $this->schemaId = $schemaData['id'];
        echo "✅ Schema created with ID: {$this->schemaId}\n";

        // Step 3: Create an object.
        echo "\n📝 Step 3: Creating object...\n";
        $objectResponse = $this->client->post("/index.php/apps/openregister/api/objects/{$schemaData['slug']}", [
            'json' => [
                'name' => 'Test Object 1',
                'age' => 25,
                'active' => true,
            ]
        ]);

        $this->assertEquals(201, $objectResponse->getStatusCode(), 'Object creation should return 201');
        $objectData = json_decode($objectResponse->getBody()->getContents(), true);
        $this->assertIsArray($objectData, 'Object response should be an array');
        $this->assertArrayHasKey('uuid', $objectData, 'Object should have a uuid');
        $this->objectUuids[] = $objectData['uuid'];
        $objectUuid = $objectData['uuid'];
        echo "✅ Object created with UUID: {$objectUuid}\n";

        // Step 4: Read the object.
        echo "\n📝 Step 4: Reading object...\n";
        $readResponse = $this->client->get("/index.php/apps/openregister/api/objects/{$objectUuid}");
        
        $this->assertEquals(200, $readResponse->getStatusCode(), 'Object read should return 200');
        $readData = json_decode($readResponse->getBody()->getContents(), true);
        $this->assertIsArray($readData, 'Read response should be an array');
        $this->assertEquals('Test Object 1', $readData['name'], 'Object name should match');
        $this->assertEquals(25, $readData['age'], 'Object age should match');
        $this->assertTrue($readData['active'], 'Object active status should match');
        echo "✅ Object read successfully\n";

        // Step 5: Update the object.
        echo "\n📝 Step 5: Updating object...\n";
        $updateResponse = $this->client->put("/index.php/apps/openregister/api/objects/{$objectUuid}", [
            'json' => [
                'name' => 'Updated Test Object 1',
                'age' => 30,
                'active' => false,
            ]
        ]);

        $this->assertEquals(200, $updateResponse->getStatusCode(), 'Object update should return 200');
        $updateData = json_decode($updateResponse->getBody()->getContents(), true);
        $this->assertEquals('Updated Test Object 1', $updateData['name'], 'Updated name should match');
        $this->assertEquals(30, $updateData['age'], 'Updated age should match');
        $this->assertFalse($updateData['active'], 'Updated active status should match');
        echo "✅ Object updated successfully\n";

        // Step 6: Create a second object.
        echo "\n📝 Step 6: Creating second object...\n";
        $object2Response = $this->client->post("/index.php/apps/openregister/api/objects/{$schemaData['slug']}", [
            'json' => [
                'name' => 'Test Object 2',
                'age' => 35,
                'active' => true,
            ]
        ]);

        $this->assertEquals(201, $object2Response->getStatusCode(), 'Second object creation should return 201');
        $object2Data = json_decode($object2Response->getBody()->getContents(), true);
        $this->objectUuids[] = $object2Data['uuid'];
        echo "✅ Second object created with UUID: {$object2Data['uuid']}\n";

        // Step 7: List objects.
        echo "\n📝 Step 7: Listing objects...\n";
        $listResponse = $this->client->get("/index.php/apps/openregister/api/objects/{$schemaData['slug']}");
        
        $this->assertEquals(200, $listResponse->getStatusCode(), 'Object list should return 200');
        $listData = json_decode($listResponse->getBody()->getContents(), true);
        $this->assertIsArray($listData, 'List response should be an array');
        $this->assertArrayHasKey('results', $listData, 'List should have results');
        $this->assertGreaterThanOrEqual(2, count($listData['results']), 'Should have at least 2 objects');
        echo "✅ Objects listed successfully (found " . count($listData['results']) . " objects)\n";

        // Step 8: Update the register.
        echo "\n📝 Step 8: Updating register...\n";
        $updateRegisterResponse = $this->client->put("/index.php/apps/openregister/api/registers/{$this->registerId}", [
            'json' => [
                'title' => 'Updated Test Register',
                'description' => 'This register has been updated.',
            ]
        ]);

        $this->assertEquals(200, $updateRegisterResponse->getStatusCode(), 'Register update should return 200');
        $updateRegisterData = json_decode($updateRegisterResponse->getBody()->getContents(), true);
        $this->assertEquals('Updated Test Register', $updateRegisterData['title'], 'Updated register title should match');
        echo "✅ Register updated successfully\n";

        // Step 9: Update the schema.
        echo "\n📝 Step 9: Updating schema...\n";
        $updateSchemaResponse = $this->client->put("/index.php/apps/openregister/api/schemas/{$this->schemaId}", [
            'json' => [
                'title' => 'Updated Test Schema',
                'description' => 'This schema has been updated.',
            ]
        ]);

        $this->assertEquals(200, $updateSchemaResponse->getStatusCode(), 'Schema update should return 200');
        $updateSchemaData = json_decode($updateSchemaResponse->getBody()->getContents(), true);
        $this->assertEquals('Updated Test Schema', $updateSchemaData['title'], 'Updated schema title should match');
        echo "✅ Schema updated successfully\n";

        // Step 10: Delete first object.
        echo "\n📝 Step 10: Deleting first object...\n";
        $deleteObjectResponse = $this->client->delete("/index.php/apps/openregister/api/objects/{$objectUuid}");
        
        $this->assertEquals(204, $deleteObjectResponse->getStatusCode(), 'Object delete should return 204');
        echo "✅ First object deleted successfully\n";

        // Step 11: Verify object is deleted.
        echo "\n📝 Step 11: Verifying object deletion...\n";
        $verifyDeleteResponse = $this->client->get("/index.php/apps/openregister/api/objects/{$objectUuid}");
        
        $this->assertEquals(404, $verifyDeleteResponse->getStatusCode(), 'Deleted object should return 404');
        echo "✅ Object deletion verified\n";

        // Step 12: Delete second object.
        echo "\n📝 Step 12: Deleting second object...\n";
        $delete2Response = $this->client->delete("/index.php/apps/openregister/api/objects/{$object2Data['uuid']}");
        
        $this->assertEquals(204, $delete2Response->getStatusCode(), 'Second object delete should return 204');
        echo "✅ Second object deleted successfully\n";

        // Step 13: Delete schema (should work now that objects are deleted).
        echo "\n📝 Step 13: Deleting schema...\n";
        $deleteSchemaResponse = $this->client->delete("/index.php/apps/openregister/api/schemas/{$this->schemaId}");
        
        $this->assertEquals(204, $deleteSchemaResponse->getStatusCode(), 'Schema delete should return 204');
        echo "✅ Schema deleted successfully\n";
        $this->schemaId = null; // Prevent double cleanup.

        // Step 14: Delete register (should work now that schema is deleted).
        echo "\n📝 Step 14: Deleting register...\n";
        $deleteRegisterResponse = $this->client->delete("/index.php/apps/openregister/api/registers/{$this->registerId}");
        
        $this->assertEquals(204, $deleteRegisterResponse->getStatusCode(), 'Register delete should return 204');
        echo "✅ Register deleted successfully\n";
        $this->registerId = null; // Prevent double cleanup.

        echo "\n🎉 Complete CRUD lifecycle test passed!\n";
    }

    /**
     * Test cascade protection: cannot delete register with schemas.
     *
     * @return void
     */
    public function testCannotDeleteRegisterWithSchemas(): void
    {
        echo "\n📝 Testing cascade protection: register with schemas...\n";

        // Create register.
        $registerResponse = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'slug' => "cascade-register-{$this->testId}",
                'title' => 'Cascade Test Register',
            ]
        ]);
        $registerData = json_decode($registerResponse->getBody()->getContents(), true);
        $this->registerId = $registerData['id'];

        // Create schema.
        $schemaResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $this->registerId,
                'slug' => "cascade-schema-{$this->testId}",
                'title' => 'Cascade Test Schema',
                'properties' => ['name' => ['type' => 'string']],
            ]
        ]);
        $schemaData = json_decode($schemaResponse->getBody()->getContents(), true);
        $this->schemaId = $schemaData['id'];

        // Try to delete register (should fail).
        $deleteResponse = $this->client->delete("/index.php/apps/openregister/api/registers/{$this->registerId}");
        
        $this->assertNotEquals(204, $deleteResponse->getStatusCode(), 'Should not be able to delete register with schemas');
        echo "✅ Cascade protection works: cannot delete register with schemas\n";
    }

    /**
     * Test cascade protection: cannot delete schema with objects.
     *
     * @return void
     */
    public function testCannotDeleteSchemaWithObjects(): void
    {
        echo "\n📝 Testing cascade protection: schema with objects...\n";

        // Create register.
        $registerResponse = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'slug' => "cascade2-register-{$this->testId}",
                'title' => 'Cascade Test Register 2',
            ]
        ]);
        $registerData = json_decode($registerResponse->getBody()->getContents(), true);
        $this->registerId = $registerData['id'];

        // Create schema.
        $schemaResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $this->registerId,
                'slug' => "cascade2-schema-{$this->testId}",
                'title' => 'Cascade Test Schema 2',
                'properties' => ['name' => ['type' => 'string']],
            ]
        ]);
        $schemaData = json_decode($schemaResponse->getBody()->getContents(), true);
        $this->schemaId = $schemaData['id'];

        // Create object.
        $objectResponse = $this->client->post("/index.php/apps/openregister/api/objects/{$schemaData['slug']}", [
            'json' => ['name' => 'Cascade Test Object'],
        ]);
        $objectData = json_decode($objectResponse->getBody()->getContents(), true);
        $this->objectUuids[] = $objectData['uuid'];

        // Try to delete schema (should fail).
        $deleteResponse = $this->client->delete("/index.php/apps/openregister/api/schemas/{$this->schemaId}");
        
        $this->assertNotEquals(204, $deleteResponse->getStatusCode(), 'Should not be able to delete schema with objects');
        echo "✅ Cascade protection works: cannot delete schema with objects\n";
    }
}

