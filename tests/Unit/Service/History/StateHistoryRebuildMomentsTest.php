<?php

/**
 * Unit tests for how recorded moments and cleared states shape a rebuilt state history.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\History
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\History;

use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\StateHistoryMapperInterface;
use OCA\OpenRegister\Service\History\StateHistoryRebuild;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A moment recorded as a DateTime is used as is; a change without a moment is skipped;
 * a change that clears the state ends the previous interval and starts none.
 *
 * @covers \OCA\OpenRegister\Service\History\StateHistoryRebuild
 */
class StateHistoryRebuildMomentsTest extends TestCase {

	/**
	 * The rebuild under test.
	 *
	 * @return StateHistoryRebuild
	 */
	private function rebuild(): StateHistoryRebuild {
		return new StateHistoryRebuild(
			$this->createMock(StateHistoryMapperInterface::class),
			$this->createMock(AuditTrailMapperInterface::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end rebuild()

	/**
	 * Intervals as [value, entered, left] in a fixed UTC rendering.
	 *
	 * @param list<array{value: string, enteredAt: ?DateTime, leftAt: ?DateTime}> $intervals The intervals.
	 *
	 * @return list<array{0: string, 1: ?string, 2: ?string}>
	 */
	private function flat(array $intervals): array {
		return array_map(
			static fn (array $i): array => [$i['value'], $i['enteredAt']?->format('Y-m-d'), $i['leftAt']?->format('Y-m-d')],
			$intervals
		);

	}//end flat()

	/**
	 * DateTime stamps are used as they are, stamp-less changes are skipped, a cleared state starts no interval.
	 *
	 * @return void
	 */
	public function testMomentsAndClearedStates(): void {
		$changes = [
			['changed' => ['status' => ['old' => null, 'new' => 'open']], 'created' => new DateTime('2026-09-01T00:00:00+00:00')],
			['changed' => ['status' => ['old' => 'open', 'new' => 'parked']], 'created' => null],
			['changed' => ['status' => ['old' => 'open', 'new' => 'parked']], 'created' => '   '],
			['changed' => ['status' => ['old' => 'open', 'new' => '']], 'created' => '2026-09-05T00:00:00+00:00'],
			['changed' => ['status' => ['old' => '', 'new' => 'closed']], 'created' => '2026-09-09T00:00:00+00:00'],
		];

		$this->assertSame(
			[
				['open', '2026-09-01', '2026-09-05'],
				['closed', '2026-09-09', null],
			],
			$this->flat($this->rebuild()->intervalsFor($changes, 'status'))
		);

	}//end testMomentsAndClearedStates()
}//end class
