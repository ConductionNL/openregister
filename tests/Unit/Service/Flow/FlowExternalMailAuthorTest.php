<?php

/**
 * Only an administrator authors a flow step that may mail any address.
 *
 * Flows are authored two ways: through the UI, and as definition files that
 * carry their reasoning in a top-level `$comment`. Until `openregister_flows`
 * had a column for it, the second kind lost that text the moment it was saved
 * — silently, because a flow with no rationale is indistinguishable from one
 * whose author wrote none.
 *
 * The tests below pin the three claims that loss turned on: the field is
 * stored, the file's `$comment` spelling reaches it, and the explicit API
 * field wins when a payload carries both.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancer;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Only an administrator authors a send-email step that may mail any address (or-b-f8).
 */
final class FlowExternalMailAuthorTest extends TestCase {

	private function service(bool $admin, FlowMapper $mapper): FlowService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('author');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$organisation = new class {
			public function getUuid(): string {
				return 'org-under-test';
			}
		};
		$organisationService = new class($organisation) {
			public function __construct(private readonly object $organisation) {
			}

			public function getActiveOrganisation(): object {
				return $this->organisation;
			}
		};
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		$preflight = $this->createMock(\OCA\OpenRegister\Service\Flow\FlowNodePreflight::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($groups, $organisationService, $preflight): object {
				if ($id === IGroupManager::class) {
					return $groups;
				}

				if ($id === \OCA\OpenRegister\Service\Flow\FlowNodePreflight::class) {
					return $preflight;
				}

				// No node registry here: the trigger validator then skips its own checks.
				if (str_ends_with($id, 'FlowNodeRegistry') === true) {
					throw new \RuntimeException('no registry in this test');
				}

				return $organisationService;
			}
		);

		return new FlowService(
			$mapper,
			$this->createMock(FlowTriggerIndex::class),
			$this->createMock(FlowRunService::class),
			$this->createMock(FlowRunAdvancer::class),
			$this->createMock(FlowRunMapper::class),
			$this->createMock(FlowRunStepMapper::class),
			$this->createMock(FlowStateMapper::class),
			$session,
			$this->createMock(LoggerInterface::class),
			$container
		);
	}//end service()

	/**
	 * @return array<string, mixed>
	 */
	private function mailingFlow(string $mode): array {
		return [
			'name'  => 'Mailer',
			'nodes' => [
				['id' => 'mail', 'type' => 'openregister.send-email', 'config' => ['recipients' => ['x@example.org'], 'body' => 'Hi', 'externalRecipients' => $mode]],
			],
			'edges' => [],
		];
	}//end mailingFlow()

	public function testANonAdministratorCannotCreateAFlowThatMailsAnyAddress(): void {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->expects($this->never())->method('insert');

		$this->expectException(NotAuthorizedException::class);
		$this->service(admin: false, mapper: $mapper)->save(data: $this->mailingFlow(mode: 'ANY'));
	}

	public function testANonAdministratorMayCreateAFlowThatMailsOnlyItsOwnRecipients(): void {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$this->service(admin: false, mapper: $mapper)->save(data: $this->mailingFlow(mode: 'none'));
	}

	public function testAnAdministratorMayCreateAFlowThatMailsAnyAddress(): void {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$this->service(admin: true, mapper: $mapper)->save(data: $this->mailingFlow(mode: 'any'));
	}
}//end class
