<?php

/**
 * FlowDispatchTest: whether a flow may dispatch, and which identity its schedule trigger runs as.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Flow;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Flow
 */
class FlowDispatchTest extends TestCase {

	/**
	 * A flow.
	 *
	 * @param bool        $enabled Whether it is enabled.
	 * @param string|null $owner   Its owner.
	 *
	 * @return Flow
	 */
	private static function flow(bool $enabled, ?string $owner): Flow {
		$flow = new Flow();
		$flow->setEnabled($enabled);
		if ($owner !== null) {
			$flow->setOwner($owner);
		}

		return $flow;
	}//end flow()

	public function testAnEnabledFlowWithAnOwnerMayDispatch(): void {
		$this->assertTrue(self::flow(true, 'ann')->canDispatch());
	}//end testAnEnabledFlowWithAnOwnerMayDispatch()

	public function testADisabledFlowNeverDispatches(): void {
		$this->assertFalse(self::flow(false, 'ann')->canDispatch());
	}//end testADisabledFlowNeverDispatches()

	public function testAnOwnerlessOrBlankOwnedFlowDoesNotDispatch(): void {
		$this->assertFalse(self::flow(true, null)->canDispatch());
		$this->assertFalse(self::flow(true, '  ')->canDispatch());
	}//end testAnOwnerlessOrBlankOwnedFlowDoesNotDispatch()

	public function testScheduledRunAsIsTakenFromTheFirstScheduleTrigger(): void {
		$flow = new Flow();
		$flow->setNodes(
			[
				['type' => 'openregister.trigger-object', 'config' => ['runAs' => 'wrong']],
				['type' => 'openregister.trigger-schedule', 'config' => ['runAs' => 'svc-batch']],
				['type' => 'openregister.trigger-schedule', 'config' => ['runAs' => 'later']],
			]
		);

		$this->assertSame('svc-batch', $flow->scheduledRunAs());
	}//end testScheduledRunAsIsTakenFromTheFirstScheduleTrigger()

	public function testAScheduleTriggerWithoutRunAsAndAFlowWithoutNodesHaveNone(): void {
		$withoutRunAs = new Flow();
		$withoutRunAs->setNodes([['type' => 'openregister.trigger-schedule', 'config' => []]]);

		$this->assertNull($withoutRunAs->scheduledRunAs());
		$this->assertNull((new Flow())->scheduledRunAs());
	}//end testAScheduleTriggerWithoutRunAsAndAFlowWithoutNodesHaveNone()
}//end class
