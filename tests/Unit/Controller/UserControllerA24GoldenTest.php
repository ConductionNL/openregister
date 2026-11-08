<?php

/**
 * Golden outcomes of the user API.
 *
 * Pins, for every UserController endpoint, the allowed and the refused outcome
 * (status, body, whether the security headers were applied) plus the side
 * effects the authentication paths record, so a restructuring of the
 * controller cannot silently change what is allowed or refused.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Controller\UserController;
use OCA\OpenRegister\Service\SecurityService;
use OCA\OpenRegister\Service\UserService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\UserControllerFactory;
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
use ReflectionMethod;
use RuntimeException;

/**
 * Golden allowed/refused outcomes of UserController.
 */
class UserControllerA24GoldenTest extends TestCase {
	private const SECURED = 'X-A24-Secured';

	/** @var UserController */
	private UserController $controller;

	/** @var IRequest&MockObject */
	private IRequest&MockObject $request;

	/** @var UserService&MockObject */
	private UserService&MockObject $userService;

	/** @var SecurityService&MockObject */
	private SecurityService&MockObject $securityService;

	/** @var IUserManager&MockObject */
	private IUserManager&MockObject $userManager;

	/** @var IUserSession&MockObject */
	private IUserSession&MockObject $userSession;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface&MockObject $logger;

	/**
	 * Logged error calls: [message, context].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $errors = [];

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userService = $this->createMock(UserService::class);
		$this->securityService = $this->createMock(SecurityService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, $context];
			}
		);
		$this->securityService->method('addSecurityHeaders')->willReturnCallback(
			static fn (JSONResponse $response): JSONResponse => $response->addHeader(self::SECURED, '1')
		);
		$this->securityService->method('sanitizeInput')->willReturnCallback([self::class, 'sanitized']);
		$this->securityService->method('getClientIpAddress')->willReturn('10.0.0.1');

		$this->controller = UserControllerFactory::create(
			'openregister',
			$this->request,
			$this->userService,
			$this->securityService,
			$this->userManager,
			$this->userSession,
			$this->logger,
			$this->createMock(IL10N::class)
		);
	}

	/**
	 * The stand-in sanitizer: marks non-empty strings so the tests see what was sanitized.
	 *
	 * @param mixed $input The raw input.
	 *
	 * @return mixed The marked input.
	 */
	public static function sanitized(mixed $input): mixed {
		if (is_string($input) === false || $input === '') {
			return $input;
		}

		return 'clean:' . $input;
	}

