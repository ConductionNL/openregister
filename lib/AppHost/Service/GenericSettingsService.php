<?php

/**
 * OpenRegister AppHost — Generic Settings Service (ADR-066 settings plane)
 *
 * The canonical settings-plane consumable for AppHost-adopting leaf apps
 * (ADR-066): one instance per leaf app, parameterised by the calling app id,
 * bound to the fleet's register JSON path convention
 * `lib/Settings/{appId}_register.json` + `lib/Settings/register.d/*.json`
 * fragments, exposing exactly:
 *
 *   - `getSettings()`                → read merged config (`index`).
 *   - `updateSettings(array $data)`  → write managed keys (`update`).
 *   - `loadConfiguration(bool $force)` → import register config (`load`),
 *     the ONLY import signature (no `loadConfigurationForced()` drift).
 *
 * ## Fail-mode (ADR-049 — explicit, never silent)
 *
 * Where the older {@see AppHostSettingsService} degrades a missing foundation
 * into `['success' => false]` result arrays (repair-step-friendly), THIS
 * service fails closed with typed exceptions so a controller can translate to
 * an explicit error status and no caller can mistake "OpenRegister missing"
 * for an empty-success:
 *
 *   - OpenRegister disabled/unresolvable → {@see FoundationUnavailableException}.
 *   - Register JSON absent/invalid       → {@see ConfigurationMissingException}.
 *
 * It never returns null.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

use OCA\OpenRegister\AppHost\Exception\ConfigurationMissingException;
use OCA\OpenRegister\AppHost\Exception\FoundationUnavailableException;
use Throwable;

/**
 * Canonical generic settings service for AppHost-adopting apps (ADR-066).
 *
 * Inherits `getSettings()` / `updateSettings()` and the register-JSON
 * resolution mechanics from {@see AppHostSettingsService}; overrides the
 * import path with the explicit ADR-049 fail-mode.
 *
 * @psalm-suppress UnusedClass Consumed by leaf apps via Bootstrap registration.
 *
 * @spec openspec/changes/apphost-settings-plane/specs/apphost-settings-plane/spec.md — Requirement: Generic settings surface
 */
class GenericSettingsService extends AppHostSettingsService {
	/**
	 * Fully-qualified ConfigurationService name, kept as a plain string so the
	 * class is only autoloaded when actually resolved (bootstrap-safety
	 * invariant shared with {@see \OCA\OpenRegister\AppHost\Bootstrap}).
	 */
	private const CONFIGURATION_SERVICE = 'OCA\\OpenRegister\\Service\\ConfigurationService';

	/**
	 * Import the app's register JSON via OpenRegister's ConfigurationService.
	 *
	 * Delegates to the existing `ConfigurationService::importFromApp()`
	 * mechanics (version-gated, `force` overrides) using the fleet path
	 * convention `lib/Settings/{appId}_register.json` + `register.d/`
	 * fragments resolved by the inherited
	 * {@see AppHostSettingsService::resolveRegisterConfiguration()}.
	 *
	 * Explicit fail-mode (ADR-049): this method throws typed exceptions and
	 * never returns null or a silent empty-success.
	 *
	 * @param bool $force Force re-import even when the stored version matches.
	 *
	 * @return array<string, mixed> Result with `success`, `message`, and `version`.
	 *
	 * @throws FoundationUnavailableException When OpenRegister (or its ConfigurationService) is unavailable.
	 * @throws ConfigurationMissingException When the app ships no readable register JSON.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `loadConfiguration(bool $force)` is
	 * the ONE canonical import signature mandated by ADR-066 (no `loadConfigurationForced` drift).
	 *
	 * @spec openspec/changes/apphost-settings-plane/specs/apphost-settings-plane/spec.md
	 *   — Requirement: Generic settings surface (Scenario: Foundation missing is explicit)
	 */
	public function loadConfiguration(bool $force = false): array {
		if ($this->isOpenRegisterAvailable() === false) {
			$this->logger->error(sprintf('[AppHost:%s] OpenRegister not available — register import refused (fail-closed)', $this->appId));
			throw new FoundationUnavailableException(appId: $this->appId);
		}

		try {
			$configurationService = $this->container->get(self::CONFIGURATION_SERVICE);
		} catch (Throwable $e) {
			$this->logger->error(
				sprintf('[AppHost:%s] ConfigurationService unresolvable — register import refused (fail-closed)', $this->appId),
				['exception' => $e]
			);
			throw new FoundationUnavailableException(
				appId: $this->appId,
				detail: 'ConfigurationService could not be resolved from the container.',
				previous: $e
			);
		}

		[$data, $version] = $this->resolveRegisterConfiguration();
		if ($data === null) {
			$this->logger->error(sprintf('[AppHost:%s] register JSON lib/Settings/%s_register.json missing or invalid', $this->appId, $this->appId));
			throw new ConfigurationMissingException(
				appId: $this->appId,
				configKey: 'lib/Settings/' . $this->appId . '_register.json'
			);
		}

		$result = $configurationService->importFromApp(appId: $this->appId, data: $data, version: $version, force: $force);
		if (empty($result) === true) {
			// Version-gated no-op or empty import: explicit non-success, never
			// dressed up as a successful import.
			return [
				'success' => false,
				'message' => 'Import returned an empty result.',
			];
		}

		$this->logger->info(sprintf('[AppHost:%s] register configuration imported successfully', $this->appId));
		// Stamp the app version the config was imported for, so admin UIs can
		// show a REAL up-to-date check (config_version === app version).
		$this->appConfig->setValueString($this->appId, 'config_version', $this->appManager->getAppVersion($this->appId));

		return [
			'success' => true,
			'message' => 'Configuration imported successfully.',
			'version' => ($result['version'] ?? 'unknown'),
		];
	}//end loadConfiguration()
}//end class
