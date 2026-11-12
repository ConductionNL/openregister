<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Files\Service\RiskLevelService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCA\OpenRegister\Tests\Unit\Service\Support\TextExtractionServiceFactory;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use OCA\OpenRegister\Files\Service\ChunkSizing;
use OCA\OpenRegister\Files\Service\SplitLevel;

/**
 * Records extractPendingFiles() (windows asked, files extracted, log lines, result) and the
 * chunks of recursiveSplit(), per scenario, against fixtures/text-extraction-pending-split-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Files\Service\TextExtractionService
 * @uses   \OCA\OpenRegister\Files\Service\PendingExtractionWalk
 * @uses   \OCA\OpenRegister\Files\Service\TextSplitPass
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor
 */
class TextExtractionServicePendingAndSplitGoldenTest extends TestCase {

	/**
	 * @var list<array>
	 */
	private array $calls = [];

	/**
	 * Builds the service over a pool of untracked files; a file that extracts drops out of the pool.
	 *
	 * @param array $s Scenario settings: pool (list of rows), failing (list of file ids).
	 *
	 * @return TextExtractionService
	 */
	private function service(array $s): TextExtractionService {
		$pool = $s['pool'] ?? [];
		$fileMapper = $this->createMock(FileMapper::class);
		$fileMapper->method('findUntrackedFiles')->willReturnCallback(function ($limit, $offset) use (&$pool) {
			$this->calls[] = ['window', $limit, $offset];
			return array_slice(array_values($pool), $offset, $limit);
		});

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level) {
				unset($context['file'], $context['line']);
				$this->calls[] = ['log.' . $level, $message, $context];
			});
		}

		$service = $this->getMockBuilder(TextExtractionService::class)
			->setConstructorArgs(
				TextExtractionServiceFactory::args(
					$fileMapper,
					$this->createMock(ChunkMapper::class),
					$this->createMock(IRootFolder::class),
					$this->createMock(IDBConnection::class),
					$logger,
					$this->createMock(MagicMapper::class),
					$this->createMock(SchemaMapper::class),
					$this->createMock(RegisterMapper::class),
					$this->createMock(EntityRecognitionHandler::class),
					$this->createMock(SettingsService::class),
					$this->createMock(RiskLevelService::class),
					$this->createMock(EmlParser::class),
					new SpreadsheetExtractor($logger),
					new PdfExtractor($logger),
					new WordExtractor($logger),
				)
			)
			->onlyMethods(['extractFile'])
			->getMock();
		$failing = $s['failing'] ?? [];
		$service->method('extractFile')->willReturnCallback(function ($fileId, $options) use (&$pool, $failing) {
			$this->calls[] = ['extract', $fileId, $options->forceReExtract];
			if (in_array($fileId, $failing, true) === true) {
				throw new Exception('unreadable ' . $fileId);
			}
			unset($pool[$fileId]);
		});
		return $service;
	}

	/**
	 * A pool of untracked file rows keyed by file id.
	 *
	 * @param int $count Number of files.
	 *
	 * @return array<int, array>
	 */
	private static function pool(int $count): array {
		$pool = [];
		for ($id = 1; $id <= $count; $id++) {
			$pool[$id] = ['fileid' => $id, 'name' => 'f' . $id . '.pdf'];
		}
		return $pool;
	}

	/**
	 * Scenario table for extractPendingFiles().
	 *
	 * @return array<string, array>
	 */
	public static function pendingScenarios(): array {
		$noId = self::pool(3);
		$noId[2] = ['name' => 'no-id.pdf'];
		$zeroId = self::pool(2);
		$zeroId[1] = ['fileid' => 0];
		return [
			'empty pool' => [['limit' => 5]],
			'short window' => [['limit' => 5, 'pool' => self::pool(3)]],
			'two full windows' => [['limit' => 2, 'pool' => self::pool(5)]],
			'failures at the head step the offset' => [['limit' => 3, 'pool' => self::pool(9), 'failing' => [1, 2]]],
			'all failing walks every window' => [['limit' => 2, 'pool' => self::pool(30), 'failing' => range(1, 30)]],
			'window cap truncates' => [['limit' => 1, 'pool' => self::pool(15), 'failing' => range(1, 15)]],
			'budget used up in the last window' => [['limit' => 1, 'pool' => self::pool(15), 'failing' => range(1, 9)]],
			'budget reached mid window' => [['limit' => 2, 'pool' => self::pool(2)]],
			'row without file id' => [['limit' => 5, 'pool' => $noId]],
			'row with zero file id' => [['limit' => 5, 'pool' => $zeroId]],
			'row without name' => [['limit' => 5, 'pool' => [7 => ['fileid' => 7]], 'failing' => [7]]],
			'limit zero' => [['limit' => 0, 'pool' => self::pool(3)]],
		];
	}

	/**
	 * @dataProvider pendingScenarios
	 */
	public function testExtractPendingFilesGolden(array $s): void {
		$service = $this->service($s);
		$this->calls[] = ['result', $service->extractPendingFiles($s['limit'])];
		$this->assertGolden();
	}

	/**
	 * Scenario table for recursiveSplit().
	 *
	 * @return array<string, array>
	 */
	public static function splitScenarios(): array {
		$para = str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 4);
		$text = $para . "\n\n" . $para . "\n" . 'Kort. ' . $para . "\n\n" . str_repeat('x', 400) . "\n\n" . 'Slot zin hier! En nog een? Ja; zeker, klaar.';
		$seps = ["\n\n", "\n", '. ', '! ', '? ', '; ', ', ', ' '];
		return [
			'fits' => ['short text', $seps, 100, 10],
			'paragraphs with overlap' => [$text, $seps, 200, 30],
			'paragraphs without overlap' => [$text, $seps, 200, 0],
			'overlap larger than chunk' => [$text, $seps, 120, 150],
			'small chunks' => [$text, $seps, 150, 20],
			'no separators left' => [str_repeat('abcdefghij ', 100), [], 250, 50],
			'single separator down to fixed size' => [str_repeat('y', 700) . "\n\n" . str_repeat('z', 150), ["\n\n"], 250, 10],
			'tiny pieces dropped' => ["ab\n\ncd\n\n" . str_repeat('w', 200) . "\n\nef\n\n" . str_repeat('v', 120), ["\n\n"], 180, 5],
			'chunk at minimum size and overlap equal to it' => [str_repeat('a', 100) . "\n\n" . str_repeat('b', 150), ["\n\n"], 120, 100],
			'piece exactly filling a chunk' => [str_repeat('c', 120) . "\n\n" . str_repeat('d', 130), ["\n\n", ' '], 120, 0],
			'whitespace pieces' => ["   \n\n" . str_repeat('q', 150) . "\n\n   \n\n" . str_repeat('r', 150), ["\n\n", ' '], 160, 3],
		];
	}

	/**
	 * @dataProvider splitScenarios
	 */
	public function testRecursiveSplitGolden(string $text, array $separators, int $size, int $overlap): void {
		$service = $this->service([]);
		$method = new ReflectionMethod(TextExtractionService::class, 'recursiveSplit');
		$this->calls[] = ['chunks', $method->invoke($service, $text, new SplitLevel(separators: $separators, sizing: new ChunkSizing($size, $overlap)))];
		$this->assertGolden();
	}

	/**
	 * Compare the recorded calls with the fixture entry of this data set.
	 *
	 * @return void
	 */
	private function assertGolden(): void {
		$file = __DIR__ . '/fixtures/text-extraction-pending-split-golden.json';
		$name = $this->name() . ':' . $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		$actual = json_decode(json_encode($this->calls, JSON_UNESCAPED_UNICODE), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}
		$this->assertSame($all[$name], $actual);
	}
}
