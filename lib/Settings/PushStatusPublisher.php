<?php

/**
 * PushStatusPublisher
 *
 * Works out the live-update push status and hands it to the admin Vue app.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Settings
 * @package  OCA\OpenRegister\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/add-live-updates/tasks.md#task-10
 */

namespace OCA\OpenRegister\Settings;

use OCP\AppFramework\Services\IInitialState;

/**
 * The push status of the admin page: read from the platform, published as
 * the `push_status` initial state.
 *
 * @spec openspec/changes/add-live-updates/tasks.md#task-10
 */
class PushStatusPublisher {

	/**
	 * Constructor
	 *
	 * @param PushStatusSources $sources      App manager and app config for the push status
	 * @param IInitialState     $initialState Initial state service for Vue data
	 */
	public function __construct(
		private readonly PushStatusSources $sources,
		private readonly IInitialState $initialState,
	) {
	}//end __construct()

	/**
	 * Work out the push status and provide it as initial state for the Vue admin app.
	 *
	 * @return string One of `not_installed`, `active`, or `unreachable`
	 *
	 * @spec openspec/changes/add-live-updates/tasks.md#task-10
	 */
	public function publish(): string {
		$pushStatus = $this->status();
		$this->initialState->provideInitialState('push_status', $pushStatus);

		return $pushStatus;
	}//end publish()

	/**
	 * Get the push notification status for the admin UI.
	 *
	 * Returns one of three statuses:
	 *   - `not_installed` — the notify_push app is not installed
	 *   - `active`        — installed and at least one push has been confirmed
	 *   - `unreachable`   — installed but no push confirmed yet
	 *
	 * This method MUST NOT instantiate IQueue to avoid exceptions in the UI
	 * when notify_push is partially installed or misconfigured.
	 *
	 * @return string One of `not_installed`, `active`, or `unreachable`
	 *
	 * @spec openspec/changes/add-live-updates/tasks.md#task-10
	 */
	private function status(): string {
		if ($this->sources->appManager->isInstalled('notify_push') === false) {
			return 'not_installed';
		}

		if ($this->sources->appConfig->getValueString('openregister', 'push_available', '') === '1') {
			return 'active';
		}

		return 'unreachable';
	}//end status()
}//end class
