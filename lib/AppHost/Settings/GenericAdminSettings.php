<?php

/**
 * OpenRegister AppHost — Generic Admin Settings
 *
 * Engine-owned generalisation of the per-app `AdminSettings`. Renders the leaf
 * app's `settings/admin` template and provides the app version as initial
 * state. Parameterised by the leaf app id + its section id.
 *
 * Implements `IDelegatedSettings` (the #299 pattern) so the form and its
 * mutating controllers can be guarded by
 * `#[AuthorizedAdminSetting(AdminSettings::class)]` — the leaf keeps a one-line
 * subclass referenced both by info.xml `<settings><admin>` and by that
 * attribute. `getAuthorizedAppConfig()` defaults to empty (no delegatable
 * sub-keys), which scopes the endpoint to full admins — fail-closed, NOT
 * fail-open: the bespoke admin gating is preserved exactly.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Settings
 * @package  OCA\OpenRegister\AppHost\Settings
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

namespace OCA\OpenRegister\AppHost\Settings;

use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\Settings\IDelegatedSettings;

/**
 * Generic admin settings form for AppHost-adopting apps.
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.4
 */
class GenericAdminSettings implements IDelegatedSettings {
	/**
	 * The leaf app id (template + version).
	 *
	 * @var string
	 */
	protected readonly string $appId;

	/**
	 * The settings section id this form sits in.
	 *
	 * @var string
	 */
	protected readonly string $sectionId;

	/**
	 * Ordering priority within the section.
	 *
	 * @var integer
	 */
	protected readonly int $priority;

	/**
	 * App manager (version lookup).
	 *
	 * @var IAppManager
	 */
	protected readonly IAppManager $appManager;

	/**
	 * Initial-state service.
	 *
	 * @var IInitialState
	 */
	protected readonly IInitialState $initialState;

	/**
	 * App config — when set, enables a real up-to-date check by comparing the
	 * running app version against the version stored when the configuration
	 * was last (re-)imported.
	 *
	 * @var IAppConfig|null
	 */
	protected readonly ?IAppConfig $appConfig;

	/**
	 * Constructor.
	 *
	 * @param SettingsPlacement $placement Leaf app id, section id and the form's priority in it.
	 * @param AdminFormServices $services  App manager, initial state and (optional) app config.
	 */
	public function __construct(SettingsPlacement $placement, AdminFormServices $services) {
		$this->appId        = $placement->appId;
		$this->sectionId    = $placement->sectionId;
		$this->priority     = $placement->priority;
		$this->appManager   = $services->appManager;
		$this->initialState = $services->initialState;
		$this->appConfig    = $services->appConfig;
	}//end __construct()

	/**
	 * Get the settings form template (the leaf app's `settings/admin`).
	 *
	 * Provides `version` (running app version) and, when an IAppConfig is
	 * available, `configuredVersion` + `isUpToDate` — a REAL up-to-date signal
	 * (the config version is stamped by AppHostSettingsService on each import).
	 * The shared CnAdminSettingsShell reads these via loadState so the badge is
	 * truthful instead of hardcoded.
	 *
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$version = $this->appManager->getAppVersion(appId: $this->appId);
		$this->initialState->provideInitialState('version', $version);

		if ($this->appConfig !== null) {
			$configuredVersion = $this->appConfig->getValueString($this->appId, 'config_version', '');
			$this->initialState->provideInitialState('configuredVersion', $configuredVersion);
			// Up to date when the config was imported for the current app version.
			// Empty configuredVersion (never imported) → not up to date.
			$this->initialState->provideInitialState('isUpToDate', ($configuredVersion !== '' && $configuredVersion === $version));
		}

		return new TemplateResponse($this->appId, 'settings/admin', []);
	}//end getForm()

	/**
	 * Section id this settings page belongs to.
	 *
	 * @return string
	 */
	public function getSection(): string {
		return $this->sectionId;
	}//end getSection()

	/**
	 * Ordering priority within the section.
	 *
	 * @return int
	 */
	public function getPriority(): int {
		return $this->priority;
	}//end getPriority()

	/**
	 * Human-readable name of the delegated settings section.
	 *
	 * @return string|null Null to use the section default.
	 */
	public function getName(): ?string {
		return null;
	}//end getName()

	/**
	 * App-config keys a delegated admin may manage. Empty = full-admin only.
	 *
	 * @return array<string, string[]> Map of appId to allowed config keys.
	 */
	public function getAuthorizedAppConfig(): array {
		return [];
	}//end getAuthorizedAppConfig()
}//end class
