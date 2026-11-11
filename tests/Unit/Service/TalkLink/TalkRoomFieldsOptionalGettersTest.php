<?php

/**
 * TalkRoomFields with a Talk room that has the optional getters: display name,
 * description, last activity, last message and a participant service.
 *
 * TalkRoomFieldsTest drives rooms through __call, where method_exists() is false
 * for every optional getter; this file covers the branches those getters open.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\TalkLink
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TalkLink;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\TalkLink\RoomPickerQuery;
use OCA\OpenRegister\Service\TalkLink\TalkViewer;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\TalkLink\TalkRoomFields;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A Talk room exposing every optional getter, each answering from a map (a Throwable is thrown).
 */
class TalkRoomFieldsFullRoom {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $answers Getter name => answer.
	 */
	public function __construct(private array $answers) {
	}

	private function answer(string $getter): mixed {
		$answer = ($this->answers[$getter] ?? null);
		if ($answer instanceof \Throwable) {
			throw $answer;
		}

		return $answer;
	}

	public function getId(): mixed {
		return $this->answer('getId');
	}

	public function getToken(): mixed {
		return $this->answer('getToken');
	}

	public function getName(): mixed {
		return $this->answer('getName');
	}

	public function getType(): mixed {
		return $this->answer('getType');
	}

	public function getDisplayName(string $userUid): mixed {
		return $this->answer('getDisplayName:' . $userUid);
	}

	public function getDescription(): mixed {
		return $this->answer('getDescription');
	}

	public function getLastActivity(): mixed {
		return $this->answer('getLastActivity');
	}

	public function getLastMessage(): mixed {
		return $this->answer('getLastMessage');
	}
}

/**
 * A Talk comment with every getter TalkRoomFields reads.
 */
class TalkRoomFieldsComment {

	public function __construct(private readonly mixed $created) {
	}

	public function getMessage(): string {
		return 'Hallo';
	}

	public function getActorType(): string {
		return 'users';
	}

	public function getActorId(): string {
		return 'bob';
	}

	public function getCreationDateTime(): mixed {
		if ($this->created instanceof \Throwable) {
			throw $this->created;
		}

		return $this->created;
	}
}

/**
 * A participant service counting actors.
 */
class TalkRoomFieldsParticipants {

	public function __construct(private readonly mixed $count) {
	}

	public function getNumberOfActors(object $room): int {
		if ($this->count instanceof \Throwable) {
			throw $this->count;
		}

		return $this->count;
	}
}

/**
 * @covers \OCA\OpenRegister\Service\TalkLink\TalkRoomFields
 */
class TalkRoomFieldsOptionalGettersTest extends TestCase {

