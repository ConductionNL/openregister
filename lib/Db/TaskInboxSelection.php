<?php

/**
 * Which tasks an inbox read selects beyond its audience, and in which order.
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

/**
 * The field filters, the due window, the anchor and the sort of one inbox read.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 */
final class TaskInboxSelection {
	/**
	 * Constructor.
	 *
	 * @param TaskInboxFilter    $filter The field filters; none by default.
	 * @param TaskInboxDueWindow $window The overdue cutoff and due window; none by default.
	 * @param TaskInboxAnchor    $anchor The object or run anchor; none by default.
	 * @param TaskInboxOrder     $order  The sort; due date ascending by default.
	 */
	public function __construct(
		public readonly TaskInboxFilter $filter = new TaskInboxFilter(),
		public readonly TaskInboxDueWindow $window = new TaskInboxDueWindow(),
		public readonly TaskInboxAnchor $anchor = new TaskInboxAnchor(),
		public readonly TaskInboxOrder $order = new TaskInboxOrder(),
	) {
	}//end __construct()
}//end class
