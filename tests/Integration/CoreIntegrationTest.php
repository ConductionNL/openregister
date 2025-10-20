<?php

namespace OCA\OpenRegister\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Core Integration Tests for OpenRegister
 * 
 * Tests:
 * - File uploads (multipart, base64, URL)
 * - Cascade protection (registers, schemas)
 * - CRUD operations
 */
class CoreIntegrationTest extends TestCase
{
    private Client $client;
    private string $baseUrl = 'http://localhost';
    private string $registerSlug;
    private string $schemaSlug = 'document';
    private array $createdObjectIds = [];
    private ?int $registerId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerSlug = 'test-file-uploads-' . uniqid();
        
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'http_errors' => false,
            'auth' => ['admin', 'admin'],
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode('admin:admin'),
                'OCS-APIRequest' => 'true',
            ],
        ]);

        $this->cleanupTestRegister();
        $this->createTestRegisterAndSchemas();
    }

    protected function tearDown(): void
    {
        // Clean up created objects FIRST (before register deletion)
        foreach ($this->createdObjectIds as $id) {
            try {
                $schemas = [$this->schemaSlug, 'strict-pdf', 'multi-type', 'gallery'];
                foreach ($schemas as $schema) {
                    try {
                        $this->client->delete("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$schema}/{$id}");
                        break;
                    } catch (\Exception $e) {
                        // Try next schema
                    }
                }
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        $this->cleanupTestRegister();
        parent::tearDown();
    }

    private function cleanupTestRegister(): void
    {
        // Clean up orphaned schemas from previous failed tests
        $orphanedSchemas = ['strict-pdf', 'multi-type', 'gallery', $this->schemaSlug];
        foreach ($orphanedSchemas as $schemaSlug) {
            try {
                $schemasResponse = $this->client->get("/index.php/apps/openregister/api/schemas?slug={$schemaSlug}");
                if ($schemasResponse->getStatusCode() === 200) {
                    $schemasBody = $schemasResponse->getBody()->getContents();
                    $schemas = json_decode($schemasBody, true);
                    if (isset($schemas['results'])) {
                        foreach ($schemas['results'] as $schema) {
                            if (isset($schema['id'])) {
                                $this->client->delete("/index.php/apps/openregister/api/schemas/{$schema['id']}");
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if ($this->registerId === null) {
            return;
        }

        try {
            $this->client->delete("/index.php/apps/openregister/api/registers/{$this->registerId}");
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 404) {
                error_log("Failed to cleanup test register: " . $e->getMessage());
            }
        } catch (\Exception $e) {
            error_log("Error during cleanup: " . $e->getMessage());
        }
        $this->registerId = null;
    }

    private function createTestRegisterAndSchemas(): void
    {
        $registerResponse = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => [
                'slug' => $this->registerSlug,
                'title' => 'Test Register',
                'description' => 'Register for integration testing',
            ]
        ]);

        $this->assertEquals(201, $registerResponse->getStatusCode());
        
        $registerBody = $registerResponse->getBody()->getContents();
        $registerData = json_decode($registerBody, true);
        $this->assertIsArray($registerData);
        $this->assertArrayHasKey('id', $registerData);
        $this->registerId = $registerData['id'];

        // Main schema
        $schemaResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $this->registerId,
                'slug' => $this->schemaSlug,
                'title' => 'Document Schema',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'attachment' => ['type' => 'file'],
                ],
                'required' => ['title']
            ]
        ]);
        $this->assertEquals(201, $schemaResponse->getStatusCode());

        // Strict PDF schema
        $strictPdfResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $this->registerId,
                'slug' => 'strict-pdf',
                'title' => 'Strict PDF Schema',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'document' => [
                        'type' => 'file',
                        'allowedTypes' => ['application/pdf'],
                        'maxSize' => 5242880
                    ],
                ],
                'required' => ['title', 'document']
            ]
        ]);
        $this->assertEquals(201, $strictPdfResponse->getStatusCode());

        // Multi-type schema
        $multiTypeResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $this->registerId,
                'slug' => 'multi-type',
                'title' => 'Multi-Type Schema',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'thumbnail' => [
                        'type' => 'file',
                        'allowedTypes' => ['image/jpeg', 'image/png'],
                        'maxSize' => 1048576
                    ],
                    'cover' => [
                        'type' => 'file',
                        'allowedTypes' => ['image/jpeg', 'image/png'],
                    ],
                ],
            ]
        ]);
        $this->assertEquals(201, $multiTypeResponse->getStatusCode());

        // Gallery schema
        $galleryResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $this->registerId,
                'slug' => 'gallery',
                'title' => 'Gallery Schema',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'images' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'file',
                            'allowedTypes' => ['image/jpeg', 'image/png']
                        ]
                    ],
                ],
            ]
        ]);
        $this->assertEquals(201, $galleryResponse->getStatusCode());
    }

    // ========================================
    // FILE UPLOAD TESTS (1-12)
    // ========================================

    public function testMultipartUploadSinglePdf(): void
    {
        $pdfContent = '%PDF-1.4 fake pdf content for testing';
        $tmpFile = tmpfile();
        fwrite($tmpFile, $pdfContent);
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->schemaSlug}", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'Test Document'],
                ['name' => 'attachment', 'contents' => fopen($tmpPath, 'r'), 'filename' => 'test.pdf', 'headers' => ['Content-Type' => 'application/pdf']],
            ]
        ]);

        $this->assertEquals(201, $response->getStatusCode(), 'Failed to create object: ' . $response->getBody());
        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('attachment', $data);
        $this->assertIsArray($data['attachment']);
        $this->createdObjectIds[] = $data['id'];
        fclose($tmpFile);
    }

    public function testMultipartUploadMultipleFiles(): void
    {
        $imageTmp = tmpfile();
        fwrite($imageTmp, "\xFF\xD8\xFF\xE0");
        $imagePath = stream_get_meta_data($imageTmp)['uri'];

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/multi-type", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'Multi-File Document'],
                ['name' => 'thumbnail', 'contents' => fopen($imagePath, 'r'), 'filename' => 'thumb.jpg', 'headers' => ['Content-Type' => 'image/jpeg']],
                ['name' => 'cover', 'contents' => fopen($imagePath, 'r'), 'filename' => 'cover.jpg', 'headers' => ['Content-Type' => 'image/jpeg']],
            ]
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->createdObjectIds[] = $data['id'];
        fclose($imageTmp);
    }

    public function testBase64UploadWithDataUri(): void
    {
        $pdfContent = '%PDF-1.4 test';
        $base64 = base64_encode($pdfContent);
        $dataUri = "data:application/pdf;base64,{$base64}";

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->schemaSlug}", [
            'json' => [
                'title' => 'Base64 Document',
                'attachment' => $dataUri
            ]
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->createdObjectIds[] = $data['id'];
    }

    public function testUrlReferenceUpload(): void
    {
        $this->markTestSkipped('URL upload requires external URL setup');
    }

    public function testArrayOfFilesMultipart(): void
    {
        $image1Tmp = tmpfile();
        fwrite($image1Tmp, "\xFF\xD8\xFF\xE0");
        $image1Path = stream_get_meta_data($image1Tmp)['uri'];

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/gallery", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'Photo Gallery'],
                ['name' => 'images[]', 'contents' => fopen($image1Path, 'r'), 'filename' => 'photo1.jpg', 'headers' => ['Content-Type' => 'image/jpeg']],
            ]
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->createdObjectIds[] = $data['id'];
        fclose($image1Tmp);
    }

    public function testArrayOfFilesBase64(): void
    {
        $image1 = base64_encode("\xFF\xD8\xFF\xE0");

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/gallery", [
            'json' => [
                'title' => 'Base64 Gallery',
                'images' => ["data:image/jpeg;base64,{$image1}"]
            ]
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->createdObjectIds[] = $data['id'];
    }

    public function testValidationWrongMimeType(): void
    {
        $imageTmp = tmpfile();
        fwrite($imageTmp, "\xFF\xD8\xFF\xE0");
        $imagePath = stream_get_meta_data($imageTmp)['uri'];

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/strict-pdf", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'Wrong Type'],
                ['name' => 'document', 'contents' => fopen($imagePath, 'r'), 'filename' => 'fake.pdf', 'headers' => ['Content-Type' => 'image/jpeg']],
            ]
        ]);

        $this->assertEquals(400, $response->getStatusCode(), 'Should reject wrong MIME type');
        fclose($imageTmp);
    }

    public function testValidationFileTooLarge(): void
    {
        $largePdf = str_repeat('%PDF-1.4 ' . str_repeat('X', 1000), 6000);
        $tmpFile = tmpfile();
        fwrite($tmpFile, $largePdf);
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/strict-pdf", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'Too Large'],
                ['name' => 'document', 'contents' => fopen($tmpPath, 'r'), 'filename' => 'huge.pdf', 'headers' => ['Content-Type' => 'application/pdf']],
            ]
        ]);

        $this->assertEquals(400, $response->getStatusCode(), 'Should reject oversized file');
        fclose($tmpFile);
    }

    public function testValidationCorruptedBase64(): void
    {
        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->schemaSlug}", [
            'json' => [
                'title' => 'Corrupted',
                'attachment' => "data:application/pdf;base64,INVALID!!!"
            ]
        ]);

        $this->assertEquals(400, $response->getStatusCode(), 'Should reject corrupted base64');
    }

    public function testGetReturnsFileMetadata(): void
    {
        $pdfTmp = tmpfile();
        fwrite($pdfTmp, '%PDF-1.4 test');
        $pdfPath = stream_get_meta_data($pdfTmp)['uri'];

        $createResponse = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->schemaSlug}", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'GET Test'],
                ['name' => 'attachment', 'contents' => fopen($pdfPath, 'r'), 'filename' => 'test.pdf', 'headers' => ['Content-Type' => 'application/pdf']],
            ]
        ]);
        
        $this->assertEquals(201, $createResponse->getStatusCode());
        $created = json_decode($createResponse->getBody(), true);
        $id = $created['id'];

        $getResponse = $this->client->get("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->schemaSlug}/{$id}");
        $this->assertEquals(200, $getResponse->getStatusCode());
        
        $this->createdObjectIds[] = $id;
        fclose($pdfTmp);
    }

    public function testUpdateObjectWithNewFile(): void
    {
        $pdf1Tmp = tmpfile();
        fwrite($pdf1Tmp, '%PDF-1.4 original');
        $pdf1Path = stream_get_meta_data($pdf1Tmp)['uri'];

        $createResponse = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->schemaSlug}", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'Original'],
                ['name' => 'attachment', 'contents' => fopen($pdf1Path, 'r'), 'filename' => 'original.pdf', 'headers' => ['Content-Type' => 'application/pdf']],
            ]
        ]);
        
        $created = json_decode($createResponse->getBody(), true);
        $id = $created['id'];

        $pdf2Tmp = tmpfile();
        fwrite($pdf2Tmp, '%PDF-1.4 updated');
        $pdf2Path = stream_get_meta_data($pdf2Tmp)['uri'];

        $updateResponse = $this->client->put("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->schemaSlug}/{$id}", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'Updated'],
                ['name' => 'attachment', 'contents' => fopen($pdf2Path, 'r'), 'filename' => 'updated.pdf', 'headers' => ['Content-Type' => 'application/pdf']],
            ]
        ]);

        $this->assertEquals(200, $updateResponse->getStatusCode());
        $this->createdObjectIds[] = $id;
        fclose($pdf1Tmp);
        fclose($pdf2Tmp);
    }

    public function testMixedMethodsMultipartAndJson(): void
    {
        $pdfTmp = tmpfile();
        fwrite($pdfTmp, '%PDF-1.4 test');
        $pdfPath = stream_get_meta_data($pdfTmp)['uri'];

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->schemaSlug}", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'Mixed'],
                ['name' => 'attachment', 'contents' => fopen($pdfPath, 'r'), 'filename' => 'doc.pdf', 'headers' => ['Content-Type' => 'application/pdf']],
            ]
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->createdObjectIds[] = $data['id'];
        fclose($pdfTmp);
    }

    // ========================================
    // CASCADE PROTECTION TESTS (13-15)
    // ========================================

    public function testCannotDeleteRegisterWithObjects(): void
    {
        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->schemaSlug}", [
            'json' => ['title' => 'Cascade Protection Test']
        ]);
        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->createdObjectIds[] = $data['id'];

        $deleteResponse = $this->client->delete("/index.php/apps/openregister/api/registers/{$this->registerId}");
        $this->assertContains($deleteResponse->getStatusCode(), [400, 409], 'Should not allow deleting register with objects');
    }

    public function testCannotDeleteSchemaWithObjects(): void
    {
        $schemasResponse = $this->client->get("/index.php/apps/openregister/api/schemas?slug={$this->schemaSlug}");
        $schemas = json_decode($schemasResponse->getBody()->getContents(), true);
        $schemaId = $schemas['results'][0]['id'];

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->schemaSlug}", [
            'json' => ['title' => 'Schema Protection Test']
        ]);
        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->createdObjectIds[] = $data['id'];

        $deleteResponse = $this->client->delete("/index.php/apps/openregister/api/schemas/{$schemaId}");
        $this->assertContains($deleteResponse->getStatusCode(), [400, 409], 'Should not allow deleting schema with objects');
    }

    public function testCanDeleteRegisterAfterObjectsRemoved(): void
    {
        $tempRegisterResponse = $this->client->post('/index.php/apps/openregister/api/registers', [
            'json' => ['slug' => 'temp-' . uniqid(), 'title' => 'Temp Register']
        ]);
        $tempRegister = json_decode($tempRegisterResponse->getBody()->getContents(), true);
        $tempRegisterId = $tempRegister['id'];
        $tempRegisterSlug = $tempRegister['slug'];

        $tempSchemaResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $tempRegisterId,
                'slug' => 'temp-schema-' . uniqid(),
                'title' => 'Temp Schema',
                'properties' => ['title' => ['type' => 'string']]
            ]
        ]);
        $tempSchema = json_decode($tempSchemaResponse->getBody()->getContents(), true);
        $tempSchemaSlug = $tempSchema['slug'];

        $objectResponse = $this->client->post("/index.php/apps/openregister/api/objects/{$tempRegisterSlug}/{$tempSchemaSlug}", [
            'json' => ['title' => 'Temp Object']
        ]);
        $object = json_decode($objectResponse->getBody(), true);

        $this->client->delete("/index.php/apps/openregister/api/objects/{$tempRegisterSlug}/{$tempSchemaSlug}/{$object['id']}");
        $this->client->delete("/index.php/apps/openregister/api/schemas/{$tempSchema['id']}");
        
        $deleteRegisterResponse = $this->client->delete("/index.php/apps/openregister/api/registers/{$tempRegisterId}");
        $this->assertContains($deleteRegisterResponse->getStatusCode(), [200, 204], 'Should allow deleting register after cleanup');
    }
}

