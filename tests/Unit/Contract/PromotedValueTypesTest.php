<?php

/**
 * The value types of the published ObjectServiceInterface live in lib/Contract,
 * and their old Kernel\Contracts\Value names keep building the same values.
 *
 * Fleet apps construct, type-hint and check these values under the old names.
 * Each old name is now a final subclass of the published class with nothing of
 * its own, so every construction, instanceof and type hint keeps working while
 * apps move to the published names (task 7.2, then 7.3).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

use OCA\OpenRegister\Contract;
use OCA\OpenRegister\Kernel\Contracts\Value;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class PromotedValueTypesTest extends TestCase {

	/**
	 * Each promoted type with the named arguments of one instance.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function promotedTypes(): array {
		return [
			'ObjectFindTarget'  => ['ObjectFindTarget', ['register' => 'publications', 'schema' => 7]],
			'SaveActor'         => ['SaveActor', ['owner' => 'portal:abc', 'unowned' => true]],
			'SavePolicy'        => [
				'SavePolicy',
				['silent' => true, 'validation' => false, 'failIfExists' => true, 'dedupOverride' => true],
			],
			'BulkBatchDefaults' => ['BulkBatchDefaults', ['register' => 3, 'schema' => 'person']],
			'LockRunStep'       => ['LockRunStep', ['runUuid' => 'run-1', 'nodeId' => 'node-2']],
		];
	}//end promotedTypes()

	/**
	 * An instance built under the old name is an instance of the published name.
	 *
	 * @param string               $type The short class name.
	 * @param array<string, mixed> $args Named constructor arguments.
	 *
	 * @return void
	 */
	#[DataProvider('promotedTypes')]
	public function testTheOldNameIsThePublishedType(string $type, array $args): void {
		$old = self::oldName(type: $type);

		$this->assertInstanceOf(self::newName(type: $type), new $old(...$args));
	}//end testTheOldNameIsThePublishedType()

	/**
	 * Both names build objects with the same properties from the same named arguments.
	 *
	 * @param string               $type The short class name.
	 * @param array<string, mixed> $args Named constructor arguments.
	 *
	 * @return void
	 */
	#[DataProvider('promotedTypes')]
	public function testBothNamesBuildEqualValues(string $type, array $args): void {
		$old = self::oldName(type: $type);
		$new = self::newName(type: $type);

		$this->assertSame(expected: get_object_vars(new $new(...$args)), actual: get_object_vars(new $old(...$args)));
	}//end testBothNamesBuildEqualValues()

	/**
	 * The old name adds nothing: it is final, extends the published class and declares no member of its own.
	 *
	 * @param string               $type The short class name.
	 * @param array<string, mixed> $args Named constructor arguments, unused here.
	 *
	 * @return void
	 */
	#[DataProvider('promotedTypes')]
	public function testTheOldNameIsAnEmptyFinalSubclass(string $type, array $args): void {
		$this->assertIsArray($args);
		$old = new ReflectionClass(self::oldName(type: $type));
		$new = new ReflectionClass(self::newName(type: $type));

		$this->assertTrue($old->isFinal());
		$this->assertFalse($new->isFinal());
		$this->assertSame(expected: $new->getName(), actual: $old->getParentClass()->getName());
		$this->assertSame(expected: $new->getConstants(), actual: $old->getConstants());
		$this->assertSame(expected: $new->getConstructor()?->getName(), actual: $old->getConstructor()?->getName());
		$this->assertSame(expected: $new->getName(), actual: $old->getConstructor()?->getDeclaringClass()->getName());
	}//end testTheOldNameIsAnEmptyFinalSubclass()

	/**
	 * Both names build a save actor with the same acting user.
	 *
	 * @return void
	 */
	public function testBothNamesCarryTheActingUser(): void {
		$user = $this->createMock(IUser::class);

		$this->assertSame(expected: $user, actual: (new Value\SaveActor(currentUser: $user))->currentUser);
		$this->assertSame(expected: $user, actual: (new Contract\SaveActor(currentUser: $user))->currentUser);
	}//end testBothNamesCarryTheActingUser()

	/**
	 * The defaults are the same under both names.
	 *
	 * @return void
	 */
	public function testBothNamesHaveTheSameDefaults(): void {
		$this->assertSame(expected: get_object_vars(new Contract\SavePolicy()), actual: get_object_vars(new Value\SavePolicy()));
		$this->assertSame(expected: get_object_vars(new Contract\SaveActor()), actual: get_object_vars(new Value\SaveActor()));
		$this->assertSame(expected: get_object_vars(new Contract\ObjectFindTarget()), actual: get_object_vars(new Value\ObjectFindTarget()));
		$this->assertSame(expected: get_object_vars(new Contract\BulkBatchDefaults()), actual: get_object_vars(new Value\BulkBatchDefaults()));
	}//end testBothNamesHaveTheSameDefaults()

	/**
	 * The named constructor of ObjectFindTarget returns the class it is called on.
	 *
	 * @return void
	 */
	public function testTheNamedConstructorReturnsTheClassItIsCalledOn(): void {
		$options = new Value\ObjectFindOptions(register: 'publications', schema: 7);

		$old = Value\ObjectFindTarget::fromFindOptions(options: $options);
		$new = Contract\ObjectFindTarget::fromFindOptions(options: $options);

		$this->assertSame(expected: Value\ObjectFindTarget::class, actual: $old::class);
		$this->assertSame(expected: Contract\ObjectFindTarget::class, actual: $new::class);
		$this->assertSame(expected: get_object_vars($new), actual: get_object_vars($old));
		$this->assertSame(expected: 'publications', actual: $old->register);
		$this->assertSame(expected: 7, actual: $old->schema);
	}//end testTheNamedConstructorReturnsTheClassItIsCalledOn()

	/**
	 * The old, deprecated name of a promoted type.
	 *
	 * @param string $type The short class name.
	 *
	 * @return class-string
	 */
	private static function oldName(string $type): string {
		return 'OCA\\OpenRegister\\Kernel\\Contracts\\Value\\' . $type;
	}//end oldName()

	/**
	 * The published name of a promoted type.
	 *
	 * @param string $type The short class name.
	 *
	 * @return class-string
	 */
	private static function newName(string $type): string {
		return 'OCA\\OpenRegister\\Contract\\' . $type;
	}//end newName()
}//end class
