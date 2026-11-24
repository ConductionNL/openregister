<?php

/**
 * The x-nl-api-design-rules marker a generated OpenAPI document carries.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

/**
 * Reads the NLGov findings of a validation report into the root marker of the
 * document: the ruleset version and the ids of the document and functional
 * rules under `pass`, `fail`, `deviation` and `manual`.
 *
 * A document rule with a finding that is not a declared deviation is `fail`;
 * one whose every finding is a declared deviation is `deviation`; one with no
 * finding is `pass`. A functional rule is `manual`. Response and module rules
 * are left out: a response rule's answer comes from a probe, which must not
 * change the document or its ETag.
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */
final class NlGovMarker {

	/**
	 * The root key of the marker.
	 *
	 * @var string
	 */
	public const KEY = 'x-nl-api-design-rules';

	/**
	 * The report codes from before the catalogue that still name one rule.
	 *
	 * @var array<string, string>
	 */
	private const LEGACY_CODES = [OasValidationReport::CODE_INVALID_HTTP_METHOD => '/core/http-methods'];

	/**
	 * The marker for the findings of one report.
	 *
	 * @param OasValidationReport $report The validation report of the document.
	 *
	 * @return array{version: string, pass: list<string>, fail: list<string>, deviation: list<string>, manual: list<string>}
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public function forReport(OasValidationReport $report): array {
		$found = $this->findingsByRule(report: $report);
		$marker = ['version' => NlGovRuleCatalogue::RULESET_VERSION, 'pass' => [], 'fail' => [], 'deviation' => [], 'manual' => []];
		foreach ((new NlGovRuleCatalogue())->all() as $rule) {
			if ($rule['kind'] === NlGovRuleCatalogue::KIND_FUNCTIONAL) {
				$marker['manual'][] = $rule['id'];
				continue;
			}

			if ($rule['kind'] === NlGovRuleCatalogue::KIND_DOCUMENT) {
				$marker[$this->resultOf(findings: ($found[$rule['id']] ?? []))][] = $rule['id'];
			}
		}

		return $marker;
	}//end forReport()

	/**
	 * Whether each finding of the report is a declared deviation, by rule.
	 *
	 * @param OasValidationReport $report The validation report.
	 *
	 * @return array<string, list<bool>> Per rule id, one entry per finding: true when it is a declared deviation.
	 */
	private function findingsByRule(OasValidationReport $report): array {
		$found = [];
		foreach ($report->getIssues() as $issue) {
			$rule = ($issue['rule'] ?? self::LEGACY_CODES[$issue['code']] ?? null);
			if (is_string($rule) === true) {
				$found[$rule][] = str_starts_with((string)$issue['message'], NlGovFindings::DEVIATION_PREFIX);
			}
		}

		return $found;
	}//end findingsByRule()

	/**
	 * The result of one document rule from its findings.
	 *
	 * @param list<bool> $findings One entry per finding: true when it is a declared deviation.
	 *
	 * @return string `pass`, `fail` or `deviation`.
	 */
	private function resultOf(array $findings): string {
		if ($findings === []) {
			return 'pass';
		}

		if (in_array(false, $findings, true) === true) {
			return 'fail';
		}

		return 'deviation';
	}//end resultOf()
}//end class
