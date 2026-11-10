<?php

/**
 * HardeningAuditWriter: an accepted and a refused fact reach the trail as before.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hardening
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Hardening;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\Hardening\HardeningAuditWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class HardeningAuditWriterTest extends TestCase {

	public function testAnAcceptedFactIsWrittenAcceptedWithNoRefusal(): void {
		$trail = $this->createMock(AuditTrailMapperInterface::class);
		$trail->expects($this->once())->method('createHardeningChangeEntry')
			->with('statement.published', '1', '2', true, '')
			->willReturn(new AuditTrail());

		(new HardeningAuditWriter($trail, $this->createMock(LoggerInterface::class)))->accepted('statement.published', '1', '2');
	}//end testAnAcceptedFactIsWrittenAcceptedWithNoRefusal()

	public function testARefusedFactIsWrittenRefusedWithItsRefusal(): void {
		$trail = $this->createMock(AuditTrailMapperInterface::class);
		$trail->expects($this->once())->method('createHardeningChangeEntry')
			->with('elevation.refused', '', 'anna', false, 'The password was not confirmed.')
			->willReturn(new AuditTrail());

		(new HardeningAuditWriter($trail, $this->createMock(LoggerInterface::class)))->refused('elevation.refused', '', 'anna', 'The password was not confirmed.');
	}//end testARefusedFactIsWrittenRefusedWithItsRefusal()

	public function testAFailedWriteIsLoggedAndNeverThrown(): void {
		$trail = $this->createMock(AuditTrailMapperInterface::class);
		$trail->method('createHardeningChangeEntry')->willThrowException(new RuntimeException('disk full'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('HardeningAuditWriter: the audit entry for elevation.lapsed could not be written: disk full');

		(new HardeningAuditWriter($trail, $logger))->refused('elevation.lapsed', '', 'anna', 'lapsed');
	}//end testAFailedWriteIsLoggedAndNeverThrown()
}//end class
