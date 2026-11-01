<?php

/**
 * The Kernel entity interfaces describe their Db entity completely.
 *
 * Callers outside lib/Db type against these interfaces, and the Kernel
 * contracts name them instead of the entities, so lib/Kernel never depends
 * on lib/Db. An interface that misses an accessor the entity has would make
 * a caller fall back to the concrete class, so every column accessor and
 * every explicit public method of the entity must be on its interface.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\ScheduledReport;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Kernel\Contracts\FlowTimerInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRowInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterInterface;
use OCA\OpenRegister\Kernel\Contracts\ScheduledReportInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCA\OpenRegister\Kernel\Contracts\TaskInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class EntityInterfacesTest extends TestCase {

	/**
	 * Public entity methods that stay off the interface, because their
	 * signature names a Service or Db class a Kernel contract may not name.
	 *
	 * @var array<class-string, array<int, string>>
	 */
	private const ENTITY_ONLY = [
		Schema::class   => ['getManagedByConfiguration', 'hydrate', 'validateProperties'],
		Register::class => ['getManagedByConfiguration'],
		ObjectEntity::class => ['getManagedByConfiguration'],
	];

	/**
	 * Each entity with the Kernel interface it implements.
	 *
	 * @return array<string, array{class-string, class-string}>
	 */
	public static function entities(): array {
		return [
			'Task'            => [Task::class, TaskInterface::class],
			'FlowTimer'       => [FlowTimer::class, FlowTimerInterface::class],
			'ScheduledReport' => [ScheduledReport::class, ScheduledReportInterface::class],
			'Schema'          => [Schema::class, SchemaInterface::class],
			'Register'        => [Register::class, RegisterInterface::class],
			'ObjectEntity'    => [ObjectEntity::class, ObjectRowInterface::class],
		];
	}//end entities()

	/**
	 * The entity implements its interface.
	 *
	 * @param class-string $entity    The entity class.
	 * @param class-string $interface The Kernel interface.
	 *
	 * @return void
	 */
	#[DataProvider('entities')]
	public function testTheEntityImplementsItsInterface(string $entity, string $interface): void {
		$this->assertInstanceOf(expected: $interface, actual: new $entity());
	}//end testTheEntityImplementsItsInterface()

	/**
	 * Every @method accessor of the entity is on the interface, as a tag or as a declared method.
	 *
	 * @param class-string $entity    The entity class.
	 * @param class-string $interface The Kernel interface.
	 *
	 * @return void
	 */
	#[DataProvider('entities')]
	public function testEveryColumnAccessorIsOnTheInterface(string $entity, string $interface): void {
		$missing = array_values(
			array_filter(
				array_diff($this->methodTags(class: $entity), $this->methodTags(class: $interface)),
				fn (string $method): bool => method_exists($interface, $method) === false
			)
		);

		$this->assertSame(expected: [], actual: $missing);
	}//end testEveryColumnAccessorIsOnTheInterface()

	/**
	 * Every explicit public method the entity declares itself is on the interface.
	 *
	 * @param class-string $entity    The entity class.
	 * @param class-string $interface The Kernel interface.
	 *
	 * @return void
	 */
	#[DataProvider('entities')]
	public function testEveryExplicitPublicMethodIsOnTheInterface(string $entity, string $interface): void {
		$declared = array_filter(
			(new ReflectionClass($entity))->getMethods(ReflectionMethod::IS_PUBLIC),
			fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $entity
				&& $method->isConstructor() === false
				&& $method->isStatic() === false
		);

		$missing = [];
		foreach ($declared as $method) {
			if (in_array($method->getName(), self::ENTITY_ONLY[$entity] ?? [], true) === true) {
				continue;
			}

			if (method_exists($interface, $method->getName()) === false) {
				$missing[] = $method->getName();
			}
		}

		$this->assertSame(expected: [], actual: $missing);
	}//end testEveryExplicitPublicMethodIsOnTheInterface()

	/**
	 * The performer vocabulary moved onto TaskInterface and still reads the same through Task.
	 *
	 * @return void
	 */
	public function testTaskPerformerConstantsKeepTheirValues(): void {
		$this->assertSame(expected: 'external', actual: Task::PERFORMER_EXTERNAL);
		$this->assertSame(expected: 'party:', actual: Task::EXTERNAL_PARTY_PREFIX);
		$this->assertSame(
			expected: ['user', 'group', 'agent', 'worker', 'external'],
			actual: Task::PERFORMER_TYPES
		);
		$this->assertSame(expected: TaskInterface::PERFORMER_TYPES, actual: Task::PERFORMER_TYPES);
	}//end testTaskPerformerConstantsKeepTheirValues()

	/**
	 * The method names a class lists in @method tags.
	 *
	 * @param class-string $class The class or interface.
	 *
	 * @return array<int, string>
	 */
	private function methodTags(string $class): array {
		preg_match_all(
			'/@method\s+(?:static\s+)?\S+\s+(\w+)\(/',
			(string)(new ReflectionClass($class))->getDocComment(),
			$matches
		);

		return array_values(array_unique($matches[1]));
	}//end methodTags()
}//end class
