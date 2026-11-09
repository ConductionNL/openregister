<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\Support\TransferCheckServices;
use OCA\OpenRegister\BackgroundJob\TransferCheckJob;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCA\OpenRegister\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Failure and empty-result branches of TransferCheckJob.
 */
class TransferCheckJobBehaviourTest extends TestCase {

	public function testScanFailureIsLoggedAndCreatesNoList(): void {
		$retention = $this->createMock(RetentionService::class);
		$lists = $this->createMock(TransferListService::class);
		$records = $this->createMock(TransferRecordService::class);
		$records->method('listTransferLists')->willReturn([]);
		$lists->method('getObjectsOnActiveTransferLists')->willReturn([]);
		$retention->method('findEligibleForTransfer')->willThrowException(new \RuntimeException('index offline'));
		$lists->expects($this->never())->method('createTransferList');
		$lists->expects($this->never())->method('notifyArchivists');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('[TransferCheckJob] Error during transfer check', ['error' => 'index offline']);

		$this->runWith($retention, $lists, $records, $logger);
	}

	public function testNoEligibleObjectsCreatesNoList(): void {
		$retention = $this->createMock(RetentionService::class);
		$lists = $this->createMock(TransferListService::class);
		$records = $this->createMock(TransferRecordService::class);
		$records->method('listTransferLists')->willReturn([]);
		$lists->method('getObjectsOnActiveTransferLists')->willReturn([]);
		$retention->method('findEligibleForTransfer')->willReturn([]);
		$lists->expects($this->never())->method('createTransferList');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$infos = [];
		$logger->method('info')->willReturnCallback(
			function (string $message) use (&$infos): void {
				$infos[] = $message;
			}
		);

		$this->runWith($retention, $lists, $records, $logger);

		$this->assertSame(
			['[TransferCheckJob] Starting transfer eligibility scan', '[TransferCheckJob] No objects eligible for transfer'],
			$infos
		);
	}

	private function runWith(RetentionService $retention, TransferListService $lists, TransferRecordService $records, LoggerInterface $logger): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'edepot_endpoint_url' ? 'https://edepot.test' : $default
		);
		$job = new TransferCheckJob(
			$this->createMock(ITimeFactory::class),
			new TransferCheckServices($retention, $lists, $records),
			$appConfig,
			$logger
		);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}
}
