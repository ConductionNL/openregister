<?php

/**
 * Taking the anonymised values out of the audit trail's stored diffs.
 *
 * 🔴 THE ONE PLACE THE RULE THAT HISTORY IS PRESERVED GIVES WAY, AND IT GIVES
 * WAY ON PURPOSE. Every write to an object leaves a diff on the chained trail
 * holding the value before and the value after. An anonymisation that changes
 * the payload and leaves those diffs alone has removed the citizen's name from
 * one place and left it in every historical row of the same record, where the
 * trail will happily show it beside the record it was removed from. That is not
 * an anonymisation; it is a rename with a receipt.
 *
 * WHAT SURVIVES IS THE FACT, NOT THE VALUES. The property names stay, the
 * timestamps stay, the actor stays, and a new entry records that the
 * anonymisation happened, when, under which profile and which properties it
 * covered. An auditor can prove the act took place and prove its scope, and
 * cannot recover the person from it.
 *
 * 🔴 AND THE CHAIN IS RE-SEALED, NOT LEFT BROKEN. Editing a sealed row changes
 * its hash, so the chain stops verifying from that row onward. A verification
 * that fails for a lawful redaction looks exactly like one that fails for
 * tampering, and an integrity check nobody can trust is an integrity check
 * nobody runs. Re-sealing is therefore part of the act rather than a follow-up:
 * ADR-003 Rule 4's verify, record the verdict, then re-seal.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * Redacts one record's stored diffs, keeping the shape and losing the values.
 */
final class AuditDiffRedactionTarget {

	/**
	 * What a redacted value reads as in the trail.
	 *
	 * A marker rather than an empty string, so a reader can tell "this was
	 * removed by an anonymisation" from "this was blank at the time". They are
	 * different facts and only one of them is about the citizen.
	 *
	 * @var string
	 */
	public const REDACTED = '[anonymised]';

	/**
	 * Redact one stored diff against a plan.
	 *
	 * The diff keeps every property name it had, so the trail still says which
	 * fields moved and when. Only the values of the anonymised properties are
	 * replaced.
	 *
	 * @param array<string, mixed> $changed           The stored diff.
	 * @param string[]             $changedProperties The anonymised properties.
	 *
	 * @return array<string, mixed> The redacted diff.
	 */
	public function redact(array $changed, array $changedProperties): array {
		$targets = array_flip($changedProperties);
		$redacted = [];

		foreach ($changed as $property => $entry) {
			$name = (string)$property;
			if (isset($targets[$name]) === false) {
				$redacted[$name] = $entry;
				continue;
			}

			if (is_array($entry) === false) {
				$redacted[$name] = self::REDACTED;
				continue;
			}

			// Both sides. Keeping `old` because only `new` matched the payload
			// is how the name survives: the value a record used to hold is the
			// value being removed.
			$rewritten = $entry;
			foreach (array_keys($entry) as $side) {
				$rewritten[$side] = self::REDACTED;
			}

			$redacted[$name] = $rewritten;
		}//end foreach

		return $redacted;
	}//end redact()

	/**
	 * The entry that records the act itself.
	 *
	 * @param AnonymisationPlan $plan The plan.
	 *
	 * @return array<string, mixed> The entry's changed payload.
	 */
	public function entryFor(AnonymisationPlan $plan): array {
		return [
			'anonymisation' => [
				'profile' => $plan->profileName,
				'properties' => $plan->changedProperties,
				'kept' => $plan->keptProperties,
				'saltFingerprint' => $plan->saltFingerprint,
			],
		];
	}//end entryFor()

	/**
	 * Whether a redacted diff still holds any of the anonymised values.
	 *
	 * The check the act runs on itself before it calls the redaction done. A
	 * redaction that missed a nested copy would otherwise report success while
	 * the name sits one level down, and this is the last place anybody would
	 * think to look for it.
	 *
	 * @param array<string, mixed> $redacted The redacted diff.
	 * @param array<string, mixed> $removed  The values that were removed.
	 *
	 * @return string[] The values still present, empty when the redaction is clean.
	 */
	public function leftovers(array $redacted, array $removed): array {
		$serialised = json_encode($redacted);
		if ($serialised === false) {
			return ['the redacted diff could not be read back'];
		}

		$found = [];
		foreach ($removed as $value) {
			if (is_scalar($value) === false) {
				continue;
			}

			$text = trim((string)$value);
			if ($text === '' || str_contains($serialised, $text) === false) {
				continue;
			}

			$found[] = $text;
		}

		return $found;
	}//end leftovers()
}//end class
