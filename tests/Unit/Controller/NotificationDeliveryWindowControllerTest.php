<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\NotificationDeliveryWindowController;
use OCA\OpenRegister\Service\Notification\NotificationDeliveryWindowService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the delivery-window (quiet hours) REST surface: default
 * `{enabled: false}` when unconfigured (backward compat), round-trip
 * store/read, validation (422), clear-on-disable, and authentication
 * scoping (401 when anonymous — the "unauthenticated request MUST be
 * rejected" scenario).
 */
class NotificationDeliveryWindowControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private NotificationDeliveryWindowController $controller;
	private NotificationDeliveryWindowService $windowService;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$config = $this->createMock(IConfig::class);
		// Real service (not a mock) backed by an in-memory fake IConfig so
		// the controller test exercises the real store/validate round-trip
		// rather than asserting on mock call shapes only.
		$this->windowService = new NotificationDeliveryWindowService($config, null);
		$this->controller = new NotificationDeliveryWindowController(
			'openregister',
			$this->request,
			$this->windowService,
			$this->userSession
		);

		$store = [];
		$config->method('setUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key, string $value) use (&$store): void {
				$store[$uid . '/' . $key] = $value;
			}
		);
		$config->method('deleteUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key) use (&$store): void {
				unset($store[$uid . '/' . $key]);
			}
		);
		$config->method('getUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key, $default = '') use (&$store) {
				return $store[$uid . '/' . $key] ?? $default;
			}
		);
	}

	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	/**
	 * Issue an `update()` call with a FRESH request mock so consecutive
	 * calls in one test don't collide on PHPUnit's `method()->willReturn()`
	 * stub ordering (a second unconstrained stub on the same mock is not
	 * guaranteed to take precedence over the first).
	 *
	 * @param array<string, mixed> $params Raw request params for this call.
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse
	 */
	private function updateWith(array $params) {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$controller = new NotificationDeliveryWindowController(
			'openregister',
			$request,
			$this->windowService,
			$this->userSession
		);

		return $controller->update();
	}

	public function testIndexReturnsDisabledWhenNoWindowConfigured(): void {
		$this->signIn('piet');

		$response = $this->controller->index();
		$data = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertFalse($data['enabled']);
	}

	public function testIndexRequiresAuthentication(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->index();
		$this->assertSame(401, $response->getStatus());
	}

	public function testUpdateRequiresAuthentication(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->update();
		$this->assertSame(401, $response->getStatus());
	}

	public function testUpdateThenIndexRoundTrips(): void {
		$this->signIn('medewerker-1');
		$this->request->method('getParams')->willReturn([
			'enabled' => true,
			'start' => '18:00',
			'end' => '08:00',
			'timezone' => 'Europe/Amsterdam',
		]);

		$updateResponse = $this->controller->update();
		$this->assertSame(200, $updateResponse->getStatus());
		$this->assertTrue($updateResponse->getData()['enabled']);

		$indexResponse = $this->controller->index();
		$data = $indexResponse->getData();
		$this->assertTrue($data['enabled']);
		$this->assertSame('18:00', $data['start']);
		$this->assertSame('08:00', $data['end']);
		$this->assertSame('Europe/Amsterdam', $data['timezone']);
	}

	public function testUpdateRejectsMalformedStart(): void {
		$this->signIn('jan');
		$this->request->method('getParams')->willReturn([
			'enabled' => true,
			'start' => 'bogus',
			'end' => '08:00',
		]);

		$response = $this->controller->update();
		$this->assertSame(422, $response->getStatus());
		$this->assertSame('notification-delivery-window-invalid', $response->getData()['code']);
	}

	public function testUpdateWithEnabledFalseClearsWindow(): void {
		$this->signIn('jan');

		// Store a window first.
		$this->updateWith(['enabled' => true, 'start' => '18:00', 'end' => '08:00', 'timezone' => 'UTC']);

		// Then clear it.
		$response = $this->updateWith(['enabled' => false]);

		$this->assertFalse($response->getData()['enabled']);

		$indexResponse = $this->controller->index();
		$this->assertFalse($indexResponse->getData()['enabled']);
	}

	/**
	 * A request for another user's window is impossible by construction —
	 * the controller never reads a uid from request params, only from the
	 * authenticated session. This test documents that guarantee: even a
	 * caller who tries to smuggle a `uid` param is scoped to their own
	 * session user, so the target ("admin") never receives a window.
	 */
	public function testUpdateIgnoresAnyUidSuppliedInRequestBody(): void {
		$this->signIn('jan');
		$this->updateWith([
			'uid' => 'admin',
			'enabled' => true,
			'start' => '18:00',
			'end' => '08:00',
			'timezone' => 'UTC',
		]);

		$this->assertNull($this->windowService->getForUser('admin'));
		$this->assertNotNull($this->windowService->getForUser('jan'));
	}
}
