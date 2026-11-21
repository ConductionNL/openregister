<?php

/**
 * AdministeredValidationCheck: save-time refusal of an administered validation that cannot be enforced.
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
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rules\AdministeredValidations;

/**
 * Refuses a schema save whose `x-openregister-validations` declares a check
 * that cannot run as written: no condition, an unknown severity, no message,
 * or a property the schema does not declare (task 4.1). Without this the
 * declaration saves with a 200 and the check it describes is never enforced
 * the way its author read it.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */
final class AdministeredValidationCheck {

	/**
	 * Refuse the save when a declared validation is malformed.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws \Exception When a validation cannot be saved, naming it.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function check(Schema $schema): void {
		$annotation = (($schema->getConfiguration() ?? [])[AdministeredValidations::ANNOTATION] ?? null);
		if (is_array($annotation) === false) {
			return;
		}

		$declared = [];
		foreach (array_keys(($schema->getProperties() ?? [])) as $property) {
			$declared[] = (string)$property;
		}

		$refusal = (new AdministeredValidations())->refusalFor(annotation: $annotation, declaredProperties: $declared);
		if ($refusal !== null) {
			(new AnnotationFaults(schema: $schema, key: AdministeredValidations::ANNOTATION))->refuseWith(messages: [$refusal]);
		}
	}//end check()
}//end class
