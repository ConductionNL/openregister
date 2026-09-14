<?php

/**
 * The settings saves and connection tests that report to integriq.
 *
 * A save or a test the admin runs is the only moment OpenRegister learns
 * whether an LLM provider, a token or an e-Depot works. If these callers stop
 * reporting, the Connections page keeps an old status and looks like an answer.
 * Every test here asserts the report the caller actually sends, and that a
 * report never changes the response.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\ApiTokenSettingsController;
use OCA\OpenRegister\Controller\Settings\EdepotSettingsController;
use OCA\OpenRegister\Controller\Settings\LlmSettingsController;
use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCA\OpenRegister\Service\Edepot\EdepotTransferService;
use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransport;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransport;
use OCA\OpenRegister\Service\Edepot\Transport\SftpTransport;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for the connection reports sent from the settings controllers.
 *
 * @covers \OCA\OpenRegister\Controller\Settings\LlmSettingsController
 * @covers \OCA\OpenRegister\Controller\Settings\ApiTokenSettingsController
 * @covers \OCA\OpenRegister\Controller\Settings\EdepotSettingsController
 */
class SettingsConnectionReportTest extends TestCase {

	/**
	 * Every report sent, as [key, status, message].
	 *
	 * @var array<int, array{0: string, 1: string, 2: string}>
	 */
	private array $reports = [];

	/**
	 * Every refresh asked for, as the saved key lists.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $refreshes = [];

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The recording reporter.
	 *
	 * @var ConnectionReporter&MockObject
	 */
	private ConnectionReporter $reporter;

	/**
	 * Set up a reporter that records instead of dispatching.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->reports = [];
		$this->refreshes = [];
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->reporter = $this->getMockBuilder(ConnectionReporter::class)
			->disableOriginalConstructor()
			->onlyMethods(['report', 'refreshFromSave'])
			->getMock();
		$this->reporter->method('report')->willReturnCallback(
			function (string $key, string $status, string $message = ''): bool {
				$this->reports[] = [$key, $status, $message];
				return true;
			}
		);
		$this->reporter->method('refreshFromSave')->willReturnCallback(
			function (array $savedKeys): array {
				$this->refreshes[] = $savedKeys;
				return [];
			}
		);
	}//end setUp()

	/**
	 * The LLM controller with a settings service that answers the given saved blob.
	 *
	 * @param array<string, mixed>|null $saved The blob the save returns, or null to throw.
	 *
	 * @return LlmSettingsController
	 */
	private function llmController(?array $saved): LlmSettingsController {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		if ($saved === null) {
			$settings->method('updateLLMSettingsOnly')->willThrowException(new RuntimeException('disk full'));
		} else {
			$settings->method('updateLLMSettingsOnly')->willReturn($saved);
		}

		$this->request->method('getParams')->willReturn([]);

		return new LlmSettingsController(
			'openregister',
			$this->request,
			$this->createMock(originalClassName: IDBConnection::class),
			$this->createMock(originalClassName: ContainerInterface::class),
			$settings,
			$this->createMock(originalClassName: VectorizationService::class),
			$this->createMock(originalClassName: LoggerInterface::class),
			$this->reporter
		);
	}//end llmController()

	/**
	 * A save with no provider reports unconfigured, never simulated.
	 *
	 * @return void
	 */
	public function testAnLlmSaveWithoutProvidersReportsUnconfigured(): void {
		$response = $this->llmController(saved: ['chatProvider' => null, 'embeddingProvider' => null])->updateLLMSettings();

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertCount(expectedCount: 1, haystack: $this->reports);
		$this->assertSame(expected: ['llm', 'unconfigured'], actual: array_slice($this->reports[0], 0, 2));
		$this->assertStringContainsString(needle: '503', haystack: $this->reports[0][2]);
	}//end testAnLlmSaveWithoutProvidersReportsUnconfigured()

