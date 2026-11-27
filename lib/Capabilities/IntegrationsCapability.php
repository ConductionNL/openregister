<?php

/**
 * IntegrationsCapability — surface the integration registry through
 * Nextcloud's `/ocs/v2.php/cloud/capabilities` endpoint.
 *
 * Per AD-17 the capabilities block is role-redacted: every
 * authenticated user gets the public surface (id, label, group,
 * enabled, surfaces); admins additionally receive operational
 * fields (requiresPermission, authStatus, openConnectorSource).
 * Absence of an admin field for a non-admin caller is
 * indistinguishable from "not configured".
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Capabilities
 * @package  OCA\OpenRegister\Capabilities
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-21
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Capabilities;

use OCA\OpenRegister\Service\Integration\IntegrationAdminView;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationCatalogueInterface;
use OCP\Capabilities\ICapability;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * OCS capability provider for the integration registry.
 */
class IntegrationsCapability implements ICapability {
	/**
	 * Contract version of the `openregister.integrations` capability shape.
	 *
	 * Bumped when the descriptor shape changes incompatibly so consumers
	 * (clients, AI agents) can branch on it. Per ADR-019 the registry is
	 * advertised through OCS discoverability; this is the version of THAT
	 * surface, distinct from any individual provider's own versioning.
	 *
	 * @var int
	 */
	public const CONTRACT_VERSION = 1;

	/**
	 * Cache namespace of the per-reader provider rows.
	 *
	 * @var string
	 */
	public const CACHE_PREFIX = 'openregister-integrations-capability';

	/**
	 * How long one reader's provider rows are kept, in seconds.
	 *
	 * The key already changes when an app is enabled, disabled or updated,
	 * or the reader's language or admin role changes; the lifetime bounds
	 * what the key cannot see (a group change behind a group-restricted
	 * app, the admin-only health status).
	 *
	 * @var int
	 */
	public const CACHE_TTL = 300;

	/**
	 * Constructor.
	 *
	 * @param IntegrationCatalogueInterface  $registry     Integration registry.
	 * @param IUserSession                   $userSession  Current user session.
	 * @param IGroupManager                  $groupManager Group manager (admin check).
	 * @param IntegrationsCapabilityServices $services     App manager (installed check) and cross-app leaf catalogue.
	 *
	 * @return void
	 */
	public function __construct(
		private IntegrationCatalogueInterface $registry,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IntegrationsCapabilityServices $services,
	) {
	}//end __construct()

	/**
	 * Expose the registered integration providers as a capability.
	 *
	 * @inheritDoc
	 *
	 * @return array<string,mixed>
	 */
	public function getCapabilities(): array {
		$registry = $this->registryRows();

		return [
			'openregister' => [
				'integrations' => [
					// `version` retained for back-compat with the original
					// Phase E shape; `contractVersion` is the canonical key
					// going forward (ADR-019 discoverability contract).
					'version' => self::CONTRACT_VERSION,
					'contractVersion' => self::CONTRACT_VERSION,
					// Flat id list — the cheapest discovery primitive, mirrors
					// IntegrationRegistry::listIds(). AI agents / clients that
					// only need "what exists" read this without walking
					// `providers`.
					'registered' => $registry['registered'],
					'providers' => $registry['providers'],
					// `leaves` — the cross-app leaf catalogue (ADR-066). A
					// manifest app or admin UI discovers which leaves exist
					// (id, label, requiredApp, surfaces, kinds, renderMode,
					// usability) WITHOUT loading any leaf app's JS bundle.
					// `renderMode` (component | mount) reports HOW a render-
					// surface leaf renders. Render-surface parity to the JS
					// registration — including the renderMode correlation — is
					// keyed by the shared id.
					'leaves' => $this->services->leafRegistry->describeForCapabilities(),
					// `semanticProviders` — the installed schemas that provide a
					// canonical semantic type (cross-app-semantic-references), so a
					// form can bind a `referenceSemanticType` field without a
					// round-trip. Cached per user; see SemanticProviderCatalogue.
					'semanticProviders' => $this->semanticProviders(),
				],
			],
		];
	}//end getCapabilities()

	/**
	 * The registered ids and the reader's provider rows, from the cache when it holds them.
	 *
	 * Nextcloud renders the capabilities into every page, so without the cache
	 * every page render builds all built-in providers (and their services) to
	 * describe them.
	 *
	 * @return array{registered: list<string>, providers: array<int, array<string, mixed>>} The ids and the rows.
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-21
	 */
	private function registryRows(): array {
		$isAdmin = $this->currentUserIsAdmin();
		$key     = $this->cacheKey(isAdmin: $isAdmin);

		if ($key !== null) {
			$cached = $this->services->cache?->get($key);
			if (is_array($cached) === true
				&& is_array($cached['registered'] ?? null) === true
				&& is_array($cached['providers'] ?? null) === true
			) {
				return ['registered' => $cached['registered'], 'providers' => $cached['providers']];
			}
		}

		$rows = [
			'registered' => $this->registry->listIds(),
			'providers' => $this->providerRows(isAdmin: $isAdmin),
		];

		if ($key !== null) {
			$this->services->cache?->set($key, $rows, self::CACHE_TTL);
		}

		return $rows;
	}//end registryRows()

