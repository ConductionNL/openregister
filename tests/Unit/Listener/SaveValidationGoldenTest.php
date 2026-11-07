<?php

/**
 * OpenRegister SaveValidationGoldenTest
 *
 * Pins what the coded-value and working-calendar save guards refuse, with
 * which body, and what they let through when they fail themselves; and the
 * order and fail-soft behaviour of the built-in bulk action registration.
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

use InvalidArgumentException;
use OCA\OpenRegister\BulkAction\ApplyRuleActionInterface;
use OCA\OpenRegister\BulkAction\AssignActionInterface;
use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\ExportWholeSetAction;
use OCA\OpenRegister\BulkAction\RestorePriorValuesActionInterface;
use OCA\OpenRegister\BulkAction\RunActionActionInterface;
use OCA\OpenRegister\BulkAction\SetPropertiesActionInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\BulkActionRegistrationEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Exception\CodedValueException;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Listener\BulkActionRegistrationListener;
use OCA\OpenRegister\Listener\CodedValueValidationListener;
use OCA\OpenRegister\Listener\WorkingCalendarValidationListener;
use OCA\OpenRegister\Service\BulkActionRegistryInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarWriteGuard;
use OCA\OpenRegister\Service\Vocabulary\CodedValueGuard;
use OCA\OpenRegister\Service\Vocabulary\ConceptShapeGuard;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Listener\Support\BulkEditActions;
use OCA\OpenRegister\Listener\Support\BulkSetActions;

/**
 * Golden outcomes of the save validation listeners.
 */
class SaveValidationGoldenTest extends TestCase {

	/** @var list<string> Warnings logged, with their context when it is not a file reference. */
	private array $warnings = [];

	/**
	 * A logger recording warnings.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				unset($context['file'], $context['line']);
				$this->warnings[] = $message.' '.json_encode($context);
			}
		);
		return $logger;
	}//end logger()

	/**
	 * An object being written.
	 *
	 * @param mixed  $data   Its data.
	 * @param string $schema Its schema reference.
	 *
	 * @return ObjectEntity
	 */
	private function object(mixed $data, string $schema='4'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$object->setSchema($schema);
		if (is_array($data) === true) {
			$object->setObject($data);
		}

		return $object;
	}//end object()

	/**
	 * The coded-value listener.
	 *
	 * @param array<string, string>  $shapeErrors The shape guard's answer.
	 * @param \Throwable|null        $enforce     What the coded value guard throws.
	 * @param list<string>           $calls       Collects the guard calls.
	 * @param bool                   $schemaFails Whether the schema lookup fails.
	 *
	 * @return CodedValueValidationListener
	 */
	private function coded(array $shapeErrors, ?\Throwable $enforce, array &$calls, bool $schemaFails=false): CodedValueValidationListener {
		$schema = new Schema();
		$schemas = $this->createMock(SchemaMapperInterface::class);
		if ($schemaFails === true) {
			$schemas->method('find')->willThrowException(new RuntimeException('no schema'));
		} else {
			$schemas->method('find')->willReturnCallback(
				function ($id, $extend = [], $rbac = true, $tenancy = true) use ($schema, &$calls): Schema {
					$calls[] = 'find:'.$id.':'.var_export($rbac, true).':'.var_export($tenancy, true);
					return $schema;
				}
			);
		}

		$shape = $this->createMock(ConceptShapeGuard::class);
		$shape->method('violations')->willReturnCallback(
			function (array $object) use ($shapeErrors, &$calls): array {
				$calls[] = 'shape:'.json_encode($object);
				return $shapeErrors;
			}
		);
		$guard = $this->createMock(CodedValueGuard::class);
		$guard->method('enforce')->willReturnCallback(
			function (array $object) use ($enforce, &$calls): void {
				$calls[] = 'enforce:'.json_encode($object);
				if ($enforce !== null) {
					throw $enforce;
				}
			}
		);

		return new CodedValueValidationListener($guard, $shape, $schemas, $this->logger());
	}//end coded()

	/**
	 * A concept failing its shape is refused before the coded values are read.
	 *
	 * @return void
	 */
	public function testCodedShapeRefusal(): void {
		$calls = [];
		$event = new ObjectCreatingEvent($this->object(['a' => 1]));
		$this->coded(['notation' => 'Notation is required.', 'label' => 'Label too long.'], null, $calls)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(
			[
				'code' => 'concept-shape-invalid',
				'message' => 'Notation is required. Label too long.',
				'notation' => 'Notation is required.',
				'label' => 'Label too long.',
			],
			$event->getErrors()
		);
		self::assertSame(['find:4:false:false', 'shape:{"id":"u-1","a":1}'], $calls);
	}//end testCodedShapeRefusal()

