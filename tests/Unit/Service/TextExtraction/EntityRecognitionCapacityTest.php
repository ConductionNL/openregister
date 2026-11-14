<?php

/**
 * A detected person is stored with a capacity suggestion and its source (REQ-DKP-001).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Service\TextExtraction\ChunkEntityScan;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionStores;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService;
use OCA\OpenRegister\Service\Anonymisation\OfficialsDirectory;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionBackends;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class EntityRecognitionCapacityTest extends TestCase {

	private const LETTER = 'Het college besluit. Namens deze, P. de Vries, wethouder. Behandeld door: J. Bakker.';

	/**
	 * The relations the handler inserted.
	 *
	 * @var list<EntityRelation>
	 */
	private array $inserted = [];

	private function fileSettings(array $anonymisation): FileSettingsHandler {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'fileManagement'
				? (string) json_encode(['anonymisation' => $anonymisation])
				: $default
		);

		return new FileSettingsHandler($appConfig);
	}//end fileSettings()

	private function handler(?FileSettingsHandler $settings, ?OfficialsDirectory $officials): EntityRecognitionHandler {
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($expr);
		$expr->method('eq')->willReturn('1=1');
		$qb->method('createNamedParameter')->willReturn('?');
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$entityMapper = $this->createMock(GdprEntityMapper::class);
		$entityMapper->method('findEntitiesPublic')->willReturn([]);
		$stored = new GdprEntity();
		$stored->setId(1);
		$entityMapper->method('insert')->willReturn($stored);

		$relationMapper = $this->createMock(EntityRelationMapper::class);
		$relationMapper->method('insert')->willReturnCallback(
			function (EntityRelation $relation): EntityRelation {
				$this->inserted[] = $relation;
				return $relation;
			}
		);

		return new EntityRecognitionHandler(
			stores: new EntityRecognitionStores(
				chunkMapper: $this->createMock(ChunkMapper::class),
				entityMapper: $entityMapper,
				entityRelationMapper: $relationMapper,
				db: $db
			),
			backends: new EntityRecognitionBackends(
				settingsService: $this->createMock(SettingsService::class),
				anonymisationBackendService: $this->createMock(AnonymisationBackendService::class)
			),
			logger: $this->createMock(LoggerInterface::class),
			fileSettings: $settings,
			officials: $officials
		);
	}//end handler()

	/**
	 * Run storeDetectedEntities() over the letter with the given detections.
	 *
	 * @param EntityRecognitionHandler $handler  The handler.
	 * @param list<array>              $detected The detections.
	 *
	 * @return array The storage result.
	 */
	private function store(EntityRecognitionHandler $handler, array $detected): array {
		$chunk = $this->getMockBuilder(Chunk::class)->addMethods(['getId', 'getSourceType', 'getSourceId'])->getMock();
		$chunk->method('getId')->willReturn(3);
		$chunk->method('getSourceType')->willReturn('file');
		$chunk->method('getSourceId')->willReturn(42);

		return (new ReflectionMethod(EntityRecognitionHandler::class, 'storeDetectedEntities'))->invoke(
			$handler,
			$detected,
			new ChunkEntityScan(chunk: $chunk, text: self::LETTER, method: 'hybrid', contextWindow: 20)
		);
	}//end store()

	private static function person(string $name): array {
		$start = (int) strpos(self::LETTER, $name);

		return [
			'type' => EntityRecognitionHandler::ENTITY_TYPE_PERSON,
			'value' => $name,
			'category' => 'personal_data',
			'position_start' => $start,
			'position_end' => $start + strlen($name),
			'confidence' => 0.9,
		];
	}//end person()

	private function directory(array $officials): OfficialsDirectory&MockObject {
		$directory = $this->createMock(OfficialsDirectory::class);
		$directory->method('officials')->willReturn($officials);

		return $directory;
	}//end directory()

	public function testAnAldermanFromTheRegisterIsSuggestedPublicOffice(): void {
		$source = ['type' => 'groups', 'groups' => ['wethouders' => 'public-office']];
		$directory = $this->createMock(OfficialsDirectory::class);
		$directory->expects($this->once())->method('officials')->with($source)
			->willReturn([['name' => 'P. de Vries', 'capacity' => 'public-office']]);

		$result = $this->store(
			handler: $this->handler(settings: $this->fileSettings(anonymisation: ['officialsSource' => $source]), officials: $directory),
			detected: [self::person(name: 'P. de Vries')]
		);

		$this->assertSame(1, $result['relations_created']);
		$this->assertCount(1, $this->inserted);
		$this->assertSame('public-office', $this->inserted[0]->getCapacity());
		$this->assertSame('officials-register', $this->inserted[0]->getCapacitySource());
		// A suggestion is not a decision: the occurrence stays to be anonymised until confirmed.
		$this->assertFalse($this->inserted[0]->getSkipAnonymization());
		$this->assertNull($this->inserted[0]->getCapacityApplied());
		$this->assertNull($this->inserted[0]->getCapacityConfirmedBy());
		$this->assertNull($this->inserted[0]->getCapacityConfirmedAt());
	}//end testAnAldermanFromTheRegisterIsSuggestedPublicOffice()

	public function testRoleWordsSuggestACapacityWithoutARegister(): void {
		$this->store(handler: $this->handler(settings: null, officials: null), detected: [self::person(name: 'J. Bakker')]);

		$this->assertSame('professional', $this->inserted[0]->getCapacity());
		$this->assertSame('role-words', $this->inserted[0]->getCapacitySource());
	}//end testRoleWordsSuggestACapacityWithoutARegister()

	public function testAPersonWithoutEvidenceIsUnknown(): void {
		$chunk = $this->getMockBuilder(Chunk::class)->addMethods(['getId', 'getSourceType', 'getSourceId'])->getMock();
		$chunk->method('getId')->willReturn(3);
		$chunk->method('getSourceType')->willReturn('file');
		$chunk->method('getSourceId')->willReturn(42);
		(new ReflectionMethod(EntityRecognitionHandler::class, 'storeDetectedEntities'))->invoke(
			$this->handler(settings: null, officials: $this->directory(officials: [])),
			[['type' => 'PERSON', 'value' => 'Maria Jansen', 'position_start' => 0, 'position_end' => 12, 'confidence' => 0.8]],
			new ChunkEntityScan(chunk: $chunk, text: 'Maria Jansen schreef ons.', method: 'hybrid', contextWindow: 20)
		);

		$this->assertSame('unknown', $this->inserted[0]->getCapacity());
		$this->assertSame('none', $this->inserted[0]->getCapacitySource());
	}//end testAPersonWithoutEvidenceIsUnknown()

	public function testANonPersonGetsNoCapacityAndTheRegisterIsNotRead(): void {
		$directory = $this->createMock(OfficialsDirectory::class);
		$directory->expects($this->never())->method('officials');

		$this->store(
			handler: $this->handler(settings: null, officials: $directory),
			detected: [['type' => 'EMAIL', 'value' => 'college', 'position_start' => 4, 'position_end' => 11, 'confidence' => 0.9]]
		);

		$this->assertCount(1, $this->inserted);
		$this->assertNull($this->inserted[0]->getCapacity());
		$this->assertNull($this->inserted[0]->getCapacitySource());
	}//end testANonPersonGetsNoCapacityAndTheRegisterIsNotRead()

	public function testTheRegisterIsReadOncePerBatch(): void {
		$directory = $this->createMock(OfficialsDirectory::class);
		$directory->expects($this->once())->method('officials')->with(null)
			->willReturn([['name' => 'J. Bakker', 'capacity' => 'professional']]);

		$this->store(
			handler: $this->handler(settings: $this->fileSettings(anonymisation: []), officials: $directory),
			detected: [self::person(name: 'P. de Vries'), self::person(name: 'J. Bakker')]
		);

		$this->assertCount(2, $this->inserted);
		$this->assertSame(['public-office', 'role-words'], [$this->inserted[0]->getCapacity(), $this->inserted[0]->getCapacitySource()]);
		$this->assertSame(['professional', 'officials-register'], [$this->inserted[1]->getCapacity(), $this->inserted[1]->getCapacitySource()]);
	}//end testTheRegisterIsReadOncePerBatch()
}//end class
