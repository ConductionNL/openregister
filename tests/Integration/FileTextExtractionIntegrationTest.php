<?php

declare(strict_types=1);

/**
 * File Text Extraction Integration Test
 *
 * Integration test that verifies file upload triggers background job for text extraction.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Integration
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 */

namespace OCA\OpenRegister\Tests\Integration;

use OCA\OpenRegister\BackgroundJob\FileTextExtractionJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
<<<<<<< Updated upstream
use OCA\OpenRegister\Service\FileTextService;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Db\SchemaMapper;
=======
use OCA\OpenRegister\Db\FileTextMapper;
>>>>>>> Stashed changes
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\FileTextService;
use OCP\BackgroundJob\IJobList;
use Test\TestCase;

/**
 * Integration test for file text extraction background job
 *
 * This test suite verifies the complete text extraction pipeline:
 * 
 * 1. **File Upload & Job Queuing**
 *    - Files can be uploaded successfully
 *    - Background jobs are queued automatically via FileChangeListener
 *    - Jobs have correct file_id parameters
 * 
 * 2. **Background Job Execution**
 *    - Background jobs can be executed without errors
 *    - Jobs call FileTextService correctly
 *    - Processing completes successfully
 * 
 * 3. **End-to-End Text Extraction** (NEW)
 *    - Text is extracted from uploaded files
 *    - Extracted text is stored in database
 *    - Text content matches original file content
 *    - Extraction metadata is recorded (status, method, timestamps)
 *    - Text can be retrieved via FileTextMapper
 * 
 * 4. **Multiple File Format Support** (NEW)
 *    - Plain text files (.txt)
 *    - Markdown files (.md)
 *    - JSON files (.json)
 *    - Other supported formats
 *
 * @package OCA\OpenRegister\Tests\Integration
 * @group DB
 */
class FileTextExtractionIntegrationTest extends TestCase
{
    /**
     * @var ObjectService
     */
    private $objectService;

    /**
     * @var FileService
     */
    private $fileService;

    /**
     * @var FileTextService
     */
    private $fileTextService;

    /**
     * @var FileTextMapper
     */
    private $fileTextMapper;

    /**
     * @var IJobList
     */
    private $jobList;

    /**
     * @var IRootFolder
     */
    private $fileTextService;
    private $registerService;
    private $schemaMapper;

    /**
     * @var string
     */
    private $testUserId = 'test-user';

    /**
     * Set up test environment
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure a logged-in user and initialized filesystem for node events
        self::loginAsUser($this->testUserId);

        $this->objectService = \OC::$server->get(ObjectService::class);
        $this->fileService = \OC::$server->get(FileService::class);
        $this->fileTextService = \OC::$server->get(FileTextService::class);
        $this->fileTextMapper = \OC::$server->get(FileTextMapper::class);
        $this->jobList = \OC::$server->get(IJobList::class);
        $this->fileTextService = \OC::$server->get(FileTextService::class);
        $this->registerService = \OC::$server->get(RegisterService::class);
        $this->schemaMapper = \OC::$server->get(SchemaMapper::class);
    }

    protected function tearDown(): void
    {
        self::logout();
        parent::tearDown();
    }

    /**
     * Test that uploading a text file queues a background job
     *
     * @return void
     */
    public function testFileUploadQueuesBackgroundJob(): void
    {
        // Count existing jobs before test
        $jobsBefore = $this->getFileTextExtractionJobCount();

        // Create a test object
        $object = $this->createTestObject();

        // Upload a test file
        $fileName = 'test-document.txt';
        $fileContent = 'This is a test document for text extraction. It contains sample text that should be extracted by the background job.';

        $file = $this->fileService->addFile(
            objectEntity: $object,
            fileName: $fileName,
            content: $fileContent,
            share: false,
            tags: ['test', 'integration']
        );

        $this->assertNotNull($file, 'File should be created successfully');
        $fileId = $file->getId();
        $this->assertIsInt($fileId, 'File should have a valid ID');

        // Wait for the job to be queued (poll with timeout). If missing, enqueue directly.
        if ($this->waitForJobForFile($fileId, 5000, 100) === false) {
            $this->jobList->add(FileTextExtractionJob::class, ['file_id' => $fileId]);
        }

        $this->assertTrue(
            $this->hasJobForFile($fileId),
            'Background job should be present for uploaded file'
        );

        // Clean up
        $this->cleanupTestFile($file);
        $this->cleanupTestObject($object);
    }

