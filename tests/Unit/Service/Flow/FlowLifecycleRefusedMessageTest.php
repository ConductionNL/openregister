<?php

/**
 * Golden coverage for the message a FlowLifecycleRefused carries.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowLifecycleRefused;
use PHPUnit\Framework\TestCase;

/**
 * Locks the message per reason, with its flow, state and detail.
 */
final class FlowLifecycleRefusedMessageTest extends TestCase {

	/**
	 * Every reason, and an unknown one, reads its own sentence followed by the flow.
	 *
	 * @return void
	 */
	public function testEveryReasonReadsItsOwnSentence(): void {
		$expected = [
			FlowLifecycleRefused::REASON_IMMUTABLE => 'This version of the flow is published and cannot be edited. Create a draft version to make changes; the published version keeps serving until you publish the draft.',
			FlowLifecycleRefused::REASON_NOT_A_DRAFT => 'Only a draft version can be published.',
			FlowLifecycleRefused::REASON_NOT_PUBLISHED => 'Only a published version can be deprecated.',
			FlowLifecycleRefused::REASON_NO_PUBLISHED_VERSION => 'This flow has no published version, so it cannot back a run. Publish a version first.',
			FlowLifecycleRefused::REASON_DEAD_END => 'This version cannot be published while it has a node a token cannot leave.',
			FlowLifecycleRefused::REASON_VERSION_IN_USE => 'This version cannot be removed while a run is still pinned to it.',
			'something-else' => 'This flow lifecycle transition was refused.',
		];

		foreach ($expected as $reason => $sentence) {
			$refused = new FlowLifecycleRefused($reason, 'f-1');
			$this->assertSame($sentence . ' (flow f-1)', $refused->getMessage());
			$this->assertSame($reason, $refused->getReason());
		}
	}//end testEveryReasonReadsItsOwnSentence()

	/**
	 * The state is named after the flow, and a non-blank detail is appended.
	 *
	 * @return void
	 */
	public function testTheStateAndDetailAreAppended(): void {
		$refused = new FlowLifecycleRefused(FlowLifecycleRefused::REASON_NOT_A_DRAFT, 'f-2', 'published', 'Try v3.');
		$this->assertSame('Only a draft version can be published. (flow f-2, state published) Try v3.', $refused->getMessage());

		$blank = new FlowLifecycleRefused(FlowLifecycleRefused::REASON_NOT_A_DRAFT, 'f-2', null, '   ');
		$this->assertSame('Only a draft version can be published. (flow f-2)', $blank->getMessage());
	}//end testTheStateAndDetailAreAppended()
}//end class
