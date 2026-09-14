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
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\VocabularyImportService;

/**
 * Validates a concept against the shape its own scheme declares.
 */
class ConceptShapeGuard {

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
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
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
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function violations(array $object, Schema $schema): array {
		if ($this->isConcept(schema: $schema) === false) {
			return [];
		}

		$schemeUri = $this->schemeUriOf(object: $object);
		if ($schemeUri === null) {
			return [];
		}

		$shape = $this->concepts->conceptShape(schemeUri: $schemeUri);
		if ($shape === []) {
			return [];
		}

		$failed = $this->lifecycle->validateAgainstShape(concept: $object, shape: $shape);

		$errors = [];
		foreach ($failed as $field) {
			$errors[$field] = sprintf(
				'The value "%s" is missing the field "%s", which its scheme requires of every value.',
				(string)($object['uri'] ?? 'unnamed'),
				$field
			);
		}

		return $errors;
	}//end violations()

	/**
	 * Report which concepts of an import batch fail their scheme's shape.
	 *
	 * The importer needs the same answer the write guard gives, per concept,
	 * without the write: a batch of forty resultaattypen with two missing a
	 * grondslag must report those two by name and import the other thirty-eight.
	 *
	 * @param array<int,array<string,mixed>> $concepts The concepts about to be imported.
	 * @param string $schemeUri The scheme they belong to.
	 *
	 * @return array<string,array<int,string>> The failed field names, keyed by concept uri.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function invalidInBatch(array $concepts, string $schemeUri): array {
		$shape = $this->concepts->conceptShape(schemeUri: $schemeUri);
		if ($shape === []) {
			return [];
		}

		$invalid = [];
		foreach ($concepts as $concept) {
			if (is_array($concept) === false) {
				continue;
			}

			$failed = $this->lifecycle->validateAgainstShape(concept: $concept, shape: $shape);
			if ($failed !== []) {
				$invalid[(string)($concept['uri'] ?? '')] = $failed;
			}
		}

		return $invalid;
	}//end invalidInBatch()

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
		$reference = ($object['inScheme'] ?? null);
		if (is_array($reference) === true) {
			$reference = ($reference['uri'] ?? ($reference['id'] ?? ($reference['uuid'] ?? null)));
		}

		if (is_string($reference) === false) {
			return null;
		}

		$reference = trim($reference);
		if ($reference === '') {
			return null;
		}

		// A reference that is already a uri resolves directly.
		if (str_contains($reference, '://') === true) {
			return $reference;
		}

		$scheme = $this->concepts->schemeByReference(reference: $reference);
		if ($scheme === null) {
			return null;
		}

		$uri = trim((string)($scheme['uri'] ?? ''));

		if ($uri === '') {
			return null;
		}

		return $uri;
	}//end schemeUriOf()
}//end class
