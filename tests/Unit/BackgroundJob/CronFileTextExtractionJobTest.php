<?php

declare(strict_types=1);

/**
 * CronFileTextExtractionJob Unit Tests
 *
 * Tests the recurring background job that processes pending files for text extraction
 * when extraction mode is set to 'cron'.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\CronFileTextExtractionJob;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Test class for CronFileTextExtractionJob
 */
class CronFileTextExtractionJobTest extends TestCase
{
    private SettingsService&MockObject $settingsService;
    private TextExtractionService&MockObject $textExtractor;
    private FileMapper&MockObject $fileMapper;
    private LoggerInterface&MockObject $logger;
    private CronFileTextExtractionJob $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsService = $this->createMock(SettingsService::class);
        $this->textExtractor   = $this->createMock(TextExtractionService::class);
        $this->fileMapper      = $this->createMock(FileMapper::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        // Register all mocks in the Nextcloud DI container.
        \OC::$server->registerService(SettingsService::class, function () {
            return $this->settingsService;
        });
        \OC::$server->registerService(TextExtractionService::class, function () {
            return $this->textExtractor;
        });
        \OC::$server->registerService(FileMapper::class, function () {
            return $this->fileMapper;
        });
        \OC::$server->registerService(LoggerInterface::class, function () {
            return $this->logger;
        });

        $timeFactory = $this->createMock(ITimeFactory::class);
        $this->job   = new CronFileTextExtractionJob($timeFactory);
    }

