<?php

/**
 * Tests for TransferAuditLog.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\TransferAuditLog;
use OCA\OpenRegister\Service\Edepot\TransferSettings;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\TransferAuditLog
 */
class TransferAuditLogTest extends TestCase {

	/**
	 * Logged lines.
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * An audit log whose audit mapper and notification manager fail.
	 *
	 * @return TransferAuditLog
	 */
	private function failingAuditLog(): TransferAuditLog {
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->method('createAuditTrail')->willThrowException(new \RuntimeException('audit down'));

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willThrowException(new \RuntimeException('no notifications'));

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'edepot_target_archive' => 'archive',
				default => $default,
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		return new TransferAuditLog($audit, new TransferSettings($config, new NullLogger()), $notifications, $logger);
	}//end failingAuditLog()

	public function testFailuresToAuditOrNotifyAreLoggedAndNeverThrown(): void {
		$object = new ObjectEntity();
		$object->setUuid('a');

		$log = $this->failingAuditLog();
		$log->initiated(transferList: ['uuid' => 'l', 'objectReferences' => [1, 2]], transport: 'sftp');
		$log->objectTransferred(object: $object, transferUuid: 'l', reference: 'r');
		$log->failed(transferList: ['uuid' => 'l', 'objectReferences' => [1]], error: 'e', transport: 'sftp');
		$log->notifyCompletion(transferList: ['uuid' => 'l', 'status' => 'completed']);

		$this->assertSame(
			[
				'info: [EdepotTransferService] Audit: archival.transfer_initiated',
				'warning: [EdepotTransferService] Failed to create audit trail for transfer',
				'info: [EdepotTransferService] Audit: archival.transferred',
				'error: [EdepotTransferService] Audit: archival.transfer_failed',
				'warning: [EdepotTransferService] Failed to send completion notification',
			],
			array_map(static fn (array $line): string => $line[0].': '.$line[1], $this->logged)
		);
		$this->assertSame('archive', $this->logged[0][2]['targetArchive']);
		$this->assertSame(2, $this->logged[0][2]['objectCount']);
		$this->assertSame(1, $this->logged[3][2]['failedCount']);
	}
}//end class
