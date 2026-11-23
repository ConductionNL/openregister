<?php

/**
 * A scheme change rewrites issued identifiers, keeps the old ones findable,
 * and runs one migration per sequence at a time.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\BackgroundJob\IdentifierSchemeMigrationJob;
use OCA\OpenRegister\Db\ForeignIdentifier;
use OCA\OpenRegister\Db\ForeignIdentifierMapper;
use OCA\OpenRegister\Db\IdentifierSchemeRun;
use OCA\OpenRegister\Db\IdentifierSchemeRunMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RunningNumbersInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ForeignIdentifierRegistry;
use OCA\OpenRegister\Service\IdentifierSchemeMigrationRunningException;
use OCA\OpenRegister\Service\IdentifierSchemeMigrationService;
use OCA\OpenRegister\Service\IdentifierSchemePass;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclarationReader;
use OCA\OpenRegister\Service\Schemas\IdentityRewriteScope;
use OCP\AppFramework\Db\Entity;
use OCP\BackgroundJob\IJobList;
use OCP\DB\Exception as DbException;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The service over in-memory runs and foreign identifiers, with the object
 * store, the counter and the job list mocked at their boundaries.
 */
#[CoversClass(IdentifierSchemeMigrationService::class)]
#[UsesClass(IdentifierSchemeMigrationRunningException::class)]
#[UsesClass(IdentifierSchemePass::class)]
#[UsesClass(IdentifierSchemeRun::class)]
#[UsesClass(IdentifierSchemeRunMapper::class)]
#[UsesClass(ForeignIdentifier::class)]
#[UsesClass(ForeignIdentifierMapper::class)]
#[UsesClass(ForeignIdentifierRegistry::class)]
#[UsesClass(GeneratedIdentifierDeclaration::class)]
#[UsesClass(GeneratedIdentifierDeclarationReader::class)]
#[UsesClass(IdentityRewriteScope::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(Schema::class)]
#[UsesClass(ObjectFindTarget::class)]
#[UsesClass(ObjectSaveOptions::class)]
class IdentifierSchemeMigrationServiceTest extends TestCase {

	/**
	 * The format identifiers were issued in.
	 */
	private const PREVIOUS = '{year}-{seq:4}';

	/**
	 * The runs, kept in memory.
	 *
	 * @var IdentifierSchemeRunMapper
	 */
	private IdentifierSchemeRunMapper $runs;

	/**
	 * The foreign identifiers, through the real registry over memory.
	 *
	 * @var ForeignIdentifierRegistry
	 */
	private ForeignIdentifierRegistry $foreignIds;

	/**
	 * The object store.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface&MockObject $objects;

	/**
	 * The counter.
	 *
	 * @var RunningNumbersInterface&MockObject
	 */
	private RunningNumbersInterface&MockObject $sequences;

	/**
	 * The job list.
	 *
	 * @var IJobList&MockObject
	 */
	private IJobList&MockObject $jobList;

	/**
	 * The service under test.
	 *
	 * @var IdentifierSchemeMigrationService
	 */
	private IdentifierSchemeMigrationService $service;

	/**
	 * Build the service over a schema numbering cases `Z-{year}-{seq:5}`, reset yearly.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->runs       = $this->memoryRuns();
		$this->foreignIds = new ForeignIdentifierRegistry($this->memoryForeignIds());
		$this->objects    = $this->createMock(ObjectOperationsInterface::class);
		$this->sequences  = $this->createMock(RunningNumbersInterface::class);
		$this->jobList    = $this->createMock(IJobList::class);
		$this->service    = $this->serviceWith(schema: self::schema(annotation: ['sequence' => 'case', 'format' => 'Z-{year}-{seq:5}', 'resetOn' => 'year']));
	}//end setUp()

	/**
	 * Starting stores a running run with the population and queues the job.
	 *
	 * @return void
	 */
	public function testStartStoresARunningRunAndQueuesTheJob(): void {
		$this->objects->expects($this->once())->method('count')
			->with(['filters' => ['register' => 3, 'schema' => 9]])->willReturn(42);
		$this->jobList->expects($this->once())->method('add')
			->with(IdentifierSchemeMigrationJob::class, ['run_id' => 1]);

		$run = $this->service->start(registerId: 3, schemaId: 9, property: 'identifier', previousFormat: ' '.self::PREVIOUS.' ', startedBy: 'admin');

		$this->assertSame(1, $run->getId());
		$this->assertSame('case', $run->getSequence());
		$this->assertSame('case', $run->getActiveSequence());
		$this->assertSame(IdentifierSchemeRun::STATE_RUNNING, $run->getState());
		$this->assertSame(self::PREVIOUS, $run->getPreviousFormat());
		$this->assertSame(42, $run->getTotal());
		$this->assertSame('admin', $run->getStartedBy());
		$this->assertSame($run, $this->service->find(id: 1));
	}//end testStartStoresARunningRunAndQueuesTheJob()

