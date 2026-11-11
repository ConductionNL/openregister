<?php

/**
 * Which tasks an inbox read keeps, by their own fields.
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
 * Restrictions on state, terminality, priority and kind.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 */
final class TaskInboxFilter {

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $states     Restrict to these states.
	 * @param bool|null          $isTerminal Restrict on terminality, or null for both.
	 * @param string|null        $priority   Restrict to one priority.
	 * @param string|null        $kind       When set, only tasks carrying this kind.
	 */
	public function __construct(
		public readonly array $states = [],
		public readonly ?bool $isTerminal = null,
		public readonly ?string $priority = null,
		public readonly ?string $kind = null,
	) {
	}//end __construct()
}//end class
