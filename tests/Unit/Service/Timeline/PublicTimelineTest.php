<?php

/**
 * What a stranger reads of an object's timeline.
 *
 * Every assertion here is about something that renders perfectly when it is
 * wrong. A handler's user id on a public entry looks like a harmless field. An
 * internal entry looks like any other entry. A note that silently vanished from
 * the public view looks like a case where less happened. None of them is a
 * crash, so each is pinned by name.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Timeline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
 */

declare(strict_types=1);

namespace Unit\Service\Timeline;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TimelineEntry;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\Timeline\PublicTimeline;
use OCA\OpenRegister\Service\Timeline\TimelineEntryService;
use OCA\OpenRegister\Service\TimelineVisibilityService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for the one anonymous timeline reader.
 *
 * @covers \OCA\OpenRegister\Service\Timeline\PublicTimeline
 */
class PublicTimelineTest extends TestCase {

	/**
	 * The record reader.
	 *
	 * @var TimelineEntryService&MockObject
	 */
	private TimelineEntryService&MockObject $entries;

	/**
	 * The note reader.
	 *
	 * @var NoteService&MockObject
	 */
	private NoteService&MockObject $notes;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The class under test.
	 *
	 * @var PublicTimeline
	 */
	private PublicTimeline $timeline;

	/**
	 * Build the reader over two mocked sources.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->entries = $this->createMock(TimelineEntryService::class);
		$this->notes = $this->createMock(NoteService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->timeline = new PublicTimeline(
			entries: $this->entries,
			notes: $this->notes,
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * Both sources are asked for the public half, by name.
	 *
	 * THE FILTER IS THE TEST. An unfiltered read returns the same shape and a
	 * plausible count, and the failure is an internal note on a stranger's
	 * screen. So both mocks refuse any call that does not name `public`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
	 */
	public function testBothSourcesAreAskedForThePublicHalf(): void {
		$this->entries->expects($this->once())
			->method('listForObject')
			->with($this->anything(), TimelineVisibilityService::PUBLIC_ENTRY, 50)
			->willReturn([]);

		$this->notes->expects($this->once())
			->method('getNotesForObject')
			->with('object-uuid', 50, 0, TimelineVisibilityService::PUBLIC_ENTRY)
			->willReturn([]);

		$this->assertSame([], $this->timeline->forObject(object: $this->object()));
	}//end testBothSourcesAreAskedForThePublicHalf()

	/**
	 * A note leaves with five keys and without the name of who wrote it.
	 *
	 * The note service shapes a note with the author's user id and display
	 * name. This is the leak the class exists to close, so the assertion names
	 * both fields rather than only counting keys.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
	 */
	public function testANoteLeavesWithoutItsAuthor(): void {
		$this->entries->method('listForObject')->willReturn([]);
		$this->notes->method('getNotesForObject')->willReturn([
			[
				'id' => 7,
				'message' => 'We hebben uw stukken ontvangen',
				'actorType' => 'users',
				'actorId' => 'j.jansen',
				'actorDisplayName' => 'Jan Jansen',
				'createdAt' => '2026-05-04T09:12:00+02:00',
				'isCurrentUser' => false,
				'visibility' => 'public',
			],
		]);

		$entry = $this->timeline->forObject(object: $this->object())[0];

		$this->assertSame(PublicTimeline::KEYS, array_keys($entry));
		$this->assertArrayNotHasKey('actorId', $entry);
		$this->assertArrayNotHasKey('actorDisplayName', $entry);
		$this->assertStringNotContainsString('Jansen', json_encode($entry));
		$this->assertSame('We hebben uw stukken ontvangen', $entry['message']);
		$this->assertSame('2026-05-04T09:12:00+02:00', $entry['occurredAt']);
	}//end testANoteLeavesWithoutItsAuthor()

	/**
	 * A record leaves with the same five keys, and its kind and fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
	 */
	public function testARecordLeavesWithItsKindAndWithoutItsAuthor(): void {
		$this->entries->method('listForObject')->willReturn([
			$this->record([
				'id' => 'entry-uuid',
				'kind' => 'beschikking-verzonden',
				'message' => 'Beschikking verzonden',
				'author' => 'j.jansen',
				'visibility' => 'public',
				'fields' => ['channel' => 'berichtenbox'],
				'rawSource' => 'From: someone',
				'created' => '2026-05-04T09:12:00+02:00',
			]),
		]);
		$this->notes->method('getNotesForObject')->willReturn([]);

		$entry = $this->timeline->forObject(object: $this->object())[0];

		$this->assertSame(PublicTimeline::KEYS, array_keys($entry));
		$this->assertSame('beschikking-verzonden', $entry['kind']);
		$this->assertSame(['channel' => 'berichtenbox'], $entry['fields']);
		$this->assertStringNotContainsString('j.jansen', json_encode($entry));
	}//end testARecordLeavesWithItsKindAndWithoutItsAuthor()

