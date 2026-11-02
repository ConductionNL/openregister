<?php

/**
 * MapperRegistrar registers the hand-built mappers in a fixed order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MapperRegistrar;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\MacroActionValidator;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionValidator;
use OCA\OpenRegister\Tests\Unit\AppHost\FactoryWiringAssertions;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MapperRegistrarTest extends TestCase {
	use FactoryWiringAssertions;

	/**
	 * register() registers the hand-built mappers, in this order.
	 *
	 * @return void
	 */
	public function testRegistersTheServicesInOrder(): void {
		$context = new RecordingRegistrationContext();
		MapperRegistrar::register(context: $context);

		$this->assertSame(
			expected: [
				'OCA\OpenRegister\Db\SchemaMapper',
				'OCA\OpenRegister\Db\RegisterMapper',
				'OCA\OpenRegister\Db\WebhookMapper',
				'OCA\OpenRegister\Db\MagicMapper',
				'OCA\OpenRegister\Db\EntityRelationMapper',
			],
			actual: $context->services
		);
	}//end testRegistersTheServicesInOrder()

	/**
	 * Every factory builds an instance of the name it is registered under.
	 *
	 * The container hands each factory a test double for every service it asks
	 * for. Each built service must also hold every nullable object dependency
	 * its constructor declares: the factory overrides autowiring, so one it
	 * leaves out is never supplied (openregister#5143).
	 *
	 * @return void
	 */
	public function testEveryFactoryBuildsItsService(): void {
		$context = new RecordingRegistrationContext();
		MapperRegistrar::register(context: $context);

		$doubles = [];
		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$doubles) {
				$doubles[$id] ??= $this->createMock(originalClassName: $id);
				return $doubles[$id];
			}
		);

		foreach ($context->factories as $name => $factory) {
			$service = $factory($container);
			$this->assertInstanceOf(expected: $name, actual: $service, message: $name);
			$this->assertFactorySuppliedEveryOptionalDependency(service: $service);
		}
	}//end testEveryFactoryBuildsItsService()
	/**
	 * The SchemaMapper the factory builds refuses `elevate` from a non-administrator.
	 *
	 * The factory once omitted the declared-action validator, so production
	 * saves skipped the admin-only `elevate` rule and the bound-flow checks
	 * (openregister#5143). The validator is resolved lazily: building the
	 * mapper must not ask the container for it, or the
	 * ObjectActionValidator → … → RegisterMapper → SchemaMapper cycle returns.
	 *
	 * @return void
	 */
	public function testTheSchemaMapperFactoryWiresTheDeclaredActionValidator(): void {
		$context = new RecordingRegistrationContext();
		MapperRegistrar::register(context: $context);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$validator = new ObjectActionValidator(
			macros: $this->createMock(originalClassName: MacroActionValidator::class),
			userSession: $session,
			groupManager: $groups
		);

		$requested = [];
		$doubles   = [];
		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$requested, &$doubles, $validator) {
				$requested[] = $id;
				if ($id === ObjectActionValidator::class) {
					return $validator;
				}

				$doubles[$id] ??= $this->createMock(originalClassName: $id);
				return $doubles[$id];
			}
		);

		$mapper = $context->factories[SchemaMapper::class]($container);
		$this->assertNotContains(
			needle: ObjectActionValidator::class,
			haystack: $requested,
			message: 'Building SchemaMapper must not resolve the validator: that closes a construction cycle.'
		);

		$schema = new Schema();
		$schema->setConfiguration(
			[
				'x-openregister-action' => [
					'publish' => [
						'name'        => 'Publish',
						'description' => 'Publish it.',
						'steps'       => [['type' => 'openregister.set-fields']],
						'elevate'     => [['register' => 'pub', 'schema' => 'publication', 'verbs' => ['create']]],
					],
				],
			]
		);
		$schema->setAuthorization(['publish' => [['group' => 'redactie']]]);

		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'only an administrator may declare "elevate"');

		$validate = new ReflectionMethod(objectOrMethod: SchemaMapper::class, method: 'validateMacroActions');
		$validate->invoke($mapper, $schema);
	}//end testTheSchemaMapperFactoryWiresTheDeclaredActionValidator()
}//end class
