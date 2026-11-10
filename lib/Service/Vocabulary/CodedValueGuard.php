<?php

/**
 * OpenRegister CodedValueGuard
 *
 * The write half of the code-list lifecycle. It answers one question for a
 * whole object: which of its coded values does the vocabulary refuse, and why.
 *
 * Three refusals live here, and they are all refusals of a WRITE, never of a
 * read (design.md D-1, ADR-005). A record that already holds a retired value
 * is read back unchanged and resolves its label; only a new write of that
 * value is refused. That asymmetry is the whole point: fail closed for new
 * data, never break old data.
 *
 * It also computes the rolled-up score, because the score is derived from the
 * same concepts the guard has already read, and reading them twice on every
 * save would be the expensive way to get the same number.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\CodedValueException;

/**
 * Refuses the coded values a scheme's lifecycle rules say cannot be written.
 */
class CodedValueGuard implements CodedValueGuardInterface {

	/**
	 * The write refusals each resolved value is judged against.
	 *
	 * @var CodedValueRefusals
	 */
	private readonly CodedValueRefusals $refusals;

	/**
	 * Constructor.
	 *
	 * @param ConceptSchemeAccess             $scheme             Reads schemes, their concepts, hierarchy and lifecycle.
	 * @param CodedPropertyDeclarationFactory $declarationFactory Reads a schema's coded declarations.
	 */
	public function __construct(
		private readonly ConceptSchemeAccess $scheme,
		private readonly CodedPropertyDeclarationFactory $declarationFactory,
	) {
		$this->refusals = new CodedValueRefusals(lifecycle: $scheme->lifecycle, hierarchy: $scheme->hierarchy);
	}//end __construct()

	/**
	 * Refuse the object's coded values that the vocabulary does not allow.
	 *
	 * A schema with no coded property is untouched and costs one array read,
	 * which is what keeps this on the unconditional write path rather than
	 * behind `hardValidation`: an expiry that only applies to schemas with
	 * hard validation on is an expiry a gemeente cannot rely on.
	 *
	 * @param CodedValueWrite $write The submitted object data, the schema it is written against and the instant to judge windows at (now when null).
	 *
	 * @return void
	 *
	 * @throws CodedValueException When a coded value is refused.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function enforce(CodedValueWrite $write): void {
		$this->refuse(errors: $this->collectViolations(write: $write));
	}//end enforce()

	/**
	 * Throw the refusals, when there are any.
	 *
	 * @param array<string,string> $errors The refusals keyed by property name.
	 *
	 * @return void
	 *
	 * @throws CodedValueException When at least one coded value is refused.
	 */
	private function refuse(array $errors): void {
		if ($errors === []) {
			return;
		}

		throw new CodedValueException(
			message: implode(' ', array_values($errors)),
			errors: $errors
		);
	}//end refuse()

	/**
	 * Every refusal the object's coded values earn, keyed by property name.
	 *
	 * Split from {@see self::enforce()} so a caller that wants to REPORT
	 * rather than refuse (a preview, a revalidation sweep) reads the same
	 * list without catching an exception to get it.
	 *
	 * @param CodedValueWrite $write The submitted object data, its schema and the instant to judge windows at.
	 *
	 * @return array<string,string> The refusals keyed by property name.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function collectViolations(CodedValueWrite $write): array {
		$declarations = $this->declarationsOf(schema: $write->schema);
		if ($declarations === []) {
			return [];
		}

		$check = new CodedValueCheck(object: $write->object, asOf: $write->at);
		return $this->violationsOf(declarations: $declarations, check: $check);
	}//end collectViolations()

	/**
	 * The coded declarations of a schema's properties, keyed by property name.
	 *
	 * @param Schema $schema The schema being written against.
	 *
	 * @return array<string,CodedPropertyDeclaration> The declarations.
	 */
	private function declarationsOf(Schema $schema): array {
		return $this->declarationFactory->fromProperties(properties: ($schema->getProperties() ?? []));
	}//end declarationsOf()

	/**
	 * The refusal of every coded property that earns one, keyed by property name.
	 *
	 * @param array<string,CodedPropertyDeclaration> $declarations The schema's coded declarations.
	 * @param CodedValueCheck                        $check        The pass over the submitted object.
	 *
	 * @return array<string,string> The refusals keyed by property name.
	 */
	private function violationsOf(array $declarations, CodedValueCheck $check): array {
		$errors = [];
		foreach ($declarations as $property => $declaration) {
			$violation = $this->propertyViolation(subject: $check->subjectFor(property: $property, declaration: $declaration));
			if ($violation !== null) {
				$errors[$property] = $violation;
			}
		}

		return $errors;
	}//end violationsOf()