	/**
	 * A second migration of the sequence is refused, naming the running one.
	 *
	 * @return void
	 */
	public function testASecondMigrationOfTheSameSequenceIsRefusedNamingTheRunningOne(): void {
		$this->service->start(registerId: 3, schemaId: 9, property: 'identifier', previousFormat: self::PREVIOUS, startedBy: 'anna');

		try {
			$this->service->start(registerId: 3, schemaId: 9, property: 'identifier', previousFormat: 'C-{year}-{seq}', startedBy: 'bert');
			$this->fail('The second migration was not refused.');
		} catch (IdentifierSchemeMigrationRunningException $e) {
			$this->assertSame(1, $e->getRunning()?->getId());
			$this->assertStringContainsString('Sequence "case" is already being migrated', $e->getMessage());
			$this->assertStringContainsString('run 1 on schema 9, property "identifier", started by anna', $e->getMessage());
		}

		$this->assertNull($this->service->find(id: 2));
	}//end testASecondMigrationOfTheSameSequenceIsRefusedNamingTheRunningOne()

	/**
	 * A concurrent start that wins the unique index is named as the running one.
	 *
	 * @return void
	 */
	public function testAConcurrentStartLosingTheUniqueIndexNamesTheWinner(): void {
		$winner = new IdentifierSchemeRun();
		$winner->setId(7);
		$winner->setSchemaId(9);
		$winner->setProperty('identifier');
		$this->runs = $this->memoryRuns(insertFailure: self::uniqueViolation(), sneakIn: $winner);
		$service    = $this->serviceWith(schema: self::schema(annotation: ['sequence' => 'case', 'format' => 'Z-{seq:5}']));
		$this->jobList->expects($this->never())->method('add');

		try {
			$service->start(registerId: 3, schemaId: 9, property: 'identifier', previousFormat: self::PREVIOUS);
			$this->fail('The losing start was not refused.');
		} catch (IdentifierSchemeMigrationRunningException $e) {
			$this->assertSame(7, $e->getRunning()?->getId());
			$this->assertStringContainsString('started by the system', $e->getMessage());
			$this->assertInstanceOf(DbException::class, $e->getPrevious());
		}
	}//end testAConcurrentStartLosingTheUniqueIndexNamesTheWinner()

	/**
	 * Requests that cannot be rewritten are refused before anything is stored.
	 *
	 * @return void
	 */
	public function testUnusableRequestsAreRefused(): void {
		$this->jobList->expects($this->never())->method('add');
		$cases = [
			'no such property' => [$this->service, 'missing', self::PREVIOUS, 'Schema 9 has no property "missing".'],
			'not generated'    => [$this->service, 'title', self::PREVIOUS, '"title" is not a generated identifier.'],
			'same format'      => [$this->service, 'identifier', 'Z-{year}-{seq:5}', 'it must differ from the current one'],
			'empty format'     => [$this->service, 'identifier', '  ', 'Give the previous format'],
			'random sequence'  => [
				$this->serviceWith(schema: self::schema(annotation: ['sequence' => 'case', 'format' => 'Z-{seq}', 'kind' => 'random', 'length' => 6])),
				'identifier',
				'C-{seq}',
				'a random code has no number to carry over',
			],
		];

		foreach ($cases as $label => [$service, $property, $previous, $expected]) {
			try {
				$service->start(registerId: 3, schemaId: 9, property: $property, previousFormat: $previous);
				$this->fail($label.' was not refused.');
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString($expected, $e->getMessage(), $label);
			}
		}

		$this->assertNull($this->service->find(id: 1));
	}//end testUnusableRequestsAreRefused()

