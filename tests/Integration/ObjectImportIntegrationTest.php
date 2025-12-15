<?php

namespace OCA\OpenRegister\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use PHPUnit\Framework\TestCase;

/**
 * Object Import Integration Tests
 * 
 * Tests the complete import flow:
 * - CSV import
 * - Excel import  
 * - Import with validation
 * - Import with schema auto-detection
 * - Import error handling
 */
class ObjectImportIntegrationTest extends TestCase
{
    private Client $client;
    private string $baseUrl = 'http://localhost';
    private string $registerSlug;
    private string $schemaSlug;
    private ?int $registerId = null;
    private ?int $schemaId = null;
    private array $createdObjectIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerSlug = 'test-import-' . uniqid();
        $this->schemaSlug = 'test-schema-' . uniqid();
        
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'http_errors' => false,
            'timeout' => 30.0,
            'allow_redirects' => true,
            'auth' => ['admin', 'admin'],
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode('admin:admin'),
                'OCS-APIRequest' => 'true',
            ],
        ]);

        $this->createTestRegisterAndSchema();
    }

    protected function tearDown(): void
    {
        // Clean up created objects
        foreach ($this->createdObjectIds as $id) {
            try {
                $this->client->delete("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->schemaSlug}/{$id}");
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        // Clean up schema
        if ($this->schemaId !== null) {
            try {
                $this->client->delete("/index.php/apps/openregister/api/schemas/{$this->schemaId}");
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
    }

    private function createTestRegisterAndSchema(): void
    {
        // Create register
        $registerResponse = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'slug' => $this->registerSlug,
                'title' => 'Test Import Register',
                'description' => 'Register for import integration testing',
            ]
        ]);

        $this->assertEquals(201, $registerResponse->getStatusCode());
        
        $registerData = json_decode($registerResponse->getBody()->getContents(), true);
        $this->assertIsArray($registerData);
        $this->assertArrayHasKey('id', $registerData);
        $this->registerId = $registerData['id'];

        // Create schema with properties for import
        $schemaResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'slug' => $this->schemaSlug,
                'title' => 'Test Import Schema',
                'description' => 'Schema for import testing',
                'register' => $this->registerId,
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Object name',
                        'required' => true,
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Object description',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Object status',
                        'enum' => ['active', 'inactive', 'pending'],
                    ],
                    'email' => [
                        'type' => 'string',
                        'format' => 'email',
                        'description' => 'Contact email',
                    ],
                ],
            ]
        ]);

        $this->assertEquals(201, $schemaResponse->getStatusCode());
        
        $schemaData = json_decode($schemaResponse->getBody()->getContents(), true);
        $this->assertIsArray($schemaData);
        $this->assertArrayHasKey('id', $schemaData);
        $this->schemaId = $schemaData['id'];
    }

    public function testCsvImportBasic(): void
    {
        // Create CSV file content
        $csvContent = "name,description,status,email\n";
        $csvContent .= "Test Object 1,First test object,active,test1@example.com\n";
        $csvContent .= "Test Object 2,Second test object,pending,test2@example.com\n";
        $csvContent .= "Test Object 3,Third test object,inactive,test3@example.com\n";

        // Create temporary file
        $tmpFile = tmpfile();
        fwrite($tmpFile, $csvContent);
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        // Import CSV
        $response = $this->client->post("/index.php/apps/openregister/api/objects/import/{$this->registerId}", [
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => fopen($tmpPath, 'r'),
                    'filename' => 'test_import.csv',
                ],
                [
                    'name' => 'schema',
                    'contents' => (string)$this->schemaId,
                ],
                [
                    'name' => 'validation',
                    'contents' => 'false',
                ],
            ],
        ]);

        fclose($tmpFile);

        // Debug: print response on failure
        if ($response->getStatusCode() !== 200) {
            echo "\n\nDEBUG - Import Response Status: " . $response->getStatusCode() . "\n";
            echo "DEBUG - Import Response Body: " . $response->getBody()->getContents() . "\n\n";
            $response->getBody()->rewind(); // Rewind for assertion
        }

        // Verify response
        $this->assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getBody()->getContents(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertEquals('Import successful', $responseData['message']);
        $this->assertArrayHasKey('summary', $responseData);

        // Verify objects were created
        $listResponse = $this->client->get("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->schemaSlug}");
        $this->assertEquals(200, $listResponse->getStatusCode());
        
        $listData = json_decode($listResponse->getBody()->getContents(), true);
        $this->assertIsArray($listData);
        $this->assertArrayHasKey('results', $listData);
        $this->assertGreaterThanOrEqual(3, count($listData['results']));

        // Track created objects for cleanup
        foreach ($listData['results'] as $object) {
            if (isset($object['id'])) {
                $this->createdObjectIds[] = $object['id'];
            }
        }
    }

    public function testCsvImportWithAutoSchemaDetection(): void
    {
        // Create CSV file content
        $csvContent = "name,description,status\n";
        $csvContent .= "Auto Schema Test,Testing auto schema detection,active\n";

        // Create temporary file
        $tmpFile = tmpfile();
        fwrite($tmpFile, $csvContent);
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        // Import CSV without specifying schema (should auto-detect first schema)
        $response = $this->client->post("/index.php/apps/openregister/api/objects/import/{$this->registerId}", [
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => fopen($tmpPath, 'r'),
                    'filename' => 'test_auto_schema.csv',
                ],
            ],
        ]);

        fclose($tmpFile);

        // Verify response
        $this->assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getBody()->getContents(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertEquals('Import successful', $responseData['message']);
    }

    public function testCsvImportWithValidation(): void
    {
        // Create CSV with invalid email
        $csvContent = "name,description,status,email\n";
        $csvContent .= "Valid Object,Valid object,active,valid@example.com\n";
        $csvContent .= "Invalid Object,Invalid email,active,not-an-email\n";

        // Create temporary file
        $tmpFile = tmpfile();
        fwrite($tmpFile, $csvContent);
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        // Import CSV with validation enabled
        $response = $this->client->post("/index.php/apps/openregister/api/objects/import/{$this->registerId}", [
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => fopen($tmpPath, 'r'),
                    'filename' => 'test_validation.csv',
                ],
                [
                    'name' => 'schema',
                    'contents' => (string)$this->schemaId,
                ],
                [
                    'name' => 'validation',
                    'contents' => 'true',
                ],
            ],
        ]);

        fclose($tmpFile);

        // Response should still be 200 but summary should show errors
        $this->assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getBody()->getContents(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('summary', $responseData);
    }

    public function testImportWithNoFile(): void
    {
        // Try to import without a file
        $response = $this->client->post("/index.php/apps/openregister/api/objects/import/{$this->registerId}");

        // Should return 400
        $this->assertEquals(400, $response->getStatusCode());
        
        $responseData = json_decode($response->getBody()->getContents(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('No file uploaded', $responseData['error']);
    }

    public function testImportWithUnsupportedFileType(): void
    {
        // Create a text file (unsupported format)
        $txtContent = "This is not a valid import file";
        
        $tmpFile = tmpfile();
        fwrite($tmpFile, $txtContent);
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $response = $this->client->post("/index.php/apps/openregister/api/objects/import/{$this->registerId}", [
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => fopen($tmpPath, 'r'),
                    'filename' => 'test.txt',
                ],
            ],
        ]);

        fclose($tmpFile);

        // Should return 500 with error about unsupported file type
        $this->assertEquals(500, $response->getStatusCode());
        
        $responseData = json_decode($response->getBody()->getContents(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('Unsupported file type', $responseData['error']);
    }

    public function testImportWithRbacParameters(): void
    {
        // Create CSV file content
        $csvContent = "name,description,status\n";
        $csvContent .= "RBAC Test Object,Testing RBAC parameters,active\n";

        // Create temporary file
        $tmpFile = tmpfile();
        fwrite($tmpFile, $csvContent);
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        // Import with RBAC parameters
        $response = $this->client->post("/index.php/apps/openregister/api/objects/import/{$this->registerId}", [
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => fopen($tmpPath, 'r'),
                    'filename' => 'test_rbac.csv',
                ],
                [
                    'name' => 'schema',
                    'contents' => (string)$this->schemaId,
                ],
                [
                    'name' => 'rbac',
                    'contents' => 'true',
                ],
                [
                    'name' => 'multi',
                    'contents' => 'true',
                ],
            ],
        ]);

        fclose($tmpFile);

        // Verify response
        $this->assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getBody()->getContents(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertEquals('Import successful', $responseData['message']);
    }
}

