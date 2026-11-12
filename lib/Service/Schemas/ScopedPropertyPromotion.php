<?php

/**
 * OpenRegister scoped property governance: a promotion.
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

use OCA\OpenRegister\Db\Schema;

/**
 * One promotion of a scoped property to an ordinary one: the schema, and the property with the scope it leaves (ADR-011 Rule 2).
 *
 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
 */
final class ScopedPropertyPromotion {
	/**
	 * Describe the promotion.
	 *
	 * @param Schema      $schema The schema the property belongs to.
	 * @param ScopedField $field  The promoted property and the scope it left.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly ScopedField $field,
	) {
	}//end __construct()
}//end class
