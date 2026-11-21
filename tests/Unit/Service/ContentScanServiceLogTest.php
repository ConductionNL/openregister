<?php

/**
 * ContentScanServiceLogTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ContentScanService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the one log line a flagged scan writes, and that a clean scan writes none.
 */
class ContentScanServiceLogTest extends TestCase {

	public function testAFlaggedScanLogsItsSeverityAndFindingCount(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with('[ContentScanService] content scan flagged patterns', ['severity' => 'dangerous', 'findingCount' => 2]);

		(new ContentScanService($logger))->scan('cat ~/.ssh/id_rsa', ['k' => 'AKIAABCDEFGHIJKLMNOP']);
	}

	public function testACleanScanLogsNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());

		$report = (new ContentScanService($logger))->scan('a perfectly ordinary text');

		$this->assertTrue($report['safe']);
	}
}//end class
