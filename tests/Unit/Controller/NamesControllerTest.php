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
	 * Characterisation: each accepted `ids` shape reaches the lookup normalised.
	 *
	 * @return void
	 */
	public function testIndexNormalisesEachIdsShape(): void {
		$shapes = [
			[' uuid-1 , uuid-2', ['uuid-1', 'uuid-2']],
			['["uuid-3","uuid-4"]', ['uuid-3', 'uuid-4']],
			['[not json', []],
			[['uuid-5'], ['uuid-5']],
			[42, ['42']],
		];
		$seen = [];
		$this->cacheHandler->method('getMultipleObjectNames')
			->willReturnCallback(
				function (array $ids) use (&$seen): array {
					$seen[] = $ids;
					return ['uuid-1' => 'Name 1'];
				}
			);
		$this->cacheHandler->expects($this->never())->method('getAllObjectNames');
		$this->cacheHandler->method('getStats')->willReturn(['hits' => 3]);
		$current = null;
		$this->request->method('getParam')->willReturnCallback(
			function () use (&$current) {
				return $current;
			}
		);

		foreach ($shapes as $shape) {
			$current = $shape[0];
			$data    = $this->controller->index()->getData();
			$this->assertSame(['names', 'total', 'cached', 'execution_time', 'cache_stats'], array_keys($data));
			$this->assertSame(['uuid-1' => 'Name 1'], $data['names']);
			$this->assertSame(1, $data['total']);
			$this->assertSame(['hits' => 3], $data['cache_stats']);
			$this->assertStringEndsWith('ms', $data['execution_time']);
		}

		$this->assertSame(array_column($shapes, 1), $seen);
	}

	/**
	 * Characterisation: the bulk lookup logs one debug line; the all-names lookup another.
	 *
	 * @return void
	 */
	public function testIndexLogsTheLookupKind(): void {
		$messages = [];
		$this->logger->method('debug')->willReturnCallback(
			function (string $message, array $context) use (&$messages): void {
				$messages[] = [$message, array_keys($context)];
			}
		);
		$this->cacheHandler->method('getAllObjectNames')->willReturn(['a' => 'A', 'b' => 'B']);
		$this->cacheHandler->method('getMultipleObjectNames')->willReturn([]);
		$this->cacheHandler->method('getStats')->willReturn([]);
		$current = null;
		$this->request->method('getParam')->willReturnCallback(
			function () use (&$current) {
				return $current;
			}
		);

		$this->controller->index();
		$current = 'x';
		$this->controller->index();

		$this->assertSame(
			[
				['[NamesController] 📋 ALL NAMES REQUEST', ['file', 'line', 'total_names', 'execution_time']],
				['[NamesController] 📦 BULK NAME LOOKUP REQUEST', ['file', 'line', 'requested_count', 'found_count', 'execution_time']],
			],
			$messages
		);
	}

	/**
	 * Characterisation: failures log an error and answer 500 with the message.
	 *
	 * @return void
	 */
	public function testFailuresLogAndReportTheMessage(): void {
		$errors = [];
		$this->logger->method('error')->willReturnCallback(
			function (string $message, array $context) use (&$errors): void {
				$errors[] = [$message, array_keys($context), $context['error']];
			}
		);
		$this->request->method('getParam')->willReturn(null);
		$this->request->method('getParams')->willReturn(['ids' => ['uuid-1']]);
		$this->cacheHandler->method('getAllObjectNames')->willThrowException(new Exception('index broke'));
		$this->cacheHandler->method('getMultipleObjectNames')->willThrowException(new Exception('post broke'));

		$index  = $this->controller->index();
		$create = $this->controller->create();

		$this->assertSame(500, $index->getStatus());
		$this->assertSame(['error' => 'Failed to retrieve object names', 'message' => 'index broke'], $index->getData());
		$this->assertSame(500, $create->getStatus());
		$this->assertSame(['error' => 'Failed to retrieve object names', 'message' => 'post broke'], $create->getData());
		$this->assertSame(
			[
				['[NamesController] Names endpoint failed', ['file', 'line', 'error', 'trace'], 'index broke'],
				['[NamesController] POST names endpoint failed', ['file', 'line', 'error', 'trace'], 'post broke'],
			],
			$errors
		);
	}

	/**
	 * Characterisation: create() trims and drops blank ids, and answers each refusal exactly.
	 *
	 * @return void
	 */
	public function testCreateTrimsIdsAndAnswersEachRefusal(): void {
		$current = null;
		$this->request->method('getParams')->willReturnCallback(
			function () use (&$current) {
				return $current;
			}
		);
		$seen = null;
		$this->cacheHandler->method('getMultipleObjectNames')->willReturnCallback(
			function (array $ids) use (&$seen): array {
				$seen = $ids;
				return ['uuid-1' => 'Name 1'];
			}
		);
		$this->cacheHandler->method('getStats')->willReturn(['hits' => 1]);
		$debug = [];
		$this->logger->method('debug')->willReturnCallback(
			function (string $message, array $context) use (&$debug): void {
				$debug[] = [$message, $context['requested_count'], $context['found_count']];
			}
		);

		$current = ['ids' => [' uuid-1 ', '', 'uuid-2']];
		$data    = $this->controller->create()->getData();
		$this->assertSame([0 => 'uuid-1', 2 => 'uuid-2'], $seen);
		$this->assertSame(['names', 'total', 'requested', 'cached', 'execution_time', 'cache_stats'], array_keys($data));
		$this->assertSame(2, $data['requested']);
		$this->assertSame(1, $data['total']);
		$this->assertTrue($data['cached']);
		$this->assertSame(['hits' => 1], $data['cache_stats']);
		$this->assertSame([['[NamesController] 📦 BULK NAME POST REQUEST', 2, 1]], $debug);

		$current = ['ids' => 'x'];
		$this->assertSame(
			[
				'error' => 'Invalid request: ids array is required in request body',
				'example' => ['ids' => ['uuid-1', 'uuid-2', 'uuid-3']],
			],
			$this->controller->create()->getData()
		);
		$current = ['ids' => [' ']];
		$this->assertSame(['error' => 'No valid IDs provided in request'], $this->controller->create()->getData());
	}

	/**
	 * Characterisation: both endpoints refuse an anonymous caller with the same body.
	 *
	 * @return void
	 */
	public function testAnonymousCallersGetTheSameRefusal(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->cacheHandler->expects($this->never())->method('getMultipleObjectNames');
		$this->cacheHandler->expects($this->never())->method('getAllObjectNames');
		$controller = new NamesController('openregister', $this->request, $this->cacheHandler, $this->logger, $session);

		foreach ([$controller->index(), $controller->create()] as $response) {
			$this->assertSame(401, $response->getStatus());
			$this->assertSame(['error' => 'Authentication required'], $response->getData());
		}
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
