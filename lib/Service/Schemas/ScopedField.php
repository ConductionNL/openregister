<?php

/**
 * OpenRegister scoped property governance: a property at its scope.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * A schema property and the scope it is declared at (ADR-011 Rule 2).
 *
 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
 */
final class ScopedField {
	/**
	 * Name the property and its scope.
	 *
	 * @param string $property The property name.
	 * @param string $scope    The trimmed scope it is declared at.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	public function __construct(
		public readonly string $property,
		public readonly string $scope,
	) {
	}//end __construct()
}//end class
