<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Controller\TransferController::eligible()}.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\TransferListRunInterface;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCA\OpenRegister\Tests\Unit\Controller\Support\TransferControllerFactory;
use OCP\AppFramework\Http;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The read of the records due for transfer.
 */
class TransferControllerEligibleTest extends TestCase {

	/**
	 * The records the transfer check would list come back, minus those on an active list.
	 *
	 * @return void
	 */
	public function testListsTheRecordsDueForTransferWithoutThoseOnAnActiveList(): void {
		$records = $this->createMock(TransferRecordService::class);
		$lists = [['uuid' => 'list-1', 'status' => 'in_review']];
		$records->method('listTransferLists')->willReturn($lists);

		$run = $this->createMock(TransferListRunInterface::class);
		$run->expects($this->once())
			->method('getObjectsOnActiveTransferLists')
			->with($lists)
			->willReturn(['held-on-list']);

		$object = new ObjectEntity();
		$object->setUuid('rec-1');
		$object->setName('Besluit 2001');
		$object->setRegister('5');
		$object->setSchema('7');
		$object->setRetention(['archiefnominatie' => 'bewaren', 'archiefactiedatum' => '2020-01-01']);

		$retention = $this->createMock(RetentionServiceInterface::class);
		$retention->expects($this->once())
			->method('findEligibleForTransfer')
			->with(['held-on-list'])
			->willReturn([$object]);

		$response = $this->controller(records: $records, retention: $retention, run: $run)->eligible();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'results' => [
					[
						'uuid'      => 'rec-1',
						'name'      => 'Besluit 2001',
						'register'  => '5',
						'schema'    => '7',
						'retention' => ['archiefnominatie' => 'bewaren', 'archiefactiedatum' => '2020-01-01'],
					],
				],
				'total'     => 1,
				'available' => true,
			],
			$response->getData()
		);
	}//end testListsTheRecordsDueForTransferWithoutThoseOnAnActiveList()

	/**
	 * Without the retention service the read says it is not available instead of failing.
	 *
	 * @return void
	 */
	public function testWithoutTheRetentionServiceTheReadIsNotAvailable(): void {
		$records = $this->createMock(TransferRecordService::class);
		$records->expects($this->never())->method('listTransferLists');

		$response = $this->controller(records: $records, retention: null, run: null)->eligible();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => [], 'total' => 0, 'available' => false], $response->getData());
	}//end testWithoutTheRetentionServiceTheReadIsNotAvailable()

	/**
	 * Build the controller.
	 *
	 * @param TransferRecordService          $records   The transfer records.
	 * @param RetentionServiceInterface|null $retention The retention service.
	 * @param TransferListRunInterface|null  $run       The transfer list run.
	 *
	 * @return \OCA\OpenRegister\Controller\TransferController
	 */
	private function controller(
		TransferRecordService $records,
		?RetentionServiceInterface $retention,
		?TransferListRunInterface $run,
	): \OCA\OpenRegister\Controller\TransferController {
		return TransferControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$records,
			$this->createMock(IJobList::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(TransferListService::class),
			$this->createMock(IUserSession::class),
			$retention,
			$run,
		);
	}//end controller()
}//end class
