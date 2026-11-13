<?php

/**
 * OpenRegister ArchivalAnnotationValidator
 *
 * Schema-save validation for the `x-openregister-archival` annotation.
 * Returns a list of structured errors; empty = valid.
 *
 * Per openregister#1614, the annotation declares a default retention
 * (ISO-8601 duration) and an ordered list of condition-based rules whose
 * `retention` (also ISO-8601 duration) overrides the default when the
 * row matches the rule's `condition`. Validation is shape-only at save
 * time; the runtime condition grammar lives in `RetentionConditionEvaluator`.
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
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-2-1
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#scenario-non-iso-8601-retention-default-is-rejected
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateInterval;
use Exception;

/**
 * Pure validation logic for the `x-openregister-archival` annotation.
 *
 * Hooked into `SchemaMapper::insert()` / `update()` alongside the existing
 * lifecycle / aggregations validators. Errors translate to HTTP 422
 * schema-save failures via a single aggregated `\Exception` whose message
 * starts `x-openregister-archival: `.
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-2
 */
final class ArchivalAnnotationValidator implements ArchivalAnnotationValidatorInterface {

	/**
	 * Allowed keys directly under `x-openregister-archival`.
	 *
	 * `retention` is the disposal decision. `aggregationLevel`,
	 * `useRestriction` and `temporalCoverage` are the archival facts MDTO asks
	 * for that nothing in openregister used to write, so an export could only
	 * ever omit them; see the archival-conformance A3 finding. `category`,
	 * `categoryProperty` and `action` are read by ArchivalDeclarationReader and
	 * ClassificationOverride, so calling them unknown was a false warning on
	 * every import of a schema that declares them. `parties` names the
	 * properties that keep a record's parties, for MDTO `betrokkene`
	 * (archival-conformance D1).
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_ANNOTATION_KEYS = [
		'retention',
		'aggregationLevel',
		'useRestriction',
		'temporalCoverage',
		'category',
		'categoryProperty',
		'action',
		'parties',
	];

	/**
	 * Allowed top-level keys under `retention`.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_RETENTION_KEYS = ['default', 'rules'];



	/**
	 * Allowed keys under each rule.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_RULE_KEYS = ['condition', 'retention', 'reason'];

	/**
	 * Messages of the annotation-level findings, by code.
	 *
	 * @var array<string, string>
	 */
	private const ANNOTATION_MESSAGES = [
		'archival-not-object' => 'x-openregister-archival must be an object.',
		'archival-retention-missing' => 'x-openregister-archival.retention is required and must be an object.',
		'archival-retention-default-missing' => 'x-openregister-archival.retention.default is required (ISO-8601 duration, e.g. "P30D").',
		'archival-rules-not-array' => 'x-openregister-archival.retention.rules must be an array of rule objects.',
	];

	/**
	 * Message formats of the rule findings that name only the rule index, by code.
	 *
	 * @var array<string, string>
	 */
	private const RULE_MESSAGES = [
		'archival-rule-not-object' => 'x-openregister-archival.retention.rules[%d] must be an object.',
		'archival-rule-condition-not-string' => 'x-openregister-archival.retention.rules[%d].condition must be a non-empty string.',
		'archival-rule-condition-empty' => 'x-openregister-archival.retention.rules[%d].condition must not be empty.',
		'archival-rule-retention-missing' => 'x-openregister-archival.retention.rules[%d].retention is required (ISO-8601 duration).',
		'archival-rule-reason-not-string' => 'x-openregister-archival.retention.rules[%d].reason must be a string when present.',
	];

	/**
	 * Severity marking a finding that SHALL NOT refuse the schema.
	 *
	 * A finding without a `severity` key is an error, so every existing check
	 * keeps refusing exactly what it refused before this constant existed.
	 *
	 * @var string
	 */
	public const SEVERITY_WARNING = 'warning';

