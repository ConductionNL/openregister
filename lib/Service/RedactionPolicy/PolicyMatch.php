<?php

/**
 * PolicyMatch: the decision a term rule match pre-sets on a detection.
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
 * The outcome of matching one detection: the winning rule, the decision it
 * pre-sets, its grounds, and every rule that matched (for the audit row).
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
 */
final class PolicyMatch {

	public const DECISION_REDACT = 'redact';
	public const DECISION_RELEASE = 'release';

	/**
	 * Constructor.
	 *
	 * @param TermRule             $rule    The recorded rule (`policyMatch`).
	 * @param array<int, TermRule> $matched Every matching rule of both lists.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
	 */
	public function __construct(
		public readonly TermRule $rule,
		public readonly array $matched,
	) {
	}//end __construct()

	/**
	 * The decision pre-set: redact for an always match, release for a never match.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
	 */
	public function decision(): string {
		if ($this->rule->list === TermRule::LIST_ALWAYS) {
			return self::DECISION_REDACT;
		}

		return self::DECISION_RELEASE;
	}//end decision()

	/**
	 * The uuids of every matching rule, for the audit row.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
	 */
	public function matchedUuids(): array {
		return array_map(static fn (TermRule $rule): string => $rule->uuid, $this->matched);
	}//end matchedUuids()
}//end class
