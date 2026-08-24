<?php

/**
 * CredentialStoreResolver — runtime backend selection for the credential store.
 *
 * Single source of truth for "which {@see CredentialStore} leaf is active"
 * (credential-doriath-leaf design D-A), mirroring the backend-selection role of
 * `AnonymisationBackendService` combined with the Deck-leaf availability idiom
 * of `DeckLinkService`. Doriath is ELIGIBLE only when ALL of the following
 * hold, evaluated in order and failing closed to the Nextcloud-vault leaf:
 *
 *   1. `IAppManager::isEnabledForUser('doriath')` — the app is enabled;
 *   2. `class_exists` succeeds for every Doriath service class the leaf calls
 *      (no compile-time dependency on the optional app);
 *   3. `method_exists` succeeds for the application-scoped seam methods
 *      (`getByNameForApplication`, `deleteByApplication`) — these land via
 *      Doriath's `application-secret-delete` change, so an OLDER Doriath
 *      yields the vault leaf, not a broken one (cross-repo merge order free);
 *   4. OpenRegister's self-registration state exists in `IAppConfig` (the
 *      Doriath-assigned application UUID + OR's public key PEM, written by the
 *      {@see \OCA\OpenRegister\Repair\RegisterOpenRegisterWithDoriath} step).
 *
 * Consumers (broker, controller) keep depending only on the `CredentialStore`
 * interface; the DI factory in `lib/AppInfo/Application.php` asks this
 * resolver per request — zero call-site changes.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
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

namespace OCA\OpenRegister\Service\Credential;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Resolves the active credential-store leaf (Doriath preferred, vault fallback).
 */
class CredentialStoreResolver {
	/**
	 * The Doriath app id probed for eligibility.
	 *
	 * @var string
	 */
	public const DORIATH_APP_ID = 'doriath';

	/**
	 * Credential-app namespaces to probe, NEWEST FIRST.
	 *
	 * The credential app renamed from OCA\Doriath to OCA\Keepiq with no
	 * compatibility alias, and this resolver named only the old one — so every
	 * probe missed and the credential store silently read as unavailable.
	 * Nothing reported it, because "all four classes absent" is exactly what an
	 * uninstalled optional app looks like, which is the case these probes exist
	 * for. Measured on a running instance: every OCA\Keepiq\Service\* class
	 * EXISTS while every OCA\Doriath\Service\* class is MISSING.
	 *
	 * A NAMESPACE rather than a flat class list, because eligibility requires
	 * ALL FOUR services — a half-resolved set spanning both spellings would be
	 * a store that exists on paper and fails on the first call.
	 *
	 * @var array<int, string>
	 */
	private const CREDENTIAL_APP_NAMESPACES = [
		'OCA\\Keepiq\\Service\\',
		'OCA\\Doriath\\Service\\',
	];

	/**
	 * Service class names the leaf calls — ALL must exist for eligibility.
	 *
	 * @var array<int, string>
	 */
	private const SERVICE_NAMES = [
		'ApplicationService',
		'SecretService',
		'EncryptService',
		'DecryptService',
	];

	/**
	 * The service carrying the application-scoped seam methods.
	 *
	 * @var string
	 */
	private const SECRET_SERVICE_NAME = 'SecretService';

