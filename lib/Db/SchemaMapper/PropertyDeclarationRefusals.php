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
use OCA\OpenRegister\Service\Schemas\ExtendingFormValidatorInterface;
use OCA\OpenRegister\Service\Schemas\PropertyVocabularyException;
use OCA\OpenRegister\Service\Schemas\SchemaDeclarationValidators;

/**
 * Save-time checks of declarations that throw their own typed refusal.
 *
 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
 */
final class PropertyDeclarationRefusals {

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
			message: 'Invalid ' . ExtendingFormValidatorInterface::ANNOTATION . " declaration: '{$keys}'. "
			. 'Read /api/schemas/property-vocabulary for the keys a form may forward.',
			errors: $errors
		);
	}//end checkExtendingForm()

	/**
	 * The errors of every extending-form declaration on the schema.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function extendingFormErrors(Schema $schema): array {
		$declarations = SchemaDeclarationValidators::extendingForm();
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
