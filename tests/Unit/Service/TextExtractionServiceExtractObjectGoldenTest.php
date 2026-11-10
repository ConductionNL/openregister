<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Tests\Unit\Service\Support\TextExtractionServiceFactory;
use DateTime;
use Exception;
use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Records the log lines, chunk writes and entity calls of extractObject(), per scenario,
 * against fixtures/text-extraction-object-golden.json. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Files\Service\TextExtractionService
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\ObjectHandler
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor
 * @uses   \OCA\OpenRegister\Files\Db\Chunk
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class TextExtractionServiceExtractObjectGoldenTest extends TestCase {

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
		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('find')->willReturnCallback(function ($id) use ($s) {
			$this->calls[] = ['object.find', $id];
			if ($s['missing'] === true) {
				throw new DoesNotExistException('gone');
			}
			$object = new ObjectEntity();
			if ($s['updated'] !== null) {
				$object->setUpdated((new DateTime())->setTimestamp($s['updated']));
			}
			$object->setUuid('obj-uuid');
			$object->setOwner('alice');
			$object->setOrganisation('org-1');
			$object->setObject($s['data']);
			return $object;
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

		return TextExtractionServiceFactory::create(
			$this->createMock(FileMapper::class),
			$chunkMapper,
			$this->createMock(IRootFolder::class),
			$this->createMock(IDBConnection::class),
			$logger,
			$objectMapper,
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$entityHandler,
			$this->createMock(SettingsService::class),
			$this->createMock(RiskLevelService::class),
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
			'missing' => false,
			'updated' => 100,
			'latest' => null,
			'force' => false,
			'entityFail' => false,
			'data' => ['subject' => 'Een besluit over een zaak.', 'note' => 'The quick brown fox jumps over the lazy dog.'],
		];
		return [
			'deleted object' => [['missing' => true] + $base],
			'up to date' => [['latest' => 150] + $base],
			'stale chunks' => [['latest' => 50] + $base],
			'forced while up to date' => [['latest' => 150, 'force' => true] + $base],
			'no updated timestamp' => [['updated' => null, 'latest' => 10] + $base],
			'entity pass fails' => [['entityFail' => true] + $base],
			'empty object' => [['data' => []] + $base],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testExtractObjectGolden(array $s): void {
		$service = $this->service($s);
		try {
			$service->extractObject(42, $s['force']);
			$this->calls[] = ['returned'];
		} catch (Exception $e) {
			$this->calls[] = ['thrown', get_class($e), $e->getMessage()];
		}

		$file = __DIR__ . '/fixtures/text-extraction-object-golden.json';
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
