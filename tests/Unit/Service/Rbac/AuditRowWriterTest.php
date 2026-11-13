<?php

/**
 * The audit row writer reports what it wrote and fails quietly to the log.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\Rbac\AuditRowWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\AuditRowWriter
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 */
class AuditRowWriterTest extends TestCase {

	public function testAllRowsAreWrittenInRevealChunks(): void {
		$rows = [new AuditTrail(), new AuditTrail()];

		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->expects($this->once())
			->method('insertAuditTrails')
			->with($rows, 100)
			->willReturn($rows);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$this->assertSame(2, (new AuditRowWriter($mapper, $logger))->write($rows, 'write failed'));
	}

	public function testAFailedWriteIsLoggedWithTheCallersMessageAndCountsNothing(): void {
		$rows = [new AuditTrail(), new AuditTrail(), new AuditTrail()];

		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->method('insertAuditTrails')->willThrowException(new RuntimeException('disk full'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				'Could not write the reveal trail',
				$this->callback(
					static fn (array $context): bool => $context['entries'] === 3
						&& $context['exception'] === 'disk full'
						&& isset($context['file'], $context['line']) === true
				)
			);

		$this->assertSame(0, (new AuditRowWriter($mapper, $logger))->write($rows, 'Could not write the reveal trail'));
	}

	public function testAnEmptyBatchStillGoesThroughTheMapper(): void {
		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->expects($this->once())->method('insertAuditTrails')->with([], 100)->willReturn([]);

		$this->assertSame(0, (new AuditRowWriter($mapper, $this->createMock(LoggerInterface::class)))->write([], 'x'));
	}
}
