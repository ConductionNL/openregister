<?php

/**
 * EntityRecognitionChunkLogGoldenTest
 *
 * Characterisation test for {@see EntityRecognitionHandler::processSourceChunks()}
 * and {@see EntityRecognitionHandler::extractFromChunk()}: the results and the
 * log lines (source line left out) for a source holding a metadata chunk, a
 * chunk that fails, an empty chunk and a chunk without entities, and the
 * options a detection receives.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use Exception;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionBackends;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionStores;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Pins the chunk loop and per-chunk extraction of EntityRecognitionHandler.
 */
class EntityRecognitionChunkLogGoldenTest extends TestCase {

	/**
	 * The captured log lines.
	 *
	 * @var list<string>
	 */
	private array $lines = [];

	/**
	 * The chunk store.
	 *
	 * @var ChunkMapper&MockObject
	 */
	private ChunkMapper&MockObject $chunkMapper;

	/**
	 * The handler under test.
	 *
	 * @var EntityRecognitionHandler
	 */
	private EntityRecognitionHandler $handler;

	/**
	 * Build the handler with a capturing logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$lines = &$this->lines;
		$logger = new class($lines) extends AbstractLogger {
			/**
			 * The capture buffer.
			 *
			 * @var list<string>
			 */
			private array $lines;

			/**
			 * Constructor.
			 *
			 * @param list<string> $lines The capture buffer.
			 */
			public function __construct(array &$lines) {
				$this->lines = &$lines;
			}

			/**
			 * Capture one line without the source line number.
			 *
			 * @param mixed $level The level.
			 * @param string|Stringable $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				unset($context['line']);
				$context['file'] = basename((string)($context['file'] ?? ''));
				$this->lines[] = $level . ' ' . $message . ' ' . json_encode($context);
			}
		};
		$this->chunkMapper = $this->createMock(ChunkMapper::class);
		$this->handler = new EntityRecognitionHandler(
			stores: new EntityRecognitionStores(
				chunkMapper: $this->chunkMapper,
				entityMapper: $this->createMock(GdprEntityMapper::class),
				entityRelationMapper: $this->createMock(EntityRelationMapper::class),
				db: $this->createMock(IDBConnection::class)
			),
			backends: new EntityRecognitionBackends(
				settingsService: $this->createMock(SettingsService::class),
				anonymisationBackendService: $this->createMock(AnonymisationBackendService::class)
			),
			logger: $logger
		);
	}//end setUp()

	/**
	 * The chunk loop skips metadata chunks, logs a failing chunk and counts the rest.
	 *
	 * @return void
	 */
	public function testSourceLoop(): void {
		$this->chunkMapper->expects($this->once())->method('findBySource')->with('file', 42)->willReturn(
			[
				$this->chunk(id: 1, text: 'metadata', index: -1),
				$this->chunk(id: 2, text: null, index: 0),
				$this->chunk(id: 3, text: '  ', index: 1),
				$this->chunk(id: 4, text: 'Geen gegevens hier.', index: 2),
			]
		);

		$result = $this->handler->processSourceChunks('file', 42, ['method' => 'regex']);

		$this->assertSame(['chunks_processed' => 2, 'entities_found' => 0, 'relations_created' => 0], $result);
		$this->assertSame(
			[
				'info [EntityRecognitionHandler] Processing chunks for entity extraction {"file":"EntityRecognitionHandler.php","source_type":"file","source_id":42}',
				'debug [EntityRecognitionHandler] Extracting entities from chunk {"file":"EntityRecognitionHandler.php","chunk_id":2,"source_type":"file","source_id":42}',
				'error [EntityRecognitionHandler] Failed to process chunk {"file":"EntityRecognitionHandler.php","chunk_id":2,"source_type":"file","source_id":42,"error":"no text"}',
				'debug [EntityRecognitionHandler] Extracting entities from chunk {"file":"EntityRecognitionHandler.php","chunk_id":3,"source_type":"file","source_id":42}',
				'debug [EntityRecognitionHandler] Extracting entities from chunk {"file":"EntityRecognitionHandler.php","chunk_id":4,"source_type":"file","source_id":42}',
				'info [EntityRecognitionHandler] Source processing complete {"file":"EntityRecognitionHandler.php","source_type":"file","source_id":42,"chunks_processed":2,"entities_found":0,"relations_created":0}',
			],
			$this->lines
		);
	}//end testSourceLoop()

	/**
	 * The confidence threshold and entity filter options reach the detection.
	 *
	 * @return void
	 */
	public function testDetectionOptions(): void {
		$text = 'Mail jan@example.nl of bel 0612345678.';
		$empty = ['entities_found' => 0, 'relations_created' => 0, 'entities' => []];

		$this->assertSame($empty, $this->handler->extractFromChunk($this->chunk(id: 5, text: $text, index: 0), ['method' => 'regex', 'entity_types' => ['IBAN']]));
		$this->assertSame($empty, $this->handler->extractFromChunk($this->chunk(id: 6, text: $text, index: 0), ['method' => 'regex', 'confidence_threshold' => 1.5]));
	}//end testDetectionOptions()

	/**
	 * A chunk mock with magic getters.
	 *
	 * @param int $id The chunk id.
	 * @param string|null $text The text, or null to make reading it fail.
	 * @param int $index The chunk index.
	 *
	 * @return Chunk&MockObject
	 */
	private function chunk(int $id, ?string $text, int $index): Chunk&MockObject {
		$mock = $this->getMockBuilder(Chunk::class)
			->addMethods(['getId', 'getSourceType', 'getSourceId', 'getTextContent', 'getChunkIndex'])
			->getMock();
		$mock->method('getId')->willReturn($id);
		$mock->method('getSourceType')->willReturn('file');
		$mock->method('getSourceId')->willReturn(42);
		if ($text === null) {
			$mock->method('getTextContent')->willThrowException(new Exception('no text'));
		} else {
			$mock->method('getTextContent')->willReturn($text);
		}

		$mock->method('getChunkIndex')->willReturn($index);
		return $mock;
	}//end chunk()
}//end class