	/**
	 * Split validator findings into the ones that refuse a schema and the ones
	 * that only deserve a log line.
	 *
	 * Callers save the schema when `errors` is empty, whatever `warnings`
	 * holds. Kept here rather than at each call site so "which findings are
	 * fatal" is answered in the file that decides it.
	 *
	 * @param array<int, array{code: string, message: string, severity?: string}> $findings Findings from validate().
	 *
	 * @return array{
	 *     errors: array<int, array{code: string, message: string, severity?: string}>,
	 *     warnings: array<int, array{code: string, message: string, severity?: string}>
	 * }
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public static function partition(array $findings): array {
		$errors = [];
		$warnings = [];
		foreach ($findings as $finding) {
			if (($finding['severity'] ?? '') === self::SEVERITY_WARNING) {
				$warnings[] = $finding;
				continue;
			}

			$errors[] = $finding;
		}

		return [
			'errors' => $errors,
			'warnings' => $warnings,
		];
	}//end partition()

	/**
	 * Validate the `x-openregister-archival` annotation block on a schema definition.
	 *
	 * @param array<string, mixed> $schema Full schema definition. The validator only
	 *                                     reads `x-openregister-archival`; it does
	 *                                     not consult `properties` because the
	 *                                     condition grammar is field-agnostic at
	 *                                     save time (fields are resolved by the
	 *                                     runtime evaluator against actual rows).
	 *
	 * @return array<int, array{code: string, message: string, severity?: string}> List of findings
	 *         (empty = valid). A finding carrying `severity: warning` does NOT refuse the schema;
	 *         see {@see partition()} and {@see SEVERITY_WARNING}.
	 *
	 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-2
	 */
	public function validate(array $schema): array {
		if (isset($schema['x-openregister-archival']) === false) {
			return [];
		}

		$annotation = $schema['x-openregister-archival'];
		$refusal    = $this->shapeRefusal(annotation: $annotation);
		if ($refusal !== []) {
			return $refusal;
		}

		return array_merge(
			$this->retentionFindings(retention: $annotation['retention']),
			$this->unknownTopLevelKeys(annotation: $annotation),
			$this->factFindings(annotation: $annotation)
		);
	}//end validate()

	/**
	 * Refuse an annotation that is not an object, or has no `retention` object.
	 *
	 * Either refusal is the only finding: nothing else is checked.
	 *
	 * @param mixed $annotation The `x-openregister-archival` value.
	 *
	 * @return array<int, array{code: string, message: string}> The refusal, or empty.
	 */
	private function shapeRefusal(mixed $annotation): array {
		if (is_array($annotation) === false) {
			return [$this->annotationFinding(code: 'archival-not-object')];
		}

		// `retention` block is required.
		if (is_array($annotation['retention'] ?? null) === false) {
			return [$this->annotationFinding(code: 'archival-retention-missing')];
		}

		return [];
	}//end shapeRefusal()

	/**
	 * Validate the `retention` block: its keys, its default and its rules, in that order.
	 *
	 * @param array<string, mixed> $retention The `retention` block.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function retentionFindings(array $retention): array {
		return array_merge(
			$this->unknownRetentionKeys(retention: $retention),
			$this->defaultFindings(retention: $retention),
			$this->ruleListFindings(retention: $retention)
		);
	}//end retentionFindings()

	/**
	 * Reject unknown keys under `retention` so typos fail loudly.
	 *
	 * @param array<string, mixed> $retention The `retention` block.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function unknownRetentionKeys(array $retention): array {
		$errors = [];
		foreach ($this->unknownKeys(block: $retention, allowed: self::ALLOWED_RETENTION_KEYS) as $key) {
			$errors[] = [
				'code' => 'archival-retention-unknown-key',
				'message' => sprintf(
					'x-openregister-archival.retention contains unknown key "%s". Allowed: %s.',
					$key,
					implode(', ', self::ALLOWED_RETENTION_KEYS)
				),
			];
		}

		return $errors;
	}//end unknownRetentionKeys()

	/**
	 * `default` is required and must be a parseable ISO-8601 duration.
	 *
	 * @param array<string, mixed> $retention The `retention` block.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function defaultFindings(array $retention): array {
		if (isset($retention['default']) === false) {
			return [$this->annotationFinding(code: 'archival-retention-default-missing')];
		}

		if ($this->isIsoDuration(value: $retention['default']) === false) {
			return [$this->malformedDefault(value: $retention['default'])];
		}

		return [];
	}//end defaultFindings()

	/**
	 * The finding for a `default` that is not an ISO-8601 duration.
	 *
	 * @param mixed $value The declared default.
	 *
	 * @return array{code: string, message: string}
	 */
	private function malformedDefault(mixed $value): array {
		return [
			'code' => 'archival-retention-default-malformed',
			'message' => sprintf(
				'x-openregister-archival.retention.default "%s" is not a valid ISO-8601 duration (e.g. "P30D", "PT1H", "P1Y6M").',
				(string)$value
			),
		];
	}//end malformedDefault()