	/**
	 * A refused coded value is refused with the guard's errors; on update too.
	 *
	 * @return void
	 */
	public function testCodedValueRefusal(): void {
		$calls = [];
		$event = new ObjectUpdatingEvent($this->object(['c' => 'x']), $this->object([]));
		$this->coded([], new CodedValueException('nope', ['c' => 'Not in the scheme.']), $calls)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['code' => 'coded-value-refused', 'message' => 'Not in the scheme.', 'c' => 'Not in the scheme.'], $event->getErrors());
		self::assertCount(3, $calls);
	}//end testCodedValueRefusal()

	/**
	 * The guard failing for its own reasons lets the save through and logs it.
	 *
	 * @return void
	 */
	public function testCodedFailureAllows(): void {
		$calls = [];
		$event = new ObjectCreatingEvent($this->object(['c' => 'x']));
		$this->coded([], new RuntimeException('vocabulary down'), $calls)->handle($event);
		$this->coded([], null, $calls, true)->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getErrors());
		self::assertSame(
			[
				'[CodedValueValidationListener] The guard itself failed, allowing the save: vocabulary down {"exception":"RuntimeException"}',
				'[CodedValueValidationListener] The guard itself failed, allowing the save: no schema {"exception":"RuntimeException"}',
			],
			$this->warnings
		);
	}//end testCodedFailureAllows()

	/**
	 * No schema reference, or another event: nothing is read.
	 *
	 * @return void
	 */
	public function testCodedNothingToDo(): void {
		$calls = [];
		$listener = $this->coded(['x' => 'y'], null, $calls);
		$listener->handle(new ObjectCreatingEvent($this->object(['a' => 1], '')));
		$listener->handle(new Event());
		self::assertSame([], $calls);
	}//end testCodedNothingToDo()

	/**
	 * The working-calendar listener.
	 *
	 * @param bool            $isCalendar Whether the object is a calendar.
	 * @param \Throwable|null $assert     What assertValid throws.
	 * @param list<string>    $calls      Collects the assertValid calls.
	 *
	 * @return WorkingCalendarValidationListener
	 */
	private function calendar(bool $isCalendar, ?\Throwable $assert, array &$calls): WorkingCalendarValidationListener {
		$guard = $this->createMock(WorkingCalendarWriteGuard::class);
		$guard->method('isWorkingCalendar')->willReturn($isCalendar);
		$guard->method('assertValid')->willReturnCallback(
			function (array $definition, ?string $fallbackSlug = null) use ($assert, &$calls): void {
				$calls[] = json_encode($definition).'|'.var_export($fallbackSlug, true);
				if ($assert !== null) {
					throw $assert;
				}
			}
		);
		return new WorkingCalendarValidationListener($guard, $this->logger());
	}//end calendar()

	/**
	 * A refused calendar stops the write with the guard's message.
	 *
	 * @return void
	 */
	public function testCalendarRefusal(): void {
		$calls = [];
		$event = new ObjectCreatingEvent($this->object(['hours' => 8]));
		$this->calendar(true, new FlowTimerValidationException('Calendar needs hours.'), $calls)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['code' => 'working-calendar-invalid', 'message' => 'Calendar needs hours.'], $event->getErrors());
		self::assertSame(['{"id":"u-1","hours":8}|NULL'], $calls);
	}//end testCalendarRefusal()

	/**
	 * The stored slug is the fallback on an update, trimmed, and only when non-empty.
	 *
	 * @return void
	 */
	public function testCalendarFallbackSlug(): void {
		$calls = [];
		$listener = $this->calendar(true, null, $calls);
		$listener->handle(new ObjectUpdatingEvent($this->object(['a' => 1]), $this->object(['slug' => '  nl-default '])));
		$listener->handle(new ObjectUpdatingEvent($this->object(['a' => 1]), $this->object(['slug' => '   '])));
		$listener->handle(new ObjectUpdatingEvent($this->object(['a' => 1]), $this->object(['other' => 1])));
		$listener->handle(new ObjectUpdatingEvent($this->object(['a' => 1]), null));

		self::assertSame(
			[
				'{"id":"u-1","a":1}|\'nl-default\'',
				'{"id":"u-1","a":1}|NULL',
				'{"id":"u-1","a":1}|NULL',
				'{"id":"u-1","a":1}|NULL',
			],
			$calls
		);
	}//end testCalendarFallbackSlug()

	/**
	 * Not a calendar: untouched. The guard failing: allowed and logged.
	 *
	 * @return void
	 */
	public function testCalendarSkipAndFailure(): void {
		$calls = [];
		$event = new ObjectCreatingEvent($this->object(['a' => 1]));
		$this->calendar(false, new FlowTimerValidationException('x'), $calls)->handle($event);
		self::assertSame([], $calls);

		$this->calendar(true, new RuntimeException('engine down'), $calls)->handle($event);
		self::assertFalse($event->isPropagationStopped());
		self::assertSame(
			['[WorkingCalendarValidationListener] The guard itself failed, allowing the save: engine down {"exception":"RuntimeException"}'],
			$this->warnings
		);
	}//end testCalendarSkipAndFailure()

	/**
	 * The built-in actions are registered in their fixed order; a refused one is logged and skipped.
	 *
	 * @return void
	 */
	public function testBulkActionsRegisterInOrder(): void {
		$make = function (string $class, string $id): BulkActionInterface {
			$action = $this->createMock($class);
			$action->method('getId')->willReturn($id);
			return $action;
		};
		$registered = [];
		$registry = $this->createMock(BulkActionRegistryInterface::class);
		$registry->method('register')->willReturnCallback(
			function (BulkActionInterface $action) use (&$registered): void {
				if ($action->getId() === 'assign') {
					throw new InvalidArgumentException('taken');
				}

				$registered[] = $action->getId();
			}
		);

		$listener = new BulkActionRegistrationListener(
			new BulkEditActions($make(SetPropertiesActionInterface::class, 'set'), $make(AssignActionInterface::class, 'assign'), $make(ApplyRuleActionInterface::class, 'rule')),
			new BulkSetActions($make(ExportWholeSetAction::class, 'export'), $make(RestorePriorValuesActionInterface::class, 'restore'), $make(RunActionActionInterface::class, 'run')),
			$this->logger()
		);
		$listener->handle(new Event());
		$listener->handle(new BulkActionRegistrationEvent($registry));

		self::assertSame(['set', 'rule', 'export', 'restore', 'run'], $registered);
		self::assertSame(['[BulkActionRegistration] Could not register a built-in action {"action":"assign","error":"taken"}'], $this->warnings);
	}//end testBulkActionsRegisterInOrder()
}//end class
