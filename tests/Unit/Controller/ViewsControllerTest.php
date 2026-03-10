<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ViewsController;
use OCA\OpenRegister\Service\ViewService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ViewsControllerTest extends TestCase
{
    private ViewsController $controller;
    private IRequest&MockObject $request;
    private ViewService&MockObject $viewService;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->viewService = $this->createMock(ViewService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new ViewsController(
            'openregister',
            $this->request,
            $this->viewService,
            $this->userSession,
            $this->logger
        );
    }

    private function mockAuthenticatedUser(string $uid = 'testuser'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function createViewEntity(): \OCA\OpenRegister\Db\View
    {
        $view = new \OCA\OpenRegister\Db\View();
        $ref = new \ReflectionClass($view);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($view, 1);
        $view->setName('Test View');
        $view->setDescription('Desc');
        $view->setIsPublic(false);
        $view->setIsDefault(false);
        $view->setQuery(['registers' => []]);
        return $view;
    }

    public function testIndexNotAuthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->controller->index();

        $this->assertEquals(401, $result->getStatus());
    }

    public function testIndexSuccess(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([]);

        $view = $this->createViewEntity();
        $this->viewService->method('findAll')->willReturn([$view]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(1, $data['results']);
        $this->assertEquals(1, $data['total']);
    }

    public function testShowNotAuthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->controller->show('1');

        $this->assertEquals(401, $result->getStatus());
    }

    public function testShowSuccess(): void
    {
        $this->mockAuthenticatedUser();
        $view = $this->createViewEntity();
        $this->viewService->method('find')->willReturn($view);

        $result = $this->controller->show('1');

        $this->assertEquals(200, $result->getStatus());
        $this->assertArrayHasKey('view', $result->getData());
    }

    public function testShowNotFound(): void
    {
        $this->mockAuthenticatedUser();
        $this->viewService->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->show('999');

        $this->assertEquals(404, $result->getStatus());
    }

    public function testCreateSuccess(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            'name' => 'New View',
            'query' => ['registers' => [1]],
        ]);

        $view = $this->createViewEntity();
        $this->viewService->method('create')->willReturn($view);

        $result = $this->controller->create();

        $this->assertEquals(201, $result->getStatus());
        $this->assertArrayHasKey('view', $result->getData());
    }

    public function testCreateMissingName(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            'query' => ['registers' => [1]],
        ]);

        $result = $this->controller->create();

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals('View name is required', $result->getData()['error']);
    }

    public function testCreateMissingQuery(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            'name' => 'New View',
        ]);

        $result = $this->controller->create();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testUpdateSuccess(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            'name' => 'Updated',
            'query' => ['registers' => [1]],
        ]);

        $view = $this->createViewEntity();
        $this->viewService->method('update')->willReturn($view);

        $result = $this->controller->update('1');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateNotFound(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            'name' => 'Updated',
            'query' => ['registers' => [1]],
        ]);
        $this->viewService->method('update')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->update('999');

        $this->assertEquals(404, $result->getStatus());
    }

    public function testPatchSuccess(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn(['name' => 'Patched']);

        $view = $this->createViewEntity();
        $this->viewService->method('find')->willReturn($view);
        $this->viewService->method('update')->willReturn($view);

        $result = $this->controller->patch('1');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testDestroySuccess(): void
    {
        $this->mockAuthenticatedUser();

        $result = $this->controller->destroy('1');

        $this->assertEquals(204, $result->getStatus());
    }

    public function testDestroyNotFound(): void
    {
        $this->mockAuthenticatedUser();
        $this->viewService->method('delete')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->destroy('999');

        $this->assertEquals(404, $result->getStatus());
    }
}
