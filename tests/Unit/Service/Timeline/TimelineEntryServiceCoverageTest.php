<?php

/**
 * Unit tests for TimelineEntryService — reading the record behind a note,
 * forgetting records without ever throwing, and a system write with no caller.
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
use OCA\OpenRegister\Notifications\Db\TimelineEntry;
use OCA\OpenRegister\Notifications\Db\TimelineEntryMapper;
use OCA\OpenRegister\Notifications\Service\Timeline\LanguageDetector;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntryRecords;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntryService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineKindService;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * TimelineEntryServiceCoverageTest.
 *
 * @covers \OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntryService
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Notifications\Db\TimelineEntry
 * @uses   \OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntryRecords
 * @uses   \OCA\OpenRegister\Notifications\Service\Timeline\LanguageDetector
 */
class TimelineEntryServiceCoverageTest extends TestCase {
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
	 * User session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

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
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$visibility = $this->createMock(TimelineVisibilityService::class);
		$visibility->method('normalise')->willReturn('internal');

		$this->service = new TimelineEntryService(
			new TimelineEntryRecords(
				entryMapper: $this->entryMapper,
				kinds: $this->kinds,
				languages: new LanguageDetector()
			),
			$visibility,
			$this->userSession,
			$this->logger
		);
	}//end setUp()

	public function testTheRecordBehindANoteIsLookedUpByItsComment(): void {
		$entry = new TimelineEntry();
		$entry->setUuid('entry-1');
		$this->entryMapper->expects($this->once())
			->method('findByComment')
			->with(42)
			->willReturn($entry);

		$this->assertSame($entry, $this->service->entryForNote(42));
	}//end testTheRecordBehindANoteIsLookedUpByItsComment()

	public function testANoteWrittenBeforeRecordsHasNone(): void {
		$this->entryMapper->expects($this->once())
			->method('findByComment')
			->with(7)
			->willReturn(null);

		$this->assertNull($this->service->entryForNote(7));
	}//end testANoteWrittenBeforeRecordsHasNone()

	public function testForgettingANoteReturnsTheRemovedCount(): void {
		$this->entryMapper->expects($this->once())
			->method('deleteForComment')
			->with(42)
			->willReturn(2);
		$this->logger->expects($this->never())->method('error');

		$this->assertSame(2, $this->service->forgetNote(42));
	}//end testForgettingANoteReturnsTheRemovedCount()

	public function testAFailingNoteForgetIsLoggedAndRemovesNothing(): void {
		$failure = new RuntimeException('database gone');
		$this->entryMapper->expects($this->once())
			->method('deleteForComment')
			->with(42)
			->willThrowException($failure);
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'[TimelineEntryService] The record for note 42 could not be removed',
				['exception' => $failure]
			);

		$this->assertSame(0, $this->service->forgetNote(42));
	}//end testAFailingNoteForgetIsLoggedAndRemovesNothing()

	public function testForgettingAnObjectReturnsTheRemovedCount(): void {
		$this->entryMapper->expects($this->once())
			->method('deleteForObject')
			->with('case-1')
			->willReturn(5);
		$this->logger->expects($this->never())->method('error');

		$this->assertSame(5, $this->service->forgetObject('case-1'));
	}//end testForgettingAnObjectReturnsTheRemovedCount()

	public function testAFailingObjectForgetIsLoggedAndRemovesNothing(): void {
		$failure = new RuntimeException('database gone');
		$this->entryMapper->expects($this->once())
			->method('deleteForObject')
			->with('case-1')
			->willThrowException($failure);
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'[TimelineEntryService] The records on object case-1 could not be removed',
				['exception' => $failure]
			);

		$this->assertSame(0, $this->service->forgetObject('case-1'));
	}//end testAFailingObjectForgetIsLoggedAndRemovesNothing()

	public function testASystemWriteWithNoCallerHasNoAuthor(): void {
		$this->userSession->expects($this->once())->method('getUser')->willReturn(null);
		$this->kinds->method('validateFields')->willReturn([]);
		$this->kinds->method('carriesFollowUp')->willReturn(false);
		$this->entryMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(static fn (TimelineEntry $entry): TimelineEntry => $entry);

		$object = new ObjectEntity();
		$object->setUuid('case-1');

		$entry = $this->service->record($object, ['message' => 'Binnengekomen per e-mail', 'commentId' => '12']);

		$this->assertNull($entry->getAuthor());
		$this->assertSame('case-1', $entry->getObjectUuid());
		$this->assertSame(12, $entry->getCommentId());
	}//end testASystemWriteWithNoCallerHasNoAuthor()
}//end class
