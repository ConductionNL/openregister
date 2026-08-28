<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\NamesController;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for NamesController
 *
 * @package Unit\Controller
 */
class NamesControllerTest extends TestCase {
	private NamesController $controller;
	private IRequest&MockObject $request;
	private CacheHandler&MockObject $cacheHandler;
	private LoggerInterface&MockObject $logger;
	private IUserSession&MockObject $userSession;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->cacheHandler = $this->createMock(CacheHandler::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);

		// SEC-CTRL-2: index()/create() now require an authenticated user.
		// Default the session to a logged-in user so happy-path tests pass;
		// individual tests override this for the unauthenticated 401 path.
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new NamesController(
			'openregister',
			$this->request,
			$this->cacheHandler,
			$this->logger,
			$this->userSession
		);
	}

	public function testIndexReturnsAllNames(): void {
		$names = ['uuid-1' => 'Name 1', 'uuid-2' => 'Name 2'];

		$this->request->method('getParam')->willReturn(null);
		$this->cacheHandler->method('getAllObjectNames')->willReturn($names);
		$this->cacheHandler->method('getStats')->willReturn([]);

		$result = $this->controller->index();

		$this->assertSame(200, $result->getStatus());
		$data = $result->getData();
		$this->assertSame($names, $data['names']);
		$this->assertSame(2, $data['total']);
		$this->assertTrue($data['cached']);
	}

	public function testIndexWithSpecificIds(): void {
		$names = ['uuid-1' => 'Name 1'];

		$this->request->method('getParam')
			->willReturnMap([
				['ids', null, 'uuid-1,uuid-2'],
			]);
		$this->cacheHandler->method('getMultipleObjectNames')->willReturn($names);
		$this->cacheHandler->method('getStats')->willReturn([]);

		$result = $this->controller->index();

		$this->assertSame(200, $result->getStatus());
		$data = $result->getData();
		$this->assertArrayHasKey('names', $data);
	}

	public function testIndexWithJsonArrayIds(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['ids', null, '["uuid-1","uuid-2"]'],
			]);
		$this->cacheHandler->method('getMultipleObjectNames')->willReturn([]);
		$this->cacheHandler->method('getStats')->willReturn([]);

		$result = $this->controller->index();

		$this->assertSame(200, $result->getStatus());
	}

	public function testIndexReturns500OnException(): void {
		$this->request->method('getParam')->willReturn(null);
		$this->cacheHandler->method('getAllObjectNames')
			->willThrowException(new Exception('Cache error'));

		$result = $this->controller->index();

		$this->assertSame(500, $result->getStatus());
		$data = $result->getData();
		$this->assertArrayHasKey('error', $data);
	}

	public function testIndexReturns401WhenUnauthenticated(): void {
		// SEC-CTRL-2: anonymous callers must not resolve object names.
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$controller = new NamesController(
			'openregister',
			$this->request,
			$this->cacheHandler,
			$this->logger,
			$session
		);

		$result = $controller->index();

		$this->assertSame(401, $result->getStatus());
		$this->assertArrayHasKey('error', $result->getData());
	}

	public function testCreateReturns401WhenUnauthenticated(): void {
		// SEC-CTRL-2: anonymous callers must not resolve object names.
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$controller = new NamesController(
			'openregister',
			$this->request,
			$this->cacheHandler,
			$this->logger,
			$session
		);

		$result = $controller->create();

		$this->assertSame(401, $result->getStatus());
		$this->assertArrayHasKey('error', $result->getData());
	}

	public function testCreateWithValidIds(): void {
		$names = ['uuid-1' => 'Name 1'];

		$this->request->method('getParams')->willReturn([
			'ids' => ['uuid-1', 'uuid-2'],
		]);
		$this->cacheHandler->method('getMultipleObjectNames')->willReturn($names);
		$this->cacheHandler->method('getStats')->willReturn([]);

		$result = $this->controller->create();

		$this->assertSame(200, $result->getStatus());
		$data = $result->getData();
		$this->assertSame($names, $data['names']);
		$this->assertSame(2, $data['requested']);
	}

	public function testCreateReturnsBadRequestWhenIdsNotArray(): void {
		$this->request->method('getParams')->willReturn([
			'ids' => 'not-an-array',
		]);

		$result = $this->controller->create();

		$this->assertSame(400, $result->getStatus());
	}

	public function testCreateReturnsBadRequestWhenIdsMissing(): void {
		$this->request->method('getParams')->willReturn([]);

		$result = $this->controller->create();

		$this->assertSame(400, $result->getStatus());
	}

	public function testCreateReturnsBadRequestWhenIdsEmpty(): void {
		$this->request->method('getParams')->willReturn([
			'ids' => ['', ' '],
		]);

		$result = $this->controller->create();

		$this->assertSame(400, $result->getStatus());
	}

	public function testCreateReturns500OnException(): void {
		$this->request->method('getParams')->willReturn([
			'ids' => ['uuid-1'],
		]);
		$this->cacheHandler->method('getMultipleObjectNames')
			->willThrowException(new Exception('Failed'));

		$result = $this->controller->create();

		$this->assertSame(500, $result->getStatus());
	}

	/**
	 * show() / stats() / warmup() were REMOVED and must not return.
	 *
	 * These replace the seven tests that used to exercise them. Deleting those
	 * tests alone would have left nothing to notice a revert: the endpoints could
	 * be reinstated and every remaining test would still pass.
	 *
	 * All three were `#[PublicPage]`. `show($id)` was the serious one — it
	 * resolved ANY object's name via
	 * `CacheHandler::getSingleObjectName()`, which calls
	 * `findAcrossAllSources(_rbac: false, _multitenancy: false)` and tries
	 * organisations first, so an anonymous caller holding a UUID could read names
	 * across every register, schema and tenant. `warmup()` let an anonymous caller
	 * make the server rebuild the entire name cache; `stats()` exposed cache
	 * internals.
	 *
	 * @return void
	 */
	public function testRemovedPublicNameEndpointsStayRemoved(): void {
		foreach (['show', 'stats', 'warmup'] as $removed) {
			$this->assertFalse(
				method_exists($this->controller, $removed),
				sprintf(
					'NamesController::%s() was removed under SEC-CTRL-2 because it was #[PublicPage] over an '
					. 'unscoped resolver. Re-adding it re-opens anonymous cross-tenant name disclosure. If it '
					. 'must come back, it needs the caller\'s read permissions and active organisation applied '
					. 'BEFORE the cache lookup — a "no user -> 401" preamble is NOT the fix (.github#365).',
					$removed
				)
			);
		}

	}//end testRemovedPublicNameEndpointsStayRemoved()

	/**
	 * The two surviving endpoints must never be reachable anonymously.
	 *
	 * `method_exists()` above cannot catch the other half of the regression:
	 * leaving index()/create() in place but re-decorating them `#[PublicPage]`.
	 * Assert the attribute's ABSENCE directly.
	 *
	 * @return void
	 */
	public function testSurvivingEndpointsAreNotPublicPages(): void {
		$reflection = new ReflectionClass(NamesController::class);

		foreach (['index', 'create'] as $method) {
			$attributes = array_map(
				static fn ($attribute): string => $attribute->getName(),
				$reflection->getMethod($method)->getAttributes()
			);

			$this->assertNotContains(
				PublicPage::class,
				$attributes,
				sprintf(
					'NamesController::%s() must not be #[PublicPage]. Name resolution is not RBAC- or '
					. 'tenant-aware (see the TODO in index()), so anonymous access leaks names across '
					. 'organisations.',
					$method
				)
			);
		}

	}//end testSurvivingEndpointsAreNotPublicPages()
}
