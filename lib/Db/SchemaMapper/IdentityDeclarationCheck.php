<?php

/**
 * IdentityDeclarationCheck: save-time refusal of an identity a schema cannot keep.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Schemas\SchemaDeclarationValidators;

/**
 * Refuses a schema that declares two identities, or an identity on a `report` constraint.
 *
 * Either one would be a key that can match two records. The refusal names the
 * constraint, and its "Invalid" wording is what makes SchemasController answer 400.
 *
 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
 */
final class IdentityDeclarationCheck {

	/**
	 * Refuse the save when the identity declaration is unsound.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @throws Exception When the schema declares an identity it cannot keep.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
	 */
	public function check(Schema $schema): void {
		$refusals = SchemaDeclarationValidators::uniqueConstraints()->identityRefusals(configuration: $schema->getConfiguration());

		(new AnnotationFaults(schema: $schema, key: 'uniqueConstraints'))->refuseInvalid(errors: $refusals);
	}//end check()
}//end class
