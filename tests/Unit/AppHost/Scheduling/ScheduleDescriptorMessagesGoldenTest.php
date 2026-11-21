<?php

declare(strict_types=1);

/**
 * Golden of ScheduleDescriptor::fromArray(): the refusal message of every
 * invalid schedule entry, and the cadence of every valid one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\AppHost\Scheduling;

use OCA\OpenRegister\AppHost\Scheduling\ScheduleDescriptor;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Pins what each schedule entry parses to, or why it is refused.
 */
class ScheduleDescriptorMessagesGoldenTest extends TestCase {

	/**
	 * Raw entry → outcome.
	 *
	 * @return array<string, array{0: array, 1: string}>
	 */
	public static function entries(): array {
		return [
			'no id' => [['action' => 'a', 'interval' => 60], 'ERR Schedule #3 is missing a non-empty "id".'],
			'blank id' => [['id' => ' ', 'action' => 'a', 'interval' => 60], 'ERR Schedule #3 is missing a non-empty "id".'],
			'no action' => [['id' => 's', 'interval' => 60], 'ERR Schedule "s" is missing a non-empty "action".'],
			'neither' => [['id' => 's', 'action' => 'a'], 'ERR Schedule "s" must declare exactly one of "interval" or "cron".'],
			'both' => [['id' => 's', 'action' => 'a', 'interval' => 60, 'cron' => '* * * * *'], 'ERR Schedule "s" must declare exactly one of "interval" or "cron".'],
			'null interval and cron' => [['id' => 's', 'action' => 'a', 'interval' => null, 'cron' => null], 'ERR Schedule "s" must declare exactly one of "interval" or "cron".'],
			'zero interval' => [['id' => 's', 'action' => 'a', 'interval' => 0], 'ERR Schedule "s" has an invalid "interval" (must be a positive integer of seconds).'],
			'string interval' => [['id' => 's', 'action' => 'a', 'interval' => '60'], 'ERR Schedule "s" has an invalid "interval" (must be a positive integer of seconds).'],
			'empty cron' => [['id' => 's', 'action' => 'a', 'cron' => '  '], 'ERR Schedule "s" has an empty "cron" expression.'],
			'numeric cron' => [['id' => 's', 'action' => 'a', 'cron' => 5], 'ERR Schedule "s" has an empty "cron" expression.'],
			'interval' => [['id' => 's', 'action' => 'a', 'interval' => 60, 'cron' => null], 'OK s a interval=60 cron= args=[] enabled=1'],
			'cron with arguments' => [['id' => 's', 'action' => 'a', 'cron' => '0 * * * *', 'arguments' => ['x' => 1]], 'OK s a interval= cron=0 * * * * args={"x":1} enabled=1'],
			'scalar arguments, disabled' => [['id' => 's', 'action' => 'a', 'interval' => 5, 'arguments' => 'x', 'enabled' => false], 'OK s a interval=5 cron= args=[] enabled='],
		];
	}//end entries()

	/**
	 * @dataProvider entries
	 *
	 * @param array  $raw      The raw schedule entry.
	 * @param string $expected The outcome.
	 *
	 * @return void
	 */
	public function testEachEntryParsesOrIsRefused(array $raw, string $expected): void {
		try {
			$schedule = ScheduleDescriptor::fromArray(raw: $raw, index: 3);
			$outcome  = sprintf(
				'OK %s %s interval=%s cron=%s args=%s enabled=%s',
				$schedule->id,
				$schedule->action,
				(string)$schedule->intervalSeconds,
				(string)$schedule->cron,
				json_encode($schedule->arguments),
				(string)$schedule->enabled
			);
		} catch (ScheduleValidationException $e) {
			$outcome = 'ERR ' . $e->getMessage();
		}

		$this->assertSame($expected, $outcome);
	}//end testEachEntryParsesOrIsRefused()
}//end class
