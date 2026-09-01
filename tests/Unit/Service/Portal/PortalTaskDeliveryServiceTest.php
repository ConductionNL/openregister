<?php

/**
 * The delivery seam: two channels per request, never a Nextcloud one; a
 * recording failure leaves the ask standing; the summary the caseworker reads.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Portal;

use DateTime;
use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Db\PortalTaskDeliveryMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Portal\PortalTaskDeliveryService;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;

/**
 * Tests for {@see PortalTaskDeliveryService} and {@see PortalTaskDelivery}.
 *
 * @covers \OCA\OpenRegister\Service\Portal\PortalTaskDeliveryService
 * @covers \OCA\OpenRegister\Db\PortalTaskDelivery
 */
class PortalTaskDeliveryServiceTest extends TestCase {

	/**
	 * The mapper, mocked.
	 *
	 * @var PortalTaskDeliveryMapper&MockObject
	 */
	private PortalTaskDeliveryMapper&MockObject $deliveries;

	/**
	 * The service under test.
	 *
	 * @var PortalTaskDeliveryService
	 */
	private PortalTaskDeliveryService $service;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->deliveries = $this->createMock(PortalTaskDeliveryMapper::class);
		$this->service = new PortalTaskDeliveryService(deliveries: $this->deliveries, logger: new NullLogger());
	}//end setUp()

	/**
	 * An external task.
	 *
	 * @return Task The task.
	 */
	private function task(): Task {
		$task = new Task();
		$task->setUuid('t-1');
		$task->setTitle('Send the payslip');
		$task->setPerformerType(Task::PERFORMER_EXTERNAL);
		$task->setAssignee('party:bsn-1');
		$task->setObjectUuid('case-7');
		$task->setRegisterId(3);
		$task->setMetadata(['cycle' => 2, 'upload' => ['required' => true]]);

		return $task;
	}//end task()

	/**
	 * A delivery row.
	 *
	 * @param string $channel The channel.
	 * @param string $state The state.
	 * @param string $requestedAt When.
	 *
	 * @return PortalTaskDelivery The row.
	 */
	private function row(string $channel, string $state, string $requestedAt = '2026-09-01T10:00:00+00:00'): PortalTaskDelivery {
		$row = new PortalTaskDelivery();
		$row->setChannel($channel);
		$row->setState($state);
		$row->setKind(PortalTaskDelivery::KIND_ASK);
		$row->setRequestedAt(new DateTime($requestedAt));
		if ($state === PortalTaskDelivery::STATE_DELIVERED) {
			$row->setDeliveredAt(new DateTime($requestedAt));
		}

		return $row;
	}//end row()

	/**
	 * A request writes one row per channel: portal inbox and mail, both
	 * `requested`, carrying the message and the party.
	 *
	 * @return void
	 */
	public function testARequestWritesOneRowPerChannel(): void {
		$written = [];
		$this->deliveries->expects($this->exactly(2))
			->method('insert')
			->willReturnCallback(
				static function (Entity $row) use (&$written): PortalTaskDelivery {
					$written[] = $row;

					return $row;
				}
			);

		$message = $this->service->messageFor(task: $this->task(), reason: 'unreadable scan');
		$rows = $this->service->request(task: $this->task(), kind: PortalTaskDelivery::KIND_RE_ASK, message: $message);

		$this->assertCount(2, $rows);
		$this->assertSame([PortalTaskDelivery::CHANNEL_PORTAL_INBOX, PortalTaskDelivery::CHANNEL_MAIL], array_map(static fn (PortalTaskDelivery $r): string => (string)$r->getChannel(), $written));
		foreach ($written as $row) {
			$this->assertSame('t-1', $row->getTaskUuid());
			$this->assertSame('party:bsn-1', $row->getPartyReference());
			$this->assertSame(PortalTaskDelivery::KIND_RE_ASK, $row->getKind());
			$this->assertSame('unreadable scan', $row->getMessage()['reason']);
			$this->assertSame(2, $row->getMessage()['cycle']);
			$this->assertSame('case-7', $row->getMessage()['case']['uuid']);
		}
	}//end testARequestWritesOneRowPerChannel()

	/**
	 * A recording failure does not throw: the ask stands, and the rows that
	 * could be written are returned.
	 *
	 * @return void
	 */
	public function testARecordingFailureNeverThrows(): void {
		$this->deliveries->method('insert')->willReturnCallback(
			static function (Entity $row): PortalTaskDelivery {
				if ($row->getChannel() === PortalTaskDelivery::CHANNEL_MAIL) {
					throw new RuntimeException('database gone');
				}

				return $row;
			}
		);

		$rows = $this->service->request(task: $this->task(), kind: PortalTaskDelivery::KIND_ASK, message: []);
		$this->assertCount(1, $rows);
		$this->assertSame(PortalTaskDelivery::CHANNEL_PORTAL_INBOX, $rows[0]->getChannel());
	}//end testARecordingFailureNeverThrows()

	/**
	 * NO NEXTCLOUD CHANNEL, STRUCTURALLY: the seam depends on nothing that
	 * could send a notification, a mail or a calendar entry, and its channel
	 * vocabulary names none.
	 *
	 * @return void
	 */
	public function testTheSeamHasNoNextcloudChannel(): void {
		$parameters = (new ReflectionClass(PortalTaskDeliveryService::class))->getConstructor()?->getParameters() ?? [];
		foreach ($parameters as $parameter) {
			$type = (string)$parameter->getType();
			$this->assertDoesNotMatchRegularExpression('/Notification|Mailer|Calendar|CalDAV|VTODO/i', $type, "constructor parameter $type");
		}

		$this->assertSame(['portal-inbox', 'mail'], PortalTaskDelivery::CHANNELS);
		$source = (string)file_get_contents((new ReflectionClass(PortalTaskDeliveryService::class))->getFileName());
		$this->assertStringNotContainsString('INotificationManager', $source);
		$this->assertStringNotContainsString('IMailer', $source);
	}//end testTheSeamHasNoNextcloudChannel()

	/**
	 * The summary: no rows is `not-recorded`; requested; delivered once the
	 * portal inbox went out; failed when any channel failed; and only the
	 * latest request round counts.
	 *
	 * @return void
	 */
	public function testTheSummaryReadsTheLatestRound(): void {
		$this->assertSame(PortalTaskDelivery::STATE_NOT_RECORDED, PortalTaskDelivery::summarise(rows: [])['state']);

		$requested = PortalTaskDelivery::summarise(rows: [$this->row('portal-inbox', 'requested'), $this->row('mail', 'requested')]);
		$this->assertSame('requested', $requested['state']);
		$this->assertSame(['portal-inbox', 'mail'], array_keys($requested['channels']));

		$delivered = PortalTaskDelivery::summarise(rows: [$this->row('portal-inbox', 'delivered'), $this->row('mail', 'requested')]);
		$this->assertSame('delivered', $delivered['state']);
		$this->assertNotNull($delivered['deliveredAt']);

		$failed = PortalTaskDelivery::summarise(rows: [$this->row('portal-inbox', 'delivered'), $this->row('mail', 'failed')]);
		$this->assertSame('failed', $failed['state']);

		$reasked = PortalTaskDelivery::summarise(
			rows: [
				$this->row('portal-inbox', 'delivered', '2026-09-01T10:00:00+00:00'),
				$this->row('portal-inbox', 'requested', '2026-09-02T10:00:00+00:00'),
			]
		);
		$this->assertSame('requested', $reasked['state'], 'the re-ask round decides');
		$this->assertSame('2026-09-02T10:00:00+00:00', $reasked['requestedAt']);
	}//end testTheSummaryReadsTheLatestRound()

	/**
	 * Settling: pending, delivered, failed pass through to the mapper.
	 *
	 * @return void
	 */
	public function testSettlingPassesThroughToTheMapper(): void {
		$row = $this->row('mail', 'requested');
		$row->setUuid('d-1');
		$this->deliveries->method('findByUuid')->with('d-1')->willReturn($row);
		$this->deliveries->method('findPending')->with(10)->willReturn([$row]);
		$this->deliveries->method('markDelivered')->with($row)->willReturn($row);
		$this->deliveries->method('markFailed')->with($row, 'smtp down')->willReturn($row);
		$this->deliveries->method('findForTask')->with('t-1')->willReturn([$row]);

		$this->assertSame([$row], $this->service->pending(limit: 10));
		$this->assertSame($row, $this->service->markDelivered(uuid: 'd-1'));
		$this->assertSame($row, $this->service->markFailed(uuid: 'd-1', error: 'smtp down'));
		$this->assertSame('requested', $this->service->stateFor(task: $this->task())['state']);
		$this->assertSame('d-1', $row->jsonSerialize()['uuid']);
	}//end testSettlingPassesThroughToTheMapper()
}//end class
