<?php

/**
 * TermRuleMatcher: match a detection, or a whole text, against the term lists.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\RedactionPolicy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\RedactionPolicy;

/**
 * Matches against the always and never-redact lists of an index.
 *
 * Match types: `exact` (the text itself), `normalized` (case and accent
 * folded), `bsn` and `kvk` (the number resolved for the detection, or `*` for
 * any). The always list wins over the never list, and within a list the
 * lowest uuid is the recorded match; every matching rule of both lists is
 * returned for the audit row. There is no way to make `never` win.
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
 */
class TermRuleMatcher {

	/**
	 * Constructor.
	 *
	 * @param TextFolder $folder The case and accent folding.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
	 */
	public function __construct(
		private readonly TextFolder $folder,
	) {
	}//end __construct()

	/**
	 * Match one detection against both lists.
	 *
	 * @param PolicyIndex           $index       The rules of this run.
	 * @param string                $text        The detected text.
	 * @param string                $entityType  The detected entity type.
	 * @param array<string, string> $identifiers Numbers resolved for the detection (`bsn`, `kvk`).
	 * @param string|null           $requestRef  The call's policyContext.requestRef.
	 *
	 * @return PolicyMatch|null The decision, or null when no rule matches.
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
	 */
	public function match(PolicyIndex $index, string $text, string $entityType, array $identifiers=[], ?string $requestRef=null): ?PolicyMatch {
		$folded = $this->folder->fold(value: $text);

		$matched = [];
		foreach ([TermRule::LIST_ALWAYS, TermRule::LIST_NEVER] as $list) {
			foreach ($index->rules(list: $list, requestRef: $requestRef) as $rule) {
				if ($rule->covers(entityType: $entityType) === true
					&& $this->ruleMatches(rule: $rule, detected: ['text' => $text, 'folded' => $folded], identifiers: $identifiers) === true
				) {
					$matched[] = $rule;
				}
			}
		}

		if ($matched === []) {
			return null;
		}

		// Always rules are collected first, each list in uuid order: the first is the winner.
		return new PolicyMatch(rule: $matched[0], matched: $matched);
	}//end match()

	/**
	 * Every occurrence in a text of an always term of type exact or normalized,
	 * so a term the model missed is still detected.
	 *
	 * @param PolicyIndex $index      The rules of this run.
	 * @param string      $text       The document text.
	 * @param string|null $requestRef The call's policyContext.requestRef.
	 *
	 * @return array<int, array{start: int, length: int, text: string, entityType: string|null, rule: TermRule}> Character offsets.
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
	 */
	public function findAlwaysTerms(PolicyIndex $index, string $text, ?string $requestRef=null): array {
		$found = [];
		foreach ($index->rules(list: TermRule::LIST_ALWAYS, requestRef: $requestRef) as $rule) {
			foreach ($rule->matchRules as $matchRule) {
				foreach ($this->occurrences(text: $text, matchRule: $matchRule) as $occurrence) {
					$key = $occurrence['start'].':'.$occurrence['length'];
					// The lowest uuid is met first and keeps the span.
					$found[$key] = ($found[$key] ?? $occurrence + ['entityType' => $rule->entityType, 'rule' => $rule]);
				}
			}
		}

		ksort($found, SORT_NATURAL);
		return array_values($found);
	}//end findAlwaysTerms()

	/**
	 * The occurrences of one text match rule (bsn and kvk are not searched in text).
	 *
	 * @param string                              $text      The document text.
	 * @param array{type: string, value: string} $matchRule The match rule.
	 *
	 * @return array<int, array{start: int, length: int, text: string}>
	 */
	private function occurrences(string $text, array $matchRule): array {
		if ($matchRule['type'] === 'normalized') {
			return $this->folder->occurrences(text: $text, term: $matchRule['value']);
		}

		if ($matchRule['type'] !== 'exact') {
			return [];
		}

		return array_values(
			array_filter(
				$this->folder->occurrences(text: $text, term: $matchRule['value']),
				static fn (array $occurrence): bool => $occurrence['text'] === $matchRule['value']
			)
		);
	}//end occurrences()

	/**
	 * Whether one of a rule's match rules matches the detection.
	 *
	 * @param TermRule                             $rule        The rule.
	 * @param array{text: string, folded: string} $detected    The detected text, as is and folded.
	 * @param array<string, string>                $identifiers Resolved numbers.
	 *
	 * @return bool
	 */
	private function ruleMatches(TermRule $rule, array $detected, array $identifiers): bool {
		foreach ($rule->matchRules as $matchRule) {
			$value   = $matchRule['value'];
			$matches = match ($matchRule['type']) {
				'exact' => $detected['text'] === $value,
				'normalized' => $detected['folded'] === $this->folder->fold(value: $value),
				default => $this->identifierMatches(resolved: (string) ($identifiers[$matchRule['type']] ?? ''), value: $value),
			};
			if ($matches === true) {
				return true;
			}
		}

		return false;
	}//end ruleMatches()

	/**
	 * Whether a resolved number matches a bsn or kvk rule value (`*` matches any resolved number).
	 *
	 * @param string $resolved The number resolved for the detection, or empty.
	 * @param string $value    The rule's value.
	 *
	 * @return bool
	 */
	private function identifierMatches(string $resolved, string $value): bool {
		if ($resolved === '') {
			return false;
		}

		return $value === '*' || $resolved === $value;
	}//end identifierMatches()
}//end class
