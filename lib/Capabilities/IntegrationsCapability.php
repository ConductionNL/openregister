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

use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Integration\LeafRegistry;
use OCP\App\IAppManager;
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
	 * Constructor.
	 *
	 * @param IntegrationRegistry $registry Integration registry.
	 * @param IUserSession $userSession Current user session.
	 * @param IGroupManager $groupManager Group manager (admin check).
	 * @param IAppManager $appManager App manager (installed check).
	 * @param LeafRegistry $leafRegistry Cross-app leaf catalogue (ADR-066).
	 *
	 * @return void
	 */
	public function __construct(
		private IntegrationRegistry $registry,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IAppManager $appManager,
		private LeafRegistry $leafRegistry,
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
		$isAdmin = $this->currentUserIsAdmin();
		$rows = [];

		foreach ($this->registry->list() as $provider) {
			$rows[] = $this->describe(provider: $provider, isAdmin: $isAdmin);
		}

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
					'registered' => $this->registry->listIds(),
					'providers' => $rows,
					// `leaves` — the cross-app leaf catalogue (ADR-066). A
					// manifest app or admin UI discovers which leaves exist
					// (id, label, requiredApp, surfaces, kinds, renderMode,
					// usability) WITHOUT loading any leaf app's JS bundle.
					// `renderMode` (component | mount) reports HOW a render-
					// surface leaf renders. Render-surface parity to the JS
					// registration — including the renderMode correlation — is
					// keyed by the shared id.
					'leaves' => $this->leafRegistry->describeForCapabilities(),
				],
			],
		];
	}//end getCapabilities()

	/**
	 * Build the role-redacted descriptor for one provider.
	 *
	 * @param IntegrationProvider $provider Provider.
	 * @param bool $isAdmin Whether the caller is admin.
	 *
	 * @return array<string,mixed>
	 */
	private function describe(IntegrationProvider $provider, bool $isAdmin): array {
		$requiredApp = $provider->getRequiredApp();

		$row = [
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

		if ($isAdmin === false) {
			return $row;
		}

		$row['requiresPermission'] = $provider->requiresPermission();
		$row['openConnectorSource'] = $provider->getOpenConnectorSource();

		try {
			$row['authStatus'] = $provider->health();
		} catch (\Throwable $e) {
			$row['authStatus'] = [
				'status' => 'unavailable',
				'authStatus' => 'unknown',
				'message' => 'Provider health check threw',
			];
		}

		return $row;
	}//end describe()

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

		return $this->appManager->isEnabledForUser($appId);
	}//end appIsInstalled()

	/**
	 * Check whether the current user is in the admin group.
	 *
	 * @return bool
	 */
	private function currentUserIsAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}//end currentUserIsAdmin()
}//end class
