<?php

declare(strict_types=1);

/*
 * Tests for ChatHealthController.
 *
 * Covers the three response shapes per the orchestrator spec:
 * 200 + {status:ok, capabilities:[chat, stream]} when a chat provider
 * is configured; 503 + {status:no_provider} when not; 503 +
 * {status:config_error} when reading settings throws.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/ai-chat-companion-orchestrator/tasks.md#4
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ChatHealthController;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ChatHealthControllerTest extends TestCase {

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function makeController(): ChatHealthController {
		return new ChatHealthController(
			appName: 'openregister',
			request: $this->request,
			settingsService: $this->settingsService,
			logger: $this->logger
		);
	}//end makeController()

	/**
	 * §9.3a — configured provider returns 200 + capabilities.
	 */
	public function testHealthReturns200WhenChatProviderConfigured(): void {
		$this->settingsService->method('getLLMSettingsOnly')
			->willReturn(['chatProvider' => 'openai']);

		$response = $this->makeController()->health();
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertSame('ok', $data['status']);
		$this->assertContains('chat', $data['capabilities']);
		$this->assertContains('stream', $data['capabilities']);
	}//end testHealthReturns200WhenChatProviderConfigured()

	/**
	 * §9.3b — no provider returns 503 + {status:no_provider}.
	 */
	public function testHealthReturns503WhenProviderMissing(): void {
		$this->settingsService->method('getLLMSettingsOnly')
			->willReturn(['chatProvider' => null]);

		$response = $this->makeController()->health();
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('no_provider', $response->getData()['status']);
	}//end testHealthReturns503WhenProviderMissing()

	/**
	 * Empty-string chatProvider is treated identically to null/missing.
	 */
	public function testHealthReturns503WhenProviderIsEmptyString(): void {
		$this->settingsService->method('getLLMSettingsOnly')
			->willReturn(['chatProvider' => '']);

		$response = $this->makeController()->health();
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('no_provider', $response->getData()['status']);
	}//end testHealthReturns503WhenProviderIsEmptyString()

	/**
	 * Settings service missing the key entirely is also treated as unconfigured.
	 */
	public function testHealthReturns503WhenChatProviderKeyAbsent(): void {
		$this->settingsService->method('getLLMSettingsOnly')
			->willReturn([]);

		$response = $this->makeController()->health();
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('no_provider', $response->getData()['status']);
	}//end testHealthReturns503WhenChatProviderKeyAbsent()

	/**
	 * Settings service throwing returns 503 + config_error and logs a warning
	 * — distinguishes a broken config service from a fresh unconfigured one.
	 */
	public function testHealthReturns503ConfigErrorWhenSettingsServiceThrows(): void {
		$this->settingsService->method('getLLMSettingsOnly')
			->willThrowException(new RuntimeException('database is down'));

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('Health probe failed'),
				$this->callback(fn ($ctx) => isset($ctx['error']) && $ctx['error'] === 'database is down')
			);

		$response = $this->makeController()->health();
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('config_error', $response->getData()['status']);
	}//end testHealthReturns503ConfigErrorWhenSettingsServiceThrows()
}//end class
