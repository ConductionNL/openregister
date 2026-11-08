<?php

/**
 * ExampleSetDailyMarksInterface: which example sets are marked for the daily refresh, as the install command reads and sets it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Reads and sets the daily-refresh marks of the example sets. Only the methods the install command calls.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
 */
interface ExampleSetDailyMarksInterface {

	/**
	 * The marked sets.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
	 */
	public function sets(): array;

	/**
	 * Mark or unmark a set for the daily refresh.
	 *
	 * @param string $set   The set id.
	 * @param bool   $daily True to refresh it daily, false to stop.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the one fact the call sets.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
	 */
	public function mark(string $set, bool $daily): void;
}//end interface
