<?php

/**
 * Golden outcomes of the user settings API.
 *
 * Pins the allowed and refused outcomes (status, body, log lines and the
 * token-store calls) of UserSettingsController before it is restructured.
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
use OCA\OpenRegister\Controller\UserSettingsController;
use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden allowed/refused outcomes of UserSettingsController.
 */
class UserSettingsControllerA24GoldenTest extends TestCase {
	/** @var UserSettingsController */
	private UserSettingsController $controller;

	/** @var IRequest&MockObject */
	private IRequest&MockObject $request;

	/** @var GitHubClientInterface&MockObject */
	private GitHubClientInterface&MockObject $gitHub;

	/** @var IUserSession&MockObject */
	private IUserSession&MockObject $userSession;

	/**
	 * Logged calls: [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array}>
	 */
	private array $logs = [];

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->gitHub = $this->createMock(GitHubClientInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		$this->controller = new UserSettingsController('openregister', $this->request, $this->gitHub, $this->userSession, $logger);
	}

	private function signedIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function assertOutcome(int $status, array $body, JSONResponse $response): void {
		$this->assertSame($status, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}

	private function assertLogged(string $level, string $message): void {
		$this->assertCount(1, $this->logs);
		$this->assertSame($level, $this->logs[0][0]);
		$this->assertSame($message, $this->logs[0][1]);
		$this->assertSame(['file', 'line'], array_keys($this->logs[0][2]));
		$this->assertStringEndsWith('UserSettingsController.php', $this->logs[0][2]['file']);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function endpoints(): array {
		return [
			'status' => ['getGitHubTokenStatus'],
			'set'    => ['setGitHubToken'],
			'remove' => ['removeGitHubToken'],
		];
	}

	/**
	 * Golden outcome per provided case.
	 *
	 * @param string $method The endpoint.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testNoSessionIsRefusedWith401(string $method): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->gitHub->expects($this->never())->method('setUserToken');
		$this->gitHub->expects($this->never())->method('getUserToken');

		$this->assertOutcome(401, ['error' => 'User not authenticated'], $this->controller->$method());
		$this->assertSame([], $this->logs);
	}

	public function testStatusWithoutToken(): void {
		$this->signedIn();
		$this->gitHub->method('getUserToken')->with('alice')->willReturn(null);
		$this->gitHub->expects($this->never())->method('validateToken');

		$this->assertOutcome(
			200,
			['hasToken' => false, 'isValid' => false, 'message' => 'No GitHub token configured'],
			$this->controller->getGitHubTokenStatus()
		);
	}

	public function testStatusValidatesTheStoredToken(): void {
		$this->signedIn();
		$this->gitHub->method('getUserToken')->willReturn('ghp_x');
		$this->gitHub->expects($this->once())->method('setUserToken')->with('ghp_x', 'alice');
		$this->gitHub->method('validateToken')->with('alice')->willReturnOnConsecutiveCalls(true, false);

		$this->assertOutcome(200, ['hasToken' => true, 'isValid' => true, 'message' => 'Token is valid'], $this->controller->getGitHubTokenStatus());
	}

	public function testStatusReportsAnInvalidToken(): void {
		$this->signedIn();
		$this->gitHub->method('getUserToken')->willReturn('ghp_x');
		$this->gitHub->method('validateToken')->willReturn(false);

		$this->assertOutcome(
			200,
			['hasToken' => true, 'isValid' => false, 'message' => 'Token is invalid or expired'],
			$this->controller->getGitHubTokenStatus()
		);
	}

	public function testStatusFailureIsLoggedAndGeneric(): void {
		$this->signedIn();
		$this->gitHub->method('getUserToken')->willThrowException(new Exception('api down'));

		$this->assertOutcome(500, ['error' => 'Failed to get token status'], $this->controller->getGitHubTokenStatus());
		$this->assertLogged('error', '[UserSettingsController] Failed to get GitHub token status: api down');
	}

	/**
	 * @return array<string, array{0: array}>
	 */
	public static function missingTokens(): array {
		return [
			'absent' => [[]],
			'null'   => [['token' => null]],
			'blank'  => [['token' => '   ']],
		];
	}

	/**
	 * Golden outcome per provided case.
	 *
	 * @param array $params The request parameters.
	 *
	 * @return void
	 *
	 * @dataProvider missingTokens
	 */
	public function testSetRequiresAToken(array $params): void {
		$this->signedIn();
		$this->request->method('getParams')->willReturn($params);
		$this->gitHub->expects($this->never())->method('setUserToken');

		$this->assertOutcome(400, ['error' => 'Token is required'], $this->controller->setGitHubToken());
	}

	public function testSetRefusesAnInvalidToken(): void {
		$this->signedIn();
		$this->request->method('getParams')->willReturn(['token' => 'ghp_bad']);
		$this->gitHub->expects($this->once())->method('setUserToken')->with('ghp_bad', 'alice');
		$this->gitHub->method('validateToken')->with('alice')->willReturn(false);

		$this->assertOutcome(400, ['error' => 'Invalid GitHub token'], $this->controller->setGitHubToken());
		$this->assertSame([], $this->logs);
	}

	public function testSetStoresAValidToken(): void {
		$this->signedIn();
		$this->request->method('getParams')->willReturn(['token' => 'ghp_ok']);
		$this->gitHub->expects($this->once())->method('setUserToken')->with('ghp_ok', 'alice');
		$this->gitHub->method('validateToken')->willReturn(true);

		$this->assertOutcome(200, ['success' => true, 'message' => 'GitHub token saved successfully'], $this->controller->setGitHubToken());
		$this->assertLogged('info', '[UserSettingsController] GitHub token set for user: alice');
	}

	public function testSetFailureExposesTheMessage(): void {
		$this->signedIn();
		$this->request->method('getParams')->willReturn(['token' => 'ghp_ok']);
		$this->gitHub->method('setUserToken')->willThrowException(new Exception('disk full'));

		$this->assertOutcome(500, ['error' => 'Failed to save token: disk full'], $this->controller->setGitHubToken());
		$this->assertLogged('error', '[UserSettingsController] Failed to set GitHub token: disk full');
	}

	public function testRemoveClearsTheToken(): void {
		$this->signedIn();
		$this->gitHub->expects($this->once())->method('setUserToken')->with(null, 'alice');

		$this->assertOutcome(200, ['success' => true, 'message' => 'GitHub token removed successfully'], $this->controller->removeGitHubToken());
		$this->assertLogged('info', '[UserSettingsController] GitHub token removed for user: alice');
	}

	public function testRemoveFailureIsLoggedAndGeneric(): void {
		$this->signedIn();
		$this->gitHub->method('setUserToken')->willThrowException(new Exception('nope'));

		$this->assertOutcome(500, ['error' => 'Failed to remove token'], $this->controller->removeGitHubToken());
		$this->assertLogged('error', '[UserSettingsController] Failed to remove GitHub token: nope');
	}
}//end class
