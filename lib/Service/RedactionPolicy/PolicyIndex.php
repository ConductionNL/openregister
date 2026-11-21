<?php

/**
 * PolicyIndex: the term rules that can match, loaded once for a run.
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

use DateTimeImmutable;

/**
 * An in-memory index of term rules by list, each list ordered by uuid.
 *
 * Built from the termRule objects once; matching reads only memory. A base
 * rule that a local object switches off (`overrides: <base slug>` with
 * `active: false`) is left out, whatever version of the base is stored.
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
 */
final class PolicyIndex {

	/**
	 * The rules by list (always, never), each ordered by uuid.
	 *
	 * @var array<string, array<int, TermRule>>
	 */
	private readonly array $byList;

	/**
	 * Constructor.
	 *
	 * @param array<int, TermRule> $rules The rules.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
	 */
	public function __construct(array $rules) {
		$byList = [TermRule::LIST_ALWAYS => [], TermRule::LIST_NEVER => []];
		foreach ($rules as $rule) {
			$byList[$rule->list][] = $rule;
		}

		foreach (array_keys($byList) as $list) {
			usort($byList[$list], static fn (TermRule $a, TermRule $b): int => strcmp($a->uuid, $b->uuid));
		}

		$this->byList = $byList;
	}//end __construct()

	/**
	 * The index of the termRule objects that can match at this moment.
	 *
	 * @param array<int, array<string, mixed>> $objects The termRule objects.
	 * @param DateTimeImmutable                $now     The moment matched at.
	 *
	 * @return self
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) TermRule::fromObject() is the value object's named constructor.
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-003
	 */
	public static function fromObjects(array $objects, DateTimeImmutable $now): self {
		$switchedOff = self::switchedOff(objects: $objects);

		$rules = [];
		foreach ($objects as $object) {
			$rule = TermRule::fromObject(object: $object, now: $now);
			if ($rule === null || ($rule->layer === 'base' && in_array($rule->slug, $switchedOff, true) === true)) {
				continue;
			}

			$rules[] = $rule;
		}

		return new self(rules: $rules);
	}//end fromObjects()

	/**
	 * The rules of a list that apply to a call for this request, in uuid order.
	 *
	 * @param string      $list       always or never.
	 * @param string|null $requestRef The call's policyContext.requestRef.
	 *
	 * @return array<int, TermRule>
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-002
	 */
	public function rules(string $list, ?string $requestRef): array {
		return array_values(
			array_filter(
				($this->byList[$list] ?? []),
				static fn (TermRule $rule): bool => $rule->appliesTo(requestRef: $requestRef)
			)
		);
	}//end rules()

	/**
	 * The base slugs a local object switches off.
	 *
	 * @param array<int, array<string, mixed>> $objects The termRule objects.
	 *
	 * @return array<int, string>
	 */
	private static function switchedOff(array $objects): array {
		$slugs = [];
		foreach ($objects as $object) {
			$overrides = (string) ($object['overrides'] ?? '');
			if ($overrides !== '' && ($object['layer'] ?? 'local') === 'local' && ($object['active'] ?? true) === false) {
				$slugs[] = $overrides;
			}
		}

		return $slugs;
	}//end switchedOff()
}//end class
