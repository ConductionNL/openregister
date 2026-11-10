<?php

/**
 * RelatedRetentionRecalculationJob refuses a run without a uuid, contains failures and only
 * stores a dependant whose reference points at the source and whose date actually moved.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\RelatedRetentionRecalculationJob;
use OCA\OpenRegister\BackgroundJob\Support\RelatedRetentionLookup;
use OCA\OpenRegister\BackgroundJob\Support\RelatedRetentionRules;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Archival\ArchiveReferenceReaderInterface;
use OCA\OpenRegister\Service\Archival\DependantRetentionIndexInterface;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Behaviour of run() and recalculateDependantsOf().
 */
class RelatedRetentionRecalculationJobBehaviourTest extends TestCase {

	private const SOURCE = 'src-uuid';

	/**
	 * @var MagicMapperInterface&MockObject
	 */
	private MagicMapperInterface $objectMapper;

	/**
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface $schemaMapper;

	/**
	 * @var RegisterMapperInterface&MockObject
	 */
	private RegisterMapperInterface $registerMapper;

	/**
	 * @var DependantRetentionIndexInterface&MockObject
	 */
	private DependantRetentionIndexInterface $index;

	/**
	 * @var ArchiveReferenceReaderInterface&MockObject
	 */
	private ArchiveReferenceReaderInterface $calculator;

	/**
	 * @var RetentionServiceInterface&MockObject
	 */
	private RetentionServiceInterface $retention;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	private RelatedRetentionRecalculationJob $job;

	/**
	 * Full rows the mapper returns by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $rows = [];

	/**
	 * Uuids written through update(), with the register and schema ids.
	 *
	 * @var list<array{0: string, 1: int, 2: int}>
	 */
	private array $updated = [];

	protected function setUp(): void {
		$this->objectMapper   = $this->createMock(MagicMapperInterface::class);
		$this->schemaMapper   = $this->createMock(SchemaMapperInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapperInterface::class);
		$this->index          = $this->createMock(DependantRetentionIndexInterface::class);
		$this->calculator     = $this->createMock(ArchiveReferenceReaderInterface::class);
		$this->retention      = $this->createMock(RetentionServiceInterface::class);
		$this->logger         = $this->createMock(LoggerInterface::class);

		$this->job = new RelatedRetentionRecalculationJob(
			$this->createMock(ITimeFactory::class),
			new RelatedRetentionLookup($this->objectMapper, $this->schemaMapper, $this->registerMapper),
			new RelatedRetentionRules($this->index, $this->calculator, $this->retention),
			$this->logger
		);

		$this->objectMapper->method('update')->willReturnCallback(
			function (ObjectEntity $entity, ?Register $register, ?Schema $schema): ObjectEntity {
				$this->updated[] = [(string)$entity->getUuid(), (int)$register?->getId(), (int)$schema?->getId()];
				return $entity;
			}
		);
		// The calculator reads the relation value as the reference itself.
		$this->calculator->method('referenceOf')->willReturnCallback(
			static fn (mixed $value): ?string => is_string($value) === true ? $value : null
		);
	}

