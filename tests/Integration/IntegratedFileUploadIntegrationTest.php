<?php

namespace OCA\OpenRegister\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Integration tests for integrated file uploads using real API calls
 * 
 * These tests use Guzzle to make real HTTP requests to a running Nextcloud instance.
 * No mocking is involved - these tests verify the full stack.
 * 
 * Prerequisites:
 * - Nextcloud container must be running
 * - OpenRegister app must be enabled
 * - Admin credentials must be 'admin:admin'
 * 
 * Run with:
 * ./vendor/bin/phpunit tests/Integration/IntegratedFileUploadIntegrationTest.php
 */
class IntegratedFileUploadIntegrationTest extends TestCase
{
    /** @var Client */
    private $client;

    /** @var string */
    private $baseUrl;

    /** @var string */
    private $registerSlug = 'test-file-uploads';

    /** @var string */
    private $schemaSlug = 'document';

    /** @var array */
    private $createdObjectIds = [];

    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        parent::setUp();

        // According to global.mdc: API calls from inside container use localhost
        // These tests run inside the container via: docker exec ... phpunit
        $this->baseUrl = 'http://localhost';
        
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'auth' => ['admin', 'admin'],
            'http_errors' => false, // Don't throw on 4xx/5xx
            'verify' => false, // Skip SSL verification for local dev
        ]);

        // Clean up any existing test register
        $this->cleanupTestRegister();

        // Create test register and schemas
        $this->createTestRegisterAndSchemas();
    }

    /**
     * Clean up after tests
     */
    protected function tearDown(): void
    {
        // Delete created objects
        foreach ($this->createdObjectIds as $uuid) {
            $this->client->delete("/index.php/apps/openregister/api/registers/{$this->registerSlug}/schemas/{$this->schemaSlug}/objects/{$uuid}");
        }

        // Delete test register (this cascades to schemas and objects)
        $this->cleanupTestRegister();

        parent::tearDown();
    }

    /**
     * Create test register and schemas with different file configurations
     */
    private function createTestRegisterAndSchemas(): void
    {
        // Create register
        $registerResponse = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'slug' => $this->registerSlug,
                'title' => 'Test File Uploads Register',
                'description' => 'Register for testing integrated file uploads',
            ]
        ]);

        $this->assertEquals(201, $registerResponse->getStatusCode(), 
            'Failed to create test register: ' . $registerResponse->getBody());

        // Schema 1: Strict PDF only
        $schema1 = $this->client->post("/index.php/apps/openregister/api/registers/{$this->registerSlug}/schemas", [
            'json' => [
                'slug' => 'strict-pdf',
                'title' => 'Strict PDF Document',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'attachment' => [
                        'type' => 'file',
                        'allowedTypes' => ['application/pdf'],
                        'maxSize' => 5242880, // 5MB
                    ]
                ]
            ]
        ]);
        $this->assertEquals(201, $schema1->getStatusCode());

        // Schema 2: Multiple file types
        $schema2 = $this->client->post("/index.php/apps/openregister/api/registers/{$this->registerSlug}/schemas", [
            'json' => [
                'slug' => 'multi-type',
                'title' => 'Multi Type Document',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'document' => [
                        'type' => 'file',
                        'allowedTypes' => ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                        'maxSize' => 10485760, // 10MB
                    ],
                    'image' => [
                        'type' => 'file',
                        'allowedTypes' => ['image/jpeg', 'image/png'],
                        'maxSize' => 2097152, // 2MB
                    ]
                ]
            ]
        ]);
        $this->assertEquals(201, $schema2->getStatusCode());

        // Schema 3: Array of files
        $schema3 = $this->client->post("/index.php/apps/openregister/api/registers/{$this->registerSlug}/schemas", [
            'json' => [
                'slug' => 'gallery',
                'title' => 'Gallery with multiple images',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'images' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'file',
                            'allowedTypes' => ['image/jpeg', 'image/png', 'image/gif'],
                            'maxSize' => 5242880, // 5MB per image
                        ]
                    ]
                ]
            ]
        ]);
        $this->assertEquals(201, $schema3->getStatusCode());

        // Main schema for most tests
        $mainSchema = $this->client->post("/index.php/apps/openregister/api/registers/{$this->registerSlug}/schemas", [
            'json' => [
                'slug' => $this->schemaSlug,
                'title' => 'Document',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'attachment' => [
                        'type' => 'file',
                        'allowedTypes' => ['application/pdf', 'application/msword'],
                        'maxSize' => 10485760, // 10MB
                    ],
                    'thumbnail' => [
                        'type' => 'file',
                        'allowedTypes' => ['image/jpeg', 'image/png'],
                        'maxSize' => 2097152, // 2MB
                    ]
                ]
            ]
        ]);
        $this->assertEquals(201, $mainSchema->getStatusCode());
    }

    /**
     * Clean up test register
     */
    private function cleanupTestRegister(): void
    {
        $this->client->delete("/index.php/apps/openregister/api/registers/{$this->registerSlug}");
    }

    /**
     * Test 1: Multipart upload - Single PDF file
     */
    public function testMultipartUploadSinglePdf(): void
    {
        // Create a fake PDF
        $pdfContent = '%PDF-1.4 fake pdf content for testing';
        $tmpFile = tmpfile();
        fwrite($tmpFile, $pdfContent);
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $response = $this->client->post("/index.php/apps/openregister/api/registers/{$this->registerSlug}/schemas/{$this->schemaSlug}/objects", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'Test Document'],
                ['name' => 'attachment', 'contents' => fopen($tmpPath, 'r'), 'filename' => 'test.pdf', 'headers' => ['Content-Type' => 'application/pdf']],
            ]
        ]);

        $this->assertEquals(201, $response->getStatusCode(), 'Failed to create object: ' . $response->getBody());

        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('uuid', $data);
        $this->assertArrayHasKey('attachment', $data);
        $this->assertIsArray($data['attachment'], 'Attachment should be hydrated to file object');
        $this->assertArrayHasKey('id', $data['attachment']);
        $this->assertArrayHasKey('path', $data['attachment']);
        $this->assertArrayHasKey('type', $data['attachment']);
        $this->assertEquals('application/pdf', $data['attachment']['type']);

        $this->createdObjectIds[] = $data['uuid'];

        fclose($tmpFile);
    }

    /**
     * Test 2: Multipart upload - Multiple files
     */
    public function testMultipartUploadMultipleFiles(): void
    {
        // Create fake files
        $pdfContent = '%PDF-1.4 fake pdf';
        $jpegContent = "\xFF\xD8\xFF\xE0 fake jpeg"; // JPEG magic bytes

        $pdfTmp = tmpfile();
        $jpegTmp = tmpfile();
        fwrite($pdfTmp, $pdfContent);
        fwrite($jpegTmp, $jpegContent);
        $pdfPath = stream_get_meta_data($pdfTmp)['uri'];
        $jpegPath = stream_get_meta_data($jpegTmp)['uri'];

        $response = $this->client->post("/index.php/apps/openregister/api/registers/{$this->registerSlug}/schemas/{$this->schemaSlug}/objects", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'Multi-File Document'],
                ['name' => 'attachment', 'contents' => fopen($pdfPath, 'r'), 'filename' => 'doc.pdf', 'headers' => ['Content-Type' => 'application/pdf']],
                ['name' => 'thumbnail', 'contents' => fopen($jpegPath, 'r'), 'filename' => 'thumb.jpg', 'headers' => ['Content-Type' => 'image/jpeg']],
            ]
        ]);

        $this->assertEquals(201, $response->getStatusCode());

        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('attachment', $data);
        $this->assertArrayHasKey('thumbnail', $data);
        $this->assertEquals('application/pdf', $data['attachment']['type']);
        $this->assertEquals('image/jpeg', $data['thumbnail']['type']);

        $this->createdObjectIds[] = $data['uuid'];

        fclose($pdfTmp);
        fclose($jpegTmp);
    }

    /**
     * Test 3: Base64 upload with data URI
     */
    public function testBase64UploadWithDataUri(): void
    {
        $pdfContent = '%PDF-1.4 fake pdf';
        $base64 = base64_encode($pdfContent);
        $dataUri = "data:application/pdf;base64,{$base64}";

        $response = $this->client->post("/index.php/apps/openregister/api/registers/{$this->registerSlug}/schemas/{$this->schemaSlug}/objects", [
            'json' => [
                'title' => 'Base64 Document',
                'attachment' => $dataUri,
            ]
        ]);

        $this->assertEquals(201, $response->getStatusCode());

        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('attachment', $data);
        $this->assertEquals('application/pdf', $data['attachment']['type']);

        $this->createdObjectIds[] = $data['uuid'];
    }

    /**
     * Test 4: URL reference upload
     */
    public function testUrlReferenceUpload(): void
    {
        // Use a public test file URL (this is a real test - will download!)
        $testUrl = 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf';

        $response = $this->client->post("/index.php/apps/openregister/api/registers/{$this->registerSlug}/schemas/{$this->schemaSlug}/objects", [
            'json' => [
                'title' => 'URL Reference Document',
                'attachment' => $testUrl,
            ]
        ]);

        // This might fail if network is down, so we allow both success and failure
        if ($response->getStatusCode() === 201) {
            $data = json_decode($response->getBody(), true);
            $this->assertArrayHasKey('attachment', $data);
            $this->createdObjectIds[] = $data['uuid'];
        } else {
            $this->markTestSkipped('URL download failed (network issue or URL unreachable)');
        }
    }

    /**
     * Test 5: Array of files (multipart)
     */
    public function testArrayOfFilesMultipart(): void
    {
        // Create multiple images
        $images = [];
        for ($i = 0; $i < 3; $i++) {
            $content = "\xFF\xD8\xFF\xE0 fake jpeg {$i}";
            $tmp = tmpfile();
            fwrite($tmp, $content);
            $images[] = [
                'tmp' => $tmp,
                'path' => stream_get_meta_data($tmp)['uri']
            ];
        }

        $multipart = [
            ['name' => 'title', 'contents' => 'Gallery'],
        ];

        foreach ($images as $i => $image) {
            $multipart[] = [
                'name' => 'images[]', // Array notation
                'contents' => fopen($image['path'], 'r'),
                'filename' => "image{$i}.jpg",
                'headers' => ['Content-Type' => 'image/jpeg']
            ];
        }

        $response = $this->client->post("/index.php/apps/openregister/api/registers/{$this->registerSlug}/schemas/gallery/objects", [
            'multipart' => $multipart
        ]);

        $this->assertEquals(201, $response->getStatusCode());

        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('images', $data);
        $this->assertIsArray($data['images']);
        $this->assertCount(3, $data['images']);

        foreach ($data['images'] as $image) {
            $this->assertArrayHasKey('id', $image);
            $this->assertArrayHasKey('type', $image);
            $this->assertEquals('image/jpeg', $image['type']);
        }

        $this->createdObjectIds[] = $data['uuid'];

        // Cleanup temp files
        foreach ($images as $image) {
            fclose($image['tmp']);
        }
    }

    /**
     * Test 6: Array of files (base64 in JSON)
     */
    public function testArrayOfFilesBase64(): void
    {
        $images = [];
        for ($i = 0; $i < 2; $i++) {
            $content = "\xFF\xD8\xFF\xE0 fake jpeg {$i}";
            $base64 = base64_encode($content);
            $images[] = "data:image/jpeg;base64,{$base64}";
        }

        $response = $this->client->post("/index.php/apps/openregister/api/registers/{$this->registerSlug}/schemas/gallery/objects", [
            'json' => [
                'title' => 'Base64 Gallery',
                'images' => $images,
            ]
        ]);

        $this->assertEquals(201, $response->getStatusCode());

        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('images', $data);
        $this->assertCount(2, $data['images']);

        $this->createdObjectIds[] = $data['uuid'];
    }

    /**
     * Test 7: Validation - Wrong MIME type (should fail)
     */
    public function testValidationWrongMimeType(): void
    {
        // Try to upload JPEG to PDF-only field
        $jpegContent = "\xFF\xD8\xFF\xE0 fake jpeg";
        $base64 = base64_encode($jpegContent);
        $dataUri = "data:image/jpeg;base64,{$base64}";

        $response = $this->client->post("/index.php/apps/openregister/api/registers/{$this->registerSlug}/schemas/strict-pdf/objects", [
            'json' => [
                'title' => 'Wrong Type Test',
                'attachment' => $dataUri, // JPEG not allowed!
            ]
        ]);

        $this->assertEquals(400, $response->getStatusCode(), 'Should reject wrong MIME type');
        
        $data = json_decode($response->getBody(), true);
        $this->assertStringContainsString('invalid type', strtolower($data['message'] ?? ''));
    }

    /**
     * Test 8: Validation - File too large (should fail)
     */
    public function testValidationFileTooLarge(): void
    {
        // Create file larger than 5MB (strict-pdf schema limit)
        $largeContent = str_repeat('A', 6 * 1024 * 1024); // 6MB
        $base64 = base64_encode($largeContent);
        $dataUri = "data:application/pdf;base64,{$base64}";

        $response = $this->client->post("/index.php/apps/openregister/api/registers/{$this->registerSlug}/schemas/strict-pdf/objects", [
            'json' => [
                'title' => 'Too Large Test',
                'attachment' => $dataUri,
            ]
        ]);

        $this->assertEquals(400, $response->getStatusCode(), 'Should reject oversized file');
        
        $data = json_decode($response->getBody(), true);
        $this->assertStringContainsString('size', strtolower($data['message'] ?? ''));
    }

    /**
     * Test 9: Validation - Corrupted base64 (should fail)
     */
    public function testValidationCorruptedBase64(): void
    {
        $corruptedData = "data:application/pdf;base64,INVALID!!!BASE64@@@";

        $response = $this->client->post("/index.php/apps/openregister/api/registers/{$this->registerSlug}/schemas/{$this->schemaSlug}/objects", [
            'json' => [
                'title' => 'Corrupted Test',
                'attachment' => $corruptedData,
            ]
        ]);

        $this->assertEquals(400, $response->getStatusCode(), 'Should reject corrupted base64');
    }

    /**
     * Test 10: GET request returns file metadata
     */
    public function testGetReturnsFileMetadata(): void
    {
        // First, create an object with file
        $pdfContent = '%PDF-1.4 fake pdf';
        $base64 = base64_encode($pdfContent);
        $dataUri = "data:application/pdf;base64,{$base64}";

        $createResponse = $this->client->post("/index.php/apps/openregister/api/registers/{$this->registerSlug}/schemas/{$this->schemaSlug}/objects", [
            'json' => [
                'title' => 'GET Test Document',
                'attachment' => $dataUri,
            ]
        ]);

        $this->assertEquals(201, $createResponse->getStatusCode());
        $created = json_decode($createResponse->getBody(), true);
        $uuid = $created['uuid'];
        $this->createdObjectIds[] = $uuid;

        // Now GET the object
        $getResponse = $this->client->get("/index.php/apps/openregister/api/registers/{$this->registerSlug}/schemas/{$this->schemaSlug}/objects/{$uuid}");
        
        $this->assertEquals(200, $getResponse->getStatusCode());
        
        $data = json_decode($getResponse->getBody(), true);
        
        // Verify file metadata is present
        $this->assertArrayHasKey('attachment', $data);
        $this->assertIsArray($data['attachment'], 'File should be hydrated to full object');
        $this->assertArrayHasKey('id', $data['attachment']);
        $this->assertArrayHasKey('path', $data['attachment']);
        $this->assertArrayHasKey('type', $data['attachment']);
        $this->assertArrayHasKey('size', $data['attachment']);
        $this->assertArrayHasKey('downloadUrl', $data['attachment']);
        $this->assertEquals('application/pdf', $data['attachment']['type']);
    }

    /**
     * Test 11: UPDATE (PUT) with new file
     */
    public function testUpdateObjectWithNewFile(): void
    {
        // Create object without file
        $createResponse = $this->client->post("/index.php/apps/openregister/api/registers/{$this->registerSlug}/schemas/{$this->schemaSlug}/objects", [
            'json' => [
                'title' => 'Update Test',
            ]
        ]);

        $this->assertEquals(201, $createResponse->getStatusCode());
        $created = json_decode($createResponse->getBody(), true);
        $uuid = $created['uuid'];
        $this->createdObjectIds[] = $uuid;

        // Update with file
        $pdfContent = '%PDF-1.4 updated pdf';
        $base64 = base64_encode($pdfContent);
        $dataUri = "data:application/pdf;base64,{$base64}";

        $updateResponse = $this->client->put("/index.php/apps/openregister/api/registers/{$this->registerSlug}/schemas/{$this->schemaSlug}/objects/{$uuid}", [
            'json' => [
                'title' => 'Updated with File',
                'attachment' => $dataUri,
            ]
        ]);

        $this->assertEquals(200, $updateResponse->getStatusCode());
        
        $updated = json_decode($updateResponse->getBody(), true);
        $this->assertArrayHasKey('attachment', $updated);
        $this->assertEquals('application/pdf', $updated['attachment']['type']);
    }

    /**
     * Test 12: Mixed methods in one request (multipart + existing properties)
     */
    public function testMixedMethodsMultipartAndJson(): void
    {
        $pdfTmp = tmpfile();
        fwrite($pdfTmp, '%PDF-1.4 fake pdf');
        $pdfPath = stream_get_meta_data($pdfTmp)['uri'];

        $jpegContent = "\xFF\xD8\xFF\xE0 fake jpeg";
        $base64 = base64_encode($jpegContent);
        $thumbnailDataUri = "data:image/jpeg;base64,{$base64}";

        // Multipart can mix files and form data
        $response = $this->client->post("/index.php/apps/openregister/api/registers/{$this->registerSlug}/schemas/{$this->schemaSlug}/objects", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'Mixed Methods'],
                ['name' => 'attachment', 'contents' => fopen($pdfPath, 'r'), 'filename' => 'doc.pdf', 'headers' => ['Content-Type' => 'application/pdf']],
                ['name' => 'thumbnail', 'contents' => $thumbnailDataUri], // Base64 as form field
            ]
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        
        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('attachment', $data);
        $this->assertArrayHasKey('thumbnail', $data);

        $this->createdObjectIds[] = $data['uuid'];

        fclose($pdfTmp);
    }
}