    /**
     * Invoke the protected run() method via reflection.
     */
    private function runJob(mixed $argument = []): void
    {
        $ref    = new ReflectionClass($this->job);
        $method = $ref->getMethod('run');
        $method->setAccessible(true);
        $method->invoke($this->job, $argument);
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function testIntervalIsSetToFifteenMinutes(): void
    {
        $ref      = new ReflectionClass($this->job);
        $property = $ref->getProperty('interval');
        $property->setAccessible(true);

        $this->assertSame(15 * 60, $property->getValue($this->job));
    }

    // -------------------------------------------------------------------------
    // Early exit: extraction mode != 'cron'
    // -------------------------------------------------------------------------

    public function testRunSkipsWhenExtractionModeIsBackground(): void
    {
        $this->settingsService
            ->method('getFileSettingsOnly')
            ->willReturn(['extractionMode' => 'background', 'batchSize' => 10]);

        $this->fileMapper
            ->expects($this->never())
            ->method('findUntrackedFiles');

        $this->textExtractor
            ->expects($this->never())
            ->method('extractFile');

        $this->runJob();
    }

    public function testRunSkipsWhenExtractionModeIsNone(): void
    {
        $this->settingsService
            ->method('getFileSettingsOnly')
            ->willReturn(['extractionMode' => 'none']);

        $this->textExtractor
            ->expects($this->never())
            ->method('extractFile');

        $this->runJob();
    }

    public function testRunSkipsWhenExtractionModeIsNotSet(): void
    {
        // Default is 'background', so without 'cron' key the job skips.
        $this->settingsService
            ->method('getFileSettingsOnly')
            ->willReturn([]);

        $this->textExtractor
            ->expects($this->never())
            ->method('extractFile');

        $this->runJob();
    }

    // -------------------------------------------------------------------------
    // Empty pending files list
    // -------------------------------------------------------------------------

    public function testRunReturnsEarlyWhenNoPendingFiles(): void
    {
        $this->settingsService
            ->method('getFileSettingsOnly')
            ->willReturn(['extractionMode' => 'cron', 'batchSize' => 10]);

        $this->fileMapper
            ->method('findUntrackedFiles')
            ->willReturn([]);

        $this->textExtractor
            ->expects($this->never())
            ->method('extractFile');

        $this->runJob();
    }

    // -------------------------------------------------------------------------
    // Happy path: files processed
    // -------------------------------------------------------------------------

    public function testRunProcessesPendingFiles(): void
    {
        $this->settingsService
            ->method('getFileSettingsOnly')
            ->willReturn(['extractionMode' => 'cron', 'batchSize' => 10]);

        $this->fileMapper
            ->method('findUntrackedFiles')
            ->with(limit: 10)
            ->willReturn([
                ['fileid' => 1, 'name' => 'doc1.pdf'],
                ['fileid' => 2, 'name' => 'doc2.pdf'],
            ]);

        $this->textExtractor
            ->expects($this->exactly(2))
            ->method('extractFile')
            ->with($this->isType('int'), forceReExtract: false);

        $this->runJob();
    }

    public function testRunUsesDefaultBatchSizeWhenNotConfigured(): void
    {
        $this->settingsService
            ->method('getFileSettingsOnly')
            ->willReturn(['extractionMode' => 'cron']);

        $this->fileMapper
            ->expects($this->once())
            ->method('findUntrackedFiles')
            ->with(limit: 10)  // DEFAULT_BATCH_SIZE = 10
            ->willReturn([]);

        $this->runJob();
    }

    public function testRunSkipsFilesWithZeroFileId(): void
    {
        $this->settingsService
            ->method('getFileSettingsOnly')
            ->willReturn(['extractionMode' => 'cron', 'batchSize' => 10]);

        $this->fileMapper
            ->method('findUntrackedFiles')
            ->willReturn([
                ['fileid' => 0, 'name' => 'bad.pdf'],
                ['fileid' => 5, 'name' => 'good.pdf'],
            ]);

        // Only the file with id=5 should be extracted.
        $this->textExtractor
            ->expects($this->once())
            ->method('extractFile')
            ->with(fileId: 5, forceReExtract: false);

        $this->runJob();
    }

    // -------------------------------------------------------------------------
    // Per-file exception handling
    // -------------------------------------------------------------------------

    public function testRunContinuesProcessingAfterPerFileException(): void
    {
        $this->settingsService
            ->method('getFileSettingsOnly')
            ->willReturn(['extractionMode' => 'cron', 'batchSize' => 10]);

        $this->fileMapper
            ->method('findUntrackedFiles')
            ->willReturn([
                ['fileid' => 10, 'name' => 'fail.pdf'],
                ['fileid' => 11, 'name' => 'ok.pdf'],
            ]);

        $callCount = 0;
        $this->textExtractor
            ->method('extractFile')
            ->willReturnCallback(static function (int $fileId) use (&$callCount): void {
                $callCount++;
                if ($fileId === 10) {
                    throw new \Exception('Extraction failed for file 10');
                }
            });

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('error');

        $this->runJob();

        $this->assertSame(2, $callCount, 'Both files should be attempted');
    }

    // -------------------------------------------------------------------------
    // Outer exception handling (e.g. SettingsService fails)
    // -------------------------------------------------------------------------

    public function testRunDoesNotPropagateOuterException(): void
    {
        $this->settingsService
            ->method('getFileSettingsOnly')
            ->willThrowException(new \Exception('Config store unavailable'));

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('error');

        // Must not rethrow for recurring jobs.
        $this->runJob();
        $this->assertTrue(true);
    }

    public function testRunDoesNotPropagateFileMapperException(): void
    {
        $this->settingsService
            ->method('getFileSettingsOnly')
            ->willReturn(['extractionMode' => 'cron', 'batchSize' => 5]);

        $this->fileMapper
            ->method('findUntrackedFiles')
            ->willThrowException(new \Exception('DB query failed'));

        // getPendingFiles catches the exception and returns [], so no files extracted.
        $this->textExtractor
            ->expects($this->never())
            ->method('extractFile');

        $this->runJob();
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Completion logging
    // -------------------------------------------------------------------------

    public function testRunLogsCompletionWithProcessedAndFailedCounts(): void
    {
        $this->settingsService
            ->method('getFileSettingsOnly')
            ->willReturn(['extractionMode' => 'cron', 'batchSize' => 10]);

        $this->fileMapper
            ->method('findUntrackedFiles')
            ->willReturn([
                ['fileid' => 1, 'name' => 'a.pdf'],
                ['fileid' => 2, 'name' => 'b.pdf'],
            ]);

        $this->textExtractor
            ->method('extractFile')
            ->willReturnCallback(static function (int $fileId): void {
                if ($fileId === 2) {
                    throw new \Exception('fail');
                }
            });

        $completionContext = null;
        $this->logger
            ->method('info')
            ->willReturnCallback(static function (string $message, array $context = []) use (&$completionContext): void {
                if (isset($context['files_processed'], $context['files_failed'])) {
                    $completionContext = $context;
                }
            });

        $this->runJob();

        $this->assertNotNull($completionContext);
        $this->assertSame(1, $completionContext['files_processed']);
        $this->assertSame(1, $completionContext['files_failed']);
    }
}
