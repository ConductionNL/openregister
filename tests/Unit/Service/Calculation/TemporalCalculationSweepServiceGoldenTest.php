<?php

/**
 * Golden (characterisation) tests for the sweep's edge and failure paths.
 *
 * Pins the summary counters, the writes and the skips of
 * {@see \OCA\OpenRegister\Service\Calculation\TemporalCalculationSweepService}
 * with a stubbed evaluator, so no assertion depends on the clock.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Calculation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Calculation\AggregateReferenceResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\Calculation\CalculationPayloadBuilder;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use OCA\OpenRegister\Service\Calculation\TemporalCalculationSweepService;
use OCA\OpenRegister\Service\Calculation\TemporalSweep\CalculationRecomputer;
use OCA\OpenRegister\Service\Calculation\TemporalSweep\LifecycleTerminals;
use OCA\OpenRegister\Service\Calculation\TemporalSweep\TemporalCalculations;
use OCA\OpenRegister\Service\Calculation\TemporalSweep\TemporalObjectRefresher;
use OCA\OpenRegister\Service\Calculation\TemporalSweep\TemporalSchemaScan;
use OCA\OpenRegister\Service\Calculation\TemporalSweep\TemporalSchemaSweeper;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * TemporalCalculationSweepServiceGoldenTest.
 */
class TemporalCalculationSweepServiceGoldenTest extends TestCase {

	/**
	 * The schemaMapper under test or mocked.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * The registerMapper under test or mocked.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * The objectMapper under test or mocked.
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper&MockObject $objectMapper;

	/**
	 * The objectService under test or mocked.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * The evaluator under test or mocked.
	 *
	 * @var CalculationEvaluatorInterface&MockObject
	 */
	private CalculationEvaluatorInterface&MockObject $evaluator;

	/**
	 * The logger under test or mocked.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The service under test or mocked.
	 *
	 * @var TemporalCalculationSweepService
	 */
	private TemporalCalculationSweepService $service;

	/**
	 * Wire the sweep with mocked mappers, a stubbed evaluator and the real payload builder.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->evaluator = $this->createMock(CalculationEvaluatorInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->evaluator->method('expressionUsesSequence')->willReturnCallback(
			static fn (mixed $expression): bool => $expression === ['sequence' => ['scope' => 'global']]
		);

		$payloadBuilder = new CalculationPayloadBuilder(
			references: $this->createMock(ReferenceResolver::class),
			aggregates: $this->createMock(AggregateReferenceResolver::class),
		);

		$logger = $this->logger;
		$this->service = new TemporalCalculationSweepService(
			scan: new TemporalSchemaScan(schemaMapper: $this->schemaMapper, registerMapper: $this->registerMapper, logger: $logger),
			calculations: new TemporalCalculations(),
			sweeper: new TemporalSchemaSweeper(
				terminals: new LifecycleTerminals(),
				refresher: new TemporalObjectRefresher(
					recomputer: new CalculationRecomputer(evaluator: $this->evaluator, payloadBuilder: $payloadBuilder, logger: $logger),
					objectService: $this->objectService,
					logger: $logger
				),
				logger: $logger
			),
			objectMapper: $this->objectMapper,
		);

	}//end setUp()

	/**
	 * A failing schema enumeration logs an error and ends the sweep with one error.
	 *
	 * @return void
	 */
	public function testSchemaEnumerationFailureEndsTheSweep(): void {
		$this->schemaMapper->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->registerMapper->expects($this->never())->method('findAll');
		$this->logger->expects($this->once())->method('error');

		$this->assertSame($this->summary(errors: 1), $this->service->runSweep());

	}//end testSchemaEnumerationFailureEndsTheSweep()

	/**
	 * A failing register enumeration leaves every temporal schema without a register.
	 *
	 * @return void
	 */
	public function testRegisterEnumerationFailureSkipsTemporalSchemas(): void {
		$this->schemaMapper->method('findAll')->willReturn([$this->schema(lifecycle: null)]);
		$this->registerMapper->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->objectMapper->expects($this->never())->method('findAllInRegisterSchemaTable');
		$this->logger->expects($this->once())->method('warning');

		$this->assertSame($this->summary(scanned: 1), $this->service->runSweep());

	}//end testRegisterEnumerationFailureSkipsTemporalSchemas()

