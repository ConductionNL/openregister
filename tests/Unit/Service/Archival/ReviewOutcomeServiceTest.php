<?php

declare(strict_types=1);

/**
 * ReviewOutcomeService tests.
 *
 * Pins what each of the three answers does to the record itself: a retention
 * moves the archiefactiedatum and says why, a transfer reaches the e-Depot
 * transfer path and comes back with the list it was put on, and a destruction
 * touches the record not at all, because the approved list's execution job is
 * what destroys it and produces the verklaring van vernietiging.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

namespace Unit\Service\Archival;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\DestructionReviewService;
use OCA\OpenRegister\Service\Archival\ReviewOutcomeService;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for ReviewOutcomeService.
 */
class ReviewOutcomeServiceTest extends TestCase {

	private MagicMapper&MockObject $objectMapper;
	private TransferListService&MockObject $transferListService;
	private ReviewOutcomeService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'update'])
			->getMock();

		$this->transferListService = $this->getMockBuilder(TransferListService::class)
			->disableOriginalConstructor()
			->onlyMethods(['createTransferList'])
			->getMock();

		$this->service = new ReviewOutcomeService(
			$this->objectMapper,
			$this->transferListService
		);
	}

	/**
	 * An object carrying a disposal date.
	 *
	 * @param array<string, mixed> $retention The retention block.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(array $retention = ['archiefactiedatum' => '2026-03-01']): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRetention($retention);

		return $object;
	}

	public function testDestroyingTouchesTheRecordNotAtAll(): void {
		$this->objectMapper->expects($this->never())->method('find');
		$this->objectMapper->expects($this->never())->method('update');

		$this->assertNull(
			$this->service->apply(
				answer: DestructionReviewService::ANSWER_DESTROY,
				entryUuid: 'obj-1',
				reason: 'Past its date'
			)
		);
	}

	public function testRetainingMovesTheDateAndSaysWhy(): void {
		$object = $this->object();
		$this->objectMapper->method('find')->willReturn($object);

		$saved = null;
		$this->objectMapper->expects($this->once())
			->method('update')
			->willReturnCallback(static function (ObjectEntity $entity) use (&$saved): ObjectEntity {
				$saved = $entity;
				return $entity;
			});

		$this->service->apply(
			answer: DestructionReviewService::ANSWER_RETAIN,
			entryUuid: 'obj-1',
			reason: 'Lopende bezwaarprocedure',
			newDate: '2028-03-01'
		);

		$retention = $saved->getRetention();
		$this->assertSame('2028-03-01', $retention['archiefactiedatum']);
		$this->assertSame('2026-03-01', $retention['originalArchiefactiedatum']);
		$this->assertCount(1, $retention['retentionHistory']);
		$this->assertSame('Lopende bezwaarprocedure', $retention['retentionHistory'][0]['reason']);
		$this->assertSame('2028-03-01', $retention['retentionHistory'][0]['newArchiefactiedatum']);
	}

	public function testASecondRetentionKeepsTheDateTheSelectielijstProduced(): void {
		$object = $this->object(
			[
				'archiefactiedatum' => '2028-03-01',
				'originalArchiefactiedatum' => '2026-03-01',
				'retentionHistory' => [
					['date' => '2026-03-02T11:30:00+00:00', 'reason' => 'Lopende bezwaarprocedure', 'newArchiefactiedatum' => '2028-03-01'],
				],
			]
		);
		$this->objectMapper->method('find')->willReturn($object);

		$saved = null;
		$this->objectMapper->method('update')
			->willReturnCallback(static function (ObjectEntity $entity) use (&$saved): ObjectEntity {
				$saved = $entity;
				return $entity;
			});

		$this->service->apply(
			answer: DestructionReviewService::ANSWER_RETAIN,
			entryUuid: 'obj-1',
			reason: 'Nog steeds in behandeling',
			newDate: '2030-03-01'
		);

		$this->assertSame('2026-03-01', $saved->getRetention()['originalArchiefactiedatum']);
		$this->assertCount(2, $saved->getRetention()['retentionHistory']);
	}

	public function testTransferHandsTheRecordToTheTransferPathAndNamesTheList(): void {
		$object = $this->object();
		$this->objectMapper->method('find')->willReturn($object);

		$this->transferListService->expects($this->once())
			->method('createTransferList')
			->with([$object])
			->willReturn(['uuid' => 'transfer-list-7', 'status' => 'in_review']);

		$this->assertSame(
			'transfer-list-7',
			$this->service->apply(
				answer: DestructionReviewService::ANSWER_TRANSFER,
				entryUuid: 'obj-1',
				reason: 'Blijvend bewaren'
			)
		);
	}

	public function testATransferThatCannotBeMadeRefusesRatherThanReportingSuccess(): void {
		$this->objectMapper->method('find')->willReturn($this->object());
		$this->transferListService->method('createTransferList')
			->willThrowException(new RuntimeException('e-Depot not configured'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/transfer list could not be made/');

		$this->service->apply(
			answer: DestructionReviewService::ANSWER_TRANSFER,
			entryUuid: 'obj-1',
			reason: 'Blijvend bewaren'
		);
	}

	public function testATransferListWithNoUuidIsRefused(): void {
		$this->objectMapper->method('find')->willReturn($this->object());
		$this->transferListService->method('createTransferList')->willReturn(['status' => 'in_review']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/came back without a uuid/');

		$this->service->apply(
			answer: DestructionReviewService::ANSWER_TRANSFER,
			entryUuid: 'obj-1',
			reason: 'Blijvend bewaren'
		);
	}

	public function testARecordThatCannotBeReadRefusesTheAnswer(): void {
		$this->objectMapper->method('find')->willThrowException(new RuntimeException('gone'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/the record could not be read/');

		$this->service->apply(
			answer: DestructionReviewService::ANSWER_RETAIN,
			entryUuid: 'obj-1',
			reason: 'Lopende procedure',
			newDate: '2030-01-01'
		);
	}
}
