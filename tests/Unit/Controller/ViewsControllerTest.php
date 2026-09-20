<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ViewsController;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Service\Rbac\ViewerReach;
use OCA\OpenRegister\Service\Rbac\ViewerReachResolver;
use OCA\OpenRegister\Service\ViewPresentationService;
use OCA\OpenRegister\Service\ViewService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ViewsControllerTest extends TestCase {
	private ViewsController $controller;
	private IRequest&MockObject $request;
	private ViewService&MockObject $viewService;
	private ViewPresentationService&MockObject $viewPresentationService;
	private LoggerInterface&MockObject $logger;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;

	/**
	 * The REAL reach resolver, over mocked Nextcloud collaborators.
	 *
	 * Not a double. The field guard `update()` and `patch()` lean on lives
	 * inside it now, and a double would answer "nothing refused" to every
	 * call, which is what a stranger rewriting somebody else's public view
	 * looks like from the outside.
	 *
	 * @var ViewerReachResolver
	 */
	private ViewerReachResolver $viewers;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->viewService = $this->createMock(ViewService::class);
		$this->viewPresentationService = $this->createMock(ViewPresentationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->viewers = new ViewerReachResolver(
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			logger: $this->logger
		);

		$this->controller = new ViewsController(
			'openregister',
			$this->request,
			$this->viewService,
			$this->viewPresentationService,
			$this->logger,
			$this->viewers
		);
	}

	private function mockAuthenticatedUser(string $uid = 'testuser'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function createViewEntity(): \OCA\OpenRegister\Db\View {
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
		// The caller owns it. Without an owner the field guard on update()
		// and patch() reads the caller as a stranger and refuses everything,
		// which is correct behaviour and was hiding behind a guard that had
		// no call site.
		$view->setOwner('testuser');
		return $view;
	}

	public function testIndexNotAuthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$result = $this->controller->index();

		$this->assertEquals(401, $result->getStatus());
	}

	public function testIndexSuccess(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn([]);

		$view = $this->createViewEntity();
		$this->viewService->method('findAllFor')->willReturn([$view]);

		$result = $this->controller->index();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertCount(1, $data['results']);
		$this->assertEquals(1, $data['total']);
	}

	/**
	 * The list is answered for the caller the controller actually read.
	 *
	 * `findAllFor()` used to take `(userId, userGroups, bool $isAdmin = false)`
	 * and the controller passed an untyped `['groups' => ..., 'isAdmin' => ...]`
	 * array into it. Either half could be dropped and the call still compiled;
	 * it just answered the narrow list. The reach now arrives as one
	 * {@see ViewerReach} with no defaults, so this pins that what
	 * {@see ViewerReachResolver::reachOf()} answered is what the list was
	 * asked for.
	 *
	 * @return void
	 */
	public function testTheListIsAskedForWithTheCallersFullReach(): void {
		$this->mockAuthenticatedUser();
		$this->groupManager->method('isAdmin')->with('testuser')->willReturn(true);
		$this->groupManager->method('getUserGroupIds')->willReturn(['staff', 'archive']);
		$this->request->method('getParams')->willReturn([]);

		$seen = null;
		$this->viewService->expects($this->once())
			->method('findAllFor')
			->willReturnCallback(function (ViewerReach $reach) use (&$seen): array {
				$seen = $reach;
				return [];
			});

		$this->controller->index();

		$this->assertInstanceOf(ViewerReach::class, $seen);
		$this->assertSame('testuser', $seen->userId);
		$this->assertSame(['staff', 'archive'], $seen->groups);
		$this->assertTrue($seen->isAdmin, 'an administrator must not be narrowed to their own views');
	}//end testTheListIsAskedForWithTheCallersFullReach()

	public function testShowNotAuthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$result = $this->controller->show('1');

		$this->assertEquals(401, $result->getStatus());
	}

	public function testShowSuccess(): void {
		$this->mockAuthenticatedUser();
		$view = $this->createViewEntity();
		$this->viewService->method('find')->willReturn($view);

		$result = $this->controller->show('1');

		$this->assertEquals(200, $result->getStatus());
		$this->assertArrayHasKey('view', $result->getData());
	}

	public function testShowNotFound(): void {
		$this->mockAuthenticatedUser();
		$this->viewService->method('find')
			->willThrowException(new DoesNotExistException('not found'));

		$result = $this->controller->show('999');

		$this->assertEquals(404, $result->getStatus());
	}

	public function testCreateSuccess(): void {
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

	public function testCreateMissingName(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn([
			'query' => ['registers' => [1]],
		]);

		$result = $this->controller->create();

		$this->assertEquals(400, $result->getStatus());
		$this->assertEquals('View name is required', $result->getData()['error']);
	}

	public function testCreateMissingQuery(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn([
			'name' => 'New View',
		]);

		$result = $this->controller->create();

		$this->assertEquals(400, $result->getStatus());
	}

	public function testUpdateSuccess(): void {
		$this->mockAuthenticatedUser();
		// The guard on update() resolves the view first; it is the caller's own.
		$this->viewService->method('find')->willReturn($this->createViewEntity());
		$this->request->method('getParams')->willReturn([
			'name' => 'Updated',
			'query' => ['registers' => [1]],
		]);

		$view = $this->createViewEntity();
		$this->viewService->method('update')->willReturn($view);

		$result = $this->controller->update('1');

		$this->assertEquals(200, $result->getStatus());
	}

	public function testUpdateNotFound(): void {
		$this->mockAuthenticatedUser();
		// The guard on update() resolves the view first; it is the caller's own.
		$this->viewService->method('find')->willReturn($this->createViewEntity());
		$this->request->method('getParams')->willReturn([
			'name' => 'Updated',
			'query' => ['registers' => [1]],
		]);
		$this->viewService->method('update')
			->willThrowException(new DoesNotExistException('not found'));

		$result = $this->controller->update('999');

		$this->assertEquals(404, $result->getStatus());
	}

	public function testPatchSuccess(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn(['name' => 'Patched']);

		$view = $this->createViewEntity();
		$this->viewService->method('find')->willReturn($view);
		$this->viewService->method('update')->willReturn($view);

		$result = $this->controller->patch('1');

		$this->assertEquals(200, $result->getStatus());
	}

	public function testDestroySuccess(): void {
		$this->mockAuthenticatedUser();

		$result = $this->controller->destroy('1');

		$this->assertEquals(204, $result->getStatus());
	}

	public function testDestroyNotFound(): void {
		$this->mockAuthenticatedUser();
		$this->viewService->method('delete')
			->willThrowException(new DoesNotExistException('not found'));

		$result = $this->controller->destroy('999');

		$this->assertEquals(404, $result->getStatus());
	}

	// ---------------------------------------------------------------
	// index() — pagination and error branches
	// ---------------------------------------------------------------

	public function testIndexWithLimitAndOffset(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn([
			'_limit' => '2',
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

		$this->viewService->method('findAllFor')->willReturn($views);

		$result = $this->controller->index();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals(5, $data['total']);
		$this->assertCount(2, $data['results']);
	}

	public function testIndexWithLimitAndPage(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn([
			'_limit' => '2',
			'_page' => '2',
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

		$this->viewService->method('findAllFor')->willReturn($views);

		$result = $this->controller->index();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		// Page 2, limit 2 → offset 2 → items at index 2,3.
		$this->assertCount(2, $data['results']);
		$this->assertEquals(5, $data['total']);
	}

	public function testIndexWithLimitOnly(): void {
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

		$this->viewService->method('findAllFor')->willReturn($views);

		$result = $this->controller->index();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		// Limit 3 with no offset/page → first 3 items.
		$this->assertCount(3, $data['results']);
		$this->assertEquals(5, $data['total']);
	}

	public function testIndexException(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn([]);
		$this->viewService->method('findAllFor')
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

	public function testShowException(): void {
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

	public function testCreateNotAuthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$result = $this->controller->create();

		$this->assertEquals(401, $result->getStatus());
		$this->assertEquals('User not authenticated', $result->getData()['error']);
	}

	public function testCreateWithConfiguration(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn([
			'name' => 'Config View',
			'description' => 'From configuration',
			'isPublic' => true,
			'isDefault' => true,
			'configuration' => [
				'registers' => [1, 2],
				'schemas' => [3],
				'source' => 'manual',
				'searchTerms' => ['test'],
				'facetFilters' => ['status' => 'active'],
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
					'registers' => [1, 2],
					'schemas' => [3],
					'source' => 'manual',
					'searchTerms' => ['test'],
					'facetFilters' => ['status' => 'active'],
					'enabledFacets' => ['status'],
				],
				null
			)
			->willReturn($view);

		$result = $this->controller->create();

		$this->assertEquals(201, $result->getStatus());
		$this->assertArrayHasKey('view', $result->getData());
	}

	public function testCreateWithConfigurationDefaults(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn([
			'name' => 'Minimal Config View',
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
					'registers' => [],
					'schemas' => [],
					'source' => 'auto',
					'searchTerms' => [],
					'facetFilters' => [],
					'enabledFacets' => [],
				],
				null
			)
			->willReturn($view);

		$result = $this->controller->create();

		$this->assertEquals(201, $result->getStatus());
	}

	public function testCreateException(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn([
			'name' => 'Fail View',
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

	public function testCreateWithEmptyName(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn([
			'name' => '',
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

	public function testUpdateNotAuthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$result = $this->controller->update('1');

		$this->assertEquals(401, $result->getStatus());
		$this->assertEquals('User not authenticated', $result->getData()['error']);
	}

	public function testUpdateMissingName(): void {
		$this->mockAuthenticatedUser();
		// The guard on update() resolves the view first; it is the caller's own.
		$this->viewService->method('find')->willReturn($this->createViewEntity());
		$this->request->method('getParams')->willReturn([
			'query' => ['registers' => [1]],
		]);

		$result = $this->controller->update('1');

		$this->assertEquals(400, $result->getStatus());
		$this->assertEquals('View name is required', $result->getData()['error']);
	}

	public function testUpdateMissingQuery(): void {
		$this->mockAuthenticatedUser();
		// The guard on update() resolves the view first; it is the caller's own.
		$this->viewService->method('find')->willReturn($this->createViewEntity());
		$this->request->method('getParams')->willReturn([
			'name' => 'Updated View',
		]);

		$result = $this->controller->update('1');

		$this->assertEquals(400, $result->getStatus());
		$this->assertEquals('View query or configuration is required', $result->getData()['error']);
	}

	public function testUpdateWithConfiguration(): void {
		$this->mockAuthenticatedUser();
		// The guard on update() resolves the view first; it is the caller's own.
		$this->viewService->method('find')->willReturn($this->createViewEntity());
		$this->request->method('getParams')->willReturn([
			'name' => 'Updated Config View',
			'description' => 'Updated desc',
			'isPublic' => true,
			'isDefault' => false,
			'configuration' => [
				'registers' => [10],
				'schemas' => [20],
				'source' => 'elasticsearch',
				'searchTerms' => ['foo'],
				'facetFilters' => ['type' => 'bar'],
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
					'registers' => [10],
					'schemas' => [20],
					'source' => 'elasticsearch',
					'searchTerms' => ['foo'],
					'facetFilters' => ['type' => 'bar'],
					'enabledFacets' => ['type'],
				],
				null,
				null
			)
			->willReturn($view);

		$result = $this->controller->update('1');

		$this->assertEquals(200, $result->getStatus());
		$this->assertArrayHasKey('view', $result->getData());
	}

	public function testUpdateException(): void {
		$this->mockAuthenticatedUser();
		// The guard on update() resolves the view first; it is the caller's own.
		$this->viewService->method('find')->willReturn($this->createViewEntity());
		$this->request->method('getParams')->willReturn([
			'name' => 'Fail Update',
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

	public function testUpdateWithEmptyName(): void {
		$this->mockAuthenticatedUser();
		// The guard on update() resolves the view first; it is the caller's own.
		$this->viewService->method('find')->willReturn($this->createViewEntity());
		$this->request->method('getParams')->willReturn([
			'name' => '',
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

	public function testPatchNotAuthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$result = $this->controller->patch('1');

		$this->assertEquals(401, $result->getStatus());
		$this->assertEquals('User not authenticated', $result->getData()['error']);
	}

	public function testPatchNotFound(): void {
		$this->mockAuthenticatedUser();
		$this->viewService->method('find')
			->willThrowException(new DoesNotExistException('not found'));

		$result = $this->controller->patch('999');

		$this->assertEquals(404, $result->getStatus());
		$this->assertEquals('View not found', $result->getData()['error']);
	}

	public function testPatchException(): void {
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

	public function testPatchWithConfiguration(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn([
			'configuration' => [
				'registers' => [5],
				'schemas' => [6],
				'source' => 'solr',
				'searchTerms' => ['hello'],
				'facetFilters' => ['cat' => 'dog'],
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
					'registers' => [5],
					'schemas' => [6],
					'source' => 'solr',
					'searchTerms' => ['hello'],
					'facetFilters' => ['cat' => 'dog'],
					'enabledFacets' => ['cat'],
				],
				[],
				null
			)
			->willReturn($updatedView);

		$result = $this->controller->patch('1');

		$this->assertEquals(200, $result->getStatus());
	}

	public function testPatchWithDirectQuery(): void {
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
				[],
				null
			)
			->willReturn($updatedView);

		$result = $this->controller->patch('1');

		$this->assertEquals(200, $result->getStatus());
	}

	public function testPatchWithIsPublicAndIsDefaultOverrides(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn([
			'isPublic' => true,
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
				[],
				null
			)
			->willReturn($updatedView);

		$result = $this->controller->patch('1');

		$this->assertEquals(200, $result->getStatus());
	}

	public function testPatchWithFavoredBy(): void {
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
				['user1', 'user2'],
				null
			)
			->willReturn($updatedView);

		$result = $this->controller->patch('1');

		$this->assertEquals(200, $result->getStatus());
	}

	public function testPatchNoFieldsUpdatesWithExistingValues(): void {
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
				['userX'],
				null
			)
			->willReturn($updatedView);

		$result = $this->controller->patch('1');

		$this->assertEquals(200, $result->getStatus());
	}

	// ---------------------------------------------------------------
	// destroy() — unauthenticated, exception
	// ---------------------------------------------------------------

	public function testDestroyNotAuthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$result = $this->controller->destroy('1');

		$this->assertEquals(401, $result->getStatus());
		$this->assertEquals('User not authenticated', $result->getData()['error']);
	}

	public function testDestroyException(): void {
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

	// ---------------------------------------------------------------
	// create()/update() — presentation passthrough + validation errors
	// ---------------------------------------------------------------

	public function testCreatePassesPresentationThrough(): void {
		$this->mockAuthenticatedUser();
		$presentation = ['viewType' => 'kanban', 'kanban' => ['groupByField' => 'status']];
		$this->request->method('getParams')->willReturn([
			'name' => 'Kanban View',
			'query' => ['registers' => [1], 'schemas' => [2]],
			'presentation' => $presentation,
		]);

		$view = $this->createViewEntity();
		$this->viewService->expects($this->once())
			->method('create')
			->with(
				'Kanban View',
				'',
				'testuser',
				false,
				false,
				['registers' => [1], 'schemas' => [2]],
				$presentation
			)
			->willReturn($view);

		$result = $this->controller->create();

		$this->assertEquals(201, $result->getStatus());
	}

	public function testCreateWithInvalidPresentationReturns400(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn([
			'name' => 'Kanban View',
			'query' => ['registers' => [1], 'schemas' => [2]],
			'presentation' => ['viewType' => 'kanban', 'kanban' => ['groupByField' => 'status']],
		]);

		$this->viewService->method('create')
			->willThrowException(new \InvalidArgumentException('Presentation kanban.groupByField "status" is not a property of the view\'s schema'));

		$result = $this->controller->create();

		$this->assertEquals(400, $result->getStatus());
		$this->assertStringContainsString('groupByField', $result->getData()['error']);
	}

	public function testUpdateWithInvalidPresentationReturns400(): void {
		$this->mockAuthenticatedUser();
		// The guard on update() resolves the view first; it is the caller's own.
		$this->viewService->method('find')->willReturn($this->createViewEntity());
		$this->request->method('getParams')->willReturn([
			'name' => 'Kanban View',
			'query' => ['registers' => [1], 'schemas' => [2]],
			'presentation' => ['viewType' => 'calendar', 'calendar' => ['dateField' => 'missing']],
		]);

		$this->viewService->method('update')
			->willThrowException(new \InvalidArgumentException('Presentation calendar.dateField "missing" is not a property of the view\'s schema'));

		$result = $this->controller->update('1');

		$this->assertEquals(400, $result->getStatus());
		$this->assertStringContainsString('dateField', $result->getData()['error']);
	}

	public function testPatchPreservesExistingPresentationWhenOmitted(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn(['name' => 'Renamed']);

		$existingPresentation = ['viewType' => 'kanban', 'kanban' => ['groupByField' => 'status']];
		$view = $this->createViewEntity();
		$view->setPresentation($existingPresentation);
		$this->viewService->method('find')->willReturn($view);

		$updatedView = $this->createViewEntity();
		$this->viewService->expects($this->once())
			->method('update')
			->with(
				'1',
				'Renamed',
				'Desc',
				'testuser',
				false,
				false,
				['registers' => []],
				[],
				$existingPresentation
			)
			->willReturn($updatedView);

		$result = $this->controller->patch('1');

		$this->assertEquals(200, $result->getStatus());
	}

	// ---------------------------------------------------------------
	// kanban() — read-only board data
	// ---------------------------------------------------------------

	public function testKanbanNotAuthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$result = $this->controller->kanban('1');

		$this->assertEquals(401, $result->getStatus());
	}

	public function testKanbanNotFound(): void {
		$this->mockAuthenticatedUser();
		$this->viewService->method('find')
			->willThrowException(new DoesNotExistException('not found'));

		$result = $this->controller->kanban('999');

		$this->assertEquals(404, $result->getStatus());
	}

	public function testKanbanSuccess(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn([]);

		$view = $this->createViewEntity();
		$this->viewService->method('find')->willReturn($view);

		$board = [
			'viewType' => 'kanban',
			'groupByField' => 'status',
			'columns' => [
				['value' => 'todo', 'cards' => [], 'total' => 0, 'limit' => 20, 'offset' => 0],
			],
		];
		$this->viewPresentationService->method('getKanbanBoard')->willReturn($board);

		$result = $this->controller->kanban('1');

		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals($board, $result->getData());
	}

	public function testKanbanInvalidConfigReturns400(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn([]);

		$view = $this->createViewEntity();
		$this->viewService->method('find')->willReturn($view);
		$this->viewPresentationService->method('getKanbanBoard')
			->willThrowException(new \InvalidArgumentException('View is not a kanban view (viewType is "table")'));

		$result = $this->controller->kanban('1');

		$this->assertEquals(400, $result->getStatus());
	}

	// ---------------------------------------------------------------
	// calendar() — date-range object query
	// ---------------------------------------------------------------

	public function testCalendarNotAuthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$result = $this->controller->calendar('1');

		$this->assertEquals(401, $result->getStatus());
	}

	public function testCalendarMissingRangeReturns400(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn([]);

		$result = $this->controller->calendar('1');

		$this->assertEquals(400, $result->getStatus());
	}

	public function testCalendarSuccess(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn([
			'start' => '2026-07-01',
			'end' => '2026-07-31',
		]);

		$view = $this->createViewEntity();
		$this->viewService->method('find')->willReturn($view);

		$calendarResult = [
			'viewType' => 'calendar',
			'dateField' => 'dueDate',
			'endDateField' => null,
			'rangeStart' => '2026-07-01',
			'rangeEnd' => '2026-07-31',
			'objects' => [],
			'total' => 0,
		];
		$this->viewPresentationService->method('getCalendarObjects')->willReturn($calendarResult);

		$result = $this->controller->calendar('1');

		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals($calendarResult, $result->getData());
	}

	public function testCalendarNotFound(): void {
		$this->mockAuthenticatedUser();
		$this->request->method('getParams')->willReturn([
			'start' => '2026-07-01',
			'end' => '2026-07-31',
		]);
		$this->viewService->method('find')
			->willThrowException(new DoesNotExistException('not found'));

		$result = $this->controller->calendar('999');

		$this->assertEquals(404, $result->getStatus());
	}

	// ---------------------------------------------------------------
	// Kanban drag-to-move MUST reuse the guarded object PATCH/PUT path
	// (REQ-VIEW-KANBAN-03, design.md D3) — no bespoke "move card" endpoint.
	// ---------------------------------------------------------------

	public function testNoBespokeCardMoveEndpointOnController(): void {
		$reflection = new \ReflectionClass(ViewsController::class);
		$methodNames = array_map(
			fn (\ReflectionMethod $m): string => $m->getName(),
			$reflection->getMethods(\ReflectionMethod::IS_PUBLIC)
		);

		$suspicious = array_values(array_filter(
			$methodNames,
			fn (string $name): bool => (bool)preg_match('/move|drag/i', $name)
		));

		$this->assertSame(
			[],
			$suspicious,
			'Kanban drag-to-move must go through the existing guarded object PATCH/PUT path — '
				. 'ViewsController must not define a bespoke move/drag-card endpoint.'
		);
	}

	public function testKanbanAndCalendarRoutesAreReadOnlyGet(): void {
		$routesFile = dirname(__DIR__, 3) . '/appinfo/routes.php';
		$this->assertFileExists($routesFile);
		$contents = file_get_contents($routesFile);

		$this->assertMatchesRegularExpression(
			"/'views#kanban'\s*,\s*'url'\s*=>\s*'\/api\/views\/\{id\}\/kanban'\s*,\s*'verb'\s*=>\s*'GET'/",
			$contents
		);
		$this->assertMatchesRegularExpression(
			"/'views#calendar'\s*,\s*'url'\s*=>\s*'\/api\/views\/\{id\}\/calendar'\s*,\s*'verb'\s*=>\s*'GET'/",
			$contents
		);
		$this->assertDoesNotMatchRegularExpression("/'views#(moveCard|dragCard|move|drag)'/i", $contents);
	}

	/**
	 * A view someone else published is not a view anyone may rewrite.
	 *
	 * `ViewService::update()` resolves through `find($id, $owner)`, which
	 * admits the owner OR any caller when `isPublic` is true. So before the
	 * field guard was wired, any authenticated account could rename another
	 * user's shared view, rewrite its query or un-publish it, and the write
	 * succeeded with a 200.
	 *
	 * @return void
	 */
	public function testAStrangerMayNotRewriteSomeoneElsesPublicView(): void {
		$this->mockAuthenticatedUser('intruder');

		$published = $this->createViewEntity();
		$published->setOwner('someone-else');
		$published->setIsPublic(true);
		$this->viewService->method('find')->willReturn($published);
		$this->viewService->method('update')->willReturn($published);

		$this->request->method('getParams')->willReturn(
			[
				'name' => 'Renamed by a stranger',
				'isPublic' => false,
				'query' => ['registers' => [1]],
			]
		);

		$result = $this->controller->update('1');

		$this->assertEquals(403, $result->getStatus());
		$this->assertSame(
			['name', 'isPublic', 'query'],
			$result->getData()['fields'],
			'the refusal must name the fields, so the member is told which one was refused'
		);
	}

	/**
	 * The same view, the same stranger, the other verb.
	 *
	 * `patch()` carries `@NoCSRFRequired` as well, so guarding only `update()`
	 * would have left the hole open behind a verb that is easier to reach.
	 *
	 * @return void
	 */
	public function testAStrangerMayNotPatchSomeoneElsesPublicView(): void {
		$this->mockAuthenticatedUser('intruder');

		$published = $this->createViewEntity();
		$published->setOwner('someone-else');
		$published->setIsPublic(true);
		$this->viewService->method('find')->willReturn($published);
		$this->viewService->method('update')->willReturn($published);

		$this->request->method('getParams')->willReturn(['name' => 'Renamed by a stranger']);

		$result = $this->controller->patch('1');

		$this->assertEquals(403, $result->getStatus());
		$this->assertSame(['name'], $result->getData()['fields']);
	}

	/**
	 * The control: the owner still writes their own view.
	 *
	 * Without it the two tests above would pass on a guard that refused
	 * everybody, which is the failure mode a field guard invites.
	 *
	 * @return void
	 */
	public function testTheOwnerStillWritesTheirOwnPublicView(): void {
		$this->mockAuthenticatedUser();

		$own = $this->createViewEntity();
		$own->setIsPublic(true);
		$this->viewService->method('find')->willReturn($own);
		$this->viewService->method('update')->willReturn($own);

		$this->request->method('getParams')->willReturn(
			[
				'name' => 'Renamed by its owner',
				'query' => ['registers' => [1]],
			]
		);

		$this->assertEquals(200, $this->controller->update('1')->getStatus());
	}
}