	/**
	 * Orphaned schemas, schemas without calculations and now-free schemas are skipped.
	 *
	 * @return void
	 */
	public function testOrphanedAndNowFreeSchemasAreSkipped(): void {
		$orphan = $this->schema(lifecycle: null, schemaId: 99);
		$empty = new Schema();
		$empty->setId(12);
		$empty->setConfiguration(['x-openregister-calculations' => 'not-a-map']);
		$unmaterialised = $this->schema(lifecycle: null, materialise: false);
		$this->schemaMapper->method('findAll')->willReturn([$orphan, $empty, $unmaterialised]);
		$this->registerMapper->method('findAll')->willReturn([$this->register(schemas: null), $this->register(schemas: ['5'])]);
		$this->objectMapper->expects($this->never())->method('findAllInRegisterSchemaTable');

		$this->assertSame($this->summary(scanned: 3), $this->service->runSweep());

	}//end testOrphanedAndNowFreeSchemasAreSkipped()

	/**
	 * A failing object enumeration counts one error for that schema.
	 *
	 * @return void
	 */
	public function testObjectEnumerationFailureCountsAnError(): void {
		$this->schemaMapper->method('findAll')->willReturn([$this->schema(lifecycle: null)]);
		$this->registerMapper->method('findAll')->willReturn([$this->register(schemas: ['12'])]);
		$this->objectMapper->method('findAllInRegisterSchemaTable')->willThrowException(new RuntimeException('no table'));
		$this->logger->expects($this->once())->method('warning');

		$this->assertSame($this->summary(scanned: 1, temporal: 1, errors: 1), $this->service->runSweep());

	}//end testObjectEnumerationFailureCountsAnError()

	/**
	 * Terminal states (declared under `property`) are skipped, unchanged values
	 * are not written, failing evaluations and sequences are skipped, a failing
	 * write counts an error, and dates are compared in ATOM form.
	 *
	 * @return void
	 */
	public function testObjectLoopOutcomes(): void {
		$schema = $this->schema(lifecycle: ['property' => 'status', 'final' => ['closed', 3]]);
		$this->schemaMapper->method('findAll')->willReturn([$schema]);
		$this->registerMapper->method('findAll')->willReturn([$this->register(schemas: [12])]);
		$this->objectMapper->method('findAllInRegisterSchemaTable')->willReturn(
			[
				$this->object(uuid: 'closed', data: ['status' => 'closed', 'tier' => 'old']),
				$this->object(uuid: 'three', data: ['status' => '3', 'tier' => 'old']),
				$this->object(uuid: 'same', data: ['status' => 'open', 'tier' => 'same']),
				$this->object(uuid: 'changed', data: ['status' => 'open', 'tier' => 'old']),
				$this->object(uuid: 'throws', data: ['status' => 'open', 'tier' => 'boom']),
				$this->object(uuid: 'date', data: ['status' => 'open', 'tier' => '2026-01-02T03:04:05+00:00']),
				$this->object(uuid: 'datechanged', data: ['status' => 'open', 'tier' => 'old-date']),
				$this->object(uuid: 'savefails', data: ['status' => 'open', 'tier' => 'save']),
			]
		);
		$this->evaluator->method('evaluate')->willReturnCallback(
			static function (array $payload): mixed {
				return match ($payload['tier']) {
					'same' => 'same',
					'boom' => throw new EvaluationException('bad'),
					'2026-01-02T03:04:05+00:00', 'old-date' => new \DateTimeImmutable('2026-01-02T03:04:05+00:00'),
					default => 'new',
				};
			}
		);
		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			static function (array $object, $extend, $register, $schema, $uuid, $rbac, $multitenancy) use (&$saved): ObjectEntity {
				if ($uuid === 'savefails') {
					throw new RuntimeException('refused');
				}

				$saved[] = [$uuid, $object, $register->getId(), $schema->getId(), $rbac, $multitenancy];
				return new ObjectEntity();
			}
		);

		$summary = $this->service->runSweep();

