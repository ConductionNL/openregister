<?php

/**
 * NotificationHistoryMapperInterface: the public surface of NotificationHistoryMapper, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Db
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

namespace OCA\OpenRegister\Notifications\Db;

use DateTime;

/**
 * Every public method of NotificationHistoryMapper. Callers depend on this interface, not on the class.
 */
interface NotificationHistoryMapperInterface {

	/**
	 * How the dispatches in a window came out, grouped by outcome.
	 *
	 * The dispatcher writes a status per attempt, and it writes more than two:
	 * `dispatched`, and then every reason a notice never reached anybody, from
	 * `rate-limited` to `preference-off` to `recipient-unresolved`. Grouping
	 * rather than counting a list of known statuses is deliberate: whatever the
	 * dispatcher learns to write next appears on the console by itself, instead
	 * of being silently dropped into neither column.
	 *
	 * Index-backed on `(status, dispatched_at)` (`or_notif_hist_status_idx`),
	 * which is the pair this groups and windows on (ADR-009).
	 *
	 * @param DateTime|null $since Only dispatches at or after this moment.
	 *
	 * @return array<string, int> Status to count, for the statuses in use.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function countByStatus(?DateTime $since = null): array;
}//end interface
