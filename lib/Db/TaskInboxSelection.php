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
	 * The field filters.
	 *
	 * @var TaskInboxFilter
	 */
	public readonly TaskInboxFilter $filter;

	/**
	 * The overdue cutoff and due window.
	 *
	 * @var TaskInboxDueWindow
	 */
	public readonly TaskInboxDueWindow $window;

	/**
	 * The object or run anchor.
	 *
	 * @var TaskInboxAnchor
	 */
	public readonly TaskInboxAnchor $anchor;

	/**
	 * The sort.
	 *
	 * @var TaskInboxOrder
	 */
	public readonly TaskInboxOrder $order;

	/**
	 * Constructor.
	 *
	 * @param TaskInboxFilter|null    $filter The field filters; none when null.
	 * @param TaskInboxDueWindow|null $window The overdue cutoff and due window; none when null.
	 * @param TaskInboxAnchor|null    $anchor The object or run anchor; none when null.
	 * @param TaskInboxOrder|null     $order  The sort; due date ascending when null.
	 */
	public function __construct(
		?TaskInboxFilter $filter = null,
		?TaskInboxDueWindow $window = null,
		?TaskInboxAnchor $anchor = null,
		?TaskInboxOrder $order = null,
	) {
		$this->filter = ($filter ?? new TaskInboxFilter());
		$this->window = ($window ?? new TaskInboxDueWindow());
		$this->anchor = ($anchor ?? new TaskInboxAnchor());
		$this->order = ($order ?? new TaskInboxOrder());
	}//end __construct()
}//end class