	/**
	 * A save with both providers reports configured, naming both and saying it is untested.
	 *
	 * @return void
	 */
	public function testAnLlmSaveWithBothProvidersReportsConfigured(): void {
		$this->llmController(saved: ['chatProvider' => 'openai', 'embeddingProvider' => 'ollama'])->updateLLMSettings();

		$this->assertSame(expected: ['llm', 'configured'], actual: array_slice($this->reports[0], 0, 2));
		$this->assertStringContainsString(needle: 'openai', haystack: $this->reports[0][2]);
		$this->assertStringContainsString(needle: 'ollama', haystack: $this->reports[0][2]);
		$this->assertStringContainsString(needle: 'not tested', haystack: $this->reports[0][2]);
	}//end testAnLlmSaveWithBothProvidersReportsConfigured()

	/**
	 * A save with only one of the two providers reports limited.
	 *
	 * @return void
	 */
	public function testAnLlmSaveWithOneProviderReportsLimited(): void {
		$this->llmController(saved: ['chatProvider' => 'fireworks', 'embeddingProvider' => 'none'])->updateLLMSettings();

		$this->assertSame(expected: ['llm', 'limited'], actual: array_slice($this->reports[0], 0, 2));
		$this->assertStringContainsString(needle: 'fireworks', haystack: $this->reports[0][2]);
	}//end testAnLlmSaveWithOneProviderReportsLimited()

	/**
	 * A failed save reports nothing: nothing new was learned.
	 *
	 * @return void
	 */
	public function testAFailedLlmSaveReportsNothing(): void {
		$response = $this->llmController(saved: null)->updateLLMSettings();

		$this->assertSame(expected: 500, actual: $response->getStatus());
		$this->assertSame(expected: [], actual: $this->reports);
	}//end testAFailedLlmSaveReportsNothing()

