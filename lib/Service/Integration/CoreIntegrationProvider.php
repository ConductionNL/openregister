<?php

/**
 * Base class for the integration providers OpenRegister ships itself.
 *
 * A core provider needs no Nextcloud app (requiredApp is null), is always
 * enabled, and sits in the `core` group. Concrete core providers declare
 * their id, label, icon, storage strategy and read path.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Always-on, built-in integration provider in the `core` group.
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-2
 */
abstract class CoreIntegrationProvider extends AbstractIntegrationProvider {
	/**
	 * Group identifier for UI grouping.
	 *
	 * @return string|null Always `core`.
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-2
	 */
	public function getGroup(): ?string {
		return 'core';
	}//end getGroup()

	/**
	 * Required NC app id.
	 *
	 * @return string|null Always null: a core provider is built in.
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-2
	 */
	public function getRequiredApp(): ?string {
		return null;
	}//end getRequiredApp()

	/**
	 * True when the provider is available for use.
	 *
	 * @return bool Always true: a core provider has no app to be missing.
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-2
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()
}//end class
