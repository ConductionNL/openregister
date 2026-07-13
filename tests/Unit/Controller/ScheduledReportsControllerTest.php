<?php

/**
 * ScheduledReportsControllerTest
 *
 * Unit tests for `ScheduledReportsController` — own-row CRUD, 403/404 on
 * another user's row, admin `?all=true` listing, and `run-now` queuing
 * (never calling the export inline).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\BackgroundJob\ScheduledReportRunNowJob;
use OCA\OpenRegister\Controller\ScheduledReportsController;
use OCA\OpenRegister\Db\ScheduledReport;
use OCA\OpenRegister\Service\ScheduledReportService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScheduledReportsControllerTest extends TestCase
{
    private ScheduledReportsController $controller;
    private ScheduledReportService&MockObject $service;
    private IJobList&MockObject $jobList;
    private IUserSession&MockObject $userSession;
    private IGroupManager&MockObject $groupManager;
    private IRequest&MockObject $request;

    /**
     * Backs the userSession/groupManager stubs below via a callback so a
     * later setupUser() call actually takes effect — configuring
     * ->method()->willReturn() twice on the same mock without a with()
     * constraint does NOT overwrite the earlier stub (PHPUnit evaluates
     * matchers in registration order), so a naive second call silently kept
     * returning the first ("alice") user.
     */
    private ?IUser $currentUser = null;
    private bool $currentUserIsAdmin = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service      = $this->createMock(ScheduledReportService::class);
        $this->jobList      = $this->createMock(IJobList::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->request      = $this->createMock(IRequest::class);

        $this->userSession->method('getUser')->willReturnCallback(fn() => $this->currentUser);
        $this->groupManager->method('isAdmin')->willReturnCallback(fn() => $this->currentUserIsAdmin);

        $this->setupUser('alice', false);

        $this->controller = new ScheduledReportsController(
            'openregister',
            $this->request,
            $this->service,
            $this->jobList,
            $this->userSession,
            $this->groupManager,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function setupUser(string $uid, bool $isAdmin): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->currentUser        = $user;
        $this->currentUserIsAdmin = $isAdmin;
    }

    private function makeReport(int $id, string $owner): ScheduledReport
    {
        $report = new ScheduledReport();
        $ref = new \ReflectionClass($report);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($report, $id);

        $report->setOwner($owner);
        $report->setName('Weekly cases');
        $report->setRegisterId(1);
        $report->setSchemaId(2);
        $report->setFilters('[]');
        $report->setFormat('csv');
        $report->setScheduleType('weekly');
        $report->setScheduleHour(8);
        $report->setScheduleDayOfWeek(0);
        $report->setDeliveryFolder('Reports/');
        $report->setEnabled(true);

        return $report;
    }

    public function testIndexReturnsOwnReports(): void
    {
        $this->request->method('getParam')->with('all', 'false')->willReturn('false');
        $this->service->expects(self::once())->method('findForOwner')->with('alice')
            ->willReturn([$this->makeReport(1, 'alice')]);

        $response = $this->controller->index();

        self::assertSame(200, $response->getStatus());
        self::assertSame(1, $response->getData()['total']);
    }

    public function testNonAdminAllParamStillOnlySeesOwnReports(): void
    {
        $this->request->method('getParam')->with('all', 'false')->willReturn('true');
        // isAdmin() is false (default setUp), so findAllForAdmin() must never run.
        $this->service->expects(self::never())->method('findAllForAdmin');
        $this->service->expects(self::once())->method('findForOwner')->with('alice')->willReturn([]);

        $this->controller->index();
    }

    public function testAdminCanListAllReports(): void
    {
        $this->setupUser('admin', true);
        $this->request->method('getParam')->with('all', 'false')->willReturn('true');

        $this->service->expects(self::once())->method('findAllForAdmin')
            ->willReturn([$this->makeReport(1, 'alice'), $this->makeReport(2, 'bob')]);
        $this->service->expects(self::never())->method('findForOwner');

        $response = $this->controller->index();

        self::assertSame(2, $response->getData()['total']);
    }

    public function testOwnerCanShowOwnReport(): void
    {
        $report = $this->makeReport(1, 'alice');
        $this->service->method('find')->with(1)->willReturn($report);
        $this->service->method('assertOwnerOrAdmin')->willReturnCallback(
            function (ScheduledReport $r, string $callerUid, bool $isAdmin): void {
                if ($r->getOwner() !== $callerUid && $isAdmin === false) {
                    throw new \RuntimeException('forbidden');
                }
            }
        );

        $response = $this->controller->show(1);

        self::assertSame(200, $response->getStatus());
    }

    public function testNonOwnerCannotShowAnotherUsersReport(): void
    {
        $report = $this->makeReport(42, 'bob');
        $this->service->method('find')->with(42)->willReturn($report);
        $this->service->method('assertOwnerOrAdmin')->willThrowException(new \RuntimeException('forbidden'));

        $response = $this->controller->show(42);

        self::assertContains($response->getStatus(), [403, 404]);
    }

    public function testShowReturns404ForMissingReport(): void
    {
        $this->service->method('find')->willThrowException($this->createMock(DoesNotExistException::class));

        $response = $this->controller->show(999);

        self::assertSame(404, $response->getStatus());
    }

    public function testNonOwnerCannotUpdateAnotherUsersReport(): void
    {
        $this->request->method('getParams')->willReturn(['name' => 'Renamed']);
        $this->service->method('update')->willThrowException(new \RuntimeException('forbidden'));

        $response = $this->controller->update(42);

        self::assertContains($response->getStatus(), [403, 404]);
    }

    public function testNonOwnerCannotDeleteAnotherUsersReport(): void
    {
        $this->service->method('delete')->willThrowException(new \RuntimeException('forbidden'));

        $response = $this->controller->destroy(42);

        self::assertContains($response->getStatus(), [403, 404]);
    }

    public function testOwnerCanDeleteOwnReport(): void
    {
        $this->service->expects(self::once())->method('delete')->with(1, 'alice', false);

        $response = $this->controller->destroy(1);

        self::assertSame(204, $response->getStatus());
    }

    public function testRunNowQueuesJobAndReturns202WithoutCallingExportInline(): void
    {
        $report = $this->makeReport(1, 'alice');
        $this->service->method('find')->with(1)->willReturn($report);
        $this->service->method('assertOwnerOrAdmin');

        $this->jobList->expects(self::once())
            ->method('add')
            ->with(ScheduledReportRunNowJob::class, ['scheduledReportId' => 1]);

        // runOne() belongs to the queued job, never called synchronously
        // from the controller.
        $this->service->expects(self::never())->method('runOne');

        $response = $this->controller->runNow(1);

        self::assertSame(202, $response->getStatus());
        self::assertTrue($response->getData()['queued']);
    }

    public function testRunNowRespectsOwnership(): void
    {
        $report = $this->makeReport(42, 'bob');
        $this->service->method('find')->with(42)->willReturn($report);
        $this->service->method('assertOwnerOrAdmin')->willThrowException(new \RuntimeException('forbidden'));

        $this->jobList->expects(self::never())->method('add');

        $response = $this->controller->runNow(42);

        self::assertContains($response->getStatus(), [403, 404]);
    }
}//end class
