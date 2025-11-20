<?php
/**
 * SchemaCompositionIntegrationTest
 *
 * Integration tests for JSON Schema composition patterns (allOf, oneOf, anyOf)
 * and Liskov Substitution Principle validation in OpenRegister.
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
use PHPUnit\Framework\TestCase;

/**
 * Schema Composition Integration Tests
 * 
 * Test Groups:
 * 
 * GROUP 1: allOf Tests (Multiple Inheritance) - Tests 1-10
 * - Single parent allOf
 * - Multiple parent allOf
 * - Property merging from multiple parents
 * - Required fields merging
 * - Multi-level allOf chains
 * - Delta storage for allOf
 * 
 * GROUP 2: oneOf Tests (Mutually Exclusive) - Tests 11-15
 * - Basic oneOf validation
 * - Multiple schema options
 * - Non-merging behavior verification
 * - Discriminated union patterns
 * 
 * GROUP 3: anyOf Tests (Flexible Composition) - Tests 16-20
 * - Basic anyOf validation
 * - Multiple matches allowed
 * - Non-merging behavior verification
 * 
 * GROUP 4: Liskov Substitution Validation - Tests 21-35
 * - Type change prevention
 * - MinLength relaxation prevention
 * - MaxLength relaxation prevention
 * - Enum expansion prevention
 * - Format removal prevention
 * - Pattern change prevention
 * - Metadata override allowance
 * - Adding stricter constraints (allowed)
 * 
 * GROUP 5: Mixed Patterns & Edge Cases - Tests 36-40
 * - Circular reference prevention for allOf
 * - Parent schema not found errors
 */
