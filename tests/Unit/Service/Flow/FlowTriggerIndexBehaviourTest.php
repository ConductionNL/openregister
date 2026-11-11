<?php

/**
 * FlowTriggerIndex: what a reindex writes (and when it writes nothing), the
 * slug canonicalisation, the failures it absorbs, and the rebuild report of
 * flows still triggered by their columns.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowTriggerMapperInterface;
use OCA\OpenRegister\Db\Flow\FlowTriggerSet;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraph;
use OCA\OpenRegister\Service\Flow\FlowTriggerDerivation;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use OCA\OpenRegister\Service\Flow\FlowTriggerSlugs;
use OCA\OpenRegister\Service\Flow\FlowTriggerSources;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowTriggerIndex
 * @uses \OCA\OpenRegister\Db\Flow
 * @uses \OCA\OpenRegister\Service\Flow\FlowTriggerDerivation
 * @uses \OCA\OpenRegister\Service\Flow\FlowTriggerSources
 */
class FlowTriggerIndexBehaviourTest extends TestCase {

	private FlowTriggerMapperInterface&MockObject $mapper;

	private LoggerInterface&MockObject $logger;

	private FlowTriggerSlugs&MockObject $slugs;

	/**
	 * Published graphs by flow uuid.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private array $graphs = [];

	/**
	 * Every replaceFor() call.
	 *
	 * @var array<int, array{flow: string, triggers: array, enabled: bool}>
	 */
	private array $written = [];

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(FlowTriggerMapperInterface::class);
		$this->mapper->method('replaceFor')->willReturnCallback(
			function (FlowTriggerSet $set): int {
				[$flowUuid, $triggers, $enabled] = [$set->flowUuid, $set->triggers, $set->enabled];
				$this->written[] = ['flow' => $flowUuid, 'triggers' => $triggers, 'enabled' => $enabled];

				return count($triggers);
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->slugs = $this->createMock(FlowTriggerSlugs::class);
		$this->slugs->method('registerSlug')->willReturnCallback(static fn (string $id): string => ($id === '5' ? 'cases' : $id));
		$this->slugs->method('schemaSlug')->willReturnCallback(static fn (string $id): string => ($id === '9' ? 'case' : $id));
	}//end setUp()

	private function index(bool $withPublished = true): FlowTriggerIndex {
		$published = null;
		if ($withPublished === true) {
			$published = $this->createMock(FlowPublishedGraph::class);
			$published->method('graphOf')->willReturnCallback(fn (string $flowId): ?array => ($this->graphs[$flowId] ?? null));
		}

		return new FlowTriggerIndex(
			mapper: $this->mapper,
			sources: new FlowTriggerSources(derivation: new FlowTriggerDerivation(), slugs: $this->slugs, published: $published),
			logger: $this->logger
		);
	}//end index()

	private function flow(string $uuid, bool $enabled = true, ?string $trigger = null, ?string $register = null, ?string $schema = null): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setEnabled($enabled);
		$flow->setNodes([]);
		$flow->setEdges([]);
		$flow->setTrigger($trigger);
		$flow->setTriggerRegister($register);
		$flow->setTriggerSchema($schema);

		return $flow;
	}//end flow()

	private function triggerNode(string $id, string $event, string $register, string $schema): array {
		return ['id' => $id, 'type' => 'openregister.trigger-object', 'config' => ['event' => $event, 'register' => $register, 'schema' => $schema]];
	}//end triggerNode()

	public function testAFlowWithoutAUuidIsNotIndexed(): void {
		$this->mapper->expects(self::never())->method('replaceFor');

		self::assertSame(0, $this->index()->reindex(flow: $this->flow(uuid: '  ')));
	}//end testAFlowWithoutAUuidIsNotIndexed()

	public function testIdentifiersAreWrittenAsSlugsAndCollapseOnceCanonical(): void {
		$this->graphs['flow-1'] = [
			'nodes' => [
				$this->triggerNode('t1', 'object.created', '5', '9'),
				$this->triggerNode('t2', 'object.created', 'cases', 'case'),
				$this->triggerNode('t3', 'object.updated', 'cases', 'case'),
			],
		];

		$rows = $this->index()->reindex(flow: $this->flow(uuid: 'flow-1', enabled: false));

		self::assertSame(2, $rows);
		self::assertSame(
			[
				'flow' => 'flow-1',
				'triggers' => [
					['event' => 'object.created', 'register' => 'cases', 'schema' => 'case'],
					['event' => 'object.updated', 'register' => 'cases', 'schema' => 'case'],
				],
				'enabled' => false,
			],
			$this->written[0]
		);
	}//end testIdentifiersAreWrittenAsSlugsAndCollapseOnceCanonical()

