<?php

/**
 * Which firings a flow's object triggers accept once the index has matched.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-trigger-transitions-and-changed-fields/specs/flow-engine/spec.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowTriggerFilter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowTriggerFilter
 */
final class FlowTriggerFilterTest extends TestCase {

	private FlowTriggerFilter $filter;

	protected function setUp(): void {
		parent::setUp();
		$this->filter = new FlowTriggerFilter();
	}//end setUp()

	/**
	 * A graph with one object trigger with this config.
	 *
	 * @param array<string, mixed> $config The trigger config.
	 *
	 * @return array<string, mixed>
	 */
	private function graph(array $config): array {
		return [
			'nodes' => [
				['id' => 't', 'type' => 'openregister.trigger-object', 'config' => $config + ['register' => 'zaken', 'schema' => 'aanvraag']],
				['id' => 'n', 'type' => 'openregister.noop', 'config' => []],
			],
			'edges' => [],
		];
	}//end graph()

	/**
	 * A flow without an object trigger for the event (a legacy column flow), or without a graph, is accepted.
	 *
	 * @return void
	 */
	public function testAFlowWithoutAMatchingTriggerIsAccepted(): void {
		$this->assertTrue($this->filter->accepts(graph: null, event: 'object.updated', context: []));
		$this->assertTrue($this->filter->accepts(graph: ['nodes' => [], 'edges' => []], event: 'object.updated', context: []));
		$this->assertTrue(
			$this->filter->accepts(graph: $this->graph(['event' => 'object.created', 'changedFields' => ['a']]), event: 'object.updated', context: ['changedFields' => []])
		);
	}//end testAFlowWithoutAMatchingTriggerIsAccepted()

	/**
	 * A transition filter starts the flow only when every list it holds contains the transition's value.
	 *
	 * @return void
	 */
	public function testTheTransitionFilterMatchesEveryListItHolds(): void {
		$closing = ['action' => 'close', 'from' => 'open', 'to' => 'closed'];
		$reopening = ['action' => 'reopen', 'from' => 'closed', 'to' => 'open'];

		$toClosed = $this->graph(['event' => 'object.transitioned', 'transition' => ['to' => ['closed']]]);
		$this->assertTrue($this->filter->accepts(graph: $toClosed, event: 'object.transitioned', context: $closing));
		$this->assertFalse($this->filter->accepts(graph: $toClosed, event: 'object.transitioned', context: $reopening));

		$all = $this->graph(['event' => 'object.transitioned', 'transition' => ['actions' => ['close'], 'from' => ['open'], 'to' => ['closed', 'rejected']]]);
		$this->assertTrue($this->filter->accepts(graph: $all, event: 'object.transitioned', context: $closing));
		$this->assertFalse($this->filter->accepts(graph: $all, event: 'object.transitioned', context: ['from' => 'draft'] + $closing));

		$any = $this->graph(['event' => 'object.transitioned', 'transition' => []]);
		$this->assertTrue($this->filter->accepts(graph: $any, event: 'object.transitioned', context: $reopening));
	}//end testTheTransitionFilterMatchesEveryListItHolds()

	/**
	 * changedFields starts the flow when at least one watched field changed, and never without the list.
	 *
	 * @return void
	 */
	public function testChangedFieldsNeedsAWatchedFieldToHaveChanged(): void {
		$watching = $this->graph(['event' => 'object.updated', 'changedFields' => ['omschrijving']]);
		$this->assertTrue($this->filter->accepts(graph: $watching, event: 'object.updated', context: ['changedFields' => ['status', 'omschrijving']]));
		$this->assertFalse($this->filter->accepts(graph: $watching, event: 'object.updated', context: ['changedFields' => ['categorie']]));
		$this->assertFalse($this->filter->accepts(graph: $watching, event: 'object.updated', context: ['changedFields' => []]));
		$this->assertFalse($this->filter->accepts(graph: $watching, event: 'object.updated', context: []));
		$this->assertFalse($this->filter->accepts(graph: $watching, event: 'object.updated', context: ['changedFields' => 'omschrijving']));
	}//end testChangedFieldsNeedsAWatchedFieldToHaveChanged()

	/**
	 * An unfiltered trigger on the event accepts every firing.
	 *
	 * @return void
	 */
	public function testAnUnfilteredTriggerAcceptsEverything(): void {
		$this->assertTrue($this->filter->accepts(graph: $this->graph(['event' => 'object.updated']), event: 'object.updated', context: []));
	}//end testAnUnfilteredTriggerAcceptsEverything()

	/**
	 * With two triggers on the event, one accepting is enough.
	 *
	 * @return void
	 */
	public function testOneAcceptingTriggerOfSeveralIsEnough(): void {
		$graph = $this->graph(['event' => 'object.updated', 'changedFields' => ['a']]);
		$graph['nodes'][] = ['id' => 't2', 'type' => 'openregister.trigger-object', 'config' => ['event' => 'object.updated', 'changedFields' => ['b']]];

		$this->assertTrue($this->filter->accepts(graph: $graph, event: 'object.updated', context: ['changedFields' => ['b']]));
		$this->assertFalse($this->filter->accepts(graph: $graph, event: 'object.updated', context: ['changedFields' => ['c']]));
	}//end testOneAcceptingTriggerOfSeveralIsEnough()
}//end class
