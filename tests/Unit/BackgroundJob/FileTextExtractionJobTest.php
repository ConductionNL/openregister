<?php

declare(strict_types=1);

/**
 * FileTextExtractionJob Unit Test
 *
 * Tests the background job that extracts text from uploaded files asynchronously.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 */

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\FileTextExtractionJob;
use OCA\OpenRegister\Service\FileTextService;
use OCA\OpenRegister\Db\FileText;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test class for FileTextExtractionJob
 *
 * @package OCA\OpenRegister\Tests\Unit\BackgroundJob
 */
class FileTextExtractionJobTest extends TestCase
{
    /**
     * @var FileTextService|MockObject
     */
    private $fileTextService;

    /**
     * @var LoggerInterface|MockObject
     */
    private $logger;

    /**
     * @var ITimeFactory|MockObject
     */
    private $timeFactory;

    /**
     * @var FileTextExtractionJob
     */
    private $job;

    /**
     * Set up test dependencies
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fileTextService = $this->createMock(FileTextService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);

        $this->job = new FileTextExtractionJob(
            $this->timeFactory,
            $this->fileTextService,
            $this->logger
        );
    }

    /**
     * Test successful text extraction
     *
     * @return void
     */
    public function testSuccessfulTextExtraction(): void
    {
        $fileId = 123;
        $argument = ['file_id' => $fileId];

        // Mock FileText result.
        $fileText = $this->createMock(FileText::class);
        $fileText->method('getTextLength')->willReturn(5000);

        // Mock that extraction is needed.
        $this->fileTextService
            ->expects($this->once())
            ->method('needsExtraction')
            ->with($fileId)
            ->willReturn(true);

        // Mock successful extraction.
        $this->fileTextService
            ->expects($this->once())
            ->method('extractAndStoreFileText')
            ->with($fileId)
            ->willReturn([
                'success' => true,
                'fileText' => $fileText,
            ]);

        // Expect success logging.
        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info')
            ->withConsecutive(
                [$this->stringContains('Starting text extraction')],
                [$this->stringContains('completed successfully')]
            );

        // Run the job.
        $this->job->start($argument);
    }

    /**
     * Test extraction when not needed (already processed)
     *
     * @return void
     */
    public function testExtractionNotNeeded(): void
    {
        $fileId = 456;
        $argument = ['file_id' => $fileId];

        // Mock that extraction is NOT needed.
        $this->fileTextService
            ->expects($this->once())
            ->method('needsExtraction')
            ->with($fileId)
            ->willReturn(false);

        // Should NOT call extractAndStoreFileText.
        $this->fileTextService
            ->expects($this->never())
            ->method('extractAndStoreFileText');

        // Expect info logging that extraction not needed.
        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info')
            ->withConsecutive(
                [$this->stringContains('Starting text extraction')],
                [$this->stringContains('no longer needed')]
            );

        // Run the job.
        $this->job->start($argument);
    }

    /**
     * Test failed text extraction
     *
     * @return void
     */
    public function testFailedTextExtraction(): void
    {
        $fileId = 789;
        $argument = ['file_id' => $fileId];

        // Mock that extraction is needed.
        $this->fileTextService
            ->expects($this->once())
            ->method('needsExtraction')
            ->with($fileId)
            ->willReturn(true);

        // Mock failed extraction.
        $this->fileTextService
            ->expects($this->once())
            ->method('extractAndStoreFileText')
            ->with($fileId)
            ->willReturn([
                'success' => false,
                'error' => 'Unsupported file format',
            ]);

        // Expect warning logging.
        $this->logger
            ->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('Text extraction failed'));

        // Run the job.
        $this->job->start($argument);
    }

    /**
     * Test exception handling
     *
     * @return void
     */
    public function testExceptionHandling(): void
    {
        $fileId = 999;
        $argument = ['file_id' => $fileId];
        $exceptionMessage = 'Database connection failed';

        // Mock that extraction is needed.
        $this->fileTextService
            ->expects($this->once())
            ->method('needsExtraction')
            ->with($fileId)
            ->willReturn(true);

        // Mock exception during extraction.
        $this->fileTextService
            ->expects($this->once())
            ->method('extractAndStoreFileText')
            ->with($fileId)
            ->willThrowException(new \Exception($exceptionMessage));

        // Expect error logging.
        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Exception during text extraction'),
                $this->callback(function ($context) use ($fileId, $exceptionMessage) {
                    return $context['file_id'] === $fileId &&
                           $context['error'] === $exceptionMessage;
                })
            );

        // Run the job (should not throw exception).
        $this->job->start($argument);
    }

    /**
     * Test missing file_id in arguments
     *
     * @return void
     */
    public function testMissingFileIdArgument(): void
    {
        $argument = []; // Missing file_id

        // Should NOT call any service methods.
        $this->fileTextService
            ->expects($this->never())
            ->method('needsExtraction');

        $this->fileTextService
            ->expects($this->never())
            ->method('extractAndStoreFileText');

        // Expect error logging.
        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Missing file_id'));

        // Run the job.
        $this->job->start($argument);
    }
}

