<?php

/**
 * Unit test for ActionRightsController: administrators only, and what it refuses.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\ActionRightsController;
use OCA\OpenRegister\Controller\Support\AdministratorOnlyGate;
use OCA\OpenRegister\Service\ActionRightsCatalogueInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeRightsGuardInterface;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * 200 for an administrator, 403 for anyone else, 400 for an unknown action.
 *
 * @covers \OCA\OpenRegister\Controller\ActionRightsController
 * @uses   \OCA\OpenRegister\Controller\Support\AdministratorOnlyGate
 */
final class ActionRightsControllerTest extends TestCase {

	/**
	 * One listed right, as the catalogue answers it.
	 *
	 * @var array<string, mixed>
	 */
	private const LISTED = [
		'action' => 'flow.node.send-email',
		'app' => 'openregister',
		'description' => 'Put a step that sends e-mail in a flow.',
		'nodeTypes' => ['openregister.send-email'],
		'groups' => ['admin'],
	];

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The catalogue.
	 *
	 * @var ActionRightsCatalogueInterface&MockObject
	 */
	private ActionRightsCatalogueInterface&MockObject $catalogue;

	/**
	 * The node map.
	 *
	 * @var FlowNodeRightsGuardInterface&MockObject
	 */
	private FlowNodeRightsGuardInterface&MockObject $nodeRights;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * Build the collaborators with a signed-in caller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->catalogue = $this->createMock(ActionRightsCatalogueInterface::class);
		$this->nodeRights = $this->createMock(FlowNodeRightsGuardInterface::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->groups = $this->createMock(IGroupManager::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('someone');
		$this->session->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * The controller, for an administrator or not.
	 *
	 * @param bool $admin Whether the caller is an administrator.
	 *
	 * @return ActionRightsController
	 */
	private function controller(bool $admin): ActionRightsController {
		$this->groups->method('isAdmin')->willReturn($admin);

		return new ActionRightsController(
			request: $this->request,
			gate: new AdministratorOnlyGate(userSession: $this->session, groupManager: $this->groups),
			catalogue: $this->catalogue,
			nodeRights: $this->nodeRights
		);
	}//end controller()

	/**
	 * An administrator reads every right with its groups and the node map.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function testAnAdministratorReadsTheRightsAndTheNodeMap(): void {
		$this->catalogue->method('actionsWithGroups')->willReturn([self::LISTED]);
		$this->nodeRights->method('adminMap')->willReturn(['openconnector.source-call' => 'flow.node.source-call']);

		$response = $this->controller(admin: true)->index();
		$data = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([self::LISTED], $data['actions']);
		$this->assertSame(['openconnector.source-call' => 'flow.node.source-call'], (array)$data['nodeRights']);
	}//end testAnAdministratorReadsTheRightsAndTheNodeMap()

	/**
	 * A non-administrator is refused 403 and reads nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function testANonAdministratorIsRefused(): void {
		$this->catalogue->expects($this->never())->method('actionsWithGroups');
		$this->catalogue->expects($this->never())->method('setGroups');
		$this->nodeRights->expects($this->never())->method('setAdminMap');

		$controller = $this->controller(admin: false);

		$this->assertSame(403, $controller->index()->getStatus());
		$this->assertSame(403, $controller->update()->getStatus());
	}//end testANonAdministratorIsRefused()

	/**
	 * An administrator grants a right to a group, and the node map is written first.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function testAnAdministratorGrantsARightToAGroup(): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => match ($key) {
				'groups' => ['flow.node.send-email' => ['builders']],
				'nodeRights' => ['openconnector.source-call' => 'flow.node.source-call'],
				default => $default,
			}
		);
		$this->nodeRights->expects($this->once())->method('setAdminMap')->with(['openconnector.source-call' => 'flow.node.source-call']);
		$this->catalogue->expects($this->once())->method('setGroups')->with(['flow.node.send-email' => ['builders']]);
		$this->catalogue->method('actionsWithGroups')->willReturn([self::LISTED]);
		$this->nodeRights->method('adminMap')->willReturn([]);

		$this->assertSame(200, $this->controller(admin: true)->update()->getStatus());
	}//end testAnAdministratorGrantsARightToAGroup()

	/**
	 * An unknown action answers 400 naming it, and the node map is put back.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function testAnUnknownActionAnswers400AndRestoresTheMap(): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => match ($key) {
				'groups' => ['flow.node.invented' => ['builders']],
				'nodeRights' => ['openconnector.source-call' => 'flow.node.source-call'],
				default => $default,
			}
		);
		$this->nodeRights->method('adminMap')->willReturn(['openregister.send-email' => null]);
		$this->catalogue->method('setGroups')->willThrowException(new InvalidArgumentException('Unknown action right: flow.node.invented.'));

		$written = [];
		$this->nodeRights->method('setAdminMap')->willReturnCallback(
			static function (array $map) use (&$written): void {
				$written[] = $map;
			}
		);

		$response = $this->controller(admin: true)->update();

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('flow.node.invented', (string)$response->getData()['error']);
		// Written, then put back as it was: a refused request changes nothing.
		$this->assertSame(
			[['openconnector.source-call' => 'flow.node.source-call'], ['openregister.send-email' => null]],
			$written
		);
	}//end testAnUnknownActionAnswers400AndRestoresTheMap()

	/**
	 * A node map naming a right outside `flow.node.` is refused 400, and no groups are written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function testANodeMapNamingAnotherAppsActionIsRefused(): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => match ($key) {
				'groups' => [],
				// `flow.create` is a right, but not a node right: a node map
				// that could name it would let a step shadow the flow verbs.
				'nodeRights' => ['openregister.send-email' => 'flow.create'],
				default => $default,
			}
		);
		$this->nodeRights->method('adminMap')->willReturn([]);
		$this->catalogue->expects($this->never())->method('setGroups');

		$response = $this->controller(admin: true)->update();

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('flow.node.', (string)$response->getData()['error']);
	}//end testANodeMapNamingAnotherAppsActionIsRefused()

	/**
	 * A `groups` that is not an object answers 400 and writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function testAMalformedGroupsBodyAnswers400(): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'groups' ? 'all of them' : $default)
		);
		$this->catalogue->expects($this->never())->method('setGroups');
		$this->nodeRights->expects($this->never())->method('setAdminMap');

		$this->assertSame(400, $this->controller(admin: true)->update()->getStatus());
	}//end testAMalformedGroupsBodyAnswers400()
}//end class
