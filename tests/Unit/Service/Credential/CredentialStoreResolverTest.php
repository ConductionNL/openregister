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
	 * The probed class list never mixes namespaces.
	 *
	 * This is the invariant the namespace resolution exists for. Eligibility
	 * requires ALL FOUR services, so a per-class fallback could assemble a set
	 * spanning both spellings — a credential store that exists on paper and
	 * fails on the first call. The set must come from ONE namespace.
	 */
	public function testProbedClassesAllShareOneNamespace(): void {
		$resolver = new CredentialStoreResolver(
			$this->createMock(IAppManager::class),
			$this->createMock(IAppConfig::class),
			$this->doriathStore,
			$this->vaultStore,
			$this->createMock(LoggerInterface::class),
		);

		$classes = $this->readProbedClasses($resolver);

		$this->assertCount(4, $classes);
		$namespaces = array_unique(
			array_map(static fn (string $fqcn): string => substr($fqcn, 0, (int)strrpos($fqcn, '\\')), $classes)
		);
		$this->assertCount(1, $namespaces, 'A half-resolved set spanning both spellings would fail on first use.');
	}

	/**
	 * The secret-service probe uses the SAME namespace as the class list.
	 *
	 * Aimed at a different namespace, the seam-method probe would be asking
	 * about a class the eligibility check never approved.
	 */
	public function testSecretServiceShareTheResolvedNamespace(): void {
		$resolver = new CredentialStoreResolver(
			$this->createMock(IAppManager::class),
			$this->createMock(IAppConfig::class),
			$this->doriathStore,
			$this->vaultStore,
			$this->createMock(LoggerInterface::class),
		);

		$classes = $this->readProbedClasses($resolver);
		$secret = $this->readSecretServiceClass($resolver);

		$this->assertContains($secret, $classes);
	}

	/**
	 * The resolved namespace is one of the declared candidates, newest first.
	 *
	 * The unit runtime loads DoriathStubs.php, which defines a COMPLETE
	 * `OCA\Doriath\Service\*` set — so the resolver legitimately selects it here,
	 * and that is the behaviour under test: pick the first candidate whose whole
	 * set is present. What must hold regardless of which app is installed is
	 * that the choice comes from the declared list and that the current
	 * namespace is tried BEFORE the legacy one.
	 */
	public function testTheResolvedNamespaceComesFromTheDeclaredCandidates(): void {
		$resolver = new CredentialStoreResolver(
			$this->createMock(IAppManager::class),
			$this->createMock(IAppConfig::class),
			$this->doriathStore,
			$this->vaultStore,
			$this->createMock(LoggerInterface::class),
		);

		// getReflectionConstant(), not getConstant(): the candidate list is
		// PRIVATE, and getConstant() answers null for a private constant — a null
		// that reads exactly like "the constant does not exist".
		$constant = (new \ReflectionClass(CredentialStoreResolver::class))
			->getReflectionConstant('CREDENTIAL_APP_NAMESPACES');
		$this->assertNotFalse($constant, 'CREDENTIAL_APP_NAMESPACES must exist.');
		$candidates = $constant->getValue();

		$this->assertStringContainsString('Keepiq', $candidates[0], 'The current namespace must be tried first.');
		$this->assertStringContainsString('Doriath', implode(' ', $candidates), 'The legacy namespace must stay listed.');

		$chosen = $this->readProbedClasses($resolver)[0];
		$this->assertTrue(
			array_reduce(
				$candidates,
				static fn (bool $carry, string $ns): bool => ($carry || str_starts_with($chosen, $ns)),
				false
			),
			'The resolved namespace must be one of the declared candidates, not an invented one.'
		);
	}

	/**
	 * Read the protected class-list accessor.
	 *
	 * @param CredentialStoreResolver $resolver The resolver.
	 *
	 * @return array<int, string> The probed FQCNs.
	 */
	private function readProbedClasses(CredentialStoreResolver $resolver): array {
		$method = new \ReflectionMethod($resolver, 'doriathServiceClasses');
		$method->setAccessible(true);

		return $method->invoke($resolver);
	}

	/**
	 * Read the protected secret-service accessor.
	 *
	 * @param CredentialStoreResolver $resolver The resolver.
	 *
	 * @return string The probed FQCN.
	 */
	private function readSecretServiceClass(CredentialStoreResolver $resolver): string {
		$method = new \ReflectionMethod($resolver, 'secretServiceClass');
		$method->setAccessible(true);

		return $method->invoke($resolver);
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
