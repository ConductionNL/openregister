<?php

/**
 * Telling the author what a publish will be called, before they publish it.
 *
 * 🔑 THE PREFLIGHT AND THE PUBLISH READ THE SAME PAIR OF GRAPHS. A preview
 * computed a second way is a second opinion, and the first time the two
 * disagree the author learns to believe neither — which is worse than showing
 * nothing, because they would have trusted it first.
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
 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-the-author-is-told-what-it-will-be-before-publishing
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Service\Flow\FlowDefinitionPin;
use OCA\OpenRegister\Service\Flow\FlowLifecycleGuard;
use OCA\OpenRegister\Service\Flow\FlowSemanticVersion;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for {@see FlowVersionService::previewPublish()}.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowVersionService
 * @uses \OCA\OpenRegister\Service\Flow\FlowSemanticVersion
 * @uses \OCA\OpenRegister\Service\Flow\FlowGraphDiff
 * @uses \OCA\OpenRegister\Db\Flow
 * @uses \OCA\OpenRegister\Db\FlowVersion
 */
final class FlowVersionPreviewTest extends TestCase {

	/**
	 * The graph that is live.
	 *
	 * @return array<string, mixed> The published graph.
	 */
	private function publishedGraph(): array {
		return [
			'nodes' => [
				['id' => 'start', 'type' => 'openregister.trigger-manual', 'config' => []],
				['id' => 'middle', 'type' => 'openregister.set-fields', 'config' => ['set' => []]],
				['id' => 'done', 'type' => 'openregister.end', 'config' => []],
			],
			'edges' => [
				['id' => 'e1', 'from' => 'start', 'to' => 'middle'],
				['id' => 'e2', 'from' => 'middle', 'to' => 'done'],
			],
		];
	}//end publishedGraph()

	/**
	 * A flow carrying a candidate graph as its head.
	 *
	 * @param array<string, mixed> $graph The head graph.
	 *
	 * @return Flow The flow.
	 */
	private function flowWithHead(array $graph): Flow {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setNodes(($graph['nodes'] ?? []));
		$flow->setEdges(($graph['edges'] ?? []));

		return $flow;
	}//end flowWithHead()

