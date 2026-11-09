<?php

/**
 * Unit tests for scoped elevation: only listed writes, only during the run, always attributed.
 *
 * The permission handler is the real one with a refusing authorization
 * block, so "the person cannot" is the handler's own verdict, not a mock's.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationEntries;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

class ObjectActionElevationTest extends TestCase {

	/**
	 * The publication schema in register 4, where only `redactie` may create.
	 *
	 * @param int $id The schema id.
	 *
	 * @return Schema
	 */
	private function schema(int $id = 9): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('publication');
		$schema->setAuthorization(['create' => ['redactie'], 'update' => ['redactie']]);
		return $schema;
	}//end schema()

	/**
	 * The real handler, with a handler (`anna`, group `behandelaars`) signed in.
	 *
	 * @return PermissionHandler
	 */
	private function handler(bool $enforceDeny = false): PermissionHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['behandelaars']);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);
		$appConfig->method('getValueString')->willReturn(DenyEnforcementMode::MODE_ENFORCING);

		return \OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory::create(
			$session,
			$users,
			$groups,
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(ConditionMatcher::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IEventDispatcher::class),
			null,
			null,
			new DenyResolver(new DenyEntryMatcher()),
			new DenyEnforcementMode($appConfig, new NullLogger())
		);
	}//end handler()

	/**
	 * The resolved entry: create on schema 9 in register 4.
	 *
	 * @return array
	 */
	private function entries(): array {
		return [['register' => '4', 'schema' => '9', 'verbs' => ['create']]];
	}//end entries()

	public function testAHandlerPublishesIntoARegisterTheyCannotWrite(): void {
		$handler = $this->handler();
		$this->assertFalse($handler->hasPermission(schema: $this->schema(), action: 'create', userId: 'anna'), 'precondition: anna may not create');

		$granted = ObjectActionElevationScope::run(
			'publish',
			'anna',
			$this->entries(),
			fn (): bool => $handler->hasPermission(schema: $this->schema(), action: 'create', userId: 'anna', register: '4')
		);

		$this->assertTrue($granted);
	}//end testAHandlerPublishesIntoARegisterTheyCannotWrite()

	public function testAnUnlistedWriteIsNotElevated(): void {
		$handler = $this->handler();
		$object = new ObjectEntity();
		$object->setUuid('p-1');
		$object->setRegister('4');

		[$update, $otherSchema, $otherRegister] = ObjectActionElevationScope::run(
			'publish',
			'anna',
			$this->entries(),
			function () use ($handler, $object): array {
				$elsewhere = new ObjectEntity();
				$elsewhere->setUuid('p-2');
				$elsewhere->setRegister('5');

				return [
					$handler->hasPermission(schema: $this->schema(), action: 'update', userId: 'anna', object: $object),
					$handler->hasPermission(schema: $this->schema(10), action: 'create', userId: 'anna'),
					$handler->hasPermission(schema: $this->schema(), action: 'create', userId: 'anna', object: $elsewhere),
				];
			}
		);

		$this->assertFalse($update, 'a verb the entry does not list was elevated');
		$this->assertFalse($otherSchema, 'a schema the entry does not list was elevated');
		$this->assertFalse($otherRegister, 'a register the entry does not list was elevated');
	}//end testAnUnlistedWriteIsNotElevated()

	public function testACreateThatNamesNoRegisterIsNotElevated(): void {
		$handler = $this->handler();

		$granted = ObjectActionElevationScope::run(
			'publish',
			'anna',
			$this->entries(),
			fn (): bool => $handler->hasPermission(schema: $this->schema(), action: 'create', userId: 'anna')
		);

		$this->assertFalse($granted, 'an elevation for register 4 opened a create that names no register');
	}//end testACreateThatNamesNoRegisterIsNotElevated()

	public function testACreateInAnotherRegisterIsNotElevated(): void {
		$handler = $this->handler();

		$granted = ObjectActionElevationScope::run(
			'publish',
			'anna',
			$this->entries(),
			fn (): bool => $handler->hasPermission(schema: $this->schema(), action: 'create', userId: 'anna', register: '5')
		);

		$this->assertFalse($granted, 'an elevation for register 4 opened a create in register 5');
	}//end testACreateInAnotherRegisterIsNotElevated()

	public function testAnExplicitDenyStaysDenied(): void {
		$handler = $this->handler();
		$schema = $this->schema();
		$schema->setAuthorization(['create' => ['redactie'], 'deny' => ['create' => ['behandelaars']]]);

		$granted = ObjectActionElevationScope::run(
			'publish',
			'anna',
			$this->entries(),
			fn (): bool => $handler->hasPermission(schema: $schema, action: 'create', userId: 'anna', register: '4')
		);

		$this->assertFalse($granted, 'an elevation overrode an explicit deny');
	}//end testAnExplicitDenyStaysDenied()

	/**
	 * Grants that admit everyone, or every signed-in user.
	 *
	 * @return array<string, array{0: list<mixed>}>
	 */
	public static function broadcastGrants(): array {
		return [
			'public' => [['public']],
			'authenticated' => [['authenticated']],
			'empty group' => [['']],
			'public as a rule' => [[['group' => 'public']]],
			'authenticated beside a group' => [['redactie', 'authenticated']],
		];
	}//end broadcastGrants()

	/**
	 * @dataProvider broadcastGrants
	 *
	 * @param list<mixed> $grant The rules granting the action.
	 */
	public function testAnElevatedActionGrantedToEveryoneIsRefusedAtSave(array $grant): void {
		$refusals = ObjectActionDefinition::refusals(
			[
				'x-openregister-action' => [
					'publish' => [
						'name' => 'Publish',
						'description' => 'Publish it.',
						'steps' => [['type' => 'openregister.set-fields']],
						'elevate' => [['register' => 'pub', 'schema' => 'publication', 'verbs' => ['create']]],
					],
				],
			],
			['publish' => $grant]
		);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('open to everyone', $refusals[0]);
	}//end testAnElevatedActionGrantedToEveryoneIsRefusedAtSave()

	public function testAnElevatedActionGrantedToAGroupIsAccepted(): void {
		$refusals = ObjectActionDefinition::refusals(
			[
				'x-openregister-action' => [
					'publish' => [
						'name' => 'Publish',
						'description' => 'Publish it.',
						'steps' => [['type' => 'openregister.set-fields']],
						'elevate' => [['register' => 'pub', 'schema' => 'publication', 'verbs' => ['create']]],
					],
				],
			],
			['publish' => [['group' => 'redactie']]]
		);

		$this->assertSame([], $refusals);
	}//end testAnElevatedActionGrantedToAGroupIsAccepted()

	public function testTheElevationEndsWithTheRunEvenWhenItThrows(): void {
		try {
			ObjectActionElevationScope::run('publish', 'anna', $this->entries(), static fn () => throw new RuntimeException('step failed'));
		} catch (RuntimeException) {
			$this->addToAssertionCount(1);
		}

		$this->assertFalse(ObjectActionElevationScope::allows(schemaId: 9, registerId: '4', verb: 'create'));
		$this->assertFalse($this->handler()->hasPermission(schema: $this->schema(), action: 'create', userId: 'anna'));
	}//end testTheElevationEndsWithTheRunEvenWhenItThrows()

	public function testAnElevatedWriteIsAttributedAndAnOrdinaryOneIsNot(): void {
		$mapper = (new \ReflectionClass(AuditTrailMapper::class))->newInstanceWithoutConstructor();
		$apply = new ReflectionMethod(AuditTrailMapper::class, 'applyElevation');

		$elevated = new AuditTrail();
		$elevated->setSchema(9);
		$elevated->setAction('create');
		$elevated->setChanged(['title' => 'Besluit']);
		$ordinary = new AuditTrail();
		$ordinary->setSchema(9);
		$ordinary->setAction('update');

		ObjectActionElevationScope::run(
			'publish',
			'anna',
			$this->entries(),
			function () use ($mapper, $apply, $elevated, $ordinary): void {
				ObjectActionElevationScope::allows(schemaId: 9, registerId: '4', verb: 'create');
				$apply->invoke($mapper, $elevated);
				$apply->invoke($mapper, $ordinary);
			}
		);

		$this->assertSame(
			['title' => 'Besluit', 'elevation' => ['elevatedBy' => 'publish', 'triggeredBy' => 'anna', 'runAs' => 'system']],
			$elevated->getChanged()
		);
		$this->assertArrayNotHasKey('elevation', $ordinary->getChanged());
	}//end testAnElevatedWriteIsAttributedAndAnOrdinaryOneIsNot()

	public function testEntriesResolveToIdsAndAnUnresolvableOneIsDropped(): void {
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			static function ($id) {
				if ($id !== 'publication') {
					throw new RuntimeException('gone');
				}

				$register = new Register();
				$register->setId(4);
				return $register;
			}
		);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($this->schema());

		$resolved = (new ObjectActionElevationEntries($registers, $schemas))->resolve(
			[
				['register' => 'publication', 'schema' => 'publication', 'verbs' => ['create']],
				['register' => 'missing', 'schema' => 'publication', 'verbs' => ['create']],
			]
		);

		$this->assertSame([['register' => '4', 'schema' => '9', 'verbs' => ['create']]], $resolved);
	}//end testEntriesResolveToIdsAndAnUnresolvableOneIsDropped()

	public function testAnAsyncElevatedActionIsRefusedAtSave(): void {
		$refusals = ObjectActionDefinition::refusals(
			[
				'x-openregister-action' => [
					'publish' => [
						'mode' => 'async',
						'steps' => [['type' => 'x.y']],
						'elevate' => [['register' => 'pub', 'schema' => 'publication', 'verbs' => ['create']]],
					],
				],
			],
			['publish' => [['group' => 'redactie']]]
		);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('runs sync', $refusals[0]);
	}//end testAnAsyncElevatedActionIsRefusedAtSave()
}//end class
