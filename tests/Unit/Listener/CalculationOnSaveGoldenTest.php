<?php

/**
 * OpenRegister CalculationOnSaveGoldenTest
 *
 * Pins what CalculationOnSaveListener does with each kind of declared
 * calculation: which are skipped, which are evaluated, what the rule run log
 * records, what is logged, and what ends up in the stored payload.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\CalculationOnSaveListener;
use OCA\OpenRegister\Listener\Support\CalculationOnSaveEngine;
use OCA\OpenRegister\Listener\Support\CalculationOnSaveLookups;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\Calculation\CalculationPayloadBuilder;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use OCA\OpenRegister\Service\Calculation\SequenceContext;
use OCA\OpenRegister\Service\Rules\RuleRunRecorderInterface;
use OCA\OpenRegister\Service\Rules\RuleTrace;
use OCA\OpenRegister\Service\SequenceServiceInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden outcomes of the save-time calculation pass.
 */
class CalculationOnSaveGoldenTest extends TestCase {

	/** @var list<string> Rule run log entries as "ruleId|status|message". */
	private array $runs = [];

	/** @var list<string> Warnings logged. */
	private array $warnings = [];

	/** @var list<SequenceContext|null> The sequence context each evaluation received. */
	private array $contexts = [];

	/** @var list<string> Register lookups as "ref|rbac|multitenancy". */
	private array $registerLookups = [];

	/** @var bool Whether the register lookup fails. */
	private bool $registerFails = false;

