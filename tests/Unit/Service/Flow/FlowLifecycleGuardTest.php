<?php

/**
 * The lifecycle preconditions, and the refusals they produce.
 *
 * 🔴 A GUARD THAT RETURNS QUIETLY IS NOT A GUARD. Every test here asserts the
 * refusal is THROWN and carries a machine-readable reason, because the two
 * refusals a client must tell apart — "this version is published, make a
 * draft" and "this flow has no published version, publish one" — want opposite
 * actions from the author, and a single human sentence leaves the editor
 * guessing which button to offer.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Service\Flow\FlowLifecycleGuard;
use OCA\OpenRegister\Service\Flow\FlowLifecycleRefused;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class FlowLifecycleGuardTest extends TestCase {

	/**
	 * A guard whose run mapper reports $pinned active runs.
	 *
	 * @param integer $pinned   How many runs are pinned to the version asked about.
	 * @param array   $deadEnds Step ids the preflight reports as dead ends.
	 *
	 * @return FlowLifecycleGuard The guard.
	 */
	private function guard(int $pinned = 0, array $deadEnds = []): FlowLifecycleGuard {
		$runs = $this->createMock(FlowRunMapper::class);
		$runs->method('countActivePinnedTo')->willReturn($pinned);

		// The preflight is a double, deliberately. Whether a given graph HAS a
		// dead end is FlowNodePreflight's question and has its own tests; what
		// this suite asserts is that the guard turns that verdict into a
		// refusal instead of a logged shrug.
		$preflight = $this->createMock(FlowNodePreflight::class);
		$preflight->method('inspect')->willReturn([
			'warnings' => array_map(
				static fn (string $step): array => [
					'reason' => FlowNodePreflight::REASON_DEAD_END,
					'step' => $step,
				],
				$deadEnds
			),
		]);

		return new FlowLifecycleGuard($runs, $preflight, new NullLogger());
	}//end guard()

	/**
	 * Assert a refusal carrying an exact reason.
	 *
	 * @param string   $reason The expected REASON_* value.
	 * @param callable $act    The call that must refuse.
	 *
	 * @return void
	 */
	private function assertRefusedWith(string $reason, callable $act): void {
		try {
			$act();
		} catch (FlowLifecycleRefused $refusal) {
			$this->assertSame($reason, $refusal->getReason());
			return;
		}

		$this->fail('Expected a FlowLifecycleRefused with reason "' . $reason . '", but nothing was thrown.');
	}//end assertRefusedWith()

	/**
	 * 🔴 A PUBLISHED VERSION IS IMMUTABLE. Silently applying the edit, or
	 * turning it into a new version behind the author's back, are both worse
	 * than refusing: the first changes a live process without anybody
	 * deciding to, the second publishes work nobody reviewed.
	 *
	 * @return void
	 */
	public function testEditingAPublishedFlowIsRefused(): void {
		$this->assertRefusedWith(
			FlowLifecycleRefused::REASON_IMMUTABLE,
			fn () => $this->guard()->refuseEditUnlessDraft('f', FlowVersion::STATUS_PUBLISHED)
		);
	}//end testEditingAPublishedFlowIsRefused()

	/**
	 * A deprecated version is immutable on the same terms.
	 *
	 * @return void
	 */
	public function testEditingADeprecatedFlowIsRefused(): void {
		$this->assertRefusedWith(
			FlowLifecycleRefused::REASON_IMMUTABLE,
			fn () => $this->guard()->refuseEditUnlessDraft('f', FlowVersion::STATUS_DEPRECATED)
		);
	}//end testEditingADeprecatedFlowIsRefused()

	/**
	 * A draft is what an author is supposed to be editing.
	 *
	 * @return void
	 */
	public function testEditingADraftIsAllowed(): void {
		$this->guard()->refuseEditUnlessDraft('f', FlowVersion::STATUS_DRAFT);

		$this->expectNotToPerformAssertions();
	}//end testEditingADraftIsAllowed()

	/**
	 * A flow with no lifecycle recorded at all is a pre-versioning flow being
	 * saved during the upgrade window. Refusing those would make the upgrade
	 * itself lock every author out of every flow.
	 *
	 * @return void
	 */
	public function testAFlowWithNoLifecycleYetIsNotRefused(): void {
		$this->guard()->refuseEditUnlessDraft('f', null);

		$this->expectNotToPerformAssertions();
	}//end testAFlowWithNoLifecycleYetIsNotRefused()

	/**
	 * Only a draft may be published.
	 *
	 * @return void
	 */
	public function testPublishingSomethingAlreadyPublishedIsRefused(): void {
		$this->assertRefusedWith(
			FlowLifecycleRefused::REASON_NOT_A_DRAFT,
			fn () => $this->guard()->refusePublishUnlessDraft('f', FlowVersion::STATUS_PUBLISHED)
		);
	}//end testPublishingSomethingAlreadyPublishedIsRefused()

	/**
	 * Only a published version may be deprecated — and "nothing is published"
	 * is reported as such rather than as a success that did nothing.
	 *
	 * @return void
	 */
	public function testDeprecatingWhenNothingIsPublishedIsRefused(): void {
		$this->assertRefusedWith(
			FlowLifecycleRefused::REASON_NOT_PUBLISHED,
			fn () => $this->guard()->refuseDeprecateUnlessPublished('f', null)
		);
	}//end testDeprecatingWhenNothingIsPublishedIsRefused()

	/**
	 * 🔴 THE PREFLIGHT JUDGES THE GRAPH BEING PUBLISHED. A node a token cannot
	 * leave would make a run stop there and still be reported as completed —
	 * the quietest possible failure, and the reason publishing checks at all.
	 *
	 * @return void
	 */
	public function testPublishingAGraphWithADeadEndIsRefused(): void {
		$graph = [
			'nodes' => [
				['id' => 'start', 'type' => 'test.wait'],
				['id' => 'orphan', 'type' => 'test.wait'],
			],
			'edges' => [['from' => 'start', 'to' => 'orphan']],
		];

		$this->assertRefusedWith(
			FlowLifecycleRefused::REASON_DEAD_END,
			fn () => $this->guard(deadEnds: ['orphan'])->refusePublishOnDeadEnd('f', $graph)
		);
	}//end testPublishingAGraphWithADeadEndIsRefused()

	/**
	 * 🔴 A VERSION A RUN STILL NEEDS MAY NOT BE REMOVED. Removing it would
	 * strand that run: it cannot be advanced, and it must not be moved onto a
	 * different graph.
	 *
	 * @return void
	 */
	public function testRemovingAVersionAnActiveRunIsPinnedToIsRefused(): void {
		$this->assertRefusedWith(
			FlowLifecycleRefused::REASON_VERSION_IN_USE,
			fn () => $this->guard(pinned: 3)->refuseRemoveWhilePinned('f', 2)
		);
	}//end testRemovingAVersionAnActiveRunIsPinnedToIsRefused()

	/**
	 * A version nothing is pinned to may be removed.
	 *
	 * @return void
	 */
	public function testRemovingAnUnusedVersionIsAllowed(): void {
		$this->guard(pinned: 0)->refuseRemoveWhilePinned('f', 2);

		$this->expectNotToPerformAssertions();
	}//end testRemovingAnUnusedVersionIsAllowed()

	/**
	 * The refusal names the flow, so a log line is actionable without a
	 * debugger.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesTheFlowAndItsState(): void {
		try {
			$this->guard()->refuseEditUnlessDraft('flow-42', FlowVersion::STATUS_PUBLISHED);
			$this->fail('expected a refusal');
		} catch (FlowLifecycleRefused $refusal) {
			$this->assertSame('flow-42', $refusal->getFlowId());
			$this->assertSame(FlowVersion::STATUS_PUBLISHED, $refusal->getState());
			$this->assertStringContainsString('flow-42', $refusal->getMessage());
		}
	}//end testTheRefusalNamesTheFlowAndItsState()
}//end class
