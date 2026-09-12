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

		$shown = gettype($value);
		if (is_scalar($value) === true) {
			$shown = (string)$value;
		}

		return [
			[
				'code' => 'archival-aggregation-level-unknown',
				'message' => sprintf(
					'x-openregister-archival.aggregationLevel "%s" is not a term of MDTO\'s %s. Allowed: %s.',
					$shown,
					MdtoTerms::AGGREGATION_LEVEL_LIST,
					implode(', ', MdtoTerms::AGGREGATION_LEVELS)
				),
			],
		];
	}//end validateAggregationLevel()

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
		if (isset($annotation['useRestriction']) === false) {
			return [];
		}

		$block = $annotation['useRestriction'];
		if (is_array($block) === false) {
			return [
				[
					'code' => 'archival-use-restriction-not-object',
					'message' => 'x-openregister-archival.useRestriction must be an object with a "type".',
				],
			];
		}

		$errors = $this->unknownKeys(
			block: $block,
			allowed: self::ALLOWED_USE_RESTRICTION_KEYS,
			path: 'x-openregister-archival.useRestriction',
			code: 'archival-use-restriction-unknown-key'
		);

		if (in_array(($block['type'] ?? null), MdtoTerms::USE_RESTRICTION_TYPES, true) === false) {
			$errors[] = [
				'code' => 'archival-use-restriction-type-unknown',
				'message' => sprintf(
					'x-openregister-archival.useRestriction.type is required and must be a term of MDTO\'s %s. Allowed: %s.',
					MdtoTerms::USE_RESTRICTION_LIST,
					implode(', ', MdtoTerms::USE_RESTRICTION_TYPES)
				),
			];
		}

		if (isset($block['description']) === true && is_string($block['description']) === false) {
			$errors[] = [
				'code' => 'archival-use-restriction-description-not-string',
				'message' => 'x-openregister-archival.useRestriction.description must be a string when present.',
			];
		}

		return $errors;
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
		if (isset($annotation['temporalCoverage']) === false) {
			return [];
		}

		$block = $annotation['temporalCoverage'];
		if (is_array($block) === false) {
			return [
				[
					'code' => 'archival-temporal-coverage-not-object',
					'message' => 'x-openregister-archival.temporalCoverage must be an object with a "type" and a "startProperty".',
				],
			];
		}

		$errors = $this->unknownKeys(
			block: $block,
			allowed: self::ALLOWED_TEMPORAL_COVERAGE_KEYS,
			path: 'x-openregister-archival.temporalCoverage',
			code: 'archival-temporal-coverage-unknown-key'
		);

		foreach (['type', 'startProperty'] as $required) {
			if (is_string(($block[$required] ?? null)) === false || trim((string)$block[$required]) === '') {
				$errors[] = [
					'code' => 'archival-temporal-coverage-' . strtolower($required) . '-missing',
					'message' => sprintf(
						'x-openregister-archival.temporalCoverage.%s is required and must be a non-empty string.',
						$required
					),
				];
			}
		}

		if (isset($block['endProperty']) === true
			&& (is_string($block['endProperty']) === false || trim($block['endProperty']) === '')
		) {
			$errors[] = [
				'code' => 'archival-temporal-coverage-endproperty-not-string',
				'message' => 'x-openregister-archival.temporalCoverage.endProperty must be a non-empty string when present.',
			];
		}

		return $errors;
	}//end validateTemporalCoverage()

	/**
	 * Report every key of a block that is not on its allow-list.
	 *
	 * @param array<string, mixed> $block The block to inspect.
	 * @param array<int, string> $allowed The allowed keys.
	 * @param string $path The block's path, for the message.
	 * @param string $code The error code to report.
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-a-schema-may-declare-the-archival-facts-mdto-asks-for
	 */
	private function unknownKeys(array $block, array $allowed, string $path, string $code): array {
		$errors = [];
		foreach (array_keys($block) as $key) {
			if (in_array((string)$key, $allowed, true) === false) {
				$errors[] = [
					'code' => $code,
					'message' => sprintf(
						'%s contains unknown key "%s". Allowed: %s.',
						$path,
						(string)$key,
						implode(', ', $allowed)
					),
				];
			}
		}

		return $errors;
	}//end unknownKeys()
}//end class
