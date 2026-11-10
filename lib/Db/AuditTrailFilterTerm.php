<?php

/**
 * AuditTrailFilterTerm
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * One scalar filter on an audit trail listing: an allowlisted column and the
 * value the caller sent for it.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
final class AuditTrailFilterTerm {

	/**
	 * Constructor.
	 *
	 * @param string $field The allowlisted column.
	 * @param mixed  $value The value: 'IS NULL', 'IS NOT NULL', an `action` prefix ending in `.*`,
	 *                      a comma-separated list, or a single value.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function __construct(
		public readonly string $field,
		public readonly mixed $value,
	) {
	}//end __construct()
}//end class
