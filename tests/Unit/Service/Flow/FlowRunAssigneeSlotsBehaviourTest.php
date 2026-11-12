<?php

/**
 * FlowRunAssignee: unreadable slots, an empty typed reference, and non-slot entries in the resume state.
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
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunAssignee;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunAssignee
 * @uses \OCA\OpenRegister\Db\FlowRun
 * @uses \OCA\OpenRegister\Service\Flow\Principal\PrincipalReference
 */
class FlowRunAssigneeSlotsBehaviourTest extends TestCase {

	private function flowRun(mixed $slots): FlowRun {
		$run = new FlowRun();
		$run->setContext([FlowResumeState::CONTEXT_KEY => $slots]);

		return $run;
	}//end flowRun()

	public function testSlotsThatAreNotAMapRecordNobody(): void {
		self::assertSame('', (new FlowRunAssignee())->recordedFor(run: $this->flowRun('corrupt')));
	}//end testSlotsThatAreNotAMapRecordNobody()

	public function testAnEmptyReferenceListRendersAsNobody(): void {
		$run = $this->flowRun(['ask' => ['askedAt' => '2026-01-01T00:00:00+00:00', 'assignee' => []]]);

		self::assertSame('', (new FlowRunAssignee())->recordedFor(run: $run, nodeId: 'ask'));
	}//end testAnEmptyReferenceListRendersAsNobody()

	public function testNonSlotEntriesAreSkippedOnTheWayToTheAskingSlot(): void {
		$run = $this->flowRun(['garbage' => 'x', 'done' => ['assignee' => 'carol'], 'ask' => ['askedAt' => '2026-01-01T00:00:00+00:00', 'assignee' => ' dave ']]);

		self::assertSame('dave', (new FlowRunAssignee())->recordedFor(run: $run));
	}//end testNonSlotEntriesAreSkippedOnTheWayToTheAskingSlot()
}//end class
