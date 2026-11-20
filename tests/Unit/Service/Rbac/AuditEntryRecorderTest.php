<?php

/**
 * Tests for AuditEntryRecorder: audit entries reach the trail, and a failed write is logged, not thrown.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTimeImmutable;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntry;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntryWriterInterface;
use OCA\OpenRegister\Service\Rbac\AuditEntryRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\AuditEntryRecorder
 */
class AuditEntryRecorderTest extends TestCase {

	/**
	 * Two entries for the test.
	 *
	 * @return list<AuditEntry> The entries.
	 */
	private function entries(): array {
		$created = new DateTimeImmutable();

		return [
			new AuditEntry(uuid: 'u-1', action: 'settings.updated', user: 'admin', userName: 'Admin', changed: [], created: $created),
			new AuditEntry(uuid: 'u-2', action: 'settings.updated', user: 'admin', userName: 'Admin', changed: [], created: $created),
		];
	}//end entries()

	/**
	 * Every entry is handed to the trail in one write, in order, and counted.
	 *
	 * @return void
	 */
	public function testTheEntriesAreWrittenInOrderAndCounted(): void {
		$entries = $this->entries();
		$trail   = $this->createMock(AuditEntryWriterInterface::class);
		$trail->expects($this->once())->method('writeAuditEntries')->with($entries);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$recorder = new AuditEntryRecorder(trail: $trail, logger: $logger);

		$this->assertSame(2, $recorder->record(entries: $entries, failure: 'lost'));
	}//end testTheEntriesAreWrittenInOrderAndCounted()

	/**
	 * A failed write is logged at error with its count and reported as nothing written.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsLoggedAndCountsAsNothing(): void {
		$trail = $this->createMock(AuditEntryWriterInterface::class);
		$trail->method('writeAuditEntries')->willThrowException(new RuntimeException('the database went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'lost',
			$this->callback(
				static fn (array $context): bool => array_keys($context) === ['file', 'line', 'entries', 'exception']
					&& $context['entries'] === 2
					&& $context['exception'] === 'the database went away'
			)
		);

		$recorder = new AuditEntryRecorder(trail: $trail, logger: $logger);

		$this->assertSame(0, $recorder->record(entries: $this->entries(), failure: 'lost'));
	}//end testAFailedWriteIsLoggedAndCountsAsNothing()
}//end class
