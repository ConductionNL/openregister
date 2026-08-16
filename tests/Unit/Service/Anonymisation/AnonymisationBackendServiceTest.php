<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService}.
 *
 * Covers the effectiveMethod precedence rule, first-run auto-select, ExApp
 * detection via IAppManager, and probe caching. HTTP-based Presidio probing is
 * exercised only via the not-configured branch; reachable-backend assertions use
 * the AppAPI-backed openanonymiser path so no HTTP client mocking is required.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Anonymisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymiser-backend-selection/tasks.md
 */

declare(strict_types=1);

namespace Unit\Service\Anonymisation;

use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService;
use OCA\OpenRegister\Service\Anonymisation\BackendState;
use OCA\OpenRegister\Service\Anonymisation\ProbeResult;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * AnonymisationBackendServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AnonymisationBackendServiceTest extends TestCase {
	private IAppManager&MockObject $appManager;
	private IAppConfig&MockObject $appConfig;
	private ICacheFactory&MockObject $cacheFactory;
	private ICache&MockObject $cache;
	private IClientService&MockObject $clientService;
	private FileSettingsHandler&MockObject $fileSettingsHandler;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cache = $this->createMock(ICache::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->fileSettingsHandler = $this->getMockBuilder(FileSettingsHandler::class)
			->disableOriginalConstructor()
			->onlyMethods(['getFileSettingsOnly'])
			->getMock();

		$this->cacheFactory->method('createDistributed')->willReturn($this->cache);
		$this->appConfig->method('getValueInt')->willReturn(60);

		// Default: cache miss so probes run fresh.
		$this->cache->method('get')->willReturn(null);
	}

	/**
	 * Build the service with the configured mocks.
	 */
	private function makeService(): AnonymisationBackendService {
		return new AnonymisationBackendService(
			$this->appManager,
			$this->appConfig,
			$this->cacheFactory,
			$this->clientService,
			$this->fileSettingsHandler,
			$this->logger
		);
	}

	/**
	 * Stub the stored file settings blob.
	 *
	 * @param array<string, mixed> $overrides Settings to merge over the defaults.
	 */
	private function withSettings(array $overrides = []): void {
		$defaults = [
			'entityRecognitionEnabled' => true,
			'entityRecognitionMethod' => BackendState::METHOD_AUTO,
			'presidioApiEndpoint' => '',
		];
		$this->fileSettingsHandler->method('getFileSettingsOnly')->willReturn(array_merge($defaults, $overrides));
	}

	/**
	 * Drive IAppManager so OpenAnonymiser is detected as enabled.
	 */
	private function exAppEnabled(bool $full = true, bool $light = false): void {
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
	}

	public function testEffectiveMethodIsRegexWhenDisabled(): void {
		$this->withSettings(['entityRecognitionEnabled' => false, 'entityRecognitionMethod' => BackendState::METHOD_HYBRID]);
		$this->exAppEnabled();

		$state = $this->makeService()->getState();

		$this->assertFalse($state->entityRecognitionEnabled);
		$this->assertSame(BackendState::METHOD_REGEX, $state->effectiveMethod);
	}

	public function testActiveRegexResolvesToRegex(): void {
		$this->withSettings(['entityRecognitionMethod' => BackendState::METHOD_REGEX]);
		$this->exAppEnabled();

		$state = $this->makeService()->getState();

		$this->assertSame(BackendState::METHOD_REGEX, $state->activeMethod);
		$this->assertSame(BackendState::METHOD_REGEX, $state->effectiveMethod);
	}

	public function testAvailableBackendPassesThrough(): void {
		$this->withSettings(['entityRecognitionMethod' => BackendState::METHOD_OPENANONYMISER]);
		$this->exAppEnabled();

		$state = $this->makeService()->getState();

		$this->assertSame(BackendState::METHOD_OPENANONYMISER, $state->activeMethod);
		$this->assertSame(BackendState::METHOD_OPENANONYMISER, $state->effectiveMethod);
		$this->assertTrue($state->backends[BackendState::METHOD_OPENANONYMISER]->available);
	}

	public function testUnreachableBackendFallsThroughToRegex(): void {
		// Presidio selected but no endpoint configured => not available.
		$this->withSettings(['entityRecognitionMethod' => BackendState::METHOD_PRESIDIO, 'presidioApiEndpoint' => '']);
		$this->exAppEnabled();

		$state = $this->makeService()->getState();

		// Operator intent preserved in activeMethod, but effective falls through.
		$this->assertSame(BackendState::METHOD_PRESIDIO, $state->activeMethod);
		$this->assertSame(BackendState::METHOD_REGEX, $state->effectiveMethod);
	}

	public function testHybridDegradesWhenComposedBackendUnavailable(): void {
		// Hybrid needs presidio; with no presidio endpoint, hybrid is unavailable.
		$this->withSettings(['entityRecognitionMethod' => BackendState::METHOD_HYBRID, 'presidioApiEndpoint' => '']);
		$this->exAppEnabled();

		$state = $this->makeService()->getState();

		$this->assertFalse($state->backends[BackendState::METHOD_HYBRID]->available);
		$this->assertSame(BackendState::METHOD_REGEX, $state->effectiveMethod);
	}

	public function testAutoResolvesToOpenAnonymiserWhenDetected(): void {
		$this->withSettings(['entityRecognitionMethod' => BackendState::METHOD_AUTO]);
		$this->exAppEnabled();

		$state = $this->makeService()->getState();

		$this->assertSame(BackendState::METHOD_OPENANONYMISER, $state->activeMethod);
		$this->assertSame(BackendState::METHOD_OPENANONYMISER, $state->effectiveMethod);
	}

	public function testAutoFallsBackToRegexWhenNoExApp(): void {
		$this->withSettings(['entityRecognitionMethod' => BackendState::METHOD_AUTO]);
		// AppAPI present but no ExApp enabled or installed.
		$this->appManager->method('isEnabledForUser')->willReturnMap([
			['app_api', null, true],
			['openanonymiser', null, false],
			['openanonymiser_light', null, false],
		]);
		$this->appManager->method('isInstalled')->willReturn(false);

		$state = $this->makeService()->getState();

		$this->assertSame(BackendState::METHOD_REGEX, $state->activeMethod);
		$this->assertSame(BackendState::METHOD_REGEX, $state->effectiveMethod);
	}

	public function testLegacyStoredMethodPreserved(): void {
		// A legacy install with a concrete stored method keeps it (no auto resolution).
		$this->withSettings(['entityRecognitionMethod' => BackendState::METHOD_OPENANONYMISER]);
		$this->exAppEnabled();

		$state = $this->makeService()->getState();

		$this->assertSame(BackendState::METHOD_OPENANONYMISER, $state->activeMethod);
	}

	public function testDetectionExAppNotInstalled(): void {
		$this->withSettings();
		$this->appManager->method('isEnabledForUser')->willReturnMap([
			['app_api', null, true],
			['openanonymiser', null, false],
			['openanonymiser_light', null, false],
		]);
		$this->appManager->method('isInstalled')->willReturn(false);

		$probe = $this->makeService()->testConnection(BackendState::METHOD_OPENANONYMISER);

		$this->assertFalse($probe->reachable);
		$this->assertSame(ProbeResult::ERROR_EXAPP_NOT_INSTALLED, $probe->error);
	}

	public function testDetectionExAppDisabled(): void {
		$this->withSettings();
		$this->appManager->method('isEnabledForUser')->willReturnMap([
			['app_api', null, true],
			['openanonymiser', null, false],
			['openanonymiser_light', null, false],
		]);
		// Installed on disk but not enabled => disabled.
		$this->appManager->method('isInstalled')->willReturnMap([
			['openanonymiser', true],
			['openanonymiser_light', false],
		]);

		$probe = $this->makeService()->testConnection(BackendState::METHOD_OPENANONYMISER);

		$this->assertSame(ProbeResult::ERROR_EXAPP_DISABLED, $probe->error);
	}

	public function testDetectionAppApiMissing(): void {
		$this->withSettings();
		$this->appManager->method('isEnabledForUser')->willReturnMap([
			['app_api', null, false],
		]);

		$probe = $this->makeService()->testConnection(BackendState::METHOD_OPENANONYMISER);

		$this->assertFalse($probe->reachable);
		$this->assertSame(ProbeResult::ERROR_APPAPI_MISSING, $probe->error);
	}

	public function testFullVariantPreferredOverLight(): void {
		$this->withSettings();
		// Both enabled; detection must still report reachable (full preferred).
		$this->exAppEnabled(full: true, light: true);

		$probe = $this->makeService()->testConnection(BackendState::METHOD_OPENANONYMISER);

		$this->assertTrue($probe->reachable);
		$this->assertNull($probe->error);
	}

	public function testCachedResultIsReusedWithinTtl(): void {
		$this->withSettings();
		// AppAPI missing would normally yield appapi_missing, but a cached hit must win.
		$this->appManager->method('isEnabledForUser')->willReturn(false);

		$cached = json_encode([
			'reachable' => true,
			'latencyMs' => 12,
			'error' => null,
			'probedAt' => '2026-06-15T00:00:00+00:00',
		]);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->with(BackendState::METHOD_OPENANONYMISER)->willReturn($cached);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$service = new AnonymisationBackendService(
			$this->appManager,
			$this->appConfig,
			$cacheFactory,
			$this->clientService,
			$this->fileSettingsHandler,
			$this->logger
		);

		$probe = $service->probe(BackendState::METHOD_OPENANONYMISER);

		$this->assertTrue($probe->reachable);
		$this->assertSame(12, $probe->latencyMs);
	}

	public function testExpiredCacheTriggersFreshProbeAndWriteBack(): void {
		$this->withSettings();
		$this->exAppEnabled();

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		// Fresh probe result MUST be written back.
		$cache->expects($this->atLeastOnce())->method('set');
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$service = new AnonymisationBackendService(
			$this->appManager,
			$this->appConfig,
			$cacheFactory,
			$this->clientService,
			$this->fileSettingsHandler,
			$this->logger
		);

		$probe = $service->probe(BackendState::METHOD_OPENANONYMISER);

		$this->assertTrue($probe->reachable);
	}

	public function testTestConnectionBypassesCache(): void {
		$this->withSettings();
		$this->exAppEnabled();

		$cached = json_encode([
			'reachable' => false,
			'latencyMs' => null,
			'error' => ProbeResult::ERROR_APPAPI_MISSING,
			'probedAt' => '2026-06-15T00:00:00+00:00',
		]);

		$cache = $this->createMock(ICache::class);
		// Even with a stale cached failure, testConnection must probe fresh.
		$cache->method('get')->willReturn($cached);
		$cache->expects($this->atLeastOnce())->method('set');
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$service = new AnonymisationBackendService(
			$this->appManager,
			$this->appConfig,
			$cacheFactory,
			$this->clientService,
			$this->fileSettingsHandler,
			$this->logger
		);

		$probe = $service->testConnection(BackendState::METHOD_OPENANONYMISER);

		$this->assertTrue($probe->reachable);
		$this->assertNull($probe->error);
	}
}
