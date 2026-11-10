<?php

/**
 * OrganisationContextAttributionInterface: what callers outside the component use of OrganisationContextAttribution.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
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

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\OrganisationContext;

/**
 * Declares only what callers outside the component use of OrganisationContextAttribution; the rest
 * of the class stays its own.
 */
interface OrganisationContextAttributionInterface {

	/**
	 * Stamp the current organisation context onto a row being built.
	 *
	 * @param AuditTrail $auditTrail The row being built.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/act-for-an-organisation/specs/rbac-scopes/spec.md
	 */
	public function apply(AuditTrail $auditTrail): void;
}//end interface
