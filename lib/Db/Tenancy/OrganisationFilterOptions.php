<?php

/**
 * OrganisationFilterOptions: how one query is scoped to the caller's organisations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Tenancy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\Tenancy;

/**
 * The optional settings of an organisation filter (ADR-011 Rule 2 Options object).
 *
 * The defaults are the ones MultiTenancyTrait::applyOrganisationFilter() has
 * always applied: the `organisation` column, no NULL-organisation rows, no
 * table alias, multitenancy on.
 */
final class OrganisationFilterOptions {
	/**
	 * Build the options.
	 *
	 * @param string $columnName          The organisation column.
	 * @param bool   $allowNullOrg        Whether admins see rows without an organisation.
	 * @param string $tableAlias          Optional table alias for the column.
	 * @param bool   $multiTenancyEnabled Whether the filter applies at all.
	 */
	public function __construct(
		public readonly string $columnName = 'organisation',
		public readonly bool $allowNullOrg = false,
		public readonly string $tableAlias = '',
		public readonly bool $multiTenancyEnabled = true,
	) {
	}//end __construct()
}//end class
