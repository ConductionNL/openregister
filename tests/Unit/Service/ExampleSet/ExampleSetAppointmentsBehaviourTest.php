<?php

/**
 * Behaviour tests for ExampleSetAppointments: which appointment rows a set may
 * write, the event it creates, and how it is checked and reverted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;


use OCA\OpenRegister\Db\CalendarLink;
use OCA\OpenRegister\Db\CalendarLinkMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetAppointments;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use OCA\OpenRegister\Service\Party\PartyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Behaviour of the calendar appointments an example set puts on its objects.
 */
class ExampleSetAppointmentsBehaviourTest extends TestCase {

	/** @var PartyService&MockObject */
	private PartyService $parties;

	/** @var TaskMapper&MockObject */
	private TaskMapper $tasks;

	/** @var CalendarLinkMapperInterface&MockObject */
	private CalendarLinkMapperInterface $links;

	/** @var IUserManager&MockObject */
	private IUserManager $users;

	private object $caldav;

	protected function setUp(): void {
		$this->parties = $this->createMock(PartyService::class);
		$this->tasks   = $this->createMock(TaskMapper::class);
		$this->links   = $this->createMock(CalendarLinkMapperInterface::class);
		$this->users   = $this->createMock(IUserManager::class);
		$this->caldav  = new class {
			public array $calendars = [];
			public array $objects   = [];
			public array $calls     = [];
			public bool $broken     = false;

			public function getCalendarsForUser(string $principal): array {
				$this->calls[] = ['list', $principal];
				return $this->calendars;
			}

			public function createCalendar(string $principal, string $uri, array $props): int {
				$this->calls[] = ['createCalendar', $principal, $uri, $props];
				return 900;
			}

			public function createCalendarObject(int $calendar, string $uri, string $ics): void {
				if ($this->broken === true) {
					throw new \RuntimeException('caldav down');
				}

				$this->objects[$calendar.'/'.$uri] = $ics;
			}

			public function getCalendarObject(int $calendar, string $uri): ?array {
				if ($this->broken === true) {
					throw new \RuntimeException('caldav down');
				}

				return (isset($this->objects[$calendar.'/'.$uri]) === true ? ['calendardata' => $this->objects[$calendar.'/'.$uri]] : null);
			}

			public function deleteCalendarObject(int $calendar, string $uri): void {
				$this->calls[] = ['delete', $calendar, $uri];
				unset($this->objects[$calendar.'/'.$uri]);
			}
		};
	}//end setUp()