	/**
	 * The refusal a single coded property's submitted values earn, or null.
	 *
	 * Extracted from {@see self::collectViolations()} so the per-property
	 * guards (present, non-empty, scheme readable) read as one block and the
	 * per-value judgement lives behind a single call.
	 *
	 * @param CodedValueSubject $subject The property being judged.
	 *
	 * @return string|null The refusal message, or null when the values are allowed.
	 */
	private function propertyViolation(CodedValueSubject $subject): ?string {
		$values = $this->valuesOf(value: $subject->submitted());
		if ($values === []) {
			return null;
		}

		$conceptsByUri = $this->scheme->concepts->conceptsOf(schemeUri: $subject->declaration->scheme);
		if ($conceptsByUri === []) {
			// An unreadable or unseeded scheme refuses nothing: a
			// vocabulary outage must not become a write outage.
			return null;
		}

		return $this->lastViolation(subject: $subject->withConcepts(conceptsByUri: $conceptsByUri), values: $values);
	}//end propertyViolation()

	/**
	 * Judge every value of a property in order; the last refusal stands.
	 *
	 * Every value is judged even after a refusal, so the exclusive-group
	 * bookkeeping sees each one.
	 *
	 * @param CodedValueSubject $subject The property being judged, with its scheme's concepts.
	 * @param array<int,string> $values  The submitted values.
	 *
	 * @return string|null The last refusal message, or null when every value is allowed.
	 */
	private function lastViolation(CodedValueSubject $subject, array $values): ?string {
		$violation = null;
		foreach ($values as $value) {
			// An unresolved value is skipped: scheme membership is refused by
			// `property-code-list-from-concept-scheme`, and saying it twice
			// would give one mistake two different messages.
			$concept = $this->resolve(declaration: $subject->declaration, value: $value);
			if ($concept !== null) {
				$match = $subject->matched(value: $value, concept: $concept);
				$violation = ($this->refusals->violationFor(subject: $subject, match: $match) ?? $violation);
			}
		}

		return $violation;
	}//end lastViolation()

	/**
	 * The rolled-up scores a schema's coded properties declare, keyed by the
	 * property each score is written to.
	 *
	 * @param array<string,mixed> $object The submitted object data.
	 * @param Schema $schema The schema being written against.
	 *
	 * @return array<string,float> The scores, keyed by target property.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function rolledUpScores(array $object, Schema $schema): array {
		$scores = [];
		foreach ($this->declarationsOf(schema: $schema) as $property => $declaration) {
			if ($declaration->scoreProperty !== null && array_key_exists($property, $object) === true) {
				$held = $this->heldConcepts(declaration: $declaration, value: $object[$property]);
				$scores[$declaration->scoreProperty] = $this->scheme->lifecycle->rollUpWeights(concepts: $held);
			}
		}

		return $scores;
	}//end rolledUpScores()

	/**
	 * The concepts a property's submitted values resolve to, skipping unresolved ones.
	 *
	 * @param CodedPropertyDeclaration $declaration The property's coded declaration.
	 * @param mixed                    $value       The submitted value, single or multi.
	 *
	 * @return array<int,array<string,mixed>> The resolved concepts.
	 */
	private function heldConcepts(CodedPropertyDeclaration $declaration, mixed $value): array {
		$held = [];
		foreach ($this->valuesOf(value: $value) as $stored) {
			$concept = $this->resolve(declaration: $declaration, value: $stored);
			if ($concept !== null) {
				$held[] = $concept;
			}
		}

		return $held;
	}//end heldConcepts()

	/**
	 * Resolve one stored value against a declaration's scheme.
	 *
	 * @param CodedPropertyDeclaration $declaration The property's coded declaration.
	 * @param string                   $value       The stored value.
	 *
	 * @return array<string,mixed>|null The concept, or null when the scheme does not hold it.
	 */
	private function resolve(CodedPropertyDeclaration $declaration, string $value): ?array {
		return $this->scheme->concepts->resolve(value: $value, declaration: $declaration);
	}//end resolve()

	/**
	 * Normalise a coded property's value to a list of stored strings.
	 *
	 * @param mixed $value The submitted value, single or multi.
	 *
	 * @return array<int,string> The stored values.
	 */
	private function valuesOf(mixed $value): array {
		if (is_string($value) === true) {
			$value = [$value];
		}

		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, static fn (mixed $entry): bool => is_string($entry) === true && $entry !== ''));
	}//end valuesOf()
}//end class
