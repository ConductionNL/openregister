<?php

/**
 * One request for the matrix rows an app provides to a schema.
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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Schema;

/**
 * The schema, the provider asked and the matrix field the rows are read against.
 * Built and consumed by MatrixRowsFromApps only; it never leaves that class.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class AppRowsRun {
	/**
	 * Set the fields.
	 *
	 * @param Schema $schema   The schema whose matrix this is.
	 * @param string $provider The provider id.
	 * @param string $field    The matrix field.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly string $provider,
		public readonly string $field,
	) {
	}//end __construct()
}//end class