class SchemaCompositionIntegrationTest extends TestCase
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
     * Set up test environment before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->registerSlug = 'test-composition-' . uniqid();
        
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
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->createdSchemaIds as $schemaId) {
            try {
                $this->client->delete("/index.php/apps/openregister/api/schemas/{$schemaId}");
            } catch (\Exception $e) {
                // Ignore cleanup errors.
            }
        }

        if ($this->registerId !== null) {
            try {
                $this->client->delete("/index.php/apps/openregister/api/registers/{$this->registerId}");
            } catch (\Exception $e) {
                // Ignore cleanup errors.
            }
        }

        parent::tearDown();
    }//end tearDown()


    /**
     * Clean up any existing test register
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
                    if (isset($register['slug']) && str_starts_with($register['slug'], 'test-composition-')) {
                        $this->client->delete("/index.php/apps/openregister/api/registers/{$register['id']}");
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignore errors.
        }
    }//end cleanupTestRegister()


    /**
     * Create test register
     *
     * @return void
     */
    private function createTestRegister(): void
    {
        $response = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'title' => 'Composition Test Register',
                'slug' => $this->registerSlug,
                'description' => 'Test register for schema composition',
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $register = json_decode((string) $response->getBody(), true);
        $this->registerId = $register['id'];
    }//end createTestRegister()


    /**
     * GROUP 1: allOf TESTS
     */

    /**
     * Test 1: Basic allOf with single parent
     *
     * @return void
     */
    public function testBasicAllOfSingleParent(): void
    {
        // Create parent schema.
        $parent = $this->createSchema([
            'title' => 'Person',
            'properties' => [
                'firstName' => ['type' => 'string'],
                'lastName' => ['type' => 'string'],
            ],
            'required' => ['firstName'],
        ]);

        // Create child with allOf.
        $child = $this->createSchema([
            'title' => 'Employee',
            'allOf' => [$parent['id']],
            'properties' => [
                'employeeId' => ['type' => 'string'],
            ],
            'required' => ['employeeId'],
        ]);

        // Verify properties merged.
        $this->assertArrayHasKey('firstName', $child['properties']);
        $this->assertArrayHasKey('lastName', $child['properties']);
        $this->assertArrayHasKey('employeeId', $child['properties']);
        
        // Verify required merged.
        $this->assertContains('firstName', $child['required']);
        $this->assertContains('employeeId', $child['required']);
    }//end testBasicAllOfSingleParent()


    /**
     * Test 2: allOf with multiple parents (multiple inheritance)
     *
     * @return void
     */
    public function testAllOfMultipleParents(): void
    {
        // Create first parent: Contactable.
        $contactable = $this->createSchema([
            'title' => 'Contactable',
            'properties' => [
                'email' => ['type' => 'string', 'format' => 'email'],
                'phone' => ['type' => 'string'],
            ],
            'required' => ['email'],
        ]);

        // Create second parent: Addressable.
        $addressable = $this->createSchema([
            'title' => 'Addressable',
            'properties' => [
                'street' => ['type' => 'string'],
                'city' => ['type' => 'string'],
            ],
            'required' => ['city'],
        ]);

        // Create child inheriting from both.
        $child = $this->createSchema([
            'title' => 'Customer',
            'allOf' => [$contactable['id'], $addressable['id']],
            'properties' => [
                'customerNumber' => ['type' => 'string'],
            ],
            'required' => ['customerNumber'],
        ]);

        // Verify all properties from both parents.
        $this->assertArrayHasKey('email', $child['properties']);
        $this->assertArrayHasKey('phone', $child['properties']);
        $this->assertArrayHasKey('street', $child['properties']);
        $this->assertArrayHasKey('city', $child['properties']);
        $this->assertArrayHasKey('customerNumber', $child['properties']);
        
        // Verify all required fields.
        $this->assertContains('email', $child['required']);
        $this->assertContains('city', $child['required']);
        $this->assertContains('customerNumber', $child['required']);
    }//end testAllOfMultipleParents()


    /**
     * Test 3: allOf with stricter validation (Liskov compliant)
     *
     * @return void
     */
    public function testAllOfStricterValidation(): void
    {
        // Parent with basic validation.
        $parent = $this->createSchema([
            'title' => 'BaseString',
            'properties' => [
                'field' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 100,
                ],
            ],
        ]);

        // Child adds stricter constraints (should succeed).
        $child = $this->createSchema([
            'title' => 'StrictString',
            'allOf' => [$parent['id']],
            'properties' => [
                'field' => [
                    'minLength' => 5,  // Higher minimum (more restrictive)
                    'maxLength' => 50,  // Lower maximum (more restrictive)
                ],
            ],
        ]);

        // Should be created successfully.
        $this->assertEquals('StrictString', $child['title']);
        $this->assertEquals(5, $child['properties']['field']['minLength']);
        $this->assertEquals(50, $child['properties']['field']['maxLength']);
    }//end testAllOfStricterValidation()


    /**
     * Test 4: allOf metadata override (allowed)
     *
     * @return void
     */
    public function testAllOfMetadataOverride(): void
    {
        // Parent with metadata.
        $parent = $this->createSchema([
            'title' => 'BaseEntity',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'title' => 'Name',
                    'description' => 'Entity name',
                    'minLength' => 2,
                ],
            ],
        ]);

        // Child overrides metadata (should succeed).
        $child = $this->createSchema([
            'title' => 'SpecialEntity',
            'allOf' => [$parent['id']],
            'properties' => [
                'name' => [
                    'title' => 'Special Name',  // Metadata override
                    'description' => 'Special entity name',  // Metadata override
                    'minLength' => 3,  // Can add stricter constraint
                ],
            ],
        ]);

        $this->assertEquals('Special Name', $child['properties']['name']['title']);
        $this->assertEquals('Special entity name', $child['properties']['name']['description']);
        $this->assertEquals(3, $child['properties']['name']['minLength']);
    }//end testAllOfMetadataOverride()


    /**
     * Test 5: allOf multi-level chain
     *
     * @return void
     */
    public function testAllOfMultiLevelChain(): void
    {
        // Base schema.
        $base = $this->createSchema([
            'title' => 'Base',
            'properties' => [
                'baseField' => ['type' => 'string'],
            ],
        ]);

        // Middle composes base.
        $middle = $this->createSchema([
            'title' => 'Middle',
            'allOf' => [$base['id']],
            'properties' => [
                'middleField' => ['type' => 'string'],
            ],
        ]);

        // Final composes middle.
        $final = $this->createSchema([
            'title' => 'Final',
            'allOf' => [$middle['id']],
            'properties' => [
                'finalField' => ['type' => 'string'],
            ],
        ]);

        // Should have all three properties.
        $this->assertArrayHasKey('baseField', $final['properties']);
        $this->assertArrayHasKey('middleField', $final['properties']);
        $this->assertArrayHasKey('finalField', $final['properties']);
    }//end testAllOfMultiLevelChain()


    /**
     * GROUP 2: LISKOV SUBSTITUTION VALIDATION TESTS
     */

    /**
     * Test 21: Type change prevention
     *
     * @return void
     */
    public function testLiskovTypeChangeRejected(): void
    {
        $parent = $this->createSchema([
            'title' => 'TypeParent',
            'properties' => [
                'field' => ['type' => 'string'],
            ],
        ]);

        // Try to change type (should fail).
        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'title' => 'TypeChild',
                'allOf' => [$parent['id']],
                'properties' => [
                    'field' => ['type' => 'number'],  // Type change - FORBIDDEN
                ],
            ],
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }//end testLiskovTypeChangeRejected()


    /**
     * Test 22: MinLength relaxation prevention
     *
     * @return void
     */
    public function testLiskovMinLengthRelaxationRejected(): void
    {
        $parent = $this->createSchema([
            'title' => 'MinLengthParent',
            'properties' => [
                'field' => [
                    'type' => 'string',
                    'minLength' => 5,
                ],
            ],
        ]);

        // Try to decrease minLength (should fail).
        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'title' => 'MinLengthChild',
                'allOf' => [$parent['id']],
                'properties' => [
                    'field' => [
                        'minLength' => 2,  // Relaxes constraint - FORBIDDEN
                    ],
                ],
            ],
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }//end testLiskovMinLengthRelaxationRejected()


    /**
     * Test 23: MaxLength relaxation prevention
     *
     * @return void
     */
    public function testLiskovMaxLengthRelaxationRejected(): void
    {
        $parent = $this->createSchema([
            'title' => 'MaxLengthParent',
            'properties' => [
                'field' => [
                    'type' => 'string',
                    'maxLength' => 50,
                ],
            ],
        ]);

        // Try to increase maxLength (should fail).
        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'title' => 'MaxLengthChild',
                'allOf' => [$parent['id']],
                'properties' => [
                    'field' => [
                        'maxLength' => 100,  // Relaxes constraint - FORBIDDEN
                    ],
                ],
            ],
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }//end testLiskovMaxLengthRelaxationRejected()


    /**
     * Test 24: Enum expansion prevention
     *
     * @return void
     */
    public function testLiskovEnumExpansionRejected(): void
    {
        $parent = $this->createSchema([
            'title' => 'EnumParent',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['draft', 'published'],
                ],
            ],
        ]);

        // Try to add enum values (should fail).
        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'title' => 'EnumChild',
                'allOf' => [$parent['id']],
                'properties' => [
                    'status' => [
                        'enum' => ['draft', 'published', 'archived'],  // Adds value - FORBIDDEN
                    ],
                ],
            ],
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }//end testLiskovEnumExpansionRejected()


    /**
     * Test 25: Enum restriction allowed
     *
     * @return void
     */
    public function testLiskovEnumRestrictionAllowed(): void
    {
        $parent = $this->createSchema([
            'title' => 'EnumParent2',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['draft', 'published', 'archived'],
                ],
            ],
        ]);

        // Restrict enum values (should succeed).
        $child = $this->createSchema([
            'title' => 'EnumChild2',
            'allOf' => [$parent['id']],
            'properties' => [
                'status' => [
                    'enum' => ['draft', 'published'],  // Removes 'archived' - ALLOWED
                ],
            ],
        ]);

        $this->assertCount(2, $child['properties']['status']['enum']);
    }//end testLiskovEnumRestrictionAllowed()


    /**
     * Test 26: Format change prevention
     *
     * @return void
     */
    public function testLiskovFormatChangeRejected(): void
    {
        $parent = $this->createSchema([
            'title' => 'FormatParent',
            'properties' => [
                'field' => [
                    'type' => 'string',
                    'format' => 'email',
                ],
            ],
        ]);

        // Try to change format (should fail).
        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'title' => 'FormatChild',
                'allOf' => [$parent['id']],
                'properties' => [
                    'field' => [
                        'format' => 'uri',  // Changes format - FORBIDDEN
                    ],
                ],
            ],
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }//end testLiskovFormatChangeRejected()


    /**
     * Helper method to create schema
     *
     * @param array $schemaData Schema data
     *
     * @return array Created schema
     */
    private function createSchema(array $schemaData): array
    {
        if (!isset($schemaData['slug'])) {
            $schemaData['slug'] = strtolower(str_replace(' ', '-', $schemaData['title'] ?? 'schema')) . '-' . uniqid();
        }

        $response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => $schemaData,
        ]);

        $this->assertEquals(201, $response->getStatusCode(), 'Failed to create schema: ' . $response->getBody());
        $schema = json_decode((string) $response->getBody(), true);
        $this->createdSchemaIds[] = $schema['id'];
        
        return $schema;
    }//end createSchema()


}//end class