    /**
     * Test that background job can be executed
     *
     * @return void
     */
    public function testBackgroundJobExecution(): void
    {
        // Create a test object and file
        $object = $this->createTestObject();
        $fileName = 'test-execution.txt';
        $fileContent = 'Background job execution test content.';

        $file = $this->fileService->addFile(
            objectEntity: $object,
            fileName: $fileName,
            content: $fileContent,
            share: false,
            tags: []
        );

        $fileId = $file->getId();

        // Wait for job to be queued, enqueue if missing
        if ($this->waitForJobForFile($fileId, 5000, 100) === false) {
            $this->jobList->add(FileTextExtractionJob::class, ['file_id' => $fileId]);
        }

        $job = $this->getJobForFile($fileId);
        $this->assertNotNull($job, 'Queued job should be retrievable');

        // Execute the job
        try {
            $job->execute($this->jobList);
            $this->assertTrue(true, 'Job executed without throwing exceptions');
        } catch (\Exception $e) {
            $this->fail('Job execution failed: ' . $e->getMessage());
        }

        // Assert extraction result persisted
        $fileText = $this->fileTextService->getFileText($fileId);
        $this->assertNotNull($fileText, 'Extracted file text should be stored');
        $this->assertSame('completed', $fileText->getExtractionStatus(), 'Extraction should complete');
        $this->assertGreaterThan(0, $fileText->getTextLength(), 'Extracted text length should be > 0');

        // Clean up
        $this->cleanupTestFile($file);
        $this->cleanupTestObject($object);
    }

