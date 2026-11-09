<?php

/**
 * OpenRegisterAdmin
 *
 * Admin settings page for OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Settings
 * @package  OCA\OpenRegister\Settings
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/add-live-updates/tasks.md#task-10
 */

namespace OCA\OpenRegister\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\IDelegatedSettings;
use OCP\Settings\ISettings;

/**
 * OpenRegisterAdmin
 *
 * Admin settings implementation for OpenRegister.
 *
 * @category Settings
 * @package  OCA\OpenRegister\Settings
 */
class OpenRegisterAdmin implements IDelegatedSettings {

	/**
	 * Constructor
	 *
	 * @param IConfig             $config     Config service
	 * @param PushStatusPublisher $pushStatus Works out the push status and provides it to Vue
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/add-live-updates/tasks.md#task-10
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly PushStatusPublisher $pushStatus,
	) {
	}//end __construct()

	/**
	 * Get the admin settings form
	 *
	 * @return TemplateResponse Template response
	 *
	 * @psalm-return TemplateResponse<200, array<string, mixed>>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/changes/add-live-updates/tasks.md#task-10
	 */
	public function getForm() {
		// Also provides pushStatus as initial state for the Vue admin settings app.
		$pushStatus = $this->pushStatus->publish();

		$parameters = [
			'mySetting' => $this->config->getSystemValue('open_register_setting', true),
			'pushStatus' => $pushStatus,
		];

		return new TemplateResponse(
			appName: 'openregister',
			templateName: 'settings/admin',
			params: $parameters,
			renderAs: 'admin'
		);
	}//end getForm()

	/**
	 * Get the section identifier
	 *
	 * @return string Section identifier
	 *
	 * @psalm-return 'openregister'
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getSection() {
		// Name of the previously created section.
		$sectionName = 'openregister';
		return $sectionName;
	}//end getSection()

	/**
	 * Get the priority of this settings form
	 *
	 * The form position in the admin section. Forms are arranged in ascending order
	 * of priority values. Must return a value between 0 and 100.
	 *
	 * @return int Priority value between 0 and 100
	 *
	 * @psalm-return 11
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getPriority() {
		return 11;
	}//end getPriority()

	/**
	 * The delegation group name, or null for "not separately delegatable".
	 *
	 * 🔴 WHY THIS PANEL BECAME DELEGATABLE AT ALL. `AuthorizedAdminSetting`
	 * — the only auth attribute that declares "administrator" WITHOUT also
	 * disabling CSRF — takes a `class-string<IDelegatedSettings>`, and this
	 * class implemented plain `ISettings`. The register-descriptor endpoints
	 * needed to declare the posture their bodies enforce, and binding them to
	 * the AppHost panel instead would have named a panel they are not part of.
	 *
	 * `IDelegatedSettings` does not delegate anything by itself: it makes the
	 * panel ELIGIBLE for delegation, which an administrator must then configure.
	 * Returning null here and an empty allowlist below keeps today's behaviour
	 * exactly — full administrators only — while giving the attribute a real
	 * settings class to bind to.
	 *
	 * @return string|null The delegation name.
	 */
	public function getName(): ?string {
		return null;
	}//end getName()

	/**
	 * App-config keys a delegated admin may manage. Empty = full-admin only.
	 *
	 * @return array<string, array<int, string>> The allowlist.
	 */
	public function getAuthorizedAppConfig(): array {
		return [];
	}//end getAuthorizedAppConfig()

}//end class
