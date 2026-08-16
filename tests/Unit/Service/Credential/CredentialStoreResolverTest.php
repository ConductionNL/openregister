<?php

/**
 * CredentialStoreResolverTest — the D-A eligibility matrix.
 *
 * Pins the resolver's fail-closed backend selection: the Doriath leaf is
 * returned ONLY when the doriath app is enabled AND every probed service class
 * exists AND the application-scoped seam methods exist AND OR's
 * self-registration state is persisted; every other combination yields the
 * Nextcloud-vault leaf. Probes run through the protected class-map seams
 * against environment-independent fixtures (tests/stubs/DoriathStubs.php).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
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
 * @spec openspec/changes/credential-doriath-leaf/specs/credential-broker/spec.md#credential-store-backend-resolution
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\CredentialStoreResolver;
use OCA\OpenRegister\Service\Credential\DoriathCredentialStore;
use OCA\OpenRegister\Service\Credential\NextcloudVaultCredentialStore;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CredentialStoreResolverTest extends TestCase {
	private const FIXTURE_NS = 'OCA\\OpenRegister\\Tests\\Fixtures\\Doriath\\';

	private DoriathCredentialStore $doriathStore;

	private NextcloudVaultCredentialStore $vaultStore;

	protected function setUp(): void {
		$this->doriathStore = $this->createMock(DoriathCredentialStore::class);
		$this->vaultStore = $this->createMock(NextcloudVaultCredentialStore::class);
	}

	/**
	 * Happy path: enabled + classes + seam methods + registration state → Doriath leaf.
	 */
	public function testEligibleSelectsDoriathStore(): void {
		$resolver = $this->makeResolver(
			doriathEnabled: true,
			registered: true,
			secretServiceClass: self::FIXTURE_NS . 'FakeSecretService'
		);

		$this->assertTrue($resolver->isDoriathEligible());
		$this->assertSame($this->doriathStore, $resolver->resolve());
	}

	/**
	 * Error path: doriath app disabled → vault leaf, nothing else probed.
	 */
	public function testDisabledAppFallsBackToVault(): void {
		$resolver = $this->makeResolver(
			doriathEnabled: false,
			registered: true,
			secretServiceClass: self::FIXTURE_NS . 'FakeSecretService'
		);

		$this->assertFalse($resolver->isDoriathEligible());
		$this->assertSame($this->vaultStore, $resolver->resolve());
	}

	/**
	 * Error path: a probed service class is missing → vault leaf.
	 */
	public function testMissingServiceClassFallsBackToVault(): void {
		$resolver = $this->makeResolver(
			doriathEnabled: true,
			registered: true,
			secretServiceClass: self::FIXTURE_NS . 'FakeSecretService',
			serviceClasses: [self::FIXTURE_NS . 'ThisFixtureDoesNotExist']
		);

		$this->assertSame($this->vaultStore, $resolver->resolve());
	}

	/**
	 * Edge (cross-repo rollout order): classes exist but the application-scoped
	 * seam methods have not landed yet → vault leaf, not a broken Doriath leaf.
	 */
	public function testMissingSeamMethodsFallBackToVault(): void {
		$resolver = $this->makeResolver(
			doriathEnabled: true,
			registered: true,
			secretServiceClass: self::FIXTURE_NS . 'FakeLegacySecretService'
		);

		$this->assertSame($this->vaultStore, $resolver->resolve());
	}

	/**
	 * Edge: everything present but OR never self-registered → vault leaf.
	 */
	public function testUnregisteredFallsBackToVault(): void {
		$resolver = $this->makeResolver(
			doriathEnabled: true,
			registered: false,
			secretServiceClass: self::FIXTURE_NS . 'FakeSecretService'
		);

		$this->assertSame($this->vaultStore, $resolver->resolve());
	}

	/**
	 * Build a resolver whose class probes point at the test fixtures.
	 *
	 * @param bool $doriathEnabled Whether IAppManager reports doriath enabled.
	 * @param bool $registered Whether IAppConfig carries the self-registration state.
	 * @param string $secretServiceClass FQCN probed for the seam methods.
	 * @param array<int, string> $serviceClasses FQCNs probed via class_exists (defaults to existing fixtures).
	 */
	private function makeResolver(
		bool $doriathEnabled,
		bool $registered,
		string $secretServiceClass,
		?array $serviceClasses = null,
	): CredentialStoreResolver {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->with('doriath')->willReturn($doriathEnabled);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($registered): string {
				if ($registered === false) {
					return '';
				}

				if ($key === DoriathCredentialStore::APP_CONFIG_APPLICATION_ID) {
					return '00000000-0000-0000-0000-000000000000';
				}

				if ($key === DoriathCredentialStore::APP_CONFIG_PUBLIC_KEY_PEM) {
					return '<public-key-pem>';
				}

				return $default;
			}
		);

		$classes = ($serviceClasses ?? [
			self::FIXTURE_NS . 'FakeApplicationService',
			self::FIXTURE_NS . 'FakeSecretService',
			self::FIXTURE_NS . 'FakeEncryptService',
			self::FIXTURE_NS . 'FakeDecryptService',
		]);

		return new class($appManager, $appConfig, $this->doriathStore, $this->vaultStore, $this->createMock(LoggerInterface::class), $classes, $secretServiceClass) extends CredentialStoreResolver {
			/**
			 * @param array<int, string> $probedClasses Probed service FQCNs.
			 */
			public function __construct(
				IAppManager $appManager,
				IAppConfig $appConfig,
				DoriathCredentialStore $doriathStore,
				NextcloudVaultCredentialStore $vaultStore,
				LoggerInterface $logger,
				private readonly array $probedClasses,
				private readonly string $probedSecretService,
			) {
				parent::__construct($appManager, $appConfig, $doriathStore, $vaultStore, $logger);
			}

			protected function doriathServiceClasses(): array {
				return $this->probedClasses;
			}

			protected function secretServiceClass(): string {
				return $this->probedSecretService;
			}
		};
	}
}
