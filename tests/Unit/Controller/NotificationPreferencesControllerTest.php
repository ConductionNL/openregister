<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\NotificationPreferencesController;
use OCA\OpenRegister\Service\Notification\NotificationPreferenceService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the override-only preferences REST surface: effective-GET, single-pair
 * override-PUT, clear-on-reset, validation, and authentication scoping.
 */
class NotificationPreferencesControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private NotificationPreferenceService&MockObject $preferenceService;
	private IUserSession&MockObject $userSession;
	private NotificationPreferencesController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->preferenceService = $this->createMock(NotificationPreferenceService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->controller = new NotificationPreferencesController(
			'openregister',
			$this->request,
			$this->preferenceService,
			$this->userSession
		);
	}

	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testIndexReturnsEffectivePreferences(): void {
		$this->signIn('jan');
		$entries = [
			['schema' => 'meldingen', 'notification' => 'object_created', 'enabled' => true, 'source' => 'schema-default'],
		];
		$this->preferenceService->expects($this->once())
			->method('getEffectiveForUser')
			->with('jan')
			->willReturn($entries);

		$response = $this->controller->index();
		$data = $response->getData();

		$this->assertSame($entries, $data['results']);
		$this->assertSame(1, $data['total']);
	}

	public function testIndexRequiresAuthentication(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->index();
		$this->assertSame(401, $response->getStatus());
	}

	public function testUpdateWritesOverride(): void {
		$this->signIn('jan');
		$this->request->method('getParams')->willReturn([
			'schema' => 'meldingen',
			'notification' => 'object_created',
			'enabled' => false,
		]);

		$this->preferenceService->expects($this->once())
			->method('setOverride')
			->with('jan', 'meldingen', 'object_created', ['enabled' => false]);
		$this->preferenceService->method('getOverride')->willReturn(['enabled' => false]);

		$response = $this->controller->update();
		$data = $response->getData();

		$this->assertSame('meldingen', $data['schema']);
		$this->assertSame(['enabled' => false], $data['override']);
	}

	public function testUpdateClearsOverrideOnReset(): void {
		$this->signIn('jan');
		$this->request->method('getParams')->willReturn([
			'schema' => 'meldingen',
			'notification' => 'object_created',
			'reset' => true,
		]);

		$this->preferenceService->expects($this->once())
			->method('setOverride')
			->with('jan', 'meldingen', 'object_created', null);

		$response = $this->controller->update();
		$this->assertNull($response->getData()['override']);
	}

	public function testUpdateRejectsMissingFields(): void {
		$this->signIn('jan');
		$this->request->method('getParams')->willReturn(['schema' => 'meldingen']);
		$this->preferenceService->expects($this->never())->method('setOverride');

		$response = $this->controller->update();
		$this->assertSame(422, $response->getStatus());
	}
}