	/**
	 * A batch rewrites each old value, keeps it findable, and skips what it cannot read.
	 *
	 * @return void
	 */
	public function testABatchRewritesTheValueAndKeepsTheOldOneFindable(): void {
		$run = $this->service->start(registerId: 3, schemaId: 9, property: 'identifier', previousFormat: self::PREVIOUS);
		$this->objects->expects($this->once())->method('findAll')
			->with(
				[
					'limit' => 50,
					'offset' => 0,
					'sort' => ['id' => 'ASC'],
					'filters' => ['register' => 3, 'schema' => 9],
				],
				false,
				false
			)
			->willReturn(
				[
					self::object(uuid: 'u-1', identifier: '2025-0007'),
					self::object(uuid: 'u-2', identifier: ''),
					self::object(uuid: 'u-3', identifier: 'Z-2025-00003'),
					'not an object',
				]
			);
		$saved = [];
		$this->objects->expects($this->once())->method('saveObject')
			->willReturnCallback(
				function (array $object, ObjectSaveOptions $options, bool $_rbac, bool $_multitenancy) use (&$saved): ObjectEntity {
					$saved[] = [$object, $options->uuid, $options->target->register, IdentityRewriteScope::isActive(), $_rbac, $_multitenancy];
					return new ObjectEntity();
				}
			);
		$this->sequences->expects($this->once())->method('advanceTo')
			->with('gen:case|2025', 7);

		$this->assertTrue($this->service->processBatch(run: $run, batchSize: 50));

		$this->assertSame([[['id' => 'u-1', 'identifier' => 'Z-2025-00007', 'title' => 'u-1'], 'u-1', 3, true, false, false]], $saved);
		$this->assertFalse(IdentityRewriteScope::isActive());
		$this->assertSame('u-1', $this->foreignIds->resolve(issuer: 'openregister:oc-test', value: '2025-0007'));
		$this->assertSame(3, $run->getProcessed());
		$this->assertSame(1, $run->getRewritten());
		$this->assertSame(2, $run->getSkipped());
		$this->assertSame(0, $run->getFailed());
		$this->assertSame(IdentifierSchemeRun::STATE_RUNNING, $run->getState());
	}//end testABatchRewritesTheValueAndKeepsTheOldOneFindable()

	/**
	 * A failed save is counted and named; the batch goes on.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsCountedAndNamed(): void {
		$run = $this->service->start(registerId: 3, schemaId: 9, property: 'identifier', previousFormat: self::PREVIOUS);
		$this->objects->method('findAll')->willReturn([self::object(uuid: 'u-1', identifier: '2025-0001'), self::object(uuid: 'u-2', identifier: '2025-0002')]);
		$this->objects->expects($this->exactly(2))->method('saveObject')
			->willReturnOnConsecutiveCalls($this->throwException(new RuntimeException('locked')), new ObjectEntity());

		$this->assertTrue($this->service->processBatch(run: $run));

		$this->assertSame(1, $run->getFailed());
		$this->assertSame(1, $run->getRewritten());
		$this->assertSame('u-1: locked', $run->getLastError());
	}//end testAFailedSaveIsCountedAndNamed()

	/**
	 * An empty batch completes the run and frees its sequence for the next.
	 *
	 * @return void
	 */
	public function testAnEmptyBatchCompletesTheRunAndFreesTheSequence(): void {
		$run = $this->service->start(registerId: 3, schemaId: 9, property: 'identifier', previousFormat: self::PREVIOUS);
		$this->objects->method('findAll')->willReturn([]);

		$this->assertFalse($this->service->processBatch(run: $run));
		$this->assertFalse($this->service->processBatch(run: $run), 'A stopped run is not advanced.');

		$this->assertSame(IdentifierSchemeRun::STATE_COMPLETED, $run->getState());
		$this->assertNull($run->getActiveSequence());
		$this->assertInstanceOf(DateTime::class, $run->getFinished());
		$next = $this->service->start(registerId: 3, schemaId: 9, property: 'identifier', previousFormat: 'C-{year}-{seq}');
		$this->assertSame(2, $next->getId());
	}//end testAnEmptyBatchCompletesTheRunAndFreesTheSequence()

