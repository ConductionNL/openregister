<?php

/**
 * Unit coverage for FlowDelegationCheck — the fire-time re-resolution.
 *
 * `FlowRunAttributionTest` already drives this through `FlowRunService::queue()`,
 * which is where it matters. These tests hit it directly for the branches that
 * path cannot reach cheaply: the recording of the refusal onto the flow, and the
 * shapes that must NOT be treated as a delegation at all.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\DelegationGrant;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Delegation\DelegationRefused;
use OCA\OpenRegister\Service\Delegation\DelegationService;
use OCA\OpenRegister\Service\Delegation\DelegationVerdict;
use OCA\OpenRegister\Service\Flow\FlowDelegationCheck;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Locks what the fire-time check refuses, parks and records.
 */
class FlowDelegationCheckTest extends TestCase {

	/**
	 * The flow the mapper double returns, if any.
	 *
	 * @var Flow|null
	 */
	private ?Flow $flow = null;

	/**
	 * Whether the flow mapper was asked to persist an update.
	 *
	 * @var boolean
	 */
	private bool $updated = false;

	protected function setUp(): void {
		parent::setUp();

		$this->flow = new Flow();
		$this->flow->setUuid('flow-1');
		$this->flow->setEnabled(true);
		$this->updated = false;
	}//end setUp()