	/**
	 * `rules` is optional but, when present, must be a list of rule objects; validate each.
	 *
	 * @param array<string, mixed> $retention The `retention` block.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function ruleListFindings(array $retention): array {
		if (isset($retention['rules']) === false) {
			return [];
		}

		if (is_array($retention['rules']) === false) {
			return [$this->annotationFinding(code: 'archival-rules-not-array')];
		}

		$errors = [];
		foreach ($retention['rules'] as $index => $rule) {
			$errors = array_merge($errors, $this->validateRule(index: (int)$index, rule: $rule));
		}

		return $errors;
	}//end ruleListFindings()

	/**
	 * Validate the MDTO archival facts the annotation declares.
	 *
	 * @param array<string, mixed> $annotation The `x-openregister-archival` block.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function factFindings(array $annotation): array {
		$facts = new ArchivalFactsValidator();

		return array_merge(
			$facts->validateAggregationLevel(annotation: $annotation),
			$facts->validateUseRestriction(annotation: $annotation),
			$facts->validateTemporalCoverage(annotation: $annotation)
		);
	}//end factFindings()

	/**
	 * An annotation-level finding whose message names nothing but the path.
	 *
	 * @param string $code A key of ANNOTATION_MESSAGES.
	 *
	 * @return array{code: string, message: string}
	 */
	private function annotationFinding(string $code): array {
		return [
			'code' => $code,
			'message' => self::ANNOTATION_MESSAGES[$code],
		];
	}//end annotationFinding()

	/**
	 * The keys of a block that are not on its allow-list, as strings, in declaration order.
	 *
	 * @param array<string|int, mixed> $block   The block whose keys are checked.
	 * @param array<int, string>       $allowed The allowed keys.
	 *
	 * @return array<int, string>
	 */
	private function unknownKeys(array $block, array $allowed): array {
		$unknown = [];
		foreach (array_keys($block) as $key) {
			if (in_array((string)$key, $allowed, true) === false) {
				$unknown[] = (string)$key;
			}
		}

		return $unknown;
	}//end unknownKeys()





