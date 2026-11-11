<?php

/**
 * ExampleSetDailyRefreshInterface: what callers outside the Service component ask of ExampleSetDailyRefresh.
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

use DateTimeImmutable;

/**
 * Running the daily refresh of the example sets, as the daily refresh job does. Only the method that caller calls.
 */
interface ExampleSetDailyRefreshInterface {

	/**
	 * Refresh every marked set that is installed, against the day given (or today in the set's time zone).
	 *
	 * @param DateTimeImmutable|null $today The day to refresh against; null for today.
	 *
	 * @return array<string, array<string, mixed>> Per set: `ok`, and `skipped` or the refusals, dropped, missing and lost.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
	 */
	public function run(?DateTimeImmutable $today=null): array;
}//end interface
