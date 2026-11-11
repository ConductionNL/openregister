<?php

/**
 * FlowTimerMapperCalendarTest: the open timers that keep a working calendar from being deleted.
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

use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapper;
use OCA\OpenRegister\Tests\Unit\Db\Support\RecordsQueries;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\FlowTimerMapper
 * @uses   \OCA\OpenRegister\Db\FlowTimer
 */
class FlowTimerMapperCalendarTest extends TestCase {
	use RecordsQueries;

	public function testOpenTimersOfACalendarAreArmedOrSuspendedOnlyOldestFirst(): void {
		$this->rowSets = [[['id' => 2, 'uuid' => 't2', 'state' => 'armed'], ['id' => 5, 'uuid' => 't5', 'state' => 'suspended']]];

		$timers = (new FlowTimerMapper($this->recordingConnection()))->findOpenByCalendarSlug('nl-2026', 10);

		$this->assertSame(['t2', 't5'], array_map(static fn (FlowTimer $timer): ?string => $timer->getUuid(), $timers));
		$this->assertSame(
			[
				'select(*)',
				'from(openregister_flow_timers)',
				"where(eq(calendar_slug, 'nl-2026'))",
				'andWhere(in(state, ["armed","suspended"]))',
				'orderBy(id, ASC)',
				'setMaxResults(10)',
				'executeQuery',
			],
			$this->queries[0]
		);
	}//end testOpenTimersOfACalendarAreArmedOrSuspendedOnlyOldestFirst()

	public function testTheOpenTimerCountIsCountedInTheDatabaseNotFromACappedPage(): void {
		$this->rowSets = [[['open_timers' => '1042']]];

		$this->assertSame(1042, (new FlowTimerMapper($this->recordingConnection()))->countOpenByCalendarSlug('nl-2026'));
		$this->assertSame(
			[
				'select(count(*, open_timers))',
				'from(openregister_flow_timers)',
				"where(eq(calendar_slug, 'nl-2026'))",
				'andWhere(in(state, ["armed","suspended"]))',
				'executeQuery',
			],
			$this->queries[0]
		);
	}//end testTheOpenTimerCountIsCountedInTheDatabaseNotFromACappedPage()

	public function testACalendarNoTimerNamesCountsZero(): void {
		$this->rowSets = [[]];

		$this->assertSame(0, (new FlowTimerMapper($this->recordingConnection()))->countOpenByCalendarSlug('unused'));
	}//end testACalendarNoTimerNamesCountsZero()
}//end class
