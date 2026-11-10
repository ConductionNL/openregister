<?php

/**
 * OpenRegister ArchivalFactsValidator
 *
 * Schema-save validation for the archival facts an `x-openregister-archival`
 * annotation declares beside its retention block.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-a-schema-may-declare-the-archival-facts-mdto-asks-for
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * Validates `aggregationLevel`, `useRestriction` and `temporalCoverage`.
 *
 * Apart from {@see ArchivalAnnotationValidator} because it answers a different
 * question: that one validates the disposal DECISION, a default duration and
 * the rules that override it. These three are FACTS about the record, and they
 * are checked against MDTO's begrippenlijsten rather than against a duration
 * grammar.
 *
 * @psalm-suppress UnusedClass
 */
final class ArchivalFactsValidator {

	/**
	 * Allowed keys under `temporalCoverage`.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_TEMPORAL_COVERAGE_KEYS = ['type', 'startProperty', 'endProperty'];

	/**
	 * Allowed keys under `useRestriction`.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_USE_RESTRICTION_KEYS = ['type', 'description'];

	/**
	 * The object-valued facts: their error-code prefix, allowed keys and the
	 * message for a value that is not an object.
	 *
	 * @var array<string, array{code: string, allowed: array<int, string>, notObject: string}>
	 */
	private const BLOCKS = [
		'useRestriction' => [
			'code' => 'archival-use-restriction',
			'allowed' => self::ALLOWED_USE_RESTRICTION_KEYS,
			'notObject' => 'x-openregister-archival.useRestriction must be an object with a "type".',
		],
		'temporalCoverage' => [
			'code' => 'archival-temporal-coverage',
			'allowed' => self::ALLOWED_TEMPORAL_COVERAGE_KEYS,
			'notObject' => 'x-openregister-archival.temporalCoverage must be an object with a "type" and a "startProperty".',
		],
	];

	/**
	 * Validate `aggregationLevel`, a term of MDTO's Aggregatieniveaus list.
	 *
	 * @param array<string, mixed> $annotation The annotation block.
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-a-schema-may-declare-the-archival-facts-mdto-asks-for
	 */
	public function validateAggregationLevel(array $annotation): array {
		if (isset($annotation['aggregationLevel']) === false) {
			return [];
		}

		$value = $annotation['aggregationLevel'];
		if (in_array($value, MdtoTerms::AGGREGATION_LEVELS, true) === true) {
			return [];
		}

		return [$this->unknownAggregationLevel(value: $value)];
	}//end validateAggregationLevel()

	/**
	 * The error for an `aggregationLevel` that is not an MDTO term.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return array{code: string, message: string} The error.
	 */
	private function unknownAggregationLevel(mixed $value): array {
		$shown = gettype($value);
		if (is_scalar($value) === true) {
			$shown = (string)$value;
		}

		$message = sprintf(
			'x-openregister-archival.aggregationLevel "%s" is not a term of MDTO\'s %s. Allowed: %s.',
			$shown,
			MdtoTerms::AGGREGATION_LEVEL_LIST,
			implode(', ', MdtoTerms::AGGREGATION_LEVELS)
		);

		return $this->error(code: 'archival-aggregation-level-unknown', message: $message);
	}//end unknownAggregationLevel()

	/**
	 * Validate `useRestriction`, whose type is a term of BeperkingGebruikTypeLijst.
	 *
	 * @param array<string, mixed> $annotation The annotation block.
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-a-schema-may-declare-the-archival-facts-mdto-asks-for
	 */
	public function validateUseRestriction(array $annotation): array {
		$shape = $this->blockShape(annotation: $annotation, key: 'useRestriction');
		if ($shape['block'] === null) {
			return $shape['errors'];
		}

		return array_merge($shape['errors'], $this->useRestrictionValueErrors(block: $shape['block']));
	}//end validateUseRestriction()

	/**
	 * Validate `temporalCoverage`, which names date PROPERTIES on the record.
	 *
	 * The dates themselves are never declared here. MDTO defines dekkingInTijd
	 * as the period the record's CONTENT pertains to, which differs per record,
	 * so a literal date on a schema would be the same wrong answer for every
	 * row. The annotation names the properties to read instead, the way
	 * `sourceDateProperty` does for a disposal date.
	 *
	 * @param array<string, mixed> $annotation The annotation block.
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-a-schema-may-declare-the-archival-facts-mdto-asks-for
	 */
	public function validateTemporalCoverage(array $annotation): array {
		$shape = $this->blockShape(annotation: $annotation, key: 'temporalCoverage');
		if ($shape['block'] === null) {
			return $shape['errors'];
		}

		return array_merge($shape['errors'], $this->temporalCoverageValueErrors(block: $shape['block']));
	}//end validateTemporalCoverage()

