<?php

/**
 * OpenRegister bulk row checks
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The per-row checks a bulk row goes through: access, schema validation and the decision-table rules.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class BulkRowChecks {
	/**
	 * Bundle the services.
	 *
	 * @param PermissionHandlerInterface|null       $permissionHandler Permission handler for per-object RBAC enforcement;
	 *                                                        wave-12 Fix 3 per-row RBAC + appendOnly gate
	 * @param ValidateObjectInterface|null $validateHandler   Validation handler for per-object schema validation;
	 *                                                        wave-12 Fix 3 per-row JSON-Schema validation
	 * @param BulkRowRules|null            $rowRules          The decision-table rules, property authorization and save
	 *                                                        events a bulk row goes through. Null only in legacy fixtures.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly ?PermissionHandlerInterface $permissionHandler = null,
		public readonly ?ValidateObjectInterface $validateHandler = null,
		public readonly ?BulkRowRules $rowRules = null,
	) {
	}//end __construct()
}//end class
