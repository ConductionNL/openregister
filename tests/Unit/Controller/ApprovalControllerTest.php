<?php

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ApprovalController;
use OCA\OpenRegister\Db\ApprovalChain;
use OCA\OpenRegister\Db\ApprovalChainMapper;
use OCA\OpenRegister\Db\ApprovalStep;
use OCA\OpenRegister\Db\ApprovalStepMapper;
use OCA\OpenRegister\Service\ApprovalService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApprovalControllerTest extends TestCase
{
    private ApprovalController $controller;
    private ApprovalChainMapper $chainMapper;
    private ApprovalStepMapper $stepMapper;
    private ApprovalService $approvalService;
    private IUserSession $userSession;
    private IRequest $request;

    protected function setUp(): void
    {
        $this->chainMapper = $this->createMock(ApprovalChainMapper::class);
        $this->stepMapper = $this->createMock(ApprovalStepMapper::class);
        $this->approvalService = $this->createMock(ApprovalService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->request = $this->createMock(IRequest::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->controller = new ApprovalController(
            'openregister',
            $this->request,
            $this->chainMapper,
            $this->stepMapper,
            $this->approvalService,
            $this->userSession,
            $logger
        );
    }

    public function testIndexReturnsChains(): void
    {
        $chain = new ApprovalChain();
        $chain->hydrate(['uuid' => 'c-1', 'name' => 'Test Chain', 'schemaId' => 1, 'steps' => []]);

        $this->chainMapper->expects($this->once())
            ->method('findAll')
            ->willReturn([$chain]);

        $response = $this->controller->index();

        $this->assertSame(200, $response->getStatus());
        $this->assertCount(1, $response->getData());
    }

    public function testShowReturns404ForMissing(): void
    {
        $this->chainMapper->expects($this->once())
            ->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $response = $this->controller->show(999);

        $this->assertSame(404, $response->getStatus());
    }

    public function testApproveReturns403ForUnauthorised(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');

        $this->userSession->method('getUser')->willReturn($user);
        $this->request->method('getParam')->willReturn('');

        $this->approvalService->expects($this->once())
            ->method('approveStep')
            ->willThrowException(new \Exception('You are not authorised for this approval step'));

        $response = $this->controller->approve(1);

        $this->assertSame(403, $response->getStatus());
    }

    public function testApproveReturns401WhenNotAuthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->approve(1);

        $this->assertSame(401, $response->getStatus());
    }

    public function testRejectReturns403ForUnauthorised(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');

        $this->userSession->method('getUser')->willReturn($user);
        $this->request->method('getParam')->willReturn('');

        $this->approvalService->expects($this->once())
            ->method('rejectStep')
            ->willThrowException(new \Exception('You are not authorised for this approval step'));

        $response = $this->controller->reject(1);

        $this->assertSame(403, $response->getStatus());
    }
}
