<?php

/**
 * Stamps the organisation a write acted for onto the audit row it produced.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/act-for-an-organisation/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\OrganisationContext;

/**
 * Records the organisation context in force on an audit row.
 *
 * A write inside an organisation context has no Nextcloud user, so the row's
 * user reads as the system. `resultSummary['organisationContext']` names the
 * organisation the code acted for. It sits inside the canonical JSON the hash
 * chain seals, so it MUST be applied before the row is inserted.
 */
class OrganisationContextAttribution implements OrganisationContextAttributionInterface {

	/**
	 * Stamp the current organisation context onto a row being built.
	 *
	 * @param AuditTrail $auditTrail The row being built.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/act-for-an-organisation/specs/rbac-scopes/spec.md
	 * @SuppressWarnings(PHPMD.StaticAccess) OrganisationContext is an ambient scope marker, read statically by design.
	 */
	public function apply(AuditTrail $auditTrail): void {
		$organisationUuid = OrganisationContext::current();
		if ($organisationUuid === null) {
			return;
		}

		// Merge rather than replace: purpose, token and tool attribution write here too.
		$summary = ($auditTrail->getResultSummary() ?? []);
		$summary['organisationContext'] = $organisationUuid;
		$auditTrail->setResultSummary($summary);
	}//end apply()
}//end class
