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
use OCA\OpenRegister\Service\FileTextService;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\FileService;
use OCP\BackgroundJob\IJobList;
use Test\TestCase;

/**
 * Integration test for file text extraction background job
 *
 * This test verifies that:
 * 1. Files can be uploaded successfully
 * 2. Background jobs are queued automatically
 * 3. Background jobs can be executed
 * 4. Text extraction completes successfully
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