	/**
	 * A note that has a record appears once, as the record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
	 */
	public function testANoteWithARecordAppearsOnceAsTheRecord(): void {
		$this->entries->method('listForObject')->willReturn([
			$this->record([
				'id' => 'entry-uuid',
				'commentId' => 7,
				'kind' => null,
				'message' => 'Same note',
				'created' => '2026-05-04T09:12:00+02:00',
			]),
		]);
		$this->notes->method('getNotesForObject')->willReturn([
			['id' => 7, 'message' => 'Same note', 'createdAt' => '2026-05-04T09:12:00+02:00'],
		]);

		$entries = $this->timeline->forObject(object: $this->object());

		$this->assertCount(1, $entries);
		$this->assertSame('entry-uuid', $entries[0]['id']);
	}//end testANoteWithARecordAppearsOnceAsTheRecord()

	/**
	 * A note written before records existed is still on the public view.
	 *
	 * Reading records alone would drop it without a word, and a case whose
	 * older history vanished looks exactly like a case where less happened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
	 */
	public function testANoteWithoutARecordIsNotDropped(): void {
		$this->entries->method('listForObject')->willReturn([
			$this->record(['id' => 'entry-uuid', 'commentId' => 9, 'message' => 'Newer', 'created' => '2026-05-05T09:00:00+02:00']),
		]);
		$this->notes->method('getNotesForObject')->willReturn([
			['id' => 3, 'message' => 'Older, from before records', 'createdAt' => '2026-01-10T09:00:00+01:00'],
		]);

		$messages = array_column($this->timeline->forObject(object: $this->object()), 'message');

		$this->assertSame(['Newer', 'Older, from before records'], $messages);
	}//end testANoteWithoutARecordIsNotDropped()

	/**
	 * Entries come newest first, whichever source they came from.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
	 */
	public function testEntriesComeNewestFirstAcrossBothSources(): void {
		$this->entries->method('listForObject')->willReturn([
			$this->record(['id' => 'r-old', 'message' => 'record old', 'created' => '2026-03-01T10:00:00+01:00']),
			$this->record(['id' => 'r-new', 'message' => 'record new', 'created' => '2026-05-01T10:00:00+02:00']),
		]);
		$this->notes->method('getNotesForObject')->willReturn([
			['id' => 1, 'message' => 'note middle', 'createdAt' => '2026-04-01T10:00:00+02:00'],
		]);

		$messages = array_column($this->timeline->forObject(object: $this->object()), 'message');

		$this->assertSame(['record new', 'note middle', 'record old'], $messages);
	}//end testEntriesComeNewestFirstAcrossBothSources()

	/**
	 * A record table that cannot be read does not hide the notes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
	 */
	public function testEachSourceFailsOnItsOwn(): void {
		$this->entries->method('listForObject')->willThrowException(new RuntimeException('no such table'));
		$this->notes->method('getNotesForObject')->willReturn([
			['id' => 1, 'message' => 'still here', 'createdAt' => '2026-04-01T10:00:00+02:00'],
		]);
		$this->logger->expects($this->once())->method('warning');

		$this->assertSame(['still here'], array_column($this->timeline->forObject(object: $this->object()), 'message'));
	}//end testEachSourceFailsOnItsOwn()

	/**
	 * The notes failing does not hide the records.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
	 */
	public function testTheNotesFailingDoesNotHideTheRecords(): void {
		$this->entries->method('listForObject')->willReturn([
			$this->record(['id' => 'r', 'message' => 'record', 'created' => '2026-04-01T10:00:00+02:00']),
		]);
		$this->notes->method('getNotesForObject')->willThrowException(new RuntimeException('comments down'));
		$this->logger->expects($this->once())->method('warning');

		$this->assertSame(['record'], array_column($this->timeline->forObject(object: $this->object()), 'message'));
	}//end testTheNotesFailingDoesNotHideTheRecords()

	/**
	 * The limit holds across both sources together.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
	 */
	public function testTheLimitHoldsAcrossBothSources(): void {
		$this->entries->method('listForObject')->willReturn([
			$this->record(['id' => 'r1', 'message' => 'a', 'created' => '2026-04-03T10:00:00+02:00']),
			$this->record(['id' => 'r2', 'message' => 'b', 'created' => '2026-04-02T10:00:00+02:00']),
		]);
		$this->notes->method('getNotesForObject')->willReturn([
			['id' => 1, 'message' => 'c', 'createdAt' => '2026-04-01T10:00:00+02:00'],
		]);

		$this->assertCount(2, $this->timeline->forObject(object: $this->object(), limit: 2));
	}//end testTheLimitHoldsAcrossBothSources()

	/**
	 * An object with a known uuid.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('object-uuid');

		return $object;
	}//end object()

	/**
	 * A record that serialises to the given row.
	 *
	 * @param array<string, mixed> $row The serialised fields.
	 *
	 * @return TimelineEntry The record.
	 */
	private function record(array $row): TimelineEntry {
		$record = $this->createMock(TimelineEntry::class);
		$record->method('jsonSerialize')->willReturn($row);

		return $record;
	}//end record()
}//end class
