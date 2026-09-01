<?php

/**
 * GenericInitializeSettingsCredentialTest — the D-G no-code onboarding hook.
 *
 * Pins: an AppHost leaf whose bundled `src/manifest.json` declares a non-empty
 * `credentials[]` is registered with the credential broker exactly once on
 * initialisation; a subsequent run detects the existing registration and NEVER
 * rotates the signing secret; leaves without a manifest or without a
 * `credentials[]` declaration are never registered.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/credential-doriath-leaf/specs/credential-broker/spec.md#manifest-driven-credential-app-onboarding
 */

declare(strict_types=1);

namespace Unit\AppHost;

use OCA\OpenRegister\AppHost\Repair\GenericInitializeSettings;
use OCA\OpenRegister\AppHost\Service\AppHostSettingsService;
use OCA\OpenRegister\Service\Credential\CredentialAppTokenService;
use OCA\OpenRegister\Service\Credential\DoriathApplicationRegistrar;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GenericInitializeSettingsCredentialTest extends TestCase {
	private const APP_ID = 'petstore';

	private string $appDir = '';

	protected function setUp(): void {
		$this->appDir = sys_get_temp_dir() . '/or-dg-test-' . bin2hex(random_bytes(6));
		mkdir($this->appDir . '/src', 0777, true);
	}

	protected function tearDown(): void {
		if ($this->appDir !== '' && is_dir($this->appDir) === true) {
			@unlink($this->appDir . '/src/manifest.json');
			@rmdir($this->appDir . '/src');
			@rmdir($this->appDir);
		}
	}

	/**
	 * Happy path: manifest declares credentials[] and the app is unregistered →
	 * register the OR app-key once AND register the per-app Doriath application
	 * (name = appId, description from the manifest).
	 */
	public function testRegistersManifestCredentialConsumerOnce(): void {
		$this->writeManifest(['description' => 'Pet Store demo', 'credentials' => [['provider' => 'doffin']]]);

		$tokenService = $this->createMock(CredentialAppTokenService::class);
		$tokenService->method('isRegistered')->with(self::APP_ID)->willReturn(false);
		$tokenService->expects($this->once())->method('registerApp')
			->with(self::APP_ID)
			->willReturn('placeholder-secret-never-used');

		$registrar = $this->createMock(DoriathApplicationRegistrar::class);
		$registrar->expects($this->once())->method('registerApplication')
			->with(self::APP_ID, 'Pet Store demo');

		$this->runStep(tokenService: $tokenService, registrar: $registrar);
	}

	/**
	 * Error-prevention path (D-G guard): an already-registered app is NEVER
	 * rotated by an auto-run — BUT the independent per-app Doriath identity
	 * registration still runs (its own live-row probe keeps it idempotent).
	 */
	public function testDoesNotRotateExistingRegistration(): void {
		$this->writeManifest(['credentials' => [['provider' => 'doffin']]]);

		$tokenService = $this->createMock(CredentialAppTokenService::class);
		$tokenService->method('isRegistered')->with(self::APP_ID)->willReturn(true);
		$tokenService->expects($this->never())->method('registerApp');

		$registrar = $this->createMock(DoriathApplicationRegistrar::class);
		$registrar->expects($this->once())->method('registerApplication')
			->with(self::APP_ID, null);

		$this->runStep(tokenService: $tokenService, registrar: $registrar);
	}

	/**
	 * Edge: a manifest without credentials[] (or with an empty array) triggers
	 * neither app-key registration NOR per-app Doriath registration.
	 */
	public function testNoCredentialsDeclarationMeansNoRegistration(): void {
		$this->writeManifest(['name' => 'Pet Store', 'credentials' => []]);

		$tokenService = $this->createMock(CredentialAppTokenService::class);
		$tokenService->expects($this->never())->method('registerApp');

		$registrar = $this->createMock(DoriathApplicationRegistrar::class);
		$registrar->expects($this->never())->method('registerApplication');

		$this->runStep(tokenService: $tokenService, registrar: $registrar);
	}

	/**
	 * Edge: a leaf without a bundled manifest triggers no registration (and no failure).
	 */
	public function testMissingManifestMeansNoRegistration(): void {
		$tokenService = $this->createMock(CredentialAppTokenService::class);
		$tokenService->expects($this->never())->method('registerApp');

		$registrar = $this->createMock(DoriathApplicationRegistrar::class);
		$registrar->expects($this->never())->method('registerApplication');

		$this->runStep(tokenService: $tokenService, registrar: $registrar);
	}

	/**
	 * Write the leaf's bundled src/manifest.json fixture.
	 *
	 * @param array<string, mixed> $manifest Manifest payload.
	 */
	private function writeManifest(array $manifest): void {
		file_put_contents($this->appDir . '/src/manifest.json', json_encode($manifest));
	}

	/**
	 * Run the repair step with the D-G collaborators injected.
	 *
	 * @param CredentialAppTokenService $tokenService The (mock) broker app registry.
	 * @param DoriathApplicationRegistrar|null $registrar The (mock) per-app Doriath registrar.
	 */
	private function runStep(CredentialAppTokenService $tokenService, ?DoriathApplicationRegistrar $registrar = null): void {
		$settingsService = $this->createMock(AppHostSettingsService::class);
		$settingsService->method('isOpenRegisterAvailable')->willReturn(true);
		$settingsService->method('loadConfiguration')->willReturn(['success' => true, 'version' => '1.0.0']);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with(self::APP_ID)->willReturn($this->appDir);

		$step = new GenericInitializeSettings(
			self::APP_ID,
			$settingsService,
			$this->createMock(LoggerInterface::class),
			$appManager,
			$tokenService,
			($registrar ?? $this->createMock(DoriathApplicationRegistrar::class))
		);

		$step->run($this->createMock(IOutput::class));
	}
}