	/**
	 * The token controller with the given saved GitHub token and HTTP client.
	 *
	 * @param string       $savedToken The token in app config.
	 * @param IClient|null $client     The client the probe uses.
	 *
	 * @return ApiTokenSettingsController
	 */
	private function tokenController(string $savedToken, ?IClient $client = null): ApiTokenSettingsController {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'github_api_token' => $savedToken,
				default => $default,
			}
		);

		$clientService = $this->createMock(originalClassName: IClientService::class);
		if ($client !== null) {
			$clientService->method('newClient')->willReturn($client);
		}

		return new ApiTokenSettingsController(
			'openregister',
			$this->request,
			$config,
			$this->createMock(originalClassName: SettingsService::class),
			$clientService,
			$this->createMock(originalClassName: LoggerInterface::class),
			$this->reporter
		);
	}//end tokenController()

	/**
	 * A token save asks integriq to resolve again for exactly the keys it wrote.
	 *
	 * @return void
	 */
	public function testATokenSaveRefreshesTheWrittenKeys(): void {
		$this->request->method('getParams')->willReturn(['github_token' => 'ghp_new', 'gitlab_token' => 'glp***789', 'gitlab_url' => 'https://git.example/api/v4']);

		$response = $this->tokenController(savedToken: '')->saveApiTokens();

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: [['github_api_token', 'gitlab_api_url']], actual: $this->refreshes);
		$this->assertSame(expected: [], actual: $this->reports);
	}//end testATokenSaveRefreshesTheWrittenKeys()

	/**
	 * A test of the saved token reports its outcome.
	 *
	 * @return void
	 */
	public function testATestOfTheSavedTokenReports(): void {
		$body = $this->createMock(originalClassName: IResponse::class);
		$body->method('getBody')->willReturn('{"login":"conduction-bot"}');
		$body->method('getHeader')->willReturn('repo');
		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('get')->willReturn($body);
		$this->request->method('getParams')->willReturn([]);

		$response = $this->tokenController(savedToken: 'ghp_saved', client: $client)->testGitHubToken();

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: ['github', 'configured'], actual: array_slice($this->reports[0], 0, 2));
		$this->assertStringContainsString(needle: 'conduction-bot', haystack: $this->reports[0][2]);
	}//end testATestOfTheSavedTokenReports()

	/**
	 * A failing test of the saved token reports an error.
	 *
	 * @return void
	 */
	public function testAFailingTestOfTheSavedTokenReportsAnError(): void {
		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('get')->willThrowException(new RuntimeException('401 Bad credentials'));
		$this->request->method('getParams')->willReturn(['token' => 'ghp_saved']);

		$response = $this->tokenController(savedToken: 'ghp_saved', client: $client)->testGitHubToken();

		$this->assertSame(expected: 400, actual: $response->getStatus());
		$this->assertSame(expected: ['github', 'error'], actual: array_slice($this->reports[0], 0, 2));
		$this->assertStringContainsString(needle: '401', haystack: $this->reports[0][2]);
	}//end testAFailingTestOfTheSavedTokenReportsAnError()

	/**
	 * A test of a token typed into the form, not saved, says nothing about the saved connection.
	 *
	 * @return void
	 */
	public function testATestOfAnUnsavedTokenReportsNothing(): void {
		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('get')->willThrowException(new RuntimeException('401 Bad credentials'));
		$this->request->method('getParams')->willReturn(['token' => 'ghp_typed']);

		$this->tokenController(savedToken: 'ghp_saved', client: $client)->testGitHubToken();

		$this->assertSame(expected: [], actual: $this->reports);
	}//end testATestOfAnUnsavedTokenReportsNothing()

	/**
	 * The e-Depot controller whose REST transport answers the given test result.
	 *
	 * @param bool|RuntimeException $outcome The testConnection result, or an exception to throw.
	 *
	 * @return EdepotSettingsController
	 */
	private function edepotController(bool|RuntimeException $outcome): EdepotSettingsController {
		$transfer = $this->createMock(originalClassName: EdepotTransferService::class);
		$transfer->method('getTransportConfig')->willReturn(['transport' => 'rest_api']);

		$rest = $this->createMock(originalClassName: RestApiTransport::class);
		$rest->method('getName')->willReturn('rest_api');
		if ($outcome instanceof RuntimeException) {
			$rest->method('testConnection')->willThrowException($outcome);
		} else {
			$rest->method('testConnection')->willReturn($outcome);
		}

		return new EdepotSettingsController(
			'openregister',
			$this->request,
			$this->createMock(originalClassName: IAppConfig::class),
			$transfer,
			$this->createMock(originalClassName: SftpTransport::class),
			$rest,
			$this->createMock(originalClassName: OpenConnectorTransport::class),
			$this->createMock(originalClassName: LoggerInterface::class),
			$this->reporter
		);
	}//end edepotController()

	/**
	 * A passing e-Depot test reports configured, naming the transport.
	 *
	 * @return void
	 */
	public function testAPassingEdepotTestReportsConfigured(): void {
		$response = $this->edepotController(outcome: true)->testEdepotConnection();

		$this->assertTrue(condition: $response->getData()['success']);
		$this->assertSame(expected: ['edepot', 'configured'], actual: array_slice($this->reports[0], 0, 2));
		$this->assertStringContainsString(needle: 'rest_api', haystack: $this->reports[0][2]);
	}//end testAPassingEdepotTestReportsConfigured()

	/**
	 * A failing e-Depot test reports an error, and the response is unchanged.
	 *
	 * @return void
	 */
	public function testAFailingEdepotTestReportsAnError(): void {
		$response = $this->edepotController(outcome: false)->testEdepotConnection();

		$this->assertFalse(condition: $response->getData()['success']);
		$this->assertSame(expected: ['edepot', 'error'], actual: array_slice($this->reports[0], 0, 2));
	}//end testAFailingEdepotTestReportsAnError()

	/**
	 * An e-Depot test that throws reports an error with the reason.
	 *
	 * @return void
	 */
	public function testAThrowingEdepotTestReportsTheReason(): void {
		$response = $this->edepotController(outcome: new RuntimeException('connection refused'))->testEdepotConnection();

		$this->assertSame(expected: 500, actual: $response->getStatus());
		$this->assertSame(expected: ['edepot', 'error'], actual: array_slice($this->reports[0], 0, 2));
		$this->assertStringContainsString(needle: 'connection refused', haystack: $this->reports[0][2]);
	}//end testAThrowingEdepotTestReportsTheReason()
}//end class
