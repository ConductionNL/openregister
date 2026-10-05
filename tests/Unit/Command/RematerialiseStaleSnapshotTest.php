<?php

/**
 * Rematerialise must not save a stale snapshot over a row an earlier save in
 * the same run already materialised (live pass O11).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rematerialise-rereads-before-save/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\RematerialiseCalculationsCommand;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Calculation\AggregateReferenceResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Two learniq enrolments of one course. Saving the first re-saves its sibling
 * (the aggregate on lesson-completion of the same course), as the live run did.
 */
class RematerialiseStaleSnapshotTest extends TestCase {

	private const A = 'ee010009-0000-4000-8000-000000000177';
	private const B = 'ee010009-0000-4000-8000-000000000196';

	/** @var array<string, array<string, mixed>> The rows as stored, by uuid. */
	private array $store = [];

	/** @var array<int, string> The uuids handed to saveObject(), in order. */
	private array $saves = [];

	/**
	 * The enrolment schema: completedLessonCount is a materialised, readOnly aggregate.
	 *
	 * @return Schema
	 */
	private function enrolment(): Schema {
		$schema = new Schema();
		$schema->setId(8);
		$schema->setSlug('enrolment');
		$schema->setProperties(
			[
				'course'               => ['type' => 'string'],
				'completedLessonCount' => ['type' => 'integer', 'readOnly' => true],
			]
		);
		$schema->setConfiguration(
			[
				'x-openregister-calculations'  => ['completedLessonCount' => ['expression' => 'completed', 'materialise' => true]],
				'x-openregister-aggregate-refs' => ['completed' => ['schema' => 'lesson-completion', 'metric' => 'count']],
			]
		);
		return $schema;
	}//end enrolment()

	/**
	 * Build an entity from the store.
	 *
	 * @param string $uuid The row.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister('5');
		$entity->setSchema('8');
		$entity->setObject($this->store[$uuid]);
		return $entity;
	}//end entity()

	/**
	 * O11: the second row was materialised by the first row's save; the run exits 0.
	 *
	 * Live: "Touched 5, unchanged 181, failed 19", rc 1, each failure "Cannot
	 * modify readOnly properties: completedLessonCount", while afterwards every
	 * row held its value.
	 *
	 * @return void
	 */
	public function testARowASiblingSaveAlreadyMaterialisedIsNotAFailure(): void {
		$schema = $this->enrolment();
		$this->store = [
			self::A => ['course' => 'c1', 'completedLessonCount' => null],
			self::B => ['course' => 'c1', 'completedLessonCount' => null],
		];

		$register = new Register();
		$register->setId(5);
		$register->setSlug('learniq');
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		// The snapshot the command reads first: both rows still null.
		$snapshot = [$this->entity(self::A), $this->entity(self::B)];
		$magic = $this->createMock(MagicMapper::class);
		$magic->method('findAllInRegisterSchemaTable')->willReturn($snapshot);
		$magic->method('find')->willReturnCallback(fn (string|int $identifier) => $this->entity((string) $identifier));

		$evaluator = $this->createMock(CalculationEvaluator::class);
		$evaluator->method('expressionUsesSequence')->willReturn(false);
		$evaluator->method('evaluate')->willReturnCallback(
			static fn (array $payload, $expression) => ($payload['@aggregate']['completed'] ?? null)
		);

		$runner = $this->createMock(AggregationRunner::class);
		$runner->method('runAdhocByRef')->willReturn(['value' => 4]);
		$aggregates = new AggregateReferenceResolver(aggregationRunner: $runner, logger: $this->createMock(LoggerInterface::class));

		// The REAL readOnly rule ObjectService::enforceReadOnlyOnUpdate() applies,
		// checked against the row as it is stored at the moment of the save.
		$rules = new ValidateObject(
			$this->createMock(IAppConfig::class),
			$this->createMock(MagicMapper::class),
			$schemas,
			$this->createMock(IURLGenerator::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IUserManager::class)
		);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $reg = null, $sch = null, ?string $uuid = null) use ($rules, $schema): ObjectEntity {
				$this->saves[] = (string) $uuid;
				$violations = $rules->validateReadOnlyConstraints(incomingObject: $object, existingObject: $this->store[$uuid], schema: $schema);
				if ($violations !== []) {
					throw new RuntimeException('Cannot modify readOnly properties: ' . implode(', ', array_column($violations, 'property')));
				}

				// The save path materialises this row AND its sibling of the same course.
				foreach (array_keys($this->store) as $row) {
					if ($this->store[$row]['course'] === $object['course']) {
						$this->store[$row]['completedLessonCount'] = 4;
					}
				}

				return $this->entity((string) $uuid);
			}
		);

		$command = new RematerialiseCalculationsCommand(
			$registers,
			$schemas,
			$magic,
			$objects,
			$evaluator,
			$this->createMock(ReferenceResolver::class),
			$aggregates
		);

		$output = new BufferedOutput();
		$code = $command->run(new ArrayInput(['register' => 'learniq', 'schema' => 'enrolment']), $output);
		$out = $output->fetch();

		$this->assertStringNotContainsString('Cannot modify readOnly', $out);
		$this->assertStringContainsString('failed 0', $out);
		$this->assertSame(0, $code, $out);
		$this->assertSame([self::A], $this->saves, 'The sibling already holds its value, so it is not saved again.');
		$this->assertSame(4, $this->store[self::B]['completedLessonCount']);
	}//end testARowASiblingSaveAlreadyMaterialisedIsNotAFailure()
}//end class