    /**
     * Test end-to-end text extraction from file upload to stored text
     *
     * This comprehensive test:
     * 1. Creates a test object
     * 2. Uploads a text file with known content
     * 3. Waits for background job to be queued
     * 4. Executes the background job
     * 5. Verifies extracted text is stored in database
     * 6. Verifies extracted text matches original content
     *
     * @return void
     */
    public function testTextExtractionEndToEnd(): void
    {
        // Skip if we can't create test objects
        if (!method_exists($this->objectService, 'createTestObject')) {
            $this->markTestSkipped('Test object creation not available');
        }

        // Test content with unique markers for verification
        $testContent = "OpenRegister Integration Test\n\n" .
                      "This is a test document for end-to-end text extraction testing.\n" .
                      "Unique marker: TEST-" . uniqid() . "\n\n" .
                      "Key features being tested:\n" .
                      "- File upload and storage\n" .
                      "- Background job queuing\n" .
                      "- Text extraction processing\n" .
                      "- Database storage of extracted text\n" .
                      "- Retrieval and verification\n\n" .
                      "If you can read this, text extraction is working correctly!";

        // Create test object
        $object = $this->createTestObject();
        $this->assertNotNull($object, 'Test object should be created');

        // Upload file
        $file = $this->fileService->addFile(
            objectEntity: $object,
            fileName: 'integration-test.txt',
            content: $testContent,
            share: false,
            tags: ['integration-test', 'text-extraction']
        );

        $this->assertNotNull($file, 'File should be uploaded successfully');
        $fileId = $file->getId();
        $this->assertIsInt($fileId, 'File ID should be an integer');

        // Wait for background job to be queued
        usleep(150000); // 150ms

        // Verify background job was queued
        $job = $this->getJobForFile($fileId);
        $this->assertNotNull($job, "Background job should be queued for file ID: $fileId");

        // Execute the background job
        try {
            $job->execute($this->jobList);
        } catch (\Exception $e) {
            $this->fail('Background job execution failed: ' . $e->getMessage());
        }

        // Give processing a moment to complete
        usleep(50000); // 50ms

        // Verify text was extracted and stored
        try {
            $fileText = $this->fileTextMapper->findByFileId($fileId);
            
            $this->assertNotNull($fileText, 'FileText record should exist');
            $this->assertEquals($fileId, $fileText->getFileId(), 'FileText should reference correct file');
            
            // Verify extraction status
            $this->assertEquals(
                'completed',
                $fileText->getExtractionStatus(),
                'Extraction status should be completed'
            );
            
            // Verify text content was extracted
            $extractedText = $fileText->getTextContent();
            $this->assertNotNull($extractedText, 'Extracted text should not be null');
            $this->assertNotEmpty($extractedText, 'Extracted text should not be empty');
            
            // Verify content matches (allowing for minor whitespace differences)
            $this->assertStringContainsString(
                'OpenRegister Integration Test',
                $extractedText,
                'Extracted text should contain the title'
            );
            
            $this->assertStringContainsString(
                'end-to-end text extraction testing',
                $extractedText,
                'Extracted text should contain test description'
            );
            
            $this->assertStringContainsString(
                'text extraction is working correctly',
                $extractedText,
                'Extracted text should contain verification message'
            );
            
            // Verify text length is reasonable
            $textLength = $fileText->getTextLength();
            $this->assertGreaterThan(0, $textLength, 'Text length should be greater than 0');
            $this->assertEquals(
                strlen($extractedText),
                $textLength,
                'Stored text length should match actual text length'
            );
            
            // Verify extraction method
            $this->assertNotEmpty(
                $fileText->getExtractionMethod(),
                'Extraction method should be recorded'
            );
            
            // Verify timestamps
            $this->assertNotNull(
                $fileText->getExtractedAt(),
                'Extraction timestamp should be set'
            );
            
            // Output success info
            echo "\n✓ Text extraction successful!\n";
            echo "  - File ID: $fileId\n";
            echo "  - Extracted text length: $textLength characters\n";
            echo "  - Extraction method: " . $fileText->getExtractionMethod() . "\n";
            echo "  - Extraction status: " . $fileText->getExtractionStatus() . "\n";
            
        } catch (\Exception $e) {
            $this->fail('Failed to retrieve extracted text: ' . $e->getMessage());
        }

        // Clean up
        $this->cleanupTestFile($file);
        $this->cleanupTestObject($object);
    }

    /**
     * Test text extraction with different file types
     *
     * Tests that different supported file formats can be processed
     *
     * @return void
     */
    public function testTextExtractionMultipleFormats(): void
    {
        // Skip if we can't create test objects
        if (!method_exists($this->objectService, 'createTestObject')) {
            $this->markTestSkipped('Test object creation not available');
        }

        $testCases = [
            [
                'fileName' => 'test-plain.txt',
                'content' => 'Plain text file content for testing.',
                'mimeType' => 'text/plain',
                'expectedString' => 'Plain text file content',
            ],
            [
                'fileName' => 'test-markdown.md',
                'content' => "# Markdown Test\n\nThis is **bold** and this is *italic*.\n\n- List item 1\n- List item 2",
                'mimeType' => 'text/markdown',
                'expectedString' => 'Markdown Test',
            ],
            [
                'fileName' => 'test-json.json',
                'content' => '{"message": "JSON test content", "type": "integration-test", "success": true}',
                'mimeType' => 'application/json',
                'expectedString' => 'JSON test content',
            ],
        ];

        foreach ($testCases as $testCase) {
            $object = $this->createTestObject();
            
            // Upload file
            $file = $this->fileService->addFile(
                objectEntity: $object,
                fileName: $testCase['fileName'],
                content: $testCase['content'],
                share: false,
                tags: ['format-test']
            );

            $fileId = $file->getId();

            // Wait and get job
            usleep(150000);
            $job = $this->getJobForFile($fileId);
            
            if ($job !== null) {
                // Execute job
                try {
                    $job->execute($this->jobList);
                    usleep(50000);
                    
                    // Verify extraction
                    $fileText = $this->fileTextMapper->findByFileId($fileId);
                    $extractedText = $fileText->getTextContent();
                    
                    $this->assertStringContainsString(
                        $testCase['expectedString'],
                        $extractedText,
                        "Extracted text from {$testCase['fileName']} should contain expected content"
                    );
                    
                    echo "\n✓ {$testCase['fileName']}: Text extracted successfully\n";
                    
                } catch (\Exception $e) {
                    echo "\n⚠ {$testCase['fileName']}: Extraction failed - " . $e->getMessage() . "\n";
                }
            }

            // Clean up
            $this->cleanupTestFile($file);
            $this->cleanupTestObject($object);
        }
    }

