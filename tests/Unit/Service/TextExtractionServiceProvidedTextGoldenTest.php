<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
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
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Records the log lines, chunk writes, entity and risk-level calls of extractFromProvidedText(),
 * per scenario, against fixtures/text-extraction-provided-text-golden.json. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Files\Service\TextExtractionService
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor
 * @uses   \OCA\OpenRegister\Files\Db\Chunk
 */
class TextExtractionServiceProvidedTextGoldenTest extends TestCase {

	/**
	 * @var list<array>
	 */
	private array $calls = [];

	/**
	 * Builds the service for one scenario.
	 *
	 * @param array $s Scenario settings.
	 *
	 * @return TextExtractionService
	 */
	private function service(array $s): TextExtractionService {
		$fileMapper = $this->createMock(FileMapper::class);
		$fileMapper->method('getFile')->willReturnCallback(function ($id) {
			$this->calls[] = ['file.get', $id];
			return ['fileid' => $id, 'name' => 'scan.pdf', 'mtime' => 1700000000, 'owner' => 'alice', 'mimetype' => 'application/pdf'];
		});

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getFileSettingsOnly')->willReturnCallback(function () use ($s) {
			$this->calls[] = ['settings.file'];
			if ($s['settingsFail'] === true) {
				throw new Exception('settings unavailable');
			}
			return $s['settings'];
		});

		$risk = $this->createMock(RiskLevelService::class);
		$risk->method('updateRiskLevel')->willReturnCallback(function ($fileId) use ($s) {
			$this->calls[] = ['risk.update', $fileId];
			if ($s['riskFail'] === true) {
				throw new Exception('risk unavailable');
			}
			return 'high';
		});

		$chunkMapper = $this->createMock(ChunkMapper::class);
		$chunkMapper->method('getLatestUpdatedTimestamp')->willReturnCallback(function ($sourceType, $sourceId) use ($s) {
			$this->calls[] = ['chunk.latest', $sourceType, $sourceId];
			return $s['latest'];
		});
		$chunkMapper->method('deleteBySource')->willReturnCallback(function ($sourceType, $sourceId) {
			$this->calls[] = ['chunk.delete', $sourceType, $sourceId];
			return 0;
		});
		$chunkMapper->method('insert')->willReturnCallback(function (Chunk $chunk) {
			$row = $chunk->jsonSerialize();
			unset($row['uuid'], $row['createdAt'], $row['updatedAt']);
			$row['text'] = $chunk->getTextContent();
			$row['detectionMethod'] = $chunk->getDetectionMethod();
			$this->calls[] = ['chunk.insert', $row];
			return $chunk;
		});

		$entityHandler = $this->createMock(EntityRecognitionHandler::class);
		$entityHandler->method('processSourceChunks')->willReturnCallback(function ($sourceType, $sourceId, $options) use ($s) {
			$this->calls[] = ['entities', $sourceType, $sourceId, $options];
			if ($s['entityFail'] === true) {
				throw new Exception('entity recognition unavailable');
			}
			return ['entities_found' => 3, 'relations_created' => 2];
		});

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level) {
				unset($context['file'], $context['line'], $context['chunking_time_ms']);
				$this->calls[] = ['log.' . $level, $message, $context];
			});
		}

		return new TextExtractionService(
			$fileMapper,
			$chunkMapper,
			$this->createMock(IRootFolder::class),
			$this->createMock(IDBConnection::class),
			$logger,
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$entityHandler,
			$settings,
			$risk,
			$this->createMock(EmlParser::class),
			new SpreadsheetExtractor($logger),
			new PdfExtractor($logger),
			new WordExtractor($logger)
		);
	}

	/**
	 * Scenario table.
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$base = [
			'latest' => null,
			'entityFail' => false,
			'settingsFail' => false,
			'riskFail' => false,
			'settings' => ['entityRecognitionEnabled' => true, 'entityRecognitionMethod' => 'llm'],
			'types' => null,
			'text' => 'Jan de Vries woont in Utrecht en werkt bij de gemeente. The quick brown fox jumps over the lazy dog.',
		];
		return [
			'recognition disabled' => [['settings' => ['entityRecognitionEnabled' => false]] + $base],
			'recognition default method' => [['settings' => ['entityRecognitionEnabled' => true]] + $base],
			'recognition with types' => [['types' => ['PERSON', 5 => 'EMAIL']] + $base],
			'entity pass fails' => [['entityFail' => true] + $base],
			'risk update fails' => [['riskFail' => true] + $base],
			'settings fail' => [['settingsFail' => true] + $base],
			'empty text' => [['text' => "  \n "] + $base],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testExtractFromProvidedTextGolden(array $s): void {
		$service = $this->service($s);
		try {
			$service->extractFromProvidedText(42, $s['text'], $s['types'], 'ocr');
			$this->calls[] = ['returned'];
		} catch (Exception $e) {
			$this->calls[] = ['thrown', get_class($e), $e->getMessage()];
		}

		$file = __DIR__ . '/fixtures/text-extraction-provided-text-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		$actual = json_decode(json_encode($this->calls), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}
		$this->assertSame($all[$name], $actual);
	}
}
