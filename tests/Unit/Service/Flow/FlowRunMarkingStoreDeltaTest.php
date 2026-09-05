<?php

/**
 * The marking store writes deltas, never whole values: two writers that both
 * read `{a, b}` before either writes leave BOTH effects committed.
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
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Flow\FlowRunMarkingStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Marking;
use stdClass;

/**
 * Delta writes.
 */
class FlowRunMarkingStoreDeltaTest extends TestCase {

	public function testASiblingsCommittedEffectSurvivesThisProcessesNextWrite(): void {
		// This process read {a, b} at the top of its pass. A sibling pass then
		// committed T2 (-b +d) and the commit path synced that read back; the
		// next in-process apply of T1 (-a +c) must be a DELTA on the synced
		// view, so d survives and b is not resurrected.
		$run = new FlowRun();
		$run->setMarking(['a' => 1, 'b' => 1]);
		$store = new FlowRunMarkingStore(run: $run);
		$subject = new stdClass();

		$store->syncCommitted(marking: ['a' => 1, 'd' => 1]);

		// Symfony's apply() re-reads the marking immediately before writing.
		$marking = $store->getMarking(subject: $subject);
		$marking->unmark('a');
		$marking->mark('c');
		$store->setMarking(subject: $subject, marking: $marking);

		$this->assertSame(['consumed' => ['a'], 'produced' => ['c']], $store->lastDelta());
		$this->assertEqualsCanonicalizing(['c' => 1, 'd' => 1], $run->getMarking());
	}//end testASiblingsCommittedEffectSurvivesThisProcessesNextWrite()

	public function testASingleStreamWriteIsIdenticalToBefore(): void {
		$run = new FlowRun();
		$run->setMarking(['start' => 1]);
		$store = new FlowRunMarkingStore(run: $run);
		$subject = new stdClass();

		$marking = $store->getMarking(subject: $subject);
		$marking->unmark('start');
		$marking->mark('next');
		$store->setMarking(subject: $subject, marking: $marking);

		$this->assertSame(['next' => 1], $run->getMarking());
	}//end testASingleStreamWriteIsIdenticalToBefore()

	public function testSyncCommittedReplacesTheViewWithTheLockedRead(): void {
		$run = new FlowRun();
		$run->setMarking(['a' => 1]);
		$store = new FlowRunMarkingStore(run: $run);
		$store->syncCommitted(marking: ['x' => 2, 'y' => 1]);

		$this->assertSame(['x' => 2, 'y' => 1], $run->getMarking());
	}//end testSyncCommittedReplacesTheViewWithTheLockedRead()

	public function testTheWholeValueWriteNoLongerExists(): void {
		// The acceptance criterion, asserted rather than argued: no line of the
		// store assigns `$marking->getPlaces()` onto the run wholesale.
		$source = (string)file_get_contents((new \ReflectionClass(FlowRunMarkingStore::class))->getFileName());
		$this->assertStringNotContainsString('setMarking($marking->getPlaces())', $source);
	}//end testTheWholeValueWriteNoLongerExists()
}//end class
