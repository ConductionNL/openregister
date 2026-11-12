<?php

/**
 * Reading and writing a settings section over its defaults.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
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

namespace OCA\OpenRegister\Service\Settings;

/**
 * Fills a settings section from submitted or stored values, key by key, and holds
 * the defaults that more than one settings handler shares.
 */
trait SettingsSectionDefaults {

	/**
	 * The audit and search trail retention defaults, shared by the full settings and the retention section.
	 *
	 * Periods are in milliseconds: one year to archive, two years to delete, a
	 * month of search trails and of create and delete logs, 24 hours of read
	 * logs and a week of update logs.
	 *
	 * @var array<string, int|bool>
	 */
	private const RETENTION_PERIOD_DEFAULTS = [
		'objectArchiveRetention' => 31536000000,
		'objectDeleteRetention' => 63072000000,
		'searchTrailRetention' => 2592000000,
		'createLogRetention' => 2592000000,
		'readLogRetention' => 86400000,
		'updateLogRetention' => 604800000,
		'deleteLogRetention' => 2592000000,
		'auditTrailsEnabled' => true,
		'searchTrailsEnabled' => true,
	];

	/**
	 * Every default key with the value set for it in $values, or the default.
	 *
	 * @param array<string, mixed> $defaults The keys and their defaults.
	 * @param mixed                $values   The values; anything but an array sets none.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec exclude pure helper of the settings handlers, covered by their goldens
	 */
	private function overDefaults(array $defaults, $values): array {
		if (is_array($values) === false) {
			$values = [];
		}

		$section = [];
		foreach ($defaults as $key => $default) {
			$section[$key] = $values[$key] ?? $default;
		}

		return $section;
	}//end overDefaults()
}//end trait
