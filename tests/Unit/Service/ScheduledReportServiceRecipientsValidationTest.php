<?php

/**
 * ScheduledReportServiceRecipientsValidationTest
 *
 * Unit tests for `ScheduledReportService::create()`/`update()` validation of
 * the email-delivery fields added by scheduled-report-email-delivery:
 * `deliveryMode` enum, and `recipients` (array-of-emails, format, cap).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/scheduled-report-email-delivery/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\ScheduledReportMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ScheduledReportService;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use OCP\Notification\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScheduledReportServiceRecipientsValidationTest extends TestCase {

	private ScheduledReportService $service;

	private ScheduledReportMapper&MockObject $mapper;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(ScheduledReportMapper::class);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$registerMapper->method('find')->willReturn(new Register());
		$schemaMapper->method('find')->willReturn(new Schema());

		$this->mapper->method('insert')->willReturnArgument(0);

		$this->service = new ScheduledReportService(
			$this->mapper,
			$registerMapper,
			$schemaMapper,
			$this->createMock(ExportService::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IManager::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IMailer::class),
			$this->createMock(IConfig::class)
		);
	}//end setUp()

	private function validPayload(array $overrides = []): array {
		return array_merge(
			[
				'name' => 'Weekly cases',
				'registerId' => 1,
				'schemaId' => 2,
				'filters' => [],
				'format' => 'csv',
				'scheduleType' => 'weekly',
				'scheduleHour' => 8,
				'scheduleDayOfWeek' => 0,
			],
			$overrides
		);
	}//end validPayload()

	public function testDefaultDeliveryModeIsFiles(): void {
		$report = $this->service->create(data: $this->validPayload(), ownerUid: 'alice');

		self::assertSame('files', $report->getDeliveryMode());
		self::assertSame([], $report->getRecipientsArray());
	}//end testDefaultDeliveryModeIsFiles()

	public function testValidDeliveryModesAreAccepted(): void {
		foreach (['files', 'email', 'both'] as $mode) {
			$report = $this->service->create(
				data: $this->validPayload(['deliveryMode' => $mode]),
				ownerUid: 'alice'
			);

			self::assertSame($mode, $report->getDeliveryMode());
		}
	}//end testValidDeliveryModesAreAccepted()

	public function testUnsupportedDeliveryModeIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->create(
			data: $this->validPayload(['deliveryMode' => 'sms']),
			ownerUid: 'alice'
		);
	}//end testUnsupportedDeliveryModeIsRejected()

	public function testValidRecipientListIsAccepted(): void {
		$report = $this->service->create(
			data: $this->validPayload(
				[
					'deliveryMode' => 'email',
					'recipients' => ['a@example.com', 'b@example.com'],
				]
			),
			ownerUid: 'alice'
		);

		self::assertSame(['a@example.com', 'b@example.com'], $report->getRecipientsArray());
	}//end testValidRecipientListIsAccepted()

	public function testRecipientsAreDeduplicatedAndTrimmed(): void {
		$report = $this->service->create(
			data: $this->validPayload(
				[
					'deliveryMode' => 'email',
					'recipients' => [' a@example.com ', 'a@example.com', 'b@example.com'],
				]
			),
			ownerUid: 'alice'
		);

		self::assertSame(['a@example.com', 'b@example.com'], $report->getRecipientsArray());
	}//end testRecipientsAreDeduplicatedAndTrimmed()

	public function testInvalidEmailAddressIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->create(
			data: $this->validPayload(['deliveryMode' => 'email', 'recipients' => ['not-an-email']]),
			ownerUid: 'alice'
		);
	}//end testInvalidEmailAddressIsRejected()

	public function testNonStringRecipientIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->create(
			data: $this->validPayload(['deliveryMode' => 'email', 'recipients' => [42]]),
			ownerUid: 'alice'
		);
	}//end testNonStringRecipientIsRejected()

	public function testRecipientsMustBeAnArray(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->create(
			data: $this->validPayload(['deliveryMode' => 'email', 'recipients' => 'a@example.com']),
			ownerUid: 'alice'
		);
	}//end testRecipientsMustBeAnArray()

	public function testRecipientsAtTheCapAreAccepted(): void {
		$recipients = [];
		for ($i = 0; $i < ScheduledReportService::MAX_RECIPIENTS; $i++) {
			$recipients[] = sprintf('user%d@example.com', $i);
		}

		$report = $this->service->create(
			data: $this->validPayload(['deliveryMode' => 'email', 'recipients' => $recipients]),
			ownerUid: 'alice'
		);

		self::assertCount(ScheduledReportService::MAX_RECIPIENTS, $report->getRecipientsArray());
	}//end testRecipientsAtTheCapAreAccepted()

	public function testRecipientsOverTheCapAreRejected(): void {
		$recipients = [];
		for ($i = 0; $i < (ScheduledReportService::MAX_RECIPIENTS + 1); $i++) {
			$recipients[] = sprintf('user%d@example.com', $i);
		}

		$this->expectException(InvalidArgumentException::class);
		$this->service->create(
			data: $this->validPayload(['deliveryMode' => 'email', 'recipients' => $recipients]),
			ownerUid: 'alice'
		);
	}//end testRecipientsOverTheCapAreRejected()

	public function testUpdatePreservesRecipientsWhenNotSent(): void {
		$existing = $this->service->create(
			data: $this->validPayload(['deliveryMode' => 'email', 'recipients' => ['a@example.com']]),
			ownerUid: 'alice'
		);

		$ref = new \ReflectionClass($existing);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($existing, 9);

		$this->mapper->method('find')->with(9)->willReturn($existing);
		$this->mapper->method('update')->willReturnArgument(0);

		$updated = $this->service->update(
			id: 9,
			data: ['name' => 'Renamed'],
			callerUid: 'alice',
			callerIsAdmin: false
		);

		self::assertSame('Renamed', $updated->getName());
		self::assertSame(['a@example.com'], $updated->getRecipientsArray());
		self::assertSame('email', $updated->getDeliveryMode());
	}//end testUpdatePreservesRecipientsWhenNotSent()
}//end class
