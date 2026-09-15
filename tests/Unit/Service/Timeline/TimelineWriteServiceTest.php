<?php

/**
 * Unit tests for TimelineWriteService — writing one entry, end to end.
 *
 * Covers the order a write happens in, the derived steps that fail soft
 * without taking the entry with them, the canned text a payload may name
 * instead of a message, and the multi-object note.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TimelineEntry;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\Timeline\EntryMentionService;
use OCA\OpenRegister\Service\Timeline\ReferenceService;
use OCA\OpenRegister\Service\Timeline\TextBlockService;
use OCA\OpenRegister\Service\Timeline\TimelineEntryService;
use OCA\OpenRegister\Service\Timeline\TimelineValidationException;
use OCA\OpenRegister\Service\Timeline\TimelineWriteService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * TimelineWriteServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class TimelineWriteServiceTest extends TestCase {
	/**
	 * Note service mock.
	 *
	 * @var NoteService&MockObject
	 */
	private NoteService&MockObject $notes;

	/**
	 * Record service mock.
	 *
	 * @var TimelineEntryService&MockObject
	 */
	private TimelineEntryService&MockObject $entries;

	/**
	 * Reference service mock.
	 *
	 * @var ReferenceService&MockObject
	 */
	private ReferenceService&MockObject $references;

	/**
	 * Mention service mock.
	 *
	 * @var EntryMentionService&MockObject
	 */
	private EntryMentionService&MockObject $mentions;

	/**
	 * Text block service mock.
	 *
	 * @var TextBlockService&MockObject
	 */
	private TextBlockService&MockObject $blocks;

	/**
	 * Service under test.
	 *
	 * @var TimelineWriteService
	 */
	private TimelineWriteService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->notes = $this->createMock(NoteService::class);
		$this->entries = $this->createMock(TimelineEntryService::class);
		$this->references = $this->createMock(ReferenceService::class);
		$this->mentions = $this->createMock(EntryMentionService::class);
		$this->blocks = $this->createMock(TextBlockService::class);

		$this->notes->method('createNote')->willReturn(['id' => 41, 'message' => 'x', 'visibility' => 'internal']);

		$this->service = new TimelineWriteService(
			$this->notes,
			$this->entries,
			$this->references,
			$this->mentions,
			$this->blocks,
			$this->createMock(LoggerInterface::class)
		);
	}

	private function object(string $uuid = 'case-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject(['zaaknummer' => 'Z-2026-0044']);

		return $object;
	}

	private function recordsAs(string $entryUuid): TimelineEntry {
		$entry = new TimelineEntry();
		$entry->setUuid($entryUuid);
		$this->entries->method('record')->willReturn($entry);

		return $entry;
	}

	public function testAWriteLeavesANoteARecordItsReferencesAndItsMentions(): void {
		$this->recordsAs('entry-a');
		$this->notes->expects($this->once())->method('createNote');
		$this->references->expects($this->once())->method('record')
			->with('entry-a', 'case-1', 'zie Z-2026-0044 en @jurist');
		$this->mentions->expects($this->once())->method('apply');

		$entry = $this->service->write($this->object(), ['message' => 'zie Z-2026-0044 en @jurist']);

		$this->assertSame('entry-a', $entry->getUuid());
	}

	public function testAReferenceStepThatFailsDoesNotTakeTheEntryWithIt(): void {
		$this->recordsAs('entry-a');
		$this->references->method('record')->willThrowException(new \RuntimeException('regex gone'));

		// Losing somebody's note to a pattern that stopped compiling is the
		// worse trade, so the entry stands and the failure is logged.
		$this->assertSame('entry-a', $this->service->write($this->object(), ['message' => 'x'])->getUuid());
	}

	public function testAMentionStepThatFailsDoesNotTakeTheEntryWithIt(): void {
		$this->recordsAs('entry-a');
		$this->mentions->method('apply')->willThrowException(new \RuntimeException('notifications down'));

		$this->assertSame('entry-a', $this->service->write($this->object(), ['message' => 'x'])->getUuid());
	}

	public function testAPayloadWithNeitherMessageNorTextBlockIsRefused(): void {
		$this->notes->expects($this->never())->method('createNote');

		$this->expectException(TimelineValidationException::class);
		$this->service->write($this->object(), []);
	}

	public function testATextBlockIsSubstitutedWithTheObjectsOwnValues(): void {
		$this->recordsAs('entry-a');
		$this->blocks->expects($this->once())->method('insert')
			->with(
				'ontvangstbevestiging',
				$this->callback(
					static fn (array $variables): bool => ($variables['zaaknummer'] === 'Z-2026-0044'
						&& $variables['objectUuid'] === 'case-1')
				)
			)
			->willReturn('Uw aanvraag Z-2026-0044 is ontvangen.');
		$this->notes->expects($this->once())->method('createNote')
			->with('case-1', 'Uw aanvraag Z-2026-0044 is ontvangen.', null)
			->willReturn(['id' => 41]);

		$this->service->write($this->object(), ['textBlock' => 'ontvangstbevestiging']);
	}

	public function testACallerThatSentTextMeantTheText(): void {
		$this->recordsAs('entry-a');
		$this->blocks->expects($this->never())->method('insert');

		$this->service->write(
			$this->object(),
			['message' => 'eigen tekst', 'textBlock' => 'ontvangstbevestiging']
		);
	}

	public function testOneNoteOnTwoCasesWritesTwoEntriesAndTiesThem(): void {
		$first = new TimelineEntry();
		$first->setUuid('entry-a');
		$second = new TimelineEntry();
		$second->setUuid('entry-b');

		$this->entries->method('record')->willReturnOnConsecutiveCalls($first, $second);
		$this->entries->expects($this->once())->method('linkSiblings')
			->with([$first, $second])
			->willReturn([$first, $second]);

		$written = $this->service->writeToMany(
			[$this->object('case-1'), $this->object('case-2')],
			['message' => 'afstemmingsverslag']
		);

		$this->assertCount(2, $written);
	}

	public function testRewritingRewritesTheReferencesFromTheNewText(): void {
		$entry = new TimelineEntry();
		$entry->setUuid('entry-a');
		$this->entries->method('syncFromNote')->willReturn($entry);
		$this->references->expects($this->once())->method('record')
			->with('entry-a', 'case-1', 'de verwijzing is eruit');
		// Somebody already named and subscribed stays subscribed: unsubscribing
		// them by deleting a word is a silent act on another person's inbox.
		$this->mentions->expects($this->never())->method('apply');

		$this->service->rewrite($this->object(), 41, 'de verwijzing is eruit');
	}

	public function testAVisibilityOnlyEditRewritesNoReferences(): void {
		$entry = new TimelineEntry();
		$entry->setUuid('entry-a');
		$this->entries->method('syncFromNote')->willReturn($entry);
		$this->references->expects($this->never())->method('record');

		$this->service->rewrite($this->object(), 41, null, 'public');
	}

	public function testANoteThatFailsToProjectIsNotLost(): void {
		$this->entries->method('record')->willThrowException(new \RuntimeException('table gone'));

		// A note that was accepted must not be lost to the index.
		$this->assertNull(
			$this->service->projectNote($this->object(), ['id' => 41, 'message' => 'x'])
		);
	}

	public function testProjectingANoteRecordsWhatItPointsAtAndWhomItNamed(): void {
		$this->recordsAs('entry-a');
		$this->references->expects($this->once())->method('record');
		$this->mentions->expects($this->once())->method('apply');

		$entry = $this->service->projectNote(
			$this->object(),
			['id' => 41, 'message' => 'zie Z-2026-0044', 'visibility' => 'internal'],
			'zaken',
			'zaak'
		);

		$this->assertSame('entry-a', $entry?->getUuid());
	}

	public function testForgettingANoteForgetsWhatItPointedAt(): void {
		$this->references->expects($this->once())->method('forget')->with('entry-a');
		$this->entries->expects($this->once())->method('forgetNote')->with(41);

		$this->service->forgetNote(41, 'entry-a');
	}

	public function testANoteWithNoRecordStillForgetsCleanly(): void {
		$this->references->expects($this->never())->method('forget');
		$this->entries->expects($this->once())->method('forgetNote')->with(41);

		$this->service->forgetNote(41);
	}
}
