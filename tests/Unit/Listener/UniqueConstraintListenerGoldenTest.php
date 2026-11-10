<?php

/**
 * OpenRegister UniqueConstraintListenerGoldenTest
 *
 * Pins which writes UniqueConstraintListener refuses, which it reports on the
 * validation envelope, and which it leaves alone.
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

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\UniqueConstraintListener;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluatorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden outcomes of the unique-constraint pass.
 */
class UniqueConstraintListenerGoldenTest extends TestCase {

	/** @var list<array<string, mixed>> Search queries sent to the object mapper. */
	private array $queries = [];

	/** @var list<string> Warnings logged. */
	private array $warnings = [];

	/**
	 * Build the listener.
	 *
	 * @param list<array<string, mixed>> $constraints The declared constraints.
	 * @param array<string, mixed>       $holders     Search answer per constraint name: list of uuids.
	 * @param bool                       $schemaFails Whether the schema lookup throws.
	 *
	 * @return UniqueConstraintListener
	 */
	private function listener(array $constraints, array $holders, bool $schemaFails=false): UniqueConstraintListener {
		$evaluator = $this->createMock(UniqueConstraintEvaluatorInterface::class);
		$evaluator->method('constraints')->willReturn($constraints);
		$evaluator->method('filtersFor')->willReturnCallback(
			static fn (array $c, array $o): ?array => ($c['name'] === 'skip' ? null : ['k' => $c['name']])
		);
		$evaluator->method('describeBreach')->willReturnCallback(
			static fn (array $c, array $o, ?string $id): string => 'breach '.$c['name'].' by '.$id
		);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		if ($schemaFails === true) {
			$schemas->method('find')->willThrowException(new RuntimeException('lookup broke'));
		} else {
			$schema = new Schema();
			$schema->setId(9);
			$schemas->method('find')->willReturn($schema);
		}

		$objects = $this->createMock(MagicMapperInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query = [], ?string $org = null, bool $rbac = true, bool $tenancy = true) use ($holders) {
				$this->queries[] = $query + ['rbac' => $rbac, 'tenancy' => $tenancy];
				$answer = ($holders[$query['k']] ?? []);
				if (is_array($answer) === false) {
					return $answer;
				}

				return array_map(
					static function ($uuid) {
						if ($uuid === 'not-an-object') {
							return 'x';
						}

						$o = new ObjectEntity();
						$o->setUuid($uuid);
						return $o;
					},
					$answer
				);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		return new UniqueConstraintListener($evaluator, $schemas, $objects, $logger);
	}//end listener()

	/**
	 * An object being written.
	 *
	 * @param string $register The register reference.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $register='3'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('own');
		$object->setRegister($register);
		$object->setSchema('9');
		$object->setObject(['a' => 1]);
		return $object;
	}//end object()

	/**
	 * A constraint.
	 *
	 * @param string $name   Its name.
	 * @param string $action Its action.
	 *
	 * @return array<string, mixed>
	 */
	private function constraint(string $name, string $action): array {
		return ['name' => $name, 'properties' => ['p-'.$name], 'action' => $action];
	}//end constraint()

	/**
	 * A refusing constraint stops the write and nothing is recorded.
	 *
	 * @return void
	 */
	public function testRefuseStopsTheWrite(): void {
		$object = $this->object();
		$event = new ObjectCreatingEvent($object);
		$this->listener(
			[$this->constraint('skip', 'refuse'), $this->constraint('rep', 'report'), $this->constraint('ref', 'refuse'), $this->constraint('late', 'refuse')],
			['rep' => ['other-1'], 'ref' => ['own', 'other-2'], 'late' => ['other-3']]
		)->handle($event);

		self::assertSame(
			[
				'code' => 'unique-constraint-breached',
				'message' => 'breach ref by other-2',
				'constraint' => 'ref',
				'properties' => ['p-ref'],
				'conflictingObject' => 'other-2',
			],
			$event->getErrors()
		);
		self::assertTrue($event->isPropagationStopped());
		self::assertSame([], $object->getValidation());
		self::assertSame(
			[
				['k' => 'rep', '@self' => ['register' => 3, 'schema' => 9], '_limit' => 5, 'rbac' => false, 'tenancy' => false],
				['k' => 'ref', '@self' => ['register' => 3, 'schema' => 9], '_limit' => 5, 'rbac' => false, 'tenancy' => false],
			],
			$this->queries
		);
	}//end testRefuseStopsTheWrite()

	/**
	 * A reporting constraint records the breach and lets the write through.
	 *
	 * @return void
	 */
	public function testReportIsRecorded(): void {
		$object = $this->object();
		$object->setValidation(['other' => true]);
		$event = new ObjectUpdatingEvent($object, $this->object());
		$this->listener(
			[$this->constraint('rep', 'report'), $this->constraint('clean', 'refuse'), $this->constraint('odd', 'refuse')],
			['rep' => ['not-an-object', '', 'other-1'], 'clean' => ['own'], 'odd' => 7]
		)->handle($event);

		self::assertSame([], $event->getErrors());
		self::assertFalse($event->isPropagationStopped());
		$validation = $object->getValidation();
		self::assertTrue($validation['other']);
		self::assertCount(1, $validation['uniqueness']);
		$report = $validation['uniqueness'][0];
		self::assertSame('rep', $report['constraint']);
		self::assertSame(['p-rep'], $report['properties']);
		self::assertSame('other-1', $report['conflictingObject']);
		self::assertSame('breach rep by other-1', $report['message']);
		self::assertIsString($report['reportedAt']);
	}//end testReportIsRecorded()

	/**
	 * A resolved breach is cleared; with no prior key nothing is written.
	 *
	 * @return void
	 */
	public function testNoBreachClearsOrLeavesTheEnvelope(): void {
		$object = $this->object();
		$object->setValidation(['uniqueness' => [['old']]]);
		$this->listener([$this->constraint('rep', 'report')], [])->handle(new ObjectCreatingEvent($object));
		self::assertSame(['uniqueness' => []], $object->getValidation());

		$object = $this->object();
		$this->listener([$this->constraint('rep', 'report')], [])->handle(new ObjectCreatingEvent($object));
		self::assertSame([], $object->getValidation());
	}//end testNoBreachClearsOrLeavesTheEnvelope()

	/**
	 * A non-numeric register is not checked at all.
	 *
	 * @return void
	 */
	public function testNonNumericRegisterIsNotChecked(): void {
		$object = $this->object('slug');
		$event = new ObjectCreatingEvent($object);
		$this->listener([$this->constraint('ref', 'refuse')], ['ref' => ['x']])->handle($event);
		self::assertSame([], $this->queries);
		self::assertSame([], $event->getErrors());
	}//end testNonNumericRegisterIsNotChecked()

	/**
	 * A soft delete, no schema reference or no constraints: nothing is evaluated.
	 *
	 * @return void
	 */
	public function testNothingToEvaluate(): void {
		$deleted = $this->object();
		$deleted->setDeleted(['deleted' => 'now']);
		$this->listener([$this->constraint('ref', 'refuse')], ['ref' => ['x']])->handle(new ObjectUpdatingEvent($deleted, $this->object()));

		$noSchema = $this->object();
		$noSchema->setSchema('');
		$this->listener([$this->constraint('ref', 'refuse')], ['ref' => ['x']])->handle(new ObjectCreatingEvent($noSchema));

		$this->listener([], ['ref' => ['x']])->handle(new ObjectCreatingEvent($this->object()));
		self::assertSame([], $this->queries);
	}//end testNothingToEvaluate()

	/**
	 * An evaluation failure allows the save and is logged.
	 *
	 * @return void
	 */
	public function testFailureAllowsTheSave(): void {
		$event = new ObjectCreatingEvent($this->object());
		$this->listener([$this->constraint('ref', 'refuse')], [], true)->handle($event);
		self::assertSame([], $event->getErrors());
		self::assertSame(['[UniqueConstraintListener] The evaluation itself failed, allowing the save: lookup broke'], $this->warnings);
	}//end testFailureAllowsTheSave()
}//end class
