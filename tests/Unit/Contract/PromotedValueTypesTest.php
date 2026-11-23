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
		$target   = new Value\ObjectFindTarget(register: 'publications', schema: 'person');
		$policy   = new Value\SavePolicy(silent: true);
		$actor    = new Value\SaveActor(owner: 'portal:abc');
		$defaults = new Value\BulkBatchDefaults(register: 3);
		$runStep  = new Value\LockRunStep(runUuid: 'run-1', nodeId: 'node-2');
		$reach    = static fn (string $uuid): bool => $uuid !== '';

		return [
			'ObjectFindTarget'  => ['ObjectFindTarget', ['register' => 'publications', 'schema' => 7]],
			'SaveActor'         => ['SaveActor', ['owner' => 'portal:abc', 'unowned' => true]],
			'SavePolicy'        => [
				'SavePolicy',
				['silent' => true, 'validation' => false, 'failIfExists' => true, 'dedupOverride' => true],
			],
			'BulkBatchDefaults' => ['BulkBatchDefaults', ['register' => 3, 'schema' => 'person']],
			'LockRunStep'       => ['LockRunStep', ['runUuid' => 'run-1', 'nodeId' => 'node-2']],
			'ObjectSaveOptions' => [
				'ObjectSaveOptions',
				[
					'target'        => $target,
					'uuid'          => 'abc-123',
					'extend'        => ['owner'],
					'uploadedFiles' => ['file' => ['name' => 'a.txt']],
					'policy'        => $policy,
					'actor'         => $actor,
				],
			],
			'BulkSaveOptions'   => [
				'BulkSaveOptions',
				['defaults' => $defaults, 'validation' => true, 'events' => true, 'audit' => false],
			],
			'LockRequest'       => [
				'LockRequest',
				['identifier' => 'abc-123', 'process' => 'import', 'duration' => 60, 'runStep' => $runStep],
			],
			'UnlockRequest'     => [
				'UnlockRequest',
				['identifier' => 'abc-123', 'advisory' => true, 'runUuid' => 'run-1', 'break' => true],
			],
			'ObjectFindOptions' => [
				'ObjectFindOptions',
				['register' => 'publications', 'schema' => 7, 'extend' => ['owner'], 'files' => true, 'render' => false, 'audit' => false],
			],
			'ObjectDeletion'    => [
				'ObjectDeletion',
				['uuid' => 'abc-123', 'target' => $target, 'permanent' => true, 'reach' => $reach],
			],
			'ObjectPatch'       => [
				'ObjectPatch',
				['objectId' => '42', 'data' => ['title' => 'New'], 'target' => $target],
			],
			'ObjectValidationVerdict' => [
				'ObjectValidationVerdict',
				['errors' => [['property' => 'title', 'message' => 'Required', 'rule' => 'schema']]],
			],
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
		$this->assertEquals(expected: get_object_vars(new Contract\ObjectSaveOptions()), actual: get_object_vars(new Value\ObjectSaveOptions()));
		$this->assertEquals(expected: get_object_vars(new Contract\BulkSaveOptions()), actual: get_object_vars(new Value\BulkSaveOptions()));
		$this->assertEquals(expected: get_object_vars(new Contract\LockRequest(identifier: 'a')), actual: get_object_vars(new Value\LockRequest(identifier: 'a')));
		$this->assertSame(expected: get_object_vars(new Contract\UnlockRequest(identifier: 'a')), actual: get_object_vars(new Value\UnlockRequest(identifier: 'a')));
		$this->assertSame(expected: get_object_vars(new Contract\ObjectFindOptions()), actual: get_object_vars(new Value\ObjectFindOptions()));
		$this->assertEquals(expected: get_object_vars(new Contract\ObjectDeletion(uuid: 'a')), actual: get_object_vars(new Value\ObjectDeletion(uuid: 'a')));
		$this->assertEquals(expected: get_object_vars(new Contract\ObjectPatch(objectId: 'a', data: [])), actual: get_object_vars(new Value\ObjectPatch(objectId: 'a', data: [])));
		$this->assertSame(expected: get_object_vars(new Contract\ObjectValidationVerdict()), actual: get_object_vars(new Value\ObjectValidationVerdict()));
	}//end testBothNamesHaveTheSameDefaults()

	/**
	 * Positional construction under the old name keeps working, as fleet apps write it.
	 *
	 * @return void
	 */
	public function testPositionalConstructionKeepsWorkingUnderTheOldName(): void {
		$target  = new Value\ObjectFindTarget('publications', 'person');
		$options = new Value\ObjectSaveOptions($target, 'abc-123');
		$lock    = new Value\LockRequest('abc-123', 'import', 60, new Value\LockRunStep('run-1', 'node-2'));

		$this->assertSame(expected: $target, actual: $options->target);
		$this->assertSame(expected: 'abc-123', actual: $options->uuid);
		$this->assertSame(expected: 'run-1', actual: $lock->runUuid);
		$this->assertSame(expected: 'node-2', actual: $lock->nodeId);
		$this->assertTrue((new Value\ObjectDeletion('abc-123', $target))->isScoped());
	}//end testPositionalConstructionKeepsWorkingUnderTheOldName()

	/**
	 * The methods the values carry answer the same under both names.
	 *
	 * @return void
	 */
	public function testTheInheritedMethodsAnswerTheSame(): void {
		$errors = [['property' => 'title', 'message' => 'Required', 'rule' => 'schema']];

		$this->assertSame(expected: (new Contract\ObjectValidationVerdict(errors: $errors))->jsonSerialize(), actual: (new Value\ObjectValidationVerdict(errors: $errors))->jsonSerialize());
		$this->assertFalse((new Value\ObjectValidationVerdict(errors: $errors))->isValid());
		$this->assertTrue((new Value\ObjectValidationVerdict())->isValid());
		$this->assertFalse((new Value\ObjectDeletion(uuid: 'a'))->isScoped());
		$this->assertTrue((new Contract\ObjectDeletion(uuid: 'a', target: new Contract\ObjectFindTarget(register: 1, schema: 2)))->isScoped());
	}//end testTheInheritedMethodsAnswerTheSame()

	/**
	 * The values built under the old name are accepted where the published name is asked for.
	 *
	 * @return void
	 */
	public function testAValueOfThePublishedNameIsAcceptedInsideTheOldValues(): void {
		$target = new Contract\ObjectFindTarget(register: 'publications', schema: 'person');

		$this->assertSame(expected: $target, actual: (new Value\ObjectSaveOptions(target: $target))->target);
		$this->assertSame(expected: $target, actual: (new Value\ObjectDeletion(uuid: 'a', target: $target))->target);
		$this->assertSame(expected: $target, actual: (new Value\ObjectPatch(objectId: 'a', data: [], target: $target))->target);
		$this->assertSame(expected: $target->register, actual: Value\ObjectFindTarget::fromFindOptions(options: new Contract\ObjectFindOptions(register: 'publications'))->register);
	}//end testAValueOfThePublishedNameIsAcceptedInsideTheOldValues()

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
