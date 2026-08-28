<?php

/**
 * RegisterOpenRegisterWithDoriath — idempotent Doriath self-registration (D-B).
 *
 * Registers OpenRegister as a first-class Doriath application so the
 * credential broker's Doriath custody leaf can hold brokered secrets as
 * application-owned ciphertext (credential-doriath-leaf design D-B/D-C). On an
 * instance with an eligible Doriath and no prior registration the step:
 * generates an RSA-4096 keypair (openssl); stores the PRIVATE key SYSTEM-scoped
 * in `ICredentialsManager` (the `CredentialAppTokenService` idiom — the key
 * never lands in `IAppConfig`, an OR object, or a log); self-generates a
 * PKCS#10 CSR (CN `openregister`); and calls Doriath's
 * `ApplicationService::register` IN-PROCESS with `isAdmin: true`, so the
 * registration auto-approves and Doriath provisions the EncryptionSuite from
 * the CSR in the same call (Doriath validates PKCS#10 + >=4096-bit keys).
 * Doriath assigns the application row's UUID; the step persists that UUID and
 * OR's public key PEM (public material only) in `IAppConfig`.
 *
 * NEVER throws (the `ImportCredentialBrokerRegister` convention): when Doriath
 * is absent/disabled or anything fails, the step warns and completes — the
 * credential broker keeps operating on the Nextcloud-vault leaf. Re-runs skip
 * fast when the persisted application UUID still matches a live Doriath row,
 * making the step safe on every `occ upgrade` (install + post-migration).
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Credential\DoriathCredentialStore;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Security\ICredentialsManager;
use OCP\Server;
use OpenSSLAsymmetricKey;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Registers OpenRegister with Doriath (keypair + CSR + EncryptionSuite), idempotently.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) Doriath's ApplicationService is lazily
 *   resolved via `OCP\Server::get` behind a `class_exists` guard so OpenRegister
 *   carries no compile-time dependency on the optional app (design D-A/D-B).
 */
class RegisterOpenRegisterWithDoriath implements IRepairStep {
	/**
	 * FQCN of Doriath's application service (registration seam).
	 *
	 * @var string
	 */
	private const APPLICATION_SERVICE = 'OCA\\Doriath\\Service\\ApplicationService';

	/**
	 * RSA modulus size for the generated keypair (Doriath requires >= 4096).
	 *
	 * @var int
	 */
	private const KEY_BITS = 4096;

	/**
	 * The application NAME OpenRegister registers under (Doriath assigns the UUID).
	 *
	 * @var string
	 */
	private const APPLICATION_NAME = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Probes whether the doriath app is enabled.
	 * @param IAppConfig $appConfig Persists the application UUID + public key PEM.
	 * @param ICredentialsManager $credentialsManager Holds OR's private key (system-scoped).
	 * @param LoggerInterface $logger Secret-free diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IAppConfig $appConfig,
		private readonly ICredentialsManager $credentialsManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function getName(): string {
		return 'Register OpenRegister as a Doriath application (credential-broker Doriath custody leaf)';
	}//end getName()