	/**
	 * Check that an object-valued fact is an object with only allowed keys.
	 *
	 * @param array<string, mixed> $annotation The annotation block.
	 * @param string               $key        The fact: a key of BLOCKS.
	 *
	 * @return array{block: array<string, mixed>|null, errors: array<int, array{code: string, message: string}>}
	 *         The block when its values still need checking, and the errors so far.
	 */
	private function blockShape(array $annotation, string $key): array {
		if (isset($annotation[$key]) === false) {
			return ['block' => null, 'errors' => []];
		}

		$spec = self::BLOCKS[$key];
		$block = $annotation[$key];
		if (is_array($block) === false) {
			return ['block' => null, 'errors' => [$this->error(code: $spec['code'] . '-not-object', message: $spec['notObject'])]];
		}

		$errors = $this->unknownKeys(block: $block, key: $key);

		return ['block' => $block, 'errors' => $errors];
	}//end blockShape()

	/**
	 * Check the values of a `useRestriction` object.
	 *
	 * @param array<string, mixed> $block The `useRestriction` object.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function useRestrictionValueErrors(array $block): array {
		$errors = [];
		if (in_array(($block['type'] ?? null), MdtoTerms::USE_RESTRICTION_TYPES, true) === false) {
			$format = 'x-openregister-archival.useRestriction.type is required and must be a term of MDTO\'s %s. Allowed: %s.';
			$message = sprintf($format, MdtoTerms::USE_RESTRICTION_LIST, implode(', ', MdtoTerms::USE_RESTRICTION_TYPES));
			$errors[] = $this->error(code: 'archival-use-restriction-type-unknown', message: $message);
		}

		if (isset($block['description']) === true && is_string($block['description']) === false) {
			$message = 'x-openregister-archival.useRestriction.description must be a string when present.';
			$errors[] = $this->error(code: 'archival-use-restriction-description-not-string', message: $message);
		}

		return $errors;
	}//end useRestrictionValueErrors()

	/**
	 * Check the values of a `temporalCoverage` object.
	 *
	 * @param array<string, mixed> $block The `temporalCoverage` object.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function temporalCoverageValueErrors(array $block): array {
		$errors = [];
		foreach (['type', 'startProperty'] as $required) {
			if (ArchivalText::fromString(value: ($block[$required] ?? null)) === null) {
				$message = sprintf('x-openregister-archival.temporalCoverage.%s is required and must be a non-empty string.', $required);
				$code = 'archival-temporal-coverage-' . strtolower($required) . '-missing';
				$errors[] = $this->error(code: $code, message: $message);
			}
		}

		if (isset($block['endProperty']) === true && ArchivalText::fromString(value: $block['endProperty']) === null) {
			$message = 'x-openregister-archival.temporalCoverage.endProperty must be a non-empty string when present.';
			$errors[] = $this->error(code: 'archival-temporal-coverage-endproperty-not-string', message: $message);
		}

		return $errors;
	}//end temporalCoverageValueErrors()

	/**
	 * One validation error.
	 *
	 * @param string $code    The error code.
	 * @param string $message The message.
	 *
	 * @return array{code: string, message: string} The error.
	 */
	private function error(string $code, string $message): array {
		return ['code' => $code, 'message' => $message];
	}//end error()

	/**
	 * Report every key of a block that is not on its allow-list.
	 *
	 * @param array<string, mixed> $block The block to inspect.
	 * @param string               $key   The fact: a key of BLOCKS, naming the allow-list, the path and the code.
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-a-schema-may-declare-the-archival-facts-mdto-asks-for
	 */
	private function unknownKeys(array $block, string $key): array {
		$allowed = self::BLOCKS[$key]['allowed'];
		$path = 'x-openregister-archival.' . $key;
		$code = self::BLOCKS[$key]['code'] . '-unknown-key';
		$errors = [];
		foreach (array_keys($block) as $blockKey) {
			if (in_array((string)$blockKey, $allowed, true) === false) {
				$message = sprintf('%s contains unknown key "%s". Allowed: %s.', $path, (string)$blockKey, implode(', ', $allowed));
				$errors[] = $this->error(code: $code, message: $message);
			}
		}

		return $errors;
	}//end unknownKeys()
}//end class
