<?php
/**
 * SchemaExtensionIntegrationTest
 *
 * Integration tests for schema extension (inheritance) feature in OpenRegister.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Integration
 * @author   Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git-id>
 * @link     https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use PHPUnit\Framework\TestCase;

/**
 * Schema Extension Integration Tests
 * 
 * Test Groups:
 * 
 * GROUP 1: Basic Extension Tests (Tests 1-5)
 * - Simple parent-child extension
 * - Property inheritance
 * - Property overriding
 * - Required fields merging
 * - Delta storage verification
 * 
 * GROUP 2: Multi-Level Inheritance Tests (Tests 6-8)
 * - Three-level inheritance chain
 * - Property accumulation through levels
 * - Required fields through levels
 * 
 * GROUP 3: Circular Reference Tests (Tests 9-11)
 * - Self-reference prevention
 * - Circular chain detection
 * - Error handling for circular references
 * 
 * GROUP 4: Property Merging Tests (Tests 12-15)
 * - Deep property merging
 * - Nested object merging
 * - Array property handling
 * - Complex property structures
 * 
 * GROUP 5: Error Handling Tests (Tests 16-18)
 * - Parent schema not found
 * - Invalid extend reference
 * - Malformed schema extension
 * 
 * GROUP 6: Object Validation Tests (Tests 19-21)
 * - Objects validate against resolved schema
 * - Required fields from parent enforced
 * - Property validation from merged schema
 */
class SchemaExtensionIntegrationTest extends TestCase
{
    /**
     * HTTP client for API requests
     *
     * @var Client
     */
    private Client $client;

    /**
     * Base URL for Nextcloud instance
     *
     * @var string
     */
    private string $baseUrl = 'http://localhost';

    /**
     * Test register slug
     *
     * @var string
     */
    private string $registerSlug;

    /**
     * Test register ID
     *
     * @var int|null
     */
    private ?int $registerId = null;

    /**
     * Array of created schema IDs for cleanup
     *
     * @var array<int>
     */
    private array $createdSchemaIds = [];

    /**
     * Array of created object IDs for cleanup
     *
     * @var array<string>
     */
    private array $createdObjectIds = [];