	/**
	 * A check whose delegation service answers the given verdict.
	 *
	 * @param DelegationVerdict|null $verdict What the service answers, or null to
	 *                                        leave the service unresolvable.
	 *
	 * @return FlowDelegationCheck The check under test.
	 */
	private function check(?DelegationVerdict $verdict): FlowDelegationCheck {
		$delegation = null;
		if ($verdict !== null) {
			$delegation = $this->createMock(DelegationService::class);
			$delegation->method('verdictFor')->willReturn($verdict);
		}

		$mapper = new class ($this) {
			/**
			 * @param FlowDelegationCheckTest $test The owning test.
			 */
			public function __construct(private readonly FlowDelegationCheckTest $test) {
			}

			/**
			 * @param string $uuid The flow uuid.
			 *
			 * @return Flow The flow.
			 */
			public function findByUuid(string $uuid): Flow {
				return $this->test->flowForMapper();
			}

			/**
			 * @param Flow $flow The flow.
			 *
			 * @return Flow The flow.
			 */
			public function update(Flow $flow): Flow {
				$this->test->markUpdated();

				return $flow;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($delegation, $mapper): object {
				if ($id === 'OCA\OpenRegister\Db\FlowMapper') {
					return $mapper;
				}

				if ($id === DelegationService::class && $delegation !== null) {
					return $delegation;
				}

				throw new RuntimeException('not available: ' . $id);
			}
		);

		return new FlowDelegationCheck($container, new NullLogger());
	}

	/**
	 * The flow the mapper double hands back.
	 *
	 * @return Flow The flow.
	 */
	public function flowForMapper(): Flow {
		return ($this->flow ?? new Flow());
	}

	/**
	 * Record that the flow was written back.
	 *
	 * @return void
	 */
	public function markUpdated(): void {
		$this->updated = true;
	}

	/**
	 * A live grant.
	 *
	 * @return DelegationGrant The grant.
	 */
	private function grant(): DelegationGrant {
		$grant = new DelegationGrant();
		$grant->setPrincipal('alice');
		$grant->setActingAs('carol');

		return $grant;
	}

	/**
	 * NO STAMP means no delegation was asserted, so nothing is re-resolved.
	 *
	 * Proven by leaving the delegation service UNRESOLVABLE: if the check
	 * consulted it, this would refuse.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testAnUnstampedTriggerIsNotADelegation(): void {
		$this->assertNull(
			$this->check(null)->refuseIfRevoked('flow-1', 'schedule', null, 'carol')
		);
	}

	/**
	 * A stamp naming the SAME uid is not a delegation either.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testASelfNamedStampIsNotADelegation(): void {
		$this->assertNull(
			$this->check(null)->refuseIfRevoked('flow-1', 'schedule', 'carol', 'carol')
		);
	}

	/**
	 * POSITIVE CONTROL: a live grant lets the run proceed, with no park.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testALiveGrantProceeds(): void {
		$this->assertNull(
			$this->check(DelegationVerdict::granted($this->grant()))
				->refuseIfRevoked('flow-1', 'schedule', 'alice', 'carol')
		);
		$this->assertFalse($this->updated, 'a permitted run must not write an error onto the flow');
	}

	/**
	 * An UNANSWERED request returns park instructions rather than throwing.
	 *
	 * Somebody has been asked and has not replied. That is a different fact from
	 * "they said no" and wants a different outcome — discarding the run throws
	 * away work that becomes legal the moment a person reads their notifications.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testAnUnansweredRequestParksRatherThanRefusing(): void {
		$park = $this->check(
			DelegationVerdict::refused(DelegationVerdict::REASON_PENDING, 'waiting.')
		)->refuseIfRevoked('flow-1', 'schedule', 'alice', 'carol');

		$this->assertSame(
			['principal' => 'alice', 'actingAs' => 'carol', 'reason' => DelegationVerdict::REASON_PENDING],
			$park
		);
		$this->assertFalse($this->updated, 'a parked run is not a flow error');
	}

	/**
	 * 🔴 A revocation refuses AND is written onto the flow.
	 *
	 * A logged warning is not a control surface. "Why did this stop firing" has to
	 * be answerable from the flow, or the answer lives only in a log nobody reads.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testARevocationRefusesAndIsRecordedOnTheFlow(): void {
		try {
			$this->check(
				DelegationVerdict::refused(DelegationVerdict::REASON_REVOKED, 'carol withdrew it.')
			)->refuseIfRevoked('flow-1', 'schedule', 'alice', 'carol');
			$this->fail('a revoked delegation must refuse');
		} catch (DelegationRefused $e) {
			$this->assertSame(DelegationVerdict::REASON_REVOKED, $e->getVerdict()->reason);
		}

		$this->assertTrue($this->updated, 'the refusal must be visible on the flow');
		$this->assertSame(Flow::STATUS_ERROR, $this->flow->getStatus());
		$this->assertStringContainsString('carol', (string)$this->flow->getStatusMessage());
	}

	/**
	 * 🔴 THE SCHEDULE IS LEFT ENABLED on a delegation refusal.
	 *
	 * Unlike the unattributed case, which disables. The two faults recover
	 * differently: a flow naming nobody cannot fix itself without an edit, so
	 * leaving it "on" would be a switch that lies; a revoked delegation becomes
	 * valid again the moment the grant does, and disabling would convert a
	 * temporary revocation into a permanent one that only a human re-enabling
	 * could undo — with nothing telling them to.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testTheScheduleIsLeftEnabled(): void {
		try {
			$this->check(
				DelegationVerdict::refused(DelegationVerdict::REASON_DENIED, 'no.')
			)->refuseIfRevoked('flow-1', 'schedule', 'alice', 'carol');
		} catch (DelegationRefused $e) {
			// Expected.
		}

		$this->assertTrue(
			$this->flow->getEnabled(),
			'a revocation must not silently switch the schedule off'
		);
	}

	/**
	 * An unreachable delegation store refuses, naming that it could not be read.
	 *
	 * Fail-closed and bounded: only a run that IS asserting a delegation reaches
	 * this, so an infrastructure fault costs exactly the runs whose authorization
	 * cannot be established.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testAnUnreachableStoreRefusesADelegatingRun(): void {
		try {
			$this->check(null)->refuseIfRevoked('flow-1', 'schedule', 'alice', 'carol');
			$this->fail('an unverifiable delegation must not run');
		} catch (DelegationRefused $e) {
			$this->assertSame(DelegationVerdict::REASON_UNREADABLE, $e->getVerdict()->reason);
		}
	}

	/**
	 * A refusal that cannot be recorded is still a refusal.
	 *
	 * Best-effort by construction: a failure to write the message must not
	 * replace the refusal, which is the part that actually prevents the run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testAnUnrecordableRefusalStillRefuses(): void {
		$this->flow = null;

		$this->expectException(DelegationRefused::class);

		$this->check(
			DelegationVerdict::refused(DelegationVerdict::REASON_EXPIRED, 'it ran out.')
		)->refuseIfRevoked('flow-1', 'schedule', 'alice', 'carol');
	}
}
