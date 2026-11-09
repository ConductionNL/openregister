<?php

/**
 * OpenRegister Example Set Background Run
 *
 * The process-wide frame a daily refresh opens, so a refresh that runs from
 * cron inside a web request may still act as a set's cast accounts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-refresh-makes-the-access-links-and-grants-of-a-set-again
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Counts the daily refresh runs open in this process.
 *
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-refresh-makes-the-access-links-and-grants-of-a-set-again
 */
final class ExampleSetBackgroundRun {
	/**
	 * How many daily refresh runs are open in this process; a background run outside the CLI (AJAX or web cron) opens one.
	 *
	 * @var integer
	 */
	private static int $open = 0;

	/**
	 * Run the daily refresh, which may act as cast accounts even when cron runs inside a web request.
	 *
	 * @param callable $run The refresh.
	 *
	 * @return mixed What the refresh returns.
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-refresh-makes-the-access-links-and-grants-of-a-set-again
	 */
	public static function inBackgroundJob(callable $run): mixed {
		self::$open++;
		try {
			return $run();
		} finally {
			self::$open--;
		}
	}//end inBackgroundJob()

	/**
	 * Whether a daily refresh run is open in this process.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-refresh-makes-the-access-links-and-grants-of-a-set-again
	 */
	public static function isOpen(): bool {
		return self::$open > 0;
	}//end isOpen()
}//end class
