<?php

/**
 * Golden: what the groupware, party, case plan and email link rows of an example set refuse, pinned on exact messages.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetAppointments;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckCards;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCollectivesPages;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkConversations;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetParties;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCasePlans;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetEmailLinks;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppointments
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckCards
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCollectivesPages
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkConversations
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetParties
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCasePlans
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetEmailLinks
 */
class ExampleSetRowRefusalsGoldenTest extends TestCase {
	/**
	 * A row of each kind and the exact refusal it gets ('' when it may be written).
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<int, string>, 3: string}>
	 */
	public static function cases(): array {
		return [
			'appointment unknown' => ['Appointments', ['object' => 'o', 'colour' => 1, 'x' => 2], ['alice', 'bob'], 'field "colour", "x" is not an appointment field a set may write'],
			'appointment no object' => ['Appointments', ['owner' => 'alice'], ['alice', 'bob'], 'an appointment needs the object it is on (object)'],
			'appointment blank owner' => ['Appointments', ['object' => 'o', 'owner' => ' '], ['alice', 'bob'], 'an appointment needs the account whose calendar holds it (owner)'],
			'appointment no summary' => ['Appointments', ['object' => 'o', 'owner' => 'alice'], ['alice', 'bob'], 'an appointment needs a summary (summary)'],
			'appointment no start' => ['Appointments', ['object' => 'o', 'owner' => 'alice', 'summary' => 's'], ['alice', 'bob'], 'an appointment needs a start (start)'],
			'appointment start not string' => ['Appointments', ['object' => 'o', 'owner' => 'alice', 'summary' => 's', 'start' => 5], ['alice', 'bob'], 'an appointment needs a start (start)'],
			'appointment stranger' => ['Appointments', ['object' => 'o', 'owner' => 'carol', 'summary' => 's', 'start' => '2026-01-01'], ['alice', 'bob'], 'owner "carol" is not an account the set creates or adopts'],
			'appointment bad start' => ['Appointments', ['object' => 'o', 'owner' => 'alice', 'summary' => 's', 'start' => 'nope'], ['alice', 'bob'], 'start "nope" is not a date'],
			'appointment bad end' => ['Appointments', ['object' => 'o', 'owner' => 'alice', 'summary' => 's', 'start' => '2026-01-01', 'end' => 'later'], ['alice', 'bob'], 'end "later" is not a date'],
			'appointment empty end' => ['Appointments', ['object' => 'o', 'owner' => 'alice', 'summary' => 's', 'start' => '2026-01-01', 'end' => ''], ['alice', 'bob'], ''],
			'appointment attendee no email' => ['Appointments', ['object' => 'o', 'owner' => 'alice', 'summary' => 's', 'start' => '2026-01-01', 'attendees' => [['name' => 'x']]], ['alice', 'bob'], 'an invitee needs an e-mail address (email)'],
			'appointment attendee bad state' => ['Appointments', ['object' => 'o', 'owner' => 'alice', 'summary' => 's', 'start' => '2026-01-01', 'attendees' => [['email' => 'a@b.nl', 'status' => 'maybe']]], ['alice', 'bob'], 'an invitee\'s status is one of invited, accepted, declined, tentative'],
			'appointment fine' => ['Appointments', ['object' => 'o', 'owner' => 'alice', 'summary' => 's', 'start' => '2026-01-01', 'end' => '2026-01-02', 'attendees' => [['email' => 'a@b.nl', 'status' => 'accepted']]], ['alice', 'bob'], ''],
			'deck unknown' => ['DeckCards', ['object' => 'o', 'stack' => 1], ['alice', 'bob'], 'field "stack" is not a deck card field a set may write'],
			'deck no object' => ['DeckCards', [], ['alice', 'bob'], 'a deck card needs the object it is on (object)'],
			'deck no board' => ['DeckCards', ['object' => 'o'], ['alice', 'bob'], 'a deck card needs a board (board)'],
			'deck no list' => ['DeckCards', ['object' => 'o', 'board' => 'b'], ['alice', 'bob'], 'a deck card needs a list (list)'],
			'deck no title' => ['DeckCards', ['object' => 'o', 'board' => 'b', 'list' => 'l'], ['alice', 'bob'], 'a deck card needs a title (title)'],
			'deck no owner' => ['DeckCards', ['object' => 'o', 'board' => 'b', 'list' => 'l', 'title' => 't'], ['alice', 'bob'], 'a deck card needs an owner (owner)'],
			'deck stranger owner' => ['DeckCards', ['object' => 'o', 'board' => 'b', 'list' => 'l', 'title' => 't', 'owner' => 'carol'], ['alice', 'bob'], '"carol" is not an account the set creates or adopts'],
			'deck stranger assignee' => ['DeckCards', ['object' => 'o', 'board' => 'b', 'list' => 'l', 'title' => 't', 'owner' => 'alice', 'assignees' => ['bob', 'dave']], ['alice', 'bob'], '"dave" is not an account the set creates or adopts'],
			'deck bad due' => ['DeckCards', ['object' => 'o', 'board' => 'b', 'list' => 'l', 'title' => 't', 'owner' => 'alice', 'due' => 'soon'], ['alice', 'bob'], 'due "soon" is not a date'],
			'deck bad done' => ['DeckCards', ['object' => 'o', 'board' => 'b', 'list' => 'l', 'title' => 't', 'owner' => 'alice', 'due' => '2026-01-01', 'done' => 'nope'], ['alice', 'bob'], 'done "nope" is not a date'],
			'deck null due' => ['DeckCards', ['object' => 'o', 'board' => 'b', 'list' => 'l', 'title' => 't', 'owner' => 'alice', 'due' => NULL], ['alice', 'bob'], ''],
			'deck fine' => ['DeckCards', ['object' => 'o', 'board' => 'b', 'list' => 'l', 'title' => 't', 'owner' => 'alice', 'assignees' => ['bob'], 'due' => '2026-01-01'], ['alice', 'bob'], ''],
			'page unknown' => ['CollectivesPages', ['object' => 'o'], ['alice', 'bob'], 'field "object" is not a collectives page field a set may write'],
			'page no collective' => ['CollectivesPages', [], ['alice', 'bob'], 'a collectives page needs a collective (collective)'],
			'page no title' => ['CollectivesPages', ['collective' => 'c'], ['alice', 'bob'], 'a collectives page needs a title (title)'],
			'page no content' => ['CollectivesPages', ['collective' => 'c', 'title' => 't'], ['alice', 'bob'], 'a collectives page needs its content (content)'],
			'page no owner' => ['CollectivesPages', ['collective' => 'c', 'title' => 't', 'content' => 'x'], ['alice', 'bob'], 'a collectives page needs an owner (owner)'],
			'page stranger' => ['CollectivesPages', ['collective' => 'c', 'title' => 't', 'content' => 'x', 'owner' => 'carol'], ['alice', 'bob'], '"carol" is not an account the set creates or adopts'],
			'page share map' => ['CollectivesPages', ['collective' => 'c', 'title' => 't', 'content' => 'x', 'owner' => 'alice', 'shareWith' => ['a' => 'g']], ['alice', 'bob'], 'shareWith is a list of groups'],
			'page fine' => ['CollectivesPages', ['collective' => 'c', 'title' => 't', 'content' => 'x', 'owner' => 'alice', 'shareWith' => ['g']], ['alice', 'bob'], ''],
			'talk unknown' => ['TalkConversations', ['object' => 'o', 'type' => 1], ['alice', 'bob'], 'field "type" is not a talk conversation field a set may write'],
			'talk no object' => ['TalkConversations', [], ['alice', 'bob'], 'a talk conversation needs the object it is on (object)'],
			'talk no name' => ['TalkConversations', ['object' => 'o'], ['alice', 'bob'], 'a talk conversation needs a name (name)'],
			'talk no owner' => ['TalkConversations', ['object' => 'o', 'name' => 'n'], ['alice', 'bob'], 'a talk conversation needs an owner (owner)'],
			'talk stranger' => ['TalkConversations', ['object' => 'o', 'name' => 'n', 'owner' => 'alice', 'participants' => ['eve']], ['alice', 'bob'], '"eve" is not an account the set creates or adopts'],
			'talk fine' => ['TalkConversations', ['object' => 'o', 'name' => 'n', 'owner' => 'alice', 'participants' => ['bob']], ['alice', 'bob'], ''],
			'talk bad call' => ['TalkConversations', ['object' => 'o', 'name' => 'n', 'owner' => 'alice', 'call' => 'yes'], ['alice', 'bob'], 'a held call needs a start and an end (call.start, call.end)'],
			'talk call map' => ['TalkConversations', ['object' => 'o', 'name' => 'n', 'owner' => 'alice', 'call' => ['participants' => ['eve']]], ['alice', 'bob'], 'a held call needs a start and an end (call.start, call.end)'],
			'talk call fine' => ['TalkConversations', ['object' => 'o', 'name' => 'n', 'owner' => 'alice', 'participants' => ['bob'], 'call' => ['start' => '2026-01-01T10:00:00Z', 'end' => '2026-01-01T11:00:00Z', 'participants' => ['bob']]], ['alice', 'bob'], ''],
			'talk call bad start' => ['TalkConversations', ['object' => 'o', 'name' => 'n', 'owner' => 'alice', 'call' => ['start' => 'nope', 'end' => '2026-01-01T11:00:00Z']], ['alice', 'bob'], 'call.start or call.end is not a date and time'],
			'talk call end first' => ['TalkConversations', ['object' => 'o', 'name' => 'n', 'owner' => 'alice', 'call' => ['start' => '2026-01-02T10:00:00Z', 'end' => '2026-01-01T11:00:00Z']], ['alice', 'bob'], 'a held call ends after it starts'],
			'talk call stranger' => ['TalkConversations', ['object' => 'o', 'name' => 'n', 'owner' => 'alice', 'call' => ['start' => '2026-01-01T10:00:00Z', 'end' => '2026-01-01T11:00:00Z', 'participants' => ['eve']]], ['alice', 'bob'], ''],
			'party unknown' => ['Parties', ['objectUuid' => 'o', 'x' => 1], ['alice', 'bob'], 'field "x" is not a party field a set may write'],
			'party no object' => ['Parties', [], ['alice', 'bob'], 'a party link needs the object it is on (objectUuid)'],
			'party no party' => ['Parties', ['objectUuid' => 'o'], ['alice', 'bob'], 'a party link needs the party (partyUuid)'],
			'party no role' => ['Parties', ['objectUuid' => 'o', 'partyUuid' => 'p'], ['alice', 'bob'], 'a party link needs a role (role)'],
			'party bad from' => ['Parties', ['objectUuid' => 'o', 'partyUuid' => 'p', 'role' => 'r', 'validFrom' => 'nope'], ['alice', 'bob'], 'validFrom "nope" is not a date'],
			'party bad until' => ['Parties', ['objectUuid' => 'o', 'partyUuid' => 'p', 'role' => 'r', 'validUntil' => 'nope'], ['alice', 'bob'], 'validUntil "nope" is not a date'],
			'party fine' => ['Parties', ['objectUuid' => 'o', 'partyUuid' => 'p', 'role' => 'r', 'validFrom' => '2026-01-01'], ['alice', 'bob'], ''],
			'plan unknown' => ['CasePlans', ['object' => 'o', 'x' => 1], ['alice', 'bob'], 'field "x" is not a case plan field a set may write'],
			'plan no object' => ['CasePlans', [], ['alice', 'bob'], 'a case plan needs the object it is on (object)'],
			'plan no items' => ['CasePlans', ['object' => 'o', 'definition' => []], ['alice', 'bob'], 'a case plan needs a definition with its items (definition.items)'],
			'plan history map' => ['CasePlans', ['object' => 'o', 'definition' => ['items' => [['key' => 'a']]], 'history' => ['a' => 1]], ['alice', 'bob'], 'history is a list'],
			'plan fine' => ['CasePlans', ['object' => 'o', 'definition' => ['items' => [['key' => 'a']]], 'history' => []], ['alice', 'bob'], ''],
			'mail unknown' => ['EmailLinks', ['object' => 'o', 'x' => 1], ['alice', 'bob'], 'field "x" is not an email link field a set may write'],
			'mail no object' => ['EmailLinks', [], ['alice', 'bob'], 'an email link needs the object it is on (object)'],
			'mail no subject' => ['EmailLinks', ['object' => 'o'], ['alice', 'bob'], 'an email link needs a subject'],
			'mail no mail' => ['EmailLinks', ['object' => 'o', 'subject' => 's'], ['alice', 'bob'], 'an email link needs the mail: a messageId, or mailAccountId and mailMessageId'],
			'mail stranger' => ['EmailLinks', ['object' => 'o', 'subject' => 's', 'messageId' => 'm', 'linkedBy' => 'eve'], ['alice', 'bob'], 'linkedBy "eve" is not an account the set creates or adopts'],
			'mail bad date' => ['EmailLinks', ['object' => 'o', 'subject' => 's', 'messageId' => 'm', 'mailDate' => 'nope'], ['alice', 'bob'], 'mailDate is a date and time'],
			'mail bad direction' => ['EmailLinks', ['object' => 'o', 'subject' => 's', 'mailAccountId' => 1, 'mailMessageId' => 2, 'direction' => 'up'], ['alice', 'bob'], 'direction is inbound or outbound'],
			'mail bad via' => ['EmailLinks', ['object' => 'o', 'subject' => 's', 'messageId' => 'm', 'linkedVia' => 'fax'], ['alice', 'bob'], 'linkedVia is manual, mailbox or file'],
			'mail bad count' => ['EmailLinks', ['object' => 'o', 'subject' => 's', 'messageId' => 'm', 'attachmentCount' => -1], ['alice', 'bob'], 'attachmentCount is a whole number of 0 or more'],
			'mail fine' => ['EmailLinks', ['object' => 'o', 'subject' => 's', 'messageId' => 'm', 'linkedBy' => 'bob', 'mailDate' => '2026-01-01T10:00:00Z', 'direction' => 'inbound', 'linkedVia' => 'file', 'attachmentCount' => 0], ['alice', 'bob'], ''],
		];
	}//end cases()

	/**
	 * A row gets exactly its refusal; the check reads nothing from the container.
	 *
	 * @param string               $kind     The kind, by class name after `ExampleSet`.
	 * @param array<string, mixed> $data     The filled fields.
	 * @param array<int, string>   $users    The account ids the set creates or adopts.
	 * @param string               $expected The refusal.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testRefusal(string $kind, array $data, array $users, string $expected): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$class = 'OCA\\OpenRegister\\Service\\ExampleSet\\ExampleSet'.$kind;
		$this->assertSame($expected, (new $class(container: $container))->refusal(data: $data, users: $users));
	}//end testRefusal()
}//end class
