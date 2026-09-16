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
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\CodedValueException;

/**
 * Refuses the coded values a scheme's lifecycle rules say cannot be written.
 */
class CodedValueGuard {

	/**
	 * Constructor.
	 *
	 * @param ConceptRepository $concepts Reads schemes and their concepts.
	 * @param ConceptLifecycle $lifecycle Answers window, group and weight.
	 * @param ConceptHierarchy $hierarchy Walks broader/narrower.
	 */
	public function __construct(
		private readonly ConceptRepository $concepts,
		private readonly ConceptLifecycle $lifecycle,
		private readonly ConceptHierarchy $hierarchy,
		private readonly CodedPropertyDeclarationFactory $declarationFactory,
	) {

	}//end __construct()

	/**
	 * Refuse the object's coded values that the vocabulary does not allow.
	 *
	 * A schema with no coded property is untouched and costs one array read,
	 * which is what keeps this on the unconditional write path rather than
	 * behind `hardValidation`: an expiry that only applies to schemas with
	 * hard validation on is an expiry a gemeente cannot rely on.
	 *
	 * @param array<string,mixed> $object The submitted object data.
	 * @param Schema $schema The schema being written against.
	 * @param DateTimeInterface|null $at The instant to judge windows at; now when null.
	 *
	 * @return void
	 *
	 * @throws CodedValueException When a coded value is refused.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function enforce(array $object, Schema $schema, ?DateTimeInterface $at = null): void {
		$errors = $this->collectViolations(object: $object, schema: $schema, at: $at);
		if ($errors === []) {
			return;
		}

		throw new CodedValueException(
			message: implode(' ', array_values($errors)),
			errors: $errors
		);
	}//end enforce()

	/**
	 * Every refusal the object's coded values earn, keyed by property name.
	 *
	 * Split from {@see self::enforce()} so a caller that wants to REPORT
	 * rather than refuse (a preview, a revalidation sweep) reads the same
	 * list without catching an exception to get it.
	 *
	 * @param array<string,mixed> $object The submitted object data.
	 * @param Schema $schema The schema being written against.
	 * @param DateTimeInterface|null $at The instant to judge windows at; now when null.
	 *
	 * @return array<string,string> The refusals keyed by property name.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function collectViolations(array $object, Schema $schema, ?DateTimeInterface $at = null): array {
		$declarations = $this->declarationFactory->fromProperties(properties: ($schema->getProperties() ?? []));
		if ($declarations === []) {
			return [];
		}

		$instant = ($at ?? new DateTimeImmutable());
		$errors = [];
		$heldBySchemeGroup = [];

		foreach ($declarations as $property => $declaration) {
			if (array_key_exists($property, $object) === false) {
				continue;
			}

			$values = $this->valuesOf(value: $object[$property]);
			if ($values === []) {
				continue;
			}

			$conceptsByUri = $this->concepts->conceptsOf(schemeUri: $declaration->scheme);
			if ($conceptsByUri === []) {
				// An unreadable or unseeded scheme refuses nothing: a
				// vocabulary outage must not become a write outage.
				continue;
			}

			foreach ($values as $value) {
				$concept = $this->concepts->resolve(
					value: $value,
					schemeUri: $declaration->scheme,
					store: $declaration->store
				);
				if ($concept === null) {
					// Membership of the scheme is the dependency change's
					// refusal (`property-code-list-from-concept-scheme`), not
					// this one's. Saying it twice would give one mistake two
					// different messages.
					continue;
				}

				$uri = (string)($concept['uri'] ?? $value);

				if ($this->lifecycle->isWithinWindow(concept: $concept, at: $instant) === false) {
					$errors[$property] = sprintf(
						'The value "%s" on "%s" is outside its validity window (%s) and can no longer be chosen.',
						$this->hierarchy->labelOf(concept: $concept, language: 'nl'),
						$property,
						$this->lifecycle->describeWindow(concept: $concept)
					);
					continue;
				}

				if ($declaration->leafOnly === true
					&& $this->hierarchy->isLeaf(uri: $uri, conceptsByUri: $conceptsByUri) === false
				) {
					$errors[$property] = sprintf(
						'The value "%s" on "%s" has narrower values under it, and this field accepts only the most specific value.',
						$this->hierarchy->labelOf(concept: $concept, language: 'nl'),
						$property
					);
					continue;
				}

				$group = $this->lifecycle->exclusiveGroupOf(concept: $concept);
				if ($group === null) {
					continue;
				}

				$key = $declaration->scheme . "\0" . $group;
				$already = ($heldBySchemeGroup[$key] ?? null);
				if ($already === null) {
					$heldBySchemeGroup[$key] = [
						'property' => $property,
						'label' => $this->hierarchy->labelOf(concept: $concept, language: 'nl'),
					];
					continue;
				}

				$errors[$property] = sprintf(
					'The values "%s" and "%s" both belong to the group "%s", of which only one may be held.',
					$already['label'],
					$this->hierarchy->labelOf(concept: $concept, language: 'nl'),
					$group
				);
			}//end foreach
		}//end foreach

		return $errors;
	}//end collectViolations()

	/**
	 * The rolled-up scores a schema's coded properties declare, keyed by the
	 * property each score is written to.
	 *
	 * @param array<string,mixed> $object The submitted object data.
	 * @param Schema $schema The schema being written against.
	 *
	 * @return array<string,float> The scores, keyed by target property.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function rolledUpScores(array $object, Schema $schema): array {
		$declarations = $this->declarationFactory->fromProperties(properties: ($schema->getProperties() ?? []));
		$scores = [];

		foreach ($declarations as $property => $declaration) {
			if ($declaration->scoreProperty === null || array_key_exists($property, $object) === false) {
				continue;
			}

			$held = [];
			foreach ($this->valuesOf(value: $object[$property]) as $value) {
				$concept = $this->concepts->resolve(
					value: $value,
					schemeUri: $declaration->scheme,
					store: $declaration->store
				);
				if ($concept !== null) {
					$held[] = $concept;
				}
			}

			$scores[$declaration->scoreProperty] = $this->lifecycle->rollUpWeights(concepts: $held);
		}//end foreach

		return $scores;
	}//end rolledUpScores()

	/**
	 * Normalise a coded property's value to a list of stored strings.
	 *
	 * @param mixed $value The submitted value, single or multi.
	 *
	 * @return array<int,string> The stored values.
	 */
	private function valuesOf(mixed $value): array {
		if (is_string($value) === true) {
			if ($value === '') {
				return [];
			}

			return [$value];
		}

		if (is_array($value) === false) {
			return [];
		}

		$values = [];
		foreach ($value as $entry) {
			if (is_string($entry) === true && $entry !== '') {
				$values[] = $entry;
			}
		}

		return $values;
	}//end valuesOf()
}//end class
