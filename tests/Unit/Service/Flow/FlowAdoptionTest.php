<?php

/**
 * Adoption: the deliberate act that turns a shipped flow into a runnable one.
 *
 * An imported flow arrives `enabled=false, owner=null` and
 * `Flow::canDispatch()` fails closed on the missing owner — correct, and
 * proven elsewhere. What had NO coverage, because it had no implementation,
 * was the second half: on a live instance the only route from "shipped" to
 * "runnable" was raw SQL. These tests pin the seam's whole contract: the
 * caller (never a supplied uid) becomes the owner, a takeover is refused, the
 * act is idempotent for the owner, and an adopted-and-enabled flow actually
 * dispatches.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-adoption/specs/flow-storage/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Db\FlowTriggerMapper;
use OCA\OpenRegister\Service\Flow\FlowAdoptionRefused;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancer;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowService
 * @covers \OCA\OpenRegister\Service\Flow\FlowAdoptionRefused
 *
 * @uses \OCA\OpenRegister\Db\Flow
 * @uses \OCA\OpenRegister\Service\Flow\FlowLocator
 */
class FlowAdoptionTest extends TestCase {

	/**
	 * The flows update() persisted.
	 *
	 * @var array<int, Flow>
	 */
	private array $updated = [];

	/**
	 * A FlowService whose session answers $uid (or nobody when null).
	 */
	private function service(?string $uid): FlowService {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('update')->willReturnCallback(
			function (Flow $flow): Flow {
				$this->updated[] = $flow;

				return $flow;
			}
		);

		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		}

		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

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
			$this->createMock(ContainerInterface::class)
		);
	}

	/**
	 * A freshly imported flow: enabled=false, owner=null.
	 */
	private function importedFlow(): Flow {
		$flow = new Flow();
		$flow->setUuid('flow-imported');
		$flow->setName('Bezwaar advies');
		$flow->setApp('dossiq');
		$flow->setEnabled(false);
		$flow->setOwner(null);

		return $flow;
	}

	public function testAdoptSetsTheCallerAsOwner(): void {
		$adopted = $this->service('alice')->adopt(flow: $this->importedFlow());

		$this->assertSame('alice', $adopted->getOwner(), 'the caller, and nobody else, becomes the owner');
		$this->assertCount(1, $this->updated, 'the adoption must be persisted');
		$this->assertFalse((bool)$adopted->getEnabled(), 'adoption must NOT enable: whose flow and may-it-run are separate acts');
	}

	public function testAdoptionWithNoSessionIsRefused(): void {
		$service = $this->service(null);

		try {
			$service->adopt(flow: $this->importedFlow());
			$this->fail('an ownerless session must not produce an owner');
		} catch (FlowAdoptionRefused $e) {
			$this->assertSame(FlowAdoptionRefused::REASON_NO_ACTING_USER, $e->getReason());
		}

		$this->assertCount(0, $this->updated, 'nothing may be written on a refusal');
	}

	public function testAdoptionIsNotATakeover(): void {
		$flow = $this->importedFlow();
		$flow->setOwner('bob');

		try {
			$this->service('alice')->adopt(flow: $flow);
			$this->fail('a flow that belongs to bob must not silently become alice\'s');
		} catch (FlowAdoptionRefused $e) {
			$this->assertSame(FlowAdoptionRefused::REASON_ALREADY_OWNED, $e->getReason());
		}

		$this->assertSame('bob', $flow->getOwner(), 'the owner must be untouched by the refused attempt');
		$this->assertCount(0, $this->updated);
	}

	public function testAdoptingOnesOwnFlowIsIdempotent(): void {
		$flow = $this->importedFlow();
		$flow->setOwner('alice');

		$adopted = $this->service('alice')->adopt(flow: $flow);

		$this->assertSame('alice', $adopted->getOwner());
		$this->assertCount(0, $this->updated, 're-adopting what one owns is a no-op, not a rewrite');
	}

	/**
	 * 🔑 THE POINT OF THE SEAM: an adopted AND enabled flow dispatches.
	 *
	 * Walked through the real `FlowLocator`, whose `dispatchableUuids()` is
	 * the exact gate that dropped the ownerless imported flow with only a log
	 * line. The same flow is offered twice: as imported (dropped) and as
	 * adopted+enabled (dispatched) — so this fails if either half of the
	 * lifecycle stops reaching `canDispatch()`.
	 */
	public function testAnAdoptedAndEnabledFlowBecomesDispatchable(): void {
		$imported = $this->importedFlow();

		$adopted = $this->service('alice')->adopt(flow: $this->importedFlow());
		$adopted->setEnabled(true);

		$this->assertFalse($imported->canDispatch(), 'the shipped state must stay fail-closed');
		$this->assertTrue($adopted->canDispatch(), 'adopted + enabled is the runnable state');

		foreach ([['flow' => $imported, 'expected' => []], ['flow' => $adopted, 'expected' => ['flow-imported']]] as $case) {
			$triggerMapper = $this->createMock(FlowTriggerMapper::class);
			$triggerMapper->method('flowUuidsFor')->willReturn(['flow-imported']);
			$triggerMapper->method('representedFlowUuids')->willReturn(['flow-imported']);

			$flowMapper = $this->createMock(FlowMapper::class);
			$flowMapper->method('findByTrigger')->willReturn([]);
			$flowMapper->method('findByUuid')->willReturn($case['flow']);

			$locator = new FlowLocator(
				$flowMapper,
				$triggerMapper,
				$this->createMock(ObjectService::class),
				new NullLogger()
			);

			$this->assertSame(
				$case['expected'],
				$locator->flowsForTrigger(event: 'object.created', register: 'dossiq', schema: 'case'),
				'the ownerless flow is refused at dispatch; the adopted and enabled one runs'
			);
		}
	}
}
