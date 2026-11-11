<?php

/**
 * Characterisation tests for the Presidio probe and the OpenAnonymiser detection
 * of {@see \OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService}.
 *
 * Pins every branch of the Presidio health probe (not configured, 2xx, 4xx, 5xx,
 * a thrown client error) and of the ExApp detection (light only, both variants)
 * before those methods were split into named steps.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Anonymisation;

use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendCollaborators;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendPlatform;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService;
use OCA\OpenRegister\Service\Anonymisation\BackendState;
use OCA\OpenRegister\Service\Anonymisation\ProbeResult;
use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The Presidio probe and the OpenAnonymiser detection, branch by branch.
 */
class AnonymisationBackendProbeTest extends TestCase {

	private IAppManager&MockObject $appManager;

	private IClientService&MockObject $clientService;

	private FileSettingsHandler&MockObject $fileSettingsHandler;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->appManager    = $this->createMock(IAppManager::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->logger        = $this->createMock(LoggerInterface::class);

		$this->fileSettingsHandler = $this->getMockBuilder(FileSettingsHandler::class)
			->disableOriginalConstructor()
			->onlyMethods(['getFileSettingsOnly'])
			->getMock();
	}//end setUp()

	/**
	 * An empty endpoint is reported not configured, without an HTTP call.
	 *
	 * @return void
	 */
	public function testPresidioWithoutEndpointIsNotConfigured(): void {
		$this->withEndpoint('   ');
		$this->clientService->expects($this->never())->method('newClient');

		$probe = $this->service()->testConnection(BackendState::METHOD_PRESIDIO);

		$this->assertFalse($probe->reachable);
		$this->assertNull($probe->latencyMs);
		$this->assertSame(ProbeResult::ERROR_NOT_CONFIGURED, $probe->error);
	}//end testPresidioWithoutEndpointIsNotConfigured()

	/**
	 * A 2xx health answer is reachable, with the measured latency; the URL and options are fixed.
	 *
	 * @return void
	 */
	public function testPresidioHealthy(): void {
		$this->withEndpoint(' http://presidio:3000/ ');
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('get')->with(
			'http://presidio:3000/health',
			[
				'timeout' => 10,
				'connect_timeout' => 5,
				'nextcloud' => ['allow_local_address' => true],
			]
		)->willReturn($this->response(204));
		$this->clientService->method('newClient')->willReturn($client);

		$probe = $this->service()->testConnection(BackendState::METHOD_PRESIDIO);

		$this->assertTrue($probe->reachable);
		$this->assertIsInt($probe->latencyMs);
		$this->assertGreaterThanOrEqual(0, $probe->latencyMs);
		$this->assertNull($probe->error);
	}//end testPresidioHealthy()

	/**
	 * A non-2xx answer is unreachable with its latency, as a 4xx or a 5xx.
	 *
	 * @return void
	 */
	public function testPresidioHttpErrorsKeepTheLatency(): void {
		foreach ([404 => ProbeResult::ERROR_HTTP_4XX, 302 => ProbeResult::ERROR_HTTP_4XX, 500 => ProbeResult::ERROR_HTTP_5XX, 503 => ProbeResult::ERROR_HTTP_5XX] as $status => $expected) {
			$this->setUp();
			$this->withEndpoint('http://presidio');
			$client = $this->createMock(IClient::class);
			$client->method('get')->willReturn($this->response($status));
			$this->clientService->method('newClient')->willReturn($client);

			$probe = $this->service()->testConnection(BackendState::METHOD_PRESIDIO);

			$this->assertFalse($probe->reachable, (string) $status);
			$this->assertIsInt($probe->latencyMs, (string) $status);
			$this->assertSame($expected, $probe->error, (string) $status);
		}
	}//end testPresidioHttpErrorsKeepTheLatency()

	/**
	 * A thrown client error is unreachable without latency, mapped from its message.
	 *
	 * @return void
	 */
	public function testPresidioClientErrorsAreMapped(): void {
		$cases = [
			'cURL error 28: Operation timed out' => ProbeResult::ERROR_TIMEOUT,
			'Could not resolve host: presidio' => ProbeResult::ERROR_DNS_ERROR,
			'Connection refused' => ProbeResult::ERROR_CONNECT_REFUSED,
		];
		foreach ($cases as $message => $expected) {
			$this->setUp();
			$this->withEndpoint('http://presidio');
			$client = $this->createMock(IClient::class);
			$client->method('get')->willThrowException(new RuntimeException($message));
			$this->clientService->method('newClient')->willReturn($client);

			$probe = $this->service()->testConnection(BackendState::METHOD_PRESIDIO);

			$this->assertFalse($probe->reachable, $message);
			$this->assertNull($probe->latencyMs, $message);
			$this->assertSame($expected, $probe->error, $message);
		}
	}//end testPresidioClientErrorsAreMapped()

	/**
	 * Only the light variant enabled is reachable with zero latency and logs nothing.
	 *
	 * @return void
	 */
	public function testOpenAnonymiserLightOnly(): void {
		$this->withEndpoint('');
		$this->exApps(full: false, light: true);
		$this->logger->expects($this->never())->method('debug');

		$probe = $this->service()->testConnection(BackendState::METHOD_OPENANONYMISER);

		$this->assertTrue($probe->reachable);
		$this->assertSame(0, $probe->latencyMs);
		$this->assertNull($probe->error);
	}//end testOpenAnonymiserLightOnly()

	/**
	 * Both variants enabled is reachable and logs that the full one is used.
	 *
	 * @return void
	 */
	public function testOpenAnonymiserBothVariantsLogsTheChoice(): void {
		$this->withEndpoint('');
		$this->exApps(full: true, light: true);
		$this->logger->expects($this->once())->method('debug')->with(
			'[AnonymisationBackendService] both OpenAnonymiser variants enabled; using full, light also-detected'
		);

		$probe = $this->service()->testConnection(BackendState::METHOD_OPENANONYMISER);

		$this->assertTrue($probe->reachable);
		$this->assertSame(0, $probe->latencyMs);
	}//end testOpenAnonymiserBothVariantsLogsTheChoice()

	/**
	 * Build the service with the configured doubles.
	 *
	 * @return AnonymisationBackendService
	 */
	private function service(): AnonymisationBackendService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(60);

		return new AnonymisationBackendService(
			new AnonymisationBackendPlatform($this->appManager, $appConfig, $this->createMock(ICacheFactory::class), $this->clientService),
			new AnonymisationBackendCollaborators(
				$this->fileSettingsHandler,
				$this->logger,
				$this->createMock(ConnectionReporter::class)
			)
		);
	}//end service()

	/**
	 * Store a Presidio endpoint in the file settings.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @return void
	 */
	private function withEndpoint(string $endpoint): void {
		$this->fileSettingsHandler->method('getFileSettingsOnly')->willReturn(['presidioApiEndpoint' => $endpoint]);
	}//end withEndpoint()

	/**
	 * Enable AppAPI and the given OpenAnonymiser variants.
	 *
	 * @param bool $full  Whether the full variant is enabled.
	 * @param bool $light Whether the light variant is enabled.
	 *
	 * @return void
	 */
	private function exApps(bool $full, bool $light): void {
		$this->appManager->method('isEnabledForUser')->willReturnCallback(
			static function (string $appId) use ($full, $light): bool {
				return match ($appId) {
					'app_api' => true,
					'openanonymiser' => $full,
					'openanonymiser_light' => $light,
					default => false,
				};
			}
		);
	}//end exApps()

	/**
	 * A response with the given status.
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return IResponse
	 */
	private function response(int $status): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);

		return $response;
	}//end response()
}//end class
