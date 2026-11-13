<?php

/**
 * Unit tests for TimelineEntryService — the entry as a record.
 *
 * Covers the record a write leaves behind, the plain note that is unchanged by
 * this change, the pin and who may set it, the follow-up close, the raw source
 * kept beside the entry, and the sibling links a multi-object note produces.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Timeline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Timeline;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntryRecords;
use OCA\OpenRegister\Notifications\Db\TimelineEntry;
use OCA\OpenRegister\Notifications\Db\TimelineEntryMapper;
use OCA\OpenRegister\Notifications\Service\Timeline\EntryPin;
use OCA\OpenRegister\Notifications\Service\Timeline\LanguageDetector;
use OCA\OpenRegister\Notifications\Service\Timeline\NoteRewrite;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntryService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineKindService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelinePermissionException;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineValidationException;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * TimelineEntryServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class TimelineEntryServiceTest extends TestCase {
	/**
	 * Record mapper mock.
	 *
	 * @var TimelineEntryMapper&MockObject
	 */
	private TimelineEntryMapper&MockObject $entryMapper;

	/**
	 * Kind service mock.
	 *
	 * @var TimelineKindService&MockObject
	 */
	private TimelineKindService&MockObject $kinds;

	/**
	 * Visibility service mock.
	 *
	 * @var TimelineVisibilityService&MockObject
	 */
	private TimelineVisibilityService&MockObject $visibility;

	/**
	 * User session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Service under test.
	 *
	 * @var TimelineEntryService
	 */
	private TimelineEntryService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->entryMapper = $this->createMock(TimelineEntryMapper::class);
		$this->kinds = $this->createMock(TimelineKindService::class);
		$this->visibility = $this->createMock(TimelineVisibilityService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->visibility->method('normalise')->willReturnCallback(
			static function (?string $value): string {
				if ($value === 'public') {
					return 'public';
				}

				return 'internal';
			}
		);

		$this->service = new TimelineEntryService(
			new TimelineEntryRecords(
				entryMapper: $this->entryMapper,
				kinds: $this->kinds,
				languages: new LanguageDetector()
			),
			$this->visibility,
			$this->userSession,
			$this->createMock(LoggerInterface::class)
		);
	}

	private function object(string $uuid = 'case-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema('3');

		return $object;
	}

	private function signIn(string $uid = 'handler'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function echoInsert(): void {
		$this->entryMapper->method('insert')
			->willReturnCallback(static fn (TimelineEntry $entry): TimelineEntry => $entry);
	}

	private function echoUpdate(): void {
		$this->entryMapper->method('update')
			->willReturnCallback(static fn (TimelineEntry $entry): TimelineEntry => $entry);
	}

	public function testARecordCarriesTheKindItsFieldsAndItsAuthor(): void {
		$this->signIn();
		$this->echoInsert();
		$this->kinds->method('validateFields')->willReturn(['channel' => 'telefoon']);
		$this->kinds->method('carriesFollowUp')->willReturn(false);

		$entry = $this->service->record(
			$this->object(),
			[
				'message' => 'Gebeld met de aanvrager',
				'kind' => 'contactmoment',
				'fields' => ['channel' => 'telefoon'],
			]
		);

		$this->assertSame('contactmoment', $entry->getKind());
		$this->assertSame(['channel' => 'telefoon'], $entry->getFields());
		$this->assertSame('handler', $entry->getAuthor());
		$this->assertSame('case-1', $entry->getObjectUuid());
		$this->assertNotNull($entry->getUuid());
	}

	public function testAnAuthorInThePayloadIsIgnored(): void {
		$this->signIn();
		$this->echoInsert();
		$this->kinds->method('validateFields')->willReturn([]);
		$this->kinds->method('carriesFollowUp')->willReturn(false);

		$entry = $this->service->record($this->object(), ['message' => 'Gebeld', 'author' => 'someone-else']);

		$this->assertSame('handler', $entry->getAuthor());
	}

	public function testAnEntryWithNoKindIsAPlainNoteWithNothingOnIt(): void {
		$this->signIn();
		$this->echoInsert();
		$this->kinds->method('validateFields')->willReturn([]);
		$this->kinds->method('carriesFollowUp')->willReturn(false);

		$entry = $this->service->record($this->object(), ['message' => 'Even genoteerd']);

		// The regression this change must not cause: a note keeps behaving as
		// a note. No kind, no fields, no follow-up, and not pinned.
		$this->assertNull($entry->getKind());
		$this->assertSame([], $entry->getFields());
		$this->assertNull($entry->getFollowUp());
		$this->assertFalse($entry->getPinned());
		$this->assertSame('internal', $entry->getVisibility());
	}

	public function testAKindThatDeclaresAFollowUpOpensOne(): void {
		$this->signIn();
		$this->echoInsert();
		$this->kinds->method('validateFields')->willReturn([]);
		$this->kinds->method('carriesFollowUp')->willReturn(true);

		$entry = $this->service->record($this->object(), ['message' => 'Terugbellen', 'kind' => 'terugbelverzoek']);

		$this->assertSame(TimelineEntry::FOLLOW_UP_OPEN, $entry->getFollowUp());
	}

	public function testAFieldThatDoesNotFitStopsTheWrite(): void {
		$this->signIn();
		$this->kinds->method('validateFields')
			->willThrowException(new TimelineValidationException(['channel' => 'no']));
		$this->entryMapper->expects($this->never())->method('insert');

		$this->expectException(TimelineValidationException::class);
		$this->service->record($this->object(), ['message' => 'x', 'kind' => 'contactmoment']);
	}

	public function testTheArrivalLanguageIsRecordedOnTheEntry(): void {
		$this->signIn();
		$this->echoInsert();
		$this->kinds->method('validateFields')->willReturn([]);
		$this->kinds->method('carriesFollowUp')->willReturn(false);

		$entry = $this->service->record(
			$this->object(),
			['message' => 'Dzień dobry, nie jest to dla mnie jasne, proszę o wyjaśnienie jak to działa.']
		);

		$this->assertSame('pl', $entry->getLanguage());
	}

	public function testTheRawSourceAndItsHeadersAreKeptBesideTheEntry(): void {
		$this->signIn();
		$this->echoInsert();
		$this->kinds->method('validateFields')->willReturn([]);
		$this->kinds->method('carriesFollowUp')->willReturn(false);

		$entry = $this->service->record(
			$this->object(),
			[
				'message' => 'Bijgevoegde brief',
				'rawSource' => "Received: from mail.example\r\nDate: Mon, 14 Sep 2026 09:12:00 +0200\r\n\r\nbody",
				'rawHeaders' => ['Date' => 'Mon, 14 Sep 2026 09:12:00 +0200'],
			]
		);

		$source = $this->service->sourceFor($entry);

		$this->assertStringContainsString('Received: from mail.example', (string)$source['source']);
		$this->assertSame(['Date' => 'Mon, 14 Sep 2026 09:12:00 +0200'], $source['headers']);
		// The list payload says a source EXISTS without carrying a mailbox
		// down the wire to draw a timeline.
		$this->assertTrue($entry->jsonSerialize()['hasSource']);
	}

	public function testAnEntryWithNoInboundSourceSaysSo(): void {
		$entry = new TimelineEntry();

		$this->assertNull($this->service->sourceFor($entry)['source']);
		$this->assertFalse($entry->jsonSerialize()['hasSource']);
	}

	public function testPinningNamesWhoPinnedIt(): void {
		$this->signIn('supervisor');
		$this->echoUpdate();
		$this->visibility->method('mayManage')->willReturn(true);

		$entry = $this->service->pin($this->object(), new EntryPin(new TimelineEntry(), true));

		$this->assertTrue($entry->getPinned());
		$this->assertSame('supervisor', $entry->getPinnedBy());
		$this->assertNotNull($entry->getPinnedAt());
	}

	public function testUnpinningForgetsWhoPinnedIt(): void {
		$this->signIn('supervisor');
		$this->echoUpdate();
		$this->visibility->method('mayManage')->willReturn(true);

		$pinned = $this->service->pin($this->object(), new EntryPin(new TimelineEntry(), true));
		$entry = $this->service->pin($this->object(), new EntryPin($pinned, false));

		$this->assertFalse($entry->getPinned());
		$this->assertNull($entry->getPinnedBy());
		$this->assertNull($entry->getPinnedAt());
	}

	public function testAReaderCannotPin(): void {
		$this->signIn('reader');
		$this->visibility->method('mayManage')->willReturn(false);
		$this->entryMapper->expects($this->never())->method('update');

		$this->expectException(TimelinePermissionException::class);
		$this->service->pin($this->object(), new EntryPin(new TimelineEntry(), true));
	}

	public function testClosingAFollowUpNamesTheCloserAndTheTime(): void {
		$this->signIn('kcc');
		$this->echoUpdate();
		$this->visibility->method('mayManage')->willReturn(true);

		$open = new TimelineEntry();
		$open->setFollowUp(TimelineEntry::FOLLOW_UP_OPEN);

		$entry = $this->service->closeFollowUp($this->object(), $open);

		$this->assertSame(TimelineEntry::FOLLOW_UP_DONE, $entry->getFollowUp());
		$this->assertSame('kcc', $entry->getClosedBy());
		$this->assertNotNull($entry->getClosedAt());
	}

	public function testAnEntryWithoutAFollowUpHasNothingToClose(): void {
		$this->signIn();
		$this->visibility->method('mayManage')->willReturn(true);
		$this->entryMapper->expects($this->never())->method('update');

		$this->expectException(TimelineValidationException::class);
		$this->service->closeFollowUp($this->object(), new TimelineEntry());
	}

	public function testAReaderCannotCloseAFollowUp(): void {
		$this->signIn('reader');
		$this->visibility->method('mayManage')->willReturn(false);

		$open = new TimelineEntry();
		$open->setFollowUp(TimelineEntry::FOLLOW_UP_OPEN);

		$this->expectException(TimelinePermissionException::class);
		$this->service->closeFollowUp($this->object(), $open);
	}

	public function testOneNoteOnTwoCasesLeavesEachNamingTheOther(): void {
		$this->echoUpdate();

		$first = new TimelineEntry();
		$first->setUuid('entry-a');
		$first->setObjectUuid('case-1');

		$second = new TimelineEntry();
		$second->setUuid('entry-b');
		$second->setObjectUuid('case-2');

		[$a, $b] = $this->service->linkSiblings([$first, $second]);

		$this->assertSame('entry-b', $a->getSiblings()[0]['entry']);
		$this->assertSame('case-2', $a->getSiblings()[0]['objectUuid']);
		$this->assertSame('entry-a', $b->getSiblings()[0]['entry']);
	}

	public function testASingleEntryHasNoSiblingsToLink(): void {
		$this->entryMapper->expects($this->never())->method('update');

		$only = new TimelineEntry();
		$this->assertSame([$only], $this->service->linkSiblings([$only]));
	}

	public function testAnEntryAddressedThroughAnotherObjectIsNotFound(): void {
		$entry = new TimelineEntry();
		$entry->setUuid('entry-a');
		$entry->setObjectUuid('case-1');
		$this->entryMapper->method('findByUuid')->willReturn($entry);

		$this->assertNotNull($this->service->get($this->object('case-1'), 'entry-a'));
		$this->assertNull($this->service->get($this->object('case-2'), 'entry-a'));
	}

	public function testANoteWrittenBeforeThisChangeHasNoRecordToSync(): void {
		$this->entryMapper->method('findByComment')->willReturn(null);
		$this->entryMapper->expects($this->never())->method('update');

		$this->assertNull($this->service->syncFromNote(new NoteRewrite(41, 'rewritten')));
	}

	public function testRewritingANoteRewritesTheIndexedCopyAndItsLanguage(): void {
		$this->echoUpdate();
		$stored = new TimelineEntry();
		$stored->setMessage('Gebeld met de aanvrager');
		$stored->setLanguage('nl');
		$this->entryMapper->method('findByComment')->willReturn($stored);

		$entry = $this->service->syncFromNote(new NoteRewrite(41, 'The applicant is going to send the papers that are missing'));

		$this->assertSame('The applicant is going to send the papers that are missing', $entry?->getMessage());
		$this->assertSame('en', $entry?->getLanguage());
	}
	// ---------------------------------------------------------------------
	// Withdrawn entries (object-archive-state, REQ-OAS-006, D-C29-4)
	// ---------------------------------------------------------------------

	/**
	 * The service with an audit trail that keeps every event it is given.
	 *
	 * @param array<int, ObjectAuditEvent> $events Filled with the audited events.
	 */
	private function auditedService(array &$events): TimelineEntryService {
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->method('recordObjectEvent')->willReturnCallback(
			static function (ObjectAuditEvent $event) use (&$events): AuditTrail {
				$events[] = $event;
				return new AuditTrail();
			}
		);

		return new TimelineEntryService(
			new TimelineEntryRecords(entryMapper: $this->entryMapper, kinds: $this->kinds, languages: new LanguageDetector()),
			$this->visibility,
			$this->userSession,
			$this->createMock(LoggerInterface::class),
			$audit
		);
	}

	public function testAWithdrawnEntryNamesWhoWhenAndWhyAndIsAudited(): void {
		$this->signIn('archivist');
		$this->echoUpdate();
		$this->visibility->method('mayManage')->willReturn(true);
		$events = [];
		$entry = new TimelineEntry();
		$entry->setUuid('entry-1');

		$written = $this->auditedService($events)->withdraw($this->object(), $entry, '  wrongly filed  ');

		$this->assertSame('archivist', $written->getWithdrawnBy());
		$this->assertNotNull($written->getWithdrawnAt());
		$this->assertSame('wrongly filed', $written->getWithdrawnReason());
		$this->assertSame(['by' => 'archivist', 'at' => $written->getWithdrawnAt()->format(\DateTime::ATOM), 'reason' => 'wrongly filed'], $written->jsonSerialize()['withdrawn']);
		$this->assertCount(1, $events);
		$this->assertSame(TimelineEntryService::WITHDRAW_AUDIT_ACTION, $events[0]->action);
		$this->assertSame('entry-1', $events[0]->context['entry']);
		$this->assertSame('wrongly filed', $events[0]->context['reason']);
	}

	public function testASecondWithdrawalLeavesTheFirstStanding(): void {
		$this->signIn('archivist');
		$this->visibility->method('mayManage')->willReturn(true);
		$this->entryMapper->expects($this->never())->method('update');
		$events = [];
		$entry = new TimelineEntry();
		$entry->setWithdrawnBy('first');
		$entry->setWithdrawnAt(new \DateTime('2026-11-01T10:00:00+00:00'));
		$entry->setWithdrawnReason('original');

		$same = $this->auditedService($events)->withdraw($this->object(), $entry, 'again');

		$this->assertSame(['first', 'original'], [$same->getWithdrawnBy(), $same->getWithdrawnReason()]);
		$this->assertSame([], $events);
	}

	public function testAReaderCannotWithdraw(): void {
		$this->signIn('reader');
		$this->visibility->method('mayManage')->willReturn(false);
		$this->entryMapper->expects($this->never())->method('update');

		$this->expectException(TimelinePermissionException::class);
		$this->expectExceptionMessage('You do not have permission to withdraw entries on this object');

		$this->service->withdraw($this->object(), new TimelineEntry(), 'reason');
	}

	public function testAWithdrawalNeedsAReason(): void {
		$this->visibility->method('mayManage')->willReturn(true);
		$this->entryMapper->expects($this->never())->method('update');

		try {
			$this->service->withdraw($this->object(), new TimelineEntry(), '   ');
			$this->fail('A withdrawal without a reason must be refused.');
		} catch (TimelineValidationException $e) {
			$this->assertSame(['withdrawn.reason' => 'A withdrawal needs a reason'], $e->getErrors());
		}
	}

	public function testTheWorkingTimelineLeavesWithdrawnEntriesOutUnlessAsked(): void {
		$asked = [];
		$this->entryMapper->method('findForObject')->willReturnCallback(
			static function (string $objectUuid, ?string $visibility, int $limit, int $offset, bool $includeWithdrawn) use (&$asked): array {
				$asked[] = [$objectUuid, $visibility, $includeWithdrawn];
				return [];
			}
		);

		$this->service->listForObject($this->object('case-9'));
		$this->service->listForObject($this->object('case-9'), new ListQuery(limit: 10, filters: ['visibility' => 'public', 'withdrawn' => true]));

		$this->assertSame([['case-9', null, false], ['case-9', 'public', true]], $asked);
	}

	public function testAnEntryInTheWorkingTimelineSerialisesNoWithdrawal(): void {
		$this->assertNull((new TimelineEntry())->jsonSerialize()['withdrawn']);
		$this->assertNull((new TimelineEntry())->withdrawal());
	}
}