		$this->assertSame($this->summary(scanned: 1, temporal: 1, evaluated: 6, rewritten: 2, errors: 1), $summary);
		$this->assertSame(
			[
				['changed', ['id' => 'changed', 'status' => 'open', 'tier' => 'old'], 7, 12, false, false],
				['datechanged', ['id' => 'datechanged', 'status' => 'open', 'tier' => 'old-date'], 7, 12, false, false],
			],
			$saved
		);

	}//end testObjectLoopOutcomes()

	/**
	 * A reference-form `final`, or one that is not a list, names no terminal
	 * state, and a lifecycle without a field sweeps every object.
	 *
	 * @return void
	 */
	public function testLifecycleWithoutUsableTerminalsSweepsEverything(): void {
		$lifecycles = [
			['field' => 'status', 'final' => ['from' => 'states', 'field' => 'terminal']],
			['field' => 'status', 'final' => 'closed'],
			['field' => '', 'final' => ['closed']],
			['final' => ['closed']],
			'not-an-array',
		];
		foreach ($lifecycles as $lifecycle) {
			$this->setUp();
			$this->schemaMapper->method('findAll')->willReturn([$this->schema(lifecycle: $lifecycle)]);
			$this->registerMapper->method('findAll')->willReturn([$this->register(schemas: [12])]);
			$this->objectMapper->method('findAllInRegisterSchemaTable')->willReturn(
				[$this->object(uuid: 'closed', data: ['status' => 'closed', 'tier' => 'same'])]
			);
			$this->evaluator->method('evaluate')->willReturn('same');

			$this->assertSame($this->summary(scanned: 1, temporal: 1, evaluated: 1), $this->service->runSweep());
		}

	}//end testLifecycleWithoutUsableTerminalsSweepsEverything()

	/**
	 * A later calculation sees the fresh value of an earlier one.
	 *
	 * @return void
	 */
	public function testALaterCalculationSeesTheFreshValue(): void {
		$schema = $this->schema(lifecycle: null);
		$schema->setConfiguration(
			[
				'x-openregister-calculations' => [
					'first' => ['materialise' => true, 'expression' => ['now' => []]],
					'second' => ['materialise' => true, 'expression' => ['prop' => 'first']],
				],
			]
		);
		$this->schemaMapper->method('findAll')->willReturn([$schema]);
		$this->registerMapper->method('findAll')->willReturn([$this->register(schemas: [12])]);
		$this->objectMapper->method('findAllInRegisterSchemaTable')->willReturn(
			[$this->object(uuid: 'a', data: ['first' => 'x', 'second' => 'x'])]
		);
		$seen = [];
		$this->evaluator->method('evaluate')->willReturnCallback(
			static function (array $payload, mixed $expression) use (&$seen): mixed {
				$seen[] = [$payload['first'], $expression];
				return 'y';
			}
		);

		$this->assertSame($this->summary(scanned: 1, temporal: 1, evaluated: 1, rewritten: 1), $this->service->runSweep());
		$this->assertSame([['x', ['now' => []]], ['y', ['prop' => 'first']]], $seen);

	}//end testALaterCalculationSeesTheFreshValue()

	/**
	 * The expected summary shape.
	 *
	 * @param int $scanned Schemas scanned.
	 * @param int $temporal Temporal schemas.
	 * @param int $evaluated Objects evaluated.
	 * @param int $rewritten Objects rewritten.
	 * @param int $errors Errors.
	 *
	 * @return array<string, int> The summary.
	 */
	private function summary(int $scanned=0, int $temporal=0, int $evaluated=0, int $rewritten=0, int $errors=0): array {
		return [
			'schemasScanned' => $scanned,
			'temporalSchemas' => $temporal,
			'objectsEvaluated' => $evaluated,
			'objectsRewritten' => $rewritten,
			'errors' => $errors,
		];

	}//end summary()

	/**
	 * A schema with one temporal calculation, one sequence and one non-materialised one.
	 *
	 * @param mixed $lifecycle The lifecycle block, or null for none.
	 * @param int $schemaId The schema id.
	 * @param bool $materialise Whether the temporal calculation materialises.
	 *
	 * @return Schema The schema.
	 */
	private function schema(mixed $lifecycle, int $schemaId=12, bool $materialise=true): Schema {
		$configuration = [
			'x-openregister-calculations' => [
				'tier' => ['materialise' => $materialise, 'expression' => ['now' => []]],
				'number' => ['materialise' => true, 'expression' => ['sequence' => ['scope' => 'global']]],
				'derived' => ['materialise' => false, 'expression' => ['now' => []]],
				'broken' => 'not-a-spec',
			],
		];
		if ($lifecycle !== null) {
			$configuration['x-openregister-lifecycle'] = $lifecycle;
		}

		$schema = new Schema();
		$schema->setId($schemaId);
		$schema->setSlug('golden');
		$schema->setProperties(['status' => ['type' => 'string'], 'tier' => ['type' => 'string']]);
		$schema->setConfiguration($configuration);

		return $schema;

	}//end schema()

	/**
	 * A register with the given schema ids.
	 *
	 * @param array<int, int|string>|null $schemas The member schema ids.
	 *
	 * @return Register The register.
	 */
	private function register(?array $schemas): Register {
		$register = new Register();
		$register->setId(7);
		$register->setSchemas($schemas);

		return $register;

	}//end register()

	/**
	 * An object with the given data.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data The stored data.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $uuid, array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('7');
		$object->setSchema('12');
		$object->setObject($data);

		return $object;

	}//end object()
}//end class