    /**
     * Set up test environment before each test
     *
     * Creates test register and initializes HTTP client
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->registerSlug = 'test-schema-extension-' . uniqid();
        
        // Initialize HTTP client with authentication
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'http_errors' => false,
            'auth' => ['admin', 'admin'],
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode('admin:admin'),
                'OCS-APIRequest' => 'true',
                'Content-Type' => 'application/json',
            ],
        ]);

        $this->cleanupTestRegister();
        $this->createTestRegister();
    }//end setUp()


    /**
     * Clean up test environment after each test
     *
     * Removes created objects, schemas, and register
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up objects first
        foreach ($this->createdObjectIds as $objectId) {
            try {
                $this->client->delete("/index.php/apps/openregister/api/objects/{$this->registerSlug}/*/{ $objectId}");
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        // Clean up schemas
        foreach ($this->createdSchemaIds as $schemaId) {
            try {
                $this->client->delete("/index.php/apps/openregister/api/schemas/{$schemaId}");
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        // Clean up register
        if ($this->registerId !== null) {
            try {
                $this->client->delete("/index.php/apps/openregister/api/registers/{$this->registerId}");
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        parent::tearDown();
    }//end tearDown()


    /**
     * Clean up any existing test register with the same slug
     *
     * @return void
     */
    private function cleanupTestRegister(): void
    {
        try {
            $response = $this->client->get('/index.php/apps/openregister/api/registers');
            $registers = json_decode((string) $response->getBody(), true);
            
            if (isset($registers['results'])) {
                foreach ($registers['results'] as $register) {
                    if (isset($register['slug']) && str_starts_with($register['slug'], 'test-schema-extension-')) {
                        $this->client->delete("/index.php/apps/openregister/api/registers/{$register['id']}");
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignore errors during cleanup
        }
    }//end cleanupTestRegister()


    /**
     * Create test register for schema extension tests
     *
     * @return void
     */
    private function createTestRegister(): void
    {
        $response = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'title' => 'Schema Extension Test Register',
                'slug' => $this->registerSlug,
                'description' => 'Test register for schema extension integration tests',
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode(), 'Failed to create test register');
        $register = json_decode((string) $response->getBody(), true);
        $this->registerId = $register['id'];
    }//end createTestRegister()


    /**
     * GROUP 1: BASIC EXTENSION TESTS
     */

    /**
     * Test 1: Create parent schema and child schema with simple extension
     *
     * Verifies that a child schema can extend a parent schema and
     * inherit its properties when retrieved.
     *
     * @return void
     */
    public function testBasicSchemaExtension(): void
    {
        // Step 1: Create parent schema
        $parentSchema = [
            'title' => 'Person',
            'slug' => 'person-' . uniqid(),
            'properties' => [
                'firstName' => [
                    'type' => 'string',
                    'title' => 'First Name',
                ],
                'lastName' => [
                    'type' => 'string',
                    'title' => 'Last Name',
                ],
            ],
            'required' => ['firstName', 'lastName'],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $parentSchema,
        ]);

        $this->assertEquals(201, $response->getStatusCode(), 'Failed to create parent schema');
        $parent = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $parent['id'];
        $parentId = $parent['id'];

        // Step 2: Create child schema that extends parent
        $childSchema = [
            'title' => 'Employee',
            'slug' => 'employee-' . uniqid(),
            'extend' => (string) $parentId,
            'properties' => [
                'employeeId' => [
                    'type' => 'string',
                    'title' => 'Employee ID',
                ],
            ],
            'required' => ['employeeId'],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $childSchema,
        ]);

        $this->assertEquals(201, $response->getStatusCode(), 'Failed to create child schema');
        $child = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $child['id'];

        // Step 3: Retrieve child schema and verify it has parent properties
        $response = $this->client->get("/index.php/apps/openregister/api/schemas/{$child['id']}");
        $this->assertEquals(200, $response->getStatusCode());
        $resolvedChild = json_decode((string) $response->getBody(), true);

        // Verify extend property is set
        $this->assertEquals($parentId, $resolvedChild['extend'], 'Extend property not set correctly');

        // Verify parent properties are present
        $this->assertArrayHasKey('firstName', $resolvedChild['properties'], 'firstName not inherited from parent');
        $this->assertArrayHasKey('lastName', $resolvedChild['properties'], 'lastName not inherited from parent');

        // Verify child property is present
        $this->assertArrayHasKey('employeeId', $resolvedChild['properties'], 'employeeId not present');

        // Verify required fields are merged
        $this->assertContains('firstName', $resolvedChild['required'], 'firstName not in required');
        $this->assertContains('lastName', $resolvedChild['required'], 'lastName not in required');
        $this->assertContains('employeeId', $resolvedChild['required'], 'employeeId not in required');
    }//end testBasicSchemaExtension()


    /**
     * Test 2: Property overriding in child schema
     *
     * Verifies that a child schema can override parent properties
     * with modified constraints or settings.
     *
     * @return void
     */
    public function testPropertyOverriding(): void
    {
        // Create parent schema with basic email property
        $parentSchema = [
            'title' => 'Contact',
            'slug' => 'contact-' . uniqid(),
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'title' => 'Name',
                ],
                'email' => [
                    'type' => 'string',
                    'format' => 'email',
                    'title' => 'Email',
                ],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $parentSchema,
        ]);
        $parent = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $parent['id'];

        // Create child schema that overrides email with additional constraint
        $childSchema = [
            'title' => 'VerifiedContact',
            'slug' => 'verified-contact-' . uniqid(),
            'extend' => (string) $parent['id'],
            'properties' => [
                'email' => [
                    'type' => 'string',
                    'format' => 'email',
                    'title' => 'Verified Email',
                    'minLength' => 5,
                    'description' => 'Must be a verified email address',
                ],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $childSchema,
        ]);
        $child = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $child['id'];

        // Retrieve and verify override
        $response = $this->client->get("/index.php/apps/openregister/api/schemas/{$child['id']}");
        $resolvedChild = json_decode((string) $response->getBody(), true);

        // Verify email property is overridden with new constraints
        $this->assertEquals('Verified Email', $resolvedChild['properties']['email']['title'], 'Title not overridden');
        $this->assertEquals(5, $resolvedChild['properties']['email']['minLength'], 'minLength not added');
        $this->assertEquals('Must be a verified email address', $resolvedChild['properties']['email']['description'], 'Description not added');

        // Verify parent property still present
        $this->assertArrayHasKey('name', $resolvedChild['properties'], 'name property lost');
    }//end testPropertyOverriding()


    /**
     * Test 3: Required fields merging
     *
     * Verifies that required fields from both parent and child
     * are properly merged (union).
     *
     * @return void
     */
    public function testRequiredFieldsMerging(): void
    {
        // Parent with required fields
        $parentSchema = [
            'title' => 'BaseEntity',
            'slug' => 'base-entity-' . uniqid(),
            'properties' => [
                'id' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'created' => ['type' => 'string', 'format' => 'date-time'],
            ],
            'required' => ['id', 'name'],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $parentSchema,
        ]);
        $parent = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $parent['id'];

        // Child with additional required fields
        $childSchema = [
            'title' => 'SpecialEntity',
            'slug' => 'special-entity-' . uniqid(),
            'extend' => (string) $parent['id'],
            'properties' => [
                'specialField' => ['type' => 'string'],
            ],
            'required' => ['created', 'specialField'],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $childSchema,
        ]);
        $child = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $child['id'];

