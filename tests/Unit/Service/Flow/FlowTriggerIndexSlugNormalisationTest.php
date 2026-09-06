<?php

/**
 * The index writer normalises every derived trigger to the SLUG vocabulary.
 *
 * 🔴 THE DEFECT THIS PINS DOWN: the index stored whatever a trigger node's
 * config held. A builder-authored node holding numeric ids wrote `16`/`26`
 * rows, an imported declaration wrote `dossiq`/`case` rows, and the fired
 * subject matched only one vocabulary — so which flows fired depended on which
 * editor their author had used. The writer is the one place both authoring
 * surfaces pass through, so the normalisation lives there, and the registered
 * `BackfillFlowTriggerIndex` repair (which rebuilds through this same writer)
 * is thereby also the migration for rows written before the fix.
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
 * @spec openspec/changes/flow-trigger-canonical-slugs/specs/flow-engine/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowTriggerMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraph;
use OCA\OpenRegister\Service\Flow\FlowTriggerDerivation;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use OCA\OpenRegister\Service\Flow\FlowTriggerSlugs;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowTriggerIndex
 * @covers \OCA\OpenRegister\Service\Flow\FlowTriggerSlugs
 *
 * @uses \OCA\OpenRegister\Db\Flow
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Flow\FlowTriggerDerivation
 */
class FlowTriggerIndexSlugNormalisationTest extends TestCase {

	/**
	 * Every replaceFor() call, in order: `{flow, triggers, enabled}`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * An index over a resolver that knows `16`=>`dossiq` and `26`=>`case`,
	 * whose published graph answers $nodes for every flow.
	 */
	private function index(array $nodes): FlowTriggerIndex {
		$mapper = $this->createMock(FlowTriggerMapper::class);
		$mapper->method('replaceFor')->willReturnCallback(
			function (string $flowUuid, array $triggers, bool $enabled): int {
				$this->written[] = ['flow' => $flowUuid, 'triggers' => $triggers, 'enabled' => $enabled];

				return count($triggers);
			}
		);

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			static function (string|int $id): Register {
				if (in_array((string)$id, ['16', 'dossiq'], true) === false) {
					throw new DoesNotExistException('no such register');
				}

				$register = new Register();
				$register->setSlug('dossiq');

				return $register;
			}
		);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			static function (string|int $id): Schema {
				if (in_array((string)$id, ['26', 'case'], true) === false) {
					throw new DoesNotExistException('no such schema');
				}

				$schema = new Schema();
				$schema->setSlug('case');

				return $schema;
			}
		);

		$published = $this->createMock(FlowPublishedGraph::class);
		$published->method('graphOf')->willReturn(['nodes' => $nodes, 'edges' => []]);

		return new FlowTriggerIndex(
			$mapper,
			new FlowTriggerDerivation(),
			new FlowTriggerSlugs($registers, $schemas, new NullLogger()),
			new NullLogger(),
			$published
		);
	}

	/**
	 * One object trigger node whose config holds the given identifiers.
	 */
	private function triggerNode(string $register, string $schema): array {
		return [
			'id' => 'trigger-' . $register . '-' . $schema,
			'type' => 'openregister.trigger-object',
			'config' => ['event' => 'object.created', 'register' => $register, 'schema' => $schema],
		];
	}

	/**
	 * A stored, enabled flow.
	 */
	private function flow(string $uuid): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setEnabled(true);
		$flow->setNodes([]);
		$flow->setEdges([]);

		return $flow;
	}

	public function testATriggerNodeHoldingNumericIdsIndexesAsSlugs(): void {
		$this->index([$this->triggerNode('16', '26')])->reindex(flow: $this->flow('flow-1'));

		$this->assertSame(
			[['event' => 'object.created', 'register' => 'dossiq', 'schema' => 'case']],
			$this->written[0]['triggers'],
			'a builder-authored node holding row ids must produce the same slug row an imported declaration does'
		);
	}

	public function testTwoNodesNamingOneTripleThroughDifferentIdentifiersCollapse(): void {
		$this->index(
			[$this->triggerNode('16', '26'), $this->triggerNode('dossiq', 'case')]
		)->reindex(flow: $this->flow('flow-1'));

		$this->assertCount(
			1,
			$this->written[0]['triggers'],
			'`16/26` and `dossiq/case` are ONE subscription; two rows would queue two runs per event'
		);
	}

	public function testAnUnresolvableIdentifierIsWrittenAsIsNotBlanked(): void {
		$this->index([$this->triggerNode('99', 'gone')])->reindex(flow: $this->flow('flow-1'));

		$this->assertSame(
			[['event' => 'object.created', 'register' => '99', 'schema' => 'gone']],
			$this->written[0]['triggers'],
			'blanking an unresolvable identifier would silently unsubscribe the flow'
		);
	}

	/**
	 * 🔑 THE REPAIR PATH. `BackfillFlowTriggerIndex` (registered post-migration
	 * in appinfo/info.xml) calls exactly this `rebuild()`, which re-derives
	 * every flow's rows through the normalising writer — so an instance whose
	 * index still holds id-keyed rows from before the fix gets them rewritten
	 * as slugs on upgrade, and already-imported flows start firing without
	 * anyone re-saving them.
	 */
	public function testRebuildRewritesEveryFlowIntoTheSlugVocabulary(): void {
		$index = $this->index([$this->triggerNode('16', '26')]);

		$report = $index->rebuild(flows: [$this->flow('flow-1'), $this->flow('flow-2')]);

		$this->assertSame(2, $report['indexed']);
		$this->assertSame(2, $report['rows']);
		foreach ($this->written as $write) {
			$this->assertSame(
				[['event' => 'object.created', 'register' => 'dossiq', 'schema' => 'case']],
				$write['triggers'],
				'the rebuild must replace pre-fix rows with their slug form for every flow it touches'
			);
		}
	}
}
