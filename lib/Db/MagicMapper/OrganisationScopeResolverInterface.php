<?php

/**
 * OrganisationScopeResolverInterface: the organisation-boundary decision as callers outside lib/Db use it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * The organisation scope modes, and the decision which rows the current caller may see.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own. Bound to MagicOrganizationHandler.
 */
interface OrganisationScopeResolverInterface {

	/**
	 * Every row is visible — a trusted system context, or an admin with the
	 * bypass enabled outside SaaS mode.
	 */
	public const SCOPE_ALL = 'all';

	/**
	 * No row is visible — a non-admin with no active organisation.
	 */
	public const SCOPE_NONE = 'none';

	/**
	 * Only rows with NO organisation — an admin with no active organisation.
	 */
	public const SCOPE_NULL_ONLY = 'null-only';

	/**
	 * Rows in the caller's active organisation(s).
	 */
	public const SCOPE_IN = 'in';

	/**
	 * Rows in the caller's active organisation(s), PLUS rows with no
	 * organisation at all. Admins, or a caller whose register-cascade grant
	 * admits org-less rows (see OrganisationlessAdmission::admit()). This is the mode the aggregation API
	 * used to get wrong: it rendered only the `IN` half, and SQL `=` / `IN`
	 * never match NULL, so org-less rows vanished from every KPI.
	 */
	public const SCOPE_IN_OR_NULL = 'in-or-null';

	/**
	 * Decide WHICH rows the current caller may see, without building any SQL.
	 *
	 * This is the single source of truth for the organisation boundary. It was
	 * extracted from {@see MagicOrganizationHandler::applyOrganizationFilter()} because a SECOND
	 * implementation had grown in `AggregationRunner::tryNativeAggregation()`,
	 * where the whole rule had been flattened to a bare
	 * `_organisation = :activeOrg`. SQL `=` never matches NULL, so every object
	 * with no organisation was invisible to the aggregation API while the list
	 * API returned it — measured 2026-08-30 on decidiq/meeting: four meetings
	 * listed, one of them org-less, `count` answered 3, and
	 * `filter[lifecycle]=closed` answered 0 for a meeting that plainly exists.
	 * Every KPI tile in the fleet reads that endpoint, so each one silently
	 * under-reported.
	 *
	 * Returning a DECISION rather than a query fragment is the point: a caller
	 * that cannot render one of these modes must refuse to run rather than
	 * approximate it, and the rule itself now lives in exactly one place.
	 *
	 * @param bool $adminBypassEnabled Whether the caller honours the admin bypass (disabled in SaaS mode).
	 * @param int|null $registerId The register of the table being read, for shared master data.
	 * @param int|null $schemaId The schema of the table being read, for shared master data.
	 *
	 * @return array{mode: string, uuids: array<int, string>} `mode` is one of the SCOPE_* constants.
	 */
	public function resolveOrganizationScope(
		bool $adminBypassEnabled = false,
		?int $registerId = null,
		?int $schemaId = null,
	): array;
}//end interface
