<?php

/**
 * PermissionReportScopeInterface: the part of PermissionReportScope that the permissions audit controller uses.
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
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The methods of PermissionReportScope that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface PermissionReportScopeInterface {

	/**
	 * The registers the report covers.
	 *
	 * @param string|null $filter Optional register filter (id|uuid|slug).
	 *
	 * @return Register[] The registers, empty when the filter matches nothing.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function registers(?string $filter): array;

	/**
	 * The schemas the report covers.
	 *
	 * @param string|null $filter Optional schema filter (id|uuid|slug).
	 *
	 * @return Schema[] The schemas, empty when the filter matches nothing.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function schemas(?string $filter): array;
}//end interface
