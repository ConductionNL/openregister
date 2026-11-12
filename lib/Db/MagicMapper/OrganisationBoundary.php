<?php

/**
 * OrganisationBoundary
 *
 * The organisation boundary one read applies.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * The organisation boundary one read of a magic table applies: the register
 * and schema the organisation scope is decided for, whether rows without an
 * organisation are admitted through the RBAC cascade, and the cross-
 * organisation RBAC branch that is OR'd in beside the scope (null when there
 * is none). It replaces the untyped `['registerId', 'schemaId',
 * 'withOrgless']` array the handler passed between its boundary helpers
 * (ADR-011 Rule 2, value object).
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class OrganisationBoundary
{

	/**
	 * Constructor.
	 *
	 * @param int|null $registerId  The register the table belongs to.
	 * @param int|null $schemaId    The schema of the table.
	 * @param bool     $withOrgless Whether rows without an organisation are admitted.
	 * @param mixed    $crossBranch The cross-organisation branch OR'd in; null for none.
	 */
	public function __construct(
		public readonly ?int $registerId,
		public readonly ?int $schemaId,
		public readonly bool $withOrgless = false,
		public readonly mixed $crossBranch = null,
	) {
	}//end __construct()

	/**
	 * The register+schema pair the organisation scope is decided for.
	 *
	 * @return OrganisationScopeTable
	 */
	public function scopeTable(): OrganisationScopeTable {
		return new OrganisationScopeTable(registerId: $this->registerId, schemaId: $this->schemaId);
	}//end scopeTable()
}//end class
