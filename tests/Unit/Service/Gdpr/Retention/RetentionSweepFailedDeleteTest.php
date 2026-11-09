<?php

declare(strict_types=1);

/**
 * RetentionSweepService failed-delete reporting test.
 *
 * A case whose dossier delete fails was not purged, and the sweep summary
 * must not say it was: the summary is the record of what was destroyed.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Retention
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Retention;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\Gdpr\Retention\RetentionSweepService;
use OCA\OpenRegister\Service\Gdpr\Retention\RetentionSweepSources;
use OCA\OpenRegister\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Only a dossier that was actually deleted is reported as purged.
 */
class RetentionSweepFailedDeleteTest extends TestCase {
	/**
	 * Build the sweep over one expired case whose delete behaves as given.
	 *
	 * @param callable $delete The deleteForSweep behaviour.
	 *
	 * @return RetentionSweepService
	 */
	private function sweepWithDelete(callable $delete): RetentionSweepService {
		$case = new ObjectEntity();
		$case->setUuid('case-expired');
		$case->setSchema('dsar-case');
		$case->setObject(['subjectId' => '', 'retainUntil' => '2020-01-01T00:00:00+00:00']);

		$accessor = $this->createMock(CaseObjectAccessor::class);
		$accessor->method('findAllCaseEntities')->willReturn([$case]);
		$accessor->method('deleteForSweep')->willReturnCallback($delete);

		$retention = $this->createMock(RetentionService::class);
		$retention->method('hasActiveLegalHold')->willReturn(false);
		$retention->method('validateNotImmutable')->willReturn(null);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1751500000);

		$schema = new Schema();
		$schema->setSlug('dsar-case');
		$schema->setConfiguration([]);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		return new RetentionSweepService(new RetentionSweepSources($accessor, $this->createMock(DataSubjectRequestService::class), $time), $retention, $this->createMock(LoggerInterface::class), new ArchivalRetentionGuard($schemaMapper, $this->createMock(LoggerInterface::class)));
	}//end sweepWithDelete()

	/**
	 * A delete that throws is reported as failed, not purged.
	 *
	 * @return void
	 */
	public function testThrowingDeleteIsNotReportedAsPurged(): void {
		$service = $this->sweepWithDelete(
			static function (): bool {
				throw new RuntimeException('delete refused');
			}
		);

		$summary = $service->runSweep(false);

		$this->assertSame([], $summary['purged']);
		$this->assertSame(['case-expired'], $summary['failed']);
	}//end testThrowingDeleteIsNotReportedAsPurged()

	/**
	 * A delete that reports false is reported as failed, not purged.
	 *
	 * @return void
	 */
	public function testUnsuccessfulDeleteIsNotReportedAsPurged(): void {
		$service = $this->sweepWithDelete(static fn (): bool => false);

		$summary = $service->runSweep(false);

		$this->assertSame([], $summary['purged']);
		$this->assertSame(['case-expired'], $summary['failed']);
	}//end testUnsuccessfulDeleteIsNotReportedAsPurged()

	/**
	 * A successful delete is still reported as purged, with nothing failed.
	 *
	 * @return void
	 */
	public function testSuccessfulDeleteIsReportedAsPurged(): void {
		$service = $this->sweepWithDelete(static fn (): bool => true);

		$summary = $service->runSweep(false);

		$this->assertSame(['case-expired'], $summary['purged']);
		$this->assertSame([], $summary['failed']);
	}//end testSuccessfulDeleteIsReportedAsPurged()
}//end class
