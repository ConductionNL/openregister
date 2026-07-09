<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Controller\TransferController}.
 *
 * The former placeholder stubs are now real (archival-transfer-hardening,
 * OR-AD-2/OR-AD-3): index/show serve the durable records; create loads the
 * persisted list, refuses a non-approved one, and dispatches
 * TransferExecutionJob for an approved one.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\BackgroundJob\TransferExecutionJob;
use OCA\OpenRegister\Controller\TransferController;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCP\AppFramework\Http;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * TransferControllerTest.
 */
class TransferControllerTest extends TestCase
{

    private TransferRecordService&MockObject $recordService;

    private IJobList&MockObject $jobList;

    private IRequest&MockObject $request;

    private TransferController $controller;


    protected function setUp(): void
    {
        $this->recordService = $this->createMock(TransferRecordService::class);
        $this->jobList       = $this->createMock(IJobList::class);
        $this->request       = $this->createMock(IRequest::class);

        $this->controller = new TransferController(
            'openregister',
            $this->request,
            $this->recordService,
            $this->jobList,
            $this->createMock(LoggerInterface::class),
        );

    }//end setUp()


    /**
     * Index returns the persisted transfer lists with a total.
     *
     * @return void
     */
    public function testIndexReturnsPersistedLists(): void
    {
        $lists = [['uuid' => 'a'], ['uuid' => 'b']];
        $this->recordService->method('listTransferLists')->willReturn($lists);

        $response = $this->controller->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['results' => $lists, 'total' => 2], $response->getData());

    }//end testIndexReturnsPersistedLists()


    /**
     * Show returns a persisted list, or 404 when absent.
     *
     * @return void
     */
    public function testShow(): void
    {
        $this->recordService->method('loadTransferList')->willReturnMap(
            [
                ['t1', ['uuid' => 't1', 'status' => 'approved']],
                ['ghost', null],
            ]
        );

        $ok = $this->controller->show('t1');
        $this->assertSame(Http::STATUS_OK, $ok->getStatus());
        $this->assertSame('t1', $ok->getData()['uuid']);

        $missing = $this->controller->show('ghost');
        $this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());

    }//end testShow()


    /**
     * Create dispatches TransferExecutionJob for an approved list (202).
     *
     * @return void
     */
    public function testCreateDispatchesApprovedTransfer(): void
    {
        $this->request->method('getParams')->willReturn(['transferListUuid' => 't1']);
        $this->recordService->method('loadTransferList')->willReturn(
            ['uuid' => 't1', 'status' => TransferListService::STATUS_APPROVED]
        );

        $dispatched = null;
        $this->jobList->expects($this->once())->method('add')->willReturnCallback(
            function ($job, $argument) use (&$dispatched): void {
                $dispatched = ['job' => $job, 'argument' => $argument];
            }
        );

        $response = $this->controller->create();

        $this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
        $this->assertSame(TransferExecutionJob::class, $dispatched['job']);
        $this->assertSame('t1', $dispatched['argument']['transferListId']);
        $this->assertSame(1, $dispatched['argument']['attempt']);

    }//end testCreateDispatchesApprovedTransfer()


    /**
     * Create refuses a non-approved list (409) and dispatches nothing.
     *
     * @return void
     */
    public function testCreateRefusesNonApprovedList(): void
    {
        $this->request->method('getParams')->willReturn(['transferListUuid' => 't1']);
        $this->recordService->method('loadTransferList')->willReturn(
            ['uuid' => 't1', 'status' => TransferListService::STATUS_IN_REVIEW]
        );

        $this->jobList->expects($this->never())->method('add');

        $response = $this->controller->create();

        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());

    }//end testCreateRefusesNonApprovedList()


    /**
     * Create validates the required uuid and 404s an unknown list.
     *
     * @return void
     */
    public function testCreateValidatesInput(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $bad = $this->controller->create();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $bad->getStatus());

        $this->setUp();
        $this->request->method('getParams')->willReturn(['transferListUuid' => 'ghost']);
        $this->recordService->method('loadTransferList')->willReturn(null);
        $missing = $this->controller->create();
        $this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());

    }//end testCreateValidatesInput()
}//end class