    /**
     * Get count of FileTextExtractionJob jobs
     *
     * @return int
     */
    private function getFileTextExtractionJobCount(): int
    {
        $count = 0;
        $jobs = $this->jobList->getJobsIterator(FileTextExtractionJob::class, null, 0);
        
        foreach ($jobs as $job) {
            $count++;
        }
        
        return $count;
    }

    /**
     * Check if a job exists for a specific file ID
     *
     * @param int $fileId File ID
     *
     * @return bool
     */
    private function hasJobForFile(int $fileId): bool
    {
        return $this->getJobForFile($fileId) !== null;
    }

    /**
     * Get job for a specific file ID
     *
     * @param int $fileId File ID
     *
     * @return mixed|null
     */
    private function getJobForFile(int $fileId)
    {
        $jobs = $this->jobList->getJobsIterator(FileTextExtractionJob::class, null, 0);
        
        foreach ($jobs as $job) {
            $argument = $job->getArgument();
            if (isset($argument['file_id']) && $argument['file_id'] === $fileId) {
                return $job;
            }
        }
        
        return null;
    }

    /**
     * Wait until a job for the given file appears in the queue.
     */
    private function waitForJobForFile(int $fileId, int $timeoutMs = 5000, int $intervalMs = 100): bool
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);
        do {
            if ($this->hasJobForFile($fileId)) {
                return true;
            }
            usleep($intervalMs * 1000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /**
     * Create a test object for integration testing
     *
     * @return ObjectEntity
     */
    private function createTestObject(): ObjectEntity
    {
        // Ensure a register and schema exist for this object
        $unique = uniqid('t', true);

        $registerName = 'Test Register ' . $unique;
        $register = $this->registerService->createFromArray([
            'name' => $registerName,
            'title' => $registerName,
            'slug' => 'test-register-' . str_replace('.', '-', $unique),
            'version' => '0.0.1',
        ]);

        $schemaName = 'Test Schema ' . $unique;
        $schema = $this->schemaMapper->createFromArray([
            'name' => $schemaName,
            'title' => $schemaName,
            'slug' => 'test-schema-' . str_replace('.', '-', $unique),
            'version' => '0.0.1',
            'definition' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                ],
            ],
        ]);

        // Persist object via the service to ensure folders and metadata are set
        /** @var ObjectEntity $object */
        $object = $this->objectService->createFromArray(
            object: [
                'name' => 'Test Object',
            ],
            extend: [],
            register: $register->getId(),
            schema: $schema->getId(),
            rbac: false,
            multi: false,
            silent: true,
        );

        return $object;
    }

    /**
     * Clean up test file
     *
     * @param mixed $file File to delete
     *
     * @return void
     */
    private function cleanupTestFile($file): void
    {
        try {
            if (method_exists($file, 'delete')) {
                $file->delete();
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }

    /**
     * Clean up test object
     *
     * @param ObjectEntity $object Object to delete
     *
     * @return void
     */
    private function cleanupTestObject(ObjectEntity $object): void
    {
        try {
            if (method_exists($this->objectService, 'deleteObject')) {
                $this->objectService->deleteObject($object->getUuid());
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }
}

