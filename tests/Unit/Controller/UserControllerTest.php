<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\UserController;
use OCA\OpenRegister\Service\SecurityService;
use OCA\OpenRegister\Service\UserService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserControllerTest extends TestCase {
	/**
	 * The controller under test.
	 *
	 * @var UserController
	 */
	private UserController $controller;

	/**
	 * The mocked HTTP request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The mocked user service.
	 *
	 * @var UserService&MockObject
	 */
	private UserService&MockObject $userService;

	/**
	 * The mocked security-header service.
	 *
	 * @var SecurityService&MockObject
	 */
	private SecurityService&MockObject $securityService;

	/**
	 * The mocked user manager.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $userManager;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The mocked translation layer.
	 *
	 * @var IL10N&MockObject
	 */
	private IL10N&MockObject $l10n;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->userService = $this->createMock(UserService::class);
		$this->securityService = $this->createMock(SecurityService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);

		$this->securityService->method('addSecurityHeaders')->willReturnArgument(0);

		$this->controller = new UserController(
			'openregister',
			$this->request,
			$this->userService,
			$this->securityService,
			$this->userManager,
			$this->userSession,
			$this->logger,
			$this->l10n
		);
	}

	public function testMeSuccess(): void {
		$user = $this->createMock(IUser::class);
		$userData = ['uid' => 'testuser', 'displayName' => 'Test User'];

		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->userService->method('buildUserDataArray')->willReturn($userData);

		$result = $this->controller->me();

		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals($userData, $result->getData());
	}

	public function testMeNotAuthenticated(): void {
		$this->userService->method('getCurrentUser')->willReturn(null);

		$result = $this->controller->me();

		$this->assertEquals(401, $result->getStatus());
		$this->assertEquals('Not authenticated', $result->getData()['error']);
	}

	public function testMeException(): void {
		$this->userService->method('getCurrentUser')
			->willThrowException(new \Exception('DB error'));

		$result = $this->controller->me();

		$this->assertEquals(500, $result->getStatus());
		$this->assertEquals('Failed to retrieve user profile', $result->getData()['error']);
	}

	public function testUpdateMeSuccess(): void {
		$user = $this->createMock(IUser::class);
		$updatedProfile = ['uid' => 'testuser', 'displayName' => 'Updated'];

		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->request->method('getParams')->willReturn(['displayName' => 'Updated']);
		$this->securityService->method('sanitizeInput')->willReturnArgument(0);
		$this->userService->method('updateUserProperties')->willReturn($updatedProfile);

		$result = $this->controller->updateMe();

		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals($updatedProfile, $result->getData());
	}

	public function testUpdateMeNotAuthenticated(): void {
		$this->userService->method('getCurrentUser')->willReturn(null);

		$result = $this->controller->updateMe();

		$this->assertEquals(401, $result->getStatus());
	}

	public function testUpdateMeStripsInternalAndImmutableFields(): void {
		$user = $this->createMock(IUser::class);

		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'_route' => 'test',
			'id' => '123',
			'uid' => 'admin',
			'created' => '2024-01-01',
			'displayName' => 'Valid',
		]);
		$this->securityService->method('sanitizeInput')->willReturnArgument(0);

		$this->userService
			->expects($this->once())
			->method('updateUserProperties')
			->with($this->anything(), $this->callback(function ($data) {
				return isset($data['displayName'])
					&& !isset($data['_route'])
					&& !isset($data['id'])
					&& !isset($data['uid'])
					&& !isset($data['created']);
			}))
			->willReturn(['displayName' => 'Valid']);

		$this->controller->updateMe();
	}

	public function testLogoutSuccess(): void {
		$this->userSession->expects($this->once())->method('logout');

		$response = $this->createMock(JSONResponse::class);
		$this->securityService->method('addSecurityHeaders')->willReturnArgument(0);

		$result = $this->controller->logout();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertTrue($result->getData()['logout']);
	}

	public function testLoginInvalidCredentials(): void {
		$this->securityService->method('getClientIpAddress')->willReturn('127.0.0.1');
		$this->securityService->method('addSecurityHeaders')->willReturnArgument(0);
		$this->request->method('getParams')->willReturn([
			'username' => 'testuser',
			'password' => 'wrongpass',
		]);
		$this->securityService->method('validateLoginCredentials')->willReturn([
			'valid' => true,
			'credentials' => ['username' => 'testuser', 'password' => 'wrongpass'],
		]);
		$this->securityService->method('checkLoginRateLimit')->willReturn(['allowed' => true]);
		$this->userManager->method('checkPassword')->willReturn(false);

		$result = $this->controller->login();

		$this->assertEquals(401, $result->getStatus());
		$this->assertEquals('Invalid username or password', $result->getData()['error']);
	}

	public function testLoginValidationFails(): void {
		$this->securityService->method('getClientIpAddress')->willReturn('127.0.0.1');
		$this->securityService->method('addSecurityHeaders')->willReturnArgument(0);
		$this->request->method('getParams')->willReturn([]);
		$this->securityService->method('validateLoginCredentials')->willReturn([
			'valid' => false,
			'error' => 'Username is required',
		]);

		$result = $this->controller->login();

		$this->assertEquals(400, $result->getStatus());
	}

	public function testLoginRateLimited(): void {
		$this->securityService->method('getClientIpAddress')->willReturn('127.0.0.1');
		$this->securityService->method('addSecurityHeaders')->willReturnArgument(0);
		$this->request->method('getParams')->willReturn([
			'username' => 'testuser',
			'password' => 'pass',
		]);
		$this->securityService->method('validateLoginCredentials')->willReturn([
			'valid' => true,
			'credentials' => ['username' => 'testuser', 'password' => 'pass'],
		]);
		$this->securityService->method('checkLoginRateLimit')->willReturn([
			'allowed' => false,
			'reason' => 'Too many attempts',
		]);

		$result = $this->controller->login();

		$this->assertEquals(429, $result->getStatus());
	}

	public function testLoginDisabledAccount(): void {
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(false);

		$this->securityService->method('getClientIpAddress')->willReturn('127.0.0.1');
		$this->securityService->method('addSecurityHeaders')->willReturnArgument(0);
		$this->request->method('getParams')->willReturn([
			'username' => 'testuser',
			'password' => 'pass',
		]);
		$this->securityService->method('validateLoginCredentials')->willReturn([
			'valid' => true,
			'credentials' => ['username' => 'testuser', 'password' => 'pass'],
		]);
		$this->securityService->method('checkLoginRateLimit')->willReturn(['allowed' => true]);
		$this->userManager->method('checkPassword')->willReturn($user);

		$result = $this->controller->login();

		$this->assertEquals(401, $result->getStatus());
		$this->assertEquals('Account is disabled', $result->getData()['error']);
	}

	public function testLoginSuccess(): void {
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$user->method('getUID')->willReturn('testuser');

		$this->securityService->method('getClientIpAddress')->willReturn('127.0.0.1');
		$this->securityService->method('addSecurityHeaders')->willReturnArgument(0);
		$this->request->method('getParams')->willReturn([
			'username' => 'testuser',
			'password' => 'correctpass',
		]);
		$this->securityService->method('validateLoginCredentials')->willReturn([
			'valid' => true,
			'credentials' => ['username' => 'testuser', 'password' => 'correctpass'],
		]);
		$this->securityService->method('checkLoginRateLimit')->willReturn(['allowed' => true]);
		$this->userManager->method('checkPassword')->willReturn($user);
		$this->userService->method('buildUserDataArray')->willReturn(['uid' => 'testuser']);

		$result = $this->controller->login();

		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals('Login successful', $result->getData()['message']);
		$this->assertTrue($result->getData()['session_created']);
	}

	// ── updateMe() exception path ──

	/**
	 * A failure while updating the profile must become a 500.
	 *
	 * @return void
	 */
	public function testUpdateMeException(): void {
		$user = $this->createMock(IUser::class);

		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->request->method('getParams')->willReturn(['displayName' => 'Test']);
		$this->securityService->method('sanitizeInput')->willReturnArgument(0);
		$this->userService->method('updateUserProperties')
			->willThrowException(new \Exception('Update failed'));

		$result = $this->controller->updateMe();

		$this->assertEquals(500, $result->getStatus());
		$this->assertEquals('Update failed', $result->getData()['error']);
	}

	// ── login() — rate limit with delay ──

	/**
	 * A throttled login must be delayed and refused.
	 *
	 * @return void
	 */
	public function testLoginRateLimitedWithDelay(): void {
		$this->securityService->method('getClientIpAddress')->willReturn('127.0.0.1');
		$this->securityService->method('addSecurityHeaders')->willReturnArgument(0);
		$this->request->method('getParams')->willReturn([
			'username' => 'testuser',
			'password' => 'pass',
		]);
		$this->securityService->method('validateLoginCredentials')->willReturn([
			'valid' => true,
			'credentials' => ['username' => 'testuser', 'password' => 'pass'],
		]);
		$this->securityService->method('checkLoginRateLimit')->willReturn([
			'allowed' => false,
			'reason' => 'Too many attempts',
			'delay' => 0,
			'lockout_until' => null,
		]);

		$result = $this->controller->login();

		$this->assertEquals(429, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals('Too many attempts', $data['error']);
	}

	// ── login() — exception path ──

	/**
	 * An unexpected failure during login must become a 500.
	 *
	 * @return void
	 */
	public function testLoginException(): void {
		$this->securityService->method('getClientIpAddress')->willReturn('127.0.0.1');
		$this->securityService->method('addSecurityHeaders')->willReturnArgument(0);
		$this->request->method('getParams')->willReturn(['username' => 'testuser', 'password' => 'pass']);
		$this->securityService->method('validateLoginCredentials')
			->willThrowException(new \Exception('Unexpected error'));

		$result = $this->controller->login();

		$this->assertEquals(500, $result->getStatus());
		$this->assertEquals('Login failed due to a system error', $result->getData()['error']);
	}

	// ── login() — failed auth records attempt ──

	/**
	 * Invalid credentials must be recorded so the throttle can see them.
	 *
	 * @return void
	 */
	public function testLoginRecordsFailedAttemptOnInvalidCredentials(): void {
		$this->securityService->method('getClientIpAddress')->willReturn('127.0.0.1');
		$this->securityService->method('addSecurityHeaders')->willReturnArgument(0);
		$this->request->method('getParams')->willReturn(['username' => 'testuser', 'password' => 'wrong']);
		$this->securityService->method('validateLoginCredentials')->willReturn([
			'valid' => true,
			'credentials' => ['username' => 'testuser', 'password' => 'wrong'],
		]);
		$this->securityService->method('checkLoginRateLimit')->willReturn(['allowed' => true]);
		$this->userManager->method('checkPassword')->willReturn(false);

		$this->securityService->expects($this->once())
			->method('recordFailedLoginAttempt')
			->with('testuser', '127.0.0.1', 'invalid_credentials');

		$result = $this->controller->login();

		$this->assertEquals(401, $result->getStatus());
	}

	// ── login() — disabled account records attempt ──

	/**
	 * A disabled account is a failed attempt too, and must be recorded.
	 *
	 * @return void
	 */
	public function testLoginRecordsFailedAttemptForDisabledAccount(): void {
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(false);
		$user->method('getUID')->willReturn('testuser');

		$this->securityService->method('getClientIpAddress')->willReturn('10.0.0.1');
		$this->securityService->method('addSecurityHeaders')->willReturnArgument(0);
		$this->request->method('getParams')->willReturn(['username' => 'testuser', 'password' => 'pass']);
		$this->securityService->method('validateLoginCredentials')->willReturn([
			'valid' => true,
			'credentials' => ['username' => 'testuser', 'password' => 'pass'],
		]);
		$this->securityService->method('checkLoginRateLimit')->willReturn(['allowed' => true]);
		$this->userManager->method('checkPassword')->willReturn($user);

		$this->securityService->expects($this->once())
			->method('recordFailedLoginAttempt')
			->with('testuser', '10.0.0.1', 'account_disabled');

		$result = $this->controller->login();

		$this->assertEquals(401, $result->getStatus());
	}

	// ── login() — success records successful login ──

	/**
	 * A successful login must clear the failure counter.
	 *
	 * @return void
	 */
	public function testLoginSuccessCallsRecordSuccessfulLogin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$user->method('getUID')->willReturn('testuser');

		$this->securityService->method('getClientIpAddress')->willReturn('192.168.1.1');
		$this->securityService->method('addSecurityHeaders')->willReturnArgument(0);
		$this->request->method('getParams')->willReturn(['username' => 'testuser', 'password' => 'pass']);
		$this->securityService->method('validateLoginCredentials')->willReturn([
			'valid' => true,
			'credentials' => ['username' => 'testuser', 'password' => 'pass'],
		]);
		$this->securityService->method('checkLoginRateLimit')->willReturn(['allowed' => true]);
		$this->userManager->method('checkPassword')->willReturn($user);
		$this->userService->method('buildUserDataArray')->willReturn(['uid' => 'testuser']);

		$this->securityService->expects($this->once())
			->method('recordSuccessfulLogin')
			->with('testuser', '192.168.1.1');

		$result = $this->controller->login();

		$this->assertEquals(200, $result->getStatus());
	}

	// ── Profile Action: changePassword() ──

	/**
	 * Changing a password requires a session.
	 *
	 * @return void
	 */
	public function testChangePasswordNotAuthenticated(): void {
		$this->userService->method('getCurrentUser')->willReturn(null);
		$result = $this->controller->changePassword();
		$this->assertEquals(401, $result->getStatus());
	}

	public function testChangePasswordSuccess(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->securityService->method('getClientIpAddress')->willReturn('127.0.0.1');
		$this->securityService->method('checkLoginRateLimit')->willReturn(['allowed' => true]);
		$this->securityService->method('sanitizeInput')->willReturnArgument(0);
		$this->request->method('getParams')->willReturn([
			'currentPassword' => 'OldPass1234!',
			'newPassword' => 'NewSecure2026!',
		]);
		$this->userService->method('changePassword')->willReturn([
			'success' => true,
			'message' => 'Password updated successfully',
		]);

		$result = $this->controller->changePassword();
		$this->assertEquals(200, $result->getStatus());
		$this->assertTrue($result->getData()['success']);
	}

	public function testChangePasswordIncorrectCurrent(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->securityService->method('getClientIpAddress')->willReturn('127.0.0.1');
		$this->securityService->method('checkLoginRateLimit')->willReturn(['allowed' => true]);
		$this->securityService->method('sanitizeInput')->willReturnArgument(0);
		$this->request->method('getParams')->willReturn([
			'currentPassword' => 'wrong',
			'newPassword' => 'NewSecure2026!',
		]);
		$this->userService->method('changePassword')
			->willThrowException(new \RuntimeException('Current password is incorrect', 403));

		$result = $this->controller->changePassword();
		$this->assertEquals(403, $result->getStatus());
	}

	public function testChangePasswordRateLimited(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->securityService->method('getClientIpAddress')->willReturn('127.0.0.1');
		$this->securityService->method('checkLoginRateLimit')->willReturn([
			'allowed' => false,
			'reason' => 'Too many attempts',
			'delay' => 60,
		]);

		$result = $this->controller->changePassword();
		$this->assertEquals(429, $result->getStatus());
	}

	// ── Profile Action: Notification Preferences ──

	/**
	 * Reading notification preferences requires a session.
	 *
	 * @return void
	 */
	public function testGetNotificationPreferencesNotAuthenticated(): void {
		$this->userService->method('getCurrentUser')->willReturn(null);
		$result = $this->controller->getNotificationPreferences();
		$this->assertEquals(401, $result->getStatus());
	}

	public function testGetNotificationPreferencesSuccess(): void {
		$user = $this->createMock(IUser::class);
		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->userService->method('getNotificationPreferences')->willReturn([
			'objectChanges' => true,
			'emailDigest' => 'daily',
		]);

		$result = $this->controller->getNotificationPreferences();
		$this->assertEquals(200, $result->getStatus());
		$this->assertTrue($result->getData()['objectChanges']);
	}

	public function testUpdateNotificationPreferencesSuccess(): void {
		$user = $this->createMock(IUser::class);
		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->request->method('getParams')->willReturn(['objectChanges' => false]);
		$this->userService->method('setNotificationPreferences')->willReturn([
			'objectChanges' => false,
			'emailDigest' => 'daily',
		]);

		$result = $this->controller->updateNotificationPreferences();
		$this->assertEquals(200, $result->getStatus());
		$this->assertFalse($result->getData()['objectChanges']);
	}

	public function testUpdateNotificationPreferencesInvalidDigest(): void {
		$user = $this->createMock(IUser::class);
		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->request->method('getParams')->willReturn(['emailDigest' => 'hourly']);
		$this->userService->method('setNotificationPreferences')
			->willThrowException(new \InvalidArgumentException('Invalid emailDigest value. Allowed: none, daily, weekly'));

		$result = $this->controller->updateNotificationPreferences();
		$this->assertEquals(400, $result->getStatus());
	}

	// ── Profile Action: Activity ──

	/**
	 * Reading the activity feed requires a session.
	 *
	 * @return void
	 */
	public function testGetActivityNotAuthenticated(): void {
		$this->userService->method('getCurrentUser')->willReturn(null);
		$result = $this->controller->getActivity();
		$this->assertEquals(401, $result->getStatus());
	}

	public function testGetActivitySuccess(): void {
		$user = $this->createMock(IUser::class);
		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->request->method('getParam')
			->willReturnMap([
				['_limit', '25', '25'],
				['_offset', '0', '0'],
				['type', null, null],
				['_from', null, null],
				['_to', null, null],
			]);
		$this->userService->method('getUserActivity')->willReturn([
			'results' => [['id' => 1, 'type' => 'create']],
			'total' => 1,
		]);

		$result = $this->controller->getActivity();
		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals(1, $result->getData()['total']);
	}

	// ── Profile Action: Tokens ──

	/**
	 * Listing app tokens requires a session.
	 *
	 * @return void
	 */
	public function testListTokensNotAuthenticated(): void {
		$this->userService->method('getCurrentUser')->willReturn(null);
		$result = $this->controller->listTokens();
		$this->assertEquals(401, $result->getStatus());
	}

	public function testListTokensSuccess(): void {
		$user = $this->createMock(IUser::class);
		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->userService->method('listApiTokens')->willReturn([
			['id' => 'abc', 'name' => 'CI', 'preview' => '****1234'],
		]);

		$result = $this->controller->listTokens();
		$this->assertEquals(200, $result->getStatus());
		$this->assertCount(1, $result->getData());
	}

	public function testCreateTokenSuccess(): void {
		$user = $this->createMock(IUser::class);
		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->securityService->method('sanitizeInput')->willReturnArgument(0);
		$this->request->method('getParams')->willReturn(['name' => 'CI Pipeline']);
		$this->userService->method('createApiToken')->willReturn([
			'id' => 'abc',
			'name' => 'CI Pipeline',
			'token' => 'full-token-value',
		]);

		$result = $this->controller->createToken();
		$this->assertEquals(201, $result->getStatus());
		$this->assertEquals('CI Pipeline', $result->getData()['name']);
	}

	public function testCreateTokenMissingName(): void {
		$user = $this->createMock(IUser::class);
		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->securityService->method('sanitizeInput')->willReturn('');
		$this->request->method('getParams')->willReturn([]);

		$result = $this->controller->createToken();
		$this->assertEquals(400, $result->getStatus());
	}

	public function testRevokeTokenSuccess(): void {
		$user = $this->createMock(IUser::class);
		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->userService->method('revokeApiToken')->willReturn([
			'success' => true,
			'message' => 'Token revoked',
		]);

		$result = $this->controller->revokeToken('abc123');
		$this->assertEquals(200, $result->getStatus());
		$this->assertTrue($result->getData()['success']);
	}

	public function testRevokeTokenNotFound(): void {
		$user = $this->createMock(IUser::class);
		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->userService->method('revokeApiToken')
			->willThrowException(new \RuntimeException('Token not found', 404));

		$result = $this->controller->revokeToken('nonexistent');
		$this->assertEquals(404, $result->getStatus());
	}

	// ── Profile Action: Deactivation ──

	/**
	 * Requesting account deactivation requires a session.
	 *
	 * @return void
	 */
	public function testRequestDeactivationNotAuthenticated(): void {
		$this->userService->method('getCurrentUser')->willReturn(null);
		$result = $this->controller->requestDeactivation();
		$this->assertEquals(401, $result->getStatus());
	}

	public function testRequestDeactivationSuccess(): void {
		$user = $this->createMock(IUser::class);
		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->securityService->method('sanitizeInput')->willReturn('Leaving');
		$this->request->method('getParams')->willReturn(['reason' => 'Leaving']);
		$this->userService->method('requestDeactivation')->willReturn([
			'success' => true,
			'status' => 'pending',
		]);

		$result = $this->controller->requestDeactivation();
		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals('pending', $result->getData()['status']);
	}

	public function testGetDeactivationStatusSuccess(): void {
		$user = $this->createMock(IUser::class);
		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->userService->method('getDeactivationStatus')->willReturn([
			'status' => 'active',
			'pendingRequest' => null,
		]);

		$result = $this->controller->getDeactivationStatus();
		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals('active', $result->getData()['status']);
	}

	public function testCancelDeactivationSuccess(): void {
		$user = $this->createMock(IUser::class);
		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->userService->method('cancelDeactivation')->willReturn([
			'success' => true,
			'status' => 'active',
		]);

		$result = $this->controller->cancelDeactivation();
		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals('active', $result->getData()['status']);
	}

	public function testCancelDeactivationNoPending(): void {
		$user = $this->createMock(IUser::class);
		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->userService->method('cancelDeactivation')
			->willThrowException(new \RuntimeException('No pending deactivation request', 404));

		$result = $this->controller->cancelDeactivation();
		$this->assertEquals(404, $result->getStatus());
	}

	// ── GET /api/user/me/export — GDPR personal-data export ────────────────

	/**
	 * The export is a FILE download, not a JSON envelope: the browser must be
	 * handed an attachment whose name carries the exporting uid and the export
	 * date, and whose body is the pretty-printed personal-data document.
	 *
	 * @return void
	 */
	public function testExportDataReturnsADownloadableJsonDocument(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$exportData = [
			'profile' => ['uid' => 'testuser', 'displayName' => 'Test User'],
			'objects' => [],
		];

		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->userService
			->expects($this->once())
			->method('exportPersonalData')
			->with($user)
			->willReturn($exportData);

		$result = $this->controller->exportData();

		$this->assertInstanceOf(DataDownloadResponse::class, $result);
		$this->assertEquals(200, $result->getStatus());
		$this->assertSame($exportData, json_decode($result->render(), true));
		$this->assertStringContainsString(
			'openregister-export-testuser-' . date('Y-m-d') . '.json',
			$result->getHeaders()['Content-Disposition']
		);
	}

	/**
	 * Unicode must survive the export unescaped — an export that mangles a
	 * name is not a lawful copy of the subject's data.
	 *
	 * @return void
	 */
	public function testExportDataPreservesUnicodeUnescaped(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->userService->method('exportPersonalData')
			->willReturn(['displayName' => 'Renée Müller']);

		$result = $this->controller->exportData();

		$this->assertStringContainsString('Renée Müller', $result->render());
	}

	/**
	 * No session, no export — the endpoint must never fall back to exporting
	 * "some" user's data.
	 *
	 * @return void
	 */
	public function testExportDataRejectsAnonymousSession(): void {
		$this->userService->method('getCurrentUser')->willReturn(null);
		$this->userService->expects($this->never())->method('exportPersonalData');

		$result = $this->controller->exportData();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertEquals(401, $result->getStatus());
		$this->assertEquals('Not authenticated', $result->getData()['error']);
	}

	/**
	 * The export is rate-limited. A throttled caller must get a 429 carrying
	 * the service's own structured payload, not a flattened 500.
	 *
	 * @return void
	 */
	public function testExportDataSurfacesTheThrottlePayloadAs429(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->userService->method('exportPersonalData')
			->willThrowException(
				new \RuntimeException(json_encode(['error' => 'Too many exports', 'retryAfter' => 3600]), 429)
			);

		$result = $this->controller->exportData();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertEquals(429, $result->getStatus());
		$this->assertEquals(3600, $result->getData()['retryAfter']);
	}

	public function testExportDataMapsARuntimeExceptionCodeToTheStatus(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->userService->method('exportPersonalData')
			->willThrowException(new \RuntimeException('Export not permitted', 403));

		$result = $this->controller->exportData();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertEquals(403, $result->getStatus());
	}

	/**
	 * An unexpected failure must become a generic 500 — the raw exception text
	 * can name internal storage paths and must not reach the wire.
	 *
	 * @return void
	 */
	public function testExportDataHidesUnexpectedFailuresBehindA500(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->userService->method('exportPersonalData')
			->willThrowException(new \Exception('/var/www/html/data/testuser is unreadable'));

		$result = $this->controller->exportData();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertEquals(500, $result->getStatus());
		$this->assertEquals('Failed to export data', $result->getData()['error']);
		$this->assertStringNotContainsString('/var/www', $result->getData()['error']);
	}
}
