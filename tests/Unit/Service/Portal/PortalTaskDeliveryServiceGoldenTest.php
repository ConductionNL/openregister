<?php

/**
 * Golden test for the message and the rows PortalTaskDeliveryService writes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Portal;

use DateTime;
use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Db\PortalTaskDeliveryMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Portal\PortalTaskDeliveryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The delivery message and rows, pinned.
 */
class PortalTaskDeliveryServiceGoldenTest extends TestCase {

	/**
	 * An external task.
	 *
	 * @return Task The task.
	 */
	private static function task(): Task {
		$task = new Task();
		$task->setUuid('task-1');
		$task->setTitle('Lever bewijs aan');
		$task->setDescription('Een kopie volstaat');
		$task->setAssignee('party:bsn-1');
		$task->setMetadata(['cycle' => '2', 'upload' => ['maxFiles' => 3]]);
		$task->setDueAt(new DateTime('2026-12-01T10:00:00+00:00'));
		$task->setObjectUuid('case-9');
		$task->setRegisterId(4);
		$task->setSchemaId(7);

		return $task;
	}//end task()

	public function testTheMessageCarriesDescriptorsAndTheCaseAnchorOnly(): void {
		$service = new PortalTaskDeliveryService($this->createMock(PortalTaskDeliveryMapper::class), $this->createMock(LoggerInterface::class));

		$this->assertSame(
			[
				'taskUuid' => 'task-1',
				'title' => 'Lever bewijs aan',
				'description' => 'Een kopie volstaat',
				'reason' => 'onleesbaar',
				'cycle' => 2,
				'partyReference' => 'party:bsn-1',
				'dueAt' => '2026-12-01T10:00:00+00:00',
				'expiresAt' => null,
				'case' => ['uuid' => 'case-9', 'register' => 4, 'schema' => 7],
				'upload' => ['maxFiles' => 3],
			],
			$service->messageFor(self::task(), 'onleesbaar')
		);
	}//end testTheMessageCarriesDescriptorsAndTheCaseAnchorOnly()

	public function testOneRowPerChannelAndAFailedChannelIsLoggedAndSkipped(): void {
		$rows = [];
		$mapper = $this->createMock(PortalTaskDeliveryMapper::class);
		$mapper->method('insert')->willReturnCallback(
			static function (PortalTaskDelivery $row) use (&$rows): PortalTaskDelivery {
				$rows[] = [$row->getTaskUuid(), $row->getPartyReference(), $row->getChannel(), $row->getKind(), $row->getMessage()];
				if ($row->getChannel() === PortalTaskDelivery::CHANNELS[1]) {
					throw new RuntimeException('db gone');
				}

				return $row;
			}
		);
		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$written = (new PortalTaskDeliveryService($mapper, $logger))->request(self::task(), 'ask', ['m' => 1]);

		$this->assertCount(1, $written);
		$this->assertSame(
			[
				['task-1', 'party:bsn-1', PortalTaskDelivery::CHANNELS[0], 'ask', ['m' => 1]],
				['task-1', 'party:bsn-1', PortalTaskDelivery::CHANNELS[1], 'ask', ['m' => 1]],
			],
			$rows
		);
		$this->assertSame(
			[
				[
					'[PortalTaskDeliveryService] Could not record the ' . PortalTaskDelivery::CHANNELS[1] . ' delivery request of task task-1; the task and its run stand, the delivery state reads not-recorded: db gone',
					['task' => 'task-1', 'channel' => PortalTaskDelivery::CHANNELS[1], 'kind' => 'ask'],
				],
			],
			$warnings
		);
	}//end testOneRowPerChannelAndAFailedChannelIsLoggedAndSkipped()
}//end class
