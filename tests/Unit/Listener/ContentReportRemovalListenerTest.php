<?php

/**
 * Unit tests for naming the copies when reported content is removed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Listener;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Listener\ContentReportRemovalListener;
use OCA\OpenRegister\Service\Audit\ContentReportService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ContentReportRemovalListenerTest extends TestCase {
	private function deleted(): ObjectDeletedEvent {
		$object = new ObjectEntity();
		$object->setUuid('object-uuid');

		return new ObjectDeletedEvent($object);
	}//end deleted()

	public function testTheRemovalRecordNamesTheCopies(): void {
		$reports = $this->createMock(ContentReportService::class);
		$reports->method('noteRemoval')->with('object-uuid')->willReturn(['report-1', 'report-2']);

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->expects(self::once())
			->method('createAuditTrailEntry')
			->with(
				self::anything(),
				ContentReportService::ACTION_REMOVAL_NAMED,
				self::callback(static fn (array $context): bool => $context['contentReports'] === ['report-1', 'report-2'])
			)
			->willReturn(new AuditTrail());

		(new ContentReportRemovalListener($reports, $audit, $this->createMock(LoggerInterface::class)))
			->handle($this->deleted());
	}//end testTheRemovalRecordNamesTheCopies()

	public function testARemovalOfUnreportedContentWritesNothing(): void {
		$reports = $this->createMock(ContentReportService::class);
		$reports->method('noteRemoval')->willReturn([]);

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->expects(self::never())->method('createAuditTrailEntry');

		(new ContentReportRemovalListener($reports, $audit, $this->createMock(LoggerInterface::class)))
			->handle($this->deleted());
	}//end testARemovalOfUnreportedContentWritesNothing()

	public function testAFailureNeverEscapesIntoTheRemoval(): void {
		$reports = $this->createMock(ContentReportService::class);
		$reports->method('noteRemoval')->willReturn(['report-1']);

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('createAuditTrailEntry')->willThrowException(new \RuntimeException('db down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');

		(new ContentReportRemovalListener($reports, $audit, $logger))->handle($this->deleted());
	}//end testAFailureNeverEscapesIntoTheRemoval()

	public function testOtherEventsAreIgnored(): void {
		$reports = $this->createMock(ContentReportService::class);
		$reports->expects(self::never())->method('noteRemoval');

		(new ContentReportRemovalListener(
			$reports,
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(LoggerInterface::class)
		))->handle(new Event());
	}//end testOtherEventsAreIgnored()
}//end class