	private function runJob(mixed $argument): void {
		(new ReflectionMethod($this->job, 'run'))->invoke($this->job, $argument);
	}

	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		return $schema;
	}

	private function register(int $id): Register {
		$register = new Register();
		$register->setId($id);
		return $register;
	}

	/**
	 * A dependant row as the relation lookup returns it, plus its full stored row.
	 *
	 * @param string $uuid  Dependant uuid.
	 * @param mixed  $value The relation field value.
	 *
	 * @return ObjectEntity The slim row.
	 */
	private function dependant(string $uuid, mixed $value): ObjectEntity {
		$slim = new ObjectEntity();
		$slim->setUuid($uuid);
		$slim->setObject(['owner' => $value]);

		$full = new ObjectEntity();
		$full->setUuid($uuid);
		$full->setObject(['owner' => $value, 'name' => 'full '.$uuid]);
		$this->rows[$uuid] = $full;

		return $slim;
	}

	/**
	 * Wire one source with one dependant schema in the given registers.
	 *
	 * @param array<int, list<ObjectEntity>> $rowsByRegister Relation rows per register id.
	 *
	 * @return void
	 */
	private function wireSource(array $rowsByRegister): void {
		$source = new ObjectEntity();
		$source->setUuid(self::SOURCE);
		$source->setSchema('4');
		$this->rows[self::SOURCE] = $source;

		$this->objectMapper->method('find')->willReturnCallback(
			fn (string|int $identifier): ObjectEntity => $this->rows[$identifier] ?? throw new RuntimeException('no row '.$identifier)
		);
		$this->schemaMapper->method('find')->with('4')->willReturn($this->schema(4));
		$this->index->method('dependantsOf')->willReturn([['schema' => $this->schema(9), 'relation' => 'owner']]);
		$this->registerMapper->method('getAllRegisterIdsWithSchema')->with(9)->willReturn(array_keys($rowsByRegister));
		$this->registerMapper->method('find')->willReturnCallback(fn (int|string $id): Register => $this->register((int)$id));
		$this->objectMapper->method('findByRelationBatchInSchema')->willReturnCallback(
			function (array $uuids, int $schemaId, int $registerId, string $field, array $extra) use ($rowsByRegister): array {
				TestCase::assertSame([[self::SOURCE], 9, 'owner', ['owner']], [$uuids, $schemaId, $field, $extra]);
				return $rowsByRegister[$registerId];
			}
		);
	}

	public function testArgumentThatIsNotAnArrayIsRefusedWithAWarning(): void {
		$this->logger->expects($this->once())->method('warning')
			->with('[RelatedRetentionRecalculationJob] queued without a uuid; nothing to do');
		$this->objectMapper->expects($this->never())->method('find');

		$this->runJob('src-uuid');
	}

	public function testBlankUuidIsRefusedWithAWarning(): void {
		$this->logger->expects($this->once())->method('warning')
			->with('[RelatedRetentionRecalculationJob] queued without a uuid; nothing to do');
		$this->objectMapper->expects($this->never())->method('invalidateTableMemos');

		$this->runJob(['uuid' => '   ']);
	}

	public function testMissingUuidKeyIsRefusedWithAWarning(): void {
		$this->logger->expects($this->once())->method('warning');
		$this->objectMapper->expects($this->never())->method('find');

		$this->runJob(['other' => 'x']);
	}

	public function testUuidIsTrimmedAndAFailureIsLoggedAsAnErrorNotThrown(): void {
		$this->objectMapper->expects($this->once())->method('find')
			->with(self::SOURCE, null, null, false, false, false)
			->willThrowException(new RuntimeException('gone'));
		$this->logger->expects($this->once())->method('error')
			->with('[RelatedRetentionRecalculationJob] dependants of src-uuid failed: gone');
		$this->logger->expects($this->never())->method('warning');

		$this->runJob(['uuid' => '  '.self::SOURCE.' ']);
	}

	public function testSourceWithoutDependantsChangesNothing(): void {
		$source = new ObjectEntity();
		$source->setUuid(self::SOURCE);
		$source->setSchema('4');
		$this->objectMapper->method('find')->willReturn($source);
		$this->schemaMapper->method('find')->with('4')->willReturn($this->schema(4));
		$this->index->method('dependantsOf')->willReturn([]);
		$this->registerMapper->expects($this->never())->method('getAllRegisterIdsWithSchema');

		$this->assertSame(0, $this->job->recalculateDependantsOf(self::SOURCE));
	}

	public function testOnlyDependantsReferencingTheSourceAndWhoseDateMovesAreStored(): void {
		$this->wireSource(
			[
				3 => [
					$this->dependant('follows', 'objects/'.self::SOURCE),
					$this->dependant('unchanged', self::SOURCE),
					$this->dependant('elsewhere', 'other-uuid'),
					$this->dependant('noref', ['not', 'a', 'string']),
				],
			]
		);
		$this->retention->method('followSourceDate')->willReturnCallback(
			function (ObjectEntity $object, Schema $schema): bool {
				TestCase::assertSame(9, $schema->getId());
				// The full row is passed, not the slim relation row.
				TestCase::assertSame('full '.$object->getUuid(), $object->getObject()['name']);
				// Every row would follow; only the reference guard and this answer keep rows out.
				return $object->getUuid() !== 'unchanged';
			}
		);

		$this->assertSame(1, $this->job->recalculateDependantsOf(self::SOURCE));
		$this->assertSame([['follows', 3, 9]], $this->updated);
	}

	public function testDependantWithoutObjectDataIsSkipped(): void {
		$slim = new ObjectEntity();
		$slim->setUuid('empty');
		$slim->setObject(null);
		$this->wireSource([3 => [$slim]]);
		$this->retention->expects($this->never())->method('followSourceDate');

		$this->assertSame(0, $this->job->recalculateDependantsOf(self::SOURCE));
	}

	public function testDependantsInEveryRegisterOfTheSchemaAreRecalculated(): void {
		$this->wireSource(
			[
				3 => [$this->dependant('a', self::SOURCE)],
				5 => [$this->dependant('b', self::SOURCE)],
			]
		);
		$this->retention->method('followSourceDate')->willReturn(true);

		$this->assertSame(2, $this->job->recalculateDependantsOf(self::SOURCE));
		$this->assertSame([['a', 3, 9], ['b', 5, 9]], $this->updated);
	}

	public function testRowsBeyondOneBatchAreAllRecalculated(): void {
		$rows = [];
		for ($i = 0; $i <= RelatedRetentionRecalculationJob::BATCH; $i++) {
			$rows[] = $this->dependant('d'.$i, self::SOURCE);
		}

		$this->wireSource([3 => $rows]);
		$this->retention->method('followSourceDate')->willReturn(true);

		$this->assertSame(RelatedRetentionRecalculationJob::BATCH + 1, $this->job->recalculateDependantsOf(self::SOURCE));
		$this->assertSame('d'.RelatedRetentionRecalculationJob::BATCH, end($this->updated)[0]);
	}

	public function testAFailingDependantIsLoggedAndTheOthersStillFollow(): void {
		$this->wireSource([3 => [$this->dependant('broken', self::SOURCE), $this->dependant('fine', self::SOURCE)]]);
		$this->retention->method('followSourceDate')->willReturnCallback(
			static function (ObjectEntity $object): bool {
				if ($object->getUuid() === 'broken') {
					throw new RuntimeException('bad date');
				}

				return true;
			}
		);
		$this->logger->expects($this->once())->method('warning')
			->with('[RelatedRetentionRecalculationJob] could not recalculate broken from src-uuid: bad date');

		$this->assertSame(1, $this->job->recalculateDependantsOf(self::SOURCE));
		$this->assertSame([['fine', 3, 9]], $this->updated);
	}

	public function testRunRecalculatesWithoutLoggingAnErrorOnSuccess(): void {
		$this->wireSource([3 => [$this->dependant('a', self::SOURCE)]]);
		$this->retention->method('followSourceDate')->willReturn(true);
		$this->logger->expects($this->never())->method('error');
		$this->logger->expects($this->never())->method('warning');

		$this->runJob(['uuid' => self::SOURCE]);

		$this->assertSame([['a', 3, 9]], $this->updated);
	}
}
