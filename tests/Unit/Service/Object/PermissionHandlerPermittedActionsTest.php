<?php

/**
 * The record answers what its reader may do with it.
 *
 * Returning the permitted actions with the object costs one resolution that has
 * already happened; not returning them costs every client a guess, and the user
 * finds out by clicking into a 403 (design D-9). So the list has to be the
 * verbs this caller actually holds on this row, deny included, rather than the
 * verbs the schema mentions.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Task 7.1: the actions a record carries.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 */
class PermissionHandlerPermittedActionsTest extends TestCase {

	/**
	 * Hands out a distinct schema id per schema built in a case.
	 *
	 * @var integer
	 */
	private int $schemaCounter = 0;

	/**
	 * Reset the counter before every case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->schemaCounter = 700;
	}//end setUp()

	/**
	 * A handler for one caller, enforcing.
	 *
	 * @param string   $userId The caller.
	 * @param string[] $groups The caller's group IDs.
	 *
	 * @return PermissionHandler The handler under test.
	 */
	private function handlerFor(string $userId, array $groups): PermissionHandler {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($userId);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);
		$appConfig->method('getValueString')->willReturn(DenyEnforcementMode::MODE_ENFORCING);

		return new PermissionHandler(
			$session,
			$userManager,
			$groupManager,
			$this->createMock(originalClassName: SchemaMapper::class),
			$this->createMock(originalClassName: MagicMapper::class),
			$this->createMock(originalClassName: ConditionMatcher::class),
			$appConfig,
			new NullLogger(),
			$this->createMock(originalClassName: ContainerInterface::class),
			null,
			null,
			null,
			new DenyResolver(new DenyEntryMatcher()),
			new DenyEnforcementMode($appConfig, new NullLogger())
		);
	}//end handlerFor()

	/**
	 * A schema naming every row verb, so nothing is answered by default-open.
	 *
	 * @param array $overrides Verb to the principals holding it.
	 *
	 * @return Schema The schema.
	 */
	private function schemaGranting(array $overrides): Schema {
		$this->schemaCounter++;

		$block = array_merge(
			[
				'read' => ['nobody'],
				'update' => ['nobody'],
				'delete' => ['nobody'],
				'destroy' => ['nobody'],
			],
			$overrides
		);

		$schema = new Schema();
		$schema->setId($this->schemaCounter);
		$schema->setTitle('Zaak');
		$schema->setAuthorization($block);

		return $schema;
	}//end schemaGranting()

	/**
	 * One row, owned by somebody else so the owner bypass answers nothing.
	 *
	 * @param array|null $authorization The row's own block.
	 *
	 * @return ObjectEntity The row.
	 */
	private function row(?array $authorization = null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('77777777-8888-9999-0000-000000000001');
		$object->setOwner('bea');
		$object->setObject(['title' => 'een zaak']);
		$object->setAuthorization($authorization);

		return $object;
	}//end row()

	/**
	 * 🔴 The record lists what the caller holds and not what it does not.
	 *
	 * @return void
	 */
	public function testTheRecordListsOnlyTheVerbsTheCallerHolds(): void {
		$handler = $this->handlerFor(userId: 'ana', groups: ['behandelaars']);

		$actions = $handler->permittedActionsFor(
			schema: $this->schemaGranting(
				[
					'read' => ['behandelaars'],
					'update' => ['behandelaars'],
				]
			),
			object: $this->row(),
			userId: 'ana'
		);

		$this->assertSame(['read', 'update'], $actions);
	}//end testTheRecordListsOnlyTheVerbsTheCallerHolds()

	/**
	 * 🔴 A deny on this one row removes the action from the record.
	 *
	 * @return void
	 */
	public function testADenyOnTheRowRemovesTheActionFromTheRecord(): void {
		$handler = $this->handlerFor(userId: 'ana', groups: ['behandelaars']);

		$actions = $handler->permittedActionsFor(
			schema: $this->schemaGranting(
				[
					'read' => ['behandelaars'],
					'update' => ['behandelaars'],
				]
			),
			object: $this->row(['deny' => ['update' => ['behandelaars']]]),
			userId: 'ana'
		);

		$this->assertSame(['read'], $actions);
	}//end testADenyOnTheRowRemovesTheActionFromTheRecord()

	/**
	 * The schema verbs are not answers about a row, so they are not in the list.
	 *
	 * A record carrying `list` or `create` invites a client to read them as
	 * rights ON the row, which is a different question with a different answer.
	 *
	 * @return void
	 */
	public function testTheSchemaVerbsAreNotReportedOnARecord(): void {
		$handler = $this->handlerFor(userId: 'ana', groups: ['behandelaars']);

		$actions = $handler->permittedActionsFor(
			schema: $this->schemaGranting(
				[
					'read' => ['behandelaars'],
					'create' => ['behandelaars'],
					'list' => ['behandelaars'],
					'manage' => ['behandelaars'],
				]
			),
			object: $this->row(),
			userId: 'ana'
		);

		$this->assertSame(['read'], $actions);
	}//end testTheSchemaVerbsAreNotReportedOnARecord()

	/**
	 * A caller who may do nothing gets an empty list, not a missing one.
	 *
	 * The floor for every case above: without it, an empty answer and a broken
	 * resolution would look the same.
	 *
	 * @return void
	 */
	public function testACallerWithNoRightsGetsAnEmptyList(): void {
		$handler = $this->handlerFor(userId: 'ana', groups: ['gasten']);

		$this->assertSame(
			[],
			$handler->permittedActionsFor(
				schema: $this->schemaGranting([]),
				object: $this->row(),
				userId: 'ana'
			)
		);
	}//end testACallerWithNoRightsGetsAnEmptyList()
}//end class