	/**
	 * Report keys at the top level of the annotation that are not on the
	 * allow-list, as WARNINGS rather than errors.
	 *
	 * That severity is the whole point of this method. An unknown key here is
	 * an EXTRA key beside a `retention` block that is itself valid: it declares
	 * nothing, so ignoring it loses nothing. Refusing it refuses the SCHEMA, and
	 * at import time `ImportHandler` then drops that schema and every object
	 * that needed it, which is a total loss of the register for a stray key.
	 *
	 * Measured on 2026-09-12: shipping this check as an error took filinq (9 of
	 * 22 schemas, on `category` / `action` / `responsibleParty`) and pipelinq
	 * (the `ticket` supertype, on a `_note`) red within the hour, on payloads
	 * those apps had carried for weeks, because every app clones
	 * openregister@development at CI run time. The first symptom was an HTTP 412
	 * out of each app's own demo-data seeding, four layers away from this check.
	 *
	 * It matches the rule the enclosing level already follows: R07 DROPS an
	 * unknown `x-openregister-*` key and warns
	 * ({@see \OCA\OpenRegister\Db\SchemaMapper::logDroppedAnnotationKeys}). A key
	 * one level further in should not be stricter than the key containing it.
	 *
	 * Every check that validates a fact the schema actually DECLARED stays an
	 * error: a malformed ISO-8601 retention, an unknown key INSIDE a block, a
	 * term outside MDTO's begrippenlijst, a literal date where a property name
	 * belongs.
	 *
	 * @param array<string, mixed> $annotation The `x-openregister-archival` block.
	 *
	 * @return array<int, array{code: string, message: string, severity: string}>
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	private function unknownTopLevelKeys(array $annotation): array {
		$warnings = [];
		foreach ($this->unknownKeys(block: $annotation, allowed: self::ALLOWED_ANNOTATION_KEYS) as $key) {
			$warnings[] = [
				'code' => 'archival-unknown-key',
				'severity' => self::SEVERITY_WARNING,
				'message' => sprintf(
					'x-openregister-archival contains unknown key "%s". Allowed: %s.',
					$key,
					implode(', ', self::ALLOWED_ANNOTATION_KEYS)
				),
			];
		}

		return $warnings;
	}//end unknownTopLevelKeys()

	/**
	 * Validate a single rule entry: its keys, condition, retention and reason, in that order.
	 *
	 * @param int   $index Zero-based rule index for error messages.
	 * @param mixed $rule  Raw rule value.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateRule(int $index, mixed $rule): array {
		if (is_array($rule) === false) {
			return [$this->ruleFinding(index: $index, code: 'archival-rule-not-object')];
		}

		return array_merge(
			$this->unknownRuleKeys(index: $index, rule: $rule),
			$this->ruleConditionFindings(index: $index, rule: $rule),
			$this->ruleRetentionFindings(index: $index, rule: $rule),
			$this->ruleReasonFindings(index: $index, rule: $rule)
		);
	}//end validateRule()

	/**
	 * Reject unknown keys under a rule.
	 *
	 * @param int                  $index Zero-based rule index.
	 * @param array<string, mixed> $rule  The rule.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function unknownRuleKeys(int $index, array $rule): array {
		$errors = [];
		foreach ($this->unknownKeys(block: $rule, allowed: self::ALLOWED_RULE_KEYS) as $key) {
			$errors[] = [
				'code' => 'archival-rule-unknown-key',
				'message' => sprintf(
					'x-openregister-archival.retention.rules[%d] contains unknown key "%s". Allowed: %s.',
					$index,
					$key,
					implode(', ', self::ALLOWED_RULE_KEYS)
				),
			];
		}

		return $errors;
	}//end unknownRuleKeys()

	/**
	 * A rule's `condition` MUST be a non-empty string.
	 *
	 * @param int                  $index Zero-based rule index.
	 * @param array<string, mixed> $rule  The rule.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function ruleConditionFindings(int $index, array $rule): array {
		$condition = ($rule['condition'] ?? null);
		if (is_string($condition) === false) {
			return [$this->ruleFinding(index: $index, code: 'archival-rule-condition-not-string')];
		}

		if (trim($condition) === '') {
			return [$this->ruleFinding(index: $index, code: 'archival-rule-condition-empty')];
		}

		return [];
	}//end ruleConditionFindings()

	/**
	 * A rule's `retention` MUST be a parseable ISO-8601 duration.
	 *
	 * @param int                  $index Zero-based rule index.
	 * @param array<string, mixed> $rule  The rule.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function ruleRetentionFindings(int $index, array $rule): array {
		if (isset($rule['retention']) === false) {
			return [$this->ruleFinding(index: $index, code: 'archival-rule-retention-missing')];
		}

		if ($this->isIsoDuration(value: $rule['retention']) === false) {
			return [$this->malformedRuleRetention(index: $index, value: $rule['retention'])];
		}

		return [];
	}//end ruleRetentionFindings()

	/**
	 * The finding for a rule `retention` that is not an ISO-8601 duration.
	 *
	 * @param int   $index Zero-based rule index.
	 * @param mixed $value The declared retention.
	 *
	 * @return array{code: string, message: string}
	 */
	private function malformedRuleRetention(int $index, mixed $value): array {
		return [
			'code' => 'archival-rule-retention-malformed',
			'message' => sprintf(
				'x-openregister-archival.retention.rules[%d].retention "%s" is not a valid ISO-8601 duration.',
				$index,
				(string)$value
			),
		];
	}//end malformedRuleRetention()

	/**
	 * A rule's `reason` is optional; if present it MUST be a string.
	 *
	 * @param int                  $index Zero-based rule index.
	 * @param array<string, mixed> $rule  The rule.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function ruleReasonFindings(int $index, array $rule): array {
		if (isset($rule['reason']) === true && is_string($rule['reason']) === false) {
			return [$this->ruleFinding(index: $index, code: 'archival-rule-reason-not-string')];
		}

		return [];
	}//end ruleReasonFindings()

	/**
	 * A rule finding whose message names nothing but the rule index.
	 *
	 * @param int    $index Zero-based rule index.
	 * @param string $code  A key of RULE_MESSAGES.
	 *
	 * @return array{code: string, message: string}
	 */
	private function ruleFinding(int $index, string $code): array {
		return [
			'code' => $code,
			'message' => sprintf(self::RULE_MESSAGES[$code], $index),
		];
	}//end ruleFinding()

	/**
	 * Return true when $value is a string parseable as ISO-8601 duration.
	 *
	 * Delegates to PHP's `\DateInterval` constructor which accepts the
	 * full ISO-8601 grammar (P[n]Y[n]M[n]DT[n]H[n]M[n]S). Rejects empty
	 * strings and non-string types up-front so the constructor only sees
	 * shape-positive input.
	 *
	 * @param mixed $value Candidate value.
	 *
	 * @return bool True when the value parses as a duration.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#scenario-non-iso-8601-retention-default-is-rejected
	 */
	private function isIsoDuration(mixed $value): bool {
		if (is_string($value) === false || $value === '') {
			return false;
		}

		try {
			new DateInterval($value);
			return true;
		} catch (Exception $unused) {
			return false;
		}

	}//end isIsoDuration()
}//end class
