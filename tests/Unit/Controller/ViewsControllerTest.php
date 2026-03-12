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

    // ---------------------------------------------------------------
    // index() — pagination and error branches
    // ---------------------------------------------------------------

    public function testIndexWithLimitAndOffset(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            '_limit'  => '2',
            '_offset' => '1',
        ]);

        $views = [];
        for ($i = 0; $i < 5; $i++) {
            $v = new \OCA\OpenRegister\Db\View();
            $ref = new \ReflectionClass($v);
            $prop = $ref->getProperty('id');
            $prop->setAccessible(true);
            $prop->setValue($v, $i + 1);
            $v->setName("View $i");
            $v->setQuery([]);
            $views[] = $v;
        }

        $this->viewService->method('findAll')->willReturn($views);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(5, $data['total']);
        $this->assertCount(2, $data['results']);
    }

    public function testIndexWithLimitAndPage(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            '_limit' => '2',
            '_page'  => '2',
        ]);

        $views = [];
        for ($i = 0; $i < 5; $i++) {
            $v = new \OCA\OpenRegister\Db\View();
            $ref = new \ReflectionClass($v);
            $prop = $ref->getProperty('id');
            $prop->setAccessible(true);
            $prop->setValue($v, $i + 1);
            $v->setName("View $i");
            $v->setQuery([]);
            $views[] = $v;
        }

        $this->viewService->method('findAll')->willReturn($views);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        // Page 2, limit 2 → offset 2 → items at index 2,3.
        $this->assertCount(2, $data['results']);
        $this->assertEquals(5, $data['total']);
    }

    public function testIndexWithLimitOnly(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            '_limit' => '3',
        ]);

        $views = [];
        for ($i = 0; $i < 5; $i++) {
            $v = new \OCA\OpenRegister\Db\View();
            $ref = new \ReflectionClass($v);
            $prop = $ref->getProperty('id');
            $prop->setAccessible(true);
            $prop->setValue($v, $i + 1);
            $v->setName("View $i");
            $v->setQuery([]);
            $views[] = $v;
        }

        $this->viewService->method('findAll')->willReturn($views);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        // Limit 3 with no offset/page → first 3 items.
        $this->assertCount(3, $data['results']);
        $this->assertEquals(5, $data['total']);
    }

    public function testIndexException(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([]);
        $this->viewService->method('findAll')
            ->willThrowException(new \Exception('DB error'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->controller->index();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to fetch views', $data['error']);
        $this->assertEquals('DB error', $data['message']);
    }

    // ---------------------------------------------------------------
    // show() — general exception branch
    // ---------------------------------------------------------------

    public function testShowException(): void
    {
        $this->mockAuthenticatedUser();
        $this->viewService->method('find')
            ->willThrowException(new \Exception('Unexpected error'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->controller->show('1');

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to fetch view', $data['error']);
        $this->assertEquals('Unexpected error', $data['message']);
    }

    // ---------------------------------------------------------------
    // create() — unauthenticated, configuration-based, exception
    // ---------------------------------------------------------------

    public function testCreateNotAuthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->controller->create();

        $this->assertEquals(401, $result->getStatus());
        $this->assertEquals('User not authenticated', $result->getData()['error']);
    }

    public function testCreateWithConfiguration(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            'name'          => 'Config View',
            'description'   => 'From configuration',
            'isPublic'      => true,
            'isDefault'     => true,
            'configuration' => [
                'registers'     => [1, 2],
                'schemas'       => [3],
                'source'        => 'manual',
                'searchTerms'   => ['test'],
                'facetFilters'  => ['status' => 'active'],
                'enabledFacets' => ['status'],
            ],
        ]);

        $view = $this->createViewEntity();
        $this->viewService->expects($this->once())
            ->method('create')
            ->with(
                'Config View',
                'From configuration',
                'testuser',
                true,
                true,
                [
                    'registers'     => [1, 2],
                    'schemas'       => [3],
                    'source'        => 'manual',
                    'searchTerms'   => ['test'],
                    'facetFilters'  => ['status' => 'active'],
                    'enabledFacets' => ['status'],
                ]
            )
            ->willReturn($view);

        $result = $this->controller->create();

        $this->assertEquals(201, $result->getStatus());
        $this->assertArrayHasKey('view', $result->getData());
    }

    public function testCreateWithConfigurationDefaults(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            'name'          => 'Minimal Config View',
            'configuration' => [
                // Empty config — all defaults.
            ],
        ]);

        $view = $this->createViewEntity();
        $this->viewService->expects($this->once())
            ->method('create')
            ->with(
                'Minimal Config View',
                '',
                'testuser',
                false,
                false,
                [
                    'registers'     => [],
                    'schemas'       => [],
                    'source'        => 'auto',
                    'searchTerms'   => [],
                    'facetFilters'  => [],
                    'enabledFacets' => [],
                ]
            )
            ->willReturn($view);

        $result = $this->controller->create();

        $this->assertEquals(201, $result->getStatus());
    }

    public function testCreateException(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            'name'  => 'Fail View',
            'query' => ['registers' => [1]],
        ]);
        $this->viewService->method('create')
            ->willThrowException(new \Exception('Insert failed'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->controller->create();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to create view', $data['error']);
        $this->assertEquals('Insert failed', $data['message']);
    }

    public function testCreateWithEmptyName(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            'name'  => '',
            'query' => ['registers' => [1]],
        ]);

        $result = $this->controller->create();

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals('View name is required', $result->getData()['error']);
    }

    // ---------------------------------------------------------------
    // update() — unauthenticated, missing name, missing query,
    //            configuration-based, exception
    // ---------------------------------------------------------------

    public function testUpdateNotAuthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->controller->update('1');

        $this->assertEquals(401, $result->getStatus());
        $this->assertEquals('User not authenticated', $result->getData()['error']);
    }

    public function testUpdateMissingName(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            'query' => ['registers' => [1]],
        ]);

        $result = $this->controller->update('1');

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals('View name is required', $result->getData()['error']);
    }

    public function testUpdateMissingQuery(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            'name' => 'Updated View',
        ]);

        $result = $this->controller->update('1');

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals('View query or configuration is required', $result->getData()['error']);
    }

    public function testUpdateWithConfiguration(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            'name'          => 'Updated Config View',
            'description'   => 'Updated desc',
            'isPublic'      => true,
            'isDefault'     => false,
            'configuration' => [
                'registers'     => [10],
                'schemas'       => [20],
                'source'        => 'elasticsearch',
                'searchTerms'   => ['foo'],
                'facetFilters'  => ['type' => 'bar'],
                'enabledFacets' => ['type'],
            ],
        ]);

        $view = $this->createViewEntity();
        $this->viewService->expects($this->once())
            ->method('update')
            ->with(
                '1',
                'Updated Config View',
                'Updated desc',
                'testuser',
                true,
                false,
                [
                    'registers'     => [10],
                    'schemas'       => [20],
                    'source'        => 'elasticsearch',
                    'searchTerms'   => ['foo'],
                    'facetFilters'  => ['type' => 'bar'],
                    'enabledFacets' => ['type'],
                ]
            )
            ->willReturn($view);

        $result = $this->controller->update('1');

        $this->assertEquals(200, $result->getStatus());
        $this->assertArrayHasKey('view', $result->getData());
    }

    public function testUpdateException(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            'name'  => 'Fail Update',
            'query' => ['registers' => [1]],
        ]);
        $this->viewService->method('update')
            ->willThrowException(new \Exception('Update failed'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->controller->update('1');

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to update view', $data['error']);
        $this->assertEquals('Update failed', $data['message']);
    }

    public function testUpdateWithEmptyName(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            'name'  => '',
            'query' => ['registers' => [1]],
        ]);

        $result = $this->controller->update('1');

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals('View name is required', $result->getData()['error']);
    }

    // ---------------------------------------------------------------
    // patch() — unauthenticated, not found, exception, configuration,
    //           direct query, isPublic/isDefault overrides, favoredBy
    // ---------------------------------------------------------------

    public function testPatchNotAuthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->controller->patch('1');

        $this->assertEquals(401, $result->getStatus());
        $this->assertEquals('User not authenticated', $result->getData()['error']);
    }

    public function testPatchNotFound(): void
    {
        $this->mockAuthenticatedUser();
        $this->viewService->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->patch('999');

        $this->assertEquals(404, $result->getStatus());
        $this->assertEquals('View not found', $result->getData()['error']);
    }

    public function testPatchException(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([]);

        $view = $this->createViewEntity();
        $this->viewService->method('find')->willReturn($view);
        $this->viewService->method('update')
            ->willThrowException(new \Exception('Patch failed'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->controller->patch('1');

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to patch view', $data['error']);
        $this->assertEquals('Patch failed', $data['message']);
    }

    public function testPatchWithConfiguration(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            'configuration' => [
                'registers'     => [5],
                'schemas'       => [6],
                'source'        => 'solr',
                'searchTerms'   => ['hello'],
                'facetFilters'  => ['cat' => 'dog'],
                'enabledFacets' => ['cat'],
            ],
        ]);

        $view = $this->createViewEntity();
        $this->viewService->method('find')->willReturn($view);

        $updatedView = $this->createViewEntity();
        $this->viewService->expects($this->once())
            ->method('update')
            ->with(
                '1',
                'Test View',
                'Desc',
                'testuser',
                false,
                false,
                [
                    'registers'     => [5],
                    'schemas'       => [6],
                    'source'        => 'solr',
                    'searchTerms'   => ['hello'],
                    'facetFilters'  => ['cat' => 'dog'],
                    'enabledFacets' => ['cat'],
                ],
                []
            )
            ->willReturn($updatedView);

        $result = $this->controller->patch('1');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testPatchWithDirectQuery(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            'query' => ['registers' => [99], 'schemas' => [88]],
        ]);

        $view = $this->createViewEntity();
        $this->viewService->method('find')->willReturn($view);

        $updatedView = $this->createViewEntity();
        $this->viewService->expects($this->once())
            ->method('update')
            ->with(
                '1',
                'Test View',
                'Desc',
                'testuser',
                false,
                false,
                ['registers' => [99], 'schemas' => [88]],
                []
            )
            ->willReturn($updatedView);

        $result = $this->controller->patch('1');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testPatchWithIsPublicAndIsDefaultOverrides(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            'isPublic'  => true,
            'isDefault' => true,
        ]);

        $view = $this->createViewEntity();
        $this->viewService->method('find')->willReturn($view);

        $updatedView = $this->createViewEntity();
        $this->viewService->expects($this->once())
            ->method('update')
            ->with(
                '1',
                'Test View',
                'Desc',
                'testuser',
                true,
                true,
                ['registers' => []],
                []
            )
            ->willReturn($updatedView);

        $result = $this->controller->patch('1');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testPatchWithFavoredBy(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([
            'favoredBy' => ['user1', 'user2'],
        ]);

        $view = $this->createViewEntity();
        $this->viewService->method('find')->willReturn($view);

        $updatedView = $this->createViewEntity();
        $this->viewService->expects($this->once())
            ->method('update')
            ->with(
                '1',
                'Test View',
                'Desc',
                'testuser',
                false,
                false,
                ['registers' => []],
                ['user1', 'user2']
            )
            ->willReturn($updatedView);

        $result = $this->controller->patch('1');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testPatchNoFieldsUpdatesWithExistingValues(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn([]);

        $view = $this->createViewEntity();
        $view->setDescription('Original Desc');
        $view->setIsPublic(true);
        $view->setIsDefault(true);
        $view->setQuery(['registers' => [42]]);
        $view->setFavoredBy(['userX']);
        $this->viewService->method('find')->willReturn($view);

        $updatedView = $this->createViewEntity();
        $this->viewService->expects($this->once())
            ->method('update')
            ->with(
                '1',
                'Test View',
                'Original Desc',
                'testuser',
                true,
                true,
                ['registers' => [42]],
                ['userX']
            )
            ->willReturn($updatedView);

        $result = $this->controller->patch('1');

        $this->assertEquals(200, $result->getStatus());
    }

    // ---------------------------------------------------------------
    // destroy() — unauthenticated, exception
    // ---------------------------------------------------------------

    public function testDestroyNotAuthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->controller->destroy('1');

        $this->assertEquals(401, $result->getStatus());
        $this->assertEquals('User not authenticated', $result->getData()['error']);
    }

    public function testDestroyException(): void
    {
        $this->mockAuthenticatedUser();
        $this->viewService->method('delete')
            ->willThrowException(new \Exception('Delete failed'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->controller->destroy('1');

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to delete view', $data['error']);
        $this->assertEquals('Delete failed', $data['message']);
    }
}
