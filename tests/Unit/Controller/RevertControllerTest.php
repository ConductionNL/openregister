<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\RevertController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RevertController
 *
 * @package Unit\Controller
 */
class RevertControllerTest extends TestCase
{
    private RevertController $controller;
    private IRequest&MockObject $request;
    private RevertHandler&MockObject $revertService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->revertService = $this->createMock(RevertHandler::class);

        $this->controller = new RevertController(
            'openregister',
            $this->request,
            $this->revertService
        );
    }

    public function testRevertWithDatetimeReturnsSuccess(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $object->method('jsonSerialize')->willReturn(['id' => 1, 'uuid' => 'uuid-123']);

        $this->request->method('getParams')->willReturn([
            'datetime' => '2024-01-01T00:00:00',
        ]);
        $this->revertService->method('revert')->willReturn($object);

        $result = $this->controller->revert('reg', 'schema', 'uuid-123');

        $this->assertSame(200, $result->getStatus());
    }

    public function testRevertWithAuditTrailIdReturnsSuccess(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $object->method('jsonSerialize')->willReturn(['id' => 1]);

        $this->request->method('getParams')->willReturn([
            'auditTrailId' => 42,
        ]);
        $this->revertService->method('revert')->willReturn($object);

        $result = $this->controller->revert('reg', 'schema', 'uuid-123');

        $this->assertSame(200, $result->getStatus());
    }

    public function testRevertWithVersionReturnsSuccess(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $object->method('jsonSerialize')->willReturn(['id' => 1]);

        $this->request->method('getParams')->willReturn([
            'version' => '1.0.0',
        ]);
        $this->revertService->method('revert')->willReturn($object);

        $result = $this->controller->revert('reg', 'schema', 'uuid-123');

        $this->assertSame(200, $result->getStatus());
    }

    public function testRevertReturns400WhenNoCriteriaProvided(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->revert('reg', 'schema', 'uuid-123');

        $this->assertSame(400, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('datetime', $data['error']);
    }

    public function testRevertReturns404WhenObjectNotFound(): void
    {
        $this->request->method('getParams')->willReturn([
            'datetime' => '2024-01-01T00:00:00',
        ]);
        $this->revertService->method('revert')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->revert('reg', 'schema', 'uuid-123');

        $this->assertSame(404, $result->getStatus());
    }

    public function testRevertReturns403WhenNotAuthorized(): void
    {
        $this->request->method('getParams')->willReturn([
            'datetime' => '2024-01-01T00:00:00',
        ]);
        $this->revertService->method('revert')
            ->willThrowException(new NotAuthorizedException('Forbidden'));

        $result = $this->controller->revert('reg', 'schema', 'uuid-123');

        $this->assertSame(403, $result->getStatus());
    }

    public function testRevertReturns423WhenLocked(): void
    {
        $this->request->method('getParams')->willReturn([
            'datetime' => '2024-01-01T00:00:00',
        ]);
        $this->revertService->method('revert')
            ->willThrowException(new LockedException('Locked'));

        $result = $this->controller->revert('reg', 'schema', 'uuid-123');

        $this->assertSame(423, $result->getStatus());
    }

    public function testRevertReturns500OnGenericException(): void
    {
        $this->request->method('getParams')->willReturn([
            'datetime' => '2024-01-01T00:00:00',
        ]);
        $this->revertService->method('revert')
            ->willThrowException(new Exception('Internal error'));

        $result = $this->controller->revert('reg', 'schema', 'uuid-123');

        $this->assertSame(500, $result->getStatus());
    }
}
