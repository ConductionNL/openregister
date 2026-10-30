<?php

/**
 * OpenRegister ReviewPolicy
 *
 * Who owns a decision on a schema's records: anyone who may write the subject
 * (`open`, today's behaviour) or the person who decided, a supervisor and an
 * administrator (`decider`), and whether one reviewer's decisions are hidden
 * from another.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Decision
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Decision;

/**
 * The review policy of one schema, or of the instance for files without one.
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
 */
final class ReviewPolicy {

	/**
	 * The schema configuration key.
	 */
	public const ANNOTATION = 'x-openregister-review';

	/**
	 * The instance default's key in the file settings, for files that belong to no schema.
	 */
	public const SETTINGS_KEY = 'anonymisationReview';

	/**
	 * Anyone who may write the subject may change its decision.
	 */
	public const OWNERSHIP_OPEN = 'open';

	/**
	 * Only the decider, a supervisor or an administrator may change it.
	 */
	public const OWNERSHIP_DECIDER = 'decider';

	/**
	 * The keys a declaration may hold.
	 */
	public const KEYS = ['ownership', 'supervisors', 'hideOthers'];

	/**
	 * Constructor.
	 *
	 * @param bool          $deciderOwned Whether ownership is `decider`.
	 * @param array<string> $supervisors  Group ids whose members may change any decision.
	 * @param bool          $hideOthers   Whether another reviewer's decisions are hidden.
	 */
	private function __construct(
		private readonly bool $deciderOwned,
		private readonly array $supervisors,
		private readonly bool $hideOthers,
	) {
	}//end __construct()

	/**
	 * Read a declaration. Nothing declared is today's open behaviour.
	 *
	 * An ownership other than `open` or `decider` fails closed to `decider`:
	 * the validator refuses it at save, and a value that got past it must not
	 * quietly open a four-eyes review.
	 *
	 * @param mixed $declaration The `x-openregister-review` value, or null.
	 *
	 * @return self The policy.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
	 */
	public static function fromDeclaration(mixed $declaration): self {
		if (is_array($declaration) === false) {
			return new self(deciderOwned: false, supervisors: [], hideOthers: false);
		}

		$ownership = ($declaration['ownership'] ?? self::OWNERSHIP_OPEN);

		$supervisors = [];
		foreach ((array)($declaration['supervisors'] ?? []) as $group) {
			if (is_string($group) === true && trim($group) !== '') {
				$supervisors[trim($group)] = true;
			}
		}

		return new self(
			deciderOwned: $ownership !== self::OWNERSHIP_OPEN,
			supervisors: array_keys($supervisors),
			hideOthers: (($declaration['hideOthers'] ?? false) === true)
		);
	}//end fromDeclaration()

	/**
	 * Whether a decision belongs to the person who took it.
	 *
	 * @return bool True for `decider` ownership.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
	 */
	public function isDeciderOwned(): bool {
		return $this->deciderOwned;
	}//end isDeciderOwned()

	/**
	 * The supervisor groups.
	 *
	 * @return array<string> Group ids.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
	 */
	public function getSupervisors(): array {
		return $this->supervisors;
	}//end getSupervisors()

	/**
	 * Whether another reviewer's decisions are hidden.
	 *
	 * @return bool True when hiding is on.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-schema-can-hide-one-reviewers-decisions-from-another-req-rod-002
	 */
	public function hidesOthers(): bool {
		return $this->hideOthers;
	}//end hidesOthers()

	/**
	 * The policy as a declaration.
	 *
	 * @return array{ownership: string, supervisors: array<string>, hideOthers: bool}
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
	 */
	public function toArray(): array {
		$ownership = self::OWNERSHIP_OPEN;
		if ($this->deciderOwned === true) {
			$ownership = self::OWNERSHIP_DECIDER;
		}

		return [
			'ownership' => $ownership,
			'supervisors' => $this->supervisors,
			'hideOthers' => $this->hideOthers,
		];
	}//end toArray()
}//end class
