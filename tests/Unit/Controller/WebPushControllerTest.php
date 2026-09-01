<?php

/**
 * Contract tests for WebPushController.
 *
 * Covers the five publicly-registered Web Push endpoints:
 *  - GET    /webpush/vapid-public-key  → vapidPublicKey
 *  - POST   /webpush/subscription      → subscribe
 *  - DELETE /webpush/subscription      → unsubscribe
 *  - GET    /webpush/icon/{app}        → hexIcon
 *  - GET    /webpush/badge/{app}       → hexBadge
 *
 * The subscription endpoints are IDOR-sensitive: they must bind every write
 * and delete to the SESSION uid and never to a uid taken from the request.
 * Those two tests assert the uid handed to the mapper, not just the status.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\WebPushController;
use OCA\OpenRegister\Db\PushSubscription;
use OCA\OpenRegister\Db\PushSubscriptionMapper;
use OCA\OpenRegister\Service\WebPush\HexIconService;
use OCA\OpenRegister\Service\WebPush\WebPushService;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WebPushControllerTest extends TestCase {
	/**
	 * The controller under test.
	 *
	 * @var WebPushController
	 */
	private WebPushController $controller;

	/**
	 * The mocked HTTP request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The mocked VAPID key + delivery service.
	 *
	 * @var WebPushService&MockObject
	 */
	private WebPushService&MockObject $webPushService;

	/**
	 * The mocked subscription store.
	 *
	 * @var PushSubscriptionMapper&MockObject
	 */
	private PushSubscriptionMapper&MockObject $subscriptionMapper;

	/**
	 * The mocked hex-icon raster service.
	 *
	 * @var HexIconService&MockObject
	 */
	private HexIconService&MockObject $hexIconService;

	/**
	 * The mocked user session that owns every subscription write.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->webPushService = $this->createMock(WebPushService::class);
		$this->subscriptionMapper = $this->createMock(PushSubscriptionMapper::class);
		$this->hexIconService = $this->createMock(HexIconService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->request->method('getHeader')->willReturn('Mozilla/5.0 (Test)');

		$this->controller = new WebPushController(
			'openregister',
			$this->request,
			$this->webPushService,
			$this->subscriptionMapper,
			$this->hexIconService,
			$this->userSession
		);
	}

	/**
	 * Bind the session to a named user id.
	 *
	 * @param string $uid The uid the session should report.
	 *
	 * @return void
	 */
	private function loginAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testVapidPublicKeyReturnsKeyAndConfiguredFlag(): void {
		$this->webPushService->method('getPublicKey')->willReturn('BPublicKeyBytes');
		$this->webPushService->method('isConfigured')->willReturn(true);

		$result = $this->controller->vapidPublicKey();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(200, $result->getStatus());
		$this->assertSame(
			['publicKey' => 'BPublicKeyBytes', 'configured' => true],
			$result->getData()
		);
	}

	/**
	 * An unconfigured instance must still answer 200 with `configured: false`
	 * rather than an error — the browser uses the flag to skip subscribing.
	 * The response must never carry a private-key field.
	 *
	 * @return void
	 */
	public function testVapidPublicKeyReportsUnconfiguredWithoutLeakingPrivateKey(): void {
		$this->webPushService->method('getPublicKey')->willReturn('');
		$this->webPushService->method('isConfigured')->willReturn(false);

		$result = $this->controller->vapidPublicKey();

		$this->assertSame(200, $result->getStatus());
		$this->assertFalse($result->getData()['configured']);
		$this->assertSame(['publicKey', 'configured'], array_keys($result->getData()));
	}

	public function testSubscribeRejectsAnonymousSession(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->subscriptionMapper->expects($this->never())->method('store');

		$result = $this->controller->subscribe(
			'https://push.example/endpoint',
			['p256dh' => 'key', 'auth' => 'secret']
		);

		$this->assertSame(401, $result->getStatus());
		$this->assertSame('No active user session', $result->getData()['error']);
	}

	public function testSubscribeRejectsMissingEndpoint(): void {
		$this->loginAs('alice');
		$this->subscriptionMapper->expects($this->never())->method('store');

		$result = $this->controller->subscribe('', ['p256dh' => 'key', 'auth' => 'secret']);

		$this->assertSame(400, $result->getStatus());
		$this->assertStringContainsString('endpoint', $result->getData()['error']);
	}

	public function testSubscribeRejectsMissingClientKeys(): void {
		$this->loginAs('alice');
		$this->subscriptionMapper->expects($this->never())->method('store');

		$result = $this->controller->subscribe('https://push.example/endpoint', []);

		$this->assertSame(400, $result->getStatus());
	}

	/**
	 * Happy path AND the IDOR guard: the stored owner is the session uid, so a
	 * caller cannot register a subscription under somebody else's account.
	 *
	 * @return void
	 */
	public function testSubscribeStoresSubscriptionOwnedBySessionUser(): void {
		$this->loginAs('alice');

		$stored = new PushSubscription();
		$stored->setId(42);
		$stored->setEndpoint('https://push.example/endpoint');
		$stored->setUserAgent('Mozilla/5.0 (Test)');

		$this->subscriptionMapper
			->expects($this->once())
			->method('store')
			->with('alice', 'https://push.example/endpoint', 'p256dh-key', 'auth-secret', 'Mozilla/5.0 (Test)')
			->willReturn($stored);

		$result = $this->controller->subscribe(
			'https://push.example/endpoint',
			['p256dh' => 'p256dh-key', 'auth' => 'auth-secret']
		);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(201, $result->getStatus());
		$this->assertSame(42, $result->getData()['id']);
		$this->assertSame('https://push.example/endpoint', $result->getData()['endpoint']);
		// The wire payload must never echo back the owning uid or the keys.
		$this->assertArrayNotHasKey('userId', $result->getData());
		$this->assertArrayNotHasKey('p256dh', $result->getData());
	}

	public function testUnsubscribeRejectsAnonymousSession(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->subscriptionMapper->expects($this->never())->method('deleteByUserAndEndpoint');

		$result = $this->controller->unsubscribe('https://push.example/endpoint');

		$this->assertSame(401, $result->getStatus());
	}

	public function testUnsubscribeRequiresAnEndpoint(): void {
		$this->loginAs('alice');
		$this->subscriptionMapper->expects($this->never())->method('deleteByUserAndEndpoint');

		$result = $this->controller->unsubscribe('');

		$this->assertSame(400, $result->getStatus());
		$this->assertSame('endpoint is required', $result->getData()['error']);
	}

	/**
	 * The delete is keyed by the SESSION uid, never by a uid on the wire.
	 *
	 * @return void
	 */
	public function testUnsubscribeDeletesOnlyTheSessionUsersSubscription(): void {
		$this->loginAs('alice');

		$this->subscriptionMapper
			->expects($this->once())
			->method('deleteByUserAndEndpoint')
			->with('alice', 'https://push.example/endpoint')
			->willReturn(1);

		$result = $this->controller->unsubscribe('https://push.example/endpoint');

		$this->assertSame(200, $result->getStatus());
		$this->assertSame(['deleted' => 1], $result->getData());
	}

	public function testHexIconServesCachedImageBytes(): void {
		$this->hexIconService
			->expects($this->once())
			->method('getIcon')
			->with('opencatalogi')
			->willReturn(['body' => 'PNGBYTES', 'mime' => 'image/png']);

		$result = $this->controller->hexIcon('opencatalogi');

		$this->assertInstanceOf(DataDisplayResponse::class, $result);
		$this->assertSame(200, $result->getStatus());
		$this->assertSame('PNGBYTES', $result->getData());
		$this->assertSame('image/png', $result->getHeaders()['Content-Type']);
		$this->assertStringContainsString('max-age=86400', $result->getHeaders()['Cache-Control']);
	}

	public function testHexBadgeServesCachedImageBytes(): void {
		$this->hexIconService
			->expects($this->once())
			->method('getBadge')
			->with('openregister')
			->willReturn(['body' => 'BADGEBYTES', 'mime' => 'image/png']);

		$result = $this->controller->hexBadge('openregister');

		$this->assertInstanceOf(DataDisplayResponse::class, $result);
		$this->assertSame(200, $result->getStatus());
		$this->assertSame('BADGEBYTES', $result->getData());
		$this->assertSame('image/png', $result->getHeaders()['Content-Type']);
		$this->assertStringContainsString('max-age=86400', $result->getHeaders()['Cache-Control']);
	}
}
