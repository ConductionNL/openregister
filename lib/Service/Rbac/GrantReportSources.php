<?php

/**
 * The readers an object's grants report is assembled from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The access report, shares, inherited grants and rule fit of one object.
 *
 * A constructor bundle, autowired by Nextcloud: it carries no behaviour.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class GrantReportSources {

	/**
	 * Bundle the collaborators.
	 *
	 * @param ObjectAccessReport   $accessReport Who may reach the object, and why.
	 * @param ObjectShareReader    $shares       The object's share grants.
	 * @param InheritedGrantLister $inherited    Grants inherited from a parent object.
	 * @param GrantRuleFit         $rules        Whether a grant fits the schema's rules.
	 */
	public function __construct(
		public readonly ObjectAccessReport $accessReport,
		public readonly ObjectShareReader $shares,
		public readonly InheritedGrantLister $inherited,
		public readonly GrantRuleFit $rules,
	) {
	}//end __construct()
}//end class