	/**
	 * Build the listener around recording collaborators.
	 *
	 * @param Schema|null $schema The schema the mapper answers, or null to throw.
	 *
	 * @return CalculationOnSaveListener
	 */
	private function listener(?Schema $schema): CalculationOnSaveListener {
		$schemaMapper = $this->createMock(SchemaMapperInterface::class);
		if ($schema === null) {
			$schemaMapper->method('find')->willThrowException(new RuntimeException('gone'));
		} else {
			$schemaMapper->method('find')->willReturn($schema);
		}

		$registerMapper = $this->createMock(RegisterMapperInterface::class);
		$registerMapper->method('find')->willReturnCallback(
			function ($ref, $rbac = true, $tenancy = true): Register {
				$this->registerLookups[] = $ref.'|'.var_export($rbac, true).'|'.var_export($tenancy, true);
				if ($this->registerFails === true) {
					throw new RuntimeException('no register');
				}

				$register = new Register();
				$register->setId(7);
				return $register;
			}
		);

		$evaluator = $this->createMock(CalculationEvaluatorInterface::class);
		$evaluator->method('expressionUsesSequence')->willReturnCallback(
			static fn ($expr): bool => is_array($expr) === true && isset($expr['sequence']) === true
		);
		$evaluator->method('evaluate')->willReturnCallback(
			function (array $data, $expr, ?SequenceContext $ctx) {
				$this->contexts[] = $ctx;
				if ($expr === 'boom') {
					throw new EvaluationException('bad expression');
				}

				if ($expr === 'date') {
					return new DateTimeImmutable('2020-01-02T03:04:05+00:00');
				}

				if (is_array($expr) === true && isset($expr['sequence']) === true) {
					return 'SEQ';
				}

				return 'v:'.$expr;
			}
		);

		$builder = $this->getMockBuilder(CalculationPayloadBuilder::class)
			->disableOriginalConstructor()
			->onlyMethods(['build', 'stripSyntheticKeys', 'unresolvedReferencesUsedBy'])
			->getMock();
		$builder->method('build')->willReturnCallback(
			static fn (ObjectEntity $o): array => array_merge(($o->getObject() ?? []), ['@self' => ['x' => 1]])
		);
		$builder->method('stripSyntheticKeys')->willReturnCallback(
			static function (array $data): array {
				unset($data['@self']);
				return $data;
			}
		);
		$builder->method('unresolvedReferencesUsedBy')->willReturnCallback(
			static fn (array $p, $expr): array => ($expr === 'ref' ? ['caseType', 'owner'] : [])
		);

		$ruleRuns = $this->createMock(RuleRunRecorderInterface::class);
		$ruleRuns->method('record')->willReturnCallback(
			function (string $ruleId, string $schemaSlug, RuleTrace $trace, ?string $objectUuid = null, ?string $registerSlug = null): void {
				$this->runs[] = $ruleId.'|'.$schemaSlug.'|'.$trace->getVerdict().'|'.($trace->getMessage() ?? '').'|'.$objectUuid.'|'.$registerSlug;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		return new CalculationOnSaveListener(
			new CalculationOnSaveLookups($schemaMapper, $registerMapper, $this->createMock(SequenceServiceInterface::class), $logger),
			new CalculationOnSaveEngine($evaluator, $builder),
			$ruleRuns
		);
	}//end listener()

	/**
	 * A schema declaring every kind of calculation.
	 *
	 * @param string $slug The schema slug.
	 * @param int    $id   The schema id.
	 *
	 * @return Schema
	 */
	private function schema(string $slug='case', int $id=5): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setProperties([]);
		$schema->setConfiguration(
			[
				'x-openregister-calculations' => [
					'notArray' => 'x',
					'off' => ['materialise' => true, 'enabled' => false, 'expression' => 'off'],
					'lazy' => ['materialise' => false, 'expression' => 'lazy'],
					'truthy' => ['materialise' => 1, 'expression' => 'truthy'],
					'number' => ['materialise' => true, 'expression' => ['sequence' => []]],
					'ref' => ['materialise' => true, 'expression' => 'ref'],
					'boom' => ['materialise' => true, 'expression' => 'boom'],
					'when' => ['materialise' => true, 'expression' => 'date'],
					'same' => ['materialise' => true, 'expression' => 'same'],
					'fresh' => ['materialise' => true, 'expression' => 'fresh'],
				],
			]
		);
		return $schema;
	}//end schema()

	/**
	 * An object to save.
	 *
	 * @param array<string, mixed> $data     The payload.
	 * @param string|null          $register The register reference.
	 *
	 * @return ObjectEntity
	 */
	private function object(array $data, ?string $register='22'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$object->setSchema('5');
		$object->setRegister($register);
		$object->setObject($data);
		return $object;
	}//end object()

	/**
	 * On create every materialised calculation runs with a sequence context.
	 *
	 * @return void
	 */
	public function testCreateOutcomes(): void {
		$object = $this->object(['same' => 'v:same', 'keep' => 1]);
		$this->listener($this->schema())->handle(new ObjectCreatingEvent($object));

		self::assertSame(
			[
				'same' => 'v:same',
				'keep' => 1,
				'number' => 'SEQ',
				'when' => '2020-01-02T03:04:05+00:00',
				'fresh' => 'v:fresh',
			],
			$this->data($object)
		);
		self::assertSame(['22|false|false'], $this->registerLookups);
		self::assertCount(5, $this->contexts);
		foreach ($this->contexts as $ctx) {
			self::assertInstanceOf(SequenceContext::class, $ctx);
		}

		self::assertSame(
			[
				'calculation:case:number|case|fired||u-1|22',
				'calculation:case:ref|case|error|Reference caseType, owner could not be resolved; the stored value was kept.|u-1|22',
				'calculation:case:boom|case|error|bad expression|u-1|22',
				'calculation:case:when|case|fired||u-1|22',
				'calculation:case:same|case|fired||u-1|22',
				'calculation:case:fresh|case|fired||u-1|22',
			],
			$this->runs
		);
		self::assertSame(['Calculation "boom" failed on u-1: bad expression'], $this->warnings);
	}//end testCreateOutcomes()

	/**
	 * The object's payload without the id the entity adds on read.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed>
	 */
	private function data(ObjectEntity $object): array {
		$data = $object->getObject();
		unset($data['id']);
		return $data;
	}//end data()

	/**
	 * On update a sequence calculation is skipped and no register is resolved.
	 *
	 * @return void
	 */
	public function testUpdateSkipsSequenceAndNeedsNoRegister(): void {
		$object = $this->object(['number' => '2026-0013']);
		$old = $this->object([]);
		$this->listener($this->schema())->handle(new ObjectUpdatingEvent($object, $old));

		self::assertSame([], $this->registerLookups);
		self::assertSame('2026-0013', $this->data($object)['number']);
		self::assertSame([null, null, null, null], $this->contexts);
		self::assertSame(5, count($this->runs));
	}//end testUpdateSkipsSequenceAndNeedsNoRegister()

	/**
	 * Nothing changed: the payload object is not replaced and synthetic keys never leak.
	 *
	 * @return void
	 */
	public function testUnchangedPayloadIsNotRewritten(): void {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setSlug('');
		$schema->setProperties([]);
		$schema->setConfiguration(['x-openregister-calculations' => ['same' => ['materialise' => true, 'expression' => 'same']]]);
		$object = $this->object(['same' => 'v:same']);
		$this->listener($schema)->handle(new ObjectUpdatingEvent($object, $this->object([])));

		self::assertSame(['same' => 'v:same'], $this->data($object));
		self::assertSame([], $this->runs, 'an empty slug records nothing');
	}//end testUnchangedPayloadIsNotRewritten()

	/**
	 * Unresolvable sequence scope: the context is null and a warning names the register.
	 *
	 * @return void
	 */
	public function testRegisterFailureGivesNoContext(): void {
		$this->registerFails = true;
		$object = $this->object([]);
		$this->listener($this->schema())->handle(new ObjectCreatingEvent($object));

		self::assertSame(['Sequence context: could not resolve register "22": no register', 'Calculation "boom" failed on u-1: bad expression'], $this->warnings);
		self::assertArrayNotHasKey('number', $this->data($object));
	}//end testRegisterFailureGivesNoContext()

	/**
	 * Unresolvable scope ids (no register, schema id 0) give a null context without a lookup.
	 *
	 * @return void
	 */
	public function testMissingScopeIdsGiveNoContext(): void {
		$object = $this->object([], '');
		$this->listener($this->schema())->handle(new ObjectCreatingEvent($object));
		self::assertSame([], $this->registerLookups);
		self::assertArrayNotHasKey('number', $this->data($object));

		$object = $this->object([]);
		$this->listener($this->schema('case', 0))->handle(new ObjectCreatingEvent($object));
		self::assertSame([], $this->registerLookups);
	}//end testMissingScopeIdsGiveNoContext()

	/**
	 * No schema, no schema ref, no calculations, or another event: nothing happens.
	 *
	 * @return void
	 */
	public function testNothingToDo(): void {
		$object = $this->object(['a' => 1]);
		$this->listener(null)->handle(new ObjectCreatingEvent($object));
		self::assertSame(['a' => 1], $this->data($object));

		$object->setSchema('');
		$this->listener($this->schema())->handle(new ObjectCreatingEvent($object));
		self::assertSame(['a' => 1], $this->data($object));

		$empty = new Schema();
		$empty->setConfiguration(['x-openregister-calculations' => 'nope']);
		$empty->setProperties([]);
		$object->setSchema('5');
		$this->listener($empty)->handle(new ObjectCreatingEvent($object));
		$this->listener($this->schema())->handle(new Event());
		self::assertSame(['a' => 1], $this->data($object));
		self::assertSame([], $this->contexts);
	}//end testNothingToDo()
}//end class