	private function fields(): TalkRoomFields {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'nl:' . $text);
		return new TalkRoomFields($l10n);
	}

	public function testCacheFieldsReadEveryOptionalGetter(): void {
		$activity = new DateTimeImmutable('2026-03-04T05:06:07+00:00');
		$created = new DateTimeImmutable('2026-03-04T05:00:00+00:00');
		$room = new TalkRoomFieldsFullRoom([
			'getId' => '12',
			'getName' => 'Raw name',
			'getDisplayName:alice' => 'Zaak 42 [or:abc-123]',
			'getType' => 2,
			'getDescription' => '  Over de bouw  ',
			'getLastActivity' => $activity,
			'getLastMessage' => new TalkRoomFieldsComment($created),
		]);

		$fields = $this->fields()->cacheFields($room, new TalkViewer('alice', new TalkRoomFieldsParticipants(5)));

		$this->assertSame(12, $fields['roomId']);
		$this->assertSame('Zaak 42', $fields['roomName']);
		$this->assertSame(2, $fields['roomType']);
		$this->assertSame('Over de bouw', $fields['subtitle']);
		$this->assertSame(5, $fields['participantCount']);
		$this->assertInstanceOf(DateTime::class, $fields['lastActivity']);
		$this->assertSame($activity->getTimestamp(), $fields['lastActivity']->getTimestamp());
		$this->assertSame(
			['actor' => ['type' => 'users', 'id' => 'bob'], 'text' => 'Hallo', 'timestamp' => $created->getTimestamp()],
			$fields['lastMessage']
		);
	}

	public function testAnEmptyDisplayNameKeepsTheRoomNameAndABlankDescriptionFallsBackToTheTypeLabel(): void {
		$room = new TalkRoomFieldsFullRoom(['getName' => 'Raw', 'getDisplayName:alice' => '', 'getType' => 1, 'getDescription' => '   ']);

		$fields = $this->fields()->cacheFields($room, new TalkViewer('alice', null));

		$this->assertSame('Raw', $fields['roomName']);
		$this->assertSame('nl:Direct message', $fields['subtitle']);
	}

	public function testEachKnownTypeHasItsLabelAndAnUnknownTypeHasNone(): void {
		$labels = [];
		foreach ([1, 2, 3, 4, 6, 5] as $type) {
			$room = new TalkRoomFieldsFullRoom(['getName' => 'R', 'getType' => $type, 'getDescription' => new RuntimeException('no')]);
			$labels[$type] = $this->fields()->cacheFields($room, new TalkViewer('u', null))['subtitle'];
		}

		$this->assertSame(
			[1 => 'nl:Direct message', 2 => 'nl:Group', 3 => 'nl:Public', 4 => 'nl:System', 6 => 'nl:Note to self', 5 => null],
			$labels
		);
	}

	public function testAnEmptyNameIsStoredAsNull(): void {
		$room = new TalkRoomFieldsFullRoom(['getName' => ' [or:x] ', 'getDisplayName:u' => new RuntimeException('no')]);

		$this->assertNull($this->fields()->cacheFields($room, new TalkViewer('u', null))['roomName']);
	}

	public function testANonDateLastActivityAndANonObjectLastMessageAreNull(): void {
		$room = new TalkRoomFieldsFullRoom(['getName' => 'R', 'getLastActivity' => '2026-01-01', 'getLastMessage' => 'text']);

		$fields = $this->fields()->cacheFields($room, new TalkViewer('u', null));

		$this->assertNull($fields['lastActivity']);
		$this->assertNull($fields['lastMessage']);
	}

	public function testACommentWithoutAReadableCreationTimeHasNoTimestamp(): void {
		foreach ([new RuntimeException('no date'), 'yesterday'] as $created) {
			$room = new TalkRoomFieldsFullRoom(['getName' => 'R', 'getLastMessage' => new TalkRoomFieldsComment($created)]);

			$this->assertNull($this->fields()->cacheFields($room, new TalkViewer('u', null))['lastMessage']['timestamp']);
		}
	}

	public function testACommentWithoutGettersReadsAsBlank(): void {
		$room = new TalkRoomFieldsFullRoom(['getName' => 'R', 'getLastMessage' => new \stdClass()]);

		$this->assertSame(
			['actor' => ['type' => '', 'id' => ''], 'text' => '', 'timestamp' => null],
			$this->fields()->cacheFields($room, new TalkViewer('u', null))['lastMessage']
		);
	}

	public function testAParticipantServiceWithoutTheCounterOrThatThrowsGivesNoCount(): void {
		$room = new TalkRoomFieldsFullRoom(['getName' => 'R']);

		$this->assertNull($this->fields()->cacheFields($room, new TalkViewer('u', new \stdClass()))['participantCount']);
		$this->assertNull($this->fields()->cacheFields($room, new TalkViewer('u', new TalkRoomFieldsParticipants(new RuntimeException('x'))))['participantCount']);
	}

	public function testPickerRowsMatchOnTheDisplayNameAndCountParticipants(): void {
		$rooms = [
			new TalkRoomFieldsFullRoom(['getToken' => 't1', 'getName' => 'Raw', 'getDisplayName:alice' => 'Bouwdossier', 'getType' => 3]),
			new TalkRoomFieldsFullRoom(['getToken' => 't2', 'getName' => 'Bouw', 'getType' => 4]),
		];

		$this->assertSame(
			[['token' => 't1', 'name' => 'Bouwdossier', 'type' => 3, 'participantCount' => 7]],
			$this->fields()->pickerRows($rooms, new RoomPickerQuery(new TalkViewer('alice', new TalkRoomFieldsParticipants(7)), 'dossier'))
		);
	}

	public function testAnEmptySearchMatchesEveryRoom(): void {
		$rooms = [new TalkRoomFieldsFullRoom(['getToken' => 't1', 'getName' => 'A', 'getType' => 2])];

		$this->assertCount(1, $this->fields()->pickerRows($rooms, new RoomPickerQuery(new TalkViewer('u', null), '  ')));
		$this->assertCount(1, $this->fields()->pickerRows($rooms, new RoomPickerQuery(new TalkViewer('u', null), null)));
	}
}
