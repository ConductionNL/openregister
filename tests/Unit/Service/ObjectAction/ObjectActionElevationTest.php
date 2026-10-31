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
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
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
	private function handler(): PermissionHandler {
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

		return new PermissionHandler(
			$session,
			$users,
			$groups,
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(ConditionMatcher::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IEventDispatcher::class)
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
			fn (): bool => $handler->hasPermission(schema: $this->schema(), action: 'create', userId: 'anna')
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
				ObjectActionElevationScope::allows(schemaId: 9, registerId: null, verb: 'create');
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
