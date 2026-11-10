<?php

/**
 * Golden test: the allowed and refused outcomes of ObjectActionGate's right
 * and precondition checks, and what the permission handler is asked.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationEntries;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionGate;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionInvocation;
use OCA\OpenRegister\Service\ObjectAction\RightCheck;
use OCA\OpenRegister\Tests\Unit\Service\Object\PermissionAskArgs;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Pins may(), mayRun() and whenHolds() of the gate for user `anna`.
 */
class ObjectActionGateRightGoldenTest extends TestCase {

	/**
	 * Every hasPermission() call the handler received.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * A gate whose permission handler answers $answer and records what it is asked.
	 *
	 * @param bool $answer The handler's answer.
	 *
	 * @return ObjectActionGate The gate.
	 */
	private function gate(bool $answer): ObjectActionGate {
		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->method('hasPermission')->willReturnCallback(PermissionAskArgs::spread(
			function (...$args) use ($answer): bool {
				$this->asked[] = $args;
				return $answer;
			}
		));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ObjectActionGate(
			permissions: $permissions,
			userSession: $session,
			l10n: $this->createMock(IL10N::class),
			elevation: $this->createMock(ObjectActionElevationEntries::class)
		);
	}//end gate()

	/**
	 * An object owned by `bob` in owning group `team`.
	 *
	 * @return ObjectEntity The object.
	 */
	private static function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setOwner('bob');
		$object->setAuthorization(['ownerGroup' => 'team', 'read' => ['team']]);
		$object->setObject(['status' => 'open', 'assignee' => null]);

		return $object;
	}//end object()

	/**
	 * Whether the gate lets `anna` run the definition with right $right.
	 *
	 * @param ObjectActionGate       $gate       The gate.
	 * @param Schema                 $schema     The schema.
	 * @param ObjectActionDefinition $definition The action.
	 * @param ObjectEntity|null      $object     The object.
	 *
	 * @return bool The answer.
	 */
	private function mayRun(ObjectActionGate $gate, Schema $schema, ObjectActionDefinition $definition, ?ObjectEntity $object): bool {
		return $gate->mayRun(check: new RightCheck(schema: $schema, right: 'approve', object: $object), definition: $definition);
	}//end mayRun()

	/**
	 * Whether the definition's `when` holds.
	 *
	 * @param ObjectActionDefinition $definition The action.
	 * @param array<string, mixed>   $inputs     The inputs.
	 *
	 * @return bool The answer.
	 */
	private function whenHolds(ObjectActionDefinition $definition, array $inputs): bool {
		return $this->gate(answer: true)->whenHolds(invocation: new ObjectActionInvocation(definition: $definition, inputs: $inputs), object: self::object());
	}//end whenHolds()

	/**
	 * may() passes the right, the uid, the owner, the _rbac flag and the object, and returns the answer.
	 *
	 * @return void
	 */
	public function testMayAsksTheHandlerAndReturnsItsAnswer(): void {
		$schema = new Schema();
		$object = self::object();

		$this->assertTrue($this->gate(answer: true)->holds(new RightCheck($schema, 'read', $object)));
		$this->assertFalse($this->gate(answer: false)->holds(new RightCheck($schema, 'read', $object)));
		$this->assertFalse($this->gate(answer: false)->holds(new RightCheck($schema, 'read', null)));
		$this->assertSame([$schema, 'read', 'anna', 'bob', true, $object], array_slice($this->asked[0], 0, 6));
		$this->assertSame([$schema, 'read', 'anna', null, true, null], array_slice($this->asked[2], 0, 6));
	}//end testMayAsksTheHandlerAndReturnsItsAnswer()

	/**
	 * A non-self-assign action is decided by the handler on the given right.
	 *
	 * @return void
	 */
	public function testPlainActionFollowsTheHandler(): void {
		$schema     = new Schema();
		$definition = ObjectActionDefinition::fromDeclaration(key: 'approve', definition: ['transition' => 'approve']);

		$this->assertTrue($this->mayRun(gate: $this->gate(answer: true), schema: $schema, definition: $definition, object: self::object()));
		$this->assertFalse($this->mayRun(gate: $this->gate(answer: false), schema: $schema, definition: $definition, object: self::object()));
		$this->assertFalse($this->mayRun(gate: $this->gate(answer: false), schema: $schema, definition: $definition, object: null));
		$this->assertSame(['approve', 'anna', 'bob', true], array_slice($this->asked[0], 1, 4));
		$this->assertNull($this->asked[2][5]);
	}//end testPlainActionFollowsTheHandler()

	/**
	 * A self-assign the schema does not name is refused without asking the handler.
	 *
	 * @return void
	 */
	public function testUnnamedSelfAssignIsRefused(): void {
		$schema     = new Schema();
		$schema->setAuthorization(['read' => ['team']]);
		$definition = ObjectActionDefinition::fromDeclaration(key: 'take', definition: ['selfAssign' => ['field' => 'assignee']]);

		$this->assertFalse($this->mayRun(gate: $this->gate(answer: true), schema: $schema, definition: $definition, object: self::object()));
		$this->assertSame([], $this->asked);
	}//end testUnnamedSelfAssignIsRefused()

	/**
	 * A named self-assign asks under its own key, without the owner and owning group.
	 *
	 * @return void
	 */
	public function testNamedSelfAssignIsAskedWithoutOwnership(): void {
		$schema     = new Schema();
		$schema->setAuthorization(['take' => ['team']]);
		$definition = ObjectActionDefinition::fromDeclaration(key: 'take', definition: ['selfAssign' => ['field' => 'assignee']]);
		$object     = self::object();

		$this->assertTrue($this->mayRun(gate: $this->gate(answer: true), schema: $schema, definition: $definition, object: $object));
		$this->assertFalse($this->mayRun(gate: $this->gate(answer: false), schema: $schema, definition: $definition, object: $object));
		$this->assertSame(['take', 'anna', null, true], array_slice($this->asked[0], 1, 4));
		$probe = $this->asked[0][5];
		$this->assertNotSame($object, $probe);
		$this->assertNull($probe->getOwner());
		$this->assertSame(['read' => ['team']], $probe->getAuthorization());
		$this->assertSame('bob', $object->getOwner());
	}//end testNamedSelfAssignIsAskedWithoutOwnership()

	/**
	 * `when` reads the object, the user and the inputs; no `when` always holds.
	 *
	 * @return void
	 */
	public function testWhenHoldsReadsObjectUserAndInputs(): void {
		$always = ObjectActionDefinition::fromDeclaration(key: 'a', definition: ['transition' => 'a']);
		$when   = ObjectActionDefinition::fromDeclaration(
			key: 'b',
			definition: ['transition' => 'b', 'when' => ['and' => [['==' => [['var' => 'object.status'], 'open']], ['==' => [['var' => 'user.id'], 'anna']], ['==' => [['var' => 'inputs.ok'], true]]]]]
		);

		$this->assertTrue($this->whenHolds(definition: $always, inputs: []));
		$this->assertTrue($this->whenHolds(definition: $when, inputs: ['ok' => true]));
		$this->assertFalse($this->whenHolds(definition: $when, inputs: ['ok' => false]));
		$this->assertFalse($this->whenHolds(definition: $when, inputs: []));
	}//end testWhenHoldsReadsObjectUserAndInputs()
}//end class
