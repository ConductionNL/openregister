<?php

/**
 * Which deadlines an inbox read keeps.
 *
 * Part of one inbox read's {@see TaskInboxCriteria}.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;

/**
 * The derived-overdue cutoff and the due window.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 */
final class TaskInboxDueWindow {

	/**
	 * Constructor.
	 *
	 * @param DateTime|null $overdueAt When set, only tasks whose `due_at` lies
	 *                                 strictly before this instant — the
	 *                                 derived-overdue filter, handed the clock
	 *                                 by TaskTemporalProjection.
	 * @param DateTime|null $dueAfter  When set, only tasks whose effective
	 *                                 deadline is at or after this instant.
	 *                                 Pairs with `dueBefore` to express a
	 *                                 WINDOW ("due this week"), which
	 *                                 `overdueAt` cannot: that one is
	 *                                 open-ended in the past by design.
	 * @param DateTime|null $dueBefore When set, only tasks whose effective
	 *                                 deadline is strictly before this
	 *                                 instant.
	 */
	public function __construct(
		public readonly ?DateTime $overdueAt = null,
		public readonly ?DateTime $dueAfter = null,
		public readonly ?DateTime $dueBefore = null,
	) {
	}//end __construct()
}//end class
