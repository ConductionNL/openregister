<?php

/**
 * The extraction jobs reach the ceiling: a file above it, extracted by the
 * queued job or picked up by the cron job, is never opened and keeps its
 * metadata chunk marked skipped-too-large.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-text-extraction-has-a-stated-ceiling-and-a-file-above-it-stays-findable-req-lfh-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ConfiguredJobRuntime;
use OCA\OpenRegister\BackgroundJob\FileTextExtractionJob;
use OCA\OpenRegister\Files\BackgroundJob\CronFileTextExtractionJob;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Service\RiskLevelService;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\ExtractionCeiling;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Tests\Unit\Service\Support\TextExtractionServiceFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\FileTextExtractionJob
 * @covers \OCA\OpenRegister\BackgroundJob\AbstractTextExtractionJob
 * @covers \OCA\OpenRegister\Files\BackgroundJob\CronFileTextExtractionJob
 * @uses   \OCA\OpenRegister\BackgroundJob\ConfiguredJobRuntime
 * @uses   \OCA\OpenRegister\Files\Service\TextExtractionService
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\ExtractionCeiling
 * @uses   \OCA\OpenRegister\Files\Service\PendingExtractionWalk
 * @uses   \OCA\OpenRegister\Files\BackgroundJob\CronExtractionRun
 * @uses   \OCA\OpenRegister\Files\Db\Chunk
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Files\Service\ChunkSizing
 * @uses   \OCA\OpenRegister\Files\Service\ChunkingPlan
 * @uses   \OCA\OpenRegister\Files\Service\SplitLevel
 * @uses   \OCA\OpenRegister\Files\Service\TextSplitPass
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\ChunkSource
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\SourceText
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionAnalysis
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionObjects
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionReaders
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionSources
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionStorage
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses   \OCA\OpenRegister\Files\Db\FileSourceOrder
 * @uses   \OCA\OpenRegister\Files\Service\FileExtractionOptions
 * @uses   \OCA\OpenRegister\Files\Service\SourceStamp
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\ChunkOwnership
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
final class FileTextExtractionCeilingTest extends TestCase {

	/**
	 * The chunks the service stored.
	 *
	 * @var array<int, Chunk>
	 */
	private array $inserted = [];

	/**
	 * The file mapper of the last service built, so a test can add answers.
	 *
	 * @var FileMapper|null
	 */
	private ?FileMapper $lastFiles = null;

	private function service(int $size, ?File $node, bool $unreadable = false): TextExtractionService {
		$files = $this->createMock(FileMapper::class);
		$this->lastFiles = $files;
		$files->method('findUntrackedFiles')->willReturnOnConsecutiveCalls([['fileid' => 7, 'name' => 'raadsdossier-2024.txt']], []);
		$files->method('getFile')->willReturn(
			['fileid' => 7, 'name' => 'raadsdossier-2024.txt', 'path' => 'files/raadsdossier-2024.txt', 'mimetype' => 'text/plain', 'size' => $size, 'mtime' => 1700000000, 'owner' => null]
		);

		$chunks = $this->createMock(ChunkMapper::class);
		$chunks->method('getLatestUpdatedTimestamp')->willReturn(null);
		$chunks->method('insert')->willReturnCallback(
			function (Chunk $chunk): Chunk {
				$this->inserted[] = $chunk;
				return $chunk;
			}
		);

		$root = $this->createMock(IRootFolder::class);
		if ($node === null) {
			$root->expects($this->never())->method('getById');
			$root->expects($this->never())->method('getUserFolder');
		} else {
			$root->method('getById')->willReturn([$node]);
		}

		$settings = $this->createMock(SettingsService::class);
		if ($unreadable === true) {
			$settings->method('getFileSettingsOnly')->willThrowException(new RuntimeException('appconfig gone'));
		} else {
			$settings->method('getFileSettingsOnly')->willReturn(['textExtraction' => ['maxBytes' => 1000], 'entityRecognitionEnabled' => false, 'extractionMode' => 'cron', 'batchSize' => 10]);
		}

		$logger = $this->createMock(LoggerInterface::class);

		return TextExtractionServiceFactory::create(
			$files,
			$chunks,
			$root,
			$this->createMock(IDBConnection::class),
			$logger,
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(EntityRecognitionHandler::class),
			$settings,
			$this->createMock(RiskLevelService::class),
			$this->createMock(EmlParser::class),
			new SpreadsheetExtractor($logger),
			new PdfExtractor($logger),
			new WordExtractor($logger)
		);
	}//end service()

	private function metadataChunk(): array {
		foreach ($this->inserted as $chunk) {
			if (($chunk->getPositionReference()['type'] ?? null) === 'metadata') {
				return json_decode((string) $chunk->getTextContent(), true);
			}
		}

		$this->fail(message: 'no metadata chunk was stored');
	}//end metadataChunk()

	/**
	 * The queued job, its runtime reading file settings that switch extraction on.
	 *
	 * @param TextExtractionService $service The extraction service.
	 *
	 * @return FileTextExtractionJob
	 */
	private function queuedJob(TextExtractionService $service): FileTextExtractionJob {
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturn(true);
		$config->method('getValueString')->willReturn((string) json_encode(['extractionScope' => 'objects']));

		$runtime = new ConfiguredJobRuntime(
			time: $this->createMock(ITimeFactory::class),
			appConfig: $config,
			logger: $this->createMock(LoggerInterface::class)
		);

		return new FileTextExtractionJob(runtime: $runtime, textExtractor: $service);
	}//end queuedJob()

	/**
	 * The queued extraction job on a file over the ceiling: never opened, metadata chunk only, marked.
	 *
	 * @return void
	 */
	public function testTheQueuedJobLeavesAFileOverTheCeilingUnopened(): void {
		$job = $this->queuedJob(service: $this->service(size: 5000, node: null));

		(new \ReflectionMethod($job, 'run'))->invoke($job, ['file_id' => 7]);

		$this->assertCount(1, $this->inserted, 'only the metadata chunk is written');
		$metadata = $this->metadataChunk();
		$this->assertSame(ExtractionCeiling::SKIPPED_TOO_LARGE, $metadata['extraction_status']);
		$this->assertSame(1000, $metadata['ceiling_bytes']);
	}//end testTheQueuedJobLeavesAFileOverTheCeilingUnopened()

	/**
	 * The cron job picks the file up from the untracked files and leaves it unopened the same way.
	 *
	 * @return void
	 */
	public function testTheCronJobLeavesAFileOverTheCeilingUnopened(): void {
		$service = $this->service(size: 5000, node: null);
		$settings = $this->createMock(AppSettingsInterface::class);
		$settings->method('getFileSettingsOnly')->willReturn(['extractionMode' => 'cron', 'batchSize' => 10, 'extractionScope' => 'objects']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				LoggerInterface::class => $this->createMock(LoggerInterface::class),
				AppSettingsInterface::class => $settings,
				TextExtractionService::class => $service,
			}
		);
		$job = new CronFileTextExtractionJob($this->createMock(ITimeFactory::class), $container);

		(new \ReflectionMethod($job, 'run'))->invoke($job, []);

		$this->assertCount(1, $this->inserted, 'only the metadata chunk is written');
		$this->assertSame(ExtractionCeiling::SKIPPED_TOO_LARGE, $this->metadataChunk()['extraction_status']);
	}//end testTheCronJobLeavesAFileOverTheCeilingUnopened()
}//end class
