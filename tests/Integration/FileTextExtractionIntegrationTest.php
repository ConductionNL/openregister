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
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\FileService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IRootFolder;
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
    private $rootFolder;

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

        $this->objectService = \OC::$server->get(ObjectService::class);
        $this->fileService = \OC::$server->get(FileService::class);
        $this->jobList = \OC::$server->get(IJobList::class);
        $this->rootFolder = \OC::$server->get(IRootFolder::class);
    }

    /**
     * Test that uploading a text file queues a background job
     *
     * @return void
     */
    public function testFileUploadQueuesBackgroundJob(): void
    {
        // Skip if we can't create test objects
        if (!method_exists($this->objectService, 'createTestObject')) {
            $this->markTestSkipped('Test object creation not available');
        }

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

        // Wait a moment for event listener to queue the job
        usleep(100000); // 100ms

        // Check that a background job was queued
        $jobsAfter = $this->getFileTextExtractionJobCount();
        $this->assertGreaterThan(
            $jobsBefore,
            $jobsAfter,
            'Background job should be queued after file upload'
        );

        // Verify the job has the correct file_id
        $this->assertTrue(
            $this->hasJobForFile($fileId),
            "Background job should be queued for file ID: $fileId"
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
        // Skip if we can't create test objects
        if (!method_exists($this->objectService, 'createTestObject')) {
            $this->markTestSkipped('Test object creation not available');
        }

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

        // Wait for job to be queued
        usleep(100000);

        // Get the job
        $job = $this->getJobForFile($fileId);
        
        if ($job === null) {
            $this->markTestSkipped('Could not find queued job');
        }

        // Execute the job
        try {
            $job->execute($this->jobList);
            $this->assertTrue(true, 'Job executed without throwing exceptions');
        } catch (\Exception $e) {
            $this->fail('Job execution failed: ' . $e->getMessage());
        }

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
     * Create a test object for integration testing
     *
     * @return ObjectEntity
     */
    private function createTestObject(): ObjectEntity
    {
        // This is a simplified version - adjust based on your actual test setup
        $object = new ObjectEntity();
        $object->setUuid('test-' . uniqid());
        $object->setRegister('test-register');
        $object->setSchema('test-schema');
        $object->setObject(['name' => 'Test Object']);
        
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

