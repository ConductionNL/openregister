<?php

/**
 * Stored flow definitions stop depending on the alias table.
 *
 * `FlowNodeRegistry` resolves `openregister.loop` and `openregister.stop`
 * through an alias its own docblock says is removed one release after the
 * rename. Nothing rewrote the stored definitions, so on the development
 * instance 13 nodes across 19 flows were still named `openregister.stop` —
 * working, silently, on a deadline nobody can see.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
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

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Repair\MigrateRenamedFlowNodeTypes;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Behaviour of the renamed-node-type rewrite.
 */
final class MigrateRenamedFlowNodeTypesTest extends TestCase {

	/**
	 * Flows the mapper was asked to update.
	 *
	 * @var Flow[]
	 */
	private array $updated = [];

	/**
	 * Run the step over the given flows and return them as the mapper saw them.
	 *
	 * @param Flow[] $flows The stored flows.
	 *
	 * @return void
	 */
	private function runOver(array $flows): void {
		$this->updated = [];

		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findAllFlows')->willReturnCallback(
			static function (?string $app = null, ?string $org = null, ?bool $enabled = null, int $limit = 100, int $offset = 0) use ($flows): array {
				return array_slice($flows, $offset, $limit);
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (Flow $flow): Flow {
				$this->updated[] = $flow;
				return $flow;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		(new MigrateRenamedFlowNodeTypes($container, new NullLogger()))
			->run($this->createMock(IOutput::class));

	}//end runOver()

	/**
	 * Build a flow with the given node types.
	 *
	 * @param string[] $types The node type ids.
	 * @param string $uuid The flow uuid.
	 *
	 * @return Flow The flow.
	 */
	private function flowWith(array $types, string $uuid = 'flow-1'): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setNodes(
			array_map(
				static fn (string $type, int $i): array => ['id' => 'n' . $i, 'type' => $type],
				$types,
				array_keys($types)
			)
		);

		return $flow;
	}//end flowWith()

	/**
	 * An old node id is rewritten to its current one.
	 *
	 * @return void
	 */
	public function testAnAliasedTypeIsRewritten(): void {
		$this->runOver([$this->flowWith(['openregister.stop'])]);

		$this->assertCount(1, $this->updated);
		$this->assertSame('openregister.end', $this->updated[0]->getNodes()[0]['type']);

	}//end testAnAliasedTypeIsRewritten()

	/**
	 * Both pairs in the map are handled, not just the one that prompted this.
	 *
	 * @return void
	 */
	public function testEveryPairInTheMapIsRewritten(): void {
		$this->runOver([$this->flowWith(['openregister.loop', 'openregister.stop'])]);

		$this->assertCount(1, $this->updated);
		$types = array_column($this->updated[0]->getNodes(), 'type');
		$this->assertSame(['openregister.batch', 'openregister.end'], $types);

	}//end testEveryPairInTheMapIsRewritten()

	/**
	 * A flow with nothing to rewrite is NOT written.
	 *
	 * A blanket save would move every flow's `updated` timestamp for a no-op,
	 * which is the kind of churn that makes "what changed and when"
	 * unanswerable — and it would make a second run indistinguishable from a
	 * first.
	 *
	 * @return void
	 */
	public function testAFlowWithNoAliasedTypesIsNotWritten(): void {
		$this->runOver([$this->flowWith(['openregister.end', 'openregister.route'])]);

		$this->assertSame([], $this->updated);

	}//end testAFlowWithNoAliasedTypesIsNotWritten()

	/**
	 * Only the aliased nodes change; their neighbours are untouched.
	 *
	 * @return void
	 */
	public function testUnrelatedNodesAreLeftAlone(): void {
		$this->runOver([$this->flowWith(['openregister.route', 'openregister.stop', 'hermiq.agent-step'])]);

		$types = array_column($this->updated[0]->getNodes(), 'type');
		$this->assertSame(['openregister.route', 'openregister.end', 'hermiq.agent-step'], $types);

	}//end testUnrelatedNodesAreLeftAlone()

	/**
	 * Running twice rewrites nothing the second time.
	 *
	 * @return void
	 */
	public function testASecondRunIsANoop(): void {
		$flow = $this->flowWith(['openregister.stop']);

		$this->runOver([$flow]);
		$this->assertCount(1, $this->updated);

		// `$flow` now carries the rewritten types, exactly as the stored row
		// would on the next upgrade.
		$this->runOver([$flow]);
		$this->assertSame([], $this->updated, 'a second run must not write anything');

	}//end testASecondRunIsANoop()

	/**
	 * A malformed node entry is skipped rather than fataling the upgrade.
	 *
	 * @return void
	 */
	public function testAMalformedNodeDoesNotBreakTheRun(): void {
		$flow = new Flow();
		$flow->setUuid('flow-odd');
		$flow->setNodes(['not-an-array', ['id' => 'n1', 'type' => 'openregister.stop'], ['id' => 'n2']]);

		$this->runOver([$flow]);

		$this->assertCount(1, $this->updated);
		$this->assertSame('openregister.end', $this->updated[0]->getNodes()[1]['type']);

	}//end testAMalformedNodeDoesNotBreakTheRun()

	/**
	 * The step reads the registry's map rather than carrying its own copy.
	 *
	 * This is what makes the retirement order correct by construction: drop a
	 * pair from `FlowNodeRegistry::RENAMED` and this step stops rewriting it in
	 * the same commit. A second copy would keep rewriting a name nothing
	 * answers to any more.
	 *
	 * @return void
	 */
	public function testTheMapComesFromTheRegistry(): void {
		$renamed = FlowNodeRegistry::renamedTypes();

		$this->assertNotSame([], $renamed, 'the registry should still declare aliases');

		foreach ($renamed as $old => $new) {
			$this->runOver([$this->flowWith([$old])]);
			$this->assertCount(1, $this->updated, $old . ' should have been rewritten');
			$this->assertSame($new, $this->updated[0]->getNodes()[0]['type']);
		}

	}//end testTheMapComesFromTheRegistry()
}//end class
