<?php

/**
 * Unit test for TransferRecordService: how transfer lists and proofs are saved,
 * found and listed through the object operations, and how failures read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\TransferRecordService
 * @uses   \OCA\OpenRegister\Service\WriteCause
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses \OCA\OpenRegister\Service\CauseFrame
 */
class TransferRecordServiceBehaviourTest extends TestCase {

	/**
	 * The object operations.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface&MockObject $objects;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mocked object operations and logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The service.
	 *
	 * @return TransferRecordService
	 */
	private function service(): TransferRecordService {
		return new TransferRecordService(objectService: $this->objects, logger: $this->logger);
	}//end service()

	/**
	 * An object with the given data and uuid.
	 *
	 * @param array<string, mixed> $data The data.
	 * @param string               $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setUuid($uuid);
		return $entity;
	}//end entity()

	/**
	 * A transfer list without a uuid is saved as new, without an @self, and rendered with the uuid it got.
	 *
	 * @return void
	 */
	public function testANewTransferListIsSavedWithoutAUuid(): void {
		$this->objects->expects($this->once())->method('saveObject')
			->with(['title' => 'Overdracht 2026'], $this->callback(static fn (ObjectSaveOptions $options): bool => $options->extend == [] && $options->target->register == 'edepot-transfers' && $options->target->schema == 'edepotTransfer' && $options->uuid === null))
			->willReturn($this->entity(data: ['title' => 'Overdracht 2026'], uuid: 'u-new'));

		$this->assertSame(['id' => 'u-new', 'title' => 'Overdracht 2026', 'uuid' => 'u-new'], $this->service()->saveTransferList(transferList: ['title' => 'Overdracht 2026']));
	}//end testANewTransferListIsSavedWithoutAUuid()

	/**
	 * A transfer list with a uuid is saved under it, with an @self naming it.
	 *
	 * @return void
	 */
	public function testAKnownTransferListIsSavedUnderItsUuid(): void {
		$this->objects->expects($this->once())->method('saveObject')
			->with(['uuid' => 'u-1', 'title' => 't', '@self' => ['uuid' => 'u-1']], $this->callback(static fn (ObjectSaveOptions $options): bool => $options->extend == [] && $options->target->register == 'edepot-transfers' && $options->target->schema == 'edepotTransfer' && $options->uuid == 'u-1'))
			->willReturn($this->entity(data: ['title' => 't'], uuid: 'u-1'));

		$this->assertSame(['id' => 'u-1', 'title' => 't', 'uuid' => 'u-1'], $this->service()->saveTransferList(transferList: ['uuid' => 'u-1', 'title' => 't']));
	}//end testAKnownTransferListIsSavedUnderItsUuid()

	/**
	 * A transfer list is loaded from the transfer register and schema; one that is not there, or fails, is null.
	 *
	 * @return void
	 */
	public function testATransferListIsLoadedOrNull(): void {
		$this->objects->expects($this->exactly(3))->method('find')
			->with(...FindCallShape::findArgs('u-1', [], false, 'edepot-transfers', 'edepotTransfer'))
			->willReturnOnConsecutiveCalls(
				$this->entity(data: ['title' => 't'], uuid: 'u-1'),
				null,
				$this->throwException(new RuntimeException('db'))
			);
		$service = $this->service();

		$this->assertSame(['id' => 'u-1', 'title' => 't', 'uuid' => 'u-1'], $service->loadTransferList(uuid: 'u-1'));
		$this->assertNull($service->loadTransferList(uuid: 'u-1'));
		$this->assertNull($service->loadTransferList(uuid: 'u-1'));
	}//end testATransferListIsLoadedOrNull()

