<?php

/**
 * MaintenanceModeService: closing the instance without locking anybody out.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

use DateTime;
use OCP\IConfig;

/**
 * The app's own maintenance mode: reads and writes refused with a message.
 *
 * D-6: closing the instance is only safe when the person who closed it can
 * open it again. So the mode is held in app configuration, not in a lock file
 * nobody can reach from the browser, and the middleware that enforces it lets
 * the administration surface through by design rather than by accident.
 *
 * This is OpenRegister's mode, not Nextcloud's. Nextcloud's `maintenance`
 * config closes the whole server including the settings pages, which is the
 * exact lock-out D-6 exists to avoid.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
 */
class MaintenanceModeService {

	/**
	 * The app the mode belongs to.
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';

	/**
	 * The setting holding whether the mode is on.
	 *
	 * @var string
	 */
	public const SETTING_ENABLED = 'maintenance_mode';

	/**
	 * The setting holding the message readers are given.
	 *
	 * @var string
	 */
	public const SETTING_MESSAGE = 'maintenance_mode_message';

	/**
	 * The setting holding who closed the instance, and when.
	 *
	 * @var string
	 */
	public const SETTING_SINCE = 'maintenance_mode_since';

	/**
	 * The setting holding the uid that closed it.
	 *
	 * @var string
	 */
	public const SETTING_ACTOR = 'maintenance_mode_actor';

	/**
	 * What readers are told when nobody wrote a message.
	 *
	 * @var string
	 */
	public const DEFAULT_MESSAGE = 'This register is closed for maintenance. Please try again later.';

	/**
	 * The act, as the run log names entering the mode.
	 *
	 * @var string
	 */
	public const ACT_ENTER = 'OperationsConsole::maintenanceEntered';

	/**
	 * The act, as the run log names leaving it.
	 *
	 * @var string
	 */
	public const ACT_LEAVE = 'OperationsConsole::maintenanceLeft';

	/**
	 * Constructor.
	 *
	 * @param IConfig        $config   Where the mode is held.
	 * @param JobRunRecorder $recorder Where entering and leaving are recorded.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly JobRunRecorder $recorder,
	) {
	}//end __construct()

	/**
	 * Is the instance closed.
	 *
	 * @return bool True while the mode holds.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 */
	public function holds(): bool {
		return $this->config->getAppValue(self::APP_ID, self::SETTING_ENABLED, 'no') === 'yes';

	}//end holds()

	/**
	 * The message readers are given.
	 *
	 * @return string The administered message, or the shipped one.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 */
	public function message(): string {
		$message = $this->config->getAppValue(self::APP_ID, self::SETTING_MESSAGE, '');

		if (trim($message) === '') {
			return self::DEFAULT_MESSAGE;
		}

		return $message;

	}//end message()

	/**
	 * The mode as the console renders it.
	 *
	 * @return array<string, mixed> Whether it holds, the message, who and when.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 */
	public function state(): array {
		$since = $this->config->getAppValue(self::APP_ID, self::SETTING_SINCE, '');

		$sinceAt = null;
		if ($since !== '') {
			$sinceAt = $since;
		}

		$actor = $this->config->getAppValue(self::APP_ID, self::SETTING_ACTOR, '');
		if ($actor === '') {
			$actor = null;
		}

		return [
			'holds' => $this->holds(),
			'message' => $this->message(),
			'since' => $sinceAt,
			'actor' => $actor,
		];

	}//end state()

	/**
	 * Close the instance.
	 *
	 * @param string      $actor   The uid closing it.
	 * @param string|null $message What readers are told.
	 *
	 * @return array<string, mixed> The mode now in force.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 */
	public function enter(string $actor, ?string $message = null): array {
		if ($message !== null && trim($message) !== '') {
			$this->config->setAppValue(self::APP_ID, self::SETTING_MESSAGE, $message);
		}

		$this->config->setAppValue(self::APP_ID, self::SETTING_ENABLED, 'yes');
		$this->config->setAppValue(self::APP_ID, self::SETTING_SINCE, (new DateTime())->format(DateTime::ATOM));
		$this->config->setAppValue(self::APP_ID, self::SETTING_ACTOR, $actor);

		$this->recorder->recordAct(
			jobClass: self::ACT_ENTER,
			actor: $actor,
			details: ['message' => $this->message()],
			message: 'Entered maintenance mode.'
		);

		return $this->state();

	}//end enter()

	/**
	 * Open the instance again.
	 *
	 * @param string $actor The uid opening it.
	 *
	 * @return array<string, mixed> The mode now in force.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 */
	public function leave(string $actor): array {
		$this->config->setAppValue(self::APP_ID, self::SETTING_ENABLED, 'no');
		$this->config->deleteAppValue(self::APP_ID, self::SETTING_SINCE);
		$this->config->deleteAppValue(self::APP_ID, self::SETTING_ACTOR);

		$this->recorder->recordAct(
			jobClass: self::ACT_LEAVE,
			actor: $actor,
			details: [],
			message: 'Left maintenance mode.'
		);

		return $this->state();

	}//end leave()
}//end class