	/**
	 * Run the self-registration, degrading (warn, never throw) on any failure.
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			if ($this->isDoriathAvailable() === false) {
				$output->warning(
					'Doriath is not installed or enabled — credential broker stays on the Nextcloud-vault leaf.'
				);
				return;
			}

			if ($this->isRegistrationLive() === true) {
				$output->info('OpenRegister is already registered with Doriath — nothing to do.');
				return;
			}

			$this->register(output: $output);
		} catch (Throwable $e) {
			$this->logger->warning('[RegisterOpenRegisterWithDoriath] self-registration failed: ' . $e->getMessage());
			$output->warning('Doriath self-registration skipped: ' . $e->getMessage());
		}
	}//end run()

	/**
	 * Whether the doriath app is enabled and its registration seam is loadable.
	 *
	 * Deliberately narrower than the resolver's full eligibility chain: the
	 * repair step only needs `ApplicationService`; the seam methods that gate
	 * the STORE selection are probed by `CredentialStoreResolver` instead, so
	 * registration can happen before Doriath's `application-secret-delete`
	 * change lands (cross-repo merge order stays free).
	 *
	 * @return bool True when self-registration can be attempted.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	private function isDoriathAvailable(): bool {
		if ($this->appManager->isEnabledForUser('doriath') === false) {
			return false;
		}

		return ($this->resolveApplicationService() !== null);
	}//end isDoriathAvailable()

	/**
	 * Whether a previous registration is still live in Doriath (skip-fast probe).
	 *
	 * True only when `IAppConfig` holds an application UUID AND Doriath still
	 * has that row. A stale UUID (Doriath reinstalled/row removed) yields
	 * false, so the step re-registers with a fresh keypair.
	 *
	 * @return bool True when already registered.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	private function isRegistrationLive(): bool {
		$applicationId = $this->appConfig->getValueString(
			'openregister',
			DoriathCredentialStore::APP_CONFIG_APPLICATION_ID,
			''
		);
		if ($applicationId === '') {
			return false;
		}

		$applicationService = $this->resolveApplicationService();
		if ($applicationService === null) {
			return false;
		}

		try {
			// Admin-scoped in-process read; throws when the row no longer exists.
			$applicationService->get($applicationId, '', true);
			return true;
		} catch (Throwable $e) {
			$this->logger->info(
				'[RegisterOpenRegisterWithDoriath] persisted application UUID is stale — re-registering',
				['error' => $e->getMessage()]
			);
			return false;
		}
	}//end isRegistrationLive()

	/**
	 * Perform the actual keypair + CSR + register + persist sequence (D-B).
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When openssl key/CSR generation fails (caught by {@see run()}).
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	private function register(IOutput $output): void {
		[$privatePem, $publicPem, $keyResource] = $this->generateKeypair();

		// SYSTEM scope (userId = '') — the CredentialAppTokenService idiom. The
		// private key exists ONLY here; IAppConfig gets public material only.
		$this->credentialsManager->store('', DoriathCredentialStore::PRIVATE_KEY_ID, $privatePem);

		$csrPem = $this->generateCsr(keyResource: $keyResource);

		$applicationService = $this->resolveApplicationService();
		if ($applicationService === null) {
			throw new RuntimeException('Doriath ApplicationService unavailable');
		}

		// Admin registration auto-approves the row and provisions the
		// EncryptionSuite from the CSR in the same call. userId is null: the
		// repair step runs without a session; Doriath audits a system actor.
		$application = $applicationService->register(
			self::APPLICATION_NAME,
			'OpenRegister credential-broker custody vault (self-registered)',
			'internal',
			$csrPem,
			null,
			true
		);

		// Doriath generates the application row UUID — persist it + our public PEM.
		$applicationId = (string)$application->getId();
		$this->appConfig->setValueString(
			'openregister',
			DoriathCredentialStore::APP_CONFIG_APPLICATION_ID,
			$applicationId
		);
		$this->appConfig->setValueString(
			'openregister',
			DoriathCredentialStore::APP_CONFIG_PUBLIC_KEY_PEM,
			$publicPem
		);

		$output->info('OpenRegister registered with Doriath (application ' . $applicationId . ', EncryptionSuite provisioned).');
	}//end register()

	/**
	 * Generate the RSA-4096 keypair, returning both PEMs and the key resource.
	 *
	 * @return array{0: string, 1: string, 2: OpenSSLAsymmetricKey} Private PEM, public PEM, key resource.
	 *
	 * @throws RuntimeException When openssl generation/export fails.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	private function generateKeypair(): array {
		$keyResource = openssl_pkey_new(
			[
				'private_key_bits' => self::KEY_BITS,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);
		if ($keyResource === false) {
			throw new RuntimeException('RSA keypair generation failed');
		}

		$privatePem = '';
		if (openssl_pkey_export($keyResource, $privatePem) === false || is_string($privatePem) === false) {
			throw new RuntimeException('RSA private key export failed');
		}

		$details = openssl_pkey_get_details($keyResource);
		if (is_array($details) === false || is_string(($details['key'] ?? null)) === false) {
			throw new RuntimeException('RSA public key export failed');
		}

		return [$privatePem, $details['key'], $keyResource];
	}//end generateKeypair()

	/**
	 * Self-generate a PKCS#10 CSR (CN `openregister`) from the keypair.
	 *
	 * @param OpenSSLAsymmetricKey $keyResource The generated private key resource.
	 *
	 * @return string The CSR PEM.
	 *
	 * @throws RuntimeException When CSR generation/export fails.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	private function generateCsr(OpenSSLAsymmetricKey $keyResource): string {
		$csr = openssl_csr_new(
			['commonName' => self::APPLICATION_NAME],
			$keyResource,
			['digest_alg' => 'sha256']
		);
		if ($csr === false) {
			throw new RuntimeException('CSR generation failed');
		}

		$csrPem = '';
		if (openssl_csr_export($csr, $csrPem) === false || is_string($csrPem) === false || $csrPem === '') {
			throw new RuntimeException('CSR export failed');
		}

		return $csrPem;
	}//end generateCsr()

	/**
	 * Resolve Doriath's ApplicationService, or null when unavailable.
	 *
	 * `class_exists` + `OCP\Server::get` (no compile-time Doriath dependency).
	 * Protected so unit tests can substitute a contract fake.
	 *
	 * @return object|null The resolved service, or null.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	protected function resolveApplicationService(): ?object {
		if (class_exists(self::APPLICATION_SERVICE) === false) {
			return null;
		}

		try {
			return Server::get(self::APPLICATION_SERVICE);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[RegisterOpenRegisterWithDoriath] failed to resolve Doriath ApplicationService',
				['error' => $e->getMessage()]
			);
			return null;
		}
	}//end resolveApplicationService()
}//end class
