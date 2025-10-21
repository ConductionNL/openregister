<?php
/**
 * Integration Tests for Array Filtering with AND/OR Logic
 *
 * Tests the array filtering functionality to ensure proper AND/OR logic is applied
 * for both metadata fields and object properties.
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
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use PHPUnit\Framework\TestCase;

/**
 * Integration Tests for Array Filtering with AND/OR Logic
 *
 * These tests verify that array filters properly support AND and OR logic
 * for both metadata fields (register, schema) and object properties (colours, tags, etc.).
 */
class ArrayFilteringTest extends TestCase
{
    /**
     * @var Client HTTP client for API requests
     */
    private Client $client;

    /**
     * @var string Base URL for Nextcloud container
     */
    private string $baseUrl = 'http://localhost';

    /**
     * @var array<int> IDs of created registers for cleanup
     */
    private array $createdRegisterIds = [];

    /**
     * @var array<int> IDs of created schemas for cleanup
     */
    private array $createdSchemaIds = [];

    /**
     * @var array<string> IDs of created objects for cleanup
     */
    private array $createdObjectIds = [];


    /**
     * Set up test environment before each test.
     *
     * Initializes HTTP client with authentication and headers.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

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

    }//end setUp()


    /**
     * Clean up test data after each test.
     *
     * Removes all created objects, schemas, and registers.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up objects first
        foreach ($this->createdObjectIds as $id) {
            try {
                $this->client->delete("/index.php/apps/openregister/api/objects/{$id}");
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        // Clean up schemas
        foreach ($this->createdSchemaIds as $id) {
            try {
                $this->client->delete("/index.php/apps/openregister/api/schemas/{$id}");
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        // Clean up registers
        foreach ($this->createdRegisterIds as $id) {
            try {
                $this->client->delete("/index.php/apps/openregister/api/registers/{$id}");
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        parent::tearDown();

    }//end tearDown()


    /**
     * Test 1: Default array behavior uses AND logic for metadata fields.
     *
     * Verifies that register[]=1&register[]=2 returns zero results (AND logic)
     * since an object cannot have multiple register values.
     *
     * @return void
     */
    public function testMetadataArrayFilterDefaultAndLogic(): void
    {
        // Create two registers
        $reg1Response = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'slug' => 'test-reg-1-' . uniqid(),
                'title' => 'Test Register 1',
            ]
        ]);
        $this->assertEquals(201, $reg1Response->getStatusCode());
        $reg1 = json_decode($reg1Response->getBody(), true);
        $this->createdRegisterIds[] = $reg1['id'];

        $reg2Response = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'slug' => 'test-reg-2-' . uniqid(),
                'title' => 'Test Register 2',
            ]
        ]);
        $this->assertEquals(201, $reg2Response->getStatusCode());
        $reg2 = json_decode($reg2Response->getBody(), true);
        $this->createdRegisterIds[] = $reg2['id'];

        // Create schemas in both registers
        $schema1Response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $reg1['id'],
                'slug' => 'test-schema-1-' . uniqid(),
                'title' => 'Test Schema 1',
                'properties' => ['title' => ['type' => 'string']],
            ]
        ]);
        $this->assertEquals(201, $schema1Response->getStatusCode());
        $schema1 = json_decode($schema1Response->getBody(), true);
        $this->createdSchemaIds[] = $schema1['id'];

        $schema2Response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $reg2['id'],
                'slug' => 'test-schema-2-' . uniqid(),
                'title' => 'Test Schema 2',
                'properties' => ['title' => ['type' => 'string']],
            ]
        ]);
        $this->assertEquals(201, $schema2Response->getStatusCode());
        $schema2 = json_decode($schema2Response->getBody(), true);
        $this->createdSchemaIds[] = $schema2['id'];

        // Create objects in each register
        $obj1Response = $this->client->post("/index.php/apps/openregister/api/objects/{$reg1['slug']}/{$schema1['slug']}", [
            'json' => ['title' => 'Object in Register 1']
        ]);
        $this->assertEquals(201, $obj1Response->getStatusCode());
        $obj1 = json_decode($obj1Response->getBody(), true);
        $this->createdObjectIds[] = $obj1['id'];

        $obj2Response = $this->client->post("/index.php/apps/openregister/api/objects/{$reg2['slug']}/{$schema2['slug']}", [
            'json' => ['title' => 'Object in Register 2']
        ]);
        $this->assertEquals(201, $obj2Response->getStatusCode());
        $obj2 = json_decode($obj2Response->getBody(), true);
        $this->createdObjectIds[] = $obj2['id'];

        // Test: Filter with register[]=1&register[]=2 should return zero results (AND logic)
        $filterResponse = $this->client->get('/index.php/apps/openregister/api/objects', [
            'query' => [
                '@self' => [
                    'register' => [$reg1['id'], $reg2['id']]
                ]
            ]
        ]);
        
        $this->assertEquals(200, $filterResponse->getStatusCode());
        $result = json_decode($filterResponse->getBody(), true);
        
        // With AND logic, no object can be in BOTH registers, so results should be empty
        $this->assertEquals(0, $result['total'], 'AND logic on metadata should return zero results when filtering for multiple values');

    }//end testMetadataArrayFilterDefaultAndLogic()


    /**
     * Test 2: Explicit [or] operator uses OR logic for metadata fields.
     *
     * Verifies that register[or]=1,2 returns objects from EITHER register.
     *
     * @return void
     */
    public function testMetadataArrayFilterExplicitOrLogic(): void
    {
        // Create two registers
        $reg1Response = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'slug' => 'test-reg-or-1-' . uniqid(),
                'title' => 'Test Register OR 1',
            ]
        ]);
        $this->assertEquals(201, $reg1Response->getStatusCode());
        $reg1 = json_decode($reg1Response->getBody(), true);
        $this->createdRegisterIds[] = $reg1['id'];

        $reg2Response = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'slug' => 'test-reg-or-2-' . uniqid(),
                'title' => 'Test Register OR 2',
            ]
        ]);
        $this->assertEquals(201, $reg2Response->getStatusCode());
        $reg2 = json_decode($reg2Response->getBody(), true);
        $this->createdRegisterIds[] = $reg2['id'];

        // Create schemas
        $schema1Response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $reg1['id'],
                'slug' => 'test-schema-or-1-' . uniqid(),
                'title' => 'Test Schema OR 1',
                'properties' => ['title' => ['type' => 'string']],
            ]
        ]);
        $this->assertEquals(201, $schema1Response->getStatusCode());
        $schema1 = json_decode($schema1Response->getBody(), true);
        $this->createdSchemaIds[] = $schema1['id'];

        $schema2Response = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $reg2['id'],
                'slug' => 'test-schema-or-2-' . uniqid(),
                'title' => 'Test Schema OR 2',
                'properties' => ['title' => ['type' => 'string']],
            ]
        ]);
        $this->assertEquals(201, $schema2Response->getStatusCode());
        $schema2 = json_decode($schema2Response->getBody(), true);
        $this->createdSchemaIds[] = $schema2['id'];

        // Create objects in each register
        $obj1Response = $this->client->post("/index.php/apps/openregister/api/objects/{$reg1['slug']}/{$schema1['slug']}", [
            'json' => ['title' => 'Object in Register OR 1']
        ]);
        $this->assertEquals(201, $obj1Response->getStatusCode());
        $obj1 = json_decode($obj1Response->getBody(), true);
        $this->createdObjectIds[] = $obj1['id'];

        $obj2Response = $this->client->post("/index.php/apps/openregister/api/objects/{$reg2['slug']}/{$schema2['slug']}", [
            'json' => ['title' => 'Object in Register OR 2']
        ]);
        $this->assertEquals(201, $obj2Response->getStatusCode());
        $obj2 = json_decode($obj2Response->getBody(), true);
        $this->createdObjectIds[] = $obj2['id'];

        // Test: Filter with register[or]=1,2 should return objects from BOTH registers (OR logic)
        $filterResponse = $this->client->get('/index.php/apps/openregister/api/objects', [
            'query' => [
                '@self' => [
                    'register' => [
                        'or' => $reg1['id'] . ',' . $reg2['id']
                    ]
                ]
            ]
        ]);
        
        $this->assertEquals(200, $filterResponse->getStatusCode());
        $result = json_decode($filterResponse->getBody(), true);
        
        // With OR logic, objects from EITHER register should be returned
        $this->assertGreaterThanOrEqual(2, $result['total'], 'OR logic should return objects from either register');
        
        $returnedIds = array_column($result['results'], 'id');
        $this->assertContains($obj1['id'], $returnedIds, 'Should include object from register 1');
        $this->assertContains($obj2['id'], $returnedIds, 'Should include object from register 2');

    }//end testMetadataArrayFilterExplicitOrLogic()


    /**
     * Test 3: Default array behavior uses AND logic for object array properties.
     *
     * Verifies that colours[]=red&colours[]=blue returns only objects with BOTH colors.
     *
     * @return void
     */
    public function testObjectArrayPropertyDefaultAndLogic(): void
    {
        // Create register and schema
        $registerResponse = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'slug' => 'test-array-reg-' . uniqid(),
                'title' => 'Test Array Register',
            ]
        ]);
        $this->assertEquals(201, $registerResponse->getStatusCode());
        $register = json_decode($registerResponse->getBody(), true);
        $this->createdRegisterIds[] = $register['id'];

        $schemaResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $register['id'],
                'slug' => 'product-' . uniqid(),
                'title' => 'Product Schema',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'availableColours' => [
                        'type' => 'array',
                        'items' => ['type' => 'string']
                    ]
                ],
            ]
        ]);
        $this->assertEquals(201, $schemaResponse->getStatusCode());
        $schema = json_decode($schemaResponse->getBody(), true);
        $this->createdSchemaIds[] = $schema['id'];

        // Create objects with different colour combinations
        $obj1Response = $this->client->post("/index.php/apps/openregister/api/objects/{$register['slug']}/{$schema['slug']}", [
            'json' => [
                'title' => 'Product with Red and Blue',
                'availableColours' => ['red', 'blue']
            ]
        ]);
        $this->assertEquals(201, $obj1Response->getStatusCode());
        $obj1 = json_decode($obj1Response->getBody(), true);
        $this->createdObjectIds[] = $obj1['id'];

        $obj2Response = $this->client->post("/index.php/apps/openregister/api/objects/{$register['slug']}/{$schema['slug']}", [
            'json' => [
                'title' => 'Product with only Blue',
                'availableColours' => ['blue']
            ]
        ]);
        $this->assertEquals(201, $obj2Response->getStatusCode());
        $obj2 = json_decode($obj2Response->getBody(), true);
        $this->createdObjectIds[] = $obj2['id'];

        $obj3Response = $this->client->post("/index.php/apps/openregister/api/objects/{$register['slug']}/{$schema['slug']}", [
            'json' => [
                'title' => 'Product with Red, Blue, and Green',
                'availableColours' => ['red', 'blue', 'green']
            ]
        ]);
        $this->assertEquals(201, $obj3Response->getStatusCode());
        $obj3 = json_decode($obj3Response->getBody(), true);
        $this->createdObjectIds[] = $obj3['id'];

        // Test: Filter with availableColours[]=red&availableColours[]=blue (AND logic)
        // Should return only objects with BOTH red AND blue
        $filterResponse = $this->client->get('/index.php/apps/openregister/api/objects', [
            'query' => [
                'availableColours' => ['red', 'blue']
            ]
        ]);
        
        $this->assertEquals(200, $filterResponse->getStatusCode());
        $result = json_decode($filterResponse->getBody(), true);
        
        $returnedIds = array_column($result['results'], 'id');
        
        // Object 1 and 3 have BOTH red and blue
        $this->assertContains($obj1['id'], $returnedIds, 'Should include object with red and blue');
        $this->assertContains($obj3['id'], $returnedIds, 'Should include object with red, blue, and green');
        
        // Object 2 has only blue, NOT red
        $this->assertNotContains($obj2['id'], $returnedIds, 'Should NOT include object with only blue');

    }//end testObjectArrayPropertyDefaultAndLogic()


    /**
     * Test 4: Explicit [or] operator uses OR logic for object array properties.
     *
     * Verifies that colours[or]=red,blue returns objects with EITHER color.
     *
     * @return void
     */
    public function testObjectArrayPropertyExplicitOrLogic(): void
    {
        // Create register and schema
        $registerResponse = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'slug' => 'test-array-or-reg-' . uniqid(),
                'title' => 'Test Array OR Register',
            ]
        ]);
        $this->assertEquals(201, $registerResponse->getStatusCode());
        $register = json_decode($registerResponse->getBody(), true);
        $this->createdRegisterIds[] = $register['id'];

        $schemaResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $register['id'],
                'slug' => 'product-or-' . uniqid(),
                'title' => 'Product OR Schema',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'availableColours' => [
                        'type' => 'array',
                        'items' => ['type' => 'string']
                    ]
                ],
            ]
        ]);
        $this->assertEquals(201, $schemaResponse->getStatusCode());
        $schema = json_decode($schemaResponse->getBody(), true);
        $this->createdSchemaIds[] = $schema['id'];

        // Create objects with different colour combinations
        $obj1Response = $this->client->post("/index.php/apps/openregister/api/objects/{$register['slug']}/{$schema['slug']}", [
            'json' => [
                'title' => 'Product with Red only',
                'availableColours' => ['red']
            ]
        ]);
        $this->assertEquals(201, $obj1Response->getStatusCode());
        $obj1 = json_decode($obj1Response->getBody(), true);
        $this->createdObjectIds[] = $obj1['id'];

        $obj2Response = $this->client->post("/index.php/apps/openregister/api/objects/{$register['slug']}/{$schema['slug']}", [
            'json' => [
                'title' => 'Product with Blue only',
                'availableColours' => ['blue']
            ]
        ]);
        $this->assertEquals(201, $obj2Response->getStatusCode());
        $obj2 = json_decode($obj2Response->getBody(), true);
        $this->createdObjectIds[] = $obj2['id'];

        $obj3Response = $this->client->post("/index.php/apps/openregister/api/objects/{$register['slug']}/{$schema['slug']}", [
            'json' => [
                'title' => 'Product with Green only',
                'availableColours' => ['green']
            ]
        ]);
        $this->assertEquals(201, $obj3Response->getStatusCode());
        $obj3 = json_decode($obj3Response->getBody(), true);
        $this->createdObjectIds[] = $obj3['id'];

        // Test: Filter with availableColours[or]=red,blue (OR logic)
        // Should return objects with EITHER red OR blue (or both)
        $filterResponse = $this->client->get('/index.php/apps/openregister/api/objects', [
            'query' => [
                'availableColours' => [
                    'or' => 'red,blue'
                ]
            ]
        ]);
        
        $this->assertEquals(200, $filterResponse->getStatusCode());
        $result = json_decode($filterResponse->getBody(), true);
        
        $returnedIds = array_column($result['results'], 'id');
        
        // Objects with red or blue should be included
        $this->assertContains($obj1['id'], $returnedIds, 'Should include object with red');
        $this->assertContains($obj2['id'], $returnedIds, 'Should include object with blue');
        
        // Object with only green should NOT be included
        $this->assertNotContains($obj3['id'], $returnedIds, 'Should NOT include object with only green');

    }//end testObjectArrayPropertyExplicitOrLogic()


    /**
     * Test 5: Explicit [and] operator uses AND logic for object array properties.
     *
     * Verifies that colours[and]=red,blue works the same as colours[]=red&colours[]=blue.
     *
     * @return void
     */
    public function testObjectArrayPropertyExplicitAndLogic(): void
    {
        // Create register and schema
        $registerResponse = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'slug' => 'test-array-and-reg-' . uniqid(),
                'title' => 'Test Array AND Register',
            ]
        ]);
        $this->assertEquals(201, $registerResponse->getStatusCode());
        $register = json_decode($registerResponse->getBody(), true);
        $this->createdRegisterIds[] = $register['id'];

        $schemaResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $register['id'],
                'slug' => 'product-and-' . uniqid(),
                'title' => 'Product AND Schema',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'availableColours' => [
                        'type' => 'array',
                        'items' => ['type' => 'string']
                    ]
                ],
            ]
        ]);
        $this->assertEquals(201, $schemaResponse->getStatusCode());
        $schema = json_decode($schemaResponse->getBody(), true);
        $this->createdSchemaIds[] = $schema['id'];

        // Create objects with different colour combinations
        $obj1Response = $this->client->post("/index.php/apps/openregister/api/objects/{$register['slug']}/{$schema['slug']}", [
            'json' => [
                'title' => 'Product with Red and Blue',
                'availableColours' => ['red', 'blue']
            ]
        ]);
        $this->assertEquals(201, $obj1Response->getStatusCode());
        $obj1 = json_decode($obj1Response->getBody(), true);
        $this->createdObjectIds[] = $obj1['id'];

        $obj2Response = $this->client->post("/index.php/apps/openregister/api/objects/{$register['slug']}/{$schema['slug']}", [
            'json' => [
                'title' => 'Product with only Red',
                'availableColours' => ['red']
            ]
        ]);
        $this->assertEquals(201, $obj2Response->getStatusCode());
        $obj2 = json_decode($obj2Response->getBody(), true);
        $this->createdObjectIds[] = $obj2['id'];

        // Test: Filter with availableColours[and]=red,blue (explicit AND logic)
        // Should return only objects with BOTH red AND blue
        $filterResponse = $this->client->get('/index.php/apps/openregister/api/objects', [
            'query' => [
                'availableColours' => [
                    'and' => 'red,blue'
                ]
            ]
        ]);
        
        $this->assertEquals(200, $filterResponse->getStatusCode());
        $result = json_decode($filterResponse->getBody(), true);
        
        $returnedIds = array_column($result['results'], 'id');
        
        // Object 1 has BOTH red and blue
        $this->assertContains($obj1['id'], $returnedIds, 'Should include object with both red and blue');
        
        // Object 2 has only red, NOT blue
        $this->assertNotContains($obj2['id'], $returnedIds, 'Should NOT include object with only red');

    }//end testObjectArrayPropertyExplicitAndLogic()


}//end class


