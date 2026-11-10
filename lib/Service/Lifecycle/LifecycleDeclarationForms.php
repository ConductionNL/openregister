<?php

/**
 * What a lifecycle's endpoint declarations are allowed to say.
 *
 * `initial` and `final` are the same idea twice: each names either a literal
 * state or a `{ from, field }` reference to a row that carries the answer. The
 * shape rule lives here rather than in any one reader, because `final` has
 * three of them (the schema-save validator, the archival nomination service and
 * the temporal sweep), and a form the validator accepts while a reader does not
 * recognise it is a schema that saves and then does nothing. That is the class
 * of silence this whole change exists to end.
 *
 * Instance methods, not statics, so every reader declares the dependency and
 * a test can substitute one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * Tells the accepted forms of `initial` and `final` apart, and refuses the rest.
 *
 * @psalm-suppress UnusedClass
 */
class LifecycleDeclarationForms {

	/**
	 * The refusal of a malformed `final`.
	 *
	 * @var array{code: string, message: string}
	 */
	private const FINAL_MALFORMED = [
		'code' => 'lifecycle-final-malformed',
		'message' => 'x-openregister-lifecycle.final must be a list of states, or an object '
			. 'with non-empty "from" (the schema the lifecycle field references) and "field" '
			. '(the property on that row saying whether it is an end) strings.',
	];

	/**
	 * Is this value the reference form rather than a list of states?
	 *
	 * The two forms are told apart by shape, not by a flag: a list of state
	 * strings has no `from` and no `field`, and the reference form is never a
	 * list. A half-written reference still answers true here, so the shape
	 * check below reports the missing key instead of the enum reporting a
	 * state it never saw.
	 *
	 * @param mixed $final The declared `final` value.
	 *
	 * @return bool True when the value is meant as `{ from, field }`.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md
	 */
	public function isReferenceForm(mixed $final): bool {
		if (is_array($final) === false) {
			return false;
		}

		return array_key_exists('from', $final) === true || array_key_exists('field', $final) === true;
	}//end isReferenceForm()

	/**
	 * Shape-check the `final` value in its two accepted forms.
	 *
	 * Valid: a list of state values (the static form, checked against the
	 * field's enum by the caller), or the reference form
	 * `{ "from": "<schema>", "field": "<property>" }` with both keys non-empty
	 * strings. The reference form exists because a lifecycle field that is a
	 * `$ref` carries the identifier of a row, and a schema cannot list
	 * identifiers every tenant creates for itself.
	 *
	 * The refusal names the shape rather than the value, because an author who
	 * wrote `{ "from": "statusType" }` has not written a list with a bad entry,
	 * they have written half a reference, and "not in the field's enum" would
	 * send them to the enum instead of to the missing key.
	 *
	 * ⚠️ `from` here names a SCHEMA, not a reference declared in
	 * `x-openregister-references`, which is what `initial.from` names. The
	 * lifecycle value already IS the row's identifier, so nothing has to be
	 * followed to find the row; the schema is named so a value that happens to
	 * match a row in an unrelated schema cannot answer for a status.
	 *
	 * @param mixed $final The raw `final` value off the annotation.
	 *
	 * @return array{code: string, message: string}|null Error, or null when valid.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md
	 */
	public function validateForm(mixed $final): ?array {
		if ($final === null) {
			return null;
		}

		if (is_array($final) === false) {
			return self::FINAL_MALFORMED;
		}

		if ($this->isReferenceForm(final: $final) === false) {
			return null;
		}

		return $this->referenceFormError(final: $final);
	}//end validateForm()

	/**
	 * The refusal of a reference-form `final` without its two strings.
	 *
	 * @param array<string, mixed> $final The reference form.
	 *
	 * @return array{code: string, message: string}|null Error, or null when complete.
	 */
	private function referenceFormError(array $final): ?array {
		if ($this->hasText(value: $final['from'] ?? null) === true && $this->hasText(value: $final['field'] ?? null) === true) {
			return null;
		}

		return self::FINAL_MALFORMED;
	}//end referenceFormError()

	/**
	 * Whether a value is a string with something besides whitespace.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function hasText(mixed $value): bool {
		return is_string($value) === true && trim($value) !== '';
	}//end hasText()

	/**
	 * Shape-check the `initial` value in its two accepted forms.
	 *
	 * Valid: a non-empty string (literal form) or an object with non-empty
	 * string `from` and `field` keys (object form). Returns a single structured
	 * error on violation, or null when valid.
	 *
	 * ⚠️ Unlike `final.from`, `initial.from` names a reference declared in
	 * `x-openregister-references`: at creation there is no value yet, so
	 * something has to be followed to reach the related object.
	 *
	 * @param mixed $initial The raw `initial` value off the annotation.
	 *
	 * @return array{code: string, message: string}|null Error, or null when valid.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function validateInitialForm(mixed $initial): ?array {
		if (is_string($initial) === true) {
			return null;
		}

		if (is_array($initial) === false) {
			$message = 'x-openregister-lifecycle.initial must be a string or an object with "from" and "field".';
			return ['code' => 'lifecycle-initial-malformed', 'message' => $message];
		}

		if ($this->namesFromAndField(initial: $initial) === true) {
			return null;
		}

		$message = 'x-openregister-lifecycle.initial object form must declare non-empty "from" and "field" strings.';

		return ['code' => 'lifecycle-initial-malformed', 'message' => $message];
	}//end validateInitialForm()

	/**
	 * Whether an object-form `initial` has non-empty `from` and `field` strings.
	 *
	 * @param array<string, mixed> $initial The object form.
	 *
	 * @return bool
	 */
	private function namesFromAndField(array $initial): bool {
		$from = ($initial['from'] ?? '');
		$field = ($initial['field'] ?? '');

		return is_string($from) === true && $from !== '' && is_string($field) === true && $field !== '';
	}//end namesFromAndField()

	/**
	 * Check the optional `initial` and `final` declarations of a field without
	 * an enum: both are shape-checked, never enum-checked, because there the
	 * reference form is the only one that can name either endpoint.
	 *
	 * @param array<string, mixed> $annotation The normalised annotation block.
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md
	 */
	public function validateEndpoints(array $annotation): array {
		$errors = [];
		if (isset($annotation['initial']) === true) {
			$errors[] = $this->validateInitialForm(initial: $annotation['initial']);
		}

		$errors[] = $this->validateForm(final: ($annotation['final'] ?? null));

		return array_values(array_filter($errors, static fn (?array $error): bool => $error !== null));
	}//end validateEndpoints()
}//end class
