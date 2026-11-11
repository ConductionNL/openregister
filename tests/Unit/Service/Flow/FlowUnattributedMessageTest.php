<?php

/**
 * Golden coverage for the message a FlowUnattributed refusal carries.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowUnattributed;
use PHPUnit\Framework\TestCase;

/**
 * Locks the refusal text, with and without a stated reason.
 */
final class FlowUnattributedMessageTest extends TestCase {

	/**
	 * Without a reason the trigger is named; with one the reason is used as it is.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesTheFlowAndItsReason(): void {
		$this->assertSame(
			'Refusing to start flow "f-1": the "schedule" trigger named no acting identity. '
			. 'A run started by a person acts as that person; a run started on a schedule acts as the '
			. 'user its schedule trigger declares in "runAs". The flow\'s owner is not used as a '
			. 'fallback — authoring a flow is not consent to unattended execution as its author.',
			(new FlowUnattributed('f-1', 'schedule'))->getMessage()
		);

		$this->assertSame(
			'Refusing to start flow "f-1": it left its tenant.',
			(new FlowUnattributed('f-1', 'schedule', 'it left its tenant.'))->getMessage()
		);
	}//end testTheRefusalNamesTheFlowAndItsReason()
}//end class