	public function testWithoutAPublishedGraphTheFlowHasNoTriggerRows(): void {
		$withHead = $this->flow(uuid: 'flow-2');
		$withHead->setNodes([$this->triggerNode('t1', 'object.created', 'cases', 'case')]);

		self::assertSame(0, $this->index()->reindex(flow: $withHead), 'an unpublished head is not indexed');
		self::assertSame(0, $this->index(withPublished: false)->reindex(flow: $withHead), 'no published-graph service at all');
		self::assertSame([[], []], array_column($this->written, 'triggers'));
		self::assertSame([true, true], array_column($this->written, 'enabled'));
	}//end testWithoutAPublishedGraphTheFlowHasNoTriggerRows()

	public function testAFailedWriteIsLoggedAndCountsAsNothing(): void {
		$mapper = $this->createMock(FlowTriggerMapperInterface::class);
		$mapper->method('replaceFor')->willThrowException(new RuntimeException('deadlock'));
		$this->mapper = $mapper;
		$this->logger->expects(self::once())->method('warning')
			->with(self::stringContains('Could not index flow "flow-3": deadlock'));

		self::assertSame(0, $this->index()->reindex(flow: $this->flow(uuid: 'flow-3')));
	}//end testAFailedWriteIsLoggedAndCountsAsNothing()

	public function testForgetDropsTheRowsAndAbsorbsAFailure(): void {
		$this->mapper->expects(self::exactly(2))->method('deleteFor')
			->willReturnCallback(static function (string $flowUuid): int {
				if ($flowUuid === 'broken') {
					throw new RuntimeException('gone away');
				}

				return 3;
			});
		$this->logger->expects(self::once())->method('warning')
			->with(self::stringContains('Could not drop trigger rows for "broken": gone away'));

		$index = $this->index();
		$index->forget(flowUuid: 'flow-1');
		$index->forget(flowUuid: 'broken');
	}//end testForgetDropsTheRowsAndAbsorbsAFailure()

	public function testRebuildReportsWhatIsIndexedAndWhyTheRestIsNot(): void {
		$this->graphs['indexed'] = ['nodes' => [$this->triggerNode('t1', 'object.created', 'cases', 'case'), $this->triggerNode('t2', 'object.deleted', 'cases', 'case')]];

		$report = $this->index()->rebuild(flows: [
			$this->flow(uuid: 'indexed'),
			$this->flow(uuid: 'manual'),
			$this->flow(uuid: 'any-register', trigger: 'object.created', register: null, schema: 'case'),
			$this->flow(uuid: 'any-schema', trigger: 'object.updated', register: 'cases', schema: '*'),
			$this->flow(uuid: 'scoped', trigger: 'object.created', register: 'cases', schema: 'case'),
			$this->flow(uuid: 'scheduled', trigger: 'schedule.daily', register: null, schema: null),
		]);

		self::assertSame(1, $report['indexed']);
		self::assertSame(2, $report['rows']);
		self::assertSame(['any-register', 'any-schema', 'scoped', 'scheduled'], array_column($report['unconverted'], 'uuid'));
		self::assertStringStartsWith('BLOCKED: an object trigger scoped to any register/schema', $report['unconverted'][0]['reason']);
		self::assertStringStartsWith('BLOCKED:', $report['unconverted'][1]['reason']);
		self::assertSame('not yet re-authored on the canvas: add a trigger node for "object.created"', $report['unconverted'][2]['reason']);
		self::assertSame('not yet re-authored on the canvas: add a trigger node for "schedule.daily"', $report['unconverted'][3]['reason']);
		self::assertSame(['event' => 'object.created', 'register' => 'cases', 'schema' => 'case'], $report['unconverted'][2]['columns']);
	}//end testRebuildReportsWhatIsIndexedAndWhyTheRestIsNot()
}//end class
