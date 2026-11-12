<?php

/**
 * Golden test: the exact action list of one object, allowed, greyed out and absent.
 *
 * Pinned on the list before its units were split: the read refusal, which
 * declared actions a caller sees and why one is unavailable, and the
 * lifecycle transitions no declared action names, in order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionCatalogue;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitions;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationEntries;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionGate;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionInputs;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionList;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ObjectActionListGoldenTest extends TestCase {

	private TransitionEngine&MockObject $transitions;

	/**
	 * Rights the caller holds, by action; absent is granted.
	 *
	 * @var array<string, bool>
	 */
	private array $rights = [];

	protected function setUp(): void {
		parent::setUp();
		$this->transitions = $this->createMock(TransitionEngine::class);
		$this->transitions->method('availableActions')->willReturn(
			[
				['action' => 'finish', 'label' => 'Finish'],
				['action' => 'reopen', 'label' => 'Reopen', 'description' => 'Open again.', 'inputs' => [['field' => 'why', 'required' => true]]],
				['action' => 'archive', 'blocked' => true],
			]
		);
	}//end setUp()

	/**
	 * The list over real definitions, gate and inputs.
	 *
	 * @return ObjectActionList The list.
	 */
	private function list(): ObjectActionList {
		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->method('hasPermission')->willReturnCallback(
			fn ($schema, string $action): bool => ($this->rights[$action] ?? true)
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10n->method('getLanguageCode')->willReturn('en');

		return new ObjectActionList(
			new ObjectActionCatalogue(new ObjectActionDefinitions($this->createMock(RegisterMapper::class)), new ObjectActionInputs(new OasRequestValidator())),
			new ObjectActionGate($permissions, $session, $l10n, $this->createMock(ObjectActionElevationEntries::class)),
			$this->transitions,
			$l10n
		);
	}//end list()

	/**
	 * A schema with a precondition action, a claim, a declared transition, an unavailable one and a secret one.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schema->setAuthorization(['claim' => [['group' => 'behandelaars']]]);
		$schema->setConfiguration(
			[
				'x-openregister-action' => [
					'publish' => [
						'name' => 'Publish',
						'when' => ['==' => [['var' => 'object.status'], 'decided']],
						'whenMessage' => 'Only decided.',
						'steps' => [['type' => 'openregister.set-fields']],
					],
					'claim' => ['name' => 'Claim', 'selfAssign' => ['field' => 'handler']],
					'close' => ['name' => 'Close', 'transition' => 'finish'],
					'suspend' => ['name' => 'Suspend', 'transition' => 'suspend', 'inputs' => ['type' => 'object']],
					'secret' => ['name' => 'Secret', 'steps' => [['type' => 'openregister.set-fields']]],
					'sendMail' => ['name' => 'Send mail'],
				],
			]
		);
		return $schema;
	}//end schema()

	/**
	 * A case being handled.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('case-1');
		$object->setOwner('bob');
		$object->setObject(['status' => 'open', 'handler' => 'carl']);
		return $object;
	}//end object()

	/**
	 * The fields of a declared entry.
	 *
	 * @param string      $key    The key.
	 * @param string      $kind   The kind.
	 * @param string      $label  The label.
	 * @param array|null  $inputs The inputs.
	 * @param bool        $avail  Whether available.
	 * @param string|null $reason The reason, when not.
	 *
	 * @return array The entry.
	 */
	private function declared(string $key, string $kind, string $label, ?array $inputs, bool $avail, ?string $reason): array {
		$entry = [
			'key' => $key, 'kind' => $kind, 'label' => $label, 'description' => null, 'icon' => null,
			'surfaces' => ['header', 'row'], 'inputs' => $inputs, 'confirm' => null, 'mode' => 'sync', 'next' => 'stay',
			'available' => $avail,
		];
		if ($reason !== null) {
			$entry['reason'] = $reason;
		}

		return $entry;
	}//end declared()

	public function testTheListOfOneObjectEntryForEntry(): void {
		$this->rights = ['secret' => false];
		$entries      = $this->list()->forObject($this->object(), $this->schema());

		$closeInputs = $entries[2]['inputs'];
		$entries[2]['inputs'] = null;
		$this->assertSame(
			[
				$this->declared('publish', 'steps', 'Publish', null, false, 'Only decided.'),
				$this->declared('claim', 'selfAssign', 'Claim', null, false, 'This object is already assigned.'),
				$this->declared('close', 'transition', 'Close', null, true, null),
				$this->declared('suspend', 'transition', 'Suspend', ['type' => 'object'], false, 'This step is not available from the current status.'),
			],
			array_slice($entries, 0, 4)
		);
		$this->assertEquals(['type' => 'object', 'properties' => (object)[]], $closeInputs);
		$this->assertEquals(
			[
				'key' => 'reopen', 'kind' => 'transition', 'label' => 'Reopen', 'description' => 'Open again.', 'icon' => null,
				'surfaces' => ['header', 'row'], 'available' => true,
				'inputs' => ['type' => 'object', 'properties' => (object)['why' => (object)[]], 'required' => ['why']],
				'confirm' => null, 'mode' => 'sync', 'next' => 'stay',
			],
			$entries[4]
		);
		$this->assertSame(['archive', 'archive', false, 'This step is blocked by a condition on the object.'], [$entries[5]['key'], $entries[5]['label'], $entries[5]['available'], $entries[5]['reason']]);
		$this->assertCount(6, $entries);
	}//end testTheListOfOneObjectEntryForEntry()

	public function testADeclaredTransitionWithoutInputsTakesTheMovesInputs(): void {
		$this->transitions = $this->createMock(TransitionEngine::class);
		$this->transitions->method('availableActions')->willReturn([['action' => 'finish', 'inputs' => [['field' => 'note']]]]);

		$entries = $this->list()->forObject($this->object(), $this->schema());
		$close   = array_column($entries, null, 'key')['close'];

		$this->assertEquals(['type' => 'object', 'properties' => (object)['note' => (object)[]]], $close['inputs']);
		$this->assertTrue($close['available']);
	}//end testADeclaredTransitionWithoutInputsTakesTheMovesInputs()

	public function testReadIsRefusedBeforeAnythingIsListed(): void {
		$this->rights = ['read' => false];
		$this->transitions = $this->createMock(TransitionEngine::class);
		$this->transitions->expects($this->never())->method('availableActions');

		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('You do not have permission to read this object.');
		$this->list()->forObject($this->object(), $this->schema());
	}//end testReadIsRefusedBeforeAnythingIsListed()

	public function testCanRunAsksTheRightTheClaimAndThePrecondition(): void {
		$this->rights = ['secret' => false];
		$object       = $this->object();
		$object->setObject(['status' => 'decided']);

		$this->assertSame(
			['publish' => true, 'claim' => true, 'close' => true, 'suspend' => true, 'secret' => false],
			$this->list()->canRun($object, $this->schema())
		);
		$this->assertSame(
			['publish' => false, 'claim' => false, 'close' => true, 'suspend' => true, 'secret' => false],
			$this->list()->canRun($this->object(), $this->schema())
		);
	}//end testCanRunAsksTheRightTheClaimAndThePrecondition()
}//end class