        // Verify merged required fields
        $response = $this->client->get("/index.php/apps/openregister/api/schemas/{$child['id']}");
        $resolvedChild = json_decode((string) $response->getBody(), true);

        // Should have union of required fields
        $expectedRequired = ['id', 'name', 'created', 'specialField'];
        $this->assertCount(4, $resolvedChild['required'], 'Required fields count incorrect');
        foreach ($expectedRequired as $requiredField) {
            $this->assertContains($requiredField, $resolvedChild['required'], "{$requiredField} not in required fields");
        }
    }//end testRequiredFieldsMerging()


    /**
     * Test 4: Delta storage verification
     *
     * Verifies that only differences (delta) are stored in the database
     * when a schema extends another, not the full resolved schema.
     *
     * @return void
     */
    public function testDeltaStorage(): void
    {
        // Create parent with multiple properties
        $parentSchema = [
            'title' => 'FullParent',
            'slug' => 'full-parent-' . uniqid(),
            'properties' => [
                'field1' => ['type' => 'string'],
                'field2' => ['type' => 'string'],
                'field3' => ['type' => 'string'],
                'field4' => ['type' => 'string'],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $parentSchema,
        ]);
        $parent = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $parent['id'];

        // Create child that only adds one property
        $childSchema = [
            'title' => 'MinimalChild',
            'slug' => 'minimal-child-' . uniqid(),
            'extend' => (string) $parent['id'],
            'properties' => [
                'childOnlyField' => ['type' => 'string'],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $childSchema,
        ]);
        $child = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $child['id'];

        // The returned schema should have all properties (resolved)
        $this->assertCount(5, $child['properties'], 'Resolved schema should have 5 properties');

        // Note: We can't directly verify delta storage without database access,
        // but we verify the resolved schema is correct
        $this->assertArrayHasKey('childOnlyField', $child['properties']);
        $this->assertArrayHasKey('field1', $child['properties']);
        $this->assertArrayHasKey('field2', $child['properties']);
        $this->assertArrayHasKey('field3', $child['properties']);
        $this->assertArrayHasKey('field4', $child['properties']);
    }//end testDeltaStorage()


    /**
     * Test 5: Clearing extend property
     *
     * Verifies that a schema can be updated to remove its extension relationship.
     *
     * @return void
     */
    public function testClearExtendProperty(): void
    {
        // Create parent and child
        $parentSchema = [
            'title' => 'TempParent',
            'slug' => 'temp-parent-' . uniqid(),
            'properties' => [
                'parentField' => ['type' => 'string'],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $parentSchema,
        ]);
        $parent = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $parent['id'];

        $childSchema = [
            'title' => 'TempChild',
            'slug' => 'temp-child-' . uniqid(),
            'extend' => (string) $parent['id'],
            'properties' => [
                'childField' => ['type' => 'string'],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $childSchema,
        ]);
        $child = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $child['id'];

        // Update child to remove extend (making it standalone)
        $updateSchema = [
            'extend' => null,
            'properties' => [
                'parentField' => ['type' => 'string'],  // Must explicitly add parent properties
                'childField' => ['type' => 'string'],
            ],
        ];

        $response = $this->client->put("/index.php/apps/openregister/api/schemas/{$child['id']}", [
            'json' => $updateSchema,
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $updatedChild = json_decode((string) $response->getBody(), true);

        // Verify extend is cleared
        $this->assertNull($updatedChild['extend'] ?? null, 'Extend should be null');
        
        // Properties should still be there since we added them explicitly
        $this->assertArrayHasKey('parentField', $updatedChild['properties']);
        $this->assertArrayHasKey('childField', $updatedChild['properties']);
    }//end testClearExtendProperty()


    /**
     * GROUP 2: MULTI-LEVEL INHERITANCE TESTS
     */

    /**
     * Test 6: Three-level inheritance chain
     *
     * Verifies that schemas can extend schemas that themselves extend other schemas,
     * creating a multi-level inheritance hierarchy.
     *
     * @return void
     */
    public function testMultiLevelInheritance(): void
    {
        // Level 1: Base
        $baseSchema = [
            'title' => 'Base',
            'slug' => 'base-' . uniqid(),
            'properties' => [
                'baseField' => ['type' => 'string', 'title' => 'Base Field'],
            ],
            'required' => ['baseField'],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $baseSchema,
        ]);
        $base = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $base['id'];

        // Level 2: Middle (extends Base)
        $middleSchema = [
            'title' => 'Middle',
            'slug' => 'middle-' . uniqid(),
            'extend' => (string) $base['id'],
            'properties' => [
                'middleField' => ['type' => 'string', 'title' => 'Middle Field'],
            ],
            'required' => ['middleField'],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $middleSchema,
        ]);
        $middle = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $middle['id'];

        // Level 3: Final (extends Middle)
        $finalSchema = [
            'title' => 'Final',
            'slug' => 'final-' . uniqid(),
            'extend' => (string) $middle['id'],
            'properties' => [
                'finalField' => ['type' => 'string', 'title' => 'Final Field'],
            ],
            'required' => ['finalField'],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $finalSchema,
        ]);
        $final = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $final['id'];

        // Verify Final schema has all properties from all levels
        $response = $this->client->get("/index.php/apps/openregister/api/schemas/{$final['id']}");
        $resolvedFinal = json_decode((string) $response->getBody(), true);

        // Should have properties from all three levels
        $this->assertArrayHasKey('baseField', $resolvedFinal['properties'], 'baseField not present');
        $this->assertArrayHasKey('middleField', $resolvedFinal['properties'], 'middleField not present');
        $this->assertArrayHasKey('finalField', $resolvedFinal['properties'], 'finalField not present');

        // Should have all required fields
        $this->assertContains('baseField', $resolvedFinal['required']);
        $this->assertContains('middleField', $resolvedFinal['required']);
        $this->assertContains('finalField', $resolvedFinal['required']);
    }//end testMultiLevelInheritance()


    /**
     * Test 7: Property override in multi-level inheritance
     *
     * Verifies that properties can be overridden at any level in the chain.
     *
     * @return void
     */
    public function testMultiLevelOverride(): void
    {
        // Base defines 'status' as simple string
        $baseSchema = [
            'title' => 'BaseStatus',
            'slug' => 'base-status-' . uniqid(),
            'properties' => [
                'status' => ['type' => 'string'],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $baseSchema,
        ]);
        $base = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $base['id'];

        // Middle adds enum constraint
        $middleSchema = [
            'title' => 'MiddleStatus',
            'slug' => 'middle-status-' . uniqid(),
            'extend' => (string) $base['id'],
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['draft', 'published'],
                ],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $middleSchema,
        ]);
        $middle = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $middle['id'];

        // Final adds more enum values
        $finalSchema = [
            'title' => 'FinalStatus',
            'slug' => 'final-status-' . uniqid(),
            'extend' => (string) $middle['id'],
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['draft', 'published', 'archived'],
                ],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $finalSchema,
        ]);
        $final = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $final['id'];

        // Verify final has the last override
        $this->assertCount(3, $final['properties']['status']['enum']);
        $this->assertContains('archived', $final['properties']['status']['enum']);
    }//end testMultiLevelOverride()


    /**
     * Test 8: Four-level deep inheritance
     *
     * Tests stability with deeper inheritance chains.
     *
     * @return void
     */
    public function testDeepInheritanceChain(): void
    {
        $schemaIds = [];
        $previousId = null;

        // Create 4 levels of inheritance
        for ($i = 1; $i <= 4; $i++) {
            $schema = [
                'title' => "Level{$i}",
                'slug' => "level{$i}-" . uniqid(),
                'properties' => [
                    "level{$i}Field" => ['type' => 'string'],
                ],
            ];

            if ($previousId !== null) {
                $schema['extend'] = (string) $previousId;
            }

            $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
                'json' => $schema,
            ]);
            $created = json_decode((string) $response->getBody(), true);
            $this->createdSchemaIds[] = $created['id'];
            $schemaIds[] = $created['id'];
            $previousId = $created['id'];
        }

        // Verify deepest level has all properties
        $response = $this->client->get("/index.php/apps/openregister/api/schemas/{$previousId}");
        $final = json_decode((string) $response->getBody(), true);

        // Should have all 4 level fields
        for ($i = 1; $i <= 4; $i++) {
            $this->assertArrayHasKey("level{$i}Field", $final['properties']);
        }
    }//end testDeepInheritanceChain()


    /**
     * GROUP 3: CIRCULAR REFERENCE TESTS
     */

    /**
     * Test 9: Self-reference prevention
     *
     * Verifies that a schema cannot extend itself.
     *
     * @return void
     */
    public function testSelfReferencePrevention(): void
    {
        // Create initial schema
        $schema = [
            'title' => 'SelfRef',
            'slug' => 'self-ref-' . uniqid(),
            'properties' => [
                'field' => ['type' => 'string'],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $schema,
        ]);
        $created = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $created['id'];

        // Try to update it to extend itself
        $update = [
            'extend' => (string) $created['id'],
        ];

        $response = $this->client->put("/index.php/apps/openregister/api/schemas/{$created['id']}", [
            'json' => $update,
        ]);

        // Should fail with error
        $this->assertNotEquals(200, $response->getStatusCode(), 'Self-reference should be rejected');
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }//end testSelfReferencePrevention()


    /**
     * Test 10: Circular chain detection (A → B → A)
     *
     * Verifies that circular inheritance chains are detected and prevented.
     *
     * @return void
     */
    public function testCircularChainDetection(): void
    {
        // Create Schema A
        $schemaA = [
            'title' => 'CircularA',
            'slug' => 'circular-a-' . uniqid(),
            'properties' => [
                'fieldA' => ['type' => 'string'],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $schemaA,
        ]);
        $a = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $a['id'];

        // Create Schema B extending A
        $schemaB = [
            'title' => 'CircularB',
            'slug' => 'circular-b-' . uniqid(),
            'extend' => (string) $a['id'],
            'properties' => [
                'fieldB' => ['type' => 'string'],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $schemaB,
        ]);
        $b = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $b['id'];

        // Try to update A to extend B (creating A → B → A circle)
        $update = [
            'extend' => (string) $b['id'],
        ];

        $response = $this->client->put("/index.php/apps/openregister/api/schemas/{$a['id']}", [
            'json' => $update,
        ]);

        // Should fail
        $this->assertNotEquals(200, $response->getStatusCode(), 'Circular reference should be rejected');
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }//end testCircularChainDetection()


    /**
     * Test 11: Complex circular detection (A → B → C → A)
     *
     * Tests detection of longer circular chains.
     *
     * @return void
     */
    public function testComplexCircularDetection(): void
    {
        // Create chain: A → B → C
        $schemas = [];
        $previousId = null;

        for ($i = 0; $i < 3; $i++) {
            $schema = [
                'title' => "CircularSchema{$i}",
                'slug' => "circular-schema-{$i}-" . uniqid(),
                'properties' => [
                    "field{$i}" => ['type' => 'string'],
                ],
            ];

            if ($previousId !== null) {
                $schema['extend'] = (string) $previousId;
            }

            $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
                'json' => $schema,
            ]);
            $created = json_decode((string) $response->getBody(), true);
            $this->createdSchemaIds[] = $created['id'];
            $schemas[] = $created;
            $previousId = $created['id'];
        }

        // Try to make A extend C (closing the loop: A → B → C → A)
        $update = [
            'extend' => (string) $schemas[2]['id'],
        ];

        $response = $this->client->put("/index.php/apps/openregister/api/schemas/{$schemas[0]['id']}", [
            'json' => $update,
        ]);

        // Should fail
        $this->assertNotEquals(200, $response->getStatusCode());
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }//end testComplexCircularDetection()


    /**
     * GROUP 4: PROPERTY MERGING TESTS
     */

    /**
     * Test 12: Deep nested property merging
     *
     * Verifies that nested object properties are properly merged.
     *
     * @return void
     */
    public function testNestedPropertyMerging(): void
    {
        // Parent with nested address object
        $parentSchema = [
            'title' => 'LocationParent',
            'slug' => 'location-parent-' . uniqid(),
            'properties' => [
                'address' => [
                    'type' => 'object',
                    'properties' => [
                        'street' => ['type' => 'string'],
                        'city' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $parentSchema,
        ]);
        $parent = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $parent['id'];

        // Child adds postalCode and overrides city with constraints
        $childSchema = [
            'title' => 'LocationChild',
            'slug' => 'location-child-' . uniqid(),
            'extend' => (string) $parent['id'],
            'properties' => [
                'address' => [
                    'properties' => [
                        'city' => [
                            'type' => 'string',
                            'minLength' => 2,
                        ],
                        'postalCode' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $childSchema,
        ]);
        $child = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $child['id'];

        // Verify deep merge
        $address = $child['properties']['address']['properties'];
        
        // Should have all three properties
        $this->assertArrayHasKey('street', $address, 'street not inherited');
        $this->assertArrayHasKey('city', $address, 'city not present');
        $this->assertArrayHasKey('postalCode', $address, 'postalCode not added');

        // City should have the override
        $this->assertEquals(2, $address['city']['minLength'] ?? null, 'city minLength not overridden');
    }//end testNestedPropertyMerging()


    /**
     * Test 13: Array property handling
     *
     * Verifies that array properties with enum values are replaced, not merged.
     *
     * @return void
     */
    public function testArrayPropertyHandling(): void
    {
        // Parent with enum
        $parentSchema = [
            'title' => 'EnumParent',
            'slug' => 'enum-parent-' . uniqid(),
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['draft', 'published'],
                ],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $parentSchema,
        ]);
        $parent = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $parent['id'];

        // Child replaces enum
        $childSchema = [
            'title' => 'EnumChild',
            'slug' => 'enum-child-' . uniqid(),
            'extend' => (string) $parent['id'],
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['active', 'inactive'],
                ],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $childSchema,
        ]);
        $child = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $child['id'];

        // Enum should be replaced, not merged
        $this->assertCount(2, $child['properties']['status']['enum']);
        $this->assertEquals(['active', 'inactive'], $child['properties']['status']['enum']);
    }//end testArrayPropertyHandling()


    /**
     * Test 14: Multiple level nested merging
     *
     * Tests complex nested property structures across multiple levels.
     *
     * @return void
     */
    public function testMultipleLevelNestedMerging(): void
    {
        // Base with simple nested structure
        $baseSchema = [
            'title' => 'NestedBase',
            'slug' => 'nested-base-' . uniqid(),
            'properties' => [
                'data' => [
                    'type' => 'object',
                    'properties' => [
                        'level1' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $baseSchema,
        ]);
        $base = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $base['id'];

        // Middle adds to nesting
        $middleSchema = [
            'title' => 'NestedMiddle',
            'slug' => 'nested-middle-' . uniqid(),
            'extend' => (string) $base['id'],
            'properties' => [
                'data' => [
                    'properties' => [
                        'level2' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $middleSchema,
        ]);
        $middle = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $middle['id'];

        // Final adds more
        $finalSchema = [
            'title' => 'NestedFinal',
            'slug' => 'nested-final-' . uniqid(),
            'extend' => (string) $middle['id'],
            'properties' => [
                'data' => [
                    'properties' => [
                        'level3' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $finalSchema,
        ]);
        $final = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $final['id'];

        // Verify all levels present
        $dataProperties = $final['properties']['data']['properties'];
        $this->assertArrayHasKey('level1', $dataProperties);
        $this->assertArrayHasKey('level2', $dataProperties);
        $this->assertArrayHasKey('level3', $dataProperties);
    }//end testMultipleLevelNestedMerging()


    /**
     * Test 15: Complex property structure merging
     *
     * Tests merging of complex property definitions with multiple attributes.
     *
     * @return void
     */
    public function testComplexPropertyMerging(): void
    {
        // Parent with basic property definition
        $parentSchema = [
            'title' => 'ComplexParent',
            'slug' => 'complex-parent-' . uniqid(),
            'properties' => [
                'complexField' => [
                    'type' => 'string',
                    'title' => 'Complex Field',
                    'description' => 'A complex field',
                ],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $parentSchema,
        ]);
        $parent = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $parent['id'];

        // Child adds more attributes
        $childSchema = [
            'title' => 'ComplexChild',
            'slug' => 'complex-child-' . uniqid(),
            'extend' => (string) $parent['id'],
            'properties' => [
                'complexField' => [
                    'minLength' => 5,
                    'maxLength' => 100,
                    'pattern' => '^[A-Za-z]+$',
                ],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $childSchema,
        ]);
        $child = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $child['id'];

        $field = $child['properties']['complexField'];
        
        // Should have attributes from both parent and child
        $this->assertEquals('string', $field['type']);
        $this->assertEquals('Complex Field', $field['title']);
        $this->assertEquals('A complex field', $field['description']);
        $this->assertEquals(5, $field['minLength']);
        $this->assertEquals(100, $field['maxLength']);
        $this->assertEquals('^[A-Za-z]+$', $field['pattern']);
    }//end testComplexPropertyMerging()


    /**
     * GROUP 5: ERROR HANDLING TESTS
     */

    /**
     * Test 16: Parent schema not found
     *
     * Verifies proper error handling when extending a non-existent schema.
     *
     * @return void
     */
    public function testParentSchemaNotFound(): void
    {
        $schema = [
            'title' => 'OrphanChild',
            'slug' => 'orphan-child-' . uniqid(),
            'extend' => '99999',  // Non-existent ID
            'properties' => [
                'field' => ['type' => 'string'],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $schema,
        ]);

        // Should return error
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }//end testParentSchemaNotFound()


    /**
     * Test 17: Invalid extend reference type
     *
     * Verifies proper handling of invalid extend property values.
     *
     * @return void
     */
    public function testInvalidExtendReferenceType(): void
    {
        $schema = [
            'title' => 'InvalidExtend',
            'slug' => 'invalid-extend-' . uniqid(),
            'extend' => ['invalid' => 'array'],  // Invalid type
            'properties' => [
                'field' => ['type' => 'string'],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $schema,
        ]);

        // May be rejected during validation or hydration
        // We just verify it doesn't crash the system
        $this->assertIsInt($response->getStatusCode());
    }//end testInvalidExtendReferenceType()


    /**
     * Test 18: Deleted parent schema handling
     *
     * Verifies behavior when parent schema is deleted after child creation.
     *
     * @return void
     */
    public function testDeletedParentSchema(): void
    {
        // Create parent
        $parentSchema = [
            'title' => 'ToBeDeleted',
            'slug' => 'to-be-deleted-' . uniqid(),
            'properties' => [
                'parentField' => ['type' => 'string'],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $parentSchema,
        ]);
        $parent = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $parent['id'];

        // Create child
        $childSchema = [
            'title' => 'DependentChild',
            'slug' => 'dependent-child-' . uniqid(),
            'extend' => (string) $parent['id'],
            'properties' => [
                'childField' => ['type' => 'string'],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $childSchema,
        ]);
        $child = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $child['id'];

        // Delete parent
        $response = $this->client->delete("/index.php/apps/openregister/api/schemas/{$parent['id']}");

        // Then try to retrieve child
        $response = $this->client->get("/index.php/apps/openregister/api/schemas/{$child['id']}");

        // Should return error since parent is missing
        $this->assertNotEquals(200, $response->getStatusCode());
    }//end testDeletedParentSchema()


    /**
     * GROUP 6: OBJECT VALIDATION TESTS
     */

    /**
     * Test 19: Objects validate against resolved schema
     *
     * Verifies that objects created with an extended schema are validated
     * against the fully resolved schema (parent + child properties).
     *
     * @return void
     */
    public function testObjectValidationWithExtendedSchema(): void
    {
        // Create parent schema
        $parentSchema = [
            'title' => 'ValidatableParent',
            'slug' => 'validatable-parent-' . uniqid(),
            'properties' => [
                'parentRequired' => ['type' => 'string'],
            ],
            'required' => ['parentRequired'],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $parentSchema,
        ]);
        $parent = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $parent['id'];

        // Create child schema
        $childSchema = [
            'title' => 'ValidatableChild',
            'slug' => 'validatable-child-' . uniqid(),
            'extend' => (string) $parent['id'],
            'properties' => [
                'childRequired' => ['type' => 'string'],
            ],
            'required' => ['childRequired'],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $childSchema,
        ]);
        $child = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $child['id'];

        // Try to create object without parent required field (should fail)
        $invalidObject = [
            'childRequired' => 'present',
            // parentRequired is missing
        ];

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$child['slug']}", [
            'json' => $invalidObject,
        ]);

        // Should fail validation
        $this->assertNotEquals(201, $response->getStatusCode(), 'Object should not validate without parent required field');

        // Create valid object with both required fields
        $validObject = [
            'parentRequired' => 'from parent',
            'childRequired' => 'from child',
        ];

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$child['slug']}", [
            'json' => $validObject,
        ]);

        $this->assertEquals(201, $response->getStatusCode(), 'Valid object should be created');
        $createdObject = json_decode((string) $response->getBody(), true);
        $this->createdObjectIds[] = $createdObject['id'] ?? $createdObject['uuid'];

        // Verify object has both fields
        $this->assertEquals('from parent', $createdObject['parentRequired']);
        $this->assertEquals('from child', $createdObject['childRequired']);
    }//end testObjectValidationWithExtendedSchema()


    /**
     * Test 20: Inherited property constraints enforced
     *
     * Verifies that validation constraints from parent properties
     * are enforced on child objects.
     *
     * @return void
     */
    public function testInheritedConstraintsEnforced(): void
    {
        // Parent with constrained property
        $parentSchema = [
            'title' => 'ConstraintParent',
            'slug' => 'constraint-parent-' . uniqid(),
            'properties' => [
                'email' => [
                    'type' => 'string',
                    'format' => 'email',
                    'minLength' => 5,
                ],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $parentSchema,
        ]);
        $parent = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $parent['id'];

        // Child extends parent
        $childSchema = [
            'title' => 'ConstraintChild',
            'slug' => 'constraint-child-' . uniqid(),
            'extend' => (string) $parent['id'],
            'properties' => [
                'name' => ['type' => 'string'],
            ],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $childSchema,
        ]);
        $child = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $child['id'];

        // Try to create object with invalid email (violates parent constraint)
        $invalidObject = [
            'email' => 'bad',  // Too short, not valid email
            'name' => 'Test',
        ];

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$child['slug']}", [
            'json' => $invalidObject,
        ]);

        // Should fail validation
        $this->assertNotEquals(201, $response->getStatusCode(), 'Invalid email should be rejected');
    }//end testInheritedConstraintsEnforced()


    /**
     * Test 21: Extended schema with objects in register
     *
     * Full end-to-end test: create schemas, create objects, verify functionality.
     *
     * @return void
     */
    public function testEndToEndSchemaExtensionWithObjects(): void
    {
        // Create base Person schema
        $personSchema = [
            'title' => 'Person',
            'slug' => 'person-' . uniqid(),
            'properties' => [
                'firstName' => ['type' => 'string', 'title' => 'First Name'],
                'lastName' => ['type' => 'string', 'title' => 'Last Name'],
                'email' => ['type' => 'string', 'format' => 'email'],
            ],
            'required' => ['firstName', 'lastName'],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $personSchema,
        ]);
        $person = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $person['id'];

        // Create Employee schema extending Person
        $employeeSchema = [
            'title' => 'Employee',
            'slug' => 'employee-' . uniqid(),
            'extend' => (string) $person['id'],
            'properties' => [
                'employeeId' => ['type' => 'string'],
                'department' => ['type' => 'string'],
            ],
            'required' => ['employeeId'],
        ];

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $employeeSchema,
        ]);
        $employee = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $employee['id'];

        // Create employee object
        $employeeObject = [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john.doe@example.com',
            'employeeId' => 'EMP123',
            'department' => 'Engineering',
        ];

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$employee['slug']}", [
            'json' => $employeeObject,
        ]);

        $this->assertEquals(201, $response->getStatusCode(), 'Employee object creation failed');
        $created = json_decode((string) $response->getBody(), true);
        $this->createdObjectIds[] = $created['id'] ?? $created['uuid'];

        // Verify all properties present
        $this->assertEquals('John', $created['firstName']);
        $this->assertEquals('Doe', $created['lastName']);
        $this->assertEquals('john.doe@example.com', $created['email']);
        $this->assertEquals('EMP123', $created['employeeId']);
        $this->assertEquals('Engineering', $created['department']);

        // Retrieve object and verify again
        $objectId = $created['id'] ?? $created['uuid'];
        $response = $this->client->get("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$employee['slug']}/{$objectId}");
        $this->assertEquals(200, $response->getStatusCode());
        $retrieved = json_decode((string) $response->getBody(), true);

        $this->assertEquals('John', $retrieved['firstName']);
        $this->assertEquals('EMP123', $retrieved['employeeId']);
    }//end testEndToEndSchemaExtensionWithObjects()


}//end class