	/**
	 * Failing a run records why and frees its sequence.
	 *
	 * @return void
	 */
	public function testFailStopsTheRunAndFreesTheSequence(): void {
		$run = $this->service->start(registerId: 3, schemaId: 9, property: 'identifier', previousFormat: self::PREVIOUS);

		$this->service->fail(run: $run, error: 'schema gone');

		$this->assertSame(IdentifierSchemeRun::STATE_FAILED, $run->getState());
		$this->assertSame('schema gone', $run->getLastError());
		$this->assertNull($run->getActiveSequence());
		$this->assertSame(IdentifierSchemeRun::STATE_FAILED, $run->jsonSerialize()['state']);
	}//end testFailStopsTheRunAndFreesTheSequence()

	/**
	 * Without an instance id the previous values are issued by `openregister:local`.
	 *
	 * @return void
	 */
	public function testThePreviousIssuerFallsBackWithoutAnInstanceId(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('instanceid', '')->willReturn(' ');
		$service = new IdentifierSchemeMigrationService(
			$this->runs,
			$this->createMock(SchemaMapperInterface::class),
			$this->objects,
			$this->foreignIds,
			$this->sequences,
			$this->jobList,
			$config
		);

		$this->assertSame('openregister:local', $service->previousIssuer());
		$this->assertSame('openregister:oc-test', $this->service->previousIssuer());
	}//end testThePreviousIssuerFallsBackWithoutAnInstanceId()

	/**
	 * The service over a schema.
	 *
	 * @param Schema $schema The schema found for every id.
	 *
	 * @return IdentifierSchemeMigrationService
	 */
	private function serviceWith(Schema $schema): IdentifierSchemeMigrationService {
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnMap([['instanceid', '', 'oc-test']]);

		return new IdentifierSchemeMigrationService(
			$this->runs,
			$schemas,
			$this->objects,
			$this->foreignIds,
			$this->sequences,
			$this->jobList,
			$config
		);
	}//end serviceWith()

