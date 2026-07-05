<?php

/**
 * MergeControllerTest
 *
 * Covers auth annotation presence, RBAC pass-through (delegates entirely to
 * MergeService), and route-method reachability for preview/execute/reverse.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-merge-engine/tasks.md#6.1
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\MergeController;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Merge\MergeService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * @coversDefaultClass \OCA\OpenRegister\Controller\MergeController
 */
class MergeControllerTest extends TestCase
{

    private MergeService&MockObject $mergeService;

    /**
     * @var IRequest&MockObject
     */
    private $request;

    private IUserSession&MockObject $userSession;

    private MergeController $controller;

    protected function setUp(): void
    {
        $this->request      = $this->createMock(IRequest::class);
        $this->mergeService = $this->createMock(MergeService::class);
        $this->userSession  = $this->createMock(IUserSession::class);

        $this->controller = new MergeController(
            'openregister',
            $this->request,
            $this->mergeService,
            $this->userSession
        );
    }//end setUp()

    /**
     * ADR-029 / ADR-005: every merge action must declare @NoAdminRequired +
     * @NoCSRFRequired via docblock and must NOT be @PublicPage.
     *
     * @return void
     */
    public function testAllActionsCarryAuthAnnotations(): void
    {
        $reflection = new ReflectionClass(MergeController::class);

        foreach (['preview', 'execute', 'reverse'] as $method) {
            $doc = $reflection->getMethod($method)->getDocComment();
            $this->assertNotFalse($doc, sprintf('%s must have a docblock.', $method));
            $this->assertStringContainsString('@NoAdminRequired', $doc, sprintf('%s missing @NoAdminRequired', $method));
            $this->assertStringContainsString('@NoCSRFRequired', $doc, sprintf('%s missing @NoCSRFRequired', $method));
            $this->assertStringNotContainsString('@PublicPage', $doc, sprintf('%s must not be @PublicPage', $method));
        }
    }//end testAllActionsCarryAuthAnnotations()

    /**
     * Reachability (ADR-029): every route target method referenced in
     * routes.php must exist on the controller.
     *
     * @return void
     */
    public function testRouteTargetMethodsExist(): void
    {
        $reflection = new ReflectionClass(MergeController::class);
        foreach (['preview', 'execute', 'reverse'] as $method) {
            $this->assertTrue($reflection->hasMethod($method));
        }
    }//end testRouteTargetMethodsExist()

    public function testPreviewDelegatesToPreviewMerge(): void
    {
        $this->request->method('getParam')->willReturnMap(
            [
                ['from', '', 'from-uuid'],
                ['into', '', 'into-uuid'],
            ]
        );

        $payload = ['from' => 'from-uuid', 'into' => 'into-uuid', 'postMergeGoldenRecord' => []];
        $this->mergeService->expects($this->once())
            ->method('previewMerge')
            ->with('from-uuid', 'into-uuid')
            ->willReturn($payload);

        $response = $this->controller->preview();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($payload, $response->getData());
    }//end testPreviewDelegatesToPreviewMerge()

    public function testPreviewNeverWrites(): void
    {
        $this->request->method('getParam')->willReturnArgument(1);
        $this->mergeService->expects($this->once())->method('previewMerge')->willReturn([]);
        $this->mergeService->expects($this->never())->method('executeMerge');
        $this->mergeService->expects($this->never())->method('reverseMerge');

        $this->controller->preview();
    }//end testPreviewNeverWrites()

    public function testExecuteEnforcesRbacViaNotAuthorized(): void
    {
        $this->request->method('getParam')->willReturnArgument(1);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->mergeService->method('executeMerge')->willThrowException(
            new NotAuthorizedException(message: 'denied')
        );

        $response = $this->controller->execute();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testExecuteEnforcesRbacViaNotAuthorized()

    public function testExecuteDelegatesToExecuteMergeWithActingUser(): void
    {
        $this->request->method('getParam')->willReturnMap(
            [
                ['from', '', 'from-uuid'],
                ['into', '', 'into-uuid'],
                ['reason', '', 'dup-review'],
            ]
        );
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $operation = ['mergedIntoUuid' => 'into-uuid', 'mergedFromUuids' => ['from-uuid']];
        $this->mergeService->expects($this->once())
            ->method('executeMerge')
            ->with('from-uuid', 'into-uuid', 'dup-review', 'alice')
            ->willReturn($operation);

        $response = $this->controller->execute();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($operation, $response->getData());
    }//end testExecuteDelegatesToExecuteMergeWithActingUser()

    public function testExecuteMapsRuntimeExceptionToNotFound(): void
    {
        $this->request->method('getParam')->willReturnArgument(1);
        $this->userSession->method('getUser')->willReturn(null);
        $this->mergeService->method('executeMerge')->willThrowException(new RuntimeException('missing'));

        $response = $this->controller->execute();

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testExecuteMapsRuntimeExceptionToNotFound()

    public function testReverseDelegatesToReverseMergeWithActingUser(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($user);

        $updated = ['reversible' => false, 'reversedBy' => 'bob'];
        $this->mergeService->expects($this->once())
            ->method('reverseMerge')
            ->with('op-uuid', 'bob')
            ->willReturn($updated);

        $response = $this->controller->reverse('op-uuid');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($updated, $response->getData());
    }//end testReverseDelegatesToReverseMergeWithActingUser()

    public function testReverseMapsNotAuthorizedToForbidden(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->mergeService->method('reverseMerge')->willThrowException(
            new NotAuthorizedException(message: 'denied')
        );

        $response = $this->controller->reverse('op-uuid');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testReverseMapsNotAuthorizedToForbidden()
}//end class
