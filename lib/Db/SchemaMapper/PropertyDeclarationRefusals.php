<?php

/**
 * PropertyDeclarationRefusals: declarations on properties and actions, refused at save.
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
use OCA\OpenRegister\Service\BulkJob\ReversibilityAnnotationValidator;
use OCA\OpenRegister\Service\BulkJob\ReversibilityDeclarationException;
use OCA\OpenRegister\Service\Rules\DependentValueDeclarationException;
use OCA\OpenRegister\Service\Rules\DependentValueValidator;
use OCA\OpenRegister\Service\Rules\ExpressionDefaultResolver;
use OCA\OpenRegister\Service\Schemas\ExtendingFormDeclaration;
use OCA\OpenRegister\Service\Schemas\PropertyVocabularyException;

/**
 * Save-time checks of declarations that throw their own typed refusal.
 *
 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
 */
final class PropertyDeclarationRefusals {

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
			(new DependentValueValidator())->validate(['properties' => $properties]),
			ExpressionDefaultResolver::validateDeclarations(properties: $properties)
		);
		if ($errors !== []) {
			throw new DependentValueDeclarationException(errors: $errors);
		}
	}//end checkDependentValues()

	/**
	 * Validate the optional `x-openregister-extends-form` annotation.
	 *
	 * An app whose own form authors schema properties declares which vocabulary
	 * keys that form forwards. A key nobody defines is refused naming it, in
	 * both directions: a type the vocabulary does not hold fails the property,
	 * and a forwarded key it does not hold fails the declaration. Silence here
	 * is how an app ends up narrowing a platform contract by ten capabilities
	 * and nobody can tell it was deliberate.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @throws PropertyVocabularyException When a declaration forwards a key the vocabulary does not hold.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function checkExtendingForm(Schema $schema): void {
		$errors = $this->extendingFormErrors(schema: $schema);
		if ($errors === []) {
			return;
		}

		$keys = implode(', ', array_map(static fn (array $error): string => $error['key'], $errors));
		throw new PropertyVocabularyException(
			message: 'Invalid ' . ExtendingFormDeclaration::ANNOTATION . " declaration: '{$keys}'. "
			. 'Read /api/schemas/property-vocabulary for the keys a form may forward.',
			errors: $errors
		);
	}//end checkExtendingForm()

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
		$errors = (new ReversibilityAnnotationValidator())->validate(configuration: ($schema->getConfiguration() ?? []));
		if ($errors !== []) {
			throw new ReversibilityDeclarationException(errors: $errors);
		}
	}//end checkReversibility()

	/**
	 * The errors of every extending-form declaration on the schema.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function extendingFormErrors(Schema $schema): array {
		$declarations = new ExtendingFormDeclaration();
		$found = $declarations->fromSchema(
			configuration: ($schema->getConfiguration() ?? []),
			properties: $schema->getProperties()
		);

		$errors = [];
		foreach ($found as $path => $annotation) {
			$errors = array_merge($errors, $declarations->validate(annotation: $annotation, path: $path));
		}

		return $errors;
	}//end extendingFormErrors()
}//end class
