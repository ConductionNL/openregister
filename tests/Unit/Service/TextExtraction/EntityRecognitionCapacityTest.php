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
use OCA\OpenRegister\Service\Anonymisation\PersonRegisterMatcher;
use OCA\OpenRegister\Service\Anonymisation\RegisterNameReader;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionBackends;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
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

	private function handler(?FileSettingsHandler $settings, ?OfficialsDirectory $officials, ?PersonRegisterMatcher $registers=null): EntityRecognitionHandler {
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
			officials: $officials,
			personRegisters: $registers
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
	private function store(EntityRecognitionHandler $handler, array $detected, string $method='hybrid'): array {
		$chunk = $this->getMockBuilder(Chunk::class)->addMethods(['getId', 'getSourceType', 'getSourceId'])->getMock();
		$chunk->method('getId')->willReturn(3);
		$chunk->method('getSourceType')->willReturn('file');
		$chunk->method('getSourceId')->willReturn(42);

		return (new ReflectionMethod(EntityRecognitionHandler::class, 'storeDetectedEntities'))->invoke(
			$handler,
			$detected,
			new ChunkEntityScan(chunk: $chunk, text: self::LETTER, method: $method, contextWindow: 20)
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
	/**
	 * The detection methods every PERSON candidate comes from.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function methods(): array {
		return [
			'regex' => ['regex'],
			'presidio' => ['presidio'],
			'openanonymiser' => ['openanonymiser'],
			'llm' => ['llm'],
			'hybrid' => ['hybrid'],
		];
	}//end methods()

	/**
	 * Whatever method detected them, persons are matched against the registers before they are stored (REQ-DKP-003).
	 *
	 * @param string $method The detection method.
	 *
	 * @return void
	 */
	#[DataProvider('methods')]
	public function testTheMatcherRunsForEveryMethod(string $method): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->exactly(2))->method('findAll')->willReturnCallback(
			static fn (array $config): array => match ($config['filters']['schema']) {
				'personen' => [['id' => 'rec-1', 'naam' => 'J. Bakker']],
				'straten' => [['id' => 'str-1', 'naam' => 'P. de Vriesstraat']],
				default => [],
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$matcher = new PersonRegisterMatcher(
			reader: new RegisterNameReader(container: $container, contacts: $this->createMock(\OCP\Contacts\IManager::class)),
			logger: $this->createMock(LoggerInterface::class)
		);
		$settings = $this->fileSettings(
			anonymisation: [
				'personRegister' => ['type' => 'schema', 'register' => 'brp', 'schema' => 'personen', 'nameProperty' => 'naam', 'purpose' => 'anonimiseren'],
				'personRegisterBoost' => 0.05,
				'placeRegister' => ['type' => 'schema', 'register' => 'bag', 'schema' => 'straten', 'nameProperty' => 'naam'],
			]
		);

		$result = $this->store(
			handler: $this->handler(settings: $settings, officials: null, registers: $matcher),
			detected: [self::person(name: 'J. Bakker'), self::person(name: 'P. de Vries')],
			method: $method
		);

		$this->assertCount(2, $this->inserted);
		$this->assertSame($method, $this->inserted[0]->getDetectionMethod());
		$this->assertSame(0.95, $this->inserted[0]->getConfidence());
		$this->assertSame('schema:brp/personen', $this->inserted[0]->getPersonMatch());
		$this->assertNull($this->inserted[0]->getUncertain());
		$this->assertSame(0.9, $this->inserted[1]->getConfidence());
		$this->assertNull($this->inserted[1]->getPersonMatch());
		$this->assertTrue($this->inserted[1]->getUncertain());
		$this->assertSame(['personRegister' => 'schema:brp/personen', 'purpose' => 'anonimiseren', 'lookups' => 2], $result['person_register']);
		$this->assertStringNotContainsString('rec-1', (string) json_encode($this->inserted[0]));
	}//end testTheMatcherRunsForEveryMethod()

	/**
	 * Without a persons register the run records not-configured and the confidence is unchanged.
	 *
	 * @return void
	 */
	public function testWithoutARegisterTheRunRecordsNotConfigured(): void {
		$result = $this->store(handler: $this->handler(settings: null, officials: null), detected: [self::person(name: 'J. Bakker')]);

		$this->assertSame(['personRegister' => 'not-configured', 'purpose' => null, 'lookups' => 0], $result['person_register']);
		$this->assertSame(0.9, $this->inserted[0]->getConfidence());
		$this->assertNull($this->inserted[0]->getPersonMatch());
	}//end testWithoutARegisterTheRunRecordsNotConfigured()
}//end class
