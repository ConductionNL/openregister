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
    private string $documentSchemaSlug;
    private string $strictPdfSchemaSlug;
    private string $multiTypeSchemaSlug;
    private string $gallerySchemaSlug;
    private array $createdObjectIds = [];
    private array $createdSchemaIds = [];
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
        // Clean up created objects FIRST (before schema/register deletion)
        foreach ($this->createdObjectIds as $id) {
            try {
                // Try all known schema slugs to find which one this object belongs to
                $schemaSlugs = [
                    $this->documentSchemaSlug ?? null,
                    $this->strictPdfSchemaSlug ?? null,
                    $this->multiTypeSchemaSlug ?? null,
                    $this->gallerySchemaSlug ?? null
                ];
                foreach (array_filter($schemaSlugs) as $schemaSlug) {
                    try {
                        $this->client->delete("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$schemaSlug}/{$id}");
                        break;
                    } catch (\Exception $e) {
                        // Try next schema
                    }
                }
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        // Clean up created schemas SECOND (after objects are deleted)
        foreach ($this->createdSchemaIds as $schemaId) {
            try {
                $this->client->delete("/index.php/apps/openregister/api/schemas/{$schemaId}");
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        $this->cleanupTestRegister();
        parent::tearDown();
    }

    private function cleanupTestRegister(): void
    {
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

        // Generate unique schema slugs to avoid conflicts with previous test runs
        $uniqueId = substr($this->registerSlug, -13); // Use the unique part from register slug

        // Main schema (document)
        $schemaResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $this->registerId,
                'slug' => 'document-' . $uniqueId,
                'title' => 'Document Schema',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'attachment' => ['type' => 'file'],
                ],
                'required' => ['title']
            ]
        ]);
        $this->assertEquals(201, $schemaResponse->getStatusCode());
        $schemaData = json_decode($schemaResponse->getBody()->getContents(), true);
        $this->createdSchemaIds[] = $schemaData['id'];
        $this->documentSchemaSlug = $schemaData['slug'];

        // Strict PDF schema
        $strictPdfResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $this->registerId,
                'slug' => 'strict-pdf-' . $uniqueId,
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
        $strictPdfData = json_decode($strictPdfResponse->getBody()->getContents(), true);
        $this->createdSchemaIds[] = $strictPdfData['id'];
        $this->strictPdfSchemaSlug = $strictPdfData['slug'];

        // Multi-type schema
        $multiTypeResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $this->registerId,
                'slug' => 'multi-type-' . $uniqueId,
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
        $multiTypeData = json_decode($multiTypeResponse->getBody()->getContents(), true);
        $this->createdSchemaIds[] = $multiTypeData['id'];
        $this->multiTypeSchemaSlug = $multiTypeData['slug'];

        // Gallery schema
        $galleryResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $this->registerId,
                'slug' => 'gallery-' . $uniqueId,
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
        $galleryData = json_decode($galleryResponse->getBody()->getContents(), true);
        $this->createdSchemaIds[] = $galleryData['id'];
        $this->gallerySchemaSlug = $galleryData['slug'];
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

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->documentSchemaSlug}", [
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

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->multiTypeSchemaSlug}", [
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

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->documentSchemaSlug}", [
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

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->gallerySchemaSlug}", [
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

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->gallerySchemaSlug}", [
            'json' => [
                'title' => 'Base64 Gallery',
                'images' => ["data:image/jpeg;base64,{$image1}"]
            ]
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->createdObjectIds[] = $data['id'];
    }

    public function testArrayOfMultipleFilesMultipart(): void
    {
        $image1Tmp = tmpfile();
        fwrite($image1Tmp, "\xFF\xD8\xFF\xE0\x00\x10");
        $image1Path = stream_get_meta_data($image1Tmp)['uri'];

        $image2Tmp = tmpfile();
        fwrite($image2Tmp, "\xFF\xD8\xFF\xE0\x00\x20");
        $image2Path = stream_get_meta_data($image2Tmp)['uri'];

        $image3Tmp = tmpfile();
        fwrite($image3Tmp, "\xFF\xD8\xFF\xE0\x00\x30");
        $image3Path = stream_get_meta_data($image3Tmp)['uri'];

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->gallerySchemaSlug}", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'Multiple Files Gallery'],
                ['name' => 'images[]', 'contents' => fopen($image1Path, 'r'), 'filename' => 'photo1.jpg', 'headers' => ['Content-Type' => 'image/jpeg']],
                ['name' => 'images[]', 'contents' => fopen($image2Path, 'r'), 'filename' => 'photo2.jpg', 'headers' => ['Content-Type' => 'image/jpeg']],
                ['name' => 'images[]', 'contents' => fopen($image3Path, 'r'), 'filename' => 'photo3.jpg', 'headers' => ['Content-Type' => 'image/jpeg']],
            ]
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('images', $data);
        $this->assertIsArray($data['images']);
        $this->assertCount(3, $data['images'], 'Should have uploaded 3 images');
        $this->createdObjectIds[] = $data['id'];
        fclose($image1Tmp);
        fclose($image2Tmp);
        fclose($image3Tmp);
    }

    public function testArrayOfMultipleFilesBase64(): void
    {
        $image1 = base64_encode("\xFF\xD8\xFF\xE0\x00\x10");
        $image2 = base64_encode("\xFF\xD8\xFF\xE0\x00\x20");
        $image3 = base64_encode("\xFF\xD8\xFF\xE0\x00\x30");

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->gallerySchemaSlug}", [
            'json' => [
                'title' => 'Multiple Base64 Gallery',
                'images' => [
                    "data:image/jpeg;base64,{$image1}",
                    "data:image/jpeg;base64,{$image2}",
                    "data:image/jpeg;base64,{$image3}"
                ]
            ]
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('images', $data);
        $this->assertIsArray($data['images']);
        $this->assertCount(3, $data['images'], 'Should have uploaded 3 images via base64');
        $this->createdObjectIds[] = $data['id'];
    }

    public function testValidationWrongMimeType(): void
    {
        $imageTmp = tmpfile();
        fwrite($imageTmp, "\xFF\xD8\xFF\xE0");
        $imagePath = stream_get_meta_data($imageTmp)['uri'];

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->strictPdfSchemaSlug}", [
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

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->strictPdfSchemaSlug}", [
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
        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->documentSchemaSlug}", [
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

        $createResponse = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->documentSchemaSlug}", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'GET Test'],
                ['name' => 'attachment', 'contents' => fopen($pdfPath, 'r'), 'filename' => 'test.pdf', 'headers' => ['Content-Type' => 'application/pdf']],
            ]
        ]);
        
        $this->assertEquals(201, $createResponse->getStatusCode());
        $created = json_decode($createResponse->getBody(), true);
        $id = $created['id'];

        $getResponse = $this->client->get("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->documentSchemaSlug}/{$id}");
        $this->assertEquals(200, $getResponse->getStatusCode());
        
        $this->createdObjectIds[] = $id;
        fclose($pdfTmp);
    }

    public function testUpdateObjectWithNewFile(): void
    {
        $pdf1Tmp = tmpfile();
        fwrite($pdf1Tmp, '%PDF-1.4 original');
        $pdf1Path = stream_get_meta_data($pdf1Tmp)['uri'];

        $createResponse = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->documentSchemaSlug}", [
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

        $updateResponse = $this->client->put("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->documentSchemaSlug}/{$id}", [
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

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->documentSchemaSlug}", [
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
        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->documentSchemaSlug}", [
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
        $schemasResponse = $this->client->get("/index.php/apps/openregister/api/schemas?slug={$this->documentSchemaSlug}");
        $schemas = json_decode($schemasResponse->getBody()->getContents(), true);
        $schemaId = $schemas['results'][0]['id'];

        $response = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->documentSchemaSlug}", [
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

    // ========================================
    // NEW FEATURE TESTS (16-20)
    // ========================================

    public function testAuthenticatedUrlsForNonSharedFiles(): void
    {
        $pdfTmp = tmpfile();
        fwrite($pdfTmp, '%PDF-1.4 test non-shared');
        $pdfPath = stream_get_meta_data($pdfTmp)['uri'];

        // Create object with file (not shared)
        $createResponse = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->documentSchemaSlug}", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'Non-Shared File Test'],
                ['name' => 'attachment', 'contents' => fopen($pdfPath, 'r'), 'filename' => 'test.pdf', 'headers' => ['Content-Type' => 'application/pdf']],
            ]
        ]);

        $this->assertEquals(201, $createResponse->getStatusCode());
        $object = json_decode($createResponse->getBody(), true);
        $this->createdObjectIds[] = $object['id'];

        // Verify file object has authenticated URLs
        $this->assertArrayHasKey('attachment', $object);
        $file = $object['attachment'];
        $this->assertIsArray($file);
        $this->assertArrayHasKey('accessUrl', $file);
        $this->assertArrayHasKey('downloadUrl', $file);
        $this->assertNotNull($file['accessUrl']);
        $this->assertNotNull($file['downloadUrl']);

        // Verify URLs contain /api/files/ for authenticated access
        $this->assertStringContainsString('/api/files/', $file['downloadUrl']);

        fclose($pdfTmp);
    }

    public function testAutoShareFileProperty(): void
    {
        // Create schema with autoPublish enabled
        $autoPublishSchemaResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $this->registerId,
                'slug' => 'auto-share-' . uniqid(),
                'title' => 'Auto-Share Schema',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'document' => [
                        'type' => 'file',
                        'autoPublish' => true
                    ],
                ],
            ]
        ]);
        $this->assertEquals(201, $autoPublishSchemaResponse->getStatusCode());
        $autoPublishSchema = json_decode($autoPublishSchemaResponse->getBody(), true);
        $this->createdSchemaIds[] = $autoPublishSchema['id'];

        $pdfTmp = tmpfile();
        fwrite($pdfTmp, '%PDF-1.4 auto-share test');
        $pdfPath = stream_get_meta_data($pdfTmp)['uri'];

        // Create object with file
        $createResponse = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$autoPublishSchema['slug']}", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'Auto-Share Test'],
                ['name' => 'document', 'contents' => fopen($pdfPath, 'r'), 'filename' => 'test.pdf', 'headers' => ['Content-Type' => 'application/pdf']],
            ]
        ]);

        $this->assertEquals(201, $createResponse->getStatusCode());
        $object = json_decode($createResponse->getBody(), true);
        $this->createdObjectIds[] = $object['id'];

        // Verify file is publicly shared
        $this->assertArrayHasKey('document', $object);
        $file = $object['document'];
        $this->assertIsArray($file);
        $this->assertArrayHasKey('published', $file);
        $this->assertNotNull($file['published'], 'File should be published when autoPublish is enabled');

        // Verify public share URL
        $this->assertArrayHasKey('accessUrl', $file);
        $this->assertStringContainsString('/index.php/s/', $file['accessUrl'], 'Should have public share URL');

        fclose($pdfTmp);
    }

    public function testLogoMetadataFromFileProperty(): void
    {
        // Create schema with logo configuration pointing to a file property
        $logoSchemaResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $this->registerId,
                'slug' => 'logo-test-' . uniqid(),
                'title' => 'Logo Test Schema',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'logo' => [
                        'type' => 'file',
                        'allowedTypes' => ['image/png', 'image/jpeg'],
                        'autoPublish' => true
                    ],
                ],
                'configuration' => [
                    'objectImageField' => 'logo'
                ]
            ]
        ]);
        $this->assertEquals(201, $logoSchemaResponse->getStatusCode());
        $logoSchema = json_decode($logoSchemaResponse->getBody(), true);
        $this->createdSchemaIds[] = $logoSchema['id'];

        $imageTmp = tmpfile();
        fwrite($imageTmp, "\xFF\xD8\xFF\xE0");
        $imagePath = stream_get_meta_data($imageTmp)['uri'];

        // Create object with logo file
        $createResponse = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$logoSchema['slug']}", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'Logo Test'],
                ['name' => 'logo', 'contents' => fopen($imagePath, 'r'), 'filename' => 'logo.jpg', 'headers' => ['Content-Type' => 'image/jpeg']],
            ]
        ]);

        $this->assertEquals(201, $createResponse->getStatusCode());
        $object = json_decode($createResponse->getBody(), true);
        $this->createdObjectIds[] = $object['id'];

        // Verify @self.image contains the downloadUrl
        $this->assertArrayHasKey('@self', $object);
        $this->assertArrayHasKey('image', $object['@self']);
        $this->assertIsString($object['@self']['image']);
        $this->assertStringContainsString('/index.php/s/', $object['@self']['image'], 'Image metadata should contain share URL');
        $this->assertStringContainsString('/download', $object['@self']['image'], 'Image should use downloadUrl for public access');

        fclose($imageTmp);
    }

    public function testImageMetadataFromFileArrayProperty(): void
    {
        // Create schema with objectImageField pointing to an array property
        // Should use the first file in the array as the image
        $arrayImageSchemaResponse = $this->client->post('/index.php/apps/openregister/api/schemas', [
            'json' => [
                'register' => $this->registerId,
                'slug' => 'array-image-test-' . uniqid(),
                'title' => 'Array Image Test Schema',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'photos' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'file',
                            'allowedTypes' => ['image/png', 'image/jpeg'],
                            'autoPublish' => true
                        ]
                    ],
                ],
                'configuration' => [
                    'objectImageField' => 'photos'
                ]
            ]
        ]);
        $this->assertEquals(201, $arrayImageSchemaResponse->getStatusCode());
        $arrayImageSchema = json_decode($arrayImageSchemaResponse->getBody(), true);
        $this->createdSchemaIds[] = $arrayImageSchema['id'];

        $image1Tmp = tmpfile();
        fwrite($image1Tmp, "\xFF\xD8\xFF\xE0\x00\x10");
        $image1Path = stream_get_meta_data($image1Tmp)['uri'];

        $image2Tmp = tmpfile();
        fwrite($image2Tmp, "\xFF\xD8\xFF\xE0\x00\x20");
        $image2Path = stream_get_meta_data($image2Tmp)['uri'];

        // Create object with multiple photos
        $createResponse = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$arrayImageSchema['slug']}", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'Array Image Test'],
                ['name' => 'photos[]', 'contents' => fopen($image1Path, 'r'), 'filename' => 'photo1.jpg', 'headers' => ['Content-Type' => 'image/jpeg']],
                ['name' => 'photos[]', 'contents' => fopen($image2Path, 'r'), 'filename' => 'photo2.jpg', 'headers' => ['Content-Type' => 'image/jpeg']],
            ]
        ]);

        $this->assertEquals(201, $createResponse->getStatusCode());
        $object = json_decode($createResponse->getBody(), true);
        $this->createdObjectIds[] = $object['id'];

        // Verify photos array has 2 files
        $this->assertArrayHasKey('photos', $object);
        $this->assertIsArray($object['photos']);
        $this->assertCount(2, $object['photos']);

        // Verify @self.image uses the FIRST photo's downloadUrl
        $this->assertArrayHasKey('@self', $object);
        $this->assertArrayHasKey('image', $object['@self']);
        $this->assertIsString($object['@self']['image']);
        // Should contain the first file's download URL (public share)
        $this->assertStringContainsString('/index.php/s/', $object['@self']['image'], 'Image metadata should use first file from array');
        $this->assertStringContainsString('/download', $object['@self']['image'], 'Image should use downloadUrl for public access');

        fclose($image1Tmp);
        fclose($image2Tmp);
    }

    public function testDeleteFileBySendingNull(): void
    {
        $pdfTmp = tmpfile();
        fwrite($pdfTmp, '%PDF-1.4 to be deleted');
        $pdfPath = stream_get_meta_data($pdfTmp)['uri'];

        // Create object with file
        $createResponse = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->documentSchemaSlug}", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'Delete Test'],
                ['name' => 'attachment', 'contents' => fopen($pdfPath, 'r'), 'filename' => 'test.pdf', 'headers' => ['Content-Type' => 'application/pdf']],
            ]
        ]);

        $this->assertEquals(201, $createResponse->getStatusCode());
        $object = json_decode($createResponse->getBody(), true);
        $objectId = $object['id'];
        $this->createdObjectIds[] = $objectId;

        // Verify file exists
        $this->assertArrayHasKey('attachment', $object);
        $this->assertNotNull($object['attachment']);

        // Update object with null to delete file
        $updateResponse = $this->client->put("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->documentSchemaSlug}/{$objectId}", [
            'json' => [
                'title' => 'Delete Test Updated',
                'attachment' => null
            ]
        ]);

        $this->assertEquals(200, $updateResponse->getStatusCode());
        $updatedObject = json_decode($updateResponse->getBody(), true);

        // Verify file is deleted (property is null)
        $this->assertArrayHasKey('attachment', $updatedObject);
        $this->assertNull($updatedObject['attachment'], 'Attachment should be null after deletion');

        fclose($pdfTmp);
    }

    public function testDeleteFileArrayBySendingEmptyArray(): void
    {
        $image1Tmp = tmpfile();
        fwrite($image1Tmp, "\xFF\xD8\xFF\xE0");
        $image1Path = stream_get_meta_data($image1Tmp)['uri'];

        // Create object with files array
        $createResponse = $this->client->post("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->gallerySchemaSlug}", [
            'multipart' => [
                ['name' => 'title', 'contents' => 'Delete Array Test'],
                ['name' => 'images[]', 'contents' => fopen($image1Path, 'r'), 'filename' => 'photo1.jpg', 'headers' => ['Content-Type' => 'image/jpeg']],
            ]
        ]);

        $this->assertEquals(201, $createResponse->getStatusCode());
        $object = json_decode($createResponse->getBody(), true);
        $objectId = $object['id'];
        $this->createdObjectIds[] = $objectId;

        // Verify files exist
        $this->assertArrayHasKey('images', $object);
        $this->assertIsArray($object['images']);
        $this->assertNotEmpty($object['images']);

        // Update object with empty array to delete all files
        $updateResponse = $this->client->put("/index.php/apps/openregister/api/objects/{$this->registerSlug}/{$this->gallerySchemaSlug}/{$objectId}", [
            'json' => [
                'title' => 'Delete Array Test Updated',
                'images' => []
            ]
        ]);

        $this->assertEquals(200, $updateResponse->getStatusCode());
        $updatedObject = json_decode($updateResponse->getBody(), true);

        // Verify files array is empty
        $this->assertArrayHasKey('images', $updatedObject);
        $this->assertIsArray($updatedObject['images']);
        $this->assertEmpty($updatedObject['images'], 'Images array should be empty after deletion');

        fclose($image1Tmp);
    }
}

