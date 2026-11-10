<?php

/**
 * Who an inbox read is for, and which of their relationships it lists.
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
 * The caller (uid, groups, admin fact) and the scope of the read.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) isAdmin is a filter VALUE
 * carried into the WHERE, not a behaviour switch.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 */
final class TaskInboxAudience {

	/**
	 * Constructor.
	 *
	 * @param string             $uid      The calling user.
	 * @param array<int, string> $groupIds The caller's group ids, resolved by
	 *                                     the SERVICE (the mapper never asks a
	 *                                     group backend).
	 * @param bool               $isAdmin  Whether the caller is an administrator — the one
	 *                                     case visibility is not narrowed.
	 * @param string             $scope    One of the TaskInboxCriteria::SCOPE_* values.
	 */
	public function __construct(
		public readonly string $uid,
		public readonly array $groupIds = [],
		public readonly bool $isAdmin = false,
		public readonly string $scope = TaskInboxCriteria::SCOPE_ASSIGNED,
	) {
	}//end __construct()
}//end class
