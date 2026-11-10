<?php

/**
 * A patch hands the caller's flags and user to the save, and reads types without them.
 *
 * The save is where a patch is authorised, so a flag lost on the way there changes
 * who may write. Every combination is pinned, because a test that only passes
 * `false` cannot see a flag replaced by that same `false`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\IAppContainer;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * @covers \OCA\OpenRegister\Service\ObjectService
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Service\Object\PatchDataMerge
 */
class ObjectServicePatchForwardingTest extends TestCase {

	/**
	 * The identifier the caller holds.
	 *
	 * @var string
	 */
	private string $identifier = 'u-1';

	/**
	 * The register the caller names.
	 *
	 * @var Register|null
	 */
	private ?Register $register = null;

	/**
	 * The argument a call received, by the parameter's name.
	 *
	 * @param string            $class  The class declaring the method.
	 * @param string            $method The method.
	 * @param array<int, mixed> $args   The positional arguments received.
	 * @param string            $name   The parameter.
	 *
	 * @return mixed
	 */
	private function argument(string $class, string $method, array $args, string $name): mixed {
		foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
			if ($parameter->getName() === $name) {
				return $args[$parameter->getPosition()];
			}
		}

		$this->fail($name.' is no parameter of '.$method);
	}//end argument()

	/**
	 * Run one patch with the save intercepted.
	 *
	 * @param bool       $rbac         The caller's RBAC flag.
	 * @param bool       $multitenancy The caller's multitenancy flag.
	 * @param IUser|null $user         The acting user.
	 *
	 * @return array{save: array<int, mixed>, schema: array<int, mixed>, lookup: array<int, mixed>} What each call received.
	 */
	private function patch(bool $rbac, bool $multitenancy, ?IUser $user): array {
		$existing = new ObjectEntity();
		$existing->setUuid('u-1');
		$existing->setSchema('2');
		$existing->setObject(['id' => 'an-older-id', 'title' => 'Alpha']);

		$seen = [];

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('find')->willReturnCallback(
			static function (mixed ...$args) use (&$seen, $existing): ObjectEntity {
				$seen['lookup'] = $args;
				return $existing;
			}
		);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			static function (mixed ...$args) use (&$seen): Schema {
				$seen['schema'] = $args;
				return new Schema();
			}
		);

		$service = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['saveObject'])
			->getMock();
		$service->method('saveObject')->willReturnCallback(
			static function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true) use (&$seen, $existing): ObjectEntity {
				$args = [$object, $options->extend, $options->target->register, $options->target->schema, $options->uuid, $_rbac, $_multitenancy, $options->policy->silent, $options->policy->validation, $options->uploadedFiles, $options->actor->currentUser, $options->policy->failIfExists, $options->actor->unowned, $options->policy->dedupOverride, $options->actor->owner];
				$seen['save'] = $args;
				return $existing;
			}
		);

		$values = [
			'objectMapper' => $mapper,
			'schemaMapper' => $schemas,
			'container'    => $this->createMock(IAppContainer::class),
			'logger'       => $this->createMock(LoggerInterface::class),
		];
		foreach ($values as $name => $value) {
			(new ReflectionProperty(ObjectService::class, $name))->setValue($service, $value);
		}

		$service->patchObject(
			objectId: $this->identifier,
			data: ['title' => 'Beta'],
			register: $this->register,
			_rbac: $rbac,
			_multitenancy: $multitenancy,
			currentUser: $user
		);

		return $seen;
	}//end patch()

	/**
	 * Every combination of the two flags, with and without an acting user.
	 *
	 * @return array<string, array{0: bool, 1: bool, 2: bool}>
	 */
	public static function callers(): array {
		return [
			'all on, session user'  => [true, true, false],
			'all off, named user'   => [false, false, true],
			'rbac only, named user' => [true, false, true],
			'tenancy only'          => [false, true, false],
		];
	}//end callers()

	#[DataProvider('callers')]
	public function testTheSaveReceivesTheCallersFlagsAndUser(bool $rbac, bool $multitenancy, bool $named): void {
		$user = null;
		if ($named === true) {
			$user = $this->createMock(IUser::class);
		}

		$save = $this->patch($rbac, $multitenancy, $user)['save'];

		$this->assertSame($rbac, $this->argument(ObjectService::class, 'saveObject', $save, '_rbac'));
		$this->assertSame($multitenancy, $this->argument(ObjectService::class, 'saveObject', $save, '_multitenancy'));
		$this->assertSame($user, $this->argument(ObjectService::class, 'saveObject', $save, 'currentUser'));
	}//end testTheSaveReceivesTheCallersFlagsAndUser()

	public function testTheSaveIsAddressedAtTheObjectThatWasResolved(): void {
		$this->identifier = 'a-slug';

		$save = $this->patch(true, true, null)['save'];

		$this->assertSame('u-1', $this->argument(ObjectService::class, 'saveObject', $save, 'object')['id']);
	}//end testTheSaveIsAddressedAtTheObjectThatWasResolved()

	public function testHalfAScopeStillUsesTheCrossTableLookup(): void {
		$this->register = new Register();
		$this->register->setId(1);

		$lookup = $this->patch(true, true, null)['lookup'];

		$this->assertNull($this->argument(MagicMapper::class, 'find', $lookup, 'register'));
		$this->assertNull($this->argument(MagicMapper::class, 'find', $lookup, 'schema'));
	}//end testHalfAScopeStillUsesTheCrossTableLookup()

	public function testTheSchemaOfTheTypesIsReadWithoutTheCallersRights(): void {
		$schema = $this->patch(true, true, null)['schema'];

		$this->assertFalse($this->argument(SchemaMapper::class, 'find', $schema, '_rbac'));
		$this->assertFalse($this->argument(SchemaMapper::class, 'find', $schema, '_multitenancy'));
	}//end testTheSchemaOfTheTypesIsReadWithoutTheCallersRights()
}//end class
