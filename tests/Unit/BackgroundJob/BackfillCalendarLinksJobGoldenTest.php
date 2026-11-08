<?php

/**
 * Characterisation (golden) test for BackfillCalendarLinksJob::run().
 *
 * Pins the ordered log of lookups, inserts (with every field) and log lines for:
 * the flag off, a user whose session or event read fails, events without an
 * object uuid or uid, an existing link (skipped), a full and a minimal insert,
 * and a failing insert. Written against the code before the job's constructor
 * and per-event method were reshaped; it must keep passing unchanged afterwards.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\BackfillCalendarLinksJob;
use OCA\OpenRegister\Db\CalendarLink;
use OCA\OpenRegister\Db\CalendarLinkMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden outcomes of the calendar link backfill.
 */
class BackfillCalendarLinksJobGoldenTest extends TestCase {

	/**
	 * Ordered call log.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Build and run the job.
	 *
	 * @param string                                  $flag   The app-config flag value.
	 * @param array<string, array<int, array>|string> $users  Events per user id, or 'session'/'read' to fail there.
	 * @param array<string, bool>                     $exists "uuid|uid" pairs that already have a link.
	 * @param array<string, bool>                     $failOn Event uids whose insert throws.
	 *
	 * @return array<int, string> The call log.
	 */
	private function runBackfill(string $flag, array $users=[], array $exists=[], array $failOn=[]): array {
		$this->calls = [];
		$current     = null;

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) use ($flag): string {
				$this->calls[] = 'config '.$app.'.'.$key.' default='.$default;
				return $flag;
			}
		);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForSeenUsers')->willReturnCallback(
			function (\Closure $callback) use ($users): void {
				foreach (array_keys($users) as $uid) {
					$user = $this->createMock(IUser::class);
					$user->method('getUID')->willReturn((string)$uid);
					$callback($user);
				}
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('setUser')->willReturnCallback(
			function ($user) use ($users, &$current): void {
				$this->calls[] = 'setUser '.$user->getUID();
				$current = $user->getUID();
				if ($users[$current] === 'session') {
					throw new RuntimeException('no session');
				}
			}
		);

		$events = $this->createMock(ObjectCalendarEventsInterface::class);
		$events->method('getEventsForObject')->willReturnCallback(
			function (string $objectUuid) use ($users, &$current): array {
				$this->calls[] = 'getEvents '.var_export($objectUuid, true);
				if ($users[$current] === 'read') {
					throw new RuntimeException('caldav down');
				}

				return $users[$current];
			}
		);

		$mapper = $this->createMock(CalendarLinkMapperInterface::class);
		$mapper->method('findByObjectAndEvent')->willReturnCallback(
			function (string $objectUuid, string $eventUid) use ($exists): CalendarLink {
				$this->calls[] = 'find '.$objectUuid.'|'.$eventUid;
				if (($exists[$objectUuid.'|'.$eventUid] ?? false) === true) {
					return new CalendarLink();
				}

				throw new DoesNotExistException('none');
			}
		);
		$mapper->method('insert')->willReturnCallback(
			function (Entity $entity) use ($failOn): Entity {
				$fields = [];
				foreach ((new ReflectionClass($entity))->getProperties() as $prop) {
					if ($prop->getDeclaringClass()->getName() !== CalendarLink::class) {
						continue;
					}

					$prop->setAccessible(true);
					$value = $prop->getValue($entity);
					if ($prop->getName() === 'linkedAt') {
						$value = ($value instanceof \DateTime);
					} elseif ($value instanceof \DateTime) {
						$value = $value->format('Y-m-d H:i');
					}

					$fields[$prop->getName()] = $value;
				}

				$this->calls[] = 'insert '.json_encode($fields);
				if (($failOn[(string)$entity->getEventUid()] ?? false) === true) {
					throw new RuntimeException('duplicate key');
				}

				return $entity;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->calls[] = $level.' '.$message;
				}
			);
		}

		$job = new BackfillCalendarLinksJob(
			$this->createMock(ITimeFactory::class),
			$mapper,
			$events,
			$userManager,
			$session,
			$config,
			$logger
		);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		return $this->calls;
	}//end runBackfill()

	/**
	 * The flag off skips everything.
	 *
	 * @return void
	 */
	public function testFlagOff(): void {
		self::assertSame(
			[
				'config openregister.backfill_calendar_links default=no',
				'info BackfillCalendarLinksJob skipped (flag backfill_calendar_links = no)',
			],
			$this->runBackfill('no', ['u1' => []])
		);
	}//end testFlagOff()

	/**
	 * Every branch of a run, in order.
	 *
	 * @return void
	 */
	public function testMatrix(): void {
		$calls = $this->runBackfill(
			'yes',
			[
				'broken'  => 'session',
				'offline' => 'read',
				'alice'   => [
					['uid' => 'no-object'],
					['objectUuid' => 'o0'],
					['objectUuid' => 'o1', 'uid' => 'e1'],
					[
						'objectUuid' => 'o2',
						'uid'        => 'e2',
						'registerId' => '4',
						'schemaId'   => 5,
						'calendarId' => '9',
						'summary'    => 12,
						'location'   => 'Hall',
						'id'         => 'evt.ics',
						'dtstart'    => '2026-01-02 10:00:00',
						'dtend'      => '2026-01-02 11:30:00',
					],
					['objectUuid' => 'o3', 'uid' => 'e3', 'calendarId' => null, 'summary' => null],
					['objectUuid' => 'o4', 'uid' => 'e4'],
				],
			],
			['o1|e1' => true],
			['e4' => true]
		);

		$base = [
			'objectUuid'    => null,
			'registerId'    => 0,
			'schemaId'      => 0,
			'calendarUri'   => '',
			'calendarId'    => null,
			'eventUid'      => null,
			'eventUri'      => '',
			'summary'       => null,
			'dtstart'       => null,
			'dtend'         => null,
			'location'      => null,
			'linkedBy'      => 'system:backfill',
			'linkedAt'      => true,
			'taggedWithXor' => true,
		];
		$full = array_merge(
			$base,
			[
				'objectUuid' => 'o2',
				'registerId' => 4,
				'schemaId'   => 5,
				'calendarId' => 9,
				'eventUid'   => 'e2',
				'eventUri'   => 'evt.ics',
				'summary'    => '12',
				'dtstart'    => '2026-01-02 10:00',
				'dtend'      => '2026-01-02 11:30',
				'location'   => 'Hall',
			]
		);

		$expected = [
			'config openregister.backfill_calendar_links default=no',
			'setUser broken',
			'setUser offline',
			"getEvents ''",
			'setUser alice',
			"getEvents ''",
			'find o1|e1',
			'find o2|e2',
			'insert '.json_encode($full),
			'find o3|e3',
			'insert '.json_encode(array_merge($base, ['objectUuid' => 'o3', 'eventUid' => 'e3'])),
			'find o4|e4',
			'insert '.json_encode(array_merge($base, ['objectUuid' => 'o4', 'eventUid' => 'e4'])),
			'warning BackfillCalendarLinksJob: failed to insert link for event e4: duplicate key',
			'info BackfillCalendarLinksJob finished. Inserted=2, skipped=1',
		];

		self::assertSame($expected, $calls);
	}//end testMatrix()
}//end class
