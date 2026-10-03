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
use OCA\OpenRegister\Tests\Unit\Support\RegistersContainerServices;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Test class for CronFileTextExtractionJob
 */
class CronFileTextExtractionJobTest extends TestCase {
	use RegistersContainerServices;

	private SettingsService&MockObject $settingsService;
	private TextExtractionService&MockObject $textExtractor;
	private FileMapper&MockObject $fileMapper;
	private LoggerInterface&MockObject $logger;
	private CronFileTextExtractionJob $job;

	protected function setUp(): void {
		parent::setUp();

		$this->settingsService = $this->createMock(SettingsService::class);
		$this->textExtractor = $this->createMock(TextExtractionService::class);
		$this->fileMapper = $this->createMock(FileMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Register all mocks in the Nextcloud DI container.
		$this->registerService(SettingsService::class, function () {
			return $this->settingsService;
		});
		$this->registerService(TextExtractionService::class, function () {
			return $this->textExtractor;
		});
		$this->registerService(FileMapper::class, function () {
			return $this->fileMapper;
		});
		$this->registerService(LoggerInterface::class, function () {
			return $this->logger;
		});

		$timeFactory = $this->createMock(ITimeFactory::class);
		$this->job = new CronFileTextExtractionJob($timeFactory, $this->containerMock());
	}

	/**
	 * Invoke the protected run() method via reflection.
	 */
	private function runJob(mixed $argument = []): void {
		$ref = new ReflectionClass($this->job);
		$method = $ref->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($this->job, $argument);
	}

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	public function testIntervalIsSetToFifteenMinutes(): void {
		$ref = new ReflectionClass($this->job);
		$property = $ref->getProperty('interval');
		$property->setAccessible(true);

		$this->assertSame(15 * 60, $property->getValue($this->job));
	}

	// -------------------------------------------------------------------------
	// Early exit: extraction mode != 'cron'
	// -------------------------------------------------------------------------

	public function testRunSkipsWhenExtractionModeIsBackground(): void {
		$this->settingsService
			->method('getFileSettingsOnly')
			->willReturn(['extractionMode' => 'background', 'batchSize' => 10]);

		$this->fileMapper
			->expects($this->never())
			->method('findUntrackedFiles');

		$this->textExtractor
			->expects($this->never())
			->method('extractPendingFiles');

		$this->runJob();
	}

	public function testRunSkipsWhenExtractionModeIsNone(): void {
		$this->settingsService
			->method('getFileSettingsOnly')
			->willReturn(['extractionMode' => 'none']);

		$this->textExtractor
			->expects($this->never())
			->method('extractPendingFiles');

		$this->runJob();
	}

	public function testRunSkipsWhenExtractionModeIsNotSet(): void {
		// Default is 'background', so without 'cron' key the job skips.
		$this->settingsService
			->method('getFileSettingsOnly')
			->willReturn([]);

		$this->textExtractor
			->expects($this->never())
			->method('extractPendingFiles');

		$this->runJob();
	}

	// -------------------------------------------------------------------------
	// Empty pending files list
	// -------------------------------------------------------------------------


	// -------------------------------------------------------------------------
	// Happy path: files processed
	// -------------------------------------------------------------------------




	// -------------------------------------------------------------------------
	// Per-file exception handling
	// -------------------------------------------------------------------------


	// -------------------------------------------------------------------------
	// The selection loop lives in TextExtractionService (WOO-576)
	// -------------------------------------------------------------------------

	public function testRunDelegatesTheBatchToTheWindowedExtractor(): void {
		$this->settingsService
			->method('getFileSettingsOnly')
			->willReturn(['extractionMode' => 'cron', 'batchSize' => 25]);
		// The job no longer takes one window of findUntrackedFiles() and walks it
		// itself: that window filled up with the same unreadable low-fileid files
		// on every run, and a newer upload was never reached. The windowed loop
		// in extractPendingFiles() steps past failures, so the job hands the whole
		// batch to it and never touches the mapper directly.
		$this->fileMapper
			->expects($this->never())
			->method('findUntrackedFiles');
		$this->textExtractor
			->expects($this->once())
			->method('extractPendingFiles')
			->with(limit: 25)
			->willReturn(['processed' => 2, 'failed' => 0, 'total' => 2]);
		$this->runJob();
	}

	public function testRunUsesDefaultBatchSizeWhenNotConfigured(): void {
		$this->settingsService
			->method('getFileSettingsOnly')
			->willReturn(['extractionMode' => 'cron']);
		$this->textExtractor
			->expects($this->once())
			->method('extractPendingFiles')
			->with(limit: 10)  // DEFAULT_BATCH_SIZE = 10
			->willReturn(['processed' => 0, 'failed' => 0, 'total' => 0]);
		$this->runJob();
	}

	public function testRunReportsNothingPendingWithoutACompletionLine(): void {
		$this->settingsService
			->method('getFileSettingsOnly')
			->willReturn(['extractionMode' => 'cron', 'batchSize' => 10]);
		$this->textExtractor
			->method('extractPendingFiles')
			->willReturn(['processed' => 0, 'failed' => 0, 'total' => 0]);
		$messages = [];
		$this->logger
			->method('info')
			->willReturnCallback(static function (string $message, array $context = []) use (&$messages): void {
				$messages[] = $message;
			});
		$this->runJob();
		$this->assertContains('[CronFileTextExtractionJob] No pending files found for cron extraction', $messages);
		$this->assertNotContains('[CronFileTextExtractionJob] ✅ Cron File Text Extraction Job Completed', $messages);
	}

	public function testRunDoesNotPropagateExtractorException(): void {
		$this->settingsService
			->method('getFileSettingsOnly')
			->willReturn(['extractionMode' => 'cron', 'batchSize' => 5]);
		$this->textExtractor
			->method('extractPendingFiles')
			->willThrowException(new \Exception('DB query failed'));
		$this->logger
			->expects($this->atLeastOnce())
			->method('error');
		// Must not rethrow for recurring jobs.
		$this->runJob();
		$this->assertTrue(true);
	}

	// -------------------------------------------------------------------------
	// Completion logging
	// -------------------------------------------------------------------------

	public function testRunLogsCompletionWithProcessedAndFailedCounts(): void {
		$this->settingsService
			->method('getFileSettingsOnly')
			->willReturn(['extractionMode' => 'cron', 'batchSize' => 10]);
		$this->textExtractor
			->method('extractPendingFiles')
			->willReturn(['processed' => 1, 'failed' => 1, 'total' => 2]);
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

	/**
	 * The cron path is the one that runs unattended, so a walk that stopped on
	 * MAX_PENDING_WINDOWS has to say so. Nothing carries the offset between
	 * ticks, so every following tick re-walks the same unextractable head of the
	 * queue and reports the same counters — a truncated run is indistinguishable
	 * from a finished one unless the flag is surfaced.
	 *
	 * @return void
	 */
	public function testRunWarnsWhenTheWalkWasTruncated(): void {
		$this->settingsService
			->method('getFileSettingsOnly')
			->willReturn(['extractionMode' => 'cron', 'batchSize' => 10]);
		$this->textExtractor
			->method('extractPendingFiles')
			->willReturn(['processed' => 0, 'failed' => 100, 'total' => 100, 'truncated' => true]);

		$warningContext = null;
		$this->logger
			->method('warning')
			->willReturnCallback(static function (string $message, array $context = []) use (&$warningContext): void {
				if (isset($context['truncated'])) {
					$warningContext = $context;
				}
			});
		$completionLine = null;
		$this->logger
			->method('info')
			->willReturnCallback(static function (string $message, array $context = []) use (&$completionLine): void {
				if (str_contains($message, 'Completed') === true) {
					$completionLine = $message;
				}
			});

		$this->runJob();

		$this->assertNull($completionLine, 'A truncated walk must not log the completion line.');
		$this->assertNotNull($warningContext);
		$this->assertTrue($warningContext['truncated']);
		$this->assertSame(0, $warningContext['files_processed']);
		$this->assertSame(100, $warningContext['files_failed']);
	}//end testRunWarnsWhenTheWalkWasTruncated()

	/**
	 * A complete walk keeps the info-level completion line, and carries the flag
	 * as false rather than leaving it out.
	 *
	 * @return void
	 */
	public function testCompletionLineCarriesTheTruncatedFlag(): void {
		$this->settingsService
			->method('getFileSettingsOnly')
			->willReturn(['extractionMode' => 'cron', 'batchSize' => 10]);
		$this->textExtractor
			->method('extractPendingFiles')
			->willReturn(['processed' => 2, 'failed' => 0, 'total' => 2, 'truncated' => false]);

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
		$this->assertArrayHasKey('truncated', $completionContext);
		$this->assertFalse($completionContext['truncated']);
	}//end testCompletionLineCarriesTheTruncatedFlag()

	// -------------------------------------------------------------------------
	// Outer exception handling (e.g. SettingsService fails)
	// -------------------------------------------------------------------------

	public function testRunDoesNotPropagateOuterException(): void {
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


	// -------------------------------------------------------------------------
	// Completion logging
	// -------------------------------------------------------------------------

}
