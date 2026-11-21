<?php

/**
 * ProcessDeclarationRefusals: process declarations a schema save refuses with their own typed refusal.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\BulkJob\ReversibilityDeclarationException;
use OCA\OpenRegister\Service\Decision\ReviewDeclarationException;
use OCA\OpenRegister\Service\Rules\DependentValueDeclarationException;
use OCA\OpenRegister\Service\Schemas\ProcessDeclarationValidators;

/**
 * Save-time checks of the dependent-value, reversibility and review declarations.
 *
 * Processes contributes them to the schema save through SchemaDeclarationChecksEvent.
 *
 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
 */
final class ProcessDeclarationRefusals {

	/**
	 * Validate the two property-level rule annotations.
	 *
	 * REFUSES, it does not warn. A table naming a property the schema does not
	 * declare, or a value the controlling property cannot take, does not fail
	 * loudly at object save: it constrains nothing, and the object it was
	 * written to guard saves cleanly. Storing it advisory would be storing a
	 * rule its author believes is enforced.
	 *
	 * `x-openregister-default-expression` rides along for the same reason: an
	 * expression the evaluator cannot dispatch does not fail at save, it fails
	 * on the first create, one object at a time, for whoever happens to be
	 * using the register that day.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @throws DependentValueDeclarationException When a declaration is malformed or names nothing.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The expression shape check reads one constant
	 *   operator table and holds no state; it is the same walk the evaluator dispatches on.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function checkDependentValues(Schema $schema): void {
		$properties = $schema->getProperties();
		if ($properties === []) {
			return;
		}

		$errors = array_merge(
			ProcessDeclarationValidators::dependentValues()->validate(['properties' => $properties]),
			ProcessDeclarationValidators::expressionDefaultRefusals(properties: $properties)
		);
		if ($errors !== []) {
			throw new DependentValueDeclarationException(errors: $errors);
		}
	}//end checkDependentValues()

	/**
	 * Refuse a schema that declares an action reversible that cannot be.
	 *
	 * Validated at the mapper because this is the one choke point the create,
	 * update and file-upload paths all pass through, the same reason the
	 * relation declarations are checked here.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @return void
	 *
	 * @throws ReversibilityDeclarationException When a declaration contradicts itself.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function checkReversibility(Schema $schema): void {
		$errors = ProcessDeclarationValidators::reversibility()->validate(configuration: ($schema->getConfiguration() ?? []));
		if ($errors !== []) {
			throw new ReversibilityDeclarationException(errors: $errors);
		}
	}//end checkReversibility()

	/**
	 * Refuse an `x-openregister-review` declaration the ownership rule cannot honour.
	 *
	 * Refused rather than logged: a mistyped ownership read as open would leave
	 * a four-eyes review open while its author believes it is closed.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @return void
	 *
	 * @throws ReviewDeclarationException When the declaration is unsound.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function checkReview(Schema $schema): void {
		$errors = ProcessDeclarationValidators::review()->validate(configuration: ($schema->getConfiguration() ?? []));
		if ($errors !== []) {
			throw new ReviewDeclarationException(errors: $errors);
		}
	}//end checkReview()
}//end class
