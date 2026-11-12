<?php

/**
 * Characterisation of AuditTrailPageService: one page, and an export inline or queued.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use OCA\OpenRegister\BackgroundJob\AuditExportSource;
use OCA\OpenRegister\BackgroundJob\JobHandles;
use OCA\OpenRegister\Db\AuditTrail\AuditPageRequest;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailCriteria;
use OCA\OpenRegister\Db\AuditTrailPageQueryInterface;
use OCA\OpenRegister\Service\Audit\AuditTrailPageService;
use OCA\OpenRegister\Service\Audit\AuditTrailQuery;
use OCA\OpenRegister\Service\LogService;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Audit\AuditTrailPageService
 */
class AuditTrailPageServiceTest extends TestCase {

	private AuditTrailPageQueryInterface&MockObject $pageQuery;

	private LogService&MockObject $logService;

	private IJobList&MockObject $jobList;

	private AuditTrailPageService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->pageQuery = $this->createMock(AuditTrailPageQueryInterface::class);
		$this->logService = $this->createMock(LogService::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->service = new AuditTrailPageService(new AuditExportSource($this->pageQuery, $this->logService), $this->jobList);
	}//end setUp()

	public function testAPageCarriesTheBoundedLimitAndBothCursors(): void {
		$this->pageQuery->expects($this->once())->method('page')
			->with(new AuditPageRequest(new AuditTrailCriteria(['action' => 'create'], 'x'), 5000, 40))
			->willReturn(['results' => [['id' => 1]], 'nextCursor' => 7]);

		$page = $this->service->page(new AuditTrailQuery(['action' => 'create'], 'x'), 5000, 40);

		$this->assertSame(
			['results' => [['id' => 1]], 'limit' => AuditTrailPageQueryInterface::MAX_LIMIT, 'cursor' => 40, 'nextCursor' => 7],
			$page
		);
	}//end testAPageCarriesTheBoundedLimitAndBothCursors()

	public function testAnExportUnderTheCeilingIsRenderedInline(): void {
		$rows = [['id' => 1], ['id' => 2]];
		$this->pageQuery->expects($this->once())->method('collect')
			->with(new AuditTrailCriteria(['user' => 'u'], null), JobHandles::AUDIT_TRAIL_EXPORT_INLINE_LIMIT)
			->willReturn(['results' => $rows, 'truncated' => false]);
		$this->logService->expects($this->once())->method('exportRows')
			->with('csv', $rows, ['includeChanges' => true])
			->willReturn(['content' => 'a,b']);
		$this->jobList->expects($this->never())->method('add');

		$result = $this->service->export(new AuditTrailQuery(['user' => 'u']), 'csv', true, 'admin');

		$this->assertSame(['queued' => false, 'rows' => 2, 'file' => ['content' => 'a,b']], $result);
	}//end testAnExportUnderTheCeilingIsRenderedInline()

	public function testAnExportOverTheCeilingIsQueuedWithItsActorAndQuery(): void {
		$this->pageQuery->method('collect')->willReturn(['results' => [['id' => 1], ['id' => 2], ['id' => 3]], 'truncated' => true]);
		$this->logService->expects($this->never())->method('exportRows');
		$this->jobList->expects($this->once())->method('add')->with(
			JobHandles::AUDIT_TRAIL_EXPORT,
			['actor' => 'admin', 'filters' => ['user' => 'u'], 'search' => 'term', 'format' => 'json', 'includeChanges' => false]
		);

		$result = $this->service->export(new AuditTrailQuery(['user' => 'u'], 'term'), 'json', false, 'admin');

		$this->assertSame(['queued' => true, 'rows' => 3, 'file' => null], $result);
	}//end testAnExportOverTheCeilingIsQueuedWithItsActorAndQuery()
}//end class
