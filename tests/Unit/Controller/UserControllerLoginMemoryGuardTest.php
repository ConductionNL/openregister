<?php

/**
 * UserController login memory guard tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\UserController;
use OCA\OpenRegister\Service\SecurityService;
use OCA\OpenRegister\Service\UserService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\UserControllerFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A login refuses with 503 when memory is already near the limit, and a login
 * that grew memory by more than 10 MB is logged as a warning.
 */
class UserControllerLoginMemoryGuardTest extends TestCase {

	/**
	 * The memory_limit in force before the test.
	 *
	 * @var string
	 */
	private string $memoryLimit;

	/**
	 * Memory held during the login to simulate growth.
	 *
	 * @var string
	 */
	private string $ballast = '';

	/**
	 * The security service double.
	 *
	 * @var SecurityService&MockObject
	 */
	private SecurityService&MockObject $security;

	/**
	 * The user manager double.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $users;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The controller under test.
	 *
	 * @var UserController
	 */
	private UserController $controller;

	/**
	 * Build a controller whose login reaches the password check.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->memoryLimit = (string) ini_get('memory_limit');

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['username' => 'jan', 'password' => 'secret']);
		$this->security = $this->createMock(SecurityService::class);
		$this->security->method('addSecurityHeaders')->willReturnArgument(0);
		$this->security->method('getClientIpAddress')->willReturn('127.0.0.1');
		$this->security->method('validateLoginCredentials')->willReturn(
			['valid' => true, 'credentials' => ['username' => 'jan', 'password' => 'secret']]
		);
		$this->security->method('checkLoginRateLimit')->willReturn(['allowed' => true]);
		$this->users  = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$l10n         = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$userService = $this->createMock(UserService::class);
		$userService->method('buildUserDataArray')->willReturn(['uid' => 'jan']);

		$this->controller = UserControllerFactory::create(
			'openregister',
			$request,
			$userService,
			$this->security,
			$this->users,
			$this->createMock(IUserSession::class),
			$this->logger,
			$l10n
		);
	}//end setUp()

	/**
	 * Restore the memory limit and drop the ballast.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->ballast = '';
		ini_set('memory_limit', $this->memoryLimit);
		parent::tearDown();
	}//end tearDown()

	/**
	 * A user that logs in.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$user->method('getUID')->willReturn('jan');
		return $user;
	}//end user()

	/**
	 * Memory above 80% of the limit refuses the login with 503 before any credential check.
	 *
	 * @return void
	 */
	public function testNearTheMemoryLimitTheLoginIsRefused(): void {
		$this->assertNotFalse(ini_set('memory_limit', (string) (int) ceil(memory_get_usage(true) * 1.1)));
		$this->security->expects($this->never())->method('validateLoginCredentials');
		$this->users->expects($this->never())->method('checkPassword');

		$response = $this->controller->login();

		$this->assertSame(503, $response->getStatus());
		$this->assertSame('Server memory usage too high, please try again later', $response->getData()['error']);
	}//end testNearTheMemoryLimitTheLoginIsRefused()

	/**
	 * With headroom the same login proceeds to the password check and succeeds.
	 *
	 * @return void
	 */
	public function testWithHeadroomTheLoginProceeds(): void {
		ini_set('memory_limit', '-1');
		$this->users->expects($this->once())->method('checkPassword')->willReturn($this->user());
		$this->logger->expects($this->never())->method('warning');

		$response = $this->controller->login();

		$this->assertSame(200, $response->getStatus());
	}//end testWithHeadroomTheLoginProceeds()

	/**
	 * A login that grew memory by more than 10 MB logs the growth.
	 *
	 * @return void
	 */
	public function testALoginThatGrewMemoryIsLogged(): void {
		ini_set('memory_limit', '-1');
		$user = $this->user();
		$this->users->method('checkPassword')->willReturnCallback(
			function () use ($user): IUser {
				$this->ballast = str_repeat('x', 24 * 1024 * 1024);
				return $user;
			}
		);
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'[UserController] High memory usage during login',
				$this->callback(
					static fn (array $context): bool => $context['user'] === 'jan'
						&& $context['increase_bytes'] > 10 * 1024 * 1024
						&& $context['final_memory'] - $context['initial_memory'] === $context['increase_bytes']
				)
			);

		$response = $this->controller->login();

		$this->assertSame(200, $response->getStatus());
	}//end testALoginThatGrewMemoryIsLogged()
}//end class
