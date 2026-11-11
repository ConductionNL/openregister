<?php

/**
 * OpenRegister ConceptShapeGuard
 *
 * A code list item is an object, so it is validated like one (design.md D-2).
 * A scheme declaring that its concepts carry `bewaartermijn` and `grondslag`
 * gets concepts that are refused when they do not, on import and on save
 * alike, and the refusal names the field.
 *
 * This guard only fires on a write to the vocabulary register's `concept`
 * schema. Every other object passes through it in one array comparison, which
 * is what keeps it on the unconditional write path.
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
use OCA\OpenRegister\Service\VocabularyImportService;

/**
 * Validates a concept against the shape its own scheme declares.
 */
class ConceptShapeGuard implements ConceptShapeGuardInterface {

	/**
	 * Constructor.
	 *
	 * @param ConceptRepository $concepts Reads the scheme a concept belongs to.
	 * @param ConceptLifecycle $lifecycle Runs the shape comparison.
	 */
	public function __construct(
		private readonly ConceptRepository $concepts,
		private readonly ConceptLifecycle $lifecycle,
	) {

	}//end __construct()

	/**
	 * Whether this schema is the vocabulary register's `concept` schema.
	 *
	 * Resolved from the schema's slug rather than sniffed from the payload: an
	 * ordinary object that happens to carry an `inScheme` key is not a concept,
	 * and a concept saved with every field missing still is one, which is
	 * exactly the write this guard has to refuse.
	 *
	 * @param Schema $schema The schema being written against.
	 *
	 * @return boolean True when the object is a concept.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function isConcept(Schema $schema): bool {
		return ((string)$schema->getSlug() === VocabularyImportService::SCHEMA_CONCEPT);
	}//end isConcept()

	/**
	 * The fields a concept fails its scheme's declared shape on.
	 *
	 * @param array<string,mixed> $object The submitted concept data.
	 * @param Schema $schema The schema being written against.
	 *
	 * @return array<string,string> The refusals keyed by field name, empty when the concept is valid.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function violations(array $object, Schema $schema): array {
		$shape = $this->shapeFor(object: $object, schema: $schema);
		if ($shape === []) {
			return [];
		}

		$errors = [];
		foreach ($this->lifecycle->validateAgainstShape(concept: $object, shape: $shape) as $field) {
			$errors[$field] = $this->missingFieldMessage(object: $object, field: $field);
		}

		return $errors;
	}//end violations()

	/**
	 * The refusal for one field a concept fails its scheme's shape on.
	 *
	 * @param array<string,mixed> $object The submitted concept data.
	 * @param string              $field  The failing field.
	 *
	 * @return string The refusal message.
	 */
	private function missingFieldMessage(array $object, string $field): string {
		return sprintf(
			'The value "%s" is missing the field "%s", which its scheme requires of every value.',
			(string) ($object['uri'] ?? 'unnamed'),
			$field
		);
	}//end missingFieldMessage()

	/**
	 * The shape a submitted concept's scheme declares, or none.
	 *
	 * @param array<string,mixed> $object The submitted concept data.
	 * @param Schema              $schema The schema being written against.
	 *
	 * @return array<string,mixed> The declared shape, empty for a non-concept or an unresolvable scheme.
	 */
	private function shapeFor(array $object, Schema $schema): array {
		if ($this->isConcept(schema: $schema) === false) {
			return [];
		}

		$schemeUri = $this->schemeUriOf(object: $object);
		if ($schemeUri === null) {
			return [];
		}

		return $this->concepts->conceptShape(schemeUri: $schemeUri);
	}//end shapeFor()

	/**
	 * The uri of the scheme a submitted concept says it belongs to.
	 *
	 * `inScheme` stores the scheme object's uuid, so the uri is read back off
	 * the scheme itself. A concept naming a scheme this instance does not hold
	 * is not this guard's refusal: it is the relation dialect's.
	 *
	 * @param array<string,mixed> $object The submitted concept data.
	 *
	 * @return string|null The scheme's uri, or null when unresolvable.
	 */
	private function schemeUriOf(array $object): ?string {
		$reference = $this->referenceOf(inScheme: ($object['inScheme'] ?? null));
		if ($reference === null) {
			return null;
		}

		// A reference that is already a uri resolves directly.
		if (str_contains($reference, '://') === true) {
			return $reference;
		}

		$scheme = $this->concepts->schemeByReference(reference: $reference);

		return $this->trimmedOrNull(value: (string) ($scheme['uri'] ?? ''));
	}//end schemeUriOf()

	/**
	 * The scheme reference an `inScheme` value carries, trimmed.
	 *
	 * @param mixed $inScheme The raw `inScheme` value: a string, or a `{uri|id|uuid}` map.
	 *
	 * @return string|null The reference, or null when it names none.
	 */
	private function referenceOf(mixed $inScheme): ?string {
		if (is_array($inScheme) === true) {
			$inScheme = $this->firstSet(map: $inScheme);
		}

		if (is_string($inScheme) === false) {
			return null;
		}

		return $this->trimmedOrNull(value: $inScheme);
	}//end referenceOf()

	/**
	 * The first of `uri`, `id`, `uuid` a reference map sets to a non-null value.
	 *
	 * @param array<string,mixed> $map The reference map.
	 *
	 * @return mixed The value, or null when none is set.
	 */
	private function firstSet(array $map): mixed {
		foreach (['uri', 'id', 'uuid'] as $key) {
			if (isset($map[$key]) === true) {
				return $map[$key];
			}
		}

		return null;
	}//end firstSet()

	/**
	 * A string trimmed, or null when nothing is left.
	 *
	 * @param string $value The raw string.
	 *
	 * @return string|null The trimmed string, or null when blank.
	 */
	private function trimmedOrNull(string $value): ?string {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end trimmedOrNull()
}//end class
