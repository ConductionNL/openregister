<?php

/**
 * ApiTokenSettingsController saved-token connection report tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\ApiTokenSettingsController;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ApiTokenSettingsControllerFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Testing the SAVED token tells the connection registry whether it works;
 * testing a token typed into the form does not.
 */
class ApiTokenSettingsControllerSavedTokenReportTest extends TestCase {

	/**
	 * The connection reporter double.
	 *
	 * @var ConnectionReporter&MockObject
	 */
	private ConnectionReporter&MockObject $reporter;

	/**
	 * The HTTP client double.
	 *
	 * @var IClient&MockObject
	 */
	private IClient&MockObject $client;

	/**
	 * Build the reporter and client doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reporter = $this->createMock(ConnectionReporter::class);
		$this->client   = $this->createMock(IClient::class);
	}//end setUp()

	/**
	 * A controller with the given request parameters and the saved GitHub token ghp_saved.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return ApiTokenSettingsController
	 */
	private function controller(array $params): ApiTokenSettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => ($key === 'github_api_token') ? 'ghp_saved' : $default
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($this->client);

		return ApiTokenSettingsControllerFactory::create(
			'openregister',
			$request,
			$config,
			$this->createMock(AppSettingsInterface::class),
			$clients,
			$this->createMock(LoggerInterface::class),
			$this->reporter,
		);
	}//end controller()

	/**
	 * A GitHub user response for the given login.
	 *
	 * @param string $login The login.
	 *
	 * @return IResponse
	 */
	private function userResponse(string $login): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode(['login' => $login]));
		$response->method('getHeader')->willReturn('repo');
		return $response;
	}//end userResponse()

	/**
	 * A working saved token is reported as configured, naming the account.
	 *
	 * @return void
	 */
	public function testAWorkingSavedTokenIsReportedConfigured(): void {
		$this->client->method('get')->willReturn($this->userResponse('jan'));
		$this->reporter->expects($this->once())
			->method('report')
			->with('github', 'configured', 'The saved token is valid for jan.');

		$response = $this->controller([])->testGitHubToken();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('jan', $response->getData()['username']);
	}//end testAWorkingSavedTokenIsReportedConfigured()

	/**
	 * A refused saved token is reported as an error and answered with 400.
	 *
	 * @return void
	 */
	public function testARefusedSavedTokenIsReportedAsError(): void {
		$this->client->method('get')->willThrowException(new RuntimeException('401 Bad credentials'));
		$this->reporter->expects($this->once())
			->method('report')
			->with('github', 'error', 'GitHub refused the saved token: 401 Bad credentials');

		$response = $this->controller([])->testGitHubToken();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('GitHub token test failed: 401 Bad credentials', $response->getData()['message']);
	}//end testARefusedSavedTokenIsReportedAsError()

	/**
	 * A token typed into the form is tested without touching the registry, either way.
	 *
	 * @return void
	 */
	public function testATypedTokenIsNeverReported(): void {
		$this->client->method('get')->willReturnOnConsecutiveCalls(
			$this->userResponse('piet'),
			$this->throwException(new RuntimeException('401'))
		);
		$this->reporter->expects($this->never())->method('report');

		$valid  = $this->controller(['token' => 'ghp_typed'])->testGitHubToken();
		$failed = $this->controller(['token' => 'ghp_typed'])->testGitHubToken();

		$this->assertSame(200, $valid->getStatus());
		$this->assertSame(400, $failed->getStatus());
	}//end testATypedTokenIsNeverReported()
}//end class
