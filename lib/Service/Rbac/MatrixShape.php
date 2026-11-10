<?php

/**
 * What a department matrix row is read against: the schema's properties and the matrix field.
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
 * The schema's properties, which a `where` key must name, and the matrix field, which a `where` may not repeat.
 *
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */
final class MatrixShape {
	/**
	 * Set the fields.
	 *
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param string               $field      The matrix field.
	 */
	public function __construct(
		public readonly array $properties,
		public readonly string $field,
	) {
	}//end __construct()
}//end class
