<?php

/**
 * The pinning contract: a run executes the version it started on, or it fails.
 *
 * 🔴 EVERY TEST HERE GUARDS ONE SENTENCE: publishing, deprecating or deleting
 * anything while a run is in flight changes what that run does in exactly zero
 * cases. The failure mode this prevents is silent — an edited flow simply
 * behaves differently for work already under way, and nothing errors — so the
 * assertions are about the GRAPH a run receives, never about a status field.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowDefinition;
use OCA\OpenRegister\Db\FlowDefinitionMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Service\Flow\FlowDefinitionPin;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraph;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

class FlowVersionPinningTest extends TestCase {

	/**
	 * @var array<string, array<string, mixed>> Graphs by hash.
	 */
	private array $definitions = [];

	/**
	 * @var array<string, FlowVersion> Version rows, keyed "flow:version".
	 */
	private array $versions = [];

	/**
	 * Register a version of a flow whose graph is $graph.
	 *
	 * @param string $flowId  The flow.
	 * @param int    $number  The version number.
	 * @param array  $graph   The graph that version names.
	 *
	 * @return void
	 */
	private function giveVersion(string $flowId, int $number, array $graph): void {
		$hash = 'hash-' . $flowId . '-' . $number;
		$this->definitions[$hash] = $graph;

		$version = new FlowVersion();
		$version->setFlowUuid($flowId);
		$version->setVersion($number);
		$version->setStatus(FlowVersion::STATUS_PUBLISHED);
		$version->setDefinitionHash($hash);

		$this->versions[$flowId . ':' . $number] = $version;
	}//end giveVersion()

	/**
	 * Forget a version, as deleting it would.
	 *
	 * @param string $flowId The flow.
	 * @param int    $number The version number.
	 *
	 * @return void
	 */
	private function removeVersion(string $flowId, int $number): void {
		unset($this->versions[$flowId . ':' . $number]);
	}//end removeVersion()

	/**
	 * A resolver over the registered versions and definitions.
	 *
	 * @return FlowPublishedGraph The resolver.
	 */
	private function graphs(): FlowPublishedGraph {
		$versionMapper = $this->createMock(FlowVersionMapper::class);
		$versionMapper->method('find')->willReturnCallback(
			fn (string $flowUuid, int $number): ?FlowVersion => ($this->versions[$flowUuid . ':' . $number] ?? null)
		);

		$definitionMapper = $this->createMock(FlowDefinitionMapper::class);
		$definitionMapper->method('findByHash')->willReturnCallback(
			function (string $hash): ?FlowDefinition {
				if (isset($this->definitions[$hash]) === false) {
					return null;
				}

				$entity = new FlowDefinition();
				$entity->setHash($hash);
				$entity->setDefinition((string)json_encode($this->definitions[$hash]));

				return $entity;
			}
		);

		$pin = new FlowDefinitionPin($definitionMapper, new NullLogger());

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($versionMapper, $pin): object {
				if ($id === FlowVersionMapper::class) {
					return $versionMapper;
				}

				return $pin;
			}
		);

		return new FlowPublishedGraph($container);
	}//end graphs()

	/**
	 * A run pinned to a version of a flow.
	 *
	 * @param string   $flowId  The flow.
	 * @param int|null $version The pinned version, or null for unpinned.
	 *
	 * @return FlowRun The run.
	 */
	private function pinnedRun(string $flowId, ?int $version): FlowRun {
		$run = new FlowRun();
		$run->setFlowId($flowId);
		$run->setFlowVersion($version);
		$run->setStatus(FlowRun::STATUS_SUSPENDED);

		return $run;
	}//end pinnedRun()

	/**
	 * 🔴 THE DEFECT. A run suspended on a human step for a fortnight, resumed
	 * after its author published a rewritten version, must finish the process
	 * it began. ADR-098 Decision 6 forbids shipping human task nodes without
	 * this, and dossiq already ships two.
	 *
	 * @return void
	 */
	public function testARunSuspendedAcrossAPublishResumesOnItsOwnVersion(): void {
		$v1 = ['nodes' => [['id' => 'ask'], ['id' => 'file']], 'edges' => [['from' => 'ask', 'to' => 'file']]];
		$v2 = ['nodes' => [['id' => 'renamed']], 'edges' => []];

		$this->giveVersion('case-flow', 1, $v1);
		$this->giveVersion('case-flow', 2, $v2);

		// The LIVE document is version 2 — that is what resolving the flow by
		// id gives you today.
		$live = $v2 + ['id' => 'case-flow', 'owner' => 'alice'];

		$walked = $this->graphs()->overlayOnto(run: $this->pinnedRun('case-flow', 1), live: $live);

		$this->assertSame($v1['nodes'], $walked['nodes'], 'the run must walk version 1');
		$this->assertSame($v1['edges'], $walked['edges']);
	}//end testARunSuspendedAcrossAPublishResumesOnItsOwnVersion()

	/**
	 * 🔴 AUTHORIZATION RE-RESOLVES; THE GRAPH DOES NOT. Pinning the owner too
	 * would make revoking access cosmetic for exactly the long-running work
	 * nobody is watching.
	 *
	 * @return void
	 */
	public function testTheOwnerComesFromTheLiveDocumentNotThePin(): void {
		$this->giveVersion('f', 1, ['nodes' => [['id' => 'a']], 'edges' => [], 'owner' => 'old-owner']);

		$walked = $this->graphs()->overlayOnto(
			run: $this->pinnedRun('f', 1),
			live: ['nodes' => [], 'edges' => [], 'owner' => 'current-owner']
		);

		$this->assertSame('current-owner', $walked['owner']);
	}//end testTheOwnerComesFromTheLiveDocumentNotThePin()

	/**
	 * Two runs of one flow on different versions, advanced in the same worker
	 * pass, must each receive their own graph. A resolver cached by flow alone
	 * would serve one run's graph to the other.
	 *
	 * @return void
	 */
	public function testTwoVersionsOfOneFlowAdvanceInOneBatchWithoutCrossTalk(): void {
		$this->giveVersion('f', 1, ['nodes' => [['id' => 'one']], 'edges' => []]);
		$this->giveVersion('f', 2, ['nodes' => [['id' => 'two']], 'edges' => []]);

		$graphs = $this->graphs();
		$live = ['nodes' => [['id' => 'two']], 'edges' => []];

		$first = $graphs->overlayOnto(run: $this->pinnedRun('f', 1), live: $live);
		$second = $graphs->overlayOnto(run: $this->pinnedRun('f', 2), live: $live);

		$this->assertSame('one', $first['nodes'][0]['id']);
		$this->assertSame('two', $second['nodes'][0]['id']);
	}//end testTwoVersionsOfOneFlowAdvanceInOneBatchWithoutCrossTalk()

	/**
	 * 🔴 A DELETED VERSION FAILS THE RUN. Null is the signal the caller turns
	 * into a failure naming the version — never a substitution.
	 *
	 * @return void
	 */
	public function testARunWhosePinnedVersionWasDeletedResolvesToNothing(): void {
		$this->giveVersion('f', 2, ['nodes' => [['id' => 'a']], 'edges' => []]);
		$this->removeVersion('f', 2);

		$this->assertNull(
			$this->graphs()->overlayOnto(run: $this->pinnedRun('f', 2), live: ['nodes' => [], 'edges' => []])
		);
	}//end testARunWhosePinnedVersionWasDeletedResolvesToNothing()

	/**
	 * 🔴 A NEWER VERSION IS NOT A SUBSTITUTE. Even with version 3 published and
	 * healthy, a run pinned to the missing version 2 must not be promoted onto
	 * it: the run's marking, its taken decisions and its log all belong to the
	 * version it started on.
	 *
	 * @return void
	 */
	public function testANewerPublishedVersionIsNotSubstitutedForAMissingPin(): void {
		$this->giveVersion('f', 3, ['nodes' => [['id' => 'newest']], 'edges' => []]);

		$walked = $this->graphs()->overlayOnto(
			run: $this->pinnedRun('f', 2),
			live: ['nodes' => [['id' => 'newest']], 'edges' => []]
		);

		$this->assertNull($walked, 'a run must fail rather than adopt a version it did not start on');
	}//end testANewerPublishedVersionIsNotSubstitutedForAMissingPin()

	/**
	 * The one documented exception: an interactive test run of a draft carries
	 * its own snapshot and is not pinned to a published version.
	 *
	 * @return void
	 */
	public function testAnUnpinnedTestRunWalksTheDocumentItWasGiven(): void {
		$live = ['nodes' => [['id' => 'draft-step']], 'edges' => []];

		$this->assertSame(
			$live,
			$this->graphs()->overlayOnto(run: $this->pinnedRun('f', null), live: $live)
		);
	}//end testAnUnpinnedTestRunWalksTheDocumentItWasGiven()

	/**
	 * A version row whose definition row is gone is as unresolvable as a
	 * missing version — it must not degrade into an empty graph, which would
	 * let a run "complete" without executing anything.
	 *
	 * @return void
	 */
	public function testAVersionWhoseDefinitionIsMissingResolvesToNothing(): void {
		$this->giveVersion('f', 1, ['nodes' => [['id' => 'a']], 'edges' => []]);
		$this->definitions = [];

		$this->assertNull(
			$this->graphs()->overlayOnto(run: $this->pinnedRun('f', 1), live: ['nodes' => [], 'edges' => []])
		);
	}//end testAVersionWhoseDefinitionIsMissingResolvesToNothing()
}//end class
