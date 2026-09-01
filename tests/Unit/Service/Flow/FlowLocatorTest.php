<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowTriggerMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * The locator replaces the resolver registry: one store, one answer.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */
class FlowLocatorTest extends TestCase {
	/**
	 * Build a Flow with the given fields.
	 *
	 * @param array<string, mixed> $fields Overrides.
	 *
	 * @return Flow The flow.
	 */
	private function flow(array $fields = []): Flow {
		// array_key_exists, NOT `?? 'alice'`: an explicit null owner is the whole
		// point of the refusal tests, and `??` treats a present-but-null value
		// as absent — so `['owner' => null]` would silently build an OWNED flow
		// and the refusal tests would pass against a default they never set.
		$owner = 'alice';
		if (array_key_exists('owner', $fields) === true) {
			$owner = $fields['owner'];
		}

		$flow = new Flow();
		$flow->setUuid(($fields['uuid'] ?? 'f1'));
		$flow->setName(($fields['name'] ?? 'A flow'));
		$flow->setEnabled(($fields['enabled'] ?? true));
		$flow->setOwner($owner);
		$flow->setTrigger(($fields['trigger'] ?? 'object.created'));
		$flow->setCron(($fields['cron'] ?? null));
		$flow->setNodes(($fields['nodes'] ?? [['id' => 'n1', 'type' => 'openregister.set-fields']]));
		$flow->setEdges(($fields['edges'] ?? []));
		$flow->setLimits(($fields['limits'] ?? ['maxNodes' => 10]));

		return $flow;
	}//end flow()

	private function locator(FlowMapper $mapper): FlowLocator {
		// An EMPTY trigger index, deliberately: these tests are about the
		// column path, and a flow absent from the index is exactly the flow
		// whose columns still decide. The cutover's own behaviour — nodes
		// first, fallback second — is asserted in FlowLocatorTriggerCutoverTest.
		return new FlowLocator(
			$mapper,
			$this->createMock(FlowTriggerMapper::class),
			$this->createMock(ObjectService::class),
			new \Psr\Log\NullLogger(),
			$this->publishedVersions()
		);
	}//end locator()

	/**
	 * A version store where every flow has a published version — the shape a
	 * backfilled instance has, so the column-path tests stay about columns.
	 *
	 * @return FlowVersionMapper The mapped double.
	 */
	private function publishedVersions(): FlowVersionMapper {
		$published = new FlowVersion();
		$published->setStatus(FlowVersion::STATUS_PUBLISHED);
		$published->setVersion(1);

		$versions = $this->createMock(FlowVersionMapper::class);
		$versions->method('findPublished')->willReturn($published);

		return $versions;
	}//end publishedVersions()

	public function testResolveFlowReturnsTheDocument(): void {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findByUuid')->willReturn($this->flow(['uuid' => 'f1']));

		$doc = $this->locator($mapper)->resolveFlow('f1');

		$this->assertSame('f1', $doc['id']);
		$this->assertSame(['maxNodes' => 10], $doc['limits']);
		$this->assertSame('alice', $doc['owner']);
	}//end testResolveFlowReturnsTheDocument()

	public function testAMissingFlowResolvesToNull(): void {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findByUuid')->willThrowException(new DoesNotExistException('nope'));

		$this->assertNull($this->locator($mapper)->resolveFlow('gone'));
	}//end testAMissingFlowResolvesToNull()

	/**
	 * The memo must cache a MISS too, or a trigger that fires several times for
	 * one object re-queries the store for a flow that is not there.
	 */
	public function testAMissIsMemoisedAndNotRequeried(): void {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->expects($this->once())
			->method('findByUuid')
			->willThrowException(new DoesNotExistException('nope'));

		$locator = $this->locator($mapper);
		$locator->resolveFlow('gone');
		$locator->resolveFlow('gone');
	}//end testAMissIsMemoisedAndNotRequeried()

	/**
	 * THE RULE THIS CLASS EXISTS TO HOLD. A trigger fires with no acting user, so
	 * an ownerless flow has no identity to execute as. It must be refused, and
	 * refused VISIBLY — dropping it silently is indistinguishable from "no flow
	 * was interested", which is how a misconfigured trigger reads as working.
	 */
	public function testAnOwnerlessFlowIsNotDispatchedByATrigger(): void {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findByTrigger')->willReturn(
			[
				$this->flow(['uuid' => 'owned', 'owner' => 'alice']),
				$this->flow(['uuid' => 'orphan', 'owner' => null]),
			]
		);

		$ids = $this->locator($mapper)->flowsForTrigger('object.created', 'r', 's');

		$this->assertSame(['owned'], $ids, 'the ownerless flow must not be dispatched');
	}//end testAnOwnerlessFlowIsNotDispatchedByATrigger()

	public function testADisabledFlowIsNotDispatchedByATrigger(): void {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findByTrigger')->willReturn(
			[
				$this->flow(['uuid' => 'off', 'enabled' => false]),
			]
		);

		$this->assertSame([], $this->locator($mapper)->flowsForTrigger('object.created', 'r', 's'));
	}//end testADisabledFlowIsNotDispatchedByATrigger()

	/**
	 * Positive control for the two refusal tests above: with the same call shape
	 * and a flow that IS dispatchable, the id comes back. Without this, a
	 * flowsForTrigger() that returned [] for every input would pass both.
	 */
	public function testADispatchableFlowIsReturned(): void {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findByTrigger')->willReturn([$this->flow(['uuid' => 'good'])]);

		$this->assertSame(['good'], $this->locator($mapper)->flowsForTrigger('object.created', 'r', 's'));
	}//end testADispatchableFlowIsReturned()

	public function testScheduledFlowsCarryTheirCronAndOwner(): void {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findScheduled')->willReturn(
			[
				$this->flow(['uuid' => 's1', 'trigger' => 'schedule', 'cron' => '0 9 * * 1']),
			]
		);

		$candidates = $this->locator($mapper)->scheduledFlows();

		$this->assertCount(1, $candidates);
		$this->assertSame('s1', $candidates[0]['id']);
		$this->assertSame('0 9 * * 1', $candidates[0]['cron']);
		$this->assertSame('alice', $candidates[0]['owner']);
	}//end testScheduledFlowsCarryTheirCronAndOwner()

	public function testAnOwnerlessScheduledFlowIsNotDispatched(): void {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findScheduled')->willReturn(
			[
				$this->flow(['uuid' => 's1', 'trigger' => 'schedule', 'cron' => '* * * * *', 'owner' => null]),
			]
		);

		$this->assertSame([], $this->locator($mapper)->scheduledFlows());
	}//end testAnOwnerlessScheduledFlowIsNotDispatched()

	/**
	 * A store read that throws must not take the triggering action down with it.
	 */
	public function testAStoreFailureYieldsNoFlowsRatherThanThrowing(): void {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findByTrigger')->willThrowException(new \RuntimeException('db down'));

		$this->assertSame([], $this->locator($mapper)->flowsForTrigger('object.created', 'r', 's'));
	}//end testAStoreFailureYieldsNoFlowsRatherThanThrowing()
}//end class
