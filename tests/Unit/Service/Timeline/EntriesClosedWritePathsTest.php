<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Timeline;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\EntriesClosedException;
use OCA\OpenRegister\Files\Service\NoteAccounts;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Files\Service\NoteVersionService;
use OCA\OpenRegister\Kernel\Contracts\EntryClosureCheckInterface;
use OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\NoteDraft;
use OCA\OpenRegister\Notifications\Db\TimelineEntry;
use OCA\OpenRegister\Notifications\Db\TimelineEntryMapper;
use OCA\OpenRegister\Notifications\Service\Timeline\LanguageDetector;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntryDerivations;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntryRecords;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntryService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineKindService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineWriteService;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityServiceInterface;
use OCP\Comments\ICommentsManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every path that adds an entry asks whether the record is closed BEFORE it writes anything: a note
 * is refused before its comment exists, a timeline record before it is inserted, and a note to
 * several records is refused whole when any of them is closed (object-archive-state C29.4).
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
 */
final class EntriesClosedWritePathsTest extends TestCase {

	/** @var array<int, string> The objects the check was asked about. */
	private array $asked = [];

	/**
	 * A closure check under which the named objects are closed.
	 *
	 * @param array<int, string> $closed The closed object uuids.
	 */
	private function closures(array $closed): EntryClosureCheckInterface {
		$check = $this->createMock(EntryClosureCheckInterface::class);
		$check->method('assertOpen')->willReturnCallback(
			function (string $objectUuid) use ($closed): void {
				$this->asked[] = $objectUuid;
				if (in_array($objectUuid, $closed, true) === true) {
					throw new EntriesClosedException(closure: ['by' => 'behandelaar', 'at' => '2026-11-25T10:00:00+00:00', 'reason' => 'beroep']);
				}
			}
		);

		return $check;
	}

	private static function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);

		return $object;
	}

	private function entries(TimelineEntryMapper $mapper, ?EntryClosureCheckInterface $closures): TimelineEntryService {
		$visibility = $this->createMock(TimelineVisibilityService::class);
		$visibility->method('normalise')->willReturn('internal');
		$kinds = $this->createMock(TimelineKindService::class);
		$kinds->method('validateFields')->willReturn([]);

		return new TimelineEntryService(
			records: new TimelineEntryRecords(entryMapper: $mapper, kinds: $kinds, languages: new LanguageDetector()),
			visibility: $visibility,
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
			closures: $closures
		);
	}

	public function testANoteOnAClosedRecordIsRefusedBeforeItsCommentExists(): void {
		$comments = $this->createMock(ICommentsManager::class);
		$comments->expects($this->never())->method('create');
		$comments->expects($this->never())->method('save');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('medewerker');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$notes = new NoteService(
			commentsManager: $comments,
			accounts: new NoteAccounts(userSession: $session, userManager: $this->createMock(IUserManager::class)),
			visibility: $this->createMock(TimelineVisibilityServiceInterface::class),
			versions: $this->createMock(NoteVersionService::class),
			closures: $this->closures(closed: ['case-1'])
		);

		$this->expectException(EntriesClosedException::class);

		try {
			$notes->createNote(draft: new NoteDraft(objectUuid: 'case-1', message: 'Nieuwe brief'));
		} finally {
			$this->assertSame(['case-1'], $this->asked);
		}
	}

	public function testATimelineRecordOnAClosedRecordIsRefusedBeforeItIsInserted(): void {
		$mapper = $this->createMock(TimelineEntryMapper::class);
		$mapper->expects($this->never())->method('insert');

		$this->expectException(EntriesClosedException::class);

		$this->entries(mapper: $mapper, closures: $this->closures(closed: ['case-1']))
			->record(object: self::object(uuid: 'case-1'), data: ['message' => 'x']);
	}

	public function testAnOpenRecordStillTakesItsEntry(): void {
		$mapper = $this->createMock(TimelineEntryMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$entry = $this->entries(mapper: $mapper, closures: $this->closures(closed: ['other']))
			->record(object: self::object(uuid: 'case-1'), data: ['message' => 'x']);

		$this->assertInstanceOf(TimelineEntry::class, $entry);
		$this->assertSame(['case-1'], $this->asked);
	}

	public function testWithoutAClosureCheckEveryRecordTakesEntries(): void {
		$mapper = $this->createMock(TimelineEntryMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$this->entries(mapper: $mapper, closures: null)->record(object: self::object(uuid: 'case-1'), data: ['message' => 'x']);
	}

	public function testANoteToSeveralRecordsIsRefusedWholeWhenOneIsClosed(): void {
		$notes = $this->createMock(NoteServiceInterface::class);
		$notes->expects($this->never())->method('createNote');
		$mapper = $this->createMock(TimelineEntryMapper::class);
		$mapper->expects($this->never())->method('insert');
		$writer = new TimelineWriteService(
			notes: $notes,
			entries: $this->entries(mapper: $mapper, closures: $this->closures(closed: ['case-2'])),
			// Final; never reached, because the refusal comes before any derivation.
			derived: (new \ReflectionClass(TimelineEntryDerivations::class))->newInstanceWithoutConstructor(),
			logger: $this->createMock(LoggerInterface::class)
		);

		try {
			$writer->writeToMany(objects: [self::object(uuid: 'case-1'), self::object(uuid: 'case-2')], data: ['message' => 'x']);
			$this->fail('One closed record must refuse the whole write.');
		} catch (EntriesClosedException) {
		}

		$this->assertSame(['case-1', 'case-2'], $this->asked, 'Every record is asked before anything is written.');
	}
}