	private function appointments(): ExampleSetAppointments {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[PartyService::class, $this->parties],
				[TaskMapper::class, $this->tasks],
				[CalendarLinkMapperInterface::class, $this->links],
				[IUserManager::class, $this->users],
				['OCA\\DAV\\CalDAV\\CalDavBackend', $this->caldav],
			]
		);
		return new ExampleSetAppointments($container);
	}//end appointments()

	private static function valid(array $override=[]): array {
		return array_merge(['object' => 'obj-1', 'owner' => 'alice', 'summary' => 'Intake', 'start' => '2026-03-02T09:00:00+01:00'], $override);
	}//end valid()

	private function objectFound(): void {
		$object = new ObjectEntity();
		$object->setRegister('4');
		$object->setSchema('7');
		$this->parties->method('findUnscoped')->willReturn($object);
	}//end objectFound()

	/**
	 * Capture the inserted link.
	 *
	 * @return \ArrayObject<int, CalendarLink>
	 */
	private function captureLink(): \ArrayObject {
		$captured = new \ArrayObject();
		$this->links->method('insert')->willReturnCallback(
			static function (CalendarLink $link) use ($captured): CalendarLink {
				$captured[] = $link;
				return $link;
			}
		);
		return $captured;
	}//end captureLink()

	public function testNamesThePartAndNoun(): void {
		$this->assertSame('appointments', $this->appointments()->part());
		$this->assertSame('appointment', $this->appointments()->noun());
	}

	public function testAValidAppointmentHasNoRefusal(): void {
		$data = self::valid(['end' => '2026-03-02T10:00:00+01:00', 'attendees' => [['email' => 'bob@example.org', 'status' => 'accepted']]]);

		$this->assertSame('', $this->appointments()->refusal($data, ['alice']));
	}

	public function testRefusesAnUnknownField(): void {
		$this->assertStringContainsString('"colour"', $this->appointments()->refusal(self::valid(['colour' => 'red']), ['alice']));
	}

	public function testRefusesAMissingSummary(): void {
		$data = self::valid();
		unset($data['summary']);

		$this->assertStringContainsString('a summary', $this->appointments()->refusal($data, ['alice']));
	}

	public function testRefusesAnOwnerTheSetDoesNotCreate(): void {
		$this->assertSame(
			'owner "alice" is not an account the set creates or adopts',
			$this->appointments()->refusal(self::valid(), ['bob'])
		);
	}

	public function testRefusesAnUnreadableStart(): void {
		$this->assertStringContainsString('start', $this->appointments()->refusal(self::valid(['start' => 'next tuesday-ish!']), ['alice']));
	}

	public function testRefusesAnInviteeWithoutAValidEmail(): void {
		$this->assertSame(
			'an invitee needs an e-mail address (email)',
			$this->appointments()->refusal(self::valid(['attendees' => [['name' => 'Bob']]]), ['alice'])
		);
	}

	public function testRefusesAnUnknownInviteeStatus(): void {
		$this->assertSame(
			'an invitee\'s status is one of invited, accepted, declined, tentative',
			$this->appointments()->refusal(self::valid(['attendees' => [['email' => 'bob@example.org', 'status' => 'maybe']]]), ['alice'])
		);
	}

	public function testWriteIsRefusedWhenNeitherObjectNorTaskExists(): void {
		$this->parties->method('findUnscoped')->willReturn(null);
		$this->tasks->method('findByUuid')->willThrowException(new DoesNotExistException('none'));
		$this->links->expects($this->never())->method('insert');

		$this->assertSame(
			['error' => 'no object or task "obj-1" to put the appointment on', 'entry' => null],
			$this->appointments()->write(new ExampleSetRow('app', 'k', self::valid()))
		);
	}

	public function testWriteOnAnObjectCreatesTheEventInAnExistingCalendarAndLinksIt(): void {
		$this->objectFound();
		$this->caldav->calendars = [['uri' => 'other', 'id' => 1], ['uri' => 'work', 'id' => 12]];
		$alice = $this->createMock(IUser::class);
		$alice->method('getEMailAddress')->willReturn('alice@example.org');
		$alice->method('getDisplayName')->willReturn('Alice, A.');
		$this->users->method('get')->with('alice')->willReturn($alice);
		$captured = $this->captureLink();

		$result = $this->appointments()->write(
			new ExampleSetRow(
				'myapp',
				'k',
				self::valid(
					[
						'calendar' => 'work',
						'end' => '2026-03-02T10:30:00+01:00',
						'location' => 'Stadhuis; zaal 2',
						'description' => "Regel 1\nRegel 2",
						'attendees' => [['email' => 'bob@example.org', 'name' => 'Bob', 'status' => 'tentative'], ['email' => 'carol@example.org']],
					]
				)
			)
		);

		$this->assertSame('', $result['error']);
		$this->assertSame(12, $result['entry']['calendar']);
		$this->assertSame('obj-1', $result['entry']['object']);
		$this->assertMatchesRegularExpression('/^[0-9A-F]{32}$/', $result['entry']['uid']);
		$this->assertSame($result['entry']['uid'].'.ics', $result['entry']['uri']);
		$this->assertSame([['list', 'principals/users/alice']], $this->caldav->calls);

		$ics = $this->caldav->objects['12/'.$result['entry']['uri']];
		$this->assertStringContainsString("SUMMARY:Intake\r\n", $ics);
		$this->assertStringContainsString("DTSTART:20260302T080000Z\r\n", $ics);
		$this->assertStringContainsString("DTEND:20260302T093000Z\r\n", $ics);
		$this->assertStringContainsString("LOCATION:Stadhuis\; zaal 2\r\n", $ics);
		$this->assertStringContainsString("DESCRIPTION:Regel 1\\nRegel 2\r\n", $ics);
		$this->assertStringContainsString("ORGANIZER;CN=\"Alice\\, A.\":mailto:alice@example.org\r\n", $ics);
		$this->assertStringContainsString('ATTENDEE;CN="Bob";ROLE=REQ-PARTICIPANT;PARTSTAT=TENTATIVE:mailto:bob@example.org', $ics);
		$this->assertStringContainsString('ATTENDEE;CN="carol@example.org";ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION:mailto:carol@example.org', $ics);
		$this->assertStringEndsWith("END:VEVENT\r\nEND:VCALENDAR\r\n", $ics);

		$link = $captured[0];
		$this->assertSame('obj-1', $link->getObjectUuid());
		$this->assertSame(4, $link->getRegisterId());
		$this->assertSame(7, $link->getSchemaId());
		$this->assertSame('work', $link->getCalendarUri());
		$this->assertSame(12, $link->getCalendarId());
		$this->assertSame('Intake', $link->getSummary());
		$this->assertSame('2026-03-02T08:00:00+00:00', $link->getDtstart()->format('c'));
		$this->assertSame('2026-03-02T09:30:00+00:00', $link->getDtend()->format('c'));
		$this->assertSame('Stadhuis; zaal 2', $link->getLocation());
		$this->assertSame('myapp', $link->getLinkedBy());
	}

	public function testWriteOnATaskCreatesThePersonalCalendarAndOmitsOptionalLines(): void {
		$this->parties->method('findUnscoped')->willReturn(null);
		$task = new Task();
		$task->setRegisterId(5);
		$task->setSchemaId(6);
		$this->tasks->method('findByUuid')->with('obj-1')->willReturn($task);
		$this->users->method('get')->willReturn(null);
		$captured = $this->captureLink();

		$result = $this->appointments()->write(new ExampleSetRow('app', 'k', self::valid(['location' => '   ', 'end' => ''])));

		$this->assertSame(900, $result['entry']['calendar']);
		$this->assertSame(['createCalendar', 'principals/users/alice', 'personal', ['{DAV:}displayname' => 'Personal']], $this->caldav->calls[1]);
		$ics = $this->caldav->objects['900/'.$result['entry']['uri']];
		$this->assertStringNotContainsString('DTEND', $ics);
		$this->assertStringNotContainsString('LOCATION', $ics);
		$this->assertStringNotContainsString('ORGANIZER', $ics);
		$this->assertStringNotContainsString('ATTENDEE', $ics);
		$this->assertSame(5, $captured[0]->getRegisterId());
		$this->assertNull($captured[0]->getDtend());
		$this->assertNull($captured[0]->getLocation());
	}

	public function testWriteReportsACaldavFailure(): void {
		$this->objectFound();
		$this->caldav->broken = true;
		$this->links->expects($this->never())->method('insert');

		$this->assertSame(['error' => 'caldav down', 'entry' => null], $this->appointments()->write(new ExampleSetRow('app', 'k', self::valid())));
	}

	public function testLostFollowsWhetherTheEventExists(): void {
		$this->caldav->objects['3/a.ics'] = 'BEGIN:VCALENDAR';

		$this->assertFalse($this->appointments()->lost(['calendar' => 3, 'uri' => 'a.ics']));
		$this->assertTrue($this->appointments()->lost(['calendar' => 3, 'uri' => 'b.ics']));
	}

	public function testLostIsTrueWhenCaldavFails(): void {
		$this->caldav->broken = true;

		$this->assertTrue($this->appointments()->lost(['calendar' => 3, 'uri' => 'a.ics']));
	}

	public function testRevertRemovesTheLinkAndAnExistingEvent(): void {
		$this->caldav->objects['3/a.ics'] = 'BEGIN:VCALENDAR';
		$this->links->expects($this->once())->method('deleteByObjectAndEvent')->with('obj-1', 'UID1');

		$this->assertSame('removed', $this->appointments()->revert(['object' => 'obj-1', 'uid' => 'UID1', 'calendar' => 3, 'uri' => 'a.ics']));
		$this->assertSame([['delete', 3, 'a.ics']], $this->caldav->calls);
	}

	public function testRevertOfAGoneEventOnlyRemovesTheLink(): void {
		$this->links->expects($this->once())->method('deleteByObjectAndEvent');

		$this->assertSame('removed', $this->appointments()->revert(['object' => 'obj-1', 'uid' => 'UID1', 'calendar' => 3, 'uri' => 'a.ics']));
		$this->assertSame([], $this->caldav->calls);
	}

	public function testRevertReportsAFailure(): void {
		$this->links->method('deleteByObjectAndEvent')->willThrowException(new \RuntimeException('db'));

		$this->assertSame('failed', $this->appointments()->revert(['object' => 'obj-1', 'uid' => 'UID1', 'calendar' => 3, 'uri' => 'a.ics']));
	}
}//end class
