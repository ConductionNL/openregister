<?php

/**
 * Golden test for NotificationTriggerValidator::validate().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Notifications\Service\Notification\NotificationTriggerValidator;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterParser;
use PHPUnit\Framework\TestCase;

/**
 * Pins the exact error list, in order and with every key, for triggers that
 * reach each branch of the trigger validator. Expected values were recorded
 * from the code before its refactor and live in
 * `fixtures/notification-trigger-validator-golden.json`.
 *
 * @coversDefaultClass \OCA\OpenRegister\Notifications\Service\Notification\NotificationTriggerValidator
 */
class NotificationTriggerValidatorGoldenTest extends TestCase {

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	public static function goldenPath(): string {
		return __DIR__.'/fixtures/notification-trigger-validator-golden.json';
	}//end goldenPath()

	/**
	 * The corpus: case name => trigger.
	 *
	 * @return array<string, mixed>
	 */
	public static function corpus(): array {
		$sched = ['type' => 'scheduled', 'intervalSec' => 60];
		$calc  = ['type' => 'calculatedChange', 'field' => 'f'];
		return [
			'null'                 => null,
			'int'                  => 5,
			'empty-array'          => [],
			'created'              => ['type' => 'created'],
			'updated'              => ['type' => 'updated', 'action' => 'x'],
			'transition'           => ['type' => 'transition'],
			'bad-type'             => ['type' => 'deleted'],
			'int-type'             => ['type' => 7],
			'event-string-ok'      => 'booking.confirmed',
			'event-camel-ok'       => 'lifecycle.optIn',
			'event-deep-ok'        => 'a.b-c.d9',
			'event-empty-string'   => '',
			'event-reserved'       => 'created',
			'event-no-dot'         => 'Booking',
			'event-upper-start'    => 'Booking.confirmed',
			'event-trailing-dot'   => 'booking.',
			'event-underscore'     => 'booking.con_firmed',
			'event-object-ok'      => ['event' => 'a.b'],
			'event-object-empty'   => ['event' => ''],
			'event-object-int'     => ['event' => 12],
			'event-object-array'   => ['event' => ['x']],
			'event-object-false'   => ['event' => false],
			'event-object-reserved' => ['event' => 'threshold', 'type' => 'threshold'],
			'event-and-bad-type'   => ['type' => 'nope', 'event' => 'a.b'],
			'event-and-scheduled'  => ['type' => 'scheduled', 'event' => 'a.b'],
			'sched-none'           => ['type' => 'scheduled'],
			'sched-low'            => ['type' => 'scheduled', 'intervalSec' => 59],
			'sched-string'         => ['type' => 'scheduled', 'intervalSec' => '3600'],
			'sched-ok'             => $sched,
			'sched-filter-string'  => $sched + ['filter' => 'x'],
			'sched-filter-null'    => $sched + ['filter' => null],
			'sched-filter-ok'      => $sched + ['filter' => ['status' => 'open']],
			'sched-filter-bad'     => $sched + ['filter' => ['status' => ['bogus' => 1]]],
			'sched-dedupe-string'  => $sched + ['dedupeFields' => 'x'],
			'sched-dedupe-empty'   => $sched + ['dedupeFields' => []],
			'sched-dedupe-blank'   => $sched + ['dedupeFields' => ['a', '', 3]],
			'sched-dedupe-int'     => $sched + ['dedupeFields' => [3, 'a']],
			'sched-dedupe-keys'    => $sched + ['dedupeFields' => ['k' => 'a', 'm' => null]],
			'sched-dedupe-ok'      => $sched + ['dedupeFields' => ['a', 'b']],
			'sched-all-bad'        => ['type' => 'scheduled', 'intervalSec' => 1, 'filter' => 3, 'dedupeFields' => false],
			'thr-empty'            => ['type' => 'threshold'],
			'thr-bad-op'           => ['type' => 'threshold', 'aggregation' => 'n', 'op' => 'between', 'value' => 1],
			'thr-null-value'       => ['type' => 'threshold', 'aggregation' => 'n', 'op' => 'gte', 'value' => null],
			'thr-int-agg'          => ['type' => 'threshold', 'aggregation' => 0, 'op' => 'eq', 'value' => 0],
			'calc-empty'           => ['type' => 'calculatedChange'],
			'calc-int-field'       => ['type' => 'calculatedChange', 'field' => 4],
			'calc-ok'              => $calc + ['condition' => ['lt' => 1], 'previously' => ['gte' => 1]],
			'calc-null-clauses'    => $calc + ['condition' => null, 'previously' => null],
			'calc-bad-clauses'     => $calc + ['condition' => [], 'previously' => 'x'],
			'calc-bad-ops'         => $calc + ['condition' => ['lt' => 1, 'foo' => 2, 0 => 3], 'previously' => ['bar' => 1, 'ne' => 2]],
			'calc-all-bad'         => ['type' => 'calculatedChange', 'condition' => 5, 'previously' => ['x' => 1]],
		];
	}//end corpus()

	/**
	 * Everything pinned: case => error list.
	 *
	 * @return array<string, mixed>
	 */
	public static function observe(): array {
		$validator = new NotificationTriggerValidator(new ScheduledFilterParser());
		$out       = [];
		foreach (self::corpus() as $name => $trigger) {
			$out[$name] = $validator->validate('rule-'.$name, $trigger);
		}

		return $out;
	}//end observe()

	/**
	 * The validator still produces the recorded error lists.
	 *
	 * @return void
	 */
	public function testMatchesGolden(): void {
		$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
		$actual = json_decode((string) json_encode(self::observe()), true);
		$this->assertSame($golden, $actual);
	}//end testMatchesGolden()

	/**
	 * The golden covers the corpus and mostly pins faults, not silence.
	 *
	 * @return void
	 */
	public function testGoldenIsNotTrivial(): void {
		$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
		$this->assertSame(array_keys(self::corpus()), array_keys($golden));
		$this->assertGreaterThanOrEqual(30, count(array_filter($golden)));
	}//end testGoldenIsNotTrivial()
}//end class
