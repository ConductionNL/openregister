<?php

/**
 * ApprovalChainGateListener runtime-enforcement tests (approval-chains-declarative).
 *
 * Exercises the ObjectUpdatingEvent gate enforcement of
 * `x-openregister-approval-chains`:
 *  - an ungated transition (no matching declared chain) passes untouched;
 *  - the first gated attempt provisions steps and BLOCKS
 *    (`approval-chain-pending`);
 *  - a still-in-progress chain blocks a repeat attempt WITHOUT duplicating steps;
 *  - amount-threshold routing selects the correct single tier;
 *  - a fully-approved chain RELEASES the transition;
 *  - a rejected cycle is cleared and a fresh cycle opened on the next attempt.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/approval-chains-declarative/specs/approval-workflow/spec.md
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ApprovalChain;
use OCA\OpenRegister\Db\ApprovalChainMapper;
use OCA\OpenRegister\Db\ApprovalStep;
use OCA\OpenRegister\Db\ApprovalStepMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\ApprovalChainGateListener;
use OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller;
use OCA\OpenRegister\Service\ApprovalService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \OCA\OpenRegister\Listener\ApprovalChainGateListener
 */
class ApprovalChainGateListenerTest extends TestCase
{
    private SchemaMapper&MockObject $schemaMapper;
    private ApprovalChainMapper&MockObject $chainMapper;
    private ApprovalStepMapper&MockObject $stepMapper;
    private ApprovalService&MockObject $approvalService;
    private ApprovalChainAnnotationInstaller&MockObject $installer;
    private IUserSession&MockObject $userSession;
    private ApprovalChainGateListener $listener;

    protected function setUp(): void
    {
        $this->schemaMapper    = $this->createMock(SchemaMapper::class);
        $this->chainMapper     = $this->createMock(ApprovalChainMapper::class);
        $this->stepMapper      = $this->createMock(ApprovalStepMapper::class);
        $this->approvalService = $this->createMock(ApprovalService::class);
        $this->installer       = $this->createMock(ApprovalChainAnnotationInstaller::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $logger                = $this->createMock(LoggerInterface::class);

        $this->loginAs('requester1');

        $this->listener = new ApprovalChainGateListener(
            $this->schemaMapper,
            $this->chainMapper,
            $this->stepMapper,
            $this->approvalService,
            $this->installer,
            $this->userSession,
            $logger
        );
    }//end setUp()

    private function loginAs(string $uid): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }//end loginAs()

    /**
     * Schema with a `submit` lifecycle transition and a declared
     * `submit-approval` chain gating it, amount-routed between two tiers.
     */
    private function gatedSchema(): Schema
    {
        $schema = new Schema();
        $schema->setId(5);
        $schema->setSlug('test-commitment');
        $schema->setConfiguration(
            [
                'x-openregister-lifecycle'       => [
                    'field'       => 'status',
                    'transitions' => [
                        'submit' => ['from' => 'draft', 'to' => 'submitted'],
                    ],
                ],
                'x-openregister-approval-chains' => [
                    'submit-approval' => [
                        'transition'         => 'submit',
                        'amountField'        => 'amount',
                        'separationOfDuties' => true,
                        'onApprove'          => 'advanceTransition',
                        'approvers'          => [
                            ['role' => 'finance-clerks', 'min' => 1, 'minAmount' => 0],
                            ['role' => 'finance-directors', 'min' => 1, 'minAmount' => 100000],
                        ],
                    ],
                ],
            ]
        );

        $this->schemaMapper->method('find')->willReturn($schema);
        return $schema;
    }//end gatedSchema()

    /**
     * Ungated schema — lifecycle transition declared, no
     * x-openregister-approval-chains at all.
     */
    private function ungatedSchema(): Schema
    {
        $schema = new Schema();
        $schema->setId(6);
        $schema->setSlug('test-plain');
        $schema->setConfiguration(
            [
                'x-openregister-lifecycle' => [
                    'field'       => 'status',
                    'transitions' => [
                        'submit' => ['from' => 'draft', 'to' => 'submitted'],
                    ],
                ],
            ]
        );

        $this->schemaMapper->method('find')->willReturn($schema);
        return $schema;
    }//end ungatedSchema()

    private function event(string $schemaSlug, string $oldStatus, string $newStatus, int $amount=5000): ObjectUpdatingEvent
    {
        $old = new ObjectEntity();
        $old->setSchema($schemaSlug);
        $old->setUuid('obj-1');
        $old->setObject(['status' => $oldStatus, 'amount' => $amount]);

        $new = new ObjectEntity();
        $new->setSchema($schemaSlug);
        $new->setUuid('obj-1');
        $new->setObject(['status' => $newStatus, 'amount' => $amount]);

        return new ObjectUpdatingEvent(newObject: $new, oldObject: $old);
    }//end event()

    public function testUngatedTransitionPassesUntouched(): void
    {
        $this->ungatedSchema();
        $event = $this->event('test-plain', 'draft', 'submitted');

        $this->approvalService->expects($this->never())->method('initializeChain');

        $this->listener->handle($event);

        $this->assertFalse($event->isPropagationStopped());
    }//end testUngatedTransitionPassesUntouched()

