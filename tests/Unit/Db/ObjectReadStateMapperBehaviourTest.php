<?php

/**
 * ObjectReadStateMapper reads, marks, refreshes, races and clears per-user seen state.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\ObjectReadState;
use OCA\OpenRegister\Db\ObjectReadStateMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\ObjectReadStateMapper
 * @uses   \OCA\OpenRegister\Db\ObjectReadState
 */
class ObjectReadStateMapperBehaviourTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * A mapper whose connection throws on the listed query builder numbers.
	 *
	 * @param array<int, int> $failOn Builder numbers (1-based) whose creation throws.
	 *
	 * @return ObjectReadStateMapper
	 */
	private function mapper(array $failOn = []): ObjectReadStateMapper {
		$db = $this->createMock(IDBConnection::class);
		$calls = 0;
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use (&$calls, $failOn) {
				$calls++;
				if (in_array($calls, $failOn, true) === true) {
					throw new RuntimeException('unique violation');
				}

				return $this->recordingBuilder();
			}
		);
		return new ObjectReadStateMapper($db);
	}//end mapper()

	/**
	 * The whole query log as one string.
	 *
	 * @return string
	 */
	private function log(): string {
		return implode("\n", $this->queryLog);
	}//end log()

	/**
	 * A sighting for user anna on object o-1.
	 *
	 * @return ObjectReadState
	 */
	private function sighting(): ObjectReadState {
		$row = new ObjectReadState();
		$row->setUserId('anna');
		$row->setObjectUuid('o-1');
		return $row;
	}//end sighting()

	/**
	 * A stored row for anna on o-1.
	 *
	 * @param array<string, mixed> $extra Extra columns.
	 *
	 * @return array<string, mixed>
	 */
	private static function storedRow(array $extra = []): array {
		return array_merge(['id' => 9, 'user_id' => 'anna', 'object_uuid' => 'o-1'], $extra);
	}//end storedRow()

	/**
	 * findOne returns the row for the user and object.
	 *
	 * @return void
	 */
	public function testFindOneReturnsTheStoredRow(): void {
		$this->queuedRows = [[self::storedRow(['register' => 'r-1'])]];

		$found = $this->mapper()->findOne('anna', 'o-1');

		$this->assertSame(9, $found?->getId());
		$this->assertSame('r-1', $found?->getRegister());
		$log = $this->log();
		$this->assertStringContainsString('eq(user_id', $log);
		$this->assertStringContainsString('eq(object_uuid', $log);
		$this->assertStringContainsString('setMaxResults(1)', $log);
	}//end testFindOneReturnsTheStoredRow()

	/**
	 * findOne returns null when the user never saw the object.
	 *
	 * @return void
	 */
	public function testFindOneReturnsNullWhenUnseen(): void {
		$this->queuedRows = [[]];

		$this->assertNull($this->mapper()->findOne('anna', 'o-1'));
	}//end testFindOneReturnsNullWhenUnseen()

	/**
	 * The seen set drops empty and duplicate uuids and narrows to register and schema.
	 *
	 * @return void
	 */
	public function testUuidsForUserDedupesAndNarrows(): void {
		$this->queuedRows = [[
			['object_uuid' => 'a'],
			['object_uuid' => ''],
			['object_uuid' => null],
			['object_uuid' => 'b'],
			['object_uuid' => 'a'],
		]];

		$uuids = $this->mapper()->uuidsForUser('anna', new ListQuery(limit: 50, filters: ['register' => 'reg', 'schema' => 'sch']));

		$this->assertSame(['a', 'b'], $uuids);
		$log = $this->log();
		$this->assertStringContainsString('setMaxResults(50)', $log);
		$this->assertStringContainsString('eq(register', $log);
		$this->assertStringContainsString('eq(schema', $log);
	}//end testUuidsForUserDedupesAndNarrows()

	/**
	 * Without register or schema the seen set is not narrowed and uses the default cap.
	 *
	 * @return void
	 */
	public function testUuidsForUserWithoutScopeIsNotNarrowed(): void {
		$this->queuedRows = [[]];

		$this->assertSame([], $this->mapper()->uuidsForUser('anna', new ListQuery(limit: ObjectReadStateMapper::SEEN_SET_LIMIT, filters: ['register' => '', 'schema' => null])));
		$log = $this->log();
		$this->assertStringNotContainsString('eq(register', $log);
		$this->assertStringNotContainsString('eq(schema', $log);
		$this->assertStringContainsString('setMaxResults(20000)', $log);
	}//end testUuidsForUserWithoutScopeIsNotNarrowed()

	/**
	 * A first sighting inserts a row stamped now, with an empty sub-seen map.
	 *
	 * @return void
	 */
	public function testAFirstSightingInsertsARow(): void {
		$this->queuedRows = [[]];
		$sighting = $this->sighting();

		$saved = $this->mapper()->markSeen($sighting);

		$this->assertSame($sighting, $saved);
		$this->assertSame([], $saved->getSubSeen());
		$this->assertInstanceOf(DateTime::class, $saved->getLastSeenAt());
		$this->assertSame($saved->getLastSeenAt(), $saved->getCreated());
		$this->assertStringContainsString('insert(openregister_object_read_state', $this->log());
	}//end testAFirstSightingInsertsARow()

	/**
	 * A repeat sighting updates the stored row: time, scope and merged sub-seen.
	 *
	 * @return void
	 */
	public function testARepeatSightingRefreshesTheStoredRow(): void {
		$this->queuedRows = [[self::storedRow(['register' => 'old-reg', 'schema' => 'old-sch', 'sub_seen' => '{"notes":"t1"}'])]];
		$when = new DateTime('2026-04-05T06:07:08+00:00');
		$sighting = $this->sighting();
		$sighting->setLastSeenAt($when);
		$sighting->setRegister('new-reg');
		$sighting->setSchema('');
		$sighting->setSubSeen(['files' => 't2']);

		$saved = $this->mapper()->markSeen($sighting);

		$this->assertNotSame($sighting, $saved);
		$this->assertSame(9, $saved->getId());
		$this->assertSame($when, $saved->getLastSeenAt());
		$this->assertSame('new-reg', $saved->getRegister());
		$this->assertSame('old-sch', $saved->getSchema());
		$this->assertSame(['notes' => 't1', 'files' => 't2'], $saved->getSubSeen());
		$log = $this->log();
		$this->assertStringContainsString('update(openregister_object_read_state', $log);
		$this->assertStringNotContainsString('insert(', $log);
	}//end testARepeatSightingRefreshesTheStoredRow()

	/**
	 * A repeat sighting without sub-seen leaves the stored sub-seen and an empty register alone.
	 *
	 * @return void
	 */
	public function testARepeatSightingWithoutSubSeenKeepsTheStoredMap(): void {
		$this->queuedRows = [[self::storedRow(['register' => 'old-reg', 'schema' => 'old-sch', 'sub_seen' => '{"notes":"t1"}'])]];
		$sighting = $this->sighting();
		$sighting->setSchema('new-sch');

		$saved = $this->mapper()->markSeen($sighting);

		$this->assertSame(['notes' => 't1'], $saved->getSubSeen());
		$this->assertSame('old-reg', $saved->getRegister());
		$this->assertSame('new-sch', $saved->getSchema());
	}//end testARepeatSightingWithoutSubSeenKeepsTheStoredMap()

	/**
	 * When a concurrent request inserted first, the winner's row is returned.
	 *
	 * @return void
	 */
	public function testALostInsertRaceReturnsTheWinner(): void {
		$this->queuedRows = [[], [self::storedRow(['register' => 'winner'])]];

		$saved = $this->mapper([2])->markSeen($this->sighting());

		$this->assertSame(9, $saved->getId());
		$this->assertSame('winner', $saved->getRegister());
	}//end testALostInsertRaceReturnsTheWinner()

	/**
	 * A failed insert with no row to fall back on rethrows.
	 *
	 * @return void
	 */
	public function testAFailedInsertWithoutAWinnerRethrows(): void {
		$this->queuedRows = [[], []];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('unique violation');

		$this->mapper([2])->markSeen($this->sighting());
	}//end testAFailedInsertWithoutAWinnerRethrows()

	/**
	 * markUnread deletes the one row and reports whether one went.
	 *
	 * @return void
	 */
	public function testMarkUnreadDeletesTheUsersRow(): void {
		$this->assertTrue($this->mapper()->markUnread('anna', 'o-1'));

		$log = $this->log();
		$this->assertStringContainsString('delete(openregister_object_read_state', $log);
		$this->assertStringContainsString('eq(user_id', $log);
		$this->assertStringContainsString('eq(object_uuid', $log);
	}//end testMarkUnreadDeletesTheUsersRow()

	/**
	 * invalidate clears every user's row but the editor's.
	 *
	 * @return void
	 */
	public function testInvalidateSparesTheEditor(): void {
		$this->assertSame(1, $this->mapper()->invalidate('o-1', 'anna'));

		$this->assertStringContainsString('neq(user_id', $this->log());
	}//end testInvalidateSparesTheEditor()

	/**
	 * invalidate without an editor clears every row for the object.
	 *
	 * @return void
	 */
	public function testInvalidateWithoutEditorClearsEveryone(): void {
		$this->mapper()->invalidate('o-1', '');

		$log = $this->log();
		$this->assertStringContainsString('eq(object_uuid', $log);
		$this->assertStringNotContainsString('user_id', $log);
	}//end testInvalidateWithoutEditorClearsEveryone()

	/**
	 * unreadAmong returns the asked uuids the user has no row for, in input order.
	 *
	 * @return void
	 */
	public function testUnreadAmongKeepsOnlyUnseenUuids(): void {
		$this->queuedRows = [[['object_uuid' => 'b'], ['object_uuid' => null]]];

		$this->assertSame(['a', 'c'], $this->mapper()->unreadAmong('anna', ['a', 'b', 'c']));
		$this->assertStringContainsString('in(object_uuid', $this->log());
	}//end testUnreadAmongKeepsOnlyUnseenUuids()

	/**
	 * unreadAmong of nothing queries nothing.
	 *
	 * @return void
	 */
	public function testUnreadAmongOfNothingDoesNotQuery(): void {
		$this->assertSame([], $this->mapper()->unreadAmong('anna', []));
		$this->assertSame([], $this->queryLog);
	}//end testUnreadAmongOfNothingDoesNotQuery()
}//end class
