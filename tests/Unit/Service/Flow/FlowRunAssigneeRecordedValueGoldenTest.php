<?php

/**
 * Characterisation tests for which recorded assignee a suspended run answers
 * to.
 *
 * Pins `FlowRunAssignee::recordedValueFor()` for every branch: an unreadable
 * slot map, an addressed node that is asking (with and without an assignee),
 * an addressed node that is not asking or is not a slot, whitespace around the
 * addressed id, and the run-level scan that skips non-slots, unasked slots and
 * unassigned ones. The recorded value is what the answer guard decides on.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunAssignee;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for the recorded assignee.
 */
class FlowRunAssigneeRecordedValueGoldenTest extends TestCase {

	/**
	 * A run whose context holds these slots.
	 *
	 * @param mixed $slots The slots value.
	 *
	 * @return FlowRun
	 */
	private function runWith(mixed $slots): FlowRun {
		$run = new FlowRun();
		$run->setContext([FlowResumeState::CONTEXT_KEY => $slots]);

		return $run;
	}//end runWith()

	/**
	 * Every branch of the recorded value, by addressed node.
	 *
	 * @return void
	 */
	public function testRecordedValueForEveryBranch(): void {
		$assignee = new FlowRunAssignee();
		$slots    = [
			'junk'     => 'not-a-slot',
			'unasked'  => ['assignee' => 'umar'],
			'open'     => ['askedAt' => 't1', 'assignee' => '  '],
			'bare'     => ['askedAt' => 't1'],
			'asking'   => ['askedAt' => 't2', 'assignee' => 'alice'],
			'later'    => ['askedAt' => 't3', 'assignee' => 'bob'],
		];
		$run = $this->runWith($slots);

		$cases = [
			'no node scans for the first assigned asking slot' => [null, 'alice'],
			'blank node scans'                                 => ['', 'alice'],
			'whitespace node scans'                            => ['   ', 'alice'],
			'addressed asking node decides'                    => ['later', 'bob'],
			'addressed node id is trimmed'                     => [' later ', 'bob'],
			'addressed unassigned node is open'                => ['open', '  '],
			'addressed node without assignee key is open'      => ['bare', ''],
			'addressed unasked node falls back'                => ['unasked', 'alice'],
			'addressed non-slot falls back'                    => ['junk', 'alice'],
			'unknown node falls back'                          => ['ghost', 'alice'],
		];
		foreach ($cases as $label => [$nodeId, $expected]) {
			$this->assertSame($expected, $assignee->recordedValueFor(run: $run, nodeId: $nodeId), $label);
		}
	}//end testRecordedValueForEveryBranch()

	/**
	 * Unreadable or empty slot maps record nobody.
	 *
	 * @return void
	 */
	public function testUnreadableSlotsRecordNobody(): void {
		$assignee = new FlowRunAssignee();

		$this->assertSame('', $assignee->recordedValueFor(run: $this->runWith('nope'), nodeId: 'a'));
		$this->assertSame('', $assignee->recordedValueFor(run: new FlowRun(), nodeId: null));
		$this->assertSame('', $assignee->recordedValueFor(run: $this->runWith(['a' => ['askedAt' => 't', 'assignee' => '']]), nodeId: null));
	}//end testUnreadableSlotsRecordNobody()
}//end class