    public function testFirstGatedAttemptProvisionsStepsAndIsBlocked(): void
    {
        $this->gatedSchema();
        $event = $this->event('test-commitment', 'draft', 'submitted', amount: 5000);

        $chain = new ApprovalChain();
        $chain->setId(10);
        $this->chainMapper->method('findBySchemaAndName')->willReturn($chain);
        $this->stepMapper->method('findByChainAndObject')->willReturn([]);

        $this->approvalService->expects($this->once())
            ->method('initializeChain')
            ->with(
                $chain,
                'obj-1',
                'requester1',
                [['order' => 1, 'role' => 'finance-clerks']]
            );

        $this->listener->handle($event);

        $this->assertTrue($event->isPropagationStopped());
        $this->assertSame('approval-chain-pending', $event->getErrors()['code']);
    }//end testFirstGatedAttemptProvisionsStepsAndIsBlocked()

    public function testInProgressChainBlocksWithoutDuplicating(): void
    {
        $this->gatedSchema();
        $event = $this->event('test-commitment', 'draft', 'submitted');

        $chain = new ApprovalChain();
        $chain->setId(10);
        $this->chainMapper->method('findBySchemaAndName')->willReturn($chain);

        $pendingStep = new ApprovalStep();
        $pendingStep->hydrate(['status' => 'pending', 'stepOrder' => 1]);
        $this->stepMapper->method('findByChainAndObject')->willReturn([$pendingStep]);

        $this->approvalService->expects($this->never())->method('initializeChain');
        $this->stepMapper->expects($this->never())->method('deleteByChainAndObject');

        $this->listener->handle($event);

        $this->assertTrue($event->isPropagationStopped());
        $this->assertSame('approval-chain-pending', $event->getErrors()['code']);
    }//end testInProgressChainBlocksWithoutDuplicating()

    public function testLowAmountObjectRoutesToLowerTier(): void
    {
        $this->gatedSchema();
        $event = $this->event('test-commitment', 'draft', 'submitted', amount: 5000);

        $chain = new ApprovalChain();
        $chain->setId(10);
        $this->chainMapper->method('findBySchemaAndName')->willReturn($chain);
        $this->stepMapper->method('findByChainAndObject')->willReturn([]);

        $this->approvalService->expects($this->once())
            ->method('initializeChain')
            ->with(
                $chain,
                'obj-1',
                'requester1',
                [['order' => 1, 'role' => 'finance-clerks']]
            );

        $this->listener->handle($event);
    }//end testLowAmountObjectRoutesToLowerTier()

    public function testHighAmountObjectRoutesToHigherTier(): void
    {
        $this->gatedSchema();
        $event = $this->event('test-commitment', 'draft', 'submitted', amount: 250000);

        $chain = new ApprovalChain();
        $chain->setId(10);
        $this->chainMapper->method('findBySchemaAndName')->willReturn($chain);
        $this->stepMapper->method('findByChainAndObject')->willReturn([]);

        $this->approvalService->expects($this->once())
            ->method('initializeChain')
            ->with(
                $chain,
                'obj-1',
                'requester1',
                [['order' => 1, 'role' => 'finance-directors']]
            );

        $this->listener->handle($event);
    }//end testHighAmountObjectRoutesToHigherTier()

    public function testFullyApprovedChainReleasesTransition(): void
    {
        $this->gatedSchema();
        $event = $this->event('test-commitment', 'draft', 'submitted');

        $chain = new ApprovalChain();
        $chain->setId(10);
        $this->chainMapper->method('findBySchemaAndName')->willReturn($chain);

        $approvedStep = new ApprovalStep();
        $approvedStep->hydrate(['status' => 'approved', 'stepOrder' => 1]);
        $this->stepMapper->method('findByChainAndObject')->willReturn([$approvedStep]);

        $this->approvalService->expects($this->never())->method('initializeChain');

        $this->listener->handle($event);

        $this->assertFalse($event->isPropagationStopped());
    }//end testFullyApprovedChainReleasesTransition()

    public function testRejectedCycleIsClearedAndReopened(): void
    {
        $this->gatedSchema();
        $event = $this->event('test-commitment', 'draft', 'submitted', amount: 5000);

        $chain = new ApprovalChain();
        $chain->setId(10);
        $this->chainMapper->method('findBySchemaAndName')->willReturn($chain);

        $rejectedStep = new ApprovalStep();
        $rejectedStep->hydrate(['status' => 'rejected', 'stepOrder' => 1]);
        $this->stepMapper->method('findByChainAndObject')->willReturn([$rejectedStep]);

        $this->stepMapper->expects($this->once())
            ->method('deleteByChainAndObject')
            ->with(10, 'obj-1');

        $this->approvalService->expects($this->once())
            ->method('initializeChain')
            ->with($chain, 'obj-1', 'requester1', $this->anything());

        $this->listener->handle($event);

        $this->assertTrue($event->isPropagationStopped());
        $this->assertSame('approval-chain-pending', $event->getErrors()['code']);
    }//end testRejectedCycleIsClearedAndReopened()
}//end class
