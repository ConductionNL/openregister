<?php

/**
 * Characterisation of AppImportJobRecorder::record()'s untraceable-import warning.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Configuration\AppImportJobRecorder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Configuration\FinishedImportJob;

/**
 * Pins the warning's text, context and the counts it reads, before record() takes a value object.
 */
final class AppImportJobRecorderWarningTest extends TestCase {

	/**
	 * The counts the recorder asked the audit trail for, as [importJobId, action].
	 *
	 * @var array<int, array{0: string, 1: string|null}>
	 */
	private array $counted = [];

	/**
	 * A recorder over an audit trail that traced nothing and an empty app config.
	 *
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return AppImportJobRecorder
	 */
	private function recorder(LoggerInterface $logger): AppImportJobRecorder {
		$auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$auditTrailMapper->method('countByImportJobId')->willReturnCallback(
			function (string $importJobId, ?string $action = 'create'): int {
				$this->counted[] = [$importJobId, $action];
				return 0;
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->never())->method('setValueString');

		return new AppImportJobRecorder($auditTrailMapper, $appConfig, $logger);
	}//end recorder()

	/**
	 * Objects written and nothing traced: one warning naming the app, the count and the job.
	 *
	 * @return void
	 */
	public function testWarningTextAndContext(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[AppImportJobRecorder] The import for learniq.demo wrote 7 object(s) and none carries import job job-9. '
			. 'The audit trail is probably off, so this import cannot be removed by job.',
			['app' => 'openregister', 'importJobId' => 'job-9']
		);

		self::assertFalse($this->recorder($logger)->recordFinished(new FinishedImportJob('learniq.demo', 'job-9', '1.0.0', 7)));
		self::assertSame([['job-9', 'create'], ['job-9', null]], $this->counted);
	}//end testWarningTextAndContext()

	/**
	 * Nothing written: no warning, and the audit trail is not asked a second time.
	 *
	 * @return void
	 */
	public function testNothingWrittenIsSilent(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		self::assertFalse($this->recorder($logger)->recordFinished(new FinishedImportJob('learniq.demo', 'job-9', '1.0.0', 0)));
		self::assertSame([['job-9', 'create']], $this->counted);
	}//end testNothingWrittenIsSilent()
}//end class