	/**
	 * Listing keeps only the array rows; a failing enumeration is empty and warned about.
	 *
	 * @return void
	 */
	public function testListingKeepsArrayRowsAndFailsSoft(): void {
		$this->objects->expects($this->exactly(2))->method('findAll')
			->with(['filters' => ['register' => 'edepot-transfers', 'schema' => 'edepotTransfer']])
			->willReturnOnConsecutiveCalls(['a' => ['uuid' => 'u-1'], 'b' => 'junk', 'c' => ['uuid' => 'u-2']], $this->throwException(new RuntimeException('index down')));
		$this->logger->expects($this->once())->method('warning')->with('[TransferRecordService] transfer-list enumeration failed: index down');
		$service = $this->service();

		$this->assertSame([['uuid' => 'u-1'], ['uuid' => 'u-2']], $service->listTransferLists());
		$this->assertSame([], $service->listTransferLists());
	}//end testListingKeepsArrayRowsAndFailsSoft()

	/**
	 * A proof is found by object and transfer; blank ids, no array row and a failing search are null.
	 *
	 * @return void
	 */
	public function testAProofIsFoundByObjectAndTransfer(): void {
		$filters = ['filters' => ['register' => 'edepot-transfers', 'schema' => 'edepotTransferProof', 'objectUuid' => 'o-1', 'transferUuid' => 't-1']];
		$this->objects->expects($this->exactly(3))->method('findAll')->with($filters)
			->willReturnOnConsecutiveCalls(['junk', ['uuid' => 'p-1']], ['junk'], $this->throwException(new RuntimeException('x')));
		$service = $this->service();

		$this->assertNull($service->findProof(objectUuid: '', transferUuid: 't-1'));
		$this->assertNull($service->findProof(objectUuid: 'o-1', transferUuid: ''));
		$this->assertSame(['uuid' => 'p-1'], $service->findProof(objectUuid: 'o-1', transferUuid: 't-1'));
		$this->assertNull($service->findProof(objectUuid: 'o-1', transferUuid: 't-1'));
		$this->assertNull($service->findProof(objectUuid: 'o-1', transferUuid: 't-1'));
	}//end testAProofIsFoundByObjectAndTransfer()

	/**
	 * An existing proof is returned as it is and nothing is created.
	 *
	 * @return void
	 */
	public function testAnExistingProofIsNotCreatedAgain(): void {
		$this->objects->method('findAll')->willReturn([['uuid' => 'p-1', 'confirmedAt' => 'then']]);
		$this->objects->expects($this->never())->method('createObject');

		$this->assertSame(
			['uuid' => 'p-1', 'confirmedAt' => 'then'],
			$this->service()->createProof(proof: ['objectUuid' => 'o-1', 'transferUuid' => 't-1'])
		);
	}//end testAnExistingProofIsNotCreatedAgain()

	/**
	 * A new proof keeps its own confirmation moment, or gets the current one, and lands in the proof schema.
	 *
	 * @return void
	 */
	public function testANewProofIsDatedAndCreatedInTheProofSchema(): void {
		$this->objects->method('findAll')->willReturn([]);
		$created = [];
		$this->objects->method('createObject')->willReturnCallback(
			function (array $data) use (&$created): ObjectEntity {
				$created[] = $data;
				return $this->entity(data: ['objectUuid' => $data['objectUuid']], uuid: 'p-'.count($created));
			}
		);
		$service = $this->service();

		$this->assertSame(
			['id' => 'p-1', 'objectUuid' => 'o-1', 'uuid' => 'p-1'],
			$service->createProof(proof: ['objectUuid' => 'o-1', 'transferUuid' => 't-1', 'confirmedAt' => '2026-01-02T00:00:00+00:00'])
		);
		$service->createProof(proof: ['objectUuid' => 'o-2', 'transferUuid' => 't-1']);

		$this->assertSame(
			['objectUuid' => 'o-1', 'transferUuid' => 't-1', 'confirmedAt' => '2026-01-02T00:00:00+00:00', '@self' => ['register' => 'edepot-transfers', 'schema' => 'edepotTransferProof']],
			$created[0]
		);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $created[1]['confirmedAt']);
	}//end testANewProofIsDatedAndCreatedInTheProofSchema()
}//end class
