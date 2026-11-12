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

use OCA\OpenRegister\Db\AuditTrail\HardeningChange;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\Hardening\HardeningAuditWriter;
use OCA\OpenRegister\Service\Hardening\HardeningFact;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class HardeningAuditWriterTest extends TestCase {

	public function testAnAcceptedFactIsWrittenAcceptedWithNoRefusal(): void {
		$trail = $this->createMock(AuditTrailMapperInterface::class);
		$trail->expects($this->once())->method('recordHardeningChange')
			->with(new HardeningChange('statement.published', '1', '2', ''), true)
			->willReturn(new AuditTrail());

		(new HardeningAuditWriter($trail, $this->createMock(LoggerInterface::class)))->accepted(new HardeningFact('statement.published', '1', '2'));
	}//end testAnAcceptedFactIsWrittenAcceptedWithNoRefusal()

	public function testARefusedFactIsWrittenRefusedWithItsRefusal(): void {
		$trail = $this->createMock(AuditTrailMapperInterface::class);
		$trail->expects($this->once())->method('recordHardeningChange')
			->with(new HardeningChange('elevation.refused', '', 'anna', 'The password was not confirmed.'), false)
			->willReturn(new AuditTrail());

		(new HardeningAuditWriter($trail, $this->createMock(LoggerInterface::class)))->refused(new HardeningFact('elevation.refused', '', 'anna'), 'The password was not confirmed.');
	}//end testARefusedFactIsWrittenRefusedWithItsRefusal()

	public function testAFailedWriteIsLoggedAndNeverThrown(): void {
		$trail = $this->createMock(AuditTrailMapperInterface::class);
		$trail->method('recordHardeningChange')->willThrowException(new RuntimeException('disk full'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('HardeningAuditWriter: the audit entry for elevation.lapsed could not be written: disk full');

		(new HardeningAuditWriter($trail, $logger))->refused(new HardeningFact('elevation.lapsed', '', 'anna'), 'lapsed');
	}//end testAFailedWriteIsLoggedAndNeverThrown()
}//end class