	/**
	 * The service, with the published version and its stored graph wired in.
	 *
	 * @param FlowVersion|null     $published The live version, or null for none.
	 * @param array<string, mixed>|null $stored The graph that version pinned.
	 * @param boolean              $pinThrows Whether reading the graph fails.
	 *
	 * @return FlowVersionService The service.
	 */
	private function service(?FlowVersion $published, ?array $stored, bool $pinThrows = false): FlowVersionService {
		$versions = $this->createMock(FlowVersionMapper::class);
		$versions->method('findPublished')->willReturn($published);

		$pin = $this->createMock(FlowDefinitionPin::class);
		if ($pinThrows === true) {
			$pin->method('graphFor')->willThrowException(new RuntimeException('the definition row was pruned'));
		} else {
			$pin->method('graphFor')->willReturn($stored);
		}

		return new FlowVersionService(
			$versions,
			$this->createMock(FlowMapper::class),
			$pin,
			$this->createMock(FlowLifecycleGuard::class),
			$this->createMock(FlowTriggerIndex::class),
			new FlowSemanticVersion(),
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * A published version with a semantic version already on it.
	 *
	 * @param string $semver The version it carries.
	 *
	 * @return FlowVersion The row.
	 */
	private function publishedVersion(string $semver): FlowVersion {
		$row = new FlowVersion();
		$row->setFlowUuid('flow-1');
		$row->setVersion(4);
		$row->setStatus(FlowVersion::STATUS_PUBLISHED);
		$row->setSemver($semver);
		$row->setDefinitionHash('deadbeef');

		return $row;
	}//end publishedVersion()

	/**
	 * 🔴 THE PREFLIGHT NAMES THE REMOVED STEP AND THE REMOVED EDGE.
	 *
	 * The requirement's scenario. Reporting only the step would hide a
	 * rewiring that removed a path while keeping every node — a change that
	 * breaks a consumer without deleting anything they can see.
	 *
	 * @return void
	 */
	public function testAMajorPreviewNamesTheRemovedStepAndTheRemovedEdge(): void {
		$candidate = [
			'nodes' => [
				['id' => 'start', 'type' => 'openregister.trigger-manual', 'config' => []],
				['id' => 'done', 'type' => 'openregister.end', 'config' => []],
			],
			'edges' => [['id' => 'e1', 'from' => 'start', 'to' => 'done']],
		];

		$preview = $this->service($this->publishedVersion('1.4.0'), $this->publishedGraph())
			->previewPublish(flow: $this->flowWithHead($candidate));

		$this->assertSame('major', $preview['verdict']);
		$this->assertSame('2.0.0', $preview['next']);
		$this->assertSame('1.4.0', $preview['current'], 'the author is shown what it is now, not only what it becomes');
		$this->assertContains('middle', $preview['removedNodes']);
		$this->assertNotSame([], $preview['removedEdges'], 'the removed connection must be named too');
		$this->assertStringContainsString('middle', $preview['removed']);
	}//end testAMajorPreviewNamesTheRemovedStepAndTheRemovedEdge()

	/**
	 * An addition-only draft previews as minor and claims no removal.
	 *
	 * @return void
	 */
	public function testAnAdditionOnlyDraftPreviewsAsMinor(): void {
		$candidate = $this->publishedGraph();
		$candidate['nodes'][] = ['id' => 'extra', 'type' => 'openregister.filter', 'config' => []];
		$candidate['edges'][] = ['id' => 'e3', 'from' => 'middle', 'to' => 'extra'];
		$candidate['edges'][] = ['id' => 'e4', 'from' => 'extra', 'to' => 'done'];

		$preview = $this->service($this->publishedVersion('1.4.0'), $this->publishedGraph())
			->previewPublish(flow: $this->flowWithHead($candidate));

		$this->assertSame('minor', $preview['verdict']);
		$this->assertSame('1.5.0', $preview['next']);
		$this->assertSame('', $preview['removed']);
		$this->assertFalse($preview['first']);
	}//end testAnAdditionOnlyDraftPreviewsAsMinor()

	/**
	 * With nothing published, the preflight says so rather than inventing a diff.
	 *
	 * @return void
	 */
	public function testAFlowWithNothingPublishedPreviewsAsTheFirstVersion(): void {
		$preview = $this->service(null, null)
			->previewPublish(flow: $this->flowWithHead($this->publishedGraph()));

		$this->assertTrue($preview['first']);
		$this->assertSame(FlowSemanticVersion::FIRST, $preview['next']);
		$this->assertNull($preview['current']);
		$this->assertSame([], $preview['removedNodes']);
	}//end testAFlowWithNothingPublishedPreviewsAsTheFirstVersion()

	/**
	 * 🔴 AN UNREADABLE PREVIOUS GRAPH IS NOT A FAILURE.
	 *
	 * The definition row may have been pruned. A LABELLING feature that threw
	 * here would block the author from finding out anything at all; treating it
	 * as no previous version yields the first version rather than a confident
	 * major nobody can check.
	 *
	 * @return void
	 */
	public function testAnUnreadablePreviousGraphPreviewsAsTheFirstVersion(): void {
		$preview = $this->service($this->publishedVersion('1.4.0'), null, pinThrows: true)
			->previewPublish(flow: $this->flowWithHead($this->publishedGraph()));

		$this->assertTrue($preview['first']);
		$this->assertSame(FlowSemanticVersion::FIRST, $preview['next']);
	}//end testAnUnreadablePreviousGraphPreviewsAsTheFirstVersion()

	/**
	 * A pin that answers with something that is not a graph is treated as absent.
	 *
	 * @return void
	 */
	public function testAPinThatAnswersWithNothingIsTreatedAsNoPreviousGraph(): void {
		$preview = $this->service($this->publishedVersion('2.0.0'), null)
			->previewPublish(flow: $this->flowWithHead($this->publishedGraph()));

		$this->assertTrue($preview['first']);
	}//end testAPinThatAnswersWithNothingIsTreatedAsNoPreviousGraph()

	/**
	 * The preflight ASKS; it never refuses.
	 *
	 * An author has to be able to find out that a publish is major without
	 * first asserting that it is not.
	 *
	 * @return void
	 */
	public function testThePreflightNeverRefuses(): void {
		$preview = $this->service($this->publishedVersion('1.0.0'), $this->publishedGraph())
			->previewPublish(flow: $this->flowWithHead(['nodes' => [], 'edges' => []]));

		$this->assertSame('major', $preview['verdict']);
	}//end testThePreflightNeverRefuses()
}//end class
