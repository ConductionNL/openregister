<?php

/**
 * One matrix row's `where` being validated.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The row's name in messages and the shape it is read against. Built and consumed by DepartmentMatrixValidator only; it never leaves that class.
 *
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */
final class MatrixRowRun {
	/**
	 * Set the fields.
	 *
	 * @param string      $name  The row, as messages name it.
	 * @param MatrixShape $shape The schema's properties and the matrix field.
	 */
	public function __construct(
		public readonly string $name,
		public readonly MatrixShape $shape,
	) {
	}//end __construct()
}//end class
