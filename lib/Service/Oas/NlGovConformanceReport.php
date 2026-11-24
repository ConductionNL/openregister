<?php

/**
 * OpenRegister NLGov conformance report
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

/**
 * Reads a document's validation report, and the last response probe, into one result per rule of the ruleset.
 *
 * A rule reads `pass` only when a check ran and found nothing: a functional or
 * module rule is `manual`, and a response rule is `not-probed` until a probe
 * has looked at a live response.
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */
final class NlGovConformanceReport {

	/**
	 * Every result a rule can have, in the order the counts list them.
	 *
	 * @var list<string>
	 */
	public const RESULTS = ['pass', 'fail', 'deviation', 'manual', 'not-probed'];

	/**
	 * Legacy report codes that speak for a rule.
	 *
	 * @var array<string, string>
	 */
	private const LEGACY_CODES = [
		OasValidationReport::CODE_INVALID_HTTP_METHOD => '/core/http-methods',
		OasValidationReport::CODE_INVALID_STATUS_CODE => '/core/http-response-code',
	];

	/**
	 * Build the report.
	 *
	 * @param OasValidationReport       $report      The validation report of the generated document.
	 * @param array<string, mixed>|null $probe       The last response probe ({probedAt, rules}), or null when none ran.
	 * @param string                    $generatedAt When the report was made, ISO 8601.
	 *
	 * @return array{ruleset: string, generatedAt: string, probedAt: string|null, rules: list<array<string, mixed>>, counts: array<string, int>}
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public function build(OasValidationReport $report, ?array $probe, string $generatedAt): array {
		$found  = $this->findingsByRule(report: $report);
		$probed = [];
		if (is_array($probe['rules'] ?? null) === true) {
			$probed = $probe['rules'];
		}

		$rules  = [];
		$counts = array_fill_keys(self::RESULTS, 0);
		foreach (NlGovRuleCatalogue::rules() as $rule) {
			$entry = $this->ruleResult(rule: $rule, findings: ($found[$rule['id']] ?? []), probed: ($probed[$rule['id']] ?? null));
			$counts[$entry['result']]++;
			$rules[] = $entry;
		}

		$probedAt = null;
		if (is_string($probe['probedAt'] ?? null) === true) {
			$probedAt = $probe['probedAt'];
		}

		return [
			'ruleset' => NlGovRuleCatalogue::RULESET_VERSION,
			'generatedAt' => $generatedAt,
			'probedAt' => $probedAt,
			'rules' => $rules,
			'counts' => $counts,
		];
	}//end build()

	/**
	 * One rule's result and findings.
	 *
	 * @param array{id: string, title: string, kind: string, deviation: string|null} $rule     The catalogue entry.
	 * @param list<array{path: string, message: string, deviation: bool}>             $findings What the document checks found for it.
	 * @param mixed                                                                    $probed   What the probe found for it, or null.
	 *
	 * @return array<string, mixed>
	 */
	private function ruleResult(array $rule, array $findings, mixed $probed): array {
		$result = $this->documentResult(rule: $rule, findings: $findings);
		if (is_array($probed) === true) {
			$result = $this->withProbe(documentResult: $result, probed: $probed);
			foreach (($probed['findings'] ?? []) as $finding) {
				$findings[] = ['path' => (string) ($finding['path'] ?? ''), 'message' => (string) ($finding['message'] ?? ''), 'deviation' => false];
			}
		}

		$entry = [
			'id' => $rule['id'],
			'title' => $rule['title'],
			'kind' => $rule['kind'],
			'result' => $result,
			'findings' => array_map(
				static fn (array $finding): array => ['path' => $finding['path'], 'message' => $finding['message']],
				$findings
			),
		];
		if ($rule['deviation'] !== null) {
			$entry['deviation'] = $rule['deviation'];
		}

		return $entry;
	}//end ruleResult()

	/**
	 * The result the document alone gives a rule.
	 *
	 * @param array{id: string, kind: string}               $rule     The catalogue entry.
	 * @param list<array{deviation: bool}>                  $findings What the document checks found.
	 *
	 * @return string
	 */
	private function documentResult(array $rule, array $findings): string {
		if ($rule['kind'] === NlGovRuleCatalogue::KIND_RESPONSE) {
			return 'not-probed';
		}

		if ($rule['kind'] !== NlGovRuleCatalogue::KIND_DOCUMENT) {
			return 'manual';
		}

		if ($findings === []) {
			return 'pass';
		}

		foreach ($findings as $finding) {
			if ($finding['deviation'] === false) {
				return 'fail';
			}
		}

		return 'deviation';
	}//end documentResult()

	/**
	 * Fold a probe's verdict into a rule's result: a failing response fails the rule, a passing one passes an unprobed rule.
	 *
	 * @param string               $documentResult The result without the probe.
	 * @param array<string, mixed> $probed         The probe's entry for the rule.
	 *
	 * @return string
	 */
	private function withProbe(string $documentResult, array $probed): string {
		$verdict = ($probed['result'] ?? null);
		if ($verdict === 'fail') {
			return 'fail';
		}

		if ($verdict === 'pass' && $documentResult === 'not-probed') {
			return 'pass';
		}

		return $documentResult;
	}//end withProbe()

	/**
	 * The report's issues grouped by the rule they speak for.
	 *
	 * @param OasValidationReport $report The validation report.
	 *
	 * @return array<string, list<array{path: string, message: string, deviation: bool}>>
	 */
	private function findingsByRule(OasValidationReport $report): array {
		$found = [];
		foreach ($report->getIssues() as $issue) {
			$rule = ($issue['rule'] ?? self::LEGACY_CODES[$issue['code']] ?? null);
			if (is_string($rule) === false) {
				continue;
			}

			$message       = (string) $issue['message'];
			$found[$rule][] = [
				'path' => (string) $issue['path'],
				'message' => $message,
				'deviation' => str_starts_with($message, NlGovFindings::DEVIATION_PREFIX),
			];
		}

		return $found;
	}//end findingsByRule()
}//end class