	private function user(string $uid = 'alice', bool $enabled = true): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}

	private function signedIn(): IUser&MockObject {
		$user = $this->user();
		$this->userService->method('getCurrentUser')->willReturn($user);
		return $user;
	}

	private function assertOutcome(int $status, mixed $body, bool $secured, mixed $response): void {
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame($status, $response->getStatus());
		$this->assertSame($body, $response->getData());
		$this->assertSame($secured, isset($response->getHeaders()[self::SECURED]));
	}

	private function assertLoggedError(string $message, string $error, int $code): void {
		$this->assertCount(1, $this->errors);
		$this->assertSame($message, $this->errors[0][0]);
		$context = $this->errors[0][1];
		$this->assertSame(['file', 'line', 'error_message', 'error_code'], array_keys($context));
		$this->assertStringEndsWith('UserController.php', $context['file']);
		$this->assertSame($error, $context['error_message']);
		$this->assertSame($code, $context['error_code']);
	}

	/**
	 * Every session-guarded endpoint with the secured error shape.
	 *
	 * @return array<string, array{0: string, 1: array}>
	 */
	public static function guardedEndpoints(): array {
		return [
			'changePassword'                => ['changePassword', []],
			'uploadAvatar'                  => ['uploadAvatar', []],
			'deleteAvatar'                  => ['deleteAvatar', []],
			'exportData'                    => ['exportData', []],
			'getNotificationPreferences'    => ['getNotificationPreferences', []],
			'updateNotificationPreferences' => ['updateNotificationPreferences', []],
			'getActivity'                   => ['getActivity', []],
			'listTokens'                    => ['listTokens', []],
			'createToken'                   => ['createToken', []],
			'revokeToken'                   => ['revokeToken', ['t1']],
			'requestDeactivation'           => ['requestDeactivation', []],
			'getDeactivationStatus'         => ['getDeactivationStatus', []],
			'cancelDeactivation'            => ['cancelDeactivation', []],
		];
	}

	/**
	 * Golden outcome per provided case.
	 *
	 * @param string $method The endpoint.
	 * @param array  $args   Its arguments.
	 *
	 * @return void
	 *
	 * @dataProvider guardedEndpoints
	 */
	public function testNoSessionIsRefusedWith401(string $method, array $args): void {
		$this->userService->method('getCurrentUser')->willReturn(null);
		$this->userService->expects($this->never())->method($this->logicalNot($this->equalTo('getCurrentUser')));

		$this->assertOutcome(401, ['error' => 'Not authenticated'], true, $this->controller->$method(...$args));
	}

	public function testProfileEndpointsRefuseNoSessionWithoutSecurityHeaders(): void {
		$this->userService->method('getCurrentUser')->willReturn(null);
		$this->userService->expects($this->never())->method('updateUserProperties');

		$this->assertOutcome(401, ['error' => 'Not authenticated'], false, $this->controller->me());
		$this->assertOutcome(401, ['error' => 'Not authenticated'], false, $this->controller->updateMe());
	}

	/**
	 * Unexpected failures: [method, args, log message, response message].
	 *
	 * @return array<string, array{0: string, 1: array, 2: string, 3: string}>
	 */
	public static function unexpectedFailures(): array {
		$notifications = 'notification preferences';
		return [
			'changePassword'                => ['changePassword', [], 'Failed to change password', 'Failed to change password'],
			'uploadAvatar'                  => ['uploadAvatar', [], 'Failed to upload avatar', 'Failed to upload avatar'],
			'deleteAvatar'                  => ['deleteAvatar', [], 'Failed to delete avatar', 'Failed to delete avatar'],
			'exportData'                    => ['exportData', [], 'Failed to export data', 'Failed to export data'],
			'getNotificationPreferences'    => ['getNotificationPreferences', [], 'Failed to get ' . $notifications, 'Failed to get ' . $notifications],
			'updateNotificationPreferences' => [
				'updateNotificationPreferences',
				[],
				'Failed to update ' . $notifications,
				'Failed to update ' . $notifications,
			],
			'getActivity'                   => ['getActivity', [], 'Failed to get activity', 'Failed to get activity history'],
			'listTokens'                    => ['listTokens', [], 'Failed to list tokens', 'Failed to list tokens'],
			'createToken'                   => ['createToken', [], 'Failed to create token', 'Failed to create token'],
			'revokeToken'                   => ['revokeToken', ['t1'], 'Failed to revoke token', 'Failed to revoke token'],
			'requestDeactivation'           => ['requestDeactivation', [], 'Failed to request deactivation', 'Failed to request deactivation'],
			'getDeactivationStatus'         => ['getDeactivationStatus', [], 'Failed to get deactivation status', 'Failed to get deactivation status'],
			'cancelDeactivation'            => ['cancelDeactivation', [], 'Failed to cancel deactivation', 'Failed to cancel deactivation'],
		];
	}

	/**
	 * Golden outcome per provided case.
	 *
	 * @param string $method  The endpoint.
	 * @param array  $args    Its arguments.
	 * @param string $log     The logged message.
	 * @param string $message The response message.
	 *
	 * @return void
	 *
	 * @dataProvider unexpectedFailures
	 */
	public function testUnexpectedFailureIsLoggedAndHiddenBehindA500(
		string $method,
		array $args,
		string $log,
		string $message
	): void {
		$this->userService->method('getCurrentUser')->willThrowException(new Exception('boom', 7));

		$this->assertOutcome(500, ['error' => $message], true, $this->controller->$method(...$args));
		$this->assertLoggedError('[UserController] ' . $log, 'boom', 7);
	}

	/**
	 * RuntimeException handling: [method, args, mapped?, log message, response message].
	 *
	 * @return array<string, array{0: string, 1: array, 2: bool, 3: string, 4: string}>
	 */
	public static function runtimeFailures(): array {
		$rows = [];
		$mapped = [
			'changePassword', 'uploadAvatar', 'deleteAvatar', 'exportData',
			'createToken', 'revokeToken', 'requestDeactivation', 'cancelDeactivation',
		];
		foreach (self::unexpectedFailures() as $name => [$method, $args, $log, $message]) {
			$rows[$name] = [$method, $args, in_array($method, $mapped, true), $log, $message];
		}

		return $rows;
	}

	/**
	 * Golden outcome per provided case.
	 *
	 * @param string $method  The endpoint.
	 * @param array  $args    Its arguments.
	 * @param bool   $mapped  Whether the endpoint maps the exception code.
	 * @param string $log     The logged message.
	 * @param string $message The response message.
	 *
	 * @return void
	 *
	 * @dataProvider runtimeFailures
	 */
	public function testRuntimeFailureMapsItsCodeOnlyWhereTheEndpointDoes(
		string $method,
		array $args,
		bool $mapped,
		string $log,
		string $message
	): void {
		$this->userService->method('getCurrentUser')->willThrowException(new RuntimeException('Gone away', 404));
		$response = $this->controller->$method(...$args);

		if ($mapped === true) {
			$this->assertOutcome(404, ['error' => 'Gone away'], true, $response);
			$this->assertSame([], $this->errors);
			return;
		}

		$this->assertOutcome(500, ['error' => $message], true, $response);
		$this->assertLoggedError('[UserController] ' . $log, 'Gone away', 404);
	}

	/**
	 * Golden outcome per provided case.
	 *
	 * @param string $method  The endpoint.
	 * @param array  $args    Its arguments.
	 * @param bool   $mapped  Whether the endpoint maps the exception code.
	 * @param string $log     The logged message.
	 * @param string $message The response message.
	 *
	 * @return void
	 *
	 * @dataProvider runtimeFailures
	 */
	public function testRuntimeFailureWithoutCodeBecomesA500(
		string $method,
		array $args,
		bool $mapped,
		string $log,
		string $message
	): void {
		$this->userService->method('getCurrentUser')->willThrowException(new RuntimeException('No code'));
		$response = $this->controller->$method(...$args);

		if ($mapped === true) {
			$this->assertOutcome(500, ['error' => 'No code'], true, $response);
			$this->assertSame([], $this->errors);
			return;
		}

		$this->assertOutcome(500, ['error' => $message], true, $response);
	}


	public function testMeReturnsTheProfileWithoutSecurityHeaders(): void {
		$user = $this->signedIn();
		$this->userService->method('buildUserDataArray')->with($user)->willReturn(['uid' => 'alice']);

		$this->assertOutcome(200, ['uid' => 'alice'], false, $this->controller->me());
	}

	public function testMeFailureIsLoggedAndGeneric(): void {
		$this->userService->method('getCurrentUser')->willThrowException(new RuntimeException('db down', 3));

		$this->assertOutcome(500, ['error' => 'Failed to retrieve user profile'], false, $this->controller->me());
		$this->assertLoggedError('[UserController] Failed to get user profile', 'db down', 3);
	}

	public function testUpdateMeStripsInternalAndImmutableFieldsAndSanitizes(): void {
		$user = $this->signedIn();
		$this->request->method('getParams')->willReturn(
			['_route' => 'x', 'id' => 1, 'uid' => 'u', 'created' => 'c', 'displayName' => 'Al', 'quota' => 5]
		);
		$this->userService->expects($this->once())->method('updateUserProperties')
			->with($user, ['displayName' => 'clean:Al', 'quota' => 5])->willReturn(['ok' => true]);

		$this->assertOutcome(200, ['ok' => true], false, $this->controller->updateMe());
	}

	public function testUpdateMeFailureExposesTheMessage(): void {
		$this->signedIn();
		$this->request->method('getParams')->willReturn([]);
		$this->userService->method('updateUserProperties')->willThrowException(new Exception('Bad email', 2));

		$this->assertOutcome(500, ['error' => 'Bad email'], false, $this->controller->updateMe());
		$this->assertLoggedError('[UserController] Failed to update user profile', 'Bad email', 2);
	}


	private function loginWith(array $validation, array $rateLimit = ['allowed' => true]): void {
		$this->request->method('getParams')->willReturn(['username' => 'alice', 'password' => 'pw']);
		$this->securityService->method('validateLoginCredentials')
			->with(['username' => 'alice', 'password' => 'pw'])->willReturn($validation);
		$this->securityService->method('checkLoginRateLimit')->with('alice', '10.0.0.1')->willReturn($rateLimit);
	}

	private static function valid(): array {
		return ['valid' => true, 'credentials' => ['username' => 'alice', 'password' => 'pw']];
	}

	public function testLoginRefusesInvalidInputWith400BeforeRateLimiting(): void {
		$this->loginWith(['valid' => false, 'error' => 'Username is required']);
		$this->securityService->expects($this->never())->method('checkLoginRateLimit');
		$this->userManager->expects($this->never())->method('checkPassword');

		$this->assertOutcome(400, ['error' => 'Username is required'], true, $this->controller->login());
	}

	public function testRateLimitedLoginIsRefusedWith429BeforeCheckingThePassword(): void {
		$this->loginWith(self::valid(), ['allowed' => false, 'reason' => 'Too many attempts', 'lockout_until' => 99]);
		$this->userManager->expects($this->never())->method('checkPassword');
		$this->securityService->expects($this->never())->method('recordFailedLoginAttempt');
		$this->userSession->expects($this->never())->method('setUser');

		$this->assertOutcome(
			429,
			['error' => 'Too many attempts', 'retry_after' => null, 'lockout_until' => 99],
			true,
			$this->controller->login()
		);
	}

	public function testRateLimitedLoginReportsTheDelay(): void {
		$this->loginWith(self::valid(), ['allowed' => false, 'reason' => 'Slow down', 'delay' => 0]);

		$this->assertOutcome(
			429,
			['error' => 'Slow down', 'retry_after' => 0, 'lockout_until' => null],
			true,
			$this->controller->login()
		);
	}

	public function testWrongPasswordIsRefusedWith401AndRecorded(): void {
		$this->loginWith(self::valid());
		$this->userManager->expects($this->once())->method('checkPassword')->with('alice', 'pw')->willReturn(false);
		$this->securityService->expects($this->once())->method('recordFailedLoginAttempt')
			->with('alice', '10.0.0.1', 'invalid_credentials');
		$this->securityService->expects($this->never())->method('recordSuccessfulLogin');
		$this->userSession->expects($this->never())->method('setUser');

		$this->assertOutcome(401, ['error' => 'Invalid username or password'], true, $this->controller->login());
	}

	public function testDisabledAccountIsRefusedWith401AndRecorded(): void {
		$this->loginWith(self::valid());
		$this->userManager->method('checkPassword')->willReturn($this->user('alice', false));
		$this->securityService->expects($this->once())->method('recordFailedLoginAttempt')
			->with('alice', '10.0.0.1', 'account_disabled');
		$this->userSession->expects($this->never())->method('setUser');

		$this->assertOutcome(401, ['error' => 'Account is disabled'], true, $this->controller->login());
	}

	public function testValidLoginRecordsSuccessThenCreatesTheSession(): void {
		$user = $this->user();
		$calls = [];
		$this->loginWith(self::valid());
		$this->userManager->method('checkPassword')->willReturn($user);
		$this->securityService->expects($this->once())->method('recordSuccessfulLogin')->with('alice', '10.0.0.1')
			->willReturnCallback(function () use (&$calls): void {
				$calls[] = 'recordSuccessfulLogin';
			});
		$this->userSession->expects($this->once())->method('setUser')->with($user)
			->willReturnCallback(function () use (&$calls): void {
				$calls[] = 'setUser';
			});
		$this->userService->method('buildUserDataArray')->with($user)->willReturn(['uid' => 'alice']);
		$this->securityService->expects($this->never())->method('recordFailedLoginAttempt');

		$this->assertOutcome(
			200,
			['message' => 'Login successful', 'user' => ['uid' => 'alice'], 'session_created' => true],
			true,
			$this->controller->login()
		);
		$this->assertSame(['recordSuccessfulLogin', 'setUser'], $calls);
	}

	public function testLoginSystemErrorIsLoggedWithoutTheErrorCode(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->securityService->method('validateLoginCredentials')->willThrowException(new Exception('kaput', 5));

		$this->assertOutcome(500, ['error' => 'Login failed due to a system error'], true, $this->controller->login());
		$this->assertCount(1, $this->errors);
		$this->assertSame('[UserController] Login failed due to system error', $this->errors[0][0]);
		$this->assertSame(['file', 'line', 'error_message'], array_keys($this->errors[0][1]));
		$this->assertSame('kaput', $this->errors[0][1]['error_message']);
	}

	public function testLogoutEndsTheSession(): void {
		$this->userSession->expects($this->once())->method('logout');

		$this->assertOutcome(200, ['logout' => true], true, $this->controller->logout());
	}

	/**
	 * Memory limit strings and their byte values.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function memoryLimits(): array {
		return [
			'unlimited' => ['-1', 0],
			'gigabytes' => ['2G', 2147483648],
			'megabytes' => ['128M', 134217728],
			'lowercase' => ['128m', 134217728],
			'kilobytes' => ['512K', 524288],
			'bytes'     => ['1024', 1024],
			'padded'    => [' 64M ', 67108864],
		];
	}

	/**
	 * Golden outcome per provided case.
	 *
	 * @param string $limit The ini value.
	 * @param int    $bytes The expected bytes.
	 *
	 * @return void
	 *
	 * @dataProvider memoryLimits
	 */
	public function testMemoryLimitConversion(string $limit, int $bytes): void {
		$method = new ReflectionMethod(UserController::class, 'convertToBytes');

		$this->assertSame($bytes, $method->invoke($this->controller, $limit));
	}


	private function passwordChange(array $params, array $rateLimit = ['allowed' => true]): IUser&MockObject {
		$user = $this->signedIn();
		$this->securityService->method('checkLoginRateLimit')->with('alice', '10.0.0.1')->willReturn($rateLimit);
		$this->request->method('getParams')->willReturn($params);
		return $user;
	}

	public function testRateLimitedPasswordChangeIsRefusedWith429(): void {
		$this->passwordChange(['currentPassword' => 'a', 'newPassword' => 'b'], ['allowed' => false, 'reason' => 'Locked', 'delay' => 30]);
		$this->userService->expects($this->never())->method('changePassword');

		$this->assertOutcome(429, ['error' => 'Locked', 'retry_after' => 30], true, $this->controller->changePassword());
	}

	public function testPasswordChangeRequiresBothPasswords(): void {
		$this->passwordChange(['currentPassword' => 'a']);
		$this->userService->expects($this->never())->method('changePassword');

		$this->assertOutcome(
			400,
			['error' => 'Both currentPassword and newPassword are required'],
			true,
			$this->controller->changePassword()
		);
	}

	public function testPasswordChangeSanitizesOnlyTheCurrentPassword(): void {
		$user = $this->passwordChange(['currentPassword' => 'old', 'newPassword' => 'new<b>']);
		$this->userService->expects($this->once())->method('changePassword')
			->with($user, 'clean:old', 'new<b>')->willReturn(['success' => true]);

		$this->assertOutcome(200, ['success' => true], true, $this->controller->changePassword());
	}

	public function testWrongCurrentPasswordIsRefusedWith403AndRecorded(): void {
		$this->passwordChange(['currentPassword' => 'bad', 'newPassword' => 'new']);
		$this->userService->method('changePassword')->willThrowException(new RuntimeException('Current password is incorrect', 403));
		$this->securityService->expects($this->once())->method('recordFailedLoginAttempt')
			->with('alice', '10.0.0.1', 'password_change_incorrect');

		$this->assertOutcome(403, ['error' => 'Current password is incorrect'], true, $this->controller->changePassword());
		$this->assertSame([], $this->errors);
	}

	public function testOtherPasswordRefusalsAreNotRecordedAsFailedAttempts(): void {
		$this->passwordChange(['currentPassword' => 'old', 'newPassword' => 'x']);
		$this->userService->method('changePassword')->willThrowException(new RuntimeException('Too weak', 400));
		$this->securityService->expects($this->never())->method('recordFailedLoginAttempt');

		$this->assertOutcome(400, ['error' => 'Too weak'], true, $this->controller->changePassword());
	}


	public function testAvatarUploadWithoutDataIsRefusedWith400(): void {
		$this->signedIn();
		$this->userService->expects($this->never())->method('uploadAvatar');

		$this->assertOutcome(400, ['error' => 'No image data provided'], true, $this->controller->uploadAvatar());
	}

	public function testAvatarDeletion(): void {
		$user = $this->signedIn();
		$this->userService->expects($this->once())->method('deleteAvatar')->with($user)->willReturn(['deleted' => true]);

		$this->assertOutcome(200, ['deleted' => true], true, $this->controller->deleteAvatar());
	}

	public function testExportIsADownloadWithoutSecurityHeaders(): void {
		$user = $this->signedIn();
		$this->userService->method('exportPersonalData')->with($user)->willReturn(['name' => 'Zoë']);

		$response = $this->controller->exportData();

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame(json_encode(['name' => 'Zoë'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), $response->render());
		$this->assertArrayNotHasKey(self::SECURED, $response->getHeaders());
		$this->assertStringContainsString('openregister-export-alice-', $response->getHeaders()['Content-Disposition']);
	}

	public function testThrottledExportSurfacesTheDecodedPayload(): void {
		$this->signedIn();
		$this->userService->method('exportPersonalData')
			->willThrowException(new RuntimeException('{"error":"Wait","retry_after":60}', 429));

		$this->assertOutcome(429, ['error' => 'Wait', 'retry_after' => 60], true, $this->controller->exportData());
	}

	public function testThrottledExportWithPlainMessageFallsBackToTheErrorShape(): void {
		$this->signedIn();
		$this->userService->method('exportPersonalData')->willThrowException(new RuntimeException('Wait', 429));

		$this->assertOutcome(429, ['error' => 'Wait'], true, $this->controller->exportData());
	}

	public function testNotificationPreferencesReadAndWrite(): void {
		$user = $this->signedIn();
		$this->userService->method('getNotificationPreferences')->with($user)->willReturn(['digest' => 'daily']);
		$this->request->method('getParams')->willReturn(['_route' => 'r', 'digest' => 'weekly']);
		$this->userService->expects($this->once())->method('setNotificationPreferences')
			->with($user, ['digest' => 'weekly'])->willReturn(['digest' => 'weekly']);

		$this->assertOutcome(200, ['digest' => 'daily'], true, $this->controller->getNotificationPreferences());
		$this->assertOutcome(200, ['digest' => 'weekly'], true, $this->controller->updateNotificationPreferences());
	}

	public function testInvalidNotificationPreferencesAreRefusedWith400Unlogged(): void {
		$this->signedIn();
		$this->request->method('getParams')->willReturn([]);
		$this->userService->method('setNotificationPreferences')->willThrowException(new InvalidArgumentException('Bad digest'));

		$this->assertOutcome(400, ['error' => 'Bad digest'], true, $this->controller->updateNotificationPreferences());
		$this->assertSame([], $this->errors);
	}

	public function testActivityPassesTheQueryParameters(): void {
		$user = $this->signedIn();
		$this->request->method('getParam')->willReturnMap(
			[['_limit', '25', '10'], ['_offset', '0', '5'], ['type', null, 'create'], ['_from', null, 'a'], ['_to', null, 'b']]
		);
		$this->userService->expects($this->once())->method('getUserActivity')
			->with($user, 10, 5, 'create', 'a', 'b')->willReturn(['results' => []]);

		$this->assertOutcome(200, ['results' => []], true, $this->controller->getActivity());
	}


	public function testTokensAreListedForTheSessionUser(): void {
		$user = $this->signedIn();
		$this->userService->method('listApiTokens')->with($user)->willReturn([['id' => 't1']]);

		$this->assertOutcome(200, [['id' => 't1']], true, $this->controller->listTokens());
	}

	public function testTokenCreationRequiresAName(): void {
		$this->signedIn();
		$this->request->method('getParams')->willReturn(['name' => '']);
		$this->userService->expects($this->never())->method('createApiToken');

		$this->assertOutcome(400, ['error' => 'Token name is required'], true, $this->controller->createToken());
	}

	public function testTokenCreationReturns201(): void {
		$user = $this->signedIn();
		$this->request->method('getParams')->willReturn(['name' => 'ci', 'expiresIn' => '30d']);
		$this->userService->expects($this->once())->method('createApiToken')
			->with($user, 'clean:ci', '30d')->willReturn(['token' => 'secret']);

		$this->assertOutcome(201, ['token' => 'secret'], true, $this->controller->createToken());
	}

	public function testTokenRevocationIsScopedToTheSessionUser(): void {
		$user = $this->signedIn();
		$this->userService->expects($this->once())->method('revokeApiToken')->with($user, 't9')->willReturn(['revoked' => true]);

		$this->assertOutcome(200, ['revoked' => true], true, $this->controller->revokeToken('t9'));
	}


	public function testDeactivationRequestSanitizesTheReason(): void {
		$user = $this->signedIn();
		$this->request->method('getParams')->willReturn(['reason' => 'leaving']);
		$this->userService->expects($this->once())->method('requestDeactivation')
			->with($user, 'clean:leaving')->willReturn(['status' => 'pending']);

		$this->assertOutcome(200, ['status' => 'pending'], true, $this->controller->requestDeactivation());
	}

	public function testDuplicateDeactivationSurfacesTheDecodedPayloadAs409(): void {
		$this->signedIn();
		$this->request->method('getParams')->willReturn([]);
		$this->userService->method('requestDeactivation')
			->willThrowException(new RuntimeException('{"error":"Already pending","pending":true}', 409));

		$this->assertOutcome(409, ['error' => 'Already pending', 'pending' => true], true, $this->controller->requestDeactivation());
	}

	public function testDeactivationStatusAndCancellation(): void {
		$user = $this->signedIn();
		$this->userService->method('getDeactivationStatus')->with($user)->willReturn(['pending' => false]);
		$this->userService->method('cancelDeactivation')->with($user)->willReturn(['cancelled' => true]);

		$this->assertOutcome(200, ['pending' => false], true, $this->controller->getDeactivationStatus());
		$this->assertOutcome(200, ['cancelled' => true], true, $this->controller->cancelDeactivation());
	}
}//end class