	/**
	 * The cache key for this reader, or null when nothing is cached.
	 *
	 * The rows differ per reader (installed-for-user, admin fields), per
	 * language (labels) and per set of enabled apps and their versions
	 * (available, and which providers exist).
	 *
	 * @param bool $isAdmin Whether the reader is an admin.
	 *
	 * @return string|null The key.
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-21
	 */
	private function cacheKey(bool $isAdmin): ?string {
		if ($this->services->cache === null) {
			return null;
		}

		try {
			$apps     = $this->services->appManager->getAppInstalledVersions(onlyEnabled: true);
			$language = $this->services->l10nFactory?->findLanguage('openregister') ?? '';
		} catch (\Throwable) {
			return null;
		}

		ksort($apps);
		$userId = $this->userSession->getUser()?->getUID() ?? '';

		return hash('xxh128', json_encode([$userId, $isAdmin, $language, $apps, self::CONTRACT_VERSION]));
	}//end cacheKey()

	/**
	 * The semantic provider rows, empty when the catalogue is not wired.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/cross-app-semantic-references/tasks.md#task-4.1
	 */
	private function semanticProviders(): array {
		if ($this->services->semanticProviders === null) {
			return [];
		}

		// The capabilities are built on every page load: a failing list must not take them down.
		try {
			return $this->services->semanticProviders->list();
		} catch (\Throwable) {
			return [];
		}
	}//end semanticProviders()

	/**
	 * The role-redacted descriptor of every registered provider.
	 *
	 * @param bool $isAdmin Whether the caller is admin.
	 *
	 * @return array<int, array<string, mixed>> One descriptor per provider
	 */
	private function providerRows(bool $isAdmin): array {
		$rows = [];

		foreach ($this->registry->list() as $provider) {
			$rows[] = $this->describe(provider: $provider, isAdmin: $isAdmin);
		}

		return $rows;
	}//end providerRows()

	/**
	 * Build the role-redacted descriptor for one provider.
	 *
	 * @param IntegrationProvider $provider Provider.
	 * @param bool $isAdmin Whether the caller is admin.
	 *
	 * @return array<string,mixed>
	 */
	private function describe(IntegrationProvider $provider, bool $isAdmin): array {
		$row = $this->publicDescriptor(provider: $provider);

		if ($isAdmin === false) {
			return $row;
		}

		return IntegrationAdminView::withAdminFields(row: $row, provider: $provider);
	}//end describe()

	/**
	 * The descriptor fields every caller sees.
	 *
	 * @param IntegrationProvider $provider Provider.
	 *
	 * @return array<string,mixed>
	 */
	private function publicDescriptor(IntegrationProvider $provider): array {
		$requiredApp = $provider->getRequiredApp();

		return [
			'id' => $provider->getId(),
			'label' => $provider->getLabel(),
			'group' => $provider->getGroup(),
			'enabled' => $provider->isEnabled(),
			// `requiredApp` + `available` are public discovery fields: a
			// non-admin needs to know whether an integration's backing NC
			// app is installed to decide whether to render its surface.
			// Built-in providers (requiredApp === null) ride on OpenRegister
			// itself and are therefore always available.
			'requiredApp' => $requiredApp,
			'available' => $this->appIsInstalled(appId: $requiredApp),
			'storageStrategy' => $provider->getStorageStrategy(),
			'surfaces' => ['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity'],
		];
	}//end publicDescriptor()

	/**
	 * Whether the NC app backing an integration is installed and enabled.
	 *
	 * Built-in integrations declare a null `requiredApp` — they ride on
	 * OpenRegister itself and are therefore always considered available.
	 *
	 * @param string|null $appId The required NC app id, or null for built-ins.
	 *
	 * @return bool True when the integration's backing app is usable.
	 */
	private function appIsInstalled(?string $appId): bool {
		if ($appId === null || $appId === '') {
			return true;
		}

		return $this->services->appManager->isEnabledForUser($appId);
	}//end appIsInstalled()

	/**
	 * Check whether the current user is in the admin group.
	 *
	 * @return bool
	 */
	private function currentUserIsAdmin(): bool {
		return IntegrationAdminView::callerIsAdmin(userSession: $this->userSession, groupManager: $this->groupManager);
	}//end currentUserIsAdmin()
}//end class