	/**
	 * A schema with a generated `identifier` and a plain `title`.
	 *
	 * @param array<string, mixed> $annotation The `x-openregister-generated` annotation.
	 *
	 * @return Schema
	 */
	private static function schema(array $annotation): Schema {
		$schema = new Schema();
		$schema->setId(9);
		$schema->setProperties(
			[
				'identifier' => ['type' => 'string', 'x-openregister-generated' => $annotation],
				'title' => ['type' => 'string'],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * An object created in 2025 carrying an identifier.
	 *
	 * @param string $uuid       The uuid, also its title.
	 * @param string $identifier The identifier.
	 *
	 * @return ObjectEntity
	 */
	private static function object(string $uuid, string $identifier): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setCreated(new DateTime('2025-03-04 10:00:00'));
		$object->setObject(['identifier' => $identifier, 'title' => $uuid]);

		return $object;
	}//end object()

	/**
	 * A unique constraint violation as the database reports it.
	 *
	 * @return DbException
	 */
	private static function uniqueViolation(): DbException {
		return new class('duplicate') extends DbException {
			/**
			 * The reason of this failure.
			 *
			 * @return int
			 */
			public function getReason(): int {
				return DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION;
			}//end getReason()
		};
	}//end uniqueViolation()

	/**
	 * Runs kept in memory, unique on the running sequence.
	 *
	 * @param DbException|null         $insertFailure Thrown by the first insert, after storing `$sneakIn`.
	 * @param IdentifierSchemeRun|null $sneakIn       A run another writer stores first.
	 *
	 * @return IdentifierSchemeRunMapper
	 */
	private function memoryRuns(?DbException $insertFailure = null, ?IdentifierSchemeRun $sneakIn = null): IdentifierSchemeRunMapper {
		return new class($this->createMock(IDBConnection::class), $insertFailure, $sneakIn) extends IdentifierSchemeRunMapper {
			/**
			 * The stored runs by id.
			 *
			 * @var array<int, IdentifierSchemeRun>
			 */
			private array $rows = [];

			/**
			 * Constructor.
			 *
			 * @param IDBConnection            $db            Unused connection.
			 * @param DbException|null         $insertFailure The first insert's failure.
			 * @param IdentifierSchemeRun|null $sneakIn       The other writer's run.
			 */
			public function __construct(
				IDBConnection $db,
				private ?DbException $insertFailure,
				private ?IdentifierSchemeRun $sneakIn,
			) {
				parent::__construct($db);
			}//end __construct()

			/**
			 * Store a run, refusing a second running one of a sequence.
			 *
			 * @param Entity $entity The run.
			 *
			 * @return Entity
			 */
			public function insert(Entity $entity): Entity {
				if ($this->insertFailure !== null) {
					$failure = $this->insertFailure;
					$this->insertFailure = null;
					$this->sneakIn?->setActiveSequence($entity->getActiveSequence());
					$this->rows[(int)$this->sneakIn?->getId()] = $this->sneakIn;
					throw $failure;
				}

				if ($this->findRunning((string)$entity->getActiveSequence()) !== null) {
					throw new class('duplicate') extends DbException {
						/**
						 * The reason of this failure.
						 *
						 * @return int
						 */
						public function getReason(): int {
							return DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION;
						}//end getReason()
					};
				}

				$entity->setId(count($this->rows) + 1);
				$this->rows[$entity->getId()] = $entity;
				return $entity;
			}//end insert()

			/**
			 * Keep a changed run.
			 *
			 * @param Entity $entity The run.
			 *
			 * @return Entity
			 */
			public function update(Entity $entity): Entity {
				$this->rows[(int)$entity->getId()] = $entity;
				return $entity;
			}//end update()

			/**
			 * One run by id.
			 *
			 * @param int $id The id.
			 *
			 * @return IdentifierSchemeRun|null
			 */
			public function findById(int $id): ?IdentifierSchemeRun {
				return ($this->rows[$id] ?? null);
			}//end findById()

			/**
			 * The running run of a sequence.
			 *
			 * @param string $sequence The sequence.
			 *
			 * @return IdentifierSchemeRun|null
			 */
			public function findRunning(string $sequence): ?IdentifierSchemeRun {
				foreach ($this->rows as $row) {
					if ($row->getActiveSequence() === $sequence) {
						return $row;
					}
				}

				return null;
			}//end findRunning()
		};
	}//end memoryRuns()

	/**
	 * Foreign identifiers kept in memory.
	 *
	 * @return ForeignIdentifierMapper
	 */
	private function memoryForeignIds(): ForeignIdentifierMapper {
		return new class($this->createMock(IDBConnection::class)) extends ForeignIdentifierMapper {
			/**
			 * The stored identifiers.
			 *
			 * @var ForeignIdentifier[]
			 */
			private array $rows = [];

			/**
			 * Store one.
			 *
			 * @param Entity $entity The identifier.
			 *
			 * @return Entity
			 */
			public function insert(Entity $entity): Entity {
				$this->rows[] = $entity;
				return $entity;
			}//end insert()

			/**
			 * One by issuer and value.
			 *
			 * @param string $issuer The issuer.
			 * @param string $value  The value.
			 *
			 * @return ForeignIdentifier|null
			 */
			public function findByIssuerAndValue(string $issuer, string $value): ?ForeignIdentifier {
				foreach ($this->rows as $row) {
					if ($row->getIssuer() === $issuer && $row->getValue() === $value) {
						return $row;
					}
				}

				return null;
			}//end findByIssuerAndValue()
		};
	}//end memoryForeignIds()
}//end class