	/**
	 * Application-scoped seam methods that must exist on the secret service.
	 *
	 * These land via Doriath's `application-secret-delete` change; probing for
	 * them keeps the cross-repo rollout order free (design D-A).
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED_SEAM_METHODS = [
		'getByNameForApplication',
		'deleteByApplication',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Probes whether the doriath app is enabled.
	 * @param IAppConfig $appConfig Holds OR's Doriath self-registration state.
	 * @param DoriathCredentialStore $doriathStore The Doriath-backed leaf (selected when eligible).
	 * @param NextcloudVaultCredentialStore $vaultStore The NC-vault leaf (fallback).
	 * @param LoggerInterface $logger Secret-free diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IAppConfig $appConfig,
		private readonly DoriathCredentialStore $doriathStore,
		private readonly NextcloudVaultCredentialStore $vaultStore,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Return the active credential-store leaf.
	 *
	 * @return CredentialStore The Doriath leaf when eligible, else the NC-vault leaf.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function resolve(): CredentialStore {
		if ($this->isDoriathEligible() === true) {
			return $this->doriathStore;
		}

		return $this->vaultStore;
	}//end resolve()

	/**
	 * Evaluate the full Doriath eligibility chain (fails closed on any miss).
	 *
	 * @return bool True when the Doriath leaf may be used.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function isDoriathEligible(): bool {
		if ($this->appManager->isEnabledForUser(self::DORIATH_APP_ID) === false) {
			return false;
		}

		foreach ($this->doriathServiceClasses() as $className) {
			if (class_exists($className) === false) {
				$this->logger->debug(
					'[CredentialStoreResolver] Doriath ineligible: service class missing',
					['service' => $className]
				);
				return false;
			}
		}

		$secretServiceClass = $this->secretServiceClass();
		foreach (self::REQUIRED_SEAM_METHODS as $method) {
			if (method_exists($secretServiceClass, $method) === false) {
				$this->logger->debug(
					'[CredentialStoreResolver] Doriath ineligible: application-scoped seam method missing',
					['method' => $method]
				);
				return false;
			}
		}

		return $this->isSelfRegistered();
	}//end isDoriathEligible()

	/**
	 * Whether OR's Doriath self-registration state exists (design D-B output).
	 *
	 * @return bool True when the application UUID and public key PEM are persisted.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	private function isSelfRegistered(): bool {
		$applicationId = $this->appConfig->getValueString(
			'openregister',
			DoriathCredentialStore::APP_CONFIG_APPLICATION_ID,
			''
		);
		$publicPem = $this->appConfig->getValueString(
			'openregister',
			DoriathCredentialStore::APP_CONFIG_PUBLIC_KEY_PEM,
			''
		);

		return ($applicationId !== '' && $publicPem !== '');
	}//end isSelfRegistered()

	/**
	 * The Doriath service classes probed via `class_exists`.
	 *
	 * Protected so unit tests can point the probes at contract fixtures
	 * without the Doriath app installed.
	 *
	 * @return array<int, string> The probed FQCNs.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	protected function doriathServiceClasses(): array {
		return $this->classesForNamespace(namespace: $this->resolveNamespace());
	}//end doriathServiceClasses()

	/**
	 * The credential-app namespace whose FULL service set is installed.
	 *
	 * Falls back to the FIRST candidate when none resolves, so the caller still
	 * gets a coherent set of names to report as missing rather than an empty
	 * list that would read as "nothing required".
	 *
	 * @return string The namespace prefix.
	 */
	private function resolveNamespace(): string {
		foreach (self::CREDENTIAL_APP_NAMESPACES as $namespace) {
			$complete = true;
			foreach (self::SERVICE_NAMES as $name) {
				if (class_exists($namespace . $name) === false) {
					$complete = false;
					break;
				}
			}

			if ($complete === true) {
				return $namespace;
			}
		}

		return self::CREDENTIAL_APP_NAMESPACES[0];
	}//end resolveNamespace()

	/**
	 * The four service FQCNs under one namespace.
	 *
	 * @param string $namespace The namespace prefix.
	 *
	 * @return array<int, string> The FQCNs.
	 */
	private function classesForNamespace(string $namespace): array {
		$classes = [];
		foreach (self::SERVICE_NAMES as $name) {
			$classes[] = $namespace . $name;
		}

		return $classes;
	}//end classesForNamespace()

	/**
	 * The secret-service class probed for the application-scoped seam methods.
	 *
	 * Protected for the same test-fixture reason as {@see doriathServiceClasses()}.
	 *
	 * @return string The probed FQCN.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	protected function secretServiceClass(): string {
		return $this->resolveNamespace() . self::SECRET_SERVICE_NAME;
	}//end secretServiceClass()
}//end class
