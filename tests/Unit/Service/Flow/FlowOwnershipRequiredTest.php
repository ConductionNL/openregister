<?php

/**
 * A flow that belongs to nobody must not be created.
 *
 * `Flow::belongsTo()` is fail-closed on BOTH sides: a flow with an empty
 * organisation matches no caller, and a caller with no organisation matches no
 * flow. So a flow saved without one is not merely unscoped — it is permanently
 * unreachable. It never appears in index(), find() refuses it, and therefore it
 * can never be edited, enabled, deleted through the API, or run.
 *
 * `save()` used to stamp those nulls and return the entity, so the caller got a
 * success it could not distinguish from a real one. index() and count() already
 * refused a null organisation; these tests hold the same line on the write side,
 * where getting it wrong is permanent rather than a blank list.
 *
 * It is also the invariant the run side leans on: a scheduled or event-fired
 * run has no session and takes its identity from the flow, so a flow without
 * one makes every run it fires unattributable.
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
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancer;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Ownership is a precondition of creating a flow, not a field on it.
 */
final class FlowOwnershipRequiredTest extends TestCase {

	/**
	 * A session, with or without somebody in it.
	 *
	 * @param string|null $uid The signed-in uid, or null for an anonymous caller.
	 *
	 * @return IUserSession The session double.
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);

		if ($uid === null) {
			$session->method('getUser')->willReturn(null);

			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * A container resolving an organisation service, or one resolving nothing.
	 *
	 * @param string|null $organisation The active organisation uuid, or null for none.
	 *
	 * @return ContainerInterface The container double.
	 */
	private function container(?string $organisation): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);

		if ($organisation === null) {
			$container->method('get')->willThrowException(new \RuntimeException('no organisation service'));

			return $container;
		}

		$service = new class($organisation) {

			/**
			 * @param string $uuid The organisation uuid.
			 */
			public function __construct(private readonly string $uuid) {
			}

			/**
			 * The active organisation.
			 *
			 * @return object The organisation stub.
			 */
			public function getActiveOrganisation(): object {
				return new class($this->uuid) {

					/**
					 * @param string $uuid The organisation uuid.
					 */
					public function __construct(private readonly string $uuid) {
					}

					/**
					 * The uuid.
					 *
					 * @return string The uuid.
					 */
					public function getUuid(): string {
						return $this->uuid;
					}

				};
			}

		};

		$container->method('get')->willReturn($service);

		return $container;
	}//end container()

	/**
	 * Build the service, recording anything the mapper is asked to insert.
	 *
	 * @param string|null $uid          The signed-in uid, or null.
	 * @param string|null $organisation The active organisation, or null.
	 * @param array<int, Flow> $inserted Collects every insert, by reference.
	 *
	 * @return FlowService The service under test.
	 */
	private function service(?string $uid, ?string $organisation, array &$inserted): FlowService {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('insert')->willReturnCallback(
			function (Flow $flow) use (&$inserted): Flow {
				$inserted[] = $flow;

				return $flow;
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
			$this->session($uid),
			$this->createMock(LoggerInterface::class),
			$this->container($organisation)
		);
	}//end service()

	/**
	 * An anonymous caller cannot create a flow.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$inserted = [];

		$this->expectException(DoesNotExistException::class);

		try {
			$this->service(null, 'org-a', $inserted)->save(data: ['name' => 'nightly']);
		} finally {
			$this->assertSame([], $inserted, 'nothing may reach the mapper');
		}
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * A signed-in caller with no resolvable organisation cannot either.
	 *
	 * This is the repair-step and occ case: there is code running, but no
	 * tenant it can honestly claim to act for.
	 *
	 * @return void
	 */
	public function testACallerWithoutAnOrganisationIsRefused(): void {
		$inserted = [];

		$this->expectException(DoesNotExistException::class);

		try {
			$this->service('author', null, $inserted)->save(data: ['name' => 'nightly']);
		} finally {
			$this->assertSame([], $inserted, 'nothing may reach the mapper');
		}
	}//end testACallerWithoutAnOrganisationIsRefused()

	/**
	 * With both present the flow is created and carries them.
	 *
	 * The counterpart assertion: the guard must not have made creation
	 * impossible, and the two values must be the SERVER's, not the payload's.
	 *
	 * @return void
	 */
	public function testAFullyIdentifiedCallerCreatesAnOwnedFlow(): void {
		$inserted = [];

		$this->service('author', 'org-a', $inserted)->save(
			data: [
				'name' => 'nightly',
				// Both are on the allowlist's deny side; a payload must never
				// be able to mint a flow that runs as somebody else.
				'owner' => 'somebody-else',
				'organisation' => 'org-attacker',
			]
		);

		$this->assertCount(1, $inserted);
		$this->assertSame('author', $inserted[0]->getOwner());
		$this->assertSame('org-a', $inserted[0]->getOrganisation());
	}//end testAFullyIdentifiedCallerCreatesAnOwnedFlow()
}
