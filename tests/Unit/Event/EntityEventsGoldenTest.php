<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Event;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins what a listening app reads off the single-entity events: the class, the
 * named constructor argument, the typed getter, and that each event stands alone.
 */
class EntityEventsGoldenTest extends TestCase {
	private const FAMILIES = [
		'Agent'         => ['agent', ['Created', 'Deleted'], 'Mcp\\Db\\Agent'],
		'Application'   => ['application', ['Created', 'Deleted'], 'Db\\Application'],
		'Configuration' => ['configuration', ['Created', 'Deleted'], 'Db\\Configuration'],
		'Conversation'  => ['conversation', ['Created', 'Deleted'], 'Mcp\\Db\\Conversation'],
		'Organisation'  => ['organisation', ['Created', 'Deleted'], 'Db\\Organisation'],
		'Register'      => ['register', ['Created', 'Deleted'], 'Db\\Register'],
		'Schema'        => ['schema', ['Created', 'Deleted'], 'Db\\Schema'],
		'Source'        => ['source', ['Created', 'Deleted'], 'Db\\Source'],
		'View'          => ['view', ['Created', 'Deleted'], 'Search\\Db\\View'],
		'Object'        => ['object', ['Created', 'Deleted', 'Locked', 'Unlocked'], 'Db\\ObjectEntity'],
	];

	/**
	 * Entities outside the Kernel travel under the Kernel read interface they implement (ADR-013 Rule 1).
	 */
	private const DECLARED_AS = [
		'OCA\\OpenRegister\\Mcp\\Db\\Agent'        => 'OCA\\OpenRegister\\Kernel\\Contracts\\AgentInterface',
		'OCA\\OpenRegister\\Mcp\\Db\\Conversation' => 'OCA\\OpenRegister\\Kernel\\Contracts\\ConversationInterface',
		'OCA\\OpenRegister\\Search\\Db\\View'      => 'OCA\\OpenRegister\\Kernel\\Contracts\\ViewInterface',
	];

	public static function events(): array {
		$cases = [];
		foreach (self::FAMILIES as $name => [$argument, $verbs, $entity]) {
			foreach ($verbs as $verb) {
				$cases[$name . $verb] = ['OCA\\OpenRegister\\Event\\' . $name . $verb . 'Event', $argument, 'OCA\\OpenRegister\\' . $entity, 'get' . $name];
			}
		}

		return $cases;
	}//end events()

	#[DataProvider('events')]
	public function testTheEntityTravelsUnderItsNamedArgument(string $class, string $argument, string $entity, string $getter): void {
		$value = new $entity();

		$this->assertSame($value, (new $class(...[$argument => $value]))->$getter());
	}//end testTheEntityTravelsUnderItsNamedArgument()

	#[DataProvider('events')]
	public function testTheSignatureStaysTheSame(string $class, string $argument, string $entity, string $getter): void {
		$parameters = (new ReflectionClass($class))->getConstructor()->getParameters();

		$this->assertCount(1, $parameters);
		$declared = (self::DECLARED_AS[$entity] ?? $entity);

		$this->assertSame($argument, $parameters[0]->getName());
		$this->assertTrue(is_a($entity, $declared, true));
		$this->assertSame($declared, (string) $parameters[0]->getType());
		$this->assertSame($declared, (string) (new ReflectionMethod($class, $getter))->getReturnType());
	}//end testTheSignatureStaysTheSame()

	#[DataProvider('events')]
	public function testTheEventIsConcreteAndStoppable(string $class, string $argument, string $entity): void {
		$event = new $class(new $entity());

		$this->assertInstanceOf(Event::class, $event);
		$this->assertFalse((new ReflectionClass($class))->isAbstract());
		$this->assertFalse($event->isPropagationStopped());
		$event->stopPropagation();
		$this->assertTrue($event->isPropagationStopped());
	}//end testTheEventIsConcreteAndStoppable()

	#[DataProvider('events')]
	public function testNoEventIsAnotherOfItsFamily(string $class): void {
		foreach (array_column(self::events(), 0) as $other) {
			$this->assertFalse(is_subclass_of($class, $other), $class . ' is a ' . $other);
		}
	}//end testNoEventIsAnotherOfItsFamily()
}//end class
