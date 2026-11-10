<?php

/**
 * Unit test for MdtoEventMapper's bound without a creation event and its
 * empty actors: more qualifying rows than the bound keep the newest ones when
 * none is the creation, and an empty user is no actor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\MdtoEventMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\MdtoEventMapper
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\AuditTrail\AuditedObject
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class MdtoEventMapperBoundTest extends TestCase {

	/**
	 * An update row with an id, a fixed UTC moment and a user.
	 *
	 * @param int    $id   The id; also the minute of the moment.
	 * @param string $user The user.
	 *
	 * @return AuditTrail
	 */
	private static function row(int $id, string $user='anna'): AuditTrail {
		$row = new AuditTrail();
		$row->setId($id);
		$row->setAction('update');
		$row->setUser($user);
		$row->setUserName($user);
		$row->setCreated(new DateTime(sprintf('2026-01-01 10:%02d:00', $id), new DateTimeZone('UTC')));
		return $row;
	}//end row()

	/**
	 * The events of one object over the given recent and creation rows.
	 *
	 * @param array<int, AuditTrail> $recent   What the recent-rows query returns.
	 * @param array<int, AuditTrail> $creation What the creation query returns.
	 * @param array<int, array>      $logged   The info messages logged.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function events(array $recent, array $creation, array &$logged): array {
		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->method('findAll')->willReturnOnConsecutiveCalls($recent, $creation);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context) use (&$logged): void {
				$logged[] = [$message, $context['qualifying'], $context['emitted']];
			}
		);
		$object = new ObjectEntity();
		$object->setUuid('u-1');

		return (new MdtoEventMapper(new \OCA\OpenRegister\Service\Edepot\AuditRecording($mapper, $logger)))->forObject(object: $object);
	}//end events()

	/**
	 * Over the bound and without a creation row, the newest 25 are kept, oldest first, and the cut is logged.
	 *
	 * @return void
	 */
	public function testWithoutACreationTheNewestAreKept(): void {
		$recent = array_map(fn (int $id): AuditTrail => self::row(id: $id), range(30, 1));
		$logged = [];

		$events = $this->events(recent: $recent, creation: [], logged: $logged);

		$this->assertCount(MdtoEventMapper::MAX_EVENTS, $events);
		$this->assertSame('2026-01-01T10:06:00+00:00', $events[0]['time']);
		$this->assertSame('2026-01-01T10:30:00+00:00', $events[24]['time']);
		$this->assertSame([['[MdtoEventMapper] Audit trail exceeded the MDTO event bound; older events were omitted', 30, 25]], $logged);
	}//end testWithoutACreationTheNewestAreKept()

	/**
	 * An empty user name and id are no actor, and an event without a moment has no time.
	 *
	 * @return void
	 */
	public function testAnEmptyUserIsNoActor(): void {
		$row = self::row(id: 1, user: '');
		$row->setCreated(null);
		$logged = [];

		$this->assertSame(
			[['type' => 'Wijziging', 'time' => null, 'actorName' => null, 'actorId' => null]],
			$this->events(recent: [$row], creation: [], logged: $logged)
		);
		$this->assertSame([], $logged);
	}//end testAnEmptyUserIsNoActor()
}//end class
